<?php

namespace App\Http\Controllers\Lgpd;

use App\Http\Controllers\Controller;
use App\Models\Biometria;
use App\Models\Consentimento;
use App\Models\Pessoa;
use App\Services\Lgpd\TermoServico;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Painel LGPD do cliente (só administrador): termo vigente, publicação de nova
 * versão e os números de consentimento e biometria.
 */
class LgpdController extends Controller
{
    public function index(TermoServico $termos): View
    {
        $pessoasAtivas = Pessoa::query()->where('ativo', true)->whereNull('anonimizada_em')->count();
        $comConsentimento = Consentimento::query()
            ->whereNull('revogado_em')
            ->whereHas('pessoa', fn ($pessoa) => $pessoa->where('ativo', true)->whereNull('anonimizada_em'))
            ->count();

        return view('lgpd.index', [
            'termo' => $termos->vigente(),
            'indicadores' => [
                'pessoas_ativas' => $pessoasAtivas,
                'com_consentimento' => $comConsentimento,
                'sem_consentimento' => max(0, $pessoasAtivas - $comConsentimento),
                'biometrias_ativas' => Biometria::query()->where('ativo', true)->count(),
                'revogados' => Consentimento::query()->whereNotNull('revogado_em')->count(),
            ],
        ]);
    }

    public function publicarTermo(Request $request, TermoServico $termos): RedirectResponse
    {
        $termo = $termos->publicar((string) $request->input('versao', ''), (string) $request->input('texto', ''));

        return redirect()->route('lgpd.index')->with('sucesso', "Termo versão {$termo->versao} publicado. "
            .'Quem já aceitou a versão anterior continua com ela; os novos aceites usam esta.');
    }
}
