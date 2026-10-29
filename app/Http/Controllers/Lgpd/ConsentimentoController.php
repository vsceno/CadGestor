<?php

namespace App\Http\Controllers\Lgpd;

use App\Http\Controllers\Controller;
use App\Models\Pessoa;
use App\Services\Lgpd\ConsentimentoServico;
use App\Services\Lgpd\ExclusaoBiometria;
use App\Services\Lgpd\TermoServico;
use App\Support\Idade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Consentimento e biometria de uma pessoa: registrar o aceite, revogar e apagar
 * as biometrias a pedido do titular.
 */
class ConsentimentoController extends Controller
{
    public function formulario(Pessoa $pessoa, TermoServico $termos): View
    {
        return view('lgpd.consentimento', [
            'pessoa' => $pessoa,
            'termo' => $termos->vigente(),
            'faixa' => Idade::faixa($pessoa->data_nascimento, now()),
            'parentescos' => ConsentimentoServico::PARENTESCOS,
        ]);
    }

    public function registrar(Request $request, Pessoa $pessoa, ConsentimentoServico $servico): RedirectResponse
    {
        $servico->registrar($pessoa, $request->all(), (string) $request->ip());

        return redirect()->route('pessoas.show', $pessoa)->with('sucesso', 'Consentimento registrado.');
    }

    public function revogar(Request $request, Pessoa $pessoa, ConsentimentoServico $servico): RedirectResponse
    {
        $apagadas = $servico->revogar($pessoa, (string) $request->ip());

        return redirect()->route('pessoas.show', $pessoa)
            ->with('sucesso', 'Consentimento revogado. '.$this->contagem($apagadas, 'apagada', 'apagadas').'.');
    }

    public function excluirBiometrias(Pessoa $pessoa, ExclusaoBiometria $exclusao): RedirectResponse
    {
        $apagadas = count($exclusao->excluir($pessoa, 'solicitacao_titular'));

        return redirect()->route('pessoas.show', $pessoa)
            ->with('sucesso', 'Biometria apagada a pedido do titular: '.$this->contagem($apagadas, 'apagada', 'apagadas').'.');
    }

    private function contagem(int $quantidade, string $singular, string $plural): string
    {
        return $quantidade === 1 ? "1 biometria {$singular}" : "{$quantidade} biometrias {$plural}";
    }
}
