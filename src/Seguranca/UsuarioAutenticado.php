<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

/**
 * Usuário logado na requisição atual.
 */
final readonly class UsuarioAutenticado
{
    /** @param list<Papel> $papeis */
    public function __construct(
        public string $id,
        public string $nome,
        public string $email,
        public array $papeis,
        public bool $deveTrocarSenha,
    ) {
    }

    /** Verdadeiro se tiver QUALQUER um dos papéis (administrador tem todos). */
    public function temPapel(Papel ...$papeis): bool
    {
        if (in_array(Papel::Administrador, $this->papeis, true)) {
            return true;
        }

        foreach ($papeis as $papel) {
            if (in_array($papel, $this->papeis, true)) {
                return true;
            }
        }

        return false;
    }

    public function primeiroNome(): string
    {
        return explode(' ', $this->nome)[0];
    }
}
