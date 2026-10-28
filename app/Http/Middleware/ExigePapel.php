<?php

namespace App\Http\Middleware;

use App\Enums\Papel;
use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checagem de papel em toda rota protegida: "papel:administrador,consulta"
 * libera quem tem pelo menos um dos papéis (o administrador do cliente tem
 * todos os do cliente; o da plataforma, só "plataforma").
 */
class ExigePapel
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$papeis): Response
    {
        $usuario = $request->user();
        $exigidos = array_map(static fn (string $papel): Papel => Papel::from($papel), $papeis);

        if (! $usuario instanceof Usuario || ! $usuario->temPapel(...$exigidos)) {
            abort(403, 'Você não tem permissão para acessar esta página.');
        }

        return $next($request);
    }
}
