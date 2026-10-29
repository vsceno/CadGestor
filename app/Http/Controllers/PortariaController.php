<?php

namespace App\Http\Controllers;

use App\Models\Catraca;
use App\Models\EventoAcesso;
use Illuminate\View\View;

/**
 * Portaria ao vivo: situação das catracas e as últimas passagens. Só leitura.
 */
class PortariaController extends Controller
{
    /** Catraca sem contato há mais que isso aparece como sem contato. */
    private const SEGUNDOS_SEM_CONTATO = 120;

    /** Quantidade de passagens na tela. */
    private const LIMITE_EVENTOS = 50;

    public function index(): View
    {
        return view('portaria.index', [
            'catracas' => Catraca::query()->with('empresa')->where('ativo', true)->orderBy('nome')->get(),
            'eventos' => EventoAcesso::query()->with(['catraca', 'pessoa'])
                ->orderByDesc('data_hora')
                ->limit(self::LIMITE_EVENTOS)
                ->get(),
            'limiteContato' => now()->subSeconds(self::SEGUNDOS_SEM_CONTATO),
        ]);
    }
}
