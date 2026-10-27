<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * O papel da aplicação (cadgestor_app) não pode apagar histórico nem mexer na
 * estrutura do banco. 42501 = insufficient_privilege.
 */
final class PermissoesAppTest extends BancoTestCase
{
    private PDO $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = self::conexaoApp();
        $this->app->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && $this->app->inTransaction()) {
            $this->app->rollBack();
        }

        parent::tearDown();
    }

    #[DataProvider('comandosProibidos')]
    public function testAplicacaoNaoPodeExecutar(string $sql): void
    {
        $this->app->exec('SAVEPOINT sp');

        try {
            $this->app->exec($sql);
            self::fail("A aplicação conseguiu executar: {$sql}");
        } catch (PDOException $erro) {
            $this->app->exec('ROLLBACK TO SAVEPOINT sp');
            self::assertSame('42501', $erro->getCode());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function comandosProibidos(): iterable
    {
        yield 'apagar auditoria' => ['DELETE FROM auditoria'];
        yield 'alterar auditoria' => ["UPDATE auditoria SET acao = 'login'"];
        yield 'apagar evento' => ['DELETE FROM evento_acesso'];
        yield 'alterar evento' => ["UPDATE evento_acesso SET resultado = 'liberado'"];
        yield 'apagar vínculo' => ['DELETE FROM vinculo'];
        yield 'apagar pessoa' => ['DELETE FROM pessoa'];
        yield 'apagar consentimento' => ['DELETE FROM consentimento'];
        yield 'apagar biometria' => ['DELETE FROM biometria'];
        yield 'alterar controle de migração' => ["UPDATE migracao SET checksum = 'x'"];
        yield 'criar tabela' => ['CREATE TABLE invasao (id int)'];
        yield 'apagar tabela' => ['DROP TABLE empresa'];
    }

    public function testAplicacaoPodeRegistrarAuditoriaELerDados(): void
    {
        $this->app->exec("INSERT INTO auditoria (acao, tabela) VALUES ('login', 'usuario_sistema')");

        self::assertSame(4, (int) $this->app->query('SELECT count(*) FROM perfil_vinculo')->fetchColumn());
    }
}
