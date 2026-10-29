<?php

namespace App\Http\Controllers\Cadastro;

use App\Http\Controllers\Controller;
use App\Models\Catraca;
use App\Models\Empresa;
use App\Services\Cadastro\CatracaServico;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catracas do cliente. O token de conexão aparece UMA vez, logo após criar ou
 * renovar: vai na sessão (flash), nunca no banco, na URL ou na auditoria.
 */
class CatracaController extends Controller
{
    public function index(): View
    {
        return view('cadastro.catracas.index', [
            'catracas' => Catraca::query()->with('empresa')->orderBy('nome')->get(),
        ]);
    }

    public function criar(): View
    {
        return view('cadastro.catracas.formulario', [
            'catraca' => new Catraca(['tipo_identificacao' => 'digital_facial', 'modo_falha' => 'bloquear']),
            'empresas' => $this->empresasAtivas(),
        ]);
    }

    public function salvar(Request $request, CatracaServico $servico): RedirectResponse
    {
        ['catraca' => $catraca, 'token' => $token] = $servico->criar($request->all());

        return redirect()->route('catracas.show', $catraca)
            ->with('sucesso', 'Catraca cadastrada. Conecte o equipamento com o token abaixo.')
            ->with('token_catraca', $token);
    }

    public function show(Catraca $catraca): View
    {
        return view('cadastro.catracas.show', ['catraca' => $catraca->load('empresa')]);
    }

    public function editar(Catraca $catraca): View
    {
        return view('cadastro.catracas.formulario', [
            'catraca' => $catraca->load('empresa'),
            'empresas' => $this->empresasAtivas(),
        ]);
    }

    public function atualizar(Request $request, Catraca $catraca, CatracaServico $servico): RedirectResponse
    {
        $servico->atualizar($catraca, $request->all());

        return redirect()->route('catracas.show', $catraca)->with('sucesso', 'Dados da catraca atualizados.');
    }

    public function situacao(Request $request, Catraca $catraca, CatracaServico $servico): RedirectResponse
    {
        $ativo = $request->boolean('ativo');
        $servico->alterarSituacao($catraca, $ativo);

        return redirect()->route('catracas.show', $catraca)->with('sucesso', $ativo
            ? 'Catraca reativada.'
            : 'Catraca desativada. Ela sai da contagem do plano.');
    }

    public function renovarToken(Catraca $catraca, CatracaServico $servico): RedirectResponse
    {
        $token = $servico->renovarToken($catraca);

        return redirect()->route('catracas.show', $catraca)
            ->with('sucesso', 'Novo token gerado. O anterior deixou de funcionar.')
            ->with('token_catraca', $token);
    }

    /** @return Collection<int, Empresa> */
    private function empresasAtivas(): Collection
    {
        return Empresa::query()->where('ativo', true)->orderBy('razao_social')->get();
    }
}
