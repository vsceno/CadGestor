<?php

declare(strict_types=1);

namespace CadGestor\Cobranca;

/**
 * Uma cobrança (fatura) gerada pelo gateway.
 */
final readonly class CobrancaGateway
{
    public function __construct(
        public string $id,
        public int $valorCentavos,
        public string $vencimento,
        public ?string $linkPagamento,
    ) {
    }
}
