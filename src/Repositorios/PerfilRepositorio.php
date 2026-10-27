<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use PDO;

/**
 * Acesso a perfil_vinculo.
 */
final class PerfilRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listar(bool $apenasAtivos = false): array
    {
        $filtro = $apenasAtivos ? 'WHERE p.ativo' : '';

        return $this->pdo->query(<<<SQL
            SELECT p.*,
                   (SELECT count(*) FROM vinculo v WHERE v.perfil_id = p.id AND v.status = 'ativo') AS vinculos,
                   (SELECT count(*) FROM empresa_perfil ep WHERE ep.perfil_id = p.id) AS empresas
              FROM perfil_vinculo p
              {$filtro}
             ORDER BY p.ativo DESC, p.nome
            SQL)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function buscar(string $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM perfil_vinculo WHERE id = :id');
        $consulta->execute(['id' => $id]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function nomeEmUso(string $nome, ?string $excetoId = null): bool
    {
        $consulta = $this->pdo->prepare(
            'SELECT 1 FROM perfil_vinculo WHERE lower(nome) = lower(:nome) AND id IS DISTINCT FROM CAST(:id AS uuid)'
        );
        $consulta->execute(['nome' => $nome, 'id' => $excetoId]);

        return $consulta->fetchColumn() !== false;
    }

    /** @param array{nome: string, descricao: ?string, base_legal: string, base_legal_validada: bool, ativo: bool} $d */
    public function inserir(array $d): string
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO perfil_vinculo (nome, descricao, base_legal, base_legal_validada, ativo)
            VALUES (:nome, :descricao, :base_legal, :validada, :ativo)
            RETURNING id
            SQL);
        $consulta->execute($this->parametros($d));

        return (string) $consulta->fetchColumn();
    }

    /** @param array{nome: string, descricao: ?string, base_legal: string, base_legal_validada: bool, ativo: bool} $d */
    public function atualizar(string $id, array $d): void
    {
        $this->pdo->prepare(<<<'SQL'
            UPDATE perfil_vinculo
               SET nome = :nome, descricao = :descricao, base_legal = :base_legal,
                   base_legal_validada = :validada, ativo = :ativo
             WHERE id = :id
            SQL)->execute($this->parametros($d) + ['id' => $id]);
    }

    /**
     * @param array{nome: string, descricao: ?string, base_legal: string, base_legal_validada: bool, ativo: bool} $d
     * @return array<string, string|null>
     */
    private function parametros(array $d): array
    {
        return [
            'nome' => $d['nome'],
            'descricao' => $d['descricao'],
            'base_legal' => $d['base_legal'],
            'validada' => $d['base_legal_validada'] ? 'true' : 'false',
            'ativo' => $d['ativo'] ? 'true' : 'false',
        ];
    }
}
