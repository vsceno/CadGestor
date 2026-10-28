<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Banco\Transacao;
use CadGestor\Cobranca\GatewayPagamento;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\LimitadorTentativas;
use DateTimeImmutable;
use PDO;

/**
 * Ferramentas de atendimento da plataforma sobre um cliente: assinatura
 * (plano, teste grátis, baixa manual de fatura), anotações internas e acesso
 * dos administradores do cliente.
 *
 * Tudo que mexe no cliente roda DENTRO da organização dele (RLS) e fica na
 * auditoria dele como "Equipe da plataforma". A plataforma continua sem ver
 * os dados do ambiente (pessoas, biometria, eventos) e nunca vê senhas.
 */
final class AtendimentoClienteServico
{
    public const DIAS_MAXIMOS_EXTENSAO = 30;

    public function __construct(
        private readonly PDO $pdo,
        private readonly OrganizacaoRepositorio $organizacoes,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly UsuarioRepositorio $usuarios,
        private readonly GatewayPagamento $gateway,
        private readonly RecuperacaoContaServico $recuperacao,
        private readonly LimitadorTentativas $limitador,
        private readonly ContextoBanco $contextoBanco,
        private readonly Auditoria $auditoria,
    ) {
    }

    // --- Assinatura --------------------------------------------------------------

    /**
     * Troca de plano negociada com o comercial. Aceita planos fora de venda
     * (contratos antigos), mas o uso atual precisa caber no plano novo.
     */
    public function trocarPlano(string $organizacaoId, string $planoId, string $motivo): void
    {
        $motivo = self::motivo($motivo);

        $this->noCliente($organizacaoId, function () use ($planoId, $motivo): void {
            $assinatura = $this->assinaturaAtiva();
            $plano = $this->assinaturas->plano($planoId) ?? throw ErroValidacao::campo('plano', 'Escolha um plano.');

            if ($plano['id'] === $assinatura['plano_id']) {
                throw ErroValidacao::campo('plano', 'O cliente já está neste plano.');
            }

            $uso = $this->assinaturas->uso();

            if ($uso['pessoas'] > $plano['limite_pessoas'] || $uso['catracas'] > $plano['limite_catracas']) {
                throw ErroValidacao::campo('plano', sprintf(
                    'O uso atual do cliente (%d pessoas e %d catracas ativas) não cabe no plano %s.',
                    $uso['pessoas'],
                    $uso['catracas'],
                    $plano['nome'],
                ));
            }

            Transacao::executar($this->pdo, function () use ($assinatura, $plano, $motivo): void {
                $this->assinaturas->definirPlano((string) $assinatura['id'], (string) $plano['id']);

                if ($assinatura['gateway_assinatura_id'] !== null) {
                    $this->gateway->alterarPlano(
                        (string) $assinatura['gateway_assinatura_id'],
                        AssinaturaServico::dadosPlano($plano),
                    );
                }

                $this->auditoria->registrar('assinatura_alterada', 'assinatura', (string) $assinatura['id'], [
                    'plano' => $assinatura['plano_codigo'],
                ], ['plano' => $plano['codigo'], 'motivo' => $motivo]);
            });
        });
    }

