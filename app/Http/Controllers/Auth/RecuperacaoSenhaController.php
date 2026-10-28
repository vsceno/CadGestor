<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Autenticacao;
use App\Services\RecuperacaoConta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Esqueci minha senha" e criação de senha pelo link do e-mail. As respostas
 * nunca revelam se um e-mail tem conta.
 */
class RecuperacaoSenhaController extends Controller
{
    public function __construct(private readonly RecuperacaoConta $recuperacao) {}

    public function formulario(): View
    {
        return view('auth.esqueci');
    }

    public function enviar(Request $request): View
    {
        $email = (string) $request->validate(['email' => ['required', 'string', 'max:254']])['email'];

        if (! $this->recuperacao->solicitarRedefinicao($email, (string) $request->ip())) {
            return view('auth.esqueci', ['limite' => true]);
        }

        return view('auth.esqueci', ['enviado' => true]);
    }

    /**
     * Abre o formulário a partir do link. O token sai da URL e segue num campo
     * oculto (não fica no histórico das páginas seguintes).
     */
    public function formularioRedefinir(Request $request): View
    {
        $token = (string) $request->query('token', '');

        return view('auth.redefinir', ['token' => $this->recuperacao->linkValido($token) ? $token : null]);
    }

    public function redefinir(Request $request, Autenticacao $autenticacao): View|RedirectResponse
    {
        $token = (string) $request->input('token', '');

        if (! $this->recuperacao->linkValido($token)) {
            return view('auth.redefinir', ['token' => null]);
        }

        $this->recuperacao->redefinir(
            $token,
            (string) $request->input('senha', ''),
            (string) $request->input('senha_confirmation', ''),
            (string) $request->ip(),
        );

        // Quem estava logado neste navegador sai: a sessão antiga não vale mais.
        $autenticacao->sair();

        return redirect()->route('entrar')->with('sucesso', 'Senha criada. Entre com o seu e-mail e a nova senha.');
    }
}
