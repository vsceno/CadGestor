<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

/**
 * Hash de senhas de operadores com Argon2id (fallback para bcrypt).
 *
 * O hash é como um moedor de carne: dá para conferir se a mesma carne gera
 * o mesmo resultado, mas não dá para "desmoer" e recuperar a senha.
 */
final class HashSenha
{
    public const TAMANHO_MINIMO = 12;

    public function gerar(#[\SensitiveParameter] string $senha): string
    {
        return password_hash($senha, $this->algoritmo());
    }

    public function verificar(#[\SensitiveParameter] string $senha, string $hash): bool
    {
        return password_verify($senha, $hash);
    }

    /** Indica que o hash foi gerado com algoritmo/custo antigo e deve ser refeito no próximo login. */
    public function precisaRefazer(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algoritmo());
    }

    /**
     * Senha aleatória legível (sem caracteres ambíguos como 0/O e 1/l),
     * usada para o administrador inicial.
     */
    public function gerarSenhaAleatoria(int $tamanho = 20): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789-_!@#';
        $limite = strlen($alfabeto) - 1;
        $senha = '';

        for ($i = 0; $i < max($tamanho, self::TAMANHO_MINIMO); $i++) {
            $senha .= $alfabeto[random_int(0, $limite)];
        }

        return $senha;
    }

    private function algoritmo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
