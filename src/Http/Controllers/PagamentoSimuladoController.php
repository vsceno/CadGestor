<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Cobranca\CobrancaGateway;
use CadGestor\Cobranca\EventoPagamento;
use CadGestor\Cobranca\GatewaySimulado;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\PagamentoServico;
use DateTimeImmutable;

/**
 * Página de pagamento do GatewaySimulado (só fora de produção). Simula o que
 * o gateway real faria: pagar, deixar vencer ou gerar a fatura do mês seguinte,
 * passando pelo MESMO processamento dos webhooks reais.
 */
final class PagamentoSimuladoController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly PagamentoServico $pagamentos,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function ver(Requisicao $requisicao, array $parametros): Resposta
    {
        $fatura = $this->assinaturas->faturaPorCobranca(GatewaySimulado::NOME, $parametros['id']);

        return $fatura === null
            ? $this->naoEncontrado()
            : $this->pagina('assinatura/pagamento-simulado', ['titulo' => 'Pagamento (simulado)', 'fatura' => $fatura]);
    }

    /** @param array<string, string> $parametros */
    public function executar(Requisicao $requisicao, array $parametros): Resposta
    {
        $fatura = $this->assinaturas->faturaPorCobranca(GatewaySimulado::NOME, $parametros['id']);

        if ($fatura === null) {
            return $this->naoEncontrado();
        }

        $cobranca = new CobrancaGateway(
            (string) $fatura['gateway_cobranca_id'],
            (int) $fatura['valor_centavos'],
            (string) $fatura['vencimento'],
            (string) $fatura['link_pagamento'],
        );

        [$tipo, $mensagem] = match ($requisicao->entrada('acao')) {
            'pagar' => [EventoPagamento::PAGAMENTO_CONFIRMADO, 'Pagamento simulado confirmado.'],
            'vencer' => [EventoPagamento::COBRANCA_VENCIDA, 'Fatura simulada marcada como vencida.'],
            'proxima' => [EventoPagamento::COBRANCA_CRIADA, 'Fatura do mês seguinte gerada.'],
            default => [null, null],
        };

        if ($tipo === null) {
            return $this->redirecionar('/assinatura');
        }

        if ($tipo === EventoPagamento::COBRANCA_CRIADA) {
            $vencimento = (new DateTimeImmutable((string) $fatura['vencimento']))->modify('+1 month')->format('Y-m-d');
            $cobranca = GatewaySimulado::novaCobranca((int) $fatura['valor_centavos'], $vencimento);
        }

        // A fatura nova ainda não existe: o cliente é achado pela assinatura no gateway.
        $assinaturaGateway = $this->assinaturas->daOrganizacao()['gateway_assinatura_id'] ?? null;

        $this->pagamentos->processar(
            new EventoPagamento('sim_evt_' . bin2hex(random_bytes(8)), $tipo, $assinaturaGateway, $cobranca),
            GatewaySimulado::NOME,
        );

        return $this->redirecionar('/assinatura', $mensagem);
    }
}