    /**
     * Mais dias de teste grátis. A primeira cobrança em aberto acompanha o
     * novo fim do teste (vence no dia seguinte), aqui e no gateway.
     */
    public function estenderTeste(
        string $organizacaoId,
        int $dias,
        string $motivo,
        ?DateTimeImmutable $hoje = null,
    ): string {
        $motivo = self::motivo($motivo);
        $hoje ??= new DateTimeImmutable('today');

        if ($dias < 1 || $dias > self::DIAS_MAXIMOS_EXTENSAO) {
            throw ErroValidacao::campo('dias', 'Informe de 1 a ' . self::DIAS_MAXIMOS_EXTENSAO . ' dias.');
        }

        return $this->noCliente($organizacaoId, function () use ($dias, $motivo, $hoje): string {
            $assinatura = $this->assinaturas->daOrganizacao();

            if ($assinatura === null || $assinatura['status'] !== 'teste') {
                throw ErroValidacao::campo('dias', 'Só é possível estender assinaturas em teste grátis.');
            }

            $fimAtual = new DateTimeImmutable((string) $assinatura['teste_ate']);
            $novoFim = max($fimAtual, $hoje)->modify("+{$dias} days");
            $vencimento = $novoFim->modify('+1 day');

            Transacao::executar($this->pdo, function () use ($assinatura, $novoFim, $vencimento, $dias, $motivo): void {
                $this->assinaturas->definirFimTeste((string) $assinatura['id'], $novoFim->format('Y-m-d'));

                foreach ($this->assinaturas->faturas() as $fatura) {
                    if ($fatura['status'] === 'pendente' && $fatura['vencimento'] < $vencimento->format('Y-m-d')) {
                        $this->assinaturas->definirVencimento((string) $fatura['id'], $vencimento->format('Y-m-d'));
                        $this->gateway->alterarVencimento((string) $fatura['gateway_cobranca_id'], $vencimento);
                    }
                }

                $this->auditoria->registrar('assinatura_alterada', 'assinatura', (string) $assinatura['id'], [
                    'teste_ate' => (string) $assinatura['teste_ate'],
                ], ['teste_ate' => $novoFim->format('Y-m-d'), 'dias' => $dias, 'motivo' => $motivo]);
            });

            return $novoFim->format('Y-m-d');
        });
    }

    /**
     * Pagamento recebido fora do gateway (Pix direto, transferência). Sem
     * outra fatura vencida, a assinatura volta a ficar em dia.
     */
    public function darBaixaManual(string $organizacaoId, string $faturaId, string $motivo): void
    {
        $motivo = self::motivo($motivo);

        $this->noCliente($organizacaoId, function () use ($faturaId, $motivo): void {
            $fatura = $this->assinaturas->fatura($faturaId);

            if ($fatura === null) {
                throw ErroValidacao::campo('id', 'Fatura não encontrada.');
            }

            if (!in_array($fatura['status'], ['pendente', 'vencida'], true)) {
                throw ErroValidacao::campo('fatura', 'Esta fatura não está em aberto.');
            }

            Transacao::executar($this->pdo, function () use ($fatura, $motivo): void {
                $this->assinaturas->marcarFaturaPaga((string) $fatura['id']);
                $this->gateway->registrarPagamentoManual(
                    (string) $fatura['gateway_cobranca_id'],
                    new DateTimeImmutable(),
                );

                if (!$this->assinaturas->temFaturaVencida()) {
                    $this->assinaturas->marcarEmDia((string) $fatura['assinatura_id']);
                }

                $this->auditoria->registrar('pagamento', 'fatura', (string) $fatura['id'], null, [
                    'evento' => 'baixa_manual',
                    'manual' => true,
                    'valor_centavos' => (int) $fatura['valor_centavos'],
                    'vencimento' => $fatura['vencimento'],
                    'motivo' => $motivo,
                ]);
            });
        });
    }

    // --- Anotações internas ------------------------------------------------------

    public function anotar(string $organizacaoId, string $texto): void
    {
        $this->existente($organizacaoId);
        $texto = trim(preg_replace('/[ \t]+/u', ' ', $texto) ?? '');

        if (mb_strlen($texto) < 3 || mb_strlen($texto) > 1000) {
            throw ErroValidacao::campo('texto', 'Escreva a anotação (3 a 1000 caracteres).');
        }

        $this->organizacoes->adicionarNota($organizacaoId, $texto);
    }

    // --- Acesso dos administradores do cliente -----------------------------------

    /** Link para o próprio administrador criar uma senha nova (a plataforma não vê a senha). */
    public function enviarLinkRedefinicao(string $organizacaoId, string $usuarioId): void
    {
        $this->noCliente($organizacaoId, function () use ($organizacaoId, $usuarioId): void {
            $usuario = $this->administrador($organizacaoId, $usuarioId);

            if ($usuario['ativo'] !== true) {
                throw ErroValidacao::campo('usuario', 'Reative o administrador antes de enviar o link.');
            }

            $this->recuperacao->enviarLinkRedefinicao($usuario, 'plataforma');
        });
    }

