<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Acesso;

use CadGestor\Acesso\DecisaoAcesso;
use CadGestor\Acesso\FonteDadosAcesso;
use CadGestor\Acesso\ResultadoDecisao;
use CadGestor\Acesso\SolicitacaoAcesso;
use CadGestor\Biometria\TipoBiometria;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Cobre cada ramo da decisão de acesso, na ordem do escopo.
 *
 * Cenário base (tudo certo): catraca C1 ativa da empresa E1, pessoa P1 ativa,
 * vínculo V1 vigente como Funcionário (F), regra seg-sex 07:00-19:00 e
 * biometria digital ativa. Quarta-feira, 28/10/2026, 10:00 em Fortaleza.
 */
final class DecisaoAcessoTest extends TestCase
{
    private FonteEmMemoria $fonte;

    protected function setUp(): void
    {
        $this->fonte = new FonteEmMemoria();
    }

    public function testCenarioCompletoLibera(): void
    {
        $resultado = $this->decidir();

        self::assertTrue($resultado->liberado);
        self::assertNull($resultado->motivo);
        self::assertSame('V1', $resultado->vinculoId);
        self::assertSame('F', $resultado->perfilId);
        self::assertSame('E1', $resultado->empresaId);
    }

    // --- 1. Catraca --------------------------------------------------------

    public function testCatracaInexistente(): void
    {
        $this->assertNegado(ResultadoDecisao::CATRACA_INEXISTENTE, $this->decidir(catraca: 'C-NAO-EXISTE'));
    }

    public function testCatracaInativa(): void
    {
        $this->fonte->catracas['C1']['ativo'] = false;

        $this->assertNegado(ResultadoDecisao::CATRACA_INATIVA, $this->decidir());
    }

    public function testEmpresaDaCatracaInativa(): void
    {
        $this->fonte->catracas['C1']['empresa_ativa'] = false;

        $this->assertNegado(ResultadoDecisao::CATRACA_INATIVA, $this->decidir());
    }

    // --- 2. Pessoa -----------------------------------------------------------

    public function testNinguemReconhecido(): void
    {
        $this->assertNegado(ResultadoDecisao::PESSOA_NAO_IDENTIFICADA, $this->decidir(pessoa: null));
    }

    public function testPessoaInexistente(): void
    {
        $this->assertNegado(ResultadoDecisao::PESSOA_NAO_IDENTIFICADA, $this->decidir(pessoa: 'P-NAO-EXISTE'));
    }

    public function testPessoaInativa(): void
    {
        $this->fonte->pessoas['P1']['ativo'] = false;

        $this->assertNegado(ResultadoDecisao::PESSOA_INATIVA, $this->decidir());
    }

    public function testPessoaAnonimizada(): void
    {
        $this->fonte->pessoas['P1']['anonimizada'] = true;

        $this->assertNegado(ResultadoDecisao::PESSOA_INATIVA, $this->decidir());
    }

    // --- 3. Vínculo ----------------------------------------------------------

    public function testSemVinculoComAEmpresaDaCatraca(): void
    {
        $this->fonte->vinculos = [];

        $this->assertNegado(ResultadoDecisao::SEM_VINCULO_ATIVO, $this->decidir());
    }

    public function testVinculoComOutraEmpresaNaoServe(): void
    {
        $this->fonte->vinculos[0]['empresa_id'] = 'E2';

        $this->assertNegado(ResultadoDecisao::SEM_VINCULO_ATIVO, $this->decidir());
    }

    public function testVinculoVencido(): void
    {
        $this->fonte->vinculos[0]['data_fim'] = '2026-10-27';

        $this->assertNegado(ResultadoDecisao::SEM_VINCULO_ATIVO, $this->decidir());
    }

    public function testVinculoNoUltimoDiaDeValidadeAindaLibera(): void
    {
        $this->fonte->vinculos[0]['data_fim'] = '2026-10-28';

        self::assertTrue($this->decidir()->liberado);
    }

    public function testVinculoQueAindaNaoComecou(): void
    {
        $this->fonte->vinculos[0]['data_inicio'] = '2026-11-01';

        $this->assertNegado(ResultadoDecisao::SEM_VINCULO_ATIVO, $this->decidir());
    }

    public function testVinculoSuspenso(): void
    {
        $this->fonte->vinculos[0]['status'] = 'suspenso';

        $this->assertNegado(ResultadoDecisao::SEM_VINCULO_ATIVO, $this->decidir());
    }

    // --- 4. Perfil -----------------------------------------------------------

    public function testPerfilNaoAceitoPelaEmpresa(): void
    {
        $this->fonte->perfisPermitidos = [];

        $resultado = $this->decidir();
        $this->assertNegado(ResultadoDecisao::PERFIL_NAO_PERMITIDO, $resultado);
        self::assertSame('V1', $resultado->vinculoId, 'O evento registra o vínculo avaliado.');
    }

    // --- 5. Regras de horário -------------------------------------------------

    public function testForaDoHorarioDoPerfil(): void
    {
        $this->assertNegado(ResultadoDecisao::FORA_DO_HORARIO, $this->decidir(quando: '2026-10-28 19:00:00'));
    }

