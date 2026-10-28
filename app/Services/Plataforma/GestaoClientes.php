<?php

namespace App\Services\Plataforma;

use App\Enums\Papel;
use App\Models\Assinatura;
use App\Models\Fatura;
use App\Models\Organizacao;
use App\Models\OrganizacaoNota;
use App\Models\Plano;
use App\Models\Usuario;
use App\Services\Auditoria;
use App\Services\LimitesPlano;
use App\Services\RecuperacaoConta;
use App\Support\ContextoBanco;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Operações da plataforma sobre os clientes: venda manual, cadastro, plano,
 * teste grátis, baixa de fatura, desativação, administradores e anotações.
 *
 * O que mexe no ambiente do cliente roda DENTRO dele (RLS) e fica na
 * auditoria dele como "Equipe da plataforma". A plataforma nunca vê nem
 * escolhe senhas: o administrador recebe um link no próprio e-mail.
 */
class GestaoClientes
{
    public const DIAS_MAXIMOS_EXTENSAO_TESTE = 30;

    public function __construct(
        private readonly ContextoBanco $contexto,
        private readonly Auditoria $auditoria,
        private readonly RecuperacaoConta $recuperacao,
        private readonly LimitesPlano $limites,
    ) {}

    /**
     * Venda manual (contrato fora do site): cliente com cadastro completo,
     * perfis padrão e termo modelo, assinatura ativa e o primeiro administrador.
     *
     * @param  array<string, mixed>  $dados  cadastro validado (DadosClienteRequest)
     */
    public function criar(array $dados, string $planoId, string $adminNome, string $adminEmail): Organizacao
    {
        $plano = Plano::query()->findOrFail($planoId);
        $adminEmail = mb_strtolower(trim($adminEmail));

        if (DB::connection('pgsql')->scalar('SELECT cg_email_em_uso(?, NULL)', [$adminEmail])) {
            throw ValidationException::withMessages(['admin_email' => 'Este e-mail já está em uso no CadGestor.']);
        }

        return DB::connection('pgsql')->transaction(function () use ($dados, $plano, $adminNome, $adminEmail): Organizacao {
            $cliente = Organizacao::query()->create($dados);
            DB::connection('pgsql')->select('SELECT cg_provisionar_organizacao(?)', [$cliente->id]);
            Assinatura::query()->create(['organizacao_id' => $cliente->id, 'plano_id' => $plano->id, 'status' => 'ativa']);
            $this->auditoria->registrar('criar', 'organizacao', $cliente->id, null, [
                'nome' => $cliente->nome,
                'cnpj' => $cliente->cnpj,
                'plano' => $plano->codigo,
            ]);

            $this->criarAdministrador($cliente, $adminNome, $adminEmail);

            return $cliente;
        });
    }

    /** @param  array<string, mixed>  $dados */
    public function atualizar(Organizacao $cliente, array $dados): void
    {
        DB::connection('pgsql')->transaction(function () use ($cliente, $dados): void {
            $antes = $cliente->only(array_keys($dados));
            $cliente->update($dados);
            [$de, $para] = Auditoria::diferenca($antes, $cliente->only(array_keys($dados)));

            if ($para !== null && $para !== []) {
                $this->auditoria->registrar('alterar', 'organizacao', $cliente->id, $de, $para + ['campos' => array_keys($para)]);
            }
        });
    }

    /**
     * Desativar (nunca excluir) ou reativar. Desativado, ninguém do cliente
     * entra e as catracas são recusadas; nada é apagado.
     */
    public function alterarSituacao(Organizacao $cliente, bool $ativo, ?string $nomeDigitado = null): void
    {
        if (! $ativo && trim((string) $nomeDigitado) !== $cliente->nome) {
            throw ValidationException::withMessages(['confirmacao_texto' => 'Digite a razão social exatamente como aparece para confirmar.']);
        }

        DB::connection('pgsql')->transaction(function () use ($cliente, $ativo): void {
            $antes = $cliente->ativo;
            $cliente->forceFill(['ativo' => $ativo])->save(); // 'ativo' não é preenchível por formulário
            $this->auditoria->registrar('alterar', 'organizacao', $cliente->id, ['ativo' => $antes], ['ativo' => $ativo]);
        });
    }

