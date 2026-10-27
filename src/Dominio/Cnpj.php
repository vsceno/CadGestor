<?php

declare(strict_types=1);

namespace CadGestor\Dominio;

/**
 * Validação de CNPJ numérico e alfanumérico (Receita Federal, a partir de
 * julho/2026). Mesma regra da função cnpj_valido do banco.
 *
 * No formato alfanumérico, cada caractere vale (código ASCII - 48): os
 * dígitos continuam valendo 0 a 9 e as letras A-Z passam a valer 17 a 42.
 */
final class Cnpj
{
    private const PESOS_DV1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    private const PESOS_DV2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /** Remove máscara e coloca em maiúsculas: "12.abc.345/01de-35" -> "12ABC34501DE35". */
    public static function normalizar(string $cnpj): string
    {
        return preg_replace('/[^0-9A-Z]/', '', mb_strtoupper($cnpj)) ?? '';
    }

    public static function valido(string $cnpj): bool
    {
        $cnpj = self::normalizar($cnpj);

        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $cnpj) !== 1 || preg_match('/^(.)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        return (int) $cnpj[12] === self::digito(substr($cnpj, 0, 12), self::PESOS_DV1)
            && (int) $cnpj[13] === self::digito(substr($cnpj, 0, 13), self::PESOS_DV2);
    }

    /** @param list<int> $pesos */
    private static function digito(string $base, array $pesos): int
    {
        $soma = 0;

        foreach ($pesos as $i => $peso) {
            $soma += (ord($base[$i]) - 48) * $peso;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
