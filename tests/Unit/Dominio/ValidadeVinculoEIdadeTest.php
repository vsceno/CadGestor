<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Dominio;

use CadGestor\Dominio\Idade;
use CadGestor\Dominio\ValidadeVinculo;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidadeVinculoEIdadeTest extends TestCase
{
    #[DataProvider('situacoes')]
    public function testSituacaoDoVinculo(string $status, string $inicio, ?string $fim, string $em, string $esperado): void
    {
        $data = new DateTimeImmutable($em, new DateTimeZone('America/Fortaleza'));

        self::assertSame($esperado, ValidadeVinculo::situacao($status, $inicio, $fim, $data));
        self::assertSame($esperado === ValidadeVinculo::VIGENTE, ValidadeVinculo::vigente($status, $inicio, $fim, $data));
    }

    /** @return iterable<string, array{string, string, ?string, string, string}> */
    public static function situacoes(): iterable
    {
        yield 'sem fim, depois do início' => ['ativo', '2026-01-01', null, '2026-10-27 10:00', ValidadeVinculo::VIGENTE];
        yield 'primeiro dia (inclusivo)' => ['ativo', '2026-10-27', null, '2026-10-27 00:00', ValidadeVinculo::VIGENTE];
        yield 'último dia (inclusivo)' => ['ativo', '2026-01-01', '2026-10-27', '2026-10-27 23:59', ValidadeVinculo::VIGENTE];
        yield 'dia seguinte ao fim' => ['ativo', '2026-01-01', '2026-10-26', '2026-10-27 00:01', ValidadeVinculo::VENCIDO];
        yield 'antes do início' => ['ativo', '2026-11-01', null, '2026-10-27 10:00', ValidadeVinculo::FUTURO];
        yield 'suspenso dentro do prazo' => ['suspenso', '2026-01-01', null, '2026-10-27', ValidadeVinculo::SUSPENSO];
        yield 'encerrado' => ['encerrado', '2026-01-01', '2026-12-31', '2026-10-27', ValidadeVinculo::ENCERRADO];
    }

    public function testDiasParaVencer(): void
    {
        $hoje = new DateTimeImmutable('2026-10-27 15:00');

        self::assertSame(0, ValidadeVinculo::diasParaVencer('2026-10-27', $hoje));
        self::assertSame(19, ValidadeVinculo::diasParaVencer('2026-11-15', $hoje));
        self::assertSame(-1, ValidadeVinculo::diasParaVencer('2026-10-26', $hoje));
        self::assertNull(ValidadeVinculo::diasParaVencer(null, $hoje));
    }

    #[DataProvider('idades')]
    public function testClassificacaoDeIdade(?string $nascimento, string $esperado): void
    {
        self::assertSame($esperado, Idade::classificar($nascimento, new DateTimeImmutable('2026-10-27')));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function idades(): iterable
    {
        yield 'adulto' => ['1988-04-12', 'adulto'];
        yield 'faz 18 hoje' => ['2008-10-27', 'adulto'];
        yield 'faz 18 amanhã' => ['2008-10-28', 'menor'];
        yield 'criança' => ['2015-01-01', 'menor'];
        yield 'sem data: coleta bloqueada' => [null, 'desconhecida'];
        yield 'data inválida' => ['2026-02-30', 'desconhecida'];
        yield 'data futura' => ['2030-01-01', 'desconhecida'];
    }
}
