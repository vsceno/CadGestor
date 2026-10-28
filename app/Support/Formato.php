<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Padrões brasileiros de exibição: data dd/mm/aaaa, hora de 24 horas, moeda
 * em R$, CPF/CNPJ formatados e telefone com DDD.
 *
 * O CPF sai mascarado por padrão (***.456.789-**). O número completo só deve
 * ser pedido por quem tem permissão, e esse acesso é auditado por quem chama.
 */
class Formato
{
    public static function data(DateTimeInterface|string|null $valor): string
    {
        return self::carbon($valor)?->format('d/m/Y') ?? '';
    }

    public static function dataHora(DateTimeInterface|string|null $valor, bool $comSegundos = false): string
    {
        return self::carbon($valor)?->format($comSegundos ? 'd/m/Y H:i:s' : 'd/m/Y H:i') ?? '';
    }

    public static function hora(DateTimeInterface|string|null $valor): string
    {
        return self::carbon($valor)?->format('H:i') ?? '';
    }

    public static function moeda(int $centavos): string
    {
        return 'R$ '.number_format($centavos / 100, 2, ',', '.');
    }

    public static function numero(int|float $valor, int $casas = 0): string
    {
        return number_format($valor, $casas, ',', '.');
    }

    /** CPF mascarado: só os dígitos do meio ficam visíveis. */
    public static function cpfMascarado(?string $cpf): string
    {
        $digitos = self::digitos($cpf);

        return strlen($digitos) === 11
            ? '***.'.substr($digitos, 3, 3).'.'.substr($digitos, 6, 3).'-**'
            : '';
    }

    /** CPF completo: use apenas para quem tem permissão (e registre na auditoria). */
    public static function cpf(?string $cpf): string
    {
        $digitos = self::digitos($cpf);

        return strlen($digitos) === 11
            ? substr($digitos, 0, 3).'.'.substr($digitos, 3, 3).'.'.substr($digitos, 6, 3).'-'.substr($digitos, 9, 2)
            : (string) $cpf;
    }

    /** CNPJ, inclusive o alfanumérico (12 posições letra/número + 2 dígitos). */
    public static function cnpj(?string $cnpj): string
    {
        $valor = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $cnpj) ?? '');

        if (strlen($valor) !== 14) {
            return (string) $cnpj;
        }

        return substr($valor, 0, 2).'.'.substr($valor, 2, 3).'.'.substr($valor, 5, 3).'/'
            .substr($valor, 8, 4).'-'.substr($valor, 12, 2);
    }

    public static function cep(?string $cep): string
    {
        $digitos = self::digitos($cep);

        return strlen($digitos) === 8 ? substr($digitos, 0, 5).'-'.substr($digitos, 5) : (string) $cep;
    }

    /** Telefone com DDD: (85) 3222-1100 ou (85) 99999-0000. */
    public static function telefone(?string $telefone): string
    {
        $digitos = self::digitos($telefone);

        return match (strlen($digitos)) {
            10 => '('.substr($digitos, 0, 2).') '.substr($digitos, 2, 4).'-'.substr($digitos, 6),
            11 => '('.substr($digitos, 0, 2).') '.substr($digitos, 2, 5).'-'.substr($digitos, 7),
            default => (string) $telefone,
        };
    }

    private static function digitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }

    private static function carbon(DateTimeInterface|string|null $valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return Carbon::parse($valor)->setTimezone(config('app.timezone'));
    }
}