    public function reenviarConfirmacao(string $organizacaoId, string $usuarioId): void
    {
        $this->noCliente($organizacaoId, function () use ($organizacaoId, $usuarioId): void {
            $usuario = $this->administrador($organizacaoId, $usuarioId);

            if (!$this->recuperacao->reenviarConfirmacao($usuario, 'plataforma')) {
                throw ErroValidacao::campo('usuario', 'Este administrador já confirmou o e-mail.');
            }
        });
    }

    /** Libera o login travado por tentativas erradas (não mexe na senha). */
    public function desbloquearLogin(string $organizacaoId, string $usuarioId, string $ip): void
    {
        $this->noCliente($organizacaoId, function () use ($organizacaoId, $usuarioId, $ip): void {
            $usuario = $this->administrador($organizacaoId, $usuarioId);

            Transacao::executar($this->pdo, function () use ($usuario, $ip): void {
                $this->limitador->desbloquear((string) $usuario['email'], $ip);
                $this->auditoria->registrar('login_desbloqueado', 'usuario_sistema', (string) $usuario['id']);
            });
        });
    }

    /** Ex.: o responsável saiu da empresa. Desativado, ele perde a sessão na próxima requisição. */
    public function alterarSituacaoAdministrador(string $organizacaoId, string $usuarioId, bool $ativo): void
    {
        $this->noCliente($organizacaoId, function () use ($organizacaoId, $usuarioId, $ativo): void {
            $usuario = $this->administrador($organizacaoId, $usuarioId);

            Transacao::executar($this->pdo, function () use ($usuario, $ativo): void {
                $this->usuarios->definirAtivo((string) $usuario['id'], $ativo);
                $this->auditoria->registrar('alterar', 'usuario_sistema', (string) $usuario['id'], [
                    'ativo' => $usuario['ativo'],
                ], ['ativo' => $ativo]);
            });
        });
    }

    /**
     * Administradores do cliente com o login travado por tentativas.
     *
     * @param list<array<string, mixed>> $usuarios
     * @return array<string, bool> id => travado
     */
    public function loginsTravados(array $usuarios): array
    {
        $travados = [];

        foreach ($usuarios as $usuario) {
            $travados[(string) $usuario['id']] = $this->limitador->emailBloqueado((string) $usuario['email']);
        }

        return $travados;
    }

    // -------------------------------------------------------------------------

    /**
     * @template T
     * @param callable(): T $acao
     * @return T
     */
    private function noCliente(string $organizacaoId, callable $acao): mixed
    {
        $this->existente($organizacaoId);

        return $this->contextoBanco->dentroDe($organizacaoId, $acao);
    }

    private function existente(string $organizacaoId): void
    {
        if ($this->organizacoes->buscar($organizacaoId) === null) {
            throw ErroValidacao::campo('id', 'Organização não encontrada.');
        }
    }

    /**
     * Só administradores DO cliente: o RLS já esconde usuários de outras
     * organizações; a conferência explícita é a segunda tranca.
     *
     * @return array<string, mixed>
     */
    private function administrador(string $organizacaoId, string $usuarioId): array
    {
        $usuario = $this->usuarios->buscarPorId($usuarioId);

        if (
            $usuario === null || $usuario['organizacao_id'] !== $organizacaoId
            || !in_array('administrador', $usuario['papeis'], true)
        ) {
            throw ErroValidacao::campo('id', 'Administrador não encontrado.');
        }

        return $usuario;
    }

    /** @return array<string, mixed> */
    private function assinaturaAtiva(): array
    {
        $assinatura = $this->assinaturas->daOrganizacao();

        if ($assinatura === null || $assinatura['status'] === 'cancelada') {
            throw ErroValidacao::campo('plano', 'A assinatura deste cliente está cancelada.');
        }

        return $assinatura;
    }

    /** O motivo vai para a auditoria do cliente: texto curto, sem dados pessoais. */
    private static function motivo(string $motivo): string
    {
        $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');

        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 200) {
            throw ErroValidacao::campo('motivo', 'Informe o motivo (5 a 200 caracteres).');
        }

        return $motivo;
    }
}
