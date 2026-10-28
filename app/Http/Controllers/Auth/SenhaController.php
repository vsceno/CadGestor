<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Rules\SenhaForte;
use App\Services\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Troca da própria senha. As sessões abertas em outros aparelhos caem (o
 * hash muda e o middleware auth.session as encerra); esta continua.
 */
class SenhaController extends Controller
{
    public function formulario(): View
    {
        return view('auth.senha');
    }

    public function trocar(Request $request, Auditoria $auditoria): RedirectResponse
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $dados = $request->validate([
            'senha_atual' => ['required', 'string'],
            'senha' => ['required', 'string', 'confirmed', new SenhaForte($usuario->email)],
        ], ['senha.confirmed' => 'A confirmação não confere com a nova senha.']);

        if (! Hash::check($dados['senha_atual'], $usuario->getAuthPassword())) {
            throw ValidationException::withMessages(['senha_atual' => 'Senha atual incorreta.']);
        }

        if (Hash::check($dados['senha'], $usuario->getAuthPassword())) {
            throw ValidationException::withMessages(['senha' => 'A nova senha deve ser diferente da atual.']);
        }

        DB::connection('pgsql')->transaction(function () use ($usuario, $dados, $auditoria): void {
            DB::connection('pgsql')->update(<<<'SQL'
                UPDATE usuario_sistema
                   SET senha_hash = ?, deve_trocar_senha = false, credenciais_versao = credenciais_versao + 1,
                       redefinicao_token_hash = NULL, redefinicao_expira_em = NULL
                 WHERE id = ?
                SQL, [Hash::make($dados['senha']), $usuario->id]);
            $auditoria->registrar('senha_alterada', 'usuario_sistema', $usuario->id, null, ['via' => 'propria_tela']);
        });

        $usuario->refresh();
        $request->session()->regenerate();
        // Esta sessão passa a carregar o hash novo; as outras deixam de valer.
        $request->session()->put('password_hash_web', $usuario->getAuthPassword());

        return redirect()->route('inicio')->with('sucesso', 'Senha alterada. Sessões abertas em outros aparelhos foram encerradas.');
    }
}
