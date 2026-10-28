<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\RecuperacaoConta;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Link de confirmação de e-mail (uso único, chega sem sessão). */
class ConfirmacaoEmailController extends Controller
{
    public function confirmar(Request $request, RecuperacaoConta $recuperacao): View
    {
        $confirmado = $recuperacao->confirmarEmail((string) $request->query('token', ''));

        return view('auth.confirmacao', ['confirmado' => $confirmado]);
    }
}
