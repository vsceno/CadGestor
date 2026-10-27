<?php

/**
 * Tabela de rotas da aplicação.
 *
 * Toda rota web passa por: sessão -> CSRF (em POST) -> login -> papel.
 * Parâmetros {id} aceitam apenas UUID.
 */

declare(strict_types=1);

use CadGestor\Http\Controllers\AutenticacaoController;
use CadGestor\Http\Controllers\InicioController;
use CadGestor\Http\Controllers\PainelController;
use CadGestor\Http\Controllers\SaudeController;
use CadGestor\Http\Controllers\UsuarioController;
use CadGestor\Http\Middleware\ExigeAutenticacao;
use CadGestor\Http\Middleware\ExigePapel;
use CadGestor\Http\Middleware\IniciaSessao;
use CadGestor\Http\Middleware\Preguicoso;
use CadGestor\Http\Middleware\VerificaCsrf;
use CadGestor\Nucleo\Aplicacao;
use CadGestor\Nucleo\Container;
use CadGestor\Nucleo\Resposta;
use CadGestor\Nucleo\Roteador;
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

            // --- Administração ---------------------------------------------
            $rotas->grupo([$papel(Papel::Administrador)], static function (Roteador $rotas) use ($acao, $app): void {
                $rotas->get('/usuarios', $acao(UsuarioController::class, 'index'));
                $rotas->get('/usuarios/novo', $acao(UsuarioController::class, 'novo'));
                $rotas->post('/usuarios', $acao(UsuarioController::class, 'criar'));
                $rotas->get('/usuarios/{id}/editar', $acao(UsuarioController::class, 'editar'));
                $rotas->post('/usuarios/{id}', $acao(UsuarioController::class, 'atualizar'));
                $rotas->post('/usuarios/{id}/senha', $acao(UsuarioController::class, 'redefinirSenha'));

                // Diagnóstico técnico: só fora de produção.
                if (!$app->ambiente->emProducao()) {
                    $rotas->get('/diagnostico', $acao(InicioController::class, 'index'));
                }
            });
        });
    });
};
