<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Nucleo\Contexto;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Repositorios\PessoaRepositorio;
use CadGestor\Repositorios\VinculoRepositorio;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\LimitesPlano;
use CadGestor\Servicos\PessoaServico;
use CadGestor\Servicos\SincronizacaoServico;
use CadGestor\Servicos\VinculoServico;

final class PessoasVinculosTest extends BancoTestCase
{
    private PessoaServico $pessoas;
    private VinculoServico $vinculos;
    private VinculoRepositorio $vinculoRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $auditoria = new Auditoria($this->pdo, new Contexto('127.0.0.1'));
        $pessoaRepo = new PessoaRepositorio($this->pdo);
        $this->vinculoRepo = new VinculoRepositorio($this->pdo);
        $this->pessoas = new PessoaServico(
            $this->pdo,
            $pessoaRepo,
            $auditoria,
            new LimitesPlano(new AssinaturaRepositorio($this->pdo)),
        );
        $this->vinculos = new VinculoServico(
            $this->pdo,
            $this->vinculoRepo,
            $pessoaRepo,
            $auditoria,
            new SincronizacaoServico($this->pdo),
        );
    }

    public function testCriaPessoaComCpfNormalizadoEAuditoriaSemDadosPessoais(): void
    {
        $id = $this->pessoas->criar($this->dadosPessoa());

        $consulta = $this->pdo->prepare("SELECT dados_depois::text FROM auditoria WHERE registro_id = :id");
        $consulta->execute(['id' => $id]);
        $auditoria = (string) $consulta->fetchColumn();

        self::assertStringNotContainsString('52998224725', $auditoria);
        self::assertStringNotContainsString('Maria', $auditoria);
    }

    public function testValidaNomeCompletoEDataDeNascimentoFutura(): void
    {
        try {
            $this->pessoas->criar(['nome' => 'Maria', 'data_nascimento' => '2099-01-01'] + $this->dadosPessoa());
            self::fail('Deveria recusar.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('nome', $erro->erros);
            self::assertArrayHasKey('data_nascimento', $erro->erros);
        }
    }

    public function testVinculoExigePerfilAceitoPelaEmpresa(): void
    {
        $pessoa = $this->pessoas->criar($this->dadosPessoa());
        $empresa = $this->criarEmpresa();

        $this->expectException(ErroValidacao::class);
        $this->vinculos->criar($pessoa, $empresa . '|' . $this->idPerfil('Aluno'), '2026-01-01', '');
    }

    public function testMesmaPessoaEmDuasEmpresasEHistoricoAposEncerrar(): void
    {
        $pessoa = $this->pessoas->criar($this->dadosPessoa());
        $funcionario = $this->idPerfil('Funcionário');
        $empresaA = $this->criarEmpresa('11222333000181');
        $empresaB = $this->criarEmpresa('11444777000161');
        $this->aceitarPerfil($empresaA, $funcionario);
        $this->aceitarPerfil($empresaB, $funcionario);

        $vA = $this->vinculos->criar($pessoa, "{$empresaA}|{$funcionario}", '2026-01-01', '');
        $this->vinculos->criar($pessoa, "{$empresaB}|{$funcionario}", '2026-01-01', '2026-12-31');
        $this->vinculos->encerrar($vA, 'Desligamento', '2026-06-30');

        $historico = $this->vinculoRepo->daPessoa($pessoa);
        self::assertCount(2, $historico);
        self::assertSame(['ativo', 'encerrado'], array_column($historico, 'status'));

        // Depois de encerrado, um novo vínculo igual é permitido e o antigo continua no histórico.
        $this->vinculos->criar($pessoa, "{$empresaA}|{$funcionario}", '2026-08-01', '');
        self::assertCount(3, $this->vinculoRepo->daPessoa($pessoa));
    }

    public function testEncerrarExigeMotivoEDataValida(): void
    {
        $pessoa = $this->pessoas->criar($this->dadosPessoa());
        $empresa = $this->criarEmpresa();
        $perfil = $this->idPerfil('Pedreiro');
        $this->aceitarPerfil($empresa, $perfil);
        $vinculo = $this->vinculos->criar($pessoa, "{$empresa}|{$perfil}", '2026-05-01', '');

        foreach ([['', null, 'motivo'], ['Fim da obra', '2026-04-01', 'data_fim']] as [$motivo, $data, $campo]) {
            try {
                $this->vinculos->encerrar($vinculo, $motivo, $data);
                self::fail("Deveria recusar ({$campo}).");
            } catch (ErroValidacao $erro) {
                self::assertArrayHasKey($campo, $erro->erros);
            }
        }
    }

    public function testSuspenderEReativar(): void
    {
        $pessoa = $this->pessoas->criar($this->dadosPessoa());
        $empresa = $this->criarEmpresa();
        $perfil = $this->idPerfil('Faxineiro');
        $this->aceitarPerfil($empresa, $perfil);
        $vinculo = $this->vinculos->criar($pessoa, "{$empresa}|{$perfil}", '2026-01-01', '');

        $this->vinculos->suspender($vinculo);
        self::assertSame('suspenso', $this->vinculoRepo->buscar($vinculo)['status']);

        $this->vinculos->reativar($vinculo);
        self::assertSame('ativo', $this->vinculoRepo->buscar($vinculo)['status']);
    }

    public function testEncerrarUltimoVinculoDaEmpresaAgendaRemocaoNasCatracas(): void
    {
        $pessoa = $this->pessoas->criar($this->dadosPessoa());
        $empresa = $this->criarEmpresa();
        $perfil = $this->idPerfil('Funcionário');
        $this->aceitarPerfil($empresa, $perfil);
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO catraca (empresa_id, nome, identificador_dispositivo, token_hash)
            VALUES (:e, 'Portaria', 'DEV-SYNC', :t)
            SQL)->execute(['e' => $empresa, 't' => hash('sha256', 'x')]);

        $vinculo = $this->vinculos->criar($pessoa, "{$empresa}|{$perfil}", '2026-01-01', '');
        $this->vinculos->encerrar($vinculo, 'Desligamento');

        $consulta = $this->pdo->prepare("SELECT operacao FROM fila_sincronizacao WHERE pessoa_id = :p");
        $consulta->execute(['p' => $pessoa]);
        self::assertSame(['remover'], $consulta->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function aceitarPerfil(string $empresaId, string $perfilId): void
    {
        $this->pdo->prepare('INSERT INTO empresa_perfil (empresa_id, perfil_id) VALUES (:e, :p)')
            ->execute(['e' => $empresaId, 'p' => $perfilId]);
    }

    /** @return array<string, string> */
    private function dadosPessoa(): array
    {
        return [
            'nome' => 'Maria Fictícia da Silva',
            'cpf' => '529.982.247-25',
            'documento_tipo' => '',
            'documento_numero' => '',
            'documento_orgao' => '',
            'documento_uf' => '',
            'data_nascimento' => '1988-04-12',
            'email' => '',
            'telefone' => '',
        ];
    }
}
