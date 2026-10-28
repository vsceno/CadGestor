<?php

declare(strict_types=1);

namespace CadGestor\Banco;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Executor de migrações SQL versionadas (database/migracoes/NNNN_nome.sql).
 *
 * Funciona como um livro de obra: cada migração aplicada é anotada na tabela
 * migracao com o checksum do arquivo. Se alguém editar um arquivo já aplicado,
 * o checksum não bate e o migrador se recusa a continuar. Correções sempre
 * entram como uma NOVA migração (as migrações só andam para frente).
 */
final class Migrador
{
    /** Chave arbitrária do advisory lock: impede duas execuções simultâneas. */
    private const CHAVE_TRAVA = 7_302_026_001;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $diretorio,
    ) {
    }

    /**
     * Arquivos de migração encontrados no disco, ordenados pela versão.
     *
     * @return array<string, array{versao: string, nome: string, arquivo: string, checksum: string}>
     */
    public function disponiveis(): array
    {
        $migracoes = [];

        foreach (glob($this->diretorio . '/*.sql') ?: [] as $arquivo) {
            if (preg_match('/^(\d{4})_([a-z0-9_]+)\.sql$/', basename($arquivo), $m) !== 1) {
                throw new RuntimeException('Nome de migração fora do padrão NNNN_nome.sql: ' . basename($arquivo));
            }

            if (isset($migracoes[$m[1]])) {
                throw new RuntimeException("Versão de migração duplicada: {$m[1]}");
            }

            $migracoes[$m[1]] = [
                'versao' => $m[1],
                'nome' => $m[2],
                'arquivo' => $arquivo,
                'checksum' => self::checksum((string) file_get_contents($arquivo)),
            ];
        }

        ksort($migracoes, SORT_STRING);

        return $migracoes;
    }

    /**
     * Migrações já registradas no banco.
     *
     * @return array<string, array{versao: string, nome: string, checksum: string, aplicada_em: string}>
     */
    public function aplicadas(): array
    {
        if (!$this->tabelaControleExiste()) {
            return [];
        }

        $linhas = $this->pdo
            ->query('SELECT versao, nome, checksum, aplicada_em FROM migracao ORDER BY versao')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_column($linhas, null, 'versao');
    }

    /**
     * @return list<string> versões pendentes
     */
    public function pendentes(): array
    {
        $this->verificarIntegridade();

        return array_values(array_diff(array_keys($this->disponiveis()), array_keys($this->aplicadas())));
    }

    /**
     * Aplica as migrações pendentes, cada uma na sua própria transação.
     *
     * @param callable(string): void|null $aoAplicar recebe "NNNN_nome" de cada migração aplicada
     * @return list<string> versões aplicadas nesta execução
     */
    public function migrar(?callable $aoAplicar = null): array
    {
        $this->pdo->query('SELECT pg_advisory_lock(' . self::CHAVE_TRAVA . ')');

        try {
            $this->criarTabelaControle();
            $disponiveis = $this->disponiveis();
            $aplicadasAgora = [];

            foreach ($this->pendentes() as $versao) {
                $migracao = $disponiveis[$versao];
                $this->aplicar($migracao);
                $aplicadasAgora[] = $versao;

                if ($aoAplicar !== null) {
                    $aoAplicar("{$versao}_{$migracao['nome']}");
                }
            }

            return $aplicadasAgora;
        } finally {
            $this->pdo->query('SELECT pg_advisory_unlock(' . self::CHAVE_TRAVA . ')');
        }
    }

    /**
     * Normaliza fim de linha antes do hash: o mesmo arquivo com CRLF (Windows)
     * ou LF (git) gera o mesmo checksum.
     */
    public static function checksum(string $conteudo): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $conteudo));
    }

    /**
     * Falha se uma migração aplicada sumiu do disco ou teve o arquivo alterado.
     */
    public function verificarIntegridade(): void
    {
        $disponiveis = $this->disponiveis();

        foreach ($this->aplicadas() as $versao => $aplicada) {
            if (!isset($disponiveis[$versao])) {
                throw new RuntimeException("Migração {$versao} está registrada no banco mas o arquivo não existe.");
            }

            if (!hash_equals($aplicada['checksum'], $disponiveis[$versao]['checksum'])) {
                throw new RuntimeException(
                    "Migração {$versao} foi alterada depois de aplicada. Crie uma nova migração em vez de editar."
                );
            }
        }
    }

    /** @param array{versao: string, nome: string, arquivo: string, checksum: string} $migracao */
    private function aplicar(array $migracao): void
    {
        $sql = (string) file_get_contents($migracao['arquivo']);

        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec($sql);

            $registro = $this->pdo->prepare(
                'INSERT INTO migracao (versao, nome, checksum) VALUES (:versao, :nome, :checksum)'
            );
            $registro->execute([
                'versao' => $migracao['versao'],
                'nome' => $migracao['nome'],
                'checksum' => $migracao['checksum'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $erro) {
            $this->pdo->rollBack();

            throw new RuntimeException(
                "Falha ao aplicar a migração {$migracao['versao']}_{$migracao['nome']}: " . $erro->getMessage(),
                0,
                $erro,
            );
        }
    }

    private function tabelaControleExiste(): bool
    {
        return (bool) $this->pdo->query("SELECT to_regclass('migracao') IS NOT NULL")->fetchColumn();
    }

    private function criarTabelaControle(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS migracao (
                versao      char(4)     PRIMARY KEY,
                nome        varchar(100) NOT NULL,
                checksum    char(64)    NOT NULL,
                aplicada_em timestamptz NOT NULL DEFAULT now()
            )
            SQL);
    }
}
