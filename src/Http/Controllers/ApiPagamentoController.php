<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Cobranca\GatewayPagamento;
use CadGestor\Cobranca\WebhookInvalido;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Servicos\PagamentoServico;

/**
 * POST /api/v1/pagamentos/webhook: avisos do gateway de pagamento.
 *
 * Sem sessão nem CSRF; a autenticidade é conferida pelo adaptador do gateway.
 * Responde 200 para tudo o que foi aceito (inclusive duplicados), para o
 * gateway não reenviar sem necessidade.
 */
final class ApiPagamentoController
{
    public function __construct(
        private readonly GatewayPagamento $gateway,
        private readonly PagamentoServico $pagamentos,
    ) {
    }

    public function webhook(Requisicao $requisicao): Resposta
    {
        try {
            $evento = $this->gateway->interpretarWebhook($requisicao);
        } catch (WebhookInvalido) {
            return Resposta::json(['erro' => 'nao_autorizado'], 401);
        }

        if ($evento === null) {
            return Resposta::json(['resultado' => 'ignorado']);
        }

        return Resposta::json(['resultado' => $this->pagamentos->processar($evento, $this->gateway->nome())]);
    }
}
