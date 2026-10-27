<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Repositorios\RelatorioRepositorio;

/**
 * Relatórios: limites do período no fuso local e agrupamentos.
 */
final class RelatorioTest extends BancoTestCase
{
    private RelatorioRepositorio $relatorios;
    private string $empresa;
    private string $funcionario;
    private string $aluno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relatorios = new RelatorioRepositorio($this->pdo, 'America/Fortaleza');
        $this->empresa = $this->criarEmpresa();
        $this->funcionario = $this->idPerfil('Funcionário');
        $this->aluno = $this->idPerfil('Aluno');

        // Fortaleza = UTC-3. 10/10 23:30 local = 11/10 02:30 UTC.
        $this->evento('2026-10-10 23:30:00-03', 'liberado', $this->funcionario);
        $this->evento('2026-10-11 00:10:00-03', 'negado', $this->funcionario, 'fora_do_horario');
        $this->evento('2026-10-11 09:00:00-03', 'liberado', $this->aluno);
        $this->evento('2026-10-12 00:00:00-03', 'liberado', $this->aluno);
    }

    public function testPeriodoRespeitaODiaLocalEInclusivo(): void
    {
        [$eventos, $total] = $this->relatorios->eventos($this->filtros('2026-10-11', '2026-10-11'), null);

        self::assertSame(2, $total, 'Só os eventos do dia 11 no horário de Fortaleza.');
        self::assertCount(2, $eventos);
    }

    public function testResumoPorPerfil(): void
    {
        $resumo = array_column($this->relatorios->resumoPorPerfil($this->filtros('2026-10-01', '2026-10-31')), null, 'grupo');

        self::assertSame(2, (int) $resumo['Funcionário']['total']);
        self::assertSame(1, (int) $resumo['Funcionário']['negados']);
        self::assertSame(2, (int) $resumo['Aluno']['liberados']);
    }

    public function testFiltroPorPerfilEResultado(): void
    {
        $filtros = ['perfil' => $this->funcionario, 'resultado' => 'negado'] + $this->filtros('2026-10-01', '2026-10-31');

        [, $total] = $this->relatorios->eventos($filtros, null);
        self::assertSame(1, $total);

        self::assertSame(
            [['motivo' => 'fora_do_horario', 'total' => 1]],
            $this->relatorios->resumoPorMotivo($filtros),
        );
    }

    public function testResumoPorDiaAgrupaNoFusoLocal(): void
    {
        $dias = array_column($this->relatorios->resumoPorDia($this->filtros('2026-10-01', '2026-10-31')), null, 'dia');

        self::assertSame(['2026-10-10', '2026-10-11', '2026-10-12'], array_keys($dias));
        self::assertSame(1, (int) $dias['2026-10-11']['negados']);
    }

    /** @return array{inicio: string, fim: string, empresa: ?string, perfil: ?string, catraca: ?string, resultado: ?string} */
    private function filtros(string $inicio, string $fim): array
    {
        return ['inicio' => $inicio, 'fim' => $fim, 'empresa' => null, 'perfil' => null, 'catraca' => null, 'resultado' => null];
    }

    private function evento(string $quando, string $resultado, string $perfil, ?string $motivo = null): void
    {
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO evento_acesso (empresa_id, perfil_id, data_hora, tipo_identificacao, resultado, motivo_negacao)
            VALUES (:e, :p, :q, 'digital', :r, :m)
            SQL)->execute(['e' => $this->empresa, 'p' => $perfil, 'q' => $quando, 'r' => $resultado, 'm' => $motivo]);
    }
}
