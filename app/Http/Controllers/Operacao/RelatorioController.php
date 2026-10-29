<?php

namespace App\Http\Controllers\Operacao;

use App\Http\Controllers\Controller;
use App\Models\EventoAcesso;
use App\Services\Acesso\ResultadoAcesso;
use App\Services\Auditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Relatório de passagens: totais, passagens por dia e motivos das negações.
 * A exportação em CSV traz nomes de pessoas, então é só do administrador e fica na auditoria.
 */
class RelatorioController extends Controller
{
    use LePeriodo;

    /** Acima disso a exportação é recusada: o período precisa ser menor. */
    private const LIMITE_EXPORTACAO = 20000;

    public function index(Request $request): View
    {
        $filtros = $this->filtros($request, 30);
        $base = $this->base($filtros);

        $passagens = (clone $base)->count();
        $liberadas = (clone $base)->where('resultado', 'liberado')->count();

        $porDia = (clone $base)
            ->selectRaw('DATE(data_hora) AS dia, count(*) AS total')
            ->groupByRaw('DATE(data_hora)')
            ->orderBy('dia')
            ->get()
            ->map(fn ($linha): array => [
                'rotulo' => Carbon::parse($linha->dia)->format('d/m'),
                'valor' => (int) $linha->total,
                'texto' => (string) $linha->total,
            ])
            ->all();

        $motivos = (clone $base)
            ->where('resultado', 'negado')
            ->selectRaw('motivo_negacao, count(*) AS total')
            ->groupBy('motivo_negacao')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($linha): array => [
                'rotulo' => ResultadoAcesso::ROTULOS[$linha->motivo_negacao] ?? 'motivo não informado',
                'valor' => (int) $linha->total,
                'texto' => (string) $linha->total,
            ])
            ->all();

        return view('relatorios.index', [
            'filtros' => $filtros,
            'totais' => ['passagens' => $passagens, 'liberadas' => $liberadas, 'negadas' => $passagens - $liberadas],
            'porDia' => $porDia,
            'motivos' => $motivos,
        ]);
    }

    public function exportar(Request $request, Auditoria $auditoria): Response
    {
        $filtros = $this->filtros($request, 30);
        $base = $this->base($filtros);
        $total = (clone $base)->count();

        if ($total > self::LIMITE_EXPORTACAO) {
            throw ValidationException::withMessages([
                'fim' => 'Este período tem mais de '.number_format(self::LIMITE_EXPORTACAO, 0, ',', '.').' passagens. Exporte um período menor.',
            ]);
        }

        // Quem exportou, o período e a quantidade ficam na trilha; o conteúdo, não.
        $auditoria->registrar('exportar', 'evento_acesso', null, null, [
            'inicio' => $filtros['inicio']->toDateString(),
            'fim' => $filtros['fim']->toDateString(),
            'linhas' => $total,
        ]);

        $eventos = (clone $base)->with(['catraca', 'pessoa'])->orderBy('data_hora')->get();

        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\xEF\xBB\xBF"); // BOM: o Excel abre os acentos certos.
        fputcsv($saida, ['Data e hora', 'Catraca', 'Pessoa', 'Resultado', 'Motivo', 'Origem'], ';');

        foreach ($eventos as $evento) {
            fputcsv($saida, [
                $evento->data_hora->format('d/m/Y H:i:s'),
                $evento->catraca->nome,
                $evento->pessoa?->nome ?? 'Não identificada',
                $evento->resultado === 'liberado' ? 'Liberado' : 'Negado',
                $evento->motivo_negacao ? (ResultadoAcesso::ROTULOS[$evento->motivo_negacao] ?? '') : '',
                $evento->offline ? 'Sem servidor' : 'Online',
            ], ';');
        }

        rewind($saida);
        $conteudo = stream_get_contents($saida);
        fclose($saida);

        return response($conteudo, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="passagens-'.$filtros['inicio']->format('Y-m-d').'-a-'.$filtros['fim']->format('Y-m-d').'.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param  array{inicio: Carbon, fim: Carbon, catraca: ?string, resultado: ?string}  $filtros */
    private function base(array $filtros): Builder
    {
        return EventoAcesso::query()
            ->whereBetween('data_hora', [$filtros['inicio'], $filtros['fim']])
            ->when($filtros['catraca'], fn (Builder $consulta, string $catraca): Builder => $consulta->where('catraca_id', $catraca))
            ->when($filtros['resultado'], fn (Builder $consulta, string $resultado): Builder => $consulta->where('resultado', $resultado));
    }
}
