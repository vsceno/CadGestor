<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\Autenticacao;
use App\Services\LimitadorTentativas;
use App\Services\RecuperacaoConta;
use App\Support\ContextoBanco;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Entrar e sair do sistema, e reenvio da confirmação de e-mail na tela de login.
 */
class SessaoController extends Controller
{
    public function __construct(private readonly Autenticacao $autenticacao) {}

    public function formulario(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('inicio') : view('auth.entrar');
    }

    public function entrar(Request $request): View|RedirectResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'senha' => ['required', 'string', 'max:128'],
        ]);

        $resultado = $this->autenticacao->entrar($dados['email'], $dados['senha'], (string) $request->ip());

        if ($resultado === Autenticacao::OK) {
            return redirect()->intended(route('inicio'));
        }

        return back()->withInput($request->only('email'))->with([
            'erro_login' => match ($resultado) {
                Autenticacao::BLOQUEADO => 'Muitas tentativas sem sucesso. Aguarde '.LimitadorTentativas::JANELA_MINUTOS.' minutos e tente de novo, ou use "Esqueci minha senha".',
                Autenticacao::EMAIL_NAO_CONFIRMADO => 'Confirme seu e-mail pelo link que enviamos antes de entrar.',
                default => 'E-mail ou senha incorretos.',
            },
            'pode_reenviar' => $resultado === Autenticacao::EMAIL_NAO_CONFIRMADO,
        ]);
    }

    public function sair(): RedirectResponse
    {
        $this->autenticacao->sair();

        return redirect()->route('entrar')->with('sucesso', 'Você saiu do sistema.');
    }

    /** Só para quem acabou de acertar a senha de uma conta ainda não confirmada. */
    public function reenviarConfirmacao(Request $request, RecuperacaoConta $recuperacao, LimitadorTentativas $limitador, ContextoBanco $contexto): RedirectResponse
    {
        $usuarioId = $this->autenticacao->contaAguardandoConfirmacao();

        if ($usuarioId === null) {
            return redirect()->route('entrar')->with('atencao', 'Entre com e-mail e senha para pedir um novo link.');
        }

        if ($limitador->excedeu('reenvio_confirmacao', (string) $request->ip(), $usuarioId, 10, 3)) {
            return redirect()->route('entrar')->with('erro_login', 'Limite de reenvios atingido. Tente de novo em uma hora.');
        }

        $limitador->registrarUso('reenvio_confirmacao', (string) $request->ip(), $usuarioId);
        $contexto->definir(null, $usuarioId);

        try {
            $usuario = Usuario::query()->find($usuarioId);

            if ($usuario instanceof Usuario && $usuario->ativo) {
                $contexto->definir($usuario->organizacao_id, $usuario->id);
                $recuperacao->reenviarConfirmacao($usuario, 'login');
            }
        } finally {
            $contexto->limpar();
        }

        $this->autenticacao->esquecerContaAguardandoConfirmacao();

        return redirect()->route('entrar')->with('sucesso', 'Enviamos um novo link de confirmação para o seu e-mail.');
    }
}
