<?php

declare(strict_types=1);

namespace CadGestor\Http\Middleware;

use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;

/**
 * Camada que envolve o controller, como os portões de um condomínio:
 * cada um confere algo (sessão, login, papel, CSRF) e deixa passar ou barra.
 */
interface Middleware
{
    /** @param callable(Requisicao): Resposta $proximo */
    public function processar(Requisicao $requisicao, callable $proximo): Resposta;
}
