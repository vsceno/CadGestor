<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\PrimeirosPassos;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tela inicial: o painel da plataforma ou o início do cliente (com o cartão
 * "Primeiros passos" até a configuração ficar completa).
 */
class InicioController extends Controller
{
    public function __invoke(Request $request, PrimeirosPassos $primeirosPassos): View
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        if ($usuario->daPlataforma()) {
            return view('inicio.plataforma');
        }

        return view('inicio.cliente', ['passos' => $primeirosPassos->doCliente()]);
    }
}
