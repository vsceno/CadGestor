<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Services\LimitesPlano;
use App\Support\SituacaoAssinatura;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica a situação da assinatura às telas do cliente:
 * - aviso no topo (atraso, fim do teste, limite do plano perto ou atingido);
 * - somente leitura: o servidor recusa qualquer alteração (não só a tela);
 * - suspenso ou bloqueado: só "Minha assinatura", trocar senha e sair.
 * A equipe da plataforma não passa por aqui (não tem assinatura).
 */
class VerificaAssinatura
{
    /** Sempre liberadas: regularizar a assinatura, trocar a senha e sair. */
    private const LIVRES = ['assinatura.*', 'senha.*', 'sair'];

    public function __construct(private readonly LimitesPlano $limites) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof Usuario || $usuario->daPlataforma()) {
            return $next($request);
        }

        $assinatura = $this->limites->assinatura();
        $situacao = $assinatura?->situacao() ?? SituacaoAssinatura::semAssinatura();

        View::share('faixas', array_values(array_filter([$situacao->aviso, ...$this->limites->avisos()])));

        if ($request->routeIs(...self::LIVRES) || $situacao->permiteAlterar()) {
            return $next($request);
        }

        if ($situacao->permiteUsarSistema() && $request->isMethodSafe()) {
            return $next($request);
        }

        return redirect()->route('assinatura.index')->with('erro', (string) $situacao->aviso);
    }
}
