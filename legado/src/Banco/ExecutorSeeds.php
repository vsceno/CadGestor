<?php

declare(strict_types=1);

namespace CadGestor\Banco;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Executa os seeds SQL (database/seeds/NN_nome.sql) em ordem.
 *
 * Diferente das migrações, seeds não são registrados: cada arquivo deve ser
 * idempotente (ON CONFLICT DO NOTHING), podendo rodar quantas vezes precisar.
 */
final class ExecutorSeeds
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $diretorio,
    ) {
    }

    /**
     * @param callable(string): void|null $aoExecutar recebe o nome de cada seed executado
     * @return list<string>
     */
    public function executar(?callable $aoExecutar = null): array
    {
        $arquivos = glob($this->diretorio . '/*.sql') ?: [];
        sort($arquivos, SORT_STRING);
        $executados = [];

        $this->pdo->beginTransaction();

        try {
            foreach ($arquivos as $arquivo) {
                $this->pdo->exec((string) file_get_contents($arquivo));
                $executados[] = basename($arquivo, '.sql');

                if ($aoExecutar !== null) {
                    $aoExecutar(basename($arquivo, '.sql'));
                }
            }

            $this->pdo->commit();
        } catch (Throwable $erro) {
            $this->pdo->rollBack();

            throw new RuntimeException('Falha ao executar seeds: ' . $erro->getMessage(), 0, $erro);
        }

        return $executados;
    }
}
