<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Política de senha: 12 a 128 caracteres, não pode ser uma senha comum nem
 * conter o e-mail. Uma frase longa é mais fácil de lembrar e mais segura.
 *
 * Não consulta serviços externos de vazamento: nenhuma informação da senha
 * sai do servidor.
 */
class SenhaForte implements ValidationRule
{
    public const TAMANHO_MINIMO = 12;

    public const TAMANHO_MAXIMO = 128;

    private const COMUNS = [
        '123456789012', 'senha1234567', 'password1234', 'qwertyuiopas', 'cadgestor123',
        'administrador', 'admin1234567', '000000000000', '111111111111', 'abcdefghijkl',
    ];

    public function __construct(private readonly string $email = '') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $senha = (string) $value;
        $tamanho = mb_strlen($senha);

        if ($tamanho < self::TAMANHO_MINIMO) {
            $fail('A senha deve ter pelo menos '.self::TAMANHO_MINIMO.' caracteres.');

            return;
        }

        if ($tamanho > self::TAMANHO_MAXIMO) {
            $fail('A senha deve ter no máximo '.self::TAMANHO_MAXIMO.' caracteres.');

            return;
        }

        $minuscula = mb_strtolower($senha);

        if (in_array($minuscula, self::COMUNS, true) || preg_match('/^(.)\1+$/u', $senha) === 1) {
            $fail('Esta senha é muito comum. Escolha outra.');

            return;
        }

        $usuarioEmail = mb_strtolower(explode('@', $this->email)[0]);

        if (mb_strlen($usuarioEmail) >= 4 && str_contains($minuscula, $usuarioEmail)) {
            $fail('A senha não pode conter o seu e-mail.');
        }
    }
}
