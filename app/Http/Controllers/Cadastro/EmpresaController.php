<?php

namespace App\Http\Controllers\Cadastro;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EmpresaHorario;
use App\Models\PerfilVinculo;
use App\Services\Cadastro\EmpresaServico;
use App\Support\IntervaloHorario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Empresas atendidas pelo cliente. Nada é excluído: a empresa é desativada e o
 * histórico das pessoas e das passagens continua.
 */
class EmpresaController extends Controller
{
    use LeEntradas;

    /** Linhas em branco oferecidas no horário padrão. */
    private const LINHAS_EXTRAS = 3;

    public function index(): View
    {
        return view('cadastro.empresas.index', [
            'empresas' => Empresa::query()->withCount('catracas')->orderBy('razao_social')->get(),
        ]);
    }

    public function criar(): View
    {
        return view('cadastro.empresas.formulario', ['empresa' => new Empresa]);
    }

    public function salvar(Request $request, EmpresaServico $servico): RedirectResponse
    {
        $empresa = $servico->criar($request->all());

        return redirect()->route('empresas.configurar', $empresa)
            ->with('sucesso', 'Empresa cadastrada. Escolha os perfis aceitos e o horário padrão.');
    }

    public function editar(Empresa $empresa): View
    {
        return view('cadastro.empresas.formulario', ['empresa' => $empresa]);
    }

    public function atualizar(Request $request, Empresa $empresa, EmpresaServico $servico): RedirectResponse
    {
        $servico->atualizar($empresa, $request->all());

        return redirect()->route('empresas.configurar', $empresa)->with('sucesso', 'Dados da empresa atualizados.');
    }

    public function configurar(Empresa $empresa): View
    {
        $horarios = $empresa->horarios()->get()->map(fn (EmpresaHorario $horario): array => [
            'dia' => (string) $horario->dia_semana,
            'inicio' => IntervaloHorario::paraFormulario($horario->hora_inicio),
            'fim' => IntervaloHorario::paraFormulario($horario->hora_fim),
        ])->all();

        return view('cadastro.empresas.configurar', [
            'empresa' => $empresa,
            'perfis' => PerfilVinculo::query()->where('ativo', true)->orderBy('nome')->get(),
            'aceitos' => $empresa->perfis()->pluck('perfil_vinculo.id')->all(),
            'linhas' => [...$horarios, ...array_fill(0, self::LINHAS_EXTRAS, ['dia' => '', 'inicio' => '', 'fim' => ''])],
        ]);
    }

    public function situacao(Request $request, Empresa $empresa, EmpresaServico $servico): RedirectResponse
    {
        $ativo = $request->boolean('ativo');
        $servico->alterarSituacao($empresa, $ativo);

        return redirect()->route('empresas.index')->with('sucesso', $ativo
            ? 'Empresa reativada.'
            : 'Empresa desativada. O histórico foi mantido.');
    }

    public function salvarPerfis(Request $request, Empresa $empresa, EmpresaServico $servico): RedirectResponse
    {
        $servico->definirPerfis($empresa, $this->lista($request, 'perfis'));

        return redirect()->route('empresas.configurar', $empresa)->with('sucesso', 'Perfis aceitos salvos.');
    }

    public function salvarHorarios(Request $request, Empresa $empresa, EmpresaServico $servico): RedirectResponse
    {
        $servico->definirHorarios(
            $empresa,
            $this->lista($request, 'dias'),
            $this->lista($request, 'inicios'),
            $this->lista($request, 'fins'),
        );

        return redirect()->route('empresas.configurar', $empresa)->with('sucesso', 'Horário padrão salvo.');
    }
}
