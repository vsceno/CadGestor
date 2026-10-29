<?php

namespace App\Http\Controllers\Cadastro;

use App\Http\Controllers\Controller;
use App\Models\Biometria;
use App\Models\Pessoa;
use App\Services\Cadastro\PessoaServico;
use App\Services\Cadastro\VinculoServico;
use App\Services\Lgpd\ConsentimentoServico;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pessoas do cliente. A busca é por POST: o nome ou o CPF pesquisado não vai
 * para a URL nem para o histórico do navegador.
 */
class PessoaController extends Controller
{
    /** Máximo de linhas na listagem: a busca refina o resultado. */
    public const LIMITE_LISTA = 200;

    public function index(Request $request): View
    {
        $termo = $request->isMethod('post') ? trim((string) $request->input('termo', '')) : '';
        $consulta = Pessoa::query()
            ->withCount(['vinculos as vinculos_ativos' => fn ($vinculo) => $vinculo->where('status', 'ativo')])
            ->orderBy('nome');

        if ($termo !== '') {
            $digitos = preg_replace('/\D/', '', $termo) ?? '';

            $consulta->where(function ($busca) use ($termo, $digitos): void {
                $busca->where('nome', 'ilike', '%'.$termo.'%');

                if ($digitos !== '') {
                    $busca->orWhere('cpf', 'like', $digitos.'%');
                }
            });
        }

        return view('cadastro.pessoas.index', [
            'pessoas' => $consulta->limit(self::LIMITE_LISTA)->get(),
            'termo' => $termo,
        ]);
    }

    public function criar(): View
    {
        return view('cadastro.pessoas.formulario', ['pessoa' => new Pessoa(['ativo' => true])]);
    }

    public function salvar(Request $request, PessoaServico $servico): RedirectResponse
    {
        $pessoa = $servico->criar($request->all());

        return redirect()->route('pessoas.show', $pessoa)
            ->with('sucesso', 'Pessoa cadastrada. Agora vincule a empresa e o perfil.');
    }

    public function show(Pessoa $pessoa, VinculoServico $vinculos, ConsentimentoServico $consentimentos): View
    {
        return view('cadastro.pessoas.show', [
            'pessoa' => $pessoa->load(['vinculos.empresa', 'vinculos.perfil']),
            'opcoes' => $vinculos->opcoes(),
            'consentimento' => $consentimentos->ativo($pessoa),
            'biometrias' => Biometria::query()
                ->where('pessoa_id', $pessoa->id)
                ->where('ativo', true)
                ->orderBy('tipo')
                ->get(['tipo', 'dedo', 'created_at']),
        ]);
    }

    public function editar(Pessoa $pessoa): View
    {
        return view('cadastro.pessoas.formulario', ['pessoa' => $pessoa]);
    }

    public function atualizar(Request $request, Pessoa $pessoa, PessoaServico $servico): RedirectResponse
    {
        $servico->atualizar($pessoa, $request->all());

        return redirect()->route('pessoas.show', $pessoa)->with('sucesso', 'Dados da pessoa atualizados.');
    }

    public function situacao(Request $request, Pessoa $pessoa, PessoaServico $servico): RedirectResponse
    {
        $ativo = $request->boolean('ativo');
        $servico->definirAtivo($pessoa, $ativo);

        return redirect()->route('pessoas.show', $pessoa)->with('sucesso', $ativo
            ? 'Pessoa reativada.'
            : 'Pessoa desativada. O histórico foi mantido.');
    }
}
