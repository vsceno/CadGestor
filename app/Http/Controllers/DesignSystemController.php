<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vitrine do design system (só fora de produção): todos os componentes e
 * estados (normal, vazio, carregando, erro, sucesso) para revisão visual.
 * Os dados mostrados são fictícios.
 */
class DesignSystemController extends Controller
{
    public function index(Request $request): View
    {
        return view('design-system.index', [
            'estado' => in_array($request->query('estado'), ['vazio', 'carregando'], true)
                ? $request->query('estado')
                : 'normal',
            'pessoas' => [
                ['id' => 1, 'nome' => 'Ana Ferreira (fictícia)', 'cpf' => '52998224725', 'perfil' => 'Funcionário', 'empresa' => 'Construtora Exemplo', 'situacao' => 'ativo', 'validade' => '2027-03-31'],
                ['id' => 2, 'nome' => 'Bruno Lima (fictício)', 'cpf' => '11144477735', 'perfil' => 'Prestador', 'empresa' => 'Elétrica Exemplo', 'situacao' => 'atencao', 'validade' => '2026-11-05'],
                ['id' => 3, 'nome' => 'Carla Souza (fictícia)', 'cpf' => '39053344705', 'perfil' => 'Visitante', 'empresa' => '—', 'situacao' => 'negado', 'validade' => '2026-10-20'],
                ['id' => 4, 'nome' => 'Daniel Rocha (fictício)', 'cpf' => '86288366757', 'perfil' => 'Funcionário', 'empresa' => 'Construtora Exemplo', 'situacao' => 'inativo', 'validade' => null],
            ],
        ]);
    }

    /** Demonstra a validação do servidor com as mensagens em português. */
    public function validar(Request $request): RedirectResponse
    {
        $request->validate([
            'nome' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'email'],
            'cnpj' => ['required', 'size:18'],
            'uf' => ['required'],
        ]);

        return redirect()->route('design-system.index')->with('sucesso', 'Formulário válido. Nada foi gravado (é só uma demonstração).');
    }
}
