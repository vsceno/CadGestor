<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

use PDO;

/**
 * Fábrica da conexão PDO com o PostgreSQL.
 *
 * A conexão é aberta só na primeira vez que alguém pede (preguiçosa) e depois
 * reaproveitada durante a requisição, como uma linha telefônica que fica
 * aberta até o fim da conversa.
 */
final class ConexaoBanco
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly int $porta,
        private readonly string $banco,
        private readonly string $usuario,
        #[\SensitiveParameter] private readonly string $senha,
        private readonly string $schema,
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $schema) !== 1) {
            throw new \InvalidArgumentException('Nome de schema inválido.');
        }
    }

    public static function doAmbiente(Ambiente $ambiente): self
    {
        return new self(
            $ambiente->texto('DB_HOST'),
            $ambiente->inteiro('DB_PORTA'),
            $ambiente->texto('DB_BANCO'),
            $ambiente->texto('DB_USUARIO'),
            $ambiente->texto('DB_SENHA', ''),
            $ambiente->texto('DB_SCHEMA'),
        );
    }

    /**
     * Conexão com o papel dono do schema, usada só por migrações, seeds e jobs.
     */
    public static function paraMigracao(Ambiente $ambiente): self
    {
        return new self(
            $ambiente->texto('DB_HOST'),
            $ambiente->inteiro('DB_PORTA'),
            $ambiente->texto('DB_BANCO'),
            $ambiente->texto('DB_MIGRACAO_USUARIO'),
            $ambiente->texto('DB_MIGRACAO_SENHA', ''),
            $ambiente->texto('DB_SCHEMA'),
        );
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;application_name=cadgestor',
                $this->host,
                $this->porta,
                $this->banco,
            );

            $this->pdo = new PDO($dsn, $this->usuario, $this->senha, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);

            // O banco trabalha sempre em UTC; a conversão para o fuso local
            // acontece só na exibição e nas regras de horário.
            $this->pdo->exec("SET TIME ZONE 'UTC'");
            $this->pdo->exec("SET search_path TO {$this->schema}");
        }

        return $this->pdo;
    }
}
