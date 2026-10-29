<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Faixa etária do titular, usada na regra de menores (LGPD, art. 14): menor de
 * 18 anos só entra com o consentimento do responsável legal.
 */
final class Idade
{
    public const MAIORIDADE = 18;

    /** @return 'menor'|'adulto'|'desconhecida' */
    public static function faixa(?CarbonInterface $nascimento, CarbonInterface $hoje): string
    {
        if ($nascimento === null) {
            return 'desconhecida';
        }

        return (int) $nascimento->diffInYears($hoje, true) < self::MAIORIDADE ? 'menor' : 'adulto';
    }
}
