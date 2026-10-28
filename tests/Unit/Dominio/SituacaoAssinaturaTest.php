<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Dominio;

use CadGestor\Dominio\SituacaoAssinatura;
use CadGestor\Servicos\PlanoServico;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Régua de inadimplência: o que o cliente pode fazer em cada dia de atraso.
 */
final class SituacaoAssinaturaTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?string, string, string, bool, bool, bool}> */
    public static function casos(): iterable
    {
        // status, teste_ate, inadimplente_desde, hoje => nível, altera, usa, catracas
        yield 'ativa' => ['ativa', null, null, '2026-11-10', SituacaoAssinatura::NORMAL, true, true, true];
        yield 'teste no prazo' => ['teste', '2026-11-14', null, '2026-11-01', SituacaoAssinatura::NORMAL, true, true, true];
        yield 'teste no último dia' => ['teste', '2026-11-14', null, '2026-11-14', SituacaoAssinatura::NORMAL, true, true, true];
        yield 'teste vencido há 1 dia' => ['teste', '2026-11-14', null, '2026-11-15', SituacaoAssinatura::NORMAL, true, true, true];
        yield 'teste vencido há 8 dias' => ['teste', '2026-11-14', null, '2026-11-22', SituacaoAssinatura::SOMENTE_LEITURA, false, true, true];
        yield 'atraso dia 0' => ['inadimplente', null, '2026-11-01', '2026-11-01', SituacaoAssinatura::NORMAL, true, true, true];
        yield 'atraso dia 6' => ['inadimplente', null, '2026-11-01', '2026-11-07', SituacaoAssinatura::NORMAL, true, true, true];
        yield 'atraso dia 7' => ['inadimplente', null, '2026-11-01', '2026-11-08', SituacaoAssinatura::SOMENTE_LEITURA, false, true, true];
        yield 'atraso dia 14' => ['inadimplente', null, '2026-11-01', '2026-11-15', SituacaoAssinatura::SOMENTE_LEITURA, false, true, true];
        yield 'atraso dia 15: suspenso, catracas seguem' => ['inadimplente', null, '2026-11-01', '2026-11-16', SituacaoAssinatura::SUSPENSA, false, false, true];
        yield 'atraso dia 29' => ['inadimplente', null, '2026-11-01', '2026-11-30', SituacaoAssinatura::SUSPENSA, false, false, true];
        yield 'atraso dia 30: catracas param' => ['inadimplente', null, '2026-11-01', '2026-12-01', SituacaoAssinatura::BLOQUEADA, false, false, false];
        yield 'cancelada' => ['cancelada', null, null, '2026-11-10', SituacaoAssinatura::BLOQUEADA, false, false, false];
    }

    #[DataProvider('casos')]
    public function testRegua(
        string $status,
        ?string $testeAte,
        ?string $inadimplenteDesde,
        string $hoje,
        string $nivel,
        bool $altera,
        bool $usa,
        bool $catracas,
    ): void {
        $s = SituacaoAssinatura::calcular($status, $testeAte, $inadimplenteDesde, new DateTimeImmutable($hoje));

        self::assertSame($nivel, $s->nivel);
        self::assertSame($altera, $s->permiteAlterar());
        self::assertSame($usa, $s->permiteUsarSistema());
        self::assertSame($catracas, $s->catracasLiberadas());
    }

    public function testAvisaFimDoTesteNosUltimosDias(): void
    {
        $longe = SituacaoAssinatura::calcular('teste', '2026-11-14', null, new DateTimeImmutable('2026-11-01'));
        $perto = SituacaoAssinatura::calcular('teste', '2026-11-14', null, new DateTimeImmutable('2026-11-12'));

        self::assertNull($longe->aviso);
        self::assertStringContainsString('2 dia', (string) $perto->aviso);
    }

    public function testSemAssinaturaFalhaFechado(): void
    {
        self::assertFalse(SituacaoAssinatura::semAssinatura()->permiteUsarSistema());
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function precos(): iterable
    {
        yield 'vírgula' => ['199,90', 19990];
        yield 'milhar e vírgula' => ['1.290,50', 129050];
        yield 'ponto de milhar sem centavos' => ['1.290', 129000];
        yield 'ponto decimal' => ['199.9', 19990];
        yield 'inteiro' => ['500', 50000];
        yield 'com R$' => ['R$ 49,00', 4900];
        yield 'texto' => ['abc', null];
        yield 'negativo' => ['-10', null];
        yield 'três decimais' => ['10,999', null];
    }

    #[DataProvider('precos')]
    public function testConversaoDePreco(string $entrada, ?int $centavos): void
    {
        self::assertSame($centavos, PlanoServico::centavos($entrada));
    }
}
