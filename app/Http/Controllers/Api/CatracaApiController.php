<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catraca;
use App\Models\FilaSincronizacao;
use App\Services\Acesso\DecisaoAcesso;
use App\Services\Acesso\RegistroAcesso;
use App\Services\Acesso\ResultadoAcesso;
use App\Services\Acesso\SincronizacaoDispositivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API das catracas. Todas as rotas passam pelo middleware "catraca", que
 * identifica o equipamento pelo token antes de qualquer coisa.
 */
class CatracaApiController extends Controller
{
    private const LIMITE_OPERACOES = 50;

    private const LIMITE_EVENTOS_OFFLINE = 500;

    public function heartbeat(Request $request): JsonResponse
    {
        $catraca = $this->catraca($request);
        $catraca->forceFill(['ultimo_contato_em' => now()])->save();

        return response()->json([
            'servidor_em' => now()->toIso8601String(),
            'ativa' => $catraca->ativo,
            'modo_falha' => $catraca->modo_falha,
            'pendentes' => FilaSincronizacao::query()
                ->where('catraca_id', $catraca->id)
                ->whereIn('status', ['pendente', 'falhou'])
                ->count(),
        ]);
    }

    public function sincronizacao(Request $request, SincronizacaoDispositivo $sincronizacao): JsonResponse
    {
        return response()->json([
            'operacoes' => $sincronizacao->pendentes($this->catraca($request), self::LIMITE_OPERACOES),
        ]);
    }

    public function resultadoSincronizacao(Request $request, string $operacao, SincronizacaoDispositivo $sincronizacao): JsonResponse
    {
        $dados = $request->validate([
            'sucesso' => ['required', 'boolean'],
            'erro' => ['nullable', 'string', 'regex:/^[a-z0-9_]{1,60}$/'],
        ]);

        if (! $sincronizacao->resultado($this->catraca($request), $operacao, (bool) $dados['sucesso'], $dados['erro'] ?? null)) {
            return response()->json(['erro' => 'operacao_nao_encontrada'], 404);
        }

        return response()->json(['ok' => true]);
    }

    public function acesso(Request $request, DecisaoAcesso $decisao, RegistroAcesso $registro): JsonResponse
    {
        $dados = $request->validate([
            'biometria_id' => ['nullable', 'uuid'],
            'tipo_identificacao' => ['required', Rule::in(['digital', 'facial'])],
            'score' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $catraca = $this->catraca($request);
        $resultado = $decisao->decidir($catraca, $dados['biometria_id'] ?? null, now());
        $registro->registrar($catraca, $resultado, $dados['tipo_identificacao'], isset($dados['score']) ? (string) $dados['score'] : null);

        return response()->json([
            'resultado' => $resultado->resultado,
            'motivo' => $resultado->motivo,
            'abrir' => $resultado->liberou(),
        ]);
    }

    public function eventosOffline(Request $request, RegistroAcesso $registro): JsonResponse
    {
        $dados = $request->validate([
            'eventos' => ['required', 'array', 'max:'.self::LIMITE_EVENTOS_OFFLINE],
            'eventos.*.data_hora' => ['required', 'date'],
            'eventos.*.tipo_identificacao' => ['required', Rule::in(['digital', 'facial'])],
            'eventos.*.resultado' => ['required', Rule::in(['liberado', 'negado'])],
            'eventos.*.motivo' => ['nullable', Rule::in(array_keys(ResultadoAcesso::ROTULOS))],
            'eventos.*.biometria_id' => ['nullable', 'uuid'],
        ]);

        return response()->json([
            'recebidos' => $registro->registrarOffline($this->catraca($request), $dados['eventos']),
        ]);
    }

    private function catraca(Request $request): Catraca
    {
        /** @var Catraca $catraca */
        $catraca = $request->attributes->get('catraca');

        return $catraca;
    }
}
