<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Dominio;

use CadGestor\Dominio\IntervaloHorario;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IntervaloHorarioTest extends TestCase
{
    public function testContemComFimExclusivo(): void
    {
        $manha = new IntervaloHorario(1, '08:00:00', '12:00:00');

        self::assertTrue($manha->contem(1, '08:00:00'));
        self::assertTrue($manha->contem(1, '11:59:59'));
        self::assertFalse($manha->contem(1, '12:00:00'));
        self::assertFalse($manha->contem(1, '07:59:59'));
        self::assertFalse($manha->contem(2, '09:00:00'), 'Outro dia da semana.');
    }

    public function testMeiaNoiteComoFimDoDia(): void
    {
        $noite = IntervaloHorario::deFormulario('5', '22:00', '00:00');

        self::assertSame('24:00:00', $noite->fim);
        self::assertTrue($noite->contem(5, '23:59:59'));
    }

    public function testRejeitaFimAntesDoInicio(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IntervaloHorario::deFormulario('1', '18:00', '08:00');
    }

    public function testRejeitaDiaInvalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IntervaloHorario::deFormulario('8', '08:00', '12:00');
    }

    public function testRejeitaHoraMalFormada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IntervaloHorario::deFormulario('1', '25:00', '26:00');
    }

    public function testSobreposicao(): void
    {
        $a = new IntervaloHorario(1, '08:00:00', '12:00:00');

        self::assertTrue($a->sobrepoe(new IntervaloHorario(1, '11:00:00', '13:00:00')));
        self::assertFalse($a->sobrepoe(new IntervaloHorario(1, '12:00:00', '13:00:00')), 'Encostar não é sobrepor.');
        self::assertFalse($a->sobrepoe(new IntervaloHorario(2, '08:00:00', '12:00:00')));
        $cedo = new IntervaloHorario(1, '07:00:00', '09:00:00');
        $tarde = new IntervaloHorario(1, '13:00:00', '18:00:00');
        self::assertNotNull(IntervaloHorario::verificarSobreposicao([$a, $cedo]));
        self::assertNull(IntervaloHorario::verificarSobreposicao([$a, $tarde]));
    }
}
