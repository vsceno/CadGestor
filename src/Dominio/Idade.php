<?php

declare(strict_types=1);

namespace CadGestor\Dominio;

use DateTimeImmutable;

/**
 * Idade do titular, usada na regra de menores (consentimento do responsável).
 */
final class Idade
{
    public const MAIORIDADE = 18;

    /** @return int|null idade em anos completos, ou null se a data é desconhecida/inválida */
    public static function emAnos(?string $dataNascimento, DateTimeImmutable $em): ?int
    {
        if ($dataNascimento === null || $dataNascimento === '') {
            return null;
        }

        $nascimento = DateTimeImmutable::createFromFormat('!Y-m-d', $dataNascimento, $em->getTimezone());

        if ($nascimento === false || $nascimento->format('Y-m-d') !== $dataNascimento || $nascimento > $em) {
            return null;
        }

        return $nascimento->diff($em)->y;
    }

    /**
     * Situação para fins de coleta biométrica:
     *  - 'adulto'        maior de idade
     *  - 'menor'         exige consentimento do responsável legal
     *  - 'desconhecida'  idade não verificável: coleta BLOQUEADA
     */
    public static function classificar(?string $dataNascimento, DateTimeImmutable $em): string
    {
        $idade = self::emAnos($dataNascimento, $em);

        return match (true) {
            $idade === null => 'desconhecida',
            $idade < self::MAIORIDADE => 'menor',
            default => 'adulto',
        };
    }
}
