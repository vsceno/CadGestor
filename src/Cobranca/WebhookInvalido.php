<?php

declare(strict_types=1);

namespace CadGestor\Cobranca;

use RuntimeException;

/**
 * Aviso de pagamento que não veio do gateway (assinatura/token não confere).
 */
final class WebhookInvalido extends RuntimeException
{
}
