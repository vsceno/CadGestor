<?php

declare(strict_types=1);

namespace CadGestor\Cobranca;

/**
 * Aviso do gateway já traduzido para a linguagem do CadGestor.
 */
final readonly class EventoPagamento
{
    public const COBRANCA_CRIADA = 'cobranca_criada';
    public const PAGAMENTO_CONFIRMADO = 'pagamento_confirmado';
    public const COBRANCA_VENCIDA = 'cobranca_vencida';
    public const COBRANCA_CANCELADA = 'cobranca_cancelada';

    public const TIPOS = [
        self::COBRANCA_CRIADA, self::PAGAMENTO_CONFIRMADO, self::COBRANCA_VENCIDA, self::COBRANCA_CANCELADA,
    ];

    /** @param string $id identificador ÚNICO do aviso no gateway (para não processar duas vezes) */
    public function __construct(
        public string $id,
        public string $tipo,
        public ?string $assinaturaId,
        public CobrancaGateway $cobranca,
    ) {
    }
}
