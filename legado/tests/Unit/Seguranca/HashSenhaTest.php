<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Seguranca;

use CadGestor\Seguranca\HashSenha;
use PHPUnit\Framework\TestCase;

final class HashSenhaTest extends TestCase
{
    public function testGeraHashArgon2idQueConfereComASenha(): void
    {
        $hash = (new HashSenha())->gerar('senha-de-teste-123');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue((new HashSenha())->verificar('senha-de-teste-123', $hash));
        self::assertFalse((new HashSenha())->verificar('senha-errada', $hash));
    }

    public function testHashBcryptAntigoPrecisaSerRefeito(): void
    {
        $antigo = password_hash('x', PASSWORD_BCRYPT);

        self::assertTrue((new HashSenha())->precisaRefazer($antigo));
        self::assertFalse((new HashSenha())->precisaRefazer((new HashSenha())->gerar('x')));
    }

    public function testSenhaAleatoriaRespeitaTamanhoMinimoESemCaracteresAmbiguos(): void
    {
        $hash = new HashSenha();

        self::assertSame(HashSenha::TAMANHO_MINIMO, strlen($hash->gerarSenhaAleatoria(4)));
        self::assertSame(30, strlen($hash->gerarSenhaAleatoria(30)));
        self::assertDoesNotMatchRegularExpression('/[0O1lI]/', $hash->gerarSenhaAleatoria(200));
        self::assertNotSame($hash->gerarSenhaAleatoria(), $hash->gerarSenhaAleatoria());
    }
}
