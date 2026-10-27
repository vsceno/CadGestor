<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Dominio\PoliticaRetencao;
use CadGestor\Nucleo\Contexto;
use CadGestor\Repositorios\LgpdRepositorio;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\ManutencaoServico;
use CadGestor\Servicos\SincronizacaoServico;
use DateTimeImmutable;

/**
 * Jobs de manutenção: vínculos vencidos, retenção e limpeza.
 */
final class ManutencaoTest extends BancoTestCase
{
    private ManutencaoServico $manutencao;
    private string $empresa;
    private string $perfil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manutencao = new ManutencaoServico(
            $this->pdo,
            new PoliticaRetencao(30),
            new LgpdRepositorio($this->pdo),
            new SincronizacaoServico($this->pdo),
            new Auditoria($this->pdo, new Contexto('127.0.0.1')),
        );
        $this->empresa = $this->criarEmpresa();
        $this->perfil = $this->idPerfil('Pedreiro');
        $this->pdo->prepare('INSERT INTO empresa_perfil (empresa_id, perfil_id) VALUES (:e, :p)')
            ->execute(['e' => $this->empresa, 'p' => $this->perfil]);
        $this->pdo->prepare("INSERT INTO catraca (empresa_id, nome, identificador_dispositivo, token_hash) VALUES (:e, 'C', 'DEV-M', :t)")
            ->execute(['e' => $this->empresa, 't' => hash('sha256', 'm')]);
    }

    public function testEncerraVinculosVencidosEAgendaRemocao(): void
    {
        $pessoa = $this->criarPessoa();
        $vinculo = $this->vinculo($pessoa, '2026-01-01', '2026-03-31');

        $resultado = $this->manutencao->executar(['vinculos'], new DateTimeImmutable('2026-04-01'));

        self::assertSame(1, $resultado['vinculos_encerrados']);
        self::assertSame('encerrado', $this->valor('SELECT status FROM vinculo WHERE id = :id', $vinculo));
        self::assertSame('remover', $this->valor('SELECT operacao FROM fila_sincronizacao WHERE pessoa_id = :id', $pessoa));
    }

    public function testRetencaoApagaBiometriaEAnonimizaSoAposOPrazo(): void
    {
        $pessoa = $this->criarPessoa('11144477735', '2010-05-05');
        $this->vinculo($pessoa, '2026-01-01', '2026-03-31', 'encerrado');
        $this->consentimentoDeMenorComBiometria($pessoa);

        // Dentro do prazo (30 dias após 31/03): nada muda.
        $antes = $this->manutencao->executar(['retencao'], new DateTimeImmutable('2026-04-30'));
        self::assertSame(0, $antes['titulares_anonimizados']);

        $depois = $this->manutencao->executar(['retencao'], new DateTimeImmutable('2026-05-01'));
        self::assertSame(1, $depois['titulares_anonimizados']);
        self::assertSame(1, $depois['biometrias_excluidas']);

        $consulta = $this->pdo->prepare('SELECT * FROM pessoa WHERE id = :id');
        $consulta->execute(['id' => $pessoa]);
        $registro = $consulta->fetch();
        self::assertNull($registro['cpf']);
        self::assertNull($registro['data_nascimento']);
        self::assertSame('Titular anonimizado', $registro['nome']);
        self::assertNotNull($registro['anonimizada_em']);

        $consentimento = $this->pdo->prepare('SELECT responsavel_cpf, responsavel_nome, anonimizado_em FROM consentimento WHERE pessoa_id = :id');
        $consentimento->execute(['id' => $pessoa]);
        $c = $consentimento->fetch();
        self::assertNull($c['responsavel_cpf'], 'Dados do responsável (terceiro) também são apagados.');
        self::assertNotNull($c['anonimizado_em']);

        self::assertSame(0, (int) $this->valor(
            'SELECT count(*) FROM biometria WHERE pessoa_id = :id AND template_cifrado IS NOT NULL',
            $pessoa,
        ));
        self::assertSame('anonimizar', $this->valor(
            "SELECT acao FROM auditoria WHERE registro_id = :id AND acao = 'anonimizar'",
            $pessoa,
        ));
    }

    public function testPessoaComVinculoVigenteNaoEAnonimizada(): void
    {
        $pessoa = $this->criarPessoa();
        $this->vinculo($pessoa, '2020-01-01', '2020-12-31', 'encerrado');
        $this->vinculo($pessoa, '2025-01-01', null);

        $resultado = $this->manutencao->executar(['retencao'], new DateTimeImmutable('2026-10-27'));

        self::assertSame(0, $resultado['titulares_anonimizados']);
    }

    public function testSimulacaoNaoAlteraNada(): void
    {
        $pessoa = $this->criarPessoa();
        $this->vinculo($pessoa, '2025-01-01', '2025-02-01');
        $auditoriaAntes = (int) $this->pdo->query('SELECT count(*) FROM auditoria')->fetchColumn();

        $resultado = $this->manutencao->executar(ManutencaoServico::TAREFAS, new DateTimeImmutable('2026-10-27'), true);

        self::assertSame(1, $resultado['vinculos_encerrados']);
        self::assertSame(1, $resultado['titulares_anonimizados']);
        self::assertSame('ativo', $this->valor('SELECT status FROM vinculo WHERE pessoa_id = :id', $pessoa));
        self::assertNotNull($this->valor('SELECT cpf FROM pessoa WHERE id = :id', $pessoa));
        self::assertSame($auditoriaAntes, (int) $this->pdo->query('SELECT count(*) FROM auditoria')->fetchColumn());
    }

    public function testLimpezaRemoveSoDadosTecnicosAntigos(): void
    {
        $this->pdo->exec("INSERT INTO tentativa_login (email_hash, ip, sucesso, data_hora) VALUES
            (repeat('a', 64), '10.0.0.1', false, now() - interval '100 days'),
            (repeat('b', 64), '10.0.0.1', false, now() - interval '10 days')");

        $resultado = $this->manutencao->executar(['limpeza'], new DateTimeImmutable('today'));

        self::assertSame(1, $resultado['tentativas_login_removidas']);
        self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM tentativa_login')->fetchColumn());
    }

    public function testAlertaCatracaSemContatoUmaVezSo(): void
    {
        $this->pdo->exec("UPDATE catraca SET ultimo_contato_em = now() - interval '2 hours'");

        self::assertSame(1, $this->manutencao->executar(['catracas'], new DateTimeImmutable())['catracas_sem_contato']);
        self::assertSame(0, $this->manutencao->executar(['catracas'], new DateTimeImmutable())['catracas_sem_contato']);
    }

    private function vinculo(string $pessoa, string $inicio, ?string $fim, string $status = 'ativo'): string
    {
        return $this->inserir(<<<'SQL'
            INSERT INTO vinculo (pessoa_id, empresa_id, perfil_id, data_inicio, data_fim, status, encerrado_em)
            VALUES (:p, :e, :f, :i, :fim, CAST(:s AS varchar), CASE WHEN CAST(:s AS varchar) = 'encerrado' THEN now() END)
            RETURNING id
            SQL, ['p' => $pessoa, 'e' => $this->empresa, 'f' => $this->perfil, 'i' => $inicio, 'fim' => $fim, 's' => $status]);
    }

    private function consentimentoDeMenorComBiometria(string $pessoa): void
    {
        $termo = $this->criarTermo();
        $consentimento = $this->inserir(<<<'SQL'
            INSERT INTO consentimento (pessoa_id, termo_id, versao_termo, hash_termo, ip, titular_menor,
                                       responsavel_nome, responsavel_cpf, responsavel_parentesco)
            SELECT :p, id, versao, hash_texto, '127.0.0.1', true, 'Mãe Teste', '52998224725', 'mae'
              FROM termo_consentimento WHERE id = :t
            RETURNING id
            SQL, ['p' => $pessoa, 't' => $termo]);
        $this->criarBiometria($pessoa, $consentimento);
    }

    private function valor(string $sql, string $id): mixed
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['id' => $id]);

        return $consulta->fetchColumn();
    }
}
