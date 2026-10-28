<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use PDO;

/**
 * Travas de LGPD garantidas pelo próprio banco.
 */
final class RestricoesLgpdTest extends BancoTestCase
{
    public function testBiometriaSemConsentimentoERejeitada(): void
    {
        $pessoa = $this->criarPessoa();
        $outraPessoa = $this->criarPessoa('11144477735');
        $consentimentoDeOutro = $this->criarConsentimento($outraPessoa, $this->criarTermo());

        // Consentimento existe, mas é de OUTRA pessoa.
        $this->pdo->exec('SAVEPOINT sp');
        try {
            $this->criarBiometria($pessoa, $consentimentoDeOutro);
            self::fail('Biometria gravada com consentimento de outra pessoa.');
        } catch (\PDOException $erro) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT sp');
            self::assertSame('23514', $erro->getCode());
        }
    }

    public function testBiometriaComConsentimentoAtivoEAceita(): void
    {
        $pessoa = $this->criarPessoa();
        $consentimento = $this->criarConsentimento($pessoa, $this->criarTermo());

        self::assertNotSame('', $this->criarBiometria($pessoa, $consentimento));
    }

    public function testBiometriaComConsentimentoRevogadoERejeitada(): void
    {
        $pessoa = $this->criarPessoa();
        $consentimento = $this->criarConsentimento($pessoa, $this->criarTermo());
        $this->pdo->prepare('UPDATE consentimento SET revogado_em = now() WHERE id = :id')
            ->execute(['id' => $consentimento]);

        $this->pdo->exec('SAVEPOINT sp');
        try {
            $this->criarBiometria($pessoa, $consentimento);
            self::fail('Biometria gravada com consentimento revogado.');
        } catch (\PDOException $erro) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT sp');
            self::assertSame('23514', $erro->getCode());
        }
    }

    public function testBiometriaExcluidaNaoGuardaTemplate(): void
    {
        $pessoa = $this->criarPessoa();
        $biometria = $this->criarBiometria($pessoa, $this->criarConsentimento($pessoa, $this->criarTermo()));

        // Marcar como excluída mantendo o template é proibido.
        self::assertSame('23514', $this->esperarViolacao(<<<'SQL'
            UPDATE biometria SET ativo = false, excluido_em = now(), motivo_exclusao = 'solicitacao_titular'
             WHERE id = :id
            SQL, ['id' => $biometria]));

        // Exclusão de fato: dados apagados.
        $this->pdo->prepare(<<<'SQL'
            UPDATE biometria
               SET ativo = false, excluido_em = now(), motivo_exclusao = 'solicitacao_titular',
                   template_cifrado = NULL, nonce = NULL, hash_integridade = NULL
             WHERE id = :id
            SQL)->execute(['id' => $biometria]);

        $consulta = $this->pdo->prepare('SELECT template_cifrado IS NULL FROM biometria WHERE id = :id');
        $consulta->execute(['id' => $biometria]);
        self::assertTrue($consulta->fetchColumn());
    }

    public function testBiometriaDigitalExigeDedoEFacialNaoTemDedo(): void
    {
        $pessoa = $this->criarPessoa();
        $consentimento = $this->criarConsentimento($pessoa, $this->criarTermo());
        $sql = <<<'SQL'
            INSERT INTO biometria (pessoa_id, consentimento_id, tipo, dedo, template_cifrado, nonce, hash_integridade)
            VALUES (:pessoa, :consentimento, :tipo, :dedo, '\x00', '\x00', :hash)
            SQL;
        $base = ['pessoa' => $pessoa, 'consentimento' => $consentimento, 'hash' => str_repeat('a', 64)];

        self::assertSame('23514', $this->esperarViolacao($sql, $base + ['tipo' => 'digital', 'dedo' => null]));
        self::assertSame('23514', $this->esperarViolacao($sql, $base + ['tipo' => 'digital', 'dedo' => 11]));
        self::assertSame('23514', $this->esperarViolacao($sql, $base + ['tipo' => 'facial', 'dedo' => 1]));
    }

    public function testConsentimentoDeMenorExigeResponsavel(): void
    {
        $pessoa = $this->criarPessoa('11144477735', '2015-03-01');
        $termo = $this->criarTermo();
        $sql = <<<'SQL'
            INSERT INTO consentimento (pessoa_id, termo_id, versao_termo, hash_termo, ip, titular_menor,
                                       responsavel_nome, responsavel_cpf, responsavel_parentesco)
            SELECT :pessoa, id, versao, hash_texto, '127.0.0.1', true, :nome, :cpf, :parentesco
              FROM termo_consentimento WHERE id = :termo
            SQL;

        self::assertSame('23514', $this->esperarViolacao($sql, [
            'pessoa' => $pessoa, 'termo' => $termo, 'nome' => null, 'cpf' => null, 'parentesco' => null,
        ]));

        // CPF do responsável também é validado.
        self::assertSame('23514', $this->esperarViolacao($sql, [
            'pessoa' => $pessoa, 'termo' => $termo, 'nome' => 'Mãe Teste', 'cpf' => '12345678900',
            'parentesco' => 'mae',
        ]));

        $this->pdo->prepare($sql)->execute([
            'pessoa' => $pessoa, 'termo' => $termo, 'nome' => 'Mãe Teste', 'cpf' => '52998224725',
            'parentesco' => 'mae',
        ]);
        self::assertSame(1, $this->contar('consentimento', $pessoa));
    }

    public function testApenasUmConsentimentoAtivoPorPessoa(): void
    {
        $pessoa = $this->criarPessoa();
        $termo = $this->criarTermo();
        $this->criarConsentimento($pessoa, $termo);

        self::assertSame('23505', $this->esperarViolacao(<<<'SQL'
            INSERT INTO consentimento (pessoa_id, termo_id, versao_termo, hash_termo, ip, titular_menor)
            SELECT :pessoa, id, versao, hash_texto, '127.0.0.1', false FROM termo_consentimento WHERE id = :termo
            SQL, ['pessoa' => $pessoa, 'termo' => $termo]));
    }

    public function testTermoPublicadoNaoPodeTerTextoAlterado(): void
    {
        $termo = $this->criarTermo();

        self::assertSame('23514', $this->esperarViolacao(
            "UPDATE termo_consentimento SET texto = 'outro texto' WHERE id = :id",
            ['id' => $termo],
        ));

        // Desativar o termo (sem mexer no texto) é permitido.
        $this->pdo->prepare('UPDATE termo_consentimento SET ativo = false WHERE id = :id')->execute(['id' => $termo]);
        self::assertTrue(true);
    }

    public function testHashDoTermoDeveCorresponderAoTexto(): void
    {
        self::assertSame('23514', $this->esperarViolacao(
            "INSERT INTO termo_consentimento (versao, texto, hash_texto) VALUES ('9.9', 'texto', :hash)",
            ['hash' => str_repeat('0', 64)],
        ));
    }

    public function testPerfisPadraoNascemComConsentimentoENaoValidados(): void
    {
        $perfis = $this->pdo
            ->query('SELECT nome, base_legal, base_legal_validada FROM perfil_vinculo ORDER BY nome')
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame(['Aluno', 'Faxineiro', 'Funcionário', 'Pedreiro'], array_column($perfis, 'nome'));

        foreach ($perfis as $perfil) {
            self::assertSame('consentimento', $perfil['base_legal']);
            self::assertFalse($perfil['base_legal_validada']);
        }
    }

    private function contar(string $tabela, string $pessoaId): int
    {
        $consulta = $this->pdo->prepare("SELECT count(*) FROM {$tabela} WHERE pessoa_id = :id");
        $consulta->execute(['id' => $pessoaId]);

        return (int) $consulta->fetchColumn();
    }
}