    /** Troca de plano negociada. O uso atual precisa caber no plano novo. */
    public function trocarPlano(Organizacao $cliente, string $planoId, string $motivo): void
    {
        $motivo = self::motivo($motivo);
        $plano = Plano::query()->findOrFail($planoId);

        $this->contexto->dentroDe($cliente->id, function () use ($plano, $motivo): void {
            $assinatura = $this->assinaturaAtiva();

            if ($assinatura->plano_id === $plano->id) {
                throw ValidationException::withMessages(['plano_id' => 'O cliente já está neste plano.']);
            }

            $uso = $this->limites->uso();

            if ($uso['pessoas'] > $plano->limite_pessoas || $uso['catracas'] > $plano->limite_catracas) {
                throw ValidationException::withMessages(['plano_id' => sprintf(
                    'O uso atual do cliente (%d pessoas e %d catracas ativas) não cabe no plano %s.',
                    $uso['pessoas'], $uso['catracas'], $plano->nome,
                )]);
            }

            DB::connection('pgsql')->transaction(function () use ($assinatura, $plano, $motivo): void {
                $anterior = $assinatura->plano->codigo;
                $assinatura->update(['plano_id' => $plano->id]);
                $this->auditoria->registrar('assinatura_alterada', 'assinatura', $assinatura->id, ['plano' => $anterior], [
                    'plano' => $plano->codigo,
                    'motivo' => $motivo,
                ]);
            });
        });
    }

    /** Mais dias de teste grátis; a primeira cobrança em aberto acompanha o novo fim. */
    public function estenderTeste(Organizacao $cliente, int $dias, string $motivo): string
    {
        $motivo = self::motivo($motivo);

        if ($dias < 1 || $dias > self::DIAS_MAXIMOS_EXTENSAO_TESTE) {
            throw ValidationException::withMessages(['dias' => 'Informe de 1 a '.self::DIAS_MAXIMOS_EXTENSAO_TESTE.' dias.']);
        }

        return $this->contexto->dentroDe($cliente->id, function () use ($dias, $motivo): string {
            $assinatura = Assinatura::query()->with('plano')->whereRaw('organizacao_id = cg_org_atual()')->first();

            if ($assinatura === null || $assinatura->status !== 'teste') {
                throw ValidationException::withMessages(['dias' => 'Só é possível estender assinaturas em teste grátis.']);
            }

            $fimAtual = $assinatura->teste_ate;
            $novoFim = $fimAtual->max(now()->startOfDay())->copy()->addDays($dias);

            DB::connection('pgsql')->transaction(function () use ($assinatura, $fimAtual, $novoFim, $dias, $motivo): void {
                $assinatura->update(['teste_ate' => $novoFim->toDateString()]);
                Fatura::query()->where('assinatura_id', $assinatura->id)->where('status', 'pendente')
                    ->whereDate('vencimento', '<=', $novoFim->toDateString())
                    ->update(['vencimento' => $novoFim->copy()->addDay()->toDateString()]);
                $this->auditoria->registrar('assinatura_alterada', 'assinatura', $assinatura->id, [
                    'teste_ate' => $fimAtual->toDateString(),
                ], ['teste_ate' => $novoFim->toDateString(), 'dias' => $dias, 'motivo' => $motivo]);
            });

            return $novoFim->toDateString();
        });
    }

    /** Pagamento recebido fora do meio de pagamento (Pix direto, transferência). */
    public function darBaixa(Organizacao $cliente, string $faturaId, string $motivo): void
    {
        $motivo = self::motivo($motivo);

        $this->contexto->dentroDe($cliente->id, function () use ($faturaId, $motivo): void {
            $fatura = Fatura::query()->whereRaw('organizacao_id = cg_org_atual()')->find($faturaId);

            if ($fatura === null) {
                abort(404);
            }

            if (! in_array($fatura->status, ['pendente', 'vencida'], true)) {
                throw ValidationException::withMessages(['fatura' => 'Esta fatura não está em aberto.']);
            }

            DB::connection('pgsql')->transaction(function () use ($fatura, $motivo): void {
                DB::connection('pgsql')->update("UPDATE fatura SET status = 'paga', pago_em = now() WHERE id = ?", [$fatura->id]);

                $temVencida = (bool) DB::connection('pgsql')->scalar(
                    "SELECT EXISTS (SELECT 1 FROM fatura WHERE status = 'vencida' AND organizacao_id = cg_org_atual())",
                );

                if (! $temVencida) {
                    DB::connection('pgsql')->update(<<<'SQL'
                        UPDATE assinatura SET status = 'ativa', inadimplente_desde = NULL, teste_ate = NULL
                         WHERE id = ? AND status <> 'cancelada'
                        SQL, [$fatura->assinatura_id]);
                }

                $this->auditoria->registrar('pagamento', 'fatura', $fatura->id, null, [
                    'evento' => 'baixa_manual',
                    'valor_centavos' => $fatura->valor_centavos,
                    'vencimento' => $fatura->vencimento->toDateString(),
                    'motivo' => $motivo,
                ]);
            });
        });
    }

