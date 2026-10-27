<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Nucleo;

use CadGestor\Nucleo\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    public function testGeraUtf8ComBomEPontoEVirgula(): void
    {
        $csv = Csv::gerar(['Nome', 'Perfil'], [['João', 'Aluno'], ['Ana; Maria', 'Funcionário']]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("Nome;Perfil\n", $csv);
        self::assertStringContainsString('"Ana; Maria";Funcionário', $csv);
    }

    #[DataProvider('celulas')]
    public function testNeutralizaInjecaoDeFormula(mixed $valor, string $esperado): void
    {
        self::assertSame($esperado, Csv::celula($valor));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function celulas(): iterable
    {
        yield 'fórmula com =' => ['=HYPERLINK("http://mal")', '\'=HYPERLINK("http://mal")'];
        yield 'fórmula com +' => ['+1+1', "'+1+1"];
        yield 'fórmula com @' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'fórmula com -' => ['-1+cmd|x', "'-1+cmd|x"];
        yield 'tabulação' => ["\t=1", "'\t=1"];
        yield 'número negativo legítimo' => ['-12,5', '-12,5'];
        yield 'texto comum' => ['Maria', 'Maria'];
        yield 'nulo' => [null, ''];
        yield 'booleano' => [true, 'sim'];
    }
}
