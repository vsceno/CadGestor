<?php

namespace App\Http\Controllers\Cadastro;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\PerfilVinculo;
use App\Models\RegraAcesso;
use App\Services\Cadastro\RegraAcessoServico;
use App\Support\IntervaloHorario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Regras de acesso: por empresa e perfil, em quais dias, horários e catracas.
 * Editor de linhas (a visão em grade de perfis x catracas vem depois).
 */
class RegraController extends Controller
{
    use LeEntradas;

    /** Linhas em branco oferecidas ao final do editor. */
    private const LINHAS_EXTRAS = 4;

    public function index(): View
    {
        $empresas = Empresa::query()
            ->where('ativo', true)
            ->with(['perfis' => fn ($consulta) => $consulta->where('ativo', true)->orderBy('nome')])
            ->orderBy('razao_social')
            ->get();

        $totais = RegraAcesso::query()
            ->selectRaw('empresa_id, perfil_id, count(*) AS total')
            ->groupBy('empresa_id', 'perfil_id')
            ->get()
            ->mapWithKeys(fn (RegraAcesso $regra): array => [$regra->empresa_id.'|'.$regra->perfil_id => (int) $regra->total]);

        $pares = [];

        foreach ($empresas as $empresa) {
            foreach ($empresa->perfis as $perfil) {
                $pares[] = [
                    'empresa' => $empresa,
                    'perfil' => $perfil,
                    'total' => $totais[$empresa->id.'|'.$perfil->id] ?? 0,
                ];
            }
        }

        return view('cadastro.regras.index', ['pares' => $pares]);
    }

    public function editar(Empresa $empresa, PerfilVinculo $perfil): View
    {
        abort_unless($empresa->perfis()->whereKey($perfil->id)->exists(), 404);

        $linhas = RegraAcesso::query()
            ->where('empresa_id', $empresa->id)
            ->where('perfil_id', $perfil->id)
            ->orderBy('dia_semana')
            ->orderBy('hora_inicio')
            ->get()
            ->map(fn (RegraAcesso $regra): array => [
                'dia' => (string) $regra->dia_semana,
                'inicio' => IntervaloHorario::paraFormulario($regra->hora_inicio),
                'fim' => IntervaloHorario::paraFormulario($regra->hora_fim),
                'catraca' => (string) $regra->catraca_id,
            ])
            ->all();

        $vazia = ['dia' => '', 'inicio' => '', 'fim' => '', 'catraca' => ''];

        // Catraca desativada continua na lista: a regra existente não some sem aviso.
        $catracas = $empresa->catracas()->get();

        return view('cadastro.regras.editar', [
            'empresa' => $empresa,
            'perfil' => $perfil,
            'linhas' => [...$linhas, ...array_fill(0, self::LINHAS_EXTRAS, $vazia)],
            'opcoesCatracas' => $catracas->mapWithKeys(fn ($catraca): array => [
                $catraca->id => $catraca->nome.($catraca->ativo ? '' : ' (desativada)'),
            ])->all(),
        ]);
    }

    public function salvar(Request $request, Empresa $empresa, PerfilVinculo $perfil, RegraAcessoServico $servico): RedirectResponse
    {
        $servico->salvar($empresa, $perfil, $this->linhas($request, 'linhas', ['dia', 'inicio', 'fim', 'catraca']));

        return redirect()->route('regras.editar', [$empresa, $perfil])->with('sucesso', 'Regras salvas.');
    }
}
