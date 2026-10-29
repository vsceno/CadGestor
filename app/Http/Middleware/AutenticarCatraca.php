<?php

namespace App\Http\Middleware;

use App\Models\Catraca;
use App\Services\Cadastro\CatracaServico;
use App\Support\ContextoBanco;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifica a catraca pelo token (Bearer cgt_...). A busca do token usa uma
 * função de segurança própria (sem RLS); depois disso o contexto passa a ser o
 * do cliente da catraca, e tudo o mais segue as regras de isolamento.
 */
class AutenticarCatraca
{
    public function __construct(private readonly ContextoBanco $contexto) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        if (preg_match('/^'.CatracaServico::PREFIXO_TOKEN.'[0-9a-f]{64}$/', $token) !== 1) {
            return $this->recusar();
        }

        $linha = DB::connection('pgsql')->selectOne(
            'SELECT catraca_id, organizacao_id FROM cg_catraca_por_token(?)',
            [CatracaServico::hashToken($token)],
        );

        if ($linha === null) {
            return $this->recusar();
        }

        $this->contexto->definir((string) $linha->organizacao_id);

        try {
            $catraca = Catraca::query()->with('empresa')->find($linha->catraca_id);

            if ($catraca === null) {
                return $this->recusar();
            }

            $request->attributes->set('catraca', $catraca);

            return $next($request);
        } finally {
            $this->contexto->limpar();
        }
    }

    private function recusar(): JsonResponse
    {
        return response()->json(['erro' => 'token_invalido'], 401);
    }
}
