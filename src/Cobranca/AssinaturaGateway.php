<?php

declare(strict_types=1);

namespace CadGestor\Cobranca;

/**
 * Resultado da criação da assinatura no gateway.
 */
final readonly class AssinaturaGateway
{
    public function __construct(
        public string $clienteId,
        public string $assinaturaId,
        public ?CobrancaGateway $primeiraCobranca,
    ) {
    }
}
