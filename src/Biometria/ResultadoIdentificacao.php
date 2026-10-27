<?php

declare(strict_types=1);

namespace CadGestor\Biometria;

/**
 * Resposta de uma identificação biométrica: quem foi reconhecido (referência
 * da pessoa no dispositivo, ou null) e a confiança do reconhecimento (0 a 1).
 */
final readonly class ResultadoIdentificacao
{
    public function __construct(
        public ?string $referencia,
        public float $score,
    ) {
    }

    public static function naoIdentificado(): self
    {
        return new self(null, 0.0);
    }
}
