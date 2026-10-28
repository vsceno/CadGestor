<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Seguranca;

use CadGestor\Seguranca\Csrf;
use CadGestor\Seguranca\Sessao;
use PHPUnit\Framework\TestCase;

final class CsrfSessaoTest extends TestCase
{
    public function testTokenEEstavelNaSessaoEValida(): void
    {
        $csrf = new Csrf(Sessao::emMemoria());
        $token = $csrf->token();

        self::assertSame(64, strlen($token));
        self::assertSame($token, $csrf->token());
        self::assertTrue($csrf->validar($token));
    }

    public function testRecusaTokenAusenteOuDiferente(): void
    {
        $csrf = new Csrf(Sessao::emMemoria());
        $csrf->token();

        self::assertFalse($csrf->validar(null));
        self::assertFalse($csrf->validar(''));
        self::assertFalse($csrf->validar(str_repeat('a', 64)));
    }

    public function testTokenDeOutraSessaoNaoServe(): void
    {
        $tokenAlheio = (new Csrf(Sessao::emMemoria()))->token();
        $csrf = new Csrf(Sessao::emMemoria());
        $csrf->token();

        self::assertFalse($csrf->validar($tokenAlheio));
    }

    public function testRenovarInvalidaTokenAnterior(): void
    {
        $csrf = new Csrf(Sessao::emMemoria());
        $antigo = $csrf->token();
        $csrf->renovar();

        self::assertNotSame($antigo, $csrf->token());
        self::assertFalse($csrf->validar($antigo));
    }

    public function testFlashEConsumidoUmaVez(): void
    {
        $sessao = Sessao::emMemoria();
        $sessao->flash('sucesso', 'Salvo.');

        self::assertSame([['tipo' => 'sucesso', 'mensagem' => 'Salvo.']], $sessao->consumirFlash());
        self::assertSame([], $sessao->consumirFlash());
    }

    public function testDestruirApagaDados(): void
    {
        $sessao = Sessao::emMemoria();
        $sessao->definir('usuario_id', 'x');
        $sessao->destruir();

        self::assertNull($sessao->obter('usuario_id'));
    }
}
