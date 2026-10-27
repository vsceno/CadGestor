<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Nucleo;

use CadGestor\Nucleo\Visao;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VisaoTest extends TestCase
{
    public function testEscapaHtml(): void
    {
        self::assertSame('&lt;script&gt;alert(&apos;x&apos;)&lt;/script&gt;', e("<script>alert('x')</script>"));
    }

    public function testRenderizaTemplateComLayout(): void
    {
        $html = Visao::renderizar('erros/404', ['titulo' => '<b>Teste</b>']);

        self::assertStringContainsString('Página não encontrada', $html);
        self::assertStringContainsString('&lt;b&gt;Teste&lt;/b&gt;', $html);
    }

    public function testRejeitaNomeDeTemplateComTravessiaDeDiretorio(): void
    {
        $this->expectException(RuntimeException::class);

        Visao::renderizar('../.env');
    }
}
