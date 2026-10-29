<?php

use App\Http\Middleware\AutenticarCatraca;
use App\Http\Middleware\CabecalhosSeguranca;
use App\Http\Middleware\DefinirContextoBanco;
use App\Http\Middleware\ExigePapel;
use App\Http\Middleware\ExigeTrocaSenha;
use App\Http\Middleware\VerificaAssinatura;
use App\Support\PreferenciasInterface;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CabecalhosSeguranca::class);

        // Isolamento entre clientes: o banco só enxerga o cliente do usuário logado.
        $middleware->web(append: [DefinirContextoBanco::class]);
        // Antes de buscar os modelos da URL (route binding): sem contexto, o RLS esconderia o registro.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: DefinirContextoBanco::class);

        // Preferências de interface (tema e menu recolhido), gravadas pelo JavaScript.
        // Não contêm dado pessoal; por isso não passam pela cifra de cookies.
        $middleware->encryptCookies(except: [PreferenciasInterface::COOKIE_TEMA, PreferenciasInterface::COOKIE_MENU]);

        $middleware->alias([
            'catraca' => AutenticarCatraca::class,
            'papel' => ExigePapel::class,
            'troca.senha' => ExigeTrocaSenha::class,
            'assinatura' => VerificaAssinatura::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('entrar'));
        $middleware->redirectUsersTo(fn () => route('inicio'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
