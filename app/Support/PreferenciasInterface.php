<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Preferências de interface do navegador: tema (claro, escuro ou seguir o
 * sistema) e menu lateral recolhido. Lidas no servidor para a página já sair
 * no tema certo (sem "piscar"); gravadas pelo JavaScript em cookies simples.
 */
class PreferenciasInterface
{
    public const COOKIE_TEMA = 'cg_tema';

    public const COOKIE_MENU = 'cg_menu';

    public const TEMAS = ['sistema', 'claro', 'escuro'];

    public function __construct(public readonly string $tema, public readonly bool $menuRecolhido) {}

    public static function daRequisicao(Request $requisicao): self
    {
        $tema = (string) $requisicao->cookie(self::COOKIE_TEMA, 'sistema');

        return new self(
            in_array($tema, self::TEMAS, true) ? $tema : 'sistema',
            $requisicao->cookie(self::COOKIE_MENU) === 'recolhido',
        );
    }

    /** Valor do atributo data-tema do <html> (null = seguir o sistema). */
    public function atributoTema(): ?string
    {
        return $this->tema === 'sistema' ? null : $this->tema;
    }
}
