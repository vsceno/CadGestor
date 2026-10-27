<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

/**
 * Regras mínimas de senha de operador.
 *
 * Preferimos comprimento a "complexidade forçada": uma frase longa é mais
 * forte e mais fácil de lembrar do que "S3nh@!".
 */
final class PoliticaSenha
{
    public const TAMANHO_MINIMO = 12;
    public const TAMANHO_MAXIMO = 128;

    private const COMUNS = [
        '123456789012', 'senha1234567', 'password1234', 'qwertyuiopas', 'cadgestor123',
        'administrador', 'admin1234567', '000000000000', '111111111111', 'abcdefghijkl',
    ];

    /** @return string|null mensagem de erro, ou null se a senha é aceitável */
    public static function validar(#[\SensitiveParameter] string $senha, string $email = ''): ?string
    {
        $tamanho = mb_strlen($senha);

        if ($tamanho < self::TAMANHO_MINIMO) {
            return 'A senha deve ter pelo menos ' . self::TAMANHO_MINIMO . ' caracteres.';
        }

        if ($tamanho > self::TAMANHO_MAXIMO) {
            return 'A senha deve ter no máximo ' . self::TAMANHO_MAXIMO . ' caracteres.';
        }

        $minuscula = mb_strtolower($senha);

        if (in_array($minuscula, self::COMUNS, true) || preg_match('/^(.)\1+$/u', $senha) === 1) {
            return 'Esta senha é muito comum. Escolha outra.';
        }

        $usuarioEmail = mb_strtolower(explode('@', $email)[0]);

        if ($usuarioEmail !== '' && mb_strlen($usuarioEmail) >= 4 && str_contains($minuscula, $usuarioEmail)) {
            return 'A senha não pode conter o seu e-mail.';
        }

        return null;
    }
}
