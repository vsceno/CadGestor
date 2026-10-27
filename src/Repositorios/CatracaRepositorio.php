<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use PDO;

/**
 * Acesso a catraca. O token do dispositivo existe aqui só como hash SHA-256.
 */
final class CatracaRepositorio
{
    public const CAMPOS = [
        'empresa_id', 'nome', 'localizacao', 'identificador_dispositivo', 'tipo_identificacao', 'modo_falha',
        'fabricante', 'modelo',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listar(?string $empresaId = null): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT c.id, c.nome, c.localizacao, c.identificador_dispositivo, c.tipo_identificacao, c.modo_falha,
                   c.ativo, c.ultimo_contato_em, e.id AS empresa_id, e.razao_social
              FROM catraca c JOIN empresa e ON e.id = c.empresa_id
             WHERE CAST(:empresa AS uuid) IS NULL OR c.empresa_id = CAST(:empresa AS uuid)
             ORDER BY e.razao_social, c.nome
            SQL);
        $consulta->execute(['empresa' => $empresaId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function daEmpresa(string $empresaId): array
    {
        return $this->listar($empresaId);
    }

    /** @return array<string, mixed>|null */
    public function buscar(string $id): ?array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT c.*, e.razao_social, e.ativo AS empresa_ativa
              FROM catraca c JOIN empresa e ON e.id = c.empresa_id
             WHERE c.id = :id
            SQL);
        $consulta->execute(['id' => $id]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null catraca dona do token (comparação por hash) */
    public function buscarPorTokenHash(string $tokenHash): ?array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT c.*, e.ativo AS empresa_ativa
              FROM catraca c JOIN empresa e ON e.id = c.empresa_id
             WHERE c.token_hash = :hash
            SQL);
        $consulta->execute(['hash' => $tokenHash]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function identificadorEmUso(string $identificador, ?string $excetoId = null): bool
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT 1 FROM catraca
             WHERE lower(identificador_dispositivo) = lower(:ident) AND id IS DISTINCT FROM CAST(:id AS uuid)
            SQL);
        $consulta->execute(['ident' => $identificador, 'id' => $excetoId]);

        return $consulta->fetchColumn() !== false;
    }

    /** @param array<string, string|null> $dados */
    public function inserir(array $dados, string $tokenHash): string
    {
        $colunas = implode(', ', self::CAMPOS);
        $valores = implode(', ', array_map(static fn (string $c): string => ':' . $c, self::CAMPOS));

        $consulta = $this->pdo->prepare(
            "INSERT INTO catraca ({$colunas}, token_hash) VALUES ({$valores}, :token_hash) RETURNING id"
        );
        $consulta->execute(array_intersect_key($dados, array_flip(self::CAMPOS)) + ['token_hash' => $tokenHash]);

        return (string) $consulta->fetchColumn();
    }

    /** @param array<string, string|null> $dados */
    public function atualizar(string $id, array $dados): void
    {
        $campos = array_diff(self::CAMPOS, ['empresa_id']);
        $atribuicoes = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", $campos));

        $this->pdo->prepare("UPDATE catraca SET {$atribuicoes} WHERE id = :id")
            ->execute(array_intersect_key($dados, array_flip($campos)) + ['id' => $id]);
    }

    public function definirToken(string $id, string $tokenHash): void
    {
        $this->pdo->prepare('UPDATE catraca SET token_hash = :hash WHERE id = :id')
            ->execute(['id' => $id, 'hash' => $tokenHash]);
    }

    public function definirAtivo(string $id, bool $ativo): void
    {
        $this->pdo->prepare('UPDATE catraca SET ativo = :ativo WHERE id = :id')
            ->execute(['id' => $id, 'ativo' => $ativo ? 'true' : 'false']);
    }

    public function registrarContato(string $id): void
    {
        $this->pdo->prepare('UPDATE catraca SET ultimo_contato_em = now() WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return list<string> IDs das catracas ativas das empresas informadas */
    public function idsDasEmpresas(array $empresasIds): array
    {
        if ($empresasIds === []) {
            return [];
        }

        $consulta = $this->pdo->prepare(
            'SELECT id FROM catraca WHERE ativo AND empresa_id = ANY(CAST(:ids AS uuid[]))'
        );
        $consulta->execute(['ids' => '{' . implode(',', $empresasIds) . '}']);

        return array_map('strval', $consulta->fetchAll(PDO::FETCH_COLUMN));
    }
}
