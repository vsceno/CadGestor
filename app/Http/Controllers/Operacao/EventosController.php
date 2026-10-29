<?php

namespace App\Http\Controllers\Operacao;

use App\Http\Controllers\Controller;
use App\Models\Alerta;
use App\Models\Catraca;
use App\Models\EventoAcesso;
use App\Services\Acesso\ResultadoAcesso;
use App\Services\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Eventos de acesso e alertas pendentes. Quem consulta trata os alertas, com
 * a observação de quem tratou. Nada é apagado: o tratamento fica registrado.
 */
class EventosController extends Controller
{
    use LePeriodo;

    /** Passagens na tela; a exportação completa fica no relatório. */
    private const LIMITE_EVENTOS = 300;

    public function index(Request $request): View
    {
        $filtros = $this->filtros($request, 7);

        $eventos = EventoAcesso::query()
            ->with(['catraca', 'pessoa'])
            ->whereBetween('data_hora', [$filtros['inicio'], $filtros['fim']])
            ->when($filtros['catraca'], fn ($consulta, $catraca) => $consulta->where('catraca_id', $catraca))
            ->when($filtros['resultado'], fn ($consulta, $resultado) => $consulta->where('resultado', $resultado))
            ->orderByDesc('data_hora')
            ->limit(self::LIMITE_EVENTOS)
            ->get();

        return view('operacao.eventos', [
            'eventos' => $eventos,
            'alertas' => Alerta::query()->with('catraca')->whereNull('tratado_em')->orderByDesc('data_hora')->limit(100)->get(),
            'catracas' => Catraca::query()->orderBy('nome')->get(['id', 'nome']),
            'filtros' => $filtros,
            'rotulos' => ResultadoAcesso::ROTULOS,
        ]);
    }

    public function tratarAlerta(Request $request, Alerta $alerta, Auditoria $auditoria): RedirectResponse
    {
        if ($alerta->tratado_em !== null) {
            return redirect()->route('eventos.index')->with('atencao', 'Este alerta já foi tratado.');
        }

        $dados = $request->validate(['observacao' => ['nullable', 'string', 'max:500']]);

        $alerta->forceFill([
            'tratado_por' => Auth::id(),
            'tratado_em' => now(),
            'observacao' => ($dados['observacao'] ?? '') === '' ? null : $dados['observacao'],
        ])->save();

        $auditoria->registrar('alterar', 'alerta', $alerta->id, ['tratado' => false], ['tratado' => true]);

        return redirect()->route('eventos.index')->with('sucesso', 'Alerta tratado.');
    }
}
