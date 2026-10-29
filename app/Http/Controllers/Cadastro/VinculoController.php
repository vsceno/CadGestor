<?php

namespace App\Http\Controllers\Cadastro;

use App\Http\Controllers\Controller;
use App\Models\Pessoa;
use App\Models\Vinculo;
use App\Services\Cadastro\VinculoServico;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vínculos de uma pessoa. Nenhum vínculo é apagado: encerrar fica no histórico.
 */
class VinculoController extends Controller
{
    public function salvar(Request $request, Pessoa $pessoa, VinculoServico $servico): RedirectResponse
    {
        $servico->criar($pessoa, $request->all());

        return redirect()->route('pessoas.show', $pessoa)->with('sucesso', 'Vínculo criado.');
    }

    public function editar(Pessoa $pessoa, Vinculo $vinculo): View
    {
        $this->garantirDaPessoa($pessoa, $vinculo);

        return view('cadastro.pessoas.vinculo-editar', [
            'pessoa' => $pessoa,
            'vinculo' => $vinculo->load(['empresa', 'perfil']),
        ]);
    }

    public function atualizar(Request $request, Pessoa $pessoa, Vinculo $vinculo, VinculoServico $servico): RedirectResponse
    {
        $this->garantirDaPessoa($pessoa, $vinculo);
        $servico->alterarPeriodo($vinculo, $request->all());

        return redirect()->route('pessoas.show', $pessoa)->with('sucesso', 'Validade do vínculo atualizada.');
    }

    public function suspender(Pessoa $pessoa, Vinculo $vinculo, VinculoServico $servico): RedirectResponse
    {
        $this->garantirDaPessoa($pessoa, $vinculo);
        $servico->suspender($vinculo);

        return redirect()->route('pessoas.show', $pessoa)->with('sucesso', 'Vínculo suspenso. A pessoa não passa por esta empresa neste perfil.');
    }

    public function reativar(Pessoa $pessoa, Vinculo $vinculo, VinculoServico $servico): RedirectResponse
    {
        $this->garantirDaPessoa($pessoa, $vinculo);
        $servico->reativar($vinculo);

        return redirect()->route('pessoas.show', $pessoa)->with('sucesso', 'Vínculo reativado.');
    }

    public function encerrar(Request $request, Pessoa $pessoa, Vinculo $vinculo, VinculoServico $servico): RedirectResponse
    {
        $this->garantirDaPessoa($pessoa, $vinculo);
        $servico->encerrar($vinculo, $request->all());

        return redirect()->route('pessoas.show', $pessoa)->with('sucesso', 'Vínculo encerrado. O registro fica no histórico.');
    }

    /** O vínculo precisa ser desta pessoa: a URL não pode misturar cadastros. */
    private function garantirDaPessoa(Pessoa $pessoa, Vinculo $vinculo): void
    {
        abort_unless($vinculo->pessoa_id === $pessoa->id, 404);
    }
}
