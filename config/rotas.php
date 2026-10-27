<?php

/**
 * Tabela de rotas da aplicação.
 *
 * Toda rota web passa por: sessão -> CSRF (em POST) -> login -> papel.
 * Parâmetros {id} aceitam apenas UUID.
 */

declare(strict_types=1);

use CadGestor\Http\Controllers\AlertaController;
use CadGestor\Http\Controllers\ApiCatracaController;
use CadGestor\Http\Controllers\AutenticacaoController;
use CadGestor\Http\Controllers\CatracaController;
use CadGestor\Http\Controllers\EmpresaController;
use CadGestor\Http\Controllers\InicioController;
use CadGestor\Http\Controllers\LgpdController;
use CadGestor\Http\Controllers\PainelController;
use CadGestor\Http\Controllers\PerfilController;
use CadGestor\Http\Controllers\PessoaController;
use CadGestor\Http\Controllers\RegraAcessoController;
use CadGestor\Http\Controllers\SaudeController;
use CadGestor\Http\Controllers\SimuladorController;
use CadGestor\Http\Controllers\UsuarioController;
use CadGestor\Http\Controllers\VinculoController;
use CadGestor\Http\Middleware\ExigeAutenticacao;
use CadGestor\Http\Middleware\ExigePapel;
use CadGestor\Http\Middleware\IniciaSessao;
use CadGestor\Http\Middleware\Preguicoso;
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

    // --- Web (com sessão e CSRF) ------------------------------------------
    $rotas->grupo([$sessao, $csrf], static function (Roteador $rotas) use ($acao, $logado, $papel, $app): void {
        $rotas->get('/', static fn (): Resposta => Resposta::redirecionar('/painel'));
        $rotas->get('/login', $acao(AutenticacaoController::class, 'formulario'));
        $rotas->post('/login', $acao(AutenticacaoController::class, 'entrar'));

        $rotas->grupo([$logado], static function (Roteador $rotas) use ($acao, $papel, $app): void {
            $rotas->post('/sair', $acao(AutenticacaoController::class, 'sair'));
            $rotas->get('/senha', $acao(AutenticacaoController::class, 'formularioSenha'));
            $rotas->post('/senha', $acao(AutenticacaoController::class, 'trocarSenha'));
            $rotas->get('/painel', $acao(PainelController::class, 'index'));

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
