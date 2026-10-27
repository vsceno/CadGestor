<?php

/**
 * Funções auxiliares globais usadas nas views.
 */

declare(strict_types=1);

if (!function_exists('e')) {
    /**
     * Escapa um valor para saída segura em HTML.
     */
    function e(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
