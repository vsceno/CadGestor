<?php

/**
 * Registro dos serviços no container (quem depende de quem).
 */

declare(strict_types=1);

use CadGestor\Http\Controllers\AutenticacaoController;
use CadGestor\Http\Controllers\InicioController;
use CadGestor\Http\Controllers\PainelController;
use CadGestor\Http\Controllers\SaudeController;
use CadGestor\Http\Controllers\UsuarioController;
use CadGestor\Nucleo\Aplicacao;
use CadGestor\Nucleo\Container;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Csrf;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Seguranca\LimitadorTentativas;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\UsuarioServico;

return static function (Container $c, Aplicacao $app): void {
    $pdo = static fn (): PDO => $app->banco->pdo();
    $ambiente = $app->ambiente;

    // --- Segurança ---------------------------------------------------------
    $c->registrar(Sessao::class, static fn (): Sessao => new Sessao(
        $ambiente->booleano('SESSAO_SEGURA'),
        $ambiente->inteiro('SESSAO_INATIVIDADE_MINUTOS', 30),
    ));
    $c->registrar(Csrf::class, static fn (Container $c): Csrf => new Csrf($c->obter(Sessao::class)));
    $c->registrar(HashSenha::class, static fn (): HashSenha => new HashSenha());
    $c->registrar(LimitadorTentativas::class, static fn (): LimitadorTentativas => new LimitadorTentativas(
        $pdo(),
        $ambiente->texto('APP_SEGREDO'),
    ));
    $c->registrar(Auditoria::class, static fn (): Auditoria => new Auditoria($pdo(), $app->contexto));
    $c->registrar(Autenticacao::class, static fn (Container $c): Autenticacao => new Autenticacao(
        $c->obter(UsuarioRepositorio::class),
        $c->obter(Sessao::class),
        $c->obter(Csrf::class),
        $c->obter(HashSenha::class),
        $c->obter(LimitadorTentativas::class),
        $c->obter(Auditoria::class),
        $app->contexto,
    ));

    // --- Repositórios e serviços ------------------------------------------
    $c->registrar(UsuarioRepositorio::class, static fn (): UsuarioRepositorio => new UsuarioRepositorio($pdo()));
    $c->registrar(UsuarioServico::class, static fn (Container $c): UsuarioServico => new UsuarioServico(
        $pdo(),
        $c->obter(UsuarioRepositorio::class),
        $c->obter(HashSenha::class),
        $c->obter(Auditoria::class),
    ));

    // --- Controllers -------------------------------------------------------
    $c->registrar(SaudeController::class, static fn (): SaudeController => new SaudeController($app->banco, $app->log));
    $c->registrar(InicioController::class, static fn (): InicioController => new InicioController(
        $ambiente,
        $app->banco,
        $app->raiz . '/database/migracoes',
    ));
    $c->registrar(AutenticacaoController::class, static fn (Container $c): AutenticacaoController =>
        new AutenticacaoController(
            $c->obter(Sessao::class),
            $c->obter(Autenticacao::class),
            $c->obter(UsuarioServico::class),
        ));
    $c->registrar(PainelController::class, static fn (Container $c): PainelController => new PainelController(
        $c->obter(Sessao::class),
        $c->obter(Autenticacao::class),
        $pdo(),
    ));
    $c->registrar(UsuarioController::class, static fn (Container $c): UsuarioController => new UsuarioController(
        $c->obter(Sessao::class),
        $c->obter(UsuarioRepositorio::class),
        $c->obter(UsuarioServico::class),
        $c->obter(Autenticacao::class),
    ));
};
