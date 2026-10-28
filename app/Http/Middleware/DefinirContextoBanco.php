<?php

namespace App\Http\Middleware;

use App\Support\ContextoBanco;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toda requisição começa SEM contexto (nenhum dado de cliente visível) e só
 * ganha o contexto do usuário autenticado, definido pelo ProvedorUsuarios
 * ao carregar a conta da sessão.
 */
class DefinirContextoBanco
{
    public function __construct(private readonly ContextoBanco $contexto) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->contexto->limpar();
        Auth::user();

        try {
            return $next($request);
        } finally {
            $this->contexto->limpar();
        }
    }
}
