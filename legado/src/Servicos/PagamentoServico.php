<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Banco\Transacao;
use CadGestor\Cobranca\EventoPagamento;
use CadGestor\Repositorios\AssinaturaRepositorio;
use DateTimeImmutable;
use PDO;

/**
 * Aplica os avisos do gateway (cobrança criada, paga, vencida, cancelada) à
 * assinatura e às faturas do cliente.
 *
 * Idempotente: cada aviso tem um id único; reenviado, é ignorado. O aviso
 * chega sem organização: ela é descoberta pela cobrança/assinatura do gateway
 * e o processamento roda DENTRO dela (auditoria na trilha do cliente).
 */
final class PagamentoServico
{
    public const PROCESSADO = 'processado';
    public const DUPLICADO = 'duplicado';
    public const DESCONHECIDO = 'desconhecido';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly ContextoBanco $contextoBanco,
        private readonly Auditoria $auditoria,
    ) {
    }

    public function processar(EventoPagamento $evento, string $gateway): string
    {
        $jaRecebido = $this->pdo->prepare('SELECT 1 FROM evento_gateway WHERE gateway = :g AND evento_id = :e');
        $jaRecebido->execute(['g' => $gateway, 'e' => $evento->id]);

        if ($jaRecebido->fetchColumn() !== false) {
            return self::DUPLICADO;
        }

        $consulta = $this->pdo->prepare('SELECT cg_organizacao_por_gateway(:g, :c, :a)');
        $consulta->execute(['g' => $gateway, 'c' => $evento->cobranca->id, 'a' => $evento->assinaturaId]);
        $organizacaoId = $consulta->fetchColumn();

        return Transacao::executar($this->pdo, function () use ($evento, $gateway, $organizacaoId): string {
            $resultado = is_string($organizacaoId)
                ? $this->contextoBanco->dentroDe($organizacaoId, fn (): string => $this->aplicar($evento, $gateway))
                : self::DESCONHECIDO;

            // Se esta gravação falhar (aviso simultâneo repetido), tudo é desfeito e o gateway reenvia.
            $this->pdo->prepare(<<<'SQL'
                INSERT INTO evento_gateway (gateway, evento_id, tipo, gateway_cobranca_id, resultado)
                VALUES (:g, :e, :t, :c, :r)
                SQL)->execute([
                'g' => $gateway,
                'e' => $evento->id,
                't' => $evento->tipo,
                'c' => $evento->cobranca->id,
                'r' => $resultado,
            ]);

            return $resultado;
        });
    }

    private function aplicar(EventoPagamento $evento, string $gateway): string
    {
        $assinatura = $this->assinaturas->daOrganizacao();

        if ($assinatura === null) {
            return self::DESCONHECIDO;
        }

        $assinaturaId = (string) $assinatura['id'];
        $cobranca = $evento->cobranca;
        $this->assinaturas->registrarFatura(
            $assinaturaId,
            $gateway,
            $cobranca->id,
            $cobranca->valorCentavos,
            $cobranca->vencimento,
            $cobranca->linkPagamento,
        );

        switch ($evento->tipo) {
            case EventoPagamento::PAGAMENTO_CONFIRMADO:
                $this->assinaturas->definirStatusFatura($gateway, $cobranca->id, 'paga');

                if (!$this->temFaturaVencida()) {
                    $this->assinaturas->marcarEmDia($assinaturaId);
                }
                break;

            case EventoPagamento::COBRANCA_VENCIDA:
                $this->assinaturas->definirStatusFatura($gateway, $cobranca->id, 'vencida');
                $desde = (new DateTimeImmutable($cobranca->vencimento))->modify('+1 day')->format('Y-m-d');
                $this->assinaturas->marcarInadimplente($assinaturaId, $desde);
                break;

            case EventoPagamento::COBRANCA_CANCELADA:
                $this->assinaturas->definirStatusFatura($gateway, $cobranca->id, 'cancelada');
                break;
        }

        $this->auditoria->registrar('pagamento', 'fatura', null, null, [
            'evento' => $evento->tipo,
            'valor_centavos' => $cobranca->valorCentavos,
            'vencimento' => $cobranca->vencimento,
        ]);

        return self::PROCESSADO;
    }

    private function temFaturaVencida(): bool
    {
        return (bool) $this->pdo->query(<<<'SQL'
            SELECT EXISTS (SELECT 1 FROM fatura WHERE status = 'vencida' AND organizacao_id = cg_org_atual())
            SQL)->fetchColumn();
    }
}
