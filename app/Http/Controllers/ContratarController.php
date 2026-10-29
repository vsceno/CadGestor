<?php

namespace App\Http\Controllers;

use App\Models\Plano;
use App\Services\CadastroCliente;
use App\Services\LimitadorTentativas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Contratação pelo site: planos, cadastro com teste grátis e documentos
 * jurídicos (MODELOS, a revisar pelo advogado).
 */
class ContratarController extends Controller
{
    public function formulario(Request $request): View
    {
        $planos = Plano::query()->where('disponivel_venda', true)->orderBy('ordem')->get();

        return view('contratar.formulario', [
            'planos' => $planos,
            'planoEscolhido' => (string) $request->query('plano', $planos->firstWhere('codigo', 'profissional')?->codigo ?? $planos->first()?->codigo),
            'diasTeste' => CadastroCliente::DIAS_TESTE,
        ]);
    }

    public function cadastrar(Request $request, CadastroCliente $cadastro, LimitadorTentativas $limitador): RedirectResponse
    {
        // Contra abuso do teste grátis: no máximo 5 cadastros por rede por hora.
        if ($limitador->excedeu('cadastro_cliente', (string) $request->ip(), (string) $request->ip(), 5, 5)) {
            return back()->withInput($request->except('senha', 'senha_confirmation'))
                ->with('erro', 'Muitos cadastros a partir desta rede. Tente de novo mais tarde.');
        }

        $limitador->registrarUso('cadastro_cliente', (string) $request->ip(), (string) $request->ip());
        $cadastro->cadastrar($request->all(), (string) $request->ip());

        return redirect()->route('contratar.enviado');
    }

    public function enviado(): View
    {
        return view('contratar.enviado', ['diasTeste' => CadastroCliente::DIAS_TESTE]);
    }

    public function documento(Request $request): View
    {
        $documentos = [
            'documentos.termos' => ['juridico.termos-de-uso', 'Termos de Uso'],
            'documentos.privacidade' => ['juridico.privacidade', 'Política de Privacidade'],
            'documentos.contrato-dados' => ['juridico.contrato-dados', 'Contrato de Tratamento de Dados'],
        ];
        [$view, $titulo] = $documentos[(string) $request->route()?->getName()];

        return view($view, ['titulo' => $titulo, 'versao' => CadastroCliente::VERSAO_TERMOS]);
    }
}
