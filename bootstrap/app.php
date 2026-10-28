<?php

use App\Http\Middleware\CabecalhosSeguranca;
use App\Support\PreferenciasInterface;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CabecalhosSeguranca::class);

        // Preferências de interface (tema e menu recolhido), gravadas pelo JavaScript.
        // Não contêm dado pessoal; por isso não passam pela cifra de cookies.
        $middleware->encryptCookies(except: [PreferenciasInterface::COOKIE_TEMA, PreferenciasInterface::COOKIE_MENU]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
