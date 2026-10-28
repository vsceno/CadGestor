<?php

declare(strict_types=1);

namespace CadGestor\Cobranca;

use CadGestor\Nucleo\Requisicao;
use DateTimeImmutable;

/**
 * Contrato com o gateway de pagamento (Asaas, Mercado Pago, Stripe...).
 *
 * O gateway faz o trabalho pesado: página de pagamento, cartão/Pix/boleto,
 * cobrança recorrente e lembretes. O CadGestor só cria a assinatura e recebe
 * os avisos (webhooks) de cobrança criada, paga ou vencida.
 *
 * NENHUM dado de cartão passa pelo CadGestor. O adaptador real entra em
 * src/Cobranca/Adaptadores, escrito a partir da documentação oficial do
 * gateway escolhido; até lá vale o GatewaySimulado (só fora de produção).
 */
interface GatewayPagamento
{
    /** Identificador curto gravado junto das assinaturas e faturas (ex.: "simulado"). */
    public function nome(): string;

    /**
     * Cria o cliente e a assinatura recorrente mensal.
     *
     * @param array{nome: string, cnpj: string, email: string} $cliente
     * @param array{codigo: string, nome: string, preco_centavos: int} $plano
     */
    public function criarAssinatura(
        array $cliente,
        array $plano,
        DateTimeImmutable $primeiroVencimento,
    ): AssinaturaGateway;

    /** @param array{codigo: string, nome: string, preco_centavos: int} $plano */
    public function alterarPlano(string $assinaturaId, array $plano): void;

    public function cancelarAssinatura(string $assinaturaId): void;

    /**
     * Pagamento recebido fora do gateway (Pix direto, transferência): avisa o
     * gateway para ele não cobrar nem lembrar o cliente dessa fatura.
     */
    public function registrarPagamentoManual(string $cobrancaId, DateTimeImmutable $pagoEm): void;

    /** Nova data de vencimento de uma cobrança ainda em aberto (ex.: teste grátis estendido). */
    public function alterarVencimento(string $cobrancaId, DateTimeImmutable $vencimento): void;

    /**
     * Confere a autenticidade do aviso e o traduz.
     *
     * @return EventoPagamento|null null = aviso que não nos interessa (responder 200 e ignorar)
     * @throws WebhookInvalido quando o aviso não é autêntico
     */
    public function interpretarWebhook(Requisicao $requisicao): ?EventoPagamento;
}