    /**
     * Novo administrador do cliente: a senha aleatória gerada é descartada e
     * ele recebe no e-mail um link para criar a própria senha (vale 72 horas).
     */
    public function criarAdministrador(Organizacao $cliente, string $nome, string $email): Usuario
    {
        $email = mb_strtolower(trim($email));

        if (DB::connection('pgsql')->scalar('SELECT cg_email_em_uso(?, NULL)', [$email])) {
            throw ValidationException::withMessages(['admin_email' => 'Este e-mail já está em uso no CadGestor.']);
        }

        return $this->contexto->dentroDe($cliente->id, function () use ($nome, $email): Usuario {
            return DB::connection('pgsql')->transaction(function () use ($nome, $email): Usuario {
                $usuario = new Usuario(['nome' => trim($nome), 'email' => $email, 'ativo' => true, 'deve_trocar_senha' => false]);
                $usuario->senha_hash = Hash::make(Str::random(64));
                $usuario->save();
                DB::connection('pgsql')->insert('INSERT INTO usuario_papel (usuario_id, papel) VALUES (?, ?)', [$usuario->id, Papel::Administrador->value]);
                $this->auditoria->registrar('criar', 'usuario_sistema', $usuario->id, null, ['papeis' => [Papel::Administrador->value]]);
                $this->recuperacao->enviarLink($usuario->refresh(), 'novo_administrador', true);

                return $usuario;
            });
        });
    }

    /** Link de nova senha para um administrador do cliente (a plataforma não vê a senha). */
    public function enviarLinkSenha(Organizacao $cliente, string $usuarioId): void
    {
        $this->contexto->dentroDe($cliente->id, function () use ($cliente, $usuarioId): void {
            $usuario = Usuario::query()->whereKey($usuarioId)->where('organizacao_id', $cliente->id)->first();

            if ($usuario === null || ! $usuario->temPapel(Papel::Administrador) || ! $usuario->ativo) {
                abort(404);
            }

            $this->recuperacao->enviarLink($usuario, 'plataforma');
        });
    }

    public function anotar(Organizacao $cliente, string $texto): void
    {
        $texto = trim((string) preg_replace('/[ \t]+/u', ' ', $texto));

        if (mb_strlen($texto) < 3 || mb_strlen($texto) > 1000) {
            throw ValidationException::withMessages(['texto' => 'Escreva a anotação (3 a 1000 caracteres).']);
        }

        OrganizacaoNota::query()->create(['organizacao_id' => $cliente->id, 'autor_id' => Auth::id(), 'texto' => $texto]);
    }

    private function assinaturaAtiva(): Assinatura
    {
        $assinatura = Assinatura::query()->with('plano')->whereRaw('organizacao_id = cg_org_atual()')->first();

        if ($assinatura === null || $assinatura->status === 'cancelada') {
            throw ValidationException::withMessages(['plano_id' => 'A assinatura deste cliente está cancelada.']);
        }

        return $assinatura;
    }

    /** O motivo vai para a auditoria do cliente: texto curto, sem dados pessoais. */
    private static function motivo(string $motivo): string
    {
        $motivo = trim((string) preg_replace('/\s+/u', ' ', $motivo));

        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 200) {
            throw ValidationException::withMessages(['motivo' => 'Informe o motivo (5 a 200 caracteres).']);
        }

        return $motivo;
    }
}
