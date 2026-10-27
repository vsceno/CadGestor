<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

use DomainException;

/**
 * Erro de validação de formulário, com uma mensagem por campo.
 */
final class ErroValidacao extends DomainException
{
    /** @param array<string, string> $erros campo => mensagem */
    public function __construct(public readonly array $erros)
    {
        parent::__construct('Há campos inválidos no formulário.');
    }

    public static function campo(string $campo, string $mensagem): self
    {
        return new self([$campo => $mensagem]);
    }
}
