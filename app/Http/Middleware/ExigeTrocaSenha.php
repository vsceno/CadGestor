<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quem está com troca de senha obrigatória só acessa a tela de trocar senha
 * (e sair) até trocá-la.
 */
class ExigeTrocaSenha
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario instanceof Usuario && $usuario->deve_trocar_senha && ! $request->routeIs('senha.*', 'sair')) {
            return redirect()->route('senha.trocar')->with('atencao', 'Por segurança, crie uma nova senha antes de continuar.');
        }

        return $next($request);
    }
}
