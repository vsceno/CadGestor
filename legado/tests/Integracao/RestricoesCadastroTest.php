<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

/**
 * Regras de integridade de vínculos, regras de acesso e eventos.
 */
final class RestricoesCadastroTest extends BancoTestCase
{
    private const SQL_VINCULO = <<<'SQL'
        INSERT INTO vinculo (pessoa_id, empresa_id, perfil_id, data_inicio, data_fim)
        VALUES (:pessoa, :empresa, :perfil, :inicio, :fim)
        RETURNING id
        SQL;

    public function testMesmaPessoaPodeTerVinculosComVariasEmpresas(): void
    {
        $pessoa = $this->criarPessoa();
        $perfil = $this->idPerfil('Funcionário');

        foreach ([$this->criarEmpresa('11222333000181'), $this->criarEmpresa('11444777000161')] as $empresa) {
            $this->inserir(self::SQL_VINCULO, [
                'pessoa' => $pessoa, 'empresa' => $empresa, 'perfil' => $perfil,
                'inicio' => '2026-01-01', 'fim' => null,
            ]);
        }

        $consulta = $this->pdo->prepare('SELECT count(*) FROM vinculo WHERE pessoa_id = :p');
        $consulta->execute(['p' => $pessoa]);
        self::assertSame(2, $consulta->fetchColumn());
    }

    public function testVinculoAtivoDuplicadoERejeitadoMasHistoricoEPreservado(): void
    {
        $dados = [
            'pessoa' => $this->criarPessoa(),
            'empresa' => $this->criarEmpresa(),
            'perfil' => $this->idPerfil('Aluno'),
            'inicio' => '2025-02-01',
            'fim' => null,
        ];
        $primeiro = $this->inserir(self::SQL_VINCULO, $dados);

        self::assertSame('23505', $this->esperarViolacao(self::SQL_VINCULO, $dados));

        // Encerrado o primeiro, um novo vínculo igual é aceito e o antigo continua lá.
        $this->pdo->prepare(<<<'SQL'
            UPDATE vinculo SET status = 'encerrado', encerrado_em = now(), data_fim = '2025-12-31' WHERE id = :id
            SQL)->execute(['id' => $primeiro]);
        $this->inserir(self::SQL_VINCULO, ['inicio' => '2026-02-01'] + $dados);

        $consulta = $this->pdo->prepare('SELECT count(*) FROM vinculo WHERE pessoa_id = :p');
        $consulta->execute(['p' => $dados['pessoa']]);
        self::assertSame(2, $consulta->fetchColumn());
    }

    public function testVinculoComFimAntesDoInicioERejeitado(): void
    {
        self::assertSame('23514', $this->esperarViolacao(self::SQL_VINCULO, [
            'pessoa' => $this->criarPessoa(),
            'empresa' => $this->criarEmpresa(),
            'perfil' => $this->idPerfil('Pedreiro'),
            'inicio' => '2026-05-10',
            'fim' => '2026-05-01',
        ]));
    }

    public function testRegraDeAcessoDeveSerDePerfilOuDeVinculoNuncaAmbos(): void
    {
        $empresa = $this->criarEmpresa();
        $perfil = $this->idPerfil('Faxineiro');
        $vinculo = $this->inserir(self::SQL_VINCULO, [
            'pessoa' => $this->criarPessoa(), 'empresa' => $empresa, 'perfil' => $perfil,
            'inicio' => '2026-01-01', 'fim' => null,
        ]);
        $sql = <<<'SQL'
            INSERT INTO regra_acesso (empresa_id, perfil_id, vinculo_id, dia_semana, hora_inicio, hora_fim)
            VALUES (:empresa, :perfil, :vinculo, 1, '07:00', '17:00')
            SQL;

        self::assertSame('23514', $this->esperarViolacao($sql, [
            'empresa' => $empresa, 'perfil' => $perfil, 'vinculo' => $vinculo,
        ]));
        self::assertSame('23514', $this->esperarViolacao($sql, [
            'empresa' => $empresa, 'perfil' => null, 'vinculo' => null,
        ]));
    }

    public function testRegraDeAcessoAceitaFimDoDiaEmVinteQuatroHoras(): void
    {
        $empresa = $this->criarEmpresa();
        $sql = <<<'SQL'
            INSERT INTO regra_acesso (empresa_id, perfil_id, dia_semana, hora_inicio, hora_fim)
            VALUES (:empresa, :perfil, :dia, :inicio, :fim)
            SQL;
        $base = ['empresa' => $empresa, 'perfil' => $this->idPerfil('Funcionário')];

        $this->pdo->prepare($sql)->execute($base + ['dia' => 1, 'inicio' => '22:00', 'fim' => '24:00']);

        $viraMeiaNoite = $base + ['dia' => 1, 'inicio' => '22:00', 'fim' => '06:00'];
        self::assertSame('23514', $this->esperarViolacao($sql, $viraMeiaNoite));

        $diaInvalido = $base + ['dia' => 8, 'inicio' => '08:00', 'fim' => '12:00'];
        self::assertSame('23514', $this->esperarViolacao($sql, $diaInvalido));
    }

    public function testEventoNegadoExigeMotivoELiberadoNaoTemMotivo(): void
    {
        $sql = <<<'SQL'
            INSERT INTO evento_acesso (tipo_identificacao, resultado, motivo_negacao)
            VALUES ('digital', :resultado, :motivo)
            SQL;

        foreach ([['negado', null], ['negado', 'motivo_inventado'], ['liberado', 'fora_do_horario']] as [$r, $m]) {
            self::assertSame('23514', $this->esperarViolacao($sql, ['resultado' => $r, 'motivo' => $m]));
        }

        $this->pdo->prepare($sql)->execute(['resultado' => 'negado', 'motivo' => 'pessoa_nao_identificada']);
        self::assertTrue(true);
    }

    public function testCatracaGuardaApenasHashDoToken(): void
    {
        $sql = <<<'SQL'
            INSERT INTO catraca (empresa_id, nome, identificador_dispositivo, token_hash)
            VALUES (:empresa, 'Portaria', 'DEV-001', :token)
            SQL;
        $empresa = $this->criarEmpresa();

        $tokenEmClaro = ['empresa' => $empresa, 'token' => 'token-em-texto-claro'];
        self::assertSame('23514', $this->esperarViolacao($sql, $tokenEmClaro));

        $this->pdo->prepare($sql)->execute(['empresa' => $empresa, 'token' => hash('sha256', 'segredo')]);
        self::assertTrue(true);
    }

    public function testUpdatedAtEAtualizadoAutomaticamente(): void
    {
        $empresa = $this->criarEmpresa();
        $this->pdo->exec(
            "UPDATE empresa SET created_at = now() - interval '1 day', updated_at = now() - interval '1 day'",
        );

        // now() é fixo dentro da transação; comparamos com o valor antigo.
        $this->pdo->prepare("UPDATE empresa SET nome_fantasia = 'Nova' WHERE id = :id")->execute(['id' => $empresa]);

        $consulta = $this->pdo->prepare('SELECT updated_at > created_at FROM empresa WHERE id = :id');
        $consulta->execute(['id' => $empresa]);
        self::assertTrue($consulta->fetchColumn());
    }
}
