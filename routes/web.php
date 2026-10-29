<?php

use App\Http\Controllers\AssinaturaController;
use App\Http\Controllers\Auth\ConfirmacaoEmailController;
use App\Http\Controllers\Auth\RecuperacaoSenhaController;
use App\Http\Controllers\Auth\SenhaController;
use App\Http\Controllers\Auth\SessaoController;
use App\Http\Controllers\Cadastro\CatracaController;
use App\Http\Controllers\Cadastro\EmpresaController;
use App\Http\Controllers\Cadastro\PerfilController;
use App\Http\Controllers\Cadastro\PessoaController;
use App\Http\Controllers\Cadastro\RegraController;
use App\Http\Controllers\Cadastro\VinculoController;
use App\Http\Controllers\ContratarController;
use App\Http\Controllers\DesignSystemController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\Lgpd\ConsentimentoController;
use App\Http\Controllers\Lgpd\LgpdController;
use App\Http\Controllers\Plataforma\ClienteController;
use App\Http\Controllers\Plataforma\PlanoController;
use App\Http\Controllers\PortariaController;
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

// Contratação pelo site e documentos jurídicos (públicos).
Route::middleware('guest')->group(function (): void {
    Route::get('/contratar', [ContratarController::class, 'formulario'])->name('contratar');
    Route::post('/contratar', [ContratarController::class, 'cadastrar']);
    Route::get('/contratar/enviado', [ContratarController::class, 'enviado'])->name('contratar.enviado');
});
Route::get('/termos-de-uso', [ContratarController::class, 'documento'])->name('documentos.termos');
Route::get('/privacidade', [ContratarController::class, 'documento'])->name('documentos.privacidade');
Route::get('/contrato-dados', [ContratarController::class, 'documento'])->name('documentos.contrato-dados');

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

    // --- Cadastros de acesso: empresas, catracas, perfis e regras ----------
    Route::middleware('papel:cadastro_empresa')->group(function (): void {
        Route::get('/empresas', [EmpresaController::class, 'index'])->name('empresas.index');
        Route::get('/empresas/novo', [EmpresaController::class, 'criar'])->name('empresas.criar');
        Route::post('/empresas', [EmpresaController::class, 'salvar'])->name('empresas.salvar');

        Route::whereUuid('empresa')->group(function (): void {
            Route::get('/empresas/{empresa}/editar', [EmpresaController::class, 'editar'])->name('empresas.editar');
            Route::put('/empresas/{empresa}', [EmpresaController::class, 'atualizar'])->name('empresas.atualizar');
            Route::post('/empresas/{empresa}/situacao', [EmpresaController::class, 'situacao'])->name('empresas.situacao');
            Route::get('/empresas/{empresa}/configurar', [EmpresaController::class, 'configurar'])->name('empresas.configurar');
            Route::put('/empresas/{empresa}/perfis', [EmpresaController::class, 'salvarPerfis'])->name('empresas.perfis.salvar');
            Route::put('/empresas/{empresa}/horarios', [EmpresaController::class, 'salvarHorarios'])->name('empresas.horarios.salvar');
        });

        Route::get('/catracas', [CatracaController::class, 'index'])->name('catracas.index');
        Route::get('/catracas/novo', [CatracaController::class, 'criar'])->name('catracas.criar');
        Route::post('/catracas', [CatracaController::class, 'salvar'])->name('catracas.salvar');

        Route::whereUuid('catraca')->group(function (): void {
            Route::get('/catracas/{catraca}', [CatracaController::class, 'show'])->name('catracas.show');
            Route::get('/catracas/{catraca}/editar', [CatracaController::class, 'editar'])->name('catracas.editar');
            Route::put('/catracas/{catraca}', [CatracaController::class, 'atualizar'])->name('catracas.atualizar');
            Route::post('/catracas/{catraca}/situacao', [CatracaController::class, 'situacao'])->name('catracas.situacao');
            Route::post('/catracas/{catraca}/token', [CatracaController::class, 'renovarToken'])->name('catracas.token');
        });

        Route::get('/regras', [RegraController::class, 'index'])->name('regras.index');
        Route::whereUuid('empresa')->whereUuid('perfil')->group(function (): void {
            Route::get('/regras/{empresa}/{perfil}', [RegraController::class, 'editar'])->name('regras.editar');
            Route::put('/regras/{empresa}/{perfil}', [RegraController::class, 'salvar'])->name('regras.salvar');
        });

        Route::get('/perfis', [PerfilController::class, 'index'])->name('perfis.index');
    });

    // Perfis definem a base legal do tratamento de dados: só o administrador altera.
    Route::middleware('papel:administrador')->group(function (): void {
        Route::get('/perfis/novo', [PerfilController::class, 'criar'])->name('perfis.criar');
        Route::post('/perfis', [PerfilController::class, 'salvar'])->name('perfis.salvar');

        Route::whereUuid('perfil')->group(function (): void {
            Route::get('/perfis/{perfil}/editar', [PerfilController::class, 'editar'])->name('perfis.editar');
            Route::put('/perfis/{perfil}', [PerfilController::class, 'atualizar'])->name('perfis.atualizar');
        });
    });

    // --- Pessoas e vínculos: cadastro e situação, nunca exclusão -----------
    Route::middleware('papel:cadastro_pessoa')->group(function (): void {
        Route::get('/pessoas', [PessoaController::class, 'index'])->name('pessoas.index');
        Route::post('/pessoas/busca', [PessoaController::class, 'index'])->name('pessoas.busca');
        Route::get('/pessoas/novo', [PessoaController::class, 'criar'])->name('pessoas.criar');
        Route::post('/pessoas', [PessoaController::class, 'salvar'])->name('pessoas.salvar');

        Route::whereUuid('pessoa')->group(function (): void {
            Route::get('/pessoas/{pessoa}', [PessoaController::class, 'show'])->name('pessoas.show');
            Route::get('/pessoas/{pessoa}/editar', [PessoaController::class, 'editar'])->name('pessoas.editar');
            Route::put('/pessoas/{pessoa}', [PessoaController::class, 'atualizar'])->name('pessoas.atualizar');
            Route::post('/pessoas/{pessoa}/situacao', [PessoaController::class, 'situacao'])->name('pessoas.situacao');
            Route::post('/pessoas/{pessoa}/vinculos', [VinculoController::class, 'salvar'])->name('pessoas.vinculos.salvar');

            Route::get('/pessoas/{pessoa}/consentimento', [ConsentimentoController::class, 'formulario'])->name('pessoas.consentimento.formulario');
            Route::post('/pessoas/{pessoa}/consentimento', [ConsentimentoController::class, 'registrar'])->name('pessoas.consentimento.salvar');
            Route::post('/pessoas/{pessoa}/consentimento/revogar', [ConsentimentoController::class, 'revogar'])->name('pessoas.consentimento.revogar');
            Route::post('/pessoas/{pessoa}/biometria/excluir', [ConsentimentoController::class, 'excluirBiometrias'])->name('pessoas.biometria.excluir');
        });

        Route::whereUuid('pessoa')->whereUuid('vinculo')->group(function (): void {
            Route::get('/pessoas/{pessoa}/vinculos/{vinculo}/editar', [VinculoController::class, 'editar'])->name('pessoas.vinculos.editar');
            Route::put('/pessoas/{pessoa}/vinculos/{vinculo}', [VinculoController::class, 'atualizar'])->name('pessoas.vinculos.atualizar');
            Route::post('/pessoas/{pessoa}/vinculos/{vinculo}/suspender', [VinculoController::class, 'suspender'])->name('pessoas.vinculos.suspender');
            Route::post('/pessoas/{pessoa}/vinculos/{vinculo}/reativar', [VinculoController::class, 'reativar'])->name('pessoas.vinculos.reativar');
            Route::post('/pessoas/{pessoa}/vinculos/{vinculo}/encerrar', [VinculoController::class, 'encerrar'])->name('pessoas.vinculos.encerrar');
        });
    });

    // --- Portaria ao vivo: só leitura ---------------------------------------
    Route::middleware('papel:consulta')->get('/portaria', [PortariaController::class, 'index'])->name('portaria');

    // --- LGPD: termos e painel, só para o administrador -------------------
    Route::middleware('papel:administrador')->group(function (): void {
        Route::get('/lgpd', [LgpdController::class, 'index'])->name('lgpd.index');
        Route::post('/lgpd/termos', [LgpdController::class, 'publicarTermo'])->name('lgpd.termos.publicar');
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
