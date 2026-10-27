<?php

declare(strict_types=1);

namespace CadGestor\Dominio;

/**
 * Validação e formatação de CPF (mesma regra da função cpf_valido do banco).
 */
final class Cpf
{
    /** Remove máscara: "529.982.247-25" -> "52998224725". */
    public static function normalizar(string $cpf): string
    {
        return preg_replace('/\D/', '', $cpf) ?? '';
    }

    public static function valido(string $cpf): bool
    {
        $cpf = self::normalizar($cpf);

        if (preg_match('/^\d{11}$/', $cpf) !== 1 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($posicao = 9; $posicao <= 10; $posicao++) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cpf[$i] * ($posicao + 1 - $i);
            }

            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $cpf[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }
}
