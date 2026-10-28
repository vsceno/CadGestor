<?php

declare(strict_types=1);

namespace CadGestor\Http\Middleware;

use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use Closure;

/**
 * Adia a criação de um middleware até a rota ser realmente chamada.
 * Assim, registrar as rotas não abre sessão nem conexão com o banco.
 */
final class Preguicoso implements Middleware
{
    private ?Middleware $instancia = null;

    /** @param Closure(): Middleware $fabrica */
    public function __construct(private readonly Closure $fabrica)
    {
    }

    public function processar(Requisicao $requisicao, callable $proximo): Resposta
    {
        $this->instancia ??= ($this->fabrica)();

        return $this->instancia->processar($requisicao, $proximo);
    }
}
