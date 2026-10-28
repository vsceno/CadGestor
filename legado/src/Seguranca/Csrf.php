<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

/**
 * Proteção contra CSRF: todo formulário leva um token secreto da sessão.
 *
 * É como uma senha combinada entre o sistema e a página que ele mesmo
 * entregou: um site malicioso consegue enviar o formulário, mas não sabe a
 * senha, então o envio é recusado.
 */
final class Csrf
{
    public const CAMPO = '_csrf';
    private const CHAVE_SESSAO = '_csrf_token';

    public function __construct(private readonly Sessao $sessao)
    {
    }

    public function token(): string
    {
        $token = $this->sessao->obter(self::CHAVE_SESSAO);

        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->sessao->definir(self::CHAVE_SESSAO, $token);
        }

        return $token;
    }

    public function validar(?string $recebido): bool
    {
        $esperado = $this->sessao->obter(self::CHAVE_SESSAO);

        return is_string($esperado) && is_string($recebido) && hash_equals($esperado, $recebido);
    }

    /** Troca o token (ex.: após login), invalidando formulários antigos. */
    public function renovar(): void
    {
        $this->sessao->remover(self::CHAVE_SESSAO);
    }
}
