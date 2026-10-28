<?php

use App\Http\Controllers\AssinaturaController;
use App\Http\Controllers\Auth\ConfirmacaoEmailController;
use App\Http\Controllers\Auth\RecuperacaoSenhaController;
use App\Http\Controllers\Auth\SenhaController;
use App\Http\Controllers\Auth\SessaoController;
use App\Http\Controllers\DesignSystemController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\Plataforma\ClienteController;
use App\Http\Controllers\Plataforma\PlanoController;
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
Route::middleware(['auth', 'auth.session', 'troca.senha', 'assinatura'])->group(function (): void {
    Route::get('/', InicioController::class)->name('inicio');
    Route::post('/sair', [SessaoController::class, 'sair'])->name('sair');
    Route::get('/senha', [SenhaController::class, 'formulario'])->name('senha.trocar');
    Route::post('/senha', [SenhaController::class, 'trocar'])->name('senha.salvar');

    // --- Plataforma: só controla os clientes (cadastro, plano, cobrança) ---
    Route::middleware('papel:plataforma')->prefix('plataforma')->name('plataforma.')->group(function (): void {
        Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::post('/clientes/busca', [ClienteController::class, 'index'])->name('clientes.busca');
        Route::get('/clientes/novo/{etapa?}', [ClienteController::class, 'assistente'])->whereNumber('etapa')->name('clientes.novo');
        Route::post('/clientes/novo/{etapa}', [ClienteController::class, 'salvarEtapa'])->whereIn('etapa', ['1', '2', '3'])->name('clientes.novo.etapa');
        Route::post('/clientes/novo/concluir', [ClienteController::class, 'concluir'])->name('clientes.novo.concluir');
        Route::post('/clientes/novo/cancelar', [ClienteController::class, 'cancelarAssistente'])->name('clientes.novo.cancelar');

        Route::whereUuid('cliente')->group(function (): void {
            Route::get('/clientes/{cliente}/editar', [ClienteController::class, 'editar'])->name('clientes.editar');
            Route::put('/clientes/{cliente}', [ClienteController::class, 'atualizar'])->name('clientes.atualizar');
            Route::post('/clientes/{cliente}/situacao', [ClienteController::class, 'situacao'])->name('clientes.situacao');
            Route::post('/clientes/{cliente}/plano', [ClienteController::class, 'plano'])->name('clientes.plano');
            Route::post('/clientes/{cliente}/teste', [ClienteController::class, 'estenderTeste'])->name('clientes.teste');
            Route::post('/clientes/{cliente}/faturas/{fatura}/baixa', [ClienteController::class, 'baixa'])->whereUuid('fatura')->name('clientes.baixa');
            Route::post('/clientes/{cliente}/notas', [ClienteController::class, 'anotar'])->name('clientes.notas');
            Route::post('/clientes/{cliente}/administradores', [ClienteController::class, 'novoAdministrador'])->name('clientes.administradores');
            Route::post('/clientes/{cliente}/administradores/{usuario}/link-senha', [ClienteController::class, 'linkSenha'])->whereUuid('usuario')->name('clientes.link-senha');
            Route::get('/clientes/{cliente}/{aba?}', [ClienteController::class, 'show'])->whereIn('aba', array_keys(ClienteController::ABAS))->name('clientes.show');
        });

        Route::get('/planos', [PlanoController::class, 'index'])->name('planos.index');
        Route::get('/planos/novo', [PlanoController::class, 'criar'])->name('planos.criar');
        Route::post('/planos', [PlanoController::class, 'salvar'])->name('planos.salvar');
        Route::get('/planos/{plano}/editar', [PlanoController::class, 'editar'])->whereUuid('plano')->name('planos.editar');
        Route::put('/planos/{plano}', [PlanoController::class, 'atualizar'])->whereUuid('plano')->name('planos.atualizar');
    });

    // --- Cliente: Minha assinatura (abre mesmo com o sistema suspenso) -----
    Route::middleware('papel:administrador')->group(function (): void {
        Route::get('/assinatura', [AssinaturaController::class, 'index'])->name('assinatura.index');
        Route::post('/assinatura/plano', [AssinaturaController::class, 'trocarPlano'])->name('assinatura.plano');
        Route::get('/assinatura/empresa', [AssinaturaController::class, 'empresa'])->name('assinatura.empresa');
        Route::put('/assinatura/empresa', [AssinaturaController::class, 'salvarEmpresa'])->name('assinatura.empresa.salvar');
    });
});

// --- Vitrine do design system: nunca existe em produção -----------------
if (! app()->isProduction()) {
    Route::get('/design-system', [DesignSystemController::class, 'index'])->name('design-system.index');
    Route::post('/design-system/validar', [DesignSystemController::class, 'validar'])->name('design-system.validar');
}
