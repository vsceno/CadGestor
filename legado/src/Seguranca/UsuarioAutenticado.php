<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

/**
 * Usuário logado na requisição atual.
 *
 * $organizacaoId é a organização do usuário (null = usuário da plataforma,
 * que nunca opera dentro de uma organização).
 */
final readonly class UsuarioAutenticado
{
    /** @param list<Papel> $papeis papéis efetivos nesta requisição */
    public function __construct(
        public string $id,
        public string $nome,
        public string $email,
        public array $papeis,
        public bool $deveTrocarSenha,
        public ?string $organizacaoId = null,
        public ?string $organizacaoNome = null,
        public bool $daPlataforma = false,
    ) {
    }

    /**
     * Verdadeiro se tiver QUALQUER um dos papéis. O administrador tem todos
     * os papéis da organização, mas não o da plataforma.
     */
    public function temPapel(Papel ...$papeis): bool
    {
        $administrador = in_array(Papel::Administrador, $this->papeis, true);

        foreach ($papeis as $papel) {
            if (in_array($papel, $this->papeis, true) || ($administrador && $papel !== Papel::Plataforma)) {
                return true;
            }
        }

        return false;
    }

    /** Usuário da plataforma fora de qualquer organização. */
    public function emModoPlataforma(): bool
    {
        return $this->daPlataforma && $this->organizacaoId === null;
    }

    public function primeiroNome(): string
    {
        return explode(' ', $this->nome)[0];
    }
}
