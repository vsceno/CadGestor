<?php

namespace App\Http\Controllers\Cadastro;

use App\Http\Controllers\Controller;
use App\Models\PerfilVinculo;
use App\Services\Cadastro\PerfilServico;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Perfis de vínculo (Funcionário, Aluno...). Quem vê a lista é quem cadastra
 * empresas; alterar a base legal é só do administrador (rotas separadas).
 */
class PerfilController extends Controller
{
    public function index(): View
    {
        return view('cadastro.perfis.index', [
            'perfis' => PerfilVinculo::query()->orderByDesc('ativo')->orderBy('nome')->get(),
        ]);
    }

    public function criar(): View
    {
        return view('cadastro.perfis.formulario', [
            'perfil' => new PerfilVinculo(['base_legal' => 'consentimento', 'ativo' => true]),
        ]);
    }

    public function salvar(Request $request, PerfilServico $servico): RedirectResponse
    {
        $servico->criar($request->all());

        return redirect()->route('perfis.index')->with('sucesso', 'Perfil criado.');
    }

    public function editar(PerfilVinculo $perfil): View
    {
        return view('cadastro.perfis.formulario', ['perfil' => $perfil]);
    }

    public function atualizar(Request $request, PerfilVinculo $perfil, PerfilServico $servico): RedirectResponse
    {
        $servico->atualizar($perfil, $request->all());

        return redirect()->route('perfis.index')->with('sucesso', 'Perfil atualizado.');
    }
}
