<?php

/**
 * Tabela de rotas da aplicação.
 *
 * Toda rota web passa por: sessão -> CSRF (em POST) -> login -> papel.
 * Parâmetros {id} aceitam apenas UUID. O isolamento entre organizações não
 * depende das rotas: o banco (RLS) só mostra linhas da organização da sessão.
 */

declare(strict_types=1);

use CadGestor\Http\Controllers\AlertaController;
use CadGestor\Http\Controllers\ApiPagamentoController;
use CadGestor\Http\Controllers\AssinaturaController;
use CadGestor\Http\Controllers\CadastroController;
use CadGestor\Http\Controllers\PagamentoSimuladoController;
use CadGestor\Http\Controllers\PlanoController;
use CadGestor\Http\Controllers\ApiCatracaController;
use CadGestor\Http\Controllers\AuditoriaController;
use CadGestor\Http\Controllers\AutenticacaoController;
use CadGestor\Http\Controllers\CatracaController;
use CadGestor\Http\Controllers\EmpresaController;
use CadGestor\Http\Controllers\InicioController;
use CadGestor\Http\Controllers\LgpdController;
use CadGestor\Http\Controllers\OrganizacaoController;
use CadGestor\Http\Controllers\PainelController;
use CadGestor\Http\Controllers\PerfilController;
use CadGestor\Http\Controllers\PessoaController;
use CadGestor\Http\Controllers\RegraAcessoController;
use CadGestor\Http\Controllers\RelatorioController;
use CadGestor\Http\Controllers\SaudeController;
use CadGestor\Http\Controllers\SimuladorController;
use CadGestor\Http\Controllers\UsuarioController;
use CadGestor\Http\Controllers\VinculoController;
use CadGestor\Http\Middleware\ExigeAutenticacao;
use CadGestor\Http\Middleware\ExigePapel;
use CadGestor\Http\Middleware\IniciaSessao;
use CadGestor\Http\Middleware\Preguicoso;
use CadGestor\Http\Middleware\VerificaAssinatura;
use CadGestor\Http\Middleware\VerificaCsrf;
use CadGestor\Nucleo\Aplicacao;
use CadGestor\Nucleo\Container;
use CadGestor\Nucleo\Resposta;
use CadGestor\Nucleo\Roteador;
use CadGestor\Nucleo\Visao;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Csrf;
use CadGestor\Seguranca\Papel;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\AssinaturaServico;

