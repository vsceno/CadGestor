<?php

namespace App\Support;

/**
 * Validação de CPF e CNPJ (inclusive o CNPJ alfanumérico da Receita:
 * 12 posições letra/número + 2 dígitos verificadores). O banco valida de
 * novo (cpf_valido / cnpj_valido): é a última trava.
 */
final class Documento
{
    private const PESOS_CNPJ_DV1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private const PESOS_CNPJ_DV2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    public static function normalizarCnpj(?string $cnpj): string
    {
        return preg_replace('/[^0-9A-Z]/', '', mb_strtoupper((string) $cnpj)) ?? '';
    }

    public static function cnpjValido(?string $cnpj): bool
    {
        $cnpj = self::normalizarCnpj($cnpj);

        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $cnpj) !== 1 || preg_match('/^(.)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        return (int) $cnpj[12] === self::digitoCnpj(substr($cnpj, 0, 12), self::PESOS_CNPJ_DV1)
            && (int) $cnpj[13] === self::digitoCnpj(substr($cnpj, 0, 13), self::PESOS_CNPJ_DV2);
    }

    public static function normalizarCpf(?string $cpf): string
    {
        return preg_replace('/\D/', '', (string) $cpf) ?? '';
    }

    public static function cpfValido(?string $cpf): bool
    {
        $cpf = self::normalizarCpf($cpf);

        if (preg_match('/^\d{11}$/', $cpf) !== 1 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($posicao = 9; $posicao < 11; $posicao++) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cpf[$i] * ($posicao + 1 - $i);
            }

            $digito = ($soma * 10) % 11 % 10;

            if ((int) $cpf[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }

    /** @param list<int> $pesos */
    private static function digitoCnpj(string $base, array $pesos): int
    {
        $soma = 0;

        foreach ($pesos as $i => $peso) {
            $soma += (ord($base[$i]) - 48) * $peso;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