    public function testFimDeSemanaSemRegra(): void
    {
        $this->assertNegado(ResultadoDecisao::FORA_DO_HORARIO, $this->decidir(quando: '2026-10-31 10:00:00'));
    }

    public function testRegraRestritaAOutraCatraca(): void
    {
        foreach ($this->fonte->regrasPerfil as &$regra) {
            $regra['catraca_id'] = 'C2';
        }

        $this->assertNegado(ResultadoDecisao::FORA_DO_HORARIO, $this->decidir());
    }

    public function testRegrasDoVinculoSubstituemAsDoPerfil(): void
    {
        // Exceção individual: só sábado de manhã. Na quarta às 10h deve negar,
        // mesmo o perfil permitindo.
        $this->fonte->regrasVinculo = [['dia_semana' => 6, 'hora_inicio' => '07:00:00', 'hora_fim' => '13:00:00', 'catraca_id' => null]];

        $this->assertNegado(ResultadoDecisao::FORA_DO_HORARIO, $this->decidir());
        self::assertTrue($this->decidir(quando: '2026-10-31 08:00:00')->liberado);
    }

    public function testSemRegraDePerfilUsaHorarioPadraoDaEmpresa(): void
    {
        $this->fonte->regrasPerfil = [];
        $this->fonte->horarioPadrao = [['dia_semana' => 3, 'hora_inicio' => '09:00:00', 'hora_fim' => '11:00:00']];

        self::assertTrue($this->decidir()->liberado);
        $this->assertNegado(ResultadoDecisao::FORA_DO_HORARIO, $this->decidir(quando: '2026-10-28 11:00:00'));
    }

    public function testSemNenhumaRegraNega(): void
    {
        $this->fonte->regrasPerfil = [];
        $this->fonte->horarioPadrao = [];

        $this->assertNegado(ResultadoDecisao::FORA_DO_HORARIO, $this->decidir());
    }

    public function testTurnoAteMeiaNoite(): void
    {
        $this->fonte->regrasPerfil = [['dia_semana' => 3, 'hora_inicio' => '22:00:00', 'hora_fim' => '24:00:00', 'catraca_id' => null]];

        self::assertTrue($this->decidir(quando: '2026-10-28 23:59:59')->liberado);
    }

    public function testBastaUmDosVinculosNaEmpresaPassar(): void
    {
        // V1 (Funcionário) fora do horário; V2 (Aluno) com regra noturna.
        $this->fonte->vinculos[] = [
            'id' => 'V2', 'empresa_id' => 'E1', 'perfil_id' => 'A', 'status' => 'ativo',
            'data_inicio' => '2026-01-01', 'data_fim' => null,
        ];
        $this->fonte->perfisPermitidos[] = 'A';
        $this->fonte->regrasPorPerfil['A'] = [['dia_semana' => 3, 'hora_inicio' => '18:00:00', 'hora_fim' => '22:00:00', 'catraca_id' => null]];

        $resultado = $this->decidir(quando: '2026-10-28 20:00:00');

        self::assertTrue($resultado->liberado);
        self::assertSame('V2', $resultado->vinculoId);
    }

    // --- 6. Biometria --------------------------------------------------------

    public function testConfiancaAbaixoDoLimiar(): void
    {
        $this->assertNegado(ResultadoDecisao::BIOMETRIA_NAO_CONFERE, $this->decidir(score: 0.79));
    }

    public function testConfiancaIgualAoLimiarLibera(): void
    {
        self::assertTrue($this->decidir(score: 0.80)->liberado);
    }

    public function testSemBiometriaAtivaDoTipo(): void
    {
        $this->assertNegado(ResultadoDecisao::BIOMETRIA_NAO_CONFERE, $this->decidir(tipo: TipoBiometria::Facial));
    }

    // --- Ordem das verificações ---------------------------------------------

    public function testPrimeiraFalhaDefineOMotivo(): void
    {
        // Pessoa inativa E fora do horário E score baixo: vale a etapa 2.
        $this->fonte->pessoas['P1']['ativo'] = false;

        $this->assertNegado(
            ResultadoDecisao::PESSOA_INATIVA,
            $this->decidir(quando: '2026-10-31 03:00:00', score: 0.1),
        );
    }

    public function testLimiarInvalidoERecusado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DecisaoAcesso($this->fonte, 1.5);
    }

    private function decidir(
        string $catraca = 'C1',
        ?string $pessoa = 'P1',
        TipoBiometria $tipo = TipoBiometria::Digital,
        float $score = 0.95,
        string $quando = '2026-10-28 10:00:00',
    ): ResultadoDecisao {
        $data = new DateTimeImmutable($quando, new DateTimeZone('America/Fortaleza'));

        return (new DecisaoAcesso($this->fonte, 0.80))->decidir(
            new SolicitacaoAcesso($catraca, $pessoa, $tipo, $score, $data),
        );
    }

    private function assertNegado(string $motivo, ResultadoDecisao $resultado): void
    {
        self::assertFalse($resultado->liberado);
        self::assertSame($motivo, $resultado->motivo);
    }
}
