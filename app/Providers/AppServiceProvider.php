<?php

namespace App\Providers;

use App\Auth\ProvedorUsuarios;
use App\Support\ContextoBanco;
use App\Support\PreferenciasInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Um contexto de banco por requisição (o "crachá" do RLS).
        $this->app->scoped(ContextoBanco::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('cadgestor', fn ($app): ProvedorUsuarios => new ProvedorUsuarios(
            $app['hash'],
            $app->make(ContextoBanco::class),
        ));

        View::composer('components.layouts.*', function (\Illuminate\View\View $view): void {
            $view->with('preferencias', PreferenciasInterface::daRequisicao(request()));
        });
    }
}
