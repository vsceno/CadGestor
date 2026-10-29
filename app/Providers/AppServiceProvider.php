<?php

namespace App\Providers;

use App\Auth\ProvedorUsuarios;
use App\Models\Usuario;
use App\Services\Biometria\CifradorBiometria;
use App\Support\ContextoBanco;
use App\Support\Navegacao;
use App\Support\PreferenciasInterface;
use Illuminate\Database\Eloquent\Model;
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

        // A chave só é lida quando a biometria é usada: o resto do sistema roda sem ela.
        $this->app->singleton(CifradorBiometria::class, fn (): CifradorBiometria => CifradorBiometria::doArquivo(
            CifradorBiometria::resolverCaminho(config('biometria.chaves_arquivo'), base_path()),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fora de produção, campo descartado em silêncio (não preenchível) vira erro: bug não passa despercebido.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Auth::provider('cadgestor', fn ($app): ProvedorUsuarios => new ProvedorUsuarios(
            $app['hash'],
            $app->make(ContextoBanco::class),
        ));

        View::composer('components.layouts.*', function (\Illuminate\View\View $view): void {
            $view->with('preferencias', PreferenciasInterface::daRequisicao(request()));
        });

        View::composer('components.layouts.app', function (\Illuminate\View\View $view): void {
            $usuario = Auth::user();

            if ($usuario instanceof Usuario) {
                $view->with('navegacao', Navegacao::para($usuario));
            }
        });

        // Em produção, e-mail de verdade: nunca "log" (gravaria links de senha em arquivo).
        if ($this->app->isProduction() && in_array(config('mail.default'), ['log', 'array'], true)) {
            throw new \RuntimeException('Configure um provedor de e-mail real (MAIL_MAILER) em produção.');
        }
    }
}
