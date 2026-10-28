<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Dominio;

use CadGestor\Dominio\PoliticaRetencao;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PoliticaRetencaoTest extends TestCase
{
    /**
     * @param list<array{status: string, data_inicio: string, data_fim: ?string}> $vinculos
     */
    #[DataProvider('casos')]
    public function testElegibilidade(array $vinculos, string $hoje, bool $esperado): void
    {
        $politica = new PoliticaRetencao(30);

        self::assertSame($esperado, $politica->elegivel($vinculos, '2026-01-01', new DateTimeImmutable($hoje)));
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string, bool}> */
    public static function casos(): iterable
    {
        $encerrado = ['status' => 'encerrado', 'data_inicio' => '2026-01-10', 'data_fim' => '2026-06-30'];

        yield 'no prazo (dia 30 após o fim)' => [[$encerrado], '2026-07-30', false];
        yield 'prazo vencido (dia 31 após o fim)' => [[$encerrado], '2026-07-31', true];
        yield 'vínculo vigente sem fim' => [[['status' => 'ativo', 'data_inicio' => '2026-01-10', 'data_fim' => null]], '2027-12-31', false];
        yield 'vínculo vigente impede mesmo com outro antigo' => [[
            $encerrado,
            ['status' => 'ativo', 'data_inicio' => '2026-07-01', 'data_fim' => '2027-06-30'],
        ], '2027-01-01', false];
        yield 'vínculo ativo já vencido conta a partir do fim' => [[
            ['status' => 'ativo', 'data_inicio' => '2026-01-10', 'data_fim' => '2026-03-31'],
        ], '2026-05-01', true];
        yield 'vínculo suspenso dentro do prazo não é elegível' => [[
            ['status' => 'suspenso', 'data_inicio' => '2026-01-10', 'data_fim' => '2026-03-31'],
        ], '2026-05-01', false];
        yield 'referência é o MAIOR fim entre vínculos encerrados' => [[
            $encerrado,
            ['status' => 'encerrado', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-09-30'],
        ], '2026-08-15', false];
        yield 'sem vínculo: conta da data de cadastro' => [[], '2026-02-01', true];
        yield 'sem vínculo, cadastro recente' => [[], '2026-01-31', false];
    }

    public function testDataLimite(): void
    {
        self::assertSame('2026-07-30', (new PoliticaRetencao(30))->dataLimite('2026-06-30'));
        self::assertSame('2027-06-30', (new PoliticaRetencao(365))->dataLimite('2026-06-30'));
    }

    public function testPrazoInvalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PoliticaRetencao(0);
    }
}
