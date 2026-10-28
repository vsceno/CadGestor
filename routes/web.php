<?php

use App\Http\Controllers\Auth\ConfirmacaoEmailController;
use App\Http\Controllers\Auth\RecuperacaoSenhaController;
use App\Http\Controllers\Auth\SenhaController;
use App\Http\Controllers\Auth\SessaoController;
use App\Http\Controllers\DesignSystemController;
use App\Http\Controllers\InicioController;
use Illuminate\Support\Facades\Route;

/*
 * Rotas web. Toda rota logada passa por: sessão -> CSRF -> login -> sessão
 * válida (senha não mudou) -> troca de senha obrigatória -> papel. O
 * isolamento entre clientes não depende das rotas: o banco (RLS) só mostra
 * linhas do cliente de quem está logado.
 */

// --- Sem login -----------------------------------------------------------
Route::middleware('guest')->group(function (): void {
    Route::get('/entrar', [SessaoController::class, 'formulario'])->name('entrar');
    Route::post('/entrar', [SessaoController::class, 'entrar']);
    Route::post('/entrar/reenviar-confirmacao', [SessaoController::class, 'reenviarConfirmacao'])->name('email.reenviar');
    Route::get('/esqueci-senha', [RecuperacaoSenhaController::class, 'formulario'])->name('senha.esqueci');
    Route::post('/esqueci-senha', [RecuperacaoSenhaController::class, 'enviar']);
});

// Links dos e-mails: funcionam logado ou não.
Route::get('/redefinir-senha', [RecuperacaoSenhaController::class, 'formularioRedefinir'])->name('senha.redefinir');
Route::post('/redefinir-senha', [RecuperacaoSenhaController::class, 'redefinir']);
Route::get('/confirmar-email', [ConfirmacaoEmailController::class, 'confirmar'])->name('email.confirmar');

// --- Logado --------------------------------------------------------------
Route::middleware(['auth', 'auth.session', 'troca.senha'])->group(function (): void {
    Route::get('/', InicioController::class)->name('inicio');
    Route::post('/sair', [SessaoController::class, 'sair'])->name('sair');
    Route::get('/senha', [SenhaController::class, 'formulario'])->name('senha.trocar');
    Route::post('/senha', [SenhaController::class, 'trocar']);
});

// --- Vitrine do design system: nunca existe em produção -----------------
if (! app()->isProduction()) {
    Route::get('/design-system', [DesignSystemController::class, 'index'])->name('design-system.index');
    Route::post('/design-system/validar', [DesignSystemController::class, 'validar'])->name('design-system.validar');
}
