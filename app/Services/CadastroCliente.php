<?php

namespace App\Services;

use App\Enums\Papel;
use App\Mail\ConfirmacaoEmail;
use App\Models\Plano;
use App\Rules\SenhaForte;
use App\Support\ContextoBanco;
use App\Support\Documento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Contratação pelo site (self-service), em uma única transação:
 *  - cliente com o aceite dos Termos de Uso (versão, data e IP);
 *  - perfis padrão e termo de consentimento modelo;
 *  - assinatura em teste grátis (a primeira cobrança só vence no fim do teste);
 *  - administrador aguardando a confirmação do e-mail (só o hash do token no banco).
 */
class CadastroCliente
{
    /** Versão dos Termos de Uso e do Contrato de Tratamento de Dados aceita no cadastro (MODELO). */
    public const VERSAO_TERMOS = '1.0-modelo';

    public const DIAS_TESTE = 14;

    public function __construct(
        private readonly ContextoBanco $contexto,
        private readonly Auditoria $auditoria,
    ) {}

    /**
     * @param  array<string, mixed>  $entrada
     *
     * @throws ValidationException
     */
    public function cadastrar(array $entrada, string $ip): string
    {
        $dados = $this->validar($entrada);
        $token = bin2hex(random_bytes(32));
        $testeAte = now()->startOfDay()->addDays(self::DIAS_TESTE);

        // A "loja" da plataforma: cria no modo plataforma e depois entra no cliente novo.
        $this->contexto->definir(null, null, true);

        try {
            $clienteId = DB::connection('pgsql')->transaction(function () use ($dados, $ip, $token, $testeAte): string {
                $banco = DB::connection('pgsql');
                $clienteId = (string) $banco->scalar(<<<'SQL'
                    INSERT INTO organizacao (id, nome, cnpj, telefone, termos_versao, termos_aceitos_em, termos_aceitos_ip)
                    VALUES (uuidv7(), ?, ?, ?, ?, now(), CAST(? AS inet))
                    RETURNING id
                    SQL, [$dados['razao_social'], $dados['cnpj'], $dados['telefone'], self::VERSAO_TERMOS, $ip]);
                $banco->select('SELECT cg_provisionar_organizacao(?)', [$clienteId]);
                $assinaturaId = (string) $banco->scalar(
                    "INSERT INTO assinatura (organizacao_id, plano_id, status, teste_ate) VALUES (?, ?, 'teste', ?) RETURNING id",
                    [$clienteId, $dados['plano']->id, $testeAte->toDateString()],
                );

                $this->contexto->dentroDe($clienteId, function () use ($banco, $dados, $token, $testeAte, $assinaturaId): void {
                    $usuarioId = (string) $banco->scalar(<<<'SQL'
                        INSERT INTO usuario_sistema (nome, email, senha_hash, email_verificado_em, verificacao_token_hash, verificacao_expira_em)
                        VALUES (?, ?, ?, NULL, ?, now() + make_interval(hours => ?))
                        RETURNING id
                        SQL, [$dados['responsavel_nome'], $dados['email'], Hash::make($dados['senha']), hash('sha256', $token), RecuperacaoConta::HORAS_CONFIRMACAO]);
                    $banco->insert('INSERT INTO usuario_papel (usuario_id, papel) VALUES (?, ?)', [$usuarioId, Papel::Administrador->value]);

                    // Primeira fatura: vence no dia seguinte ao fim do teste (paga no meio de pagamento).
                    $banco->insert(<<<'SQL'
                        INSERT INTO fatura (assinatura_id, gateway, gateway_cobranca_id, valor_centavos, vencimento)
                        VALUES (?, 'manual', ?, ?, ?)
                        SQL, [$assinaturaId, 'inicial-'.$assinaturaId, $dados['plano']->preco_centavos, $testeAte->copy()->addDay()->toDateString()]);

                    $this->auditoria->registrar('cadastro_cliente', 'organizacao', null, null, [
                        'plano' => $dados['plano']->codigo,
                        'teste_ate' => $testeAte->toDateString(),
                        'termos_versao' => self::VERSAO_TERMOS,
                    ], $usuarioId);
                });

                return $clienteId;
            });
        } finally {
            $this->contexto->limpar();
        }

        Mail::to($dados['email'])->send(new ConfirmacaoEmail($dados['responsavel_nome'], route('email.confirmar', ['token' => $token]), RecuperacaoConta::HORAS_CONFIRMACAO));

        return $clienteId;
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{razao_social: string, cnpj: string, telefone: string, responsavel_nome: string, email: string, senha: string, plano: Plano}
     */
    private function validar(array $entrada): array
    {
        $entrada['cnpj'] = Documento::normalizarCnpj((string) ($entrada['cnpj'] ?? ''));
        $entrada['telefone'] = preg_replace('/\D/', '', (string) ($entrada['telefone'] ?? ''));
        $entrada['email'] = mb_strtolower(trim((string) ($entrada['email'] ?? '')));
        $entrada['razao_social'] = trim((string) preg_replace('/\s+/u', ' ', (string) ($entrada['razao_social'] ?? '')));

        $dados = Validator::make($entrada, [
            'plano' => ['required', 'string'],
            'razao_social' => ['required', 'string', 'min:3', 'max:150'],
            'cnpj' => ['required', function (string $a, mixed $valor, \Closure $falha): void {
                if (! Documento::cnpjValido((string) $valor)) {
                    $falha('CNPJ inválido. Confira os dígitos.');
                } elseif (DB::connection('pgsql')->scalar('SELECT cg_cnpj_cliente_em_uso(?, NULL)', [$valor])) {
                    $falha('Este CNPJ já tem cadastro. Entre com o e-mail do administrador ou fale com o comercial.');
                }
            }],
            'telefone' => ['required', 'digits_between:10,11'],
            'responsavel_nome' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'email', 'max:254', function (string $a, mixed $valor, \Closure $falha): void {
                if (DB::connection('pgsql')->scalar('SELECT cg_email_em_uso(?, NULL)', [$valor])) {
                    $falha('Este e-mail já está em uso. Se você já tem conta, entre ou use "Esqueci minha senha".');
                }
            }],
            'senha' => ['required', 'string', 'confirmed', new SenhaForte((string) $entrada['email'])],
            'aceite' => ['accepted'],
        ], [
            'telefone.digits_between' => 'Informe o telefone da empresa com DDD (10 ou 11 dígitos).',
            'senha.confirmed' => 'A confirmação não confere com a senha.',
            'aceite.accepted' => 'É preciso aceitar os Termos de Uso e o Contrato de Tratamento de Dados.',
        ], ['razao_social' => 'razão social', 'responsavel_nome' => 'nome do responsável'])->validate();

        $plano = Plano::query()->where('codigo', $dados['plano'])->where('disponivel_venda', true)->first();

        if ($plano === null) {
            throw ValidationException::withMessages(['plano' => 'Escolha um plano.']);
        }

        return [...$dados, 'plano' => $plano];
    }
}
