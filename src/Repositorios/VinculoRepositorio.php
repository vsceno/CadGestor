<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use PDO;

/**
 * Acesso a vinculo. Vínculos não são apagados: o histórico é preservado.
 */
final class VinculoRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> todos os vínculos da pessoa (histórico completo) */
    public function daPessoa(string $pessoaId): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT v.*, e.razao_social, e.ativo AS empresa_ativa, p.nome AS perfil_nome,
                   (SELECT count(*) FROM regra_acesso r WHERE r.vinculo_id = v.id AND r.ativo) AS regras_proprias
              FROM vinculo v
              JOIN empresa e ON e.id = v.empresa_id
              JOIN perfil_vinculo p ON p.id = v.perfil_id
             WHERE v.pessoa_id = :id
             ORDER BY (v.status = 'encerrado'), v.data_inicio DESC
            SQL);
        $consulta->execute(['id' => $pessoaId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function buscar(string $id): ?array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT v.*, e.razao_social, p.nome AS perfil_nome, pe.nome AS pessoa_nome
              FROM vinculo v
              JOIN empresa e ON e.id = v.empresa_id
              JOIN perfil_vinculo p ON p.id = v.perfil_id
              JOIN pessoa pe ON pe.id = v.pessoa_id
             WHERE v.id = :id
            SQL);
        $consulta->execute(['id' => $id]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function existeAtivoIgual(string $pessoaId, string $empresaId, string $perfilId): bool
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT 1 FROM vinculo
             WHERE pessoa_id = :pessoa AND empresa_id = :empresa AND perfil_id = :perfil AND status <> 'encerrado'
            SQL);
        $consulta->execute(['pessoa' => $pessoaId, 'empresa' => $empresaId, 'perfil' => $perfilId]);

        return $consulta->fetchColumn() !== false;
    }

    public function inserir(string $pessoaId, string $empresaId, string $perfilId, string $inicio, ?string $fim): string
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO vinculo (pessoa_id, empresa_id, perfil_id, data_inicio, data_fim)
            VALUES (:pessoa, :empresa, :perfil, :inicio, :fim)
            RETURNING id
            SQL);
        $consulta->execute([
            'pessoa' => $pessoaId,
            'empresa' => $empresaId,
            'perfil' => $perfilId,
            'inicio' => $inicio,
            'fim' => $fim,
        ]);

        return (string) $consulta->fetchColumn();
    }

    public function definirPeriodo(string $id, string $inicio, ?string $fim): void
    {
        $this->pdo->prepare('UPDATE vinculo SET data_inicio = :inicio, data_fim = :fim WHERE id = :id')
            ->execute(['id' => $id, 'inicio' => $inicio, 'fim' => $fim]);
    }

    public function definirStatus(string $id, string $status): void
    {
        $this->pdo->prepare('UPDATE vinculo SET status = :status WHERE id = :id')
            ->execute(['id' => $id, 'status' => $status]);
    }

    public function encerrar(string $id, string $dataFim, string $motivo): void
    {
        $this->pdo->prepare(<<<'SQL'
            UPDATE vinculo
               SET status = 'encerrado', encerrado_em = now(), data_fim = :fim, motivo_encerramento = :motivo
             WHERE id = :id
            SQL)->execute(['id' => $id, 'fim' => $dataFim, 'motivo' => $motivo]);
    }

    /** @return list<string> empresas com vínculo não encerrado da pessoa */
    public function empresasDaPessoa(string $pessoaId): array
    {
        $consulta = $this->pdo->prepare(
            "SELECT DISTINCT empresa_id FROM vinculo WHERE pessoa_id = :id AND status <> 'encerrado'"
        );
        $consulta->execute(['id' => $pessoaId]);

        return array_map('strval', $consulta->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Combinações empresa ativa x perfil aceito e ativo, para o formulário de vínculo.
     *
     * @return list<array{empresa_id: string, razao_social: string, perfil_id: string, perfil_nome: string}>
     */
    public function opcoesEmpresaPerfil(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT e.id AS empresa_id, e.razao_social, p.id AS perfil_id, p.nome AS perfil_nome
              FROM empresa_perfil ep
              JOIN empresa e ON e.id = ep.empresa_id AND e.ativo
              JOIN perfil_vinculo p ON p.id = ep.perfil_id AND p.ativo
             ORDER BY e.razao_social, p.nome
            SQL)->fetchAll(PDO::FETCH_ASSOC);
    }
}
