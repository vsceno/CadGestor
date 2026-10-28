<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\Plataforma\IndicadoresClientes;
use App\Services\PrimeirosPassos;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tela inicial: o painel da plataforma ou o início do cliente (com o cartão
 * "Primeiros passos" até a configuração ficar completa).
 */
class InicioController extends Controller
{
    public function __invoke(Request $request, PrimeirosPassos $primeirosPassos, IndicadoresClientes $indicadores): View
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        if ($usuario->daPlataforma()) {
            $clientes = $indicadores->clientes();
            $planos = $indicadores->planos()->keyBy('codigo');

            return view('inicio.plataforma', [
                'totais' => $indicadores->totais($clientes),
                'porPlano' => $indicadores->porPlano()->map(fn (int $total, string $codigo): array => [
                    'rotulo' => $planos[$codigo]->nome ?? $codigo,
                    'valor' => $total,
                    'texto' => $total.' '.($total === 1 ? 'cliente' : 'clientes'),
                ])->values()->all(),
                'recentes' => $clientes->sortByDesc('created_at')->take(5),
            ]);
        }

        return view('inicio.cliente', ['passos' => $primeirosPassos->doCliente()]);
    }
}
