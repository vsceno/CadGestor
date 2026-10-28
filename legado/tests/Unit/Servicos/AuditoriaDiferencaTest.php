<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Servicos;

use CadGestor\Servicos\Auditoria;
use PHPUnit\Framework\TestCase;

final class AuditoriaDiferencaTest extends TestCase
{
    public function testGuardaApenasCamposAlterados(): void
    {
        [$antes, $depois] = Auditoria::diferenca(
            ['nome' => 'Ana', 'email' => 'a@x.com', 'ativo' => true],
            ['nome' => 'Ana Maria', 'email' => 'a@x.com', 'ativo' => true],
        );

        self::assertSame(['nome' => 'Ana'], $antes);
        self::assertSame(['nome' => 'Ana Maria'], $depois);
    }

    public function testNuncaGuardaSegredos(): void
    {
        [$antes, $depois] = Auditoria::diferenca(null, [
            'nome' => 'Catraca 1',
            'token_hash' => 'abc',
            'senha_hash' => 'x',
            'template_cifrado' => 'y',
            'nonce' => 'z',
        ]);

        self::assertNull($antes);
        self::assertSame(['nome' => 'Catraca 1'], $depois);
    }

    public function testCriacaoMantemTodosOsCamposPermitidos(): void
    {
        [, $depois] = Auditoria::diferenca(null, ['a' => 1, 'b' => 2, 'updated_at' => 'agora']);

        self::assertSame(['a' => 1, 'b' => 2], $depois);
    }
}
