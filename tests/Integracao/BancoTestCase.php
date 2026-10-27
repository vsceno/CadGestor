<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Banco\ExecutorSeeds;
use CadGestor\Banco\Migrador;
use CadGestor\Nucleo\Ambiente;
use CadGestor\Nucleo\ConexaoBanco;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base dos testes de integração com o PostgreSQL.
 *
 * Na primeira vez em cada execução, apaga e recria o schema do banco de TESTE
 * (DB_TESTE_BANCO, definido no phpunit.xml), aplica todas as migrações e os
 * seeds. Cada teste roda dentro de uma transação desfeita no tearDown, então
 * um teste nunca enxerga dados de outro.
 */
abstract class BancoTestCase extends TestCase
{
    private static ?Ambiente $ambiente = null;
    private static ?PDO $pdoOwner = null;
    private static ?string $falhaPreparacao = null;
    private static bool $preparado = false;

    protected PDO $pdo;

    protected function setUp(): void
    {
        self::prepararBanco();

        if (self::$falhaPreparacao !== null) {
            self::markTestSkipped('Banco de teste indisponível: ' . self::$falhaPreparacao);
        }

        $this->pdo = self::$pdoOwner;
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** Conexão com o papel da aplicação (cadgestor_app) no banco de teste. */
    protected static function conexaoApp(): PDO
    {
        $ambiente = self::$ambiente;

        return (new ConexaoBanco(
            $ambiente->texto('DB_HOST'),
            $ambiente->inteiro('DB_PORTA'),
            self::nomeBancoTeste(),
            $ambiente->texto('DB_USUARIO'),
            $ambiente->texto('DB_SENHA', ''),
            $ambiente->texto('DB_SCHEMA'),
        ))->pdo();
    }

    protected static function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    // ---------------------------------------------------------------------
    // Fábricas de dados de teste (dados fictícios, CPFs/CNPJs de exemplo).
    // ---------------------------------------------------------------------

    protected function criarEmpresa(string $cnpj = '11222333000181'): string
    {
        $sql = <<<'SQL'
            INSERT INTO empresa (razao_social, cnpj, cep, logradouro, numero, bairro, municipio, uf,
                                 responsavel_nome, responsavel_email, responsavel_telefone)
            VALUES ('Empresa Teste Ltda', :cnpj, '60000000', 'Rua Teste', '100', 'Centro', 'Fortaleza', 'CE',
                    'Responsável Teste', 'responsavel@exemplo.com', '85999990000')
            RETURNING id
            SQL;

        return $this->inserir($sql, ['cnpj' => $cnpj]);
    }

    protected function criarPessoa(string $cpf = '52998224725', ?string $nascimento = '1990-05-10'): string
    {
        return $this->inserir(
            "INSERT INTO pessoa (nome, cpf, data_nascimento) VALUES ('Pessoa Teste', :cpf, :nasc) RETURNING id",
            ['cpf' => $cpf, 'nasc' => $nascimento],
        );
    }

    protected function idPerfil(string $nome): string
    {
        $consulta = $this->pdo->prepare('SELECT id FROM perfil_vinculo WHERE nome = :nome');
        $consulta->execute(['nome' => $nome]);

        return (string) $consulta->fetchColumn();
    }

    protected function criarTermo(string $versao = '1.0'): string
    {
        $texto = "Termo de consentimento de teste, versão {$versao}.";

        return $this->inserir(
            'INSERT INTO termo_consentimento (versao, texto, hash_texto) VALUES (:v, :t, :h) RETURNING id',
            ['v' => $versao, 't' => $texto, 'h' => hash('sha256', $texto)],
        );
    }

    protected function criarConsentimento(string $pessoaId, string $termoId): string
    {
        $sql = <<<'SQL'
            INSERT INTO consentimento (pessoa_id, termo_id, versao_termo, hash_termo, ip, titular_menor)
            SELECT :pessoa, t.id, t.versao, t.hash_texto, '127.0.0.1', false
              FROM termo_consentimento t WHERE t.id = :termo
            RETURNING id
            SQL;

        return $this->inserir($sql, ['pessoa' => $pessoaId, 'termo' => $termoId]);
    }

    protected function criarBiometria(string $pessoaId, string $consentimentoId): string
    {
        $sql = <<<'SQL'
            INSERT INTO biometria (pessoa_id, consentimento_id, tipo, dedo, template_cifrado, nonce, hash_integridade)
            VALUES (:pessoa, :consentimento, 'digital', 2, :template, :nonce, :hash)
            RETURNING id
            SQL;

        $consulta = $this->pdo->prepare($sql);
        $consulta->bindValue('pessoa', $pessoaId);
        $consulta->bindValue('consentimento', $consentimentoId);
        $consulta->bindValue('template', random_bytes(64), PDO::PARAM_LOB);
        $consulta->bindValue('nonce', random_bytes(24), PDO::PARAM_LOB);
        $consulta->bindValue('hash', str_repeat('a', 64));
        $consulta->execute();

        return (string) $consulta->fetchColumn();
    }

    /** @param array<string, scalar|null> $parametros */
    protected function inserir(string $sql, array $parametros = []): string
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($parametros);

        return (string) $consulta->fetchColumn();
    }