return static function (Roteador $rotas, Container $c, Aplicacao $app): void {
    // Controllers e middlewares são criados só quando a rota é chamada.
    $acao = static fn (string $classe, string $metodo): Closure =>
        static fn ($requisicao, array $parametros) => $c->obter($classe)->$metodo($requisicao, $parametros);

    $sessao = new Preguicoso(static fn (): IniciaSessao => new IniciaSessao(
        $c->obter(Sessao::class),
        $c->obter(Csrf::class),
        $c->obter(Autenticacao::class),
    ));
    $csrf = new Preguicoso(static fn (): VerificaCsrf => new VerificaCsrf($c->obter(Csrf::class)));
    $logado = new Preguicoso(static fn (): ExigeAutenticacao => new ExigeAutenticacao($c->obter(Autenticacao::class)));
    $assinatura = new Preguicoso(static fn (): VerificaAssinatura => new VerificaAssinatura(
        $c->obter(Autenticacao::class),
        $c->obter(AssinaturaServico::class),
        $c->obter(Sessao::class),
    ));
    $papel = static fn (Papel ...$papeis): Preguicoso => new Preguicoso(
        static fn (): ExigePapel => new ExigePapel($c->obter(Autenticacao::class), ...$papeis),
    );

    // --- Públicas, sem sessão ---------------------------------------------
    $rotas->get('/saude', $acao(SaudeController::class, 'verificar'));

    // --- API das catracas: sem sessão/CSRF; autenticação por token do dispositivo
    $rotas->post('/api/v1/acesso', $acao(ApiCatracaController::class, 'acesso'));
    $rotas->get('/api/v1/configuracao', $acao(ApiCatracaController::class, 'configuracao'));
    $rotas->get('/api/v1/sincronizacao', $acao(ApiCatracaController::class, 'sincronizacao'));
    $rotas->post('/api/v1/sincronizacao/{id}', $acao(ApiCatracaController::class, 'confirmarSincronizacao'));
    $rotas->post('/api/v1/eventos-offline', $acao(ApiCatracaController::class, 'eventosOffline'));

    // --- Avisos do gateway de pagamento (autenticidade conferida pelo adaptador)
    $rotas->post('/api/v1/pagamentos/webhook', $acao(ApiPagamentoController::class, 'webhook'));

    // --- Web (com sessão e CSRF) ------------------------------------------
    $rotas->grupo([$sessao, $csrf], static function (Roteador $rotas) use (
        $acao,
        $logado,
        $assinatura,
        $papel,
        $app,
    ): void {
        $rotas->get('/', static fn (): Resposta => Resposta::redirecionar('/painel'));
        $rotas->get('/login', $acao(AutenticacaoController::class, 'formulario'));
        $rotas->post('/login', $acao(AutenticacaoController::class, 'entrar'));

        // Loja pública: contratação self-service e documentos jurídicos.
        $rotas->get('/cadastro', $acao(CadastroController::class, 'formulario'));
        $rotas->post('/cadastro', $acao(CadastroController::class, 'cadastrar'));
        $rotas->get('/confirmar-email', $acao(CadastroController::class, 'confirmarEmail'));
        $rotas->get('/termos-de-uso', $acao(CadastroController::class, 'documento'));
        $rotas->get('/privacidade', $acao(CadastroController::class, 'documento'));
        $rotas->get('/contrato-dados', $acao(CadastroController::class, 'documento'));

        $rotas->grupo([$logado, $assinatura], static function (Roteador $rotas) use ($acao, $papel, $app): void {
            // --- Minha assinatura (abre mesmo com o sistema suspenso) ------
            // Qualquer usuário de organização vê; a plataforma não (não tem organização).
            $rotas->get(
                '/assinatura',
                $acao(AssinaturaController::class, 'index'),
                [$papel(...Papel::daOrganizacao())],
            );
            $rotas->grupo([$papel(Papel::Administrador)], static function (Roteador $rotas) use ($acao, $app): void {
                $rotas->post('/assinatura/plano', $acao(AssinaturaController::class, 'trocarPlano'));
                $rotas->post('/assinatura/cancelar', $acao(AssinaturaController::class, 'cancelar'));
                $rotas->get('/assinatura/exportar', $acao(AssinaturaController::class, 'exportar'));

                if (!$app->ambiente->emProducao()) {
                    $rotas->get('/pagamento-simulado/{id}', $acao(PagamentoSimuladoController::class, 'ver'));
                    $rotas->post('/pagamento-simulado/{id}', $acao(PagamentoSimuladoController::class, 'executar'));
                }
            });

            $rotas->post('/sair', $acao(AutenticacaoController::class, 'sair'));
            $rotas->get('/senha', $acao(AutenticacaoController::class, 'formularioSenha'));
            $rotas->post('/senha', $acao(AutenticacaoController::class, 'trocarSenha'));
            $rotas->get('/painel', $acao(PainelController::class, 'index'));

            // --- Plataforma: organizações (clientes), planos e assinaturas -
            $rotas->grupo([$papel(Papel::Plataforma)], static function (Roteador $rotas) use ($acao): void {
                $rotas->get('/planos', $acao(PlanoController::class, 'index'));
                $rotas->get('/planos/novo', $acao(PlanoController::class, 'novo'));
                $rotas->post('/planos', $acao(PlanoController::class, 'criar'));
                $rotas->get('/planos/{id}/editar', $acao(PlanoController::class, 'editar'));
                $rotas->post('/planos/{id}', $acao(PlanoController::class, 'atualizar'));

                $rotas->get('/organizacoes', $acao(OrganizacaoController::class, 'index'));
                $rotas->get('/organizacoes/nova', $acao(OrganizacaoController::class, 'novo'));
                $rotas->post('/organizacoes', $acao(OrganizacaoController::class, 'criar'));
                $rotas->get('/organizacoes/{id}', $acao(OrganizacaoController::class, 'ver'));
                $rotas->get('/organizacoes/{id}/editar', $acao(OrganizacaoController::class, 'editar'));
                $rotas->post('/organizacoes/{id}', $acao(OrganizacaoController::class, 'atualizar'));
                $rotas->post('/organizacoes/{id}/situacao', $acao(OrganizacaoController::class, 'alterarSituacao'));
                $rotas->post(
                    '/organizacoes/{id}/administradores',
                    $acao(OrganizacaoController::class, 'criarAdministrador'),
                );
            });

            // --- Alertas: consulta vê, administrador trata ------------------
            $rotas->get('/alertas', $acao(AlertaController::class, 'index'), [$papel(Papel::Consulta)]);
            $rotas->post(
                '/alertas/{id}/tratar',
                $acao(AlertaController::class, 'tratar'),
                [$papel(Papel::Administrador)],
            );

            // --- Empresas, catracas e regras (cadastro_empresa) -----------
            $rotas->grupo([$papel(Papel::CadastroEmpresa)], static function (Roteador $rotas) use ($acao): void {
                $rotas->get('/empresas', $acao(EmpresaController::class, 'index'));
                $rotas->get('/empresas/nova', $acao(EmpresaController::class, 'novo'));
                $rotas->post('/empresas', $acao(EmpresaController::class, 'criar'));
                $rotas->get('/empresas/{id}', $acao(EmpresaController::class, 'ver'));
                $rotas->get('/empresas/{id}/editar', $acao(EmpresaController::class, 'editar'));
                $rotas->post('/empresas/{id}', $acao(EmpresaController::class, 'atualizar'));
                $rotas->post('/empresas/{id}/situacao', $acao(EmpresaController::class, 'alterarSituacao'));
                $rotas->post('/empresas/{id}/perfis', $acao(EmpresaController::class, 'salvarPerfis'));
                $rotas->post('/empresas/{id}/horarios', $acao(EmpresaController::class, 'salvarHorarios'));
                $rotas->get('/empresas/{id}/regras', $acao(RegraAcessoController::class, 'index'));
                $rotas->post('/empresas/{id}/regras/{perfil}', $acao(RegraAcessoController::class, 'salvarDoPerfil'));

                $rotas->get('/catracas', $acao(CatracaController::class, 'index'));
                $rotas->get('/catracas/nova', $acao(CatracaController::class, 'novo'));
                $rotas->post('/catracas', $acao(CatracaController::class, 'criar'));
                $rotas->get('/catracas/{id}/editar', $acao(CatracaController::class, 'editar'));
                $rotas->post('/catracas/{id}', $acao(CatracaController::class, 'atualizar'));
                $rotas->post('/catracas/{id}/token', $acao(CatracaController::class, 'renovarToken'));
                $rotas->post('/catracas/{id}/situacao', $acao(CatracaController::class, 'alterarSituacao'));
            });

            // --- Pessoas e vínculos (cadastro_pessoa) ----------------------
            $rotas->grupo([$papel(Papel::CadastroPessoa)], static function (Roteador $rotas) use ($acao): void {
                $rotas->get('/pessoas', $acao(PessoaController::class, 'index'));
                $rotas->post('/pessoas/busca', $acao(PessoaController::class, 'index'));
                $rotas->get('/pessoas/nova', $acao(PessoaController::class, 'novo'));
                $rotas->post('/pessoas', $acao(PessoaController::class, 'criar'));
                $rotas->get('/pessoas/{id}', $acao(PessoaController::class, 'ver'));
                $rotas->get('/pessoas/{id}/editar', $acao(PessoaController::class, 'editar'));
                $rotas->post('/pessoas/{id}', $acao(PessoaController::class, 'atualizar'));
                $rotas->post('/pessoas/{id}/situacao', $acao(PessoaController::class, 'alterarSituacao'));
                $rotas->post('/pessoas/{id}/vinculos', $acao(VinculoController::class, 'criar'));
                $rotas->post('/vinculos/{id}', $acao(VinculoController::class, 'alterar'));
                $rotas->get('/vinculos/{id}/regras', $acao(VinculoController::class, 'regras'));
                $rotas->post('/vinculos/{id}/regras', $acao(VinculoController::class, 'salvarRegras'));

                // LGPD: consentimento e biometria
                $rotas->post('/pessoas/{id}/consentimento', $acao(LgpdController::class, 'registrarConsentimento'));
                $rotas->post(
                    '/pessoas/{id}/consentimento/revogar',
                    $acao(LgpdController::class, 'revogarConsentimento'),
                );
                $rotas->post('/pessoas/{id}/biometrias', $acao(LgpdController::class, 'cadastrarBiometria'));
                $rotas->post(
                    '/pessoas/{id}/biometrias/{biometria}/excluir',
                    $acao(LgpdController::class, 'excluirBiometria'),
                );
            });

            // --- Relatórios (consulta) --------------------------------------
            $rotas->grupo([$papel(Papel::Consulta)], static function (Roteador $rotas) use ($acao): void {
                $rotas->get('/relatorios', $acao(RelatorioController::class, 'index'));
                $rotas->get('/relatorios/exportar', $acao(RelatorioController::class, 'exportar'));
            });

            // --- Administração ---------------------------------------------
            $rotas->grupo([$papel(Papel::Administrador)], static function (Roteador $rotas) use ($acao, $app): void {
                $rotas->get('/usuarios', $acao(UsuarioController::class, 'index'));
                $rotas->get('/usuarios/novo', $acao(UsuarioController::class, 'novo'));
                $rotas->post('/usuarios', $acao(UsuarioController::class, 'criar'));
                $rotas->get('/usuarios/{id}/editar', $acao(UsuarioController::class, 'editar'));
                $rotas->post('/usuarios/{id}', $acao(UsuarioController::class, 'atualizar'));
                $rotas->post('/usuarios/{id}/senha', $acao(UsuarioController::class, 'redefinirSenha'));

                $rotas->get('/perfis', $acao(PerfilController::class, 'index'));
                $rotas->get('/perfis/novo', $acao(PerfilController::class, 'novo'));
                $rotas->post('/perfis', $acao(PerfilController::class, 'criar'));
                $rotas->get('/perfis/{id}/editar', $acao(PerfilController::class, 'editar'));
                $rotas->post('/perfis/{id}', $acao(PerfilController::class, 'atualizar'));

                $rotas->get('/termos', $acao(LgpdController::class, 'termos'));
                $rotas->get('/auditoria', $acao(AuditoriaController::class, 'index'));
                $rotas->get('/auditoria/exportar', $acao(AuditoriaController::class, 'exportar'));
                $rotas->post('/termos', $acao(LgpdController::class, 'publicarTermo'));

                // Diagnóstico técnico: só fora de produção.
                if (!$app->ambiente->emProducao()) {
                    $rotas->get('/diagnostico', $acao(InicioController::class, 'index'));
                    $rotas->get('/simulador', $acao(SimuladorController::class, 'index'));
                    $rotas->post('/simulador', $acao(SimuladorController::class, 'simular'));
                    Visao::compartilhar(['simulador_disponivel' => true]);
                }
            });
        });
    });
};
