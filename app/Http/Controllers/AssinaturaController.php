<?php

namespace App\Http\Controllers;

use App\Http\Requests\DadosClienteRequest;
use App\Models\Fatura;
use App\Models\Organizacao;
use App\Models\Plano;
use App\Services\Auditoria;
use App\Services\LimitesPlano;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Minha assinatura" do cliente: plano, uso frente aos limites, faturas,
 * troca de plano e dados da empresa (usados na cobrança e na nota fiscal).
 * Abre mesmo com o sistema suspenso, para o cliente conseguir regularizar.
 */
class AssinaturaController extends Controller
{
    public function index(LimitesPlano $limites): View
    {
        $assinatura = $limites->assinatura();

        return view('assinatura.index', [
            'assinatura' => $assinatura,
            'situacao' => $assinatura?->situacao(),
            'uso' => $limites->uso(),
            'faturas' => Fatura::query()->whereRaw('organizacao_id = cg_org_atual()')->orderByDesc('vencimento')->limit(24)->get(),
            'planos' => Plano::query()->where('disponivel_venda', true)->orderBy('ordem')->get(),
            'cliente' => $this->cliente(),
        ]);
    }

    /** Upgrade ou downgrade. O downgrade só passa se o uso atual couber no plano novo. */
    public function trocarPlano(Request $request, LimitesPlano $limites, Auditoria $auditoria): RedirectResponse
    {
        $assinatura = $limites->assinatura();
        $plano = Plano::query()->where('disponivel_venda', true)->find((string) $request->input('plano_id'));

        if ($assinatura === null || $assinatura->status === 'cancelada') {
            throw ValidationException::withMessages(['plano_id' => 'Assinatura cancelada. Fale com o comercial para contratar de novo.']);
        }

        if ($plano === null) {
            throw ValidationException::withMessages(['plano_id' => 'Escolha um plano disponível.']);
        }

        if ($plano->id === $assinatura->plano_id) {
            throw ValidationException::withMessages(['plano_id' => 'Este já é o seu plano.']);
        }

        $uso = $limites->uso();

        if ($uso['pessoas'] > $plano->limite_pessoas || $uso['catracas'] > $plano->limite_catracas) {
            throw ValidationException::withMessages(['plano_id' => sprintf(
                'Seu uso atual (%d pessoas e %d catracas ativas) não cabe no plano %s. Inative o excedente antes.',
                $uso['pessoas'], $uso['catracas'], $plano->nome,
            )]);
        }

        DB::connection('pgsql')->transaction(function () use ($assinatura, $plano, $auditoria): void {
            $anterior = $assinatura->plano->codigo;
            $assinatura->update(['plano_id' => $plano->id]);
            $auditoria->registrar('assinatura_alterada', 'assinatura', $assinatura->id, ['plano' => $anterior], ['plano' => $plano->codigo]);
        });

        return redirect()->route('assinatura.index')->with('sucesso', "Plano alterado para {$plano->nome}. O novo valor vale a partir da próxima cobrança.");
    }

    public function empresa(): View
    {
        return view('assinatura.empresa', ['cliente' => $this->cliente(), 'ufs' => DadosClienteRequest::UFS]);
    }

    /**
     * O administrador atualiza endereço e contatos pela função do banco que só
     * mexe nesses campos e confere o papel. Razão social e CNPJ: só com a plataforma.
     */
    public function salvarEmpresa(Request $request, Auditoria $auditoria): RedirectResponse
    {
        // Só os campos de contato: razão social e CNPJ enviados aqui são ignorados.
        $entrada = array_intersect_key(DadosClienteRequest::normalizar($request->all()), array_flip(Organizacao::CAMPOS_CONTATO));
        $dados = validator($entrada, DadosClienteRequest::regrasContato(), DadosClienteRequest::mensagens(), [
            'email_financeiro' => 'e-mail financeiro', 'contato_nome' => 'responsável comercial',
        ])->validate();
        $dados = array_replace(array_fill_keys(Organizacao::CAMPOS_CONTATO, null), $dados);
        $cliente = $this->cliente();

        DB::connection('pgsql')->transaction(function () use ($dados, $cliente, $auditoria): void {
            $antes = $cliente->only(Organizacao::CAMPOS_CONTATO);
            DB::connection('pgsql')->select(
                'SELECT cg_atualizar_contato_organizacao('.implode(', ', array_fill(0, count(Organizacao::CAMPOS_CONTATO), '?')).')',
                array_map(fn (string $campo): mixed => $dados[$campo], Organizacao::CAMPOS_CONTATO),
            );
            [$de, $para] = Auditoria::diferenca($antes, $dados);

            if ($para) {
                $auditoria->registrar('alterar', 'organizacao', $cliente->id, $de, $para + ['campos' => array_keys($para)]);
            }
        });

        return redirect()->route('assinatura.index')->with('sucesso', 'Dados da empresa atualizados.');
    }

    private function cliente(): Organizacao
    {
        return Organizacao::query()->whereRaw('id = cg_org_atual()')->firstOrFail();
    }
}