    /**
     * Executa o SQL esperando violação de regra do banco e devolve o SQLSTATE.
     *
     * @param array<string, scalar|null> $parametros
     */
    protected function esperarViolacao(string $sql, array $parametros = []): string
    {
        $this->pdo->exec('SAVEPOINT antes_da_violacao');

        try {
            $consulta = $this->pdo->prepare($sql);
            $consulta->execute($parametros);
        } catch (PDOException $erro) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT antes_da_violacao');

            return (string) $erro->getCode();
        }

        self::fail('Era esperada uma violação de regra do banco, mas o comando foi aceito.');
    }

    private static function nomeBancoTeste(): string
    {
        return (string) (getenv('DB_TESTE_BANCO') ?: 'cadgestor_teste');
    }

    private static function prepararBanco(): void
    {
        if (self::$preparado) {
            return;
        }

        self::$preparado = true;

        try {
            $ambiente = Ambiente::carregar(self::raiz());
            $bancoTeste = self::nomeBancoTeste();

            if ($bancoTeste === $ambiente->texto('DB_BANCO')) {
                throw new \RuntimeException('DB_TESTE_BANCO não pode ser o mesmo banco do .env.');
            }

            $schema = $ambiente->texto('DB_SCHEMA');
            $pdo = (new ConexaoBanco(
                $ambiente->texto('DB_HOST'),
                $ambiente->inteiro('DB_PORTA'),
                $bancoTeste,
                $ambiente->texto('DB_MIGRACAO_USUARIO'),
                $ambiente->texto('DB_MIGRACAO_SENHA', ''),
                $schema,
            ))->pdo();

            // Recria o schema do zero com as mesmas permissões do setup.
            $app = $ambiente->texto('DB_USUARIO');
            $pdo->exec(<<<SQL
                DROP SCHEMA IF EXISTS {$schema} CASCADE;
                CREATE SCHEMA {$schema};
                GRANT USAGE ON SCHEMA {$schema} TO {$app};
                ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema}
                    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$app};
                ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema}
                    GRANT USAGE, SELECT ON SEQUENCES TO {$app};
                SET search_path TO {$schema};
                SQL);

            (new Migrador($pdo, self::raiz() . '/database/migracoes'))->migrar();
            (new ExecutorSeeds($pdo, self::raiz() . '/database/seeds'))->executar();

            self::$ambiente = $ambiente;
            self::$pdoOwner = $pdo;
        } catch (Throwable $erro) {
            self::$falhaPreparacao = $erro->getMessage();
        }
    }
}
