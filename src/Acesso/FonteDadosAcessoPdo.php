<?php

declare(strict_types=1);

namespace CadGestor\Acesso;

use PDO;

/**
 * FonteDadosAcesso lendo do PostgreSQL.
 */
final class FonteDadosAcessoPdo implements FonteDadosAcesso
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function catraca(string $catracaId): ?array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT c.id, c.empresa_id, c.ativo, e.ativo AS empresa_ativa
              FROM catraca c JOIN empresa e ON e.id = c.empresa_id WHERE c.id = :id
            SQL);
        $consulta->execute(['id' => $catracaId]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function pessoa(string $pessoaId): ?array
    {
        $consulta = $this->pdo->prepare(
            'SELECT id, ativo, anonimizada_em IS NOT NULL AS anonimizada FROM pessoa WHERE id = :id'
        );
        $consulta->execute(['id' => $pessoaId]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function vinculos(string $pessoaId, string $empresaId): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT id, perfil_id, status, data_inicio::text AS data_inicio, data_fim::text AS data_fim
              FROM vinculo
             WHERE pessoa_id = :p AND empresa_id = :e AND status <> 'encerrado'
             ORDER BY data_inicio
            SQL);
        $consulta->execute(['p' => $pessoaId, 'e' => $empresaId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    public function perfilPermitido(string $empresaId, string $perfilId): bool
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT 1 FROM empresa_perfil ep JOIN perfil_vinculo p ON p.id = ep.perfil_id
             WHERE ep.empresa_id = :e AND ep.perfil_id = :p AND p.ativo
            SQL);
        $consulta->execute(['e' => $empresaId, 'p' => $perfilId]);

        return $consulta->fetchColumn() !== false;
    }

    public function regrasDoVinculo(string $vinculoId): array
    {
        return $this->regras('vinculo_id = :v', ['v' => $vinculoId]);
    }

    public function regrasDoPerfil(string $empresaId, string $perfilId): array
    {
        return $this->regras('empresa_id = :e AND perfil_id = :p', ['e' => $empresaId, 'p' => $perfilId]);
    }

    public function horarioPadrao(string $empresaId): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT dia_semana, hora_inicio::text AS hora_inicio, hora_fim::text AS hora_fim
              FROM empresa_horario_padrao WHERE empresa_id = :e
            SQL);
        $consulta->execute(['e' => $empresaId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    public function biometriaAtiva(string $pessoaId, string $tipo): bool
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT 1 FROM biometria b
              JOIN consentimento c ON c.id = b.consentimento_id AND c.revogado_em IS NULL
             WHERE b.pessoa_id = :p AND b.tipo = :t AND b.ativo
            SQL);
        $consulta->execute(['p' => $pessoaId, 't' => $tipo]);

        return $consulta->fetchColumn() !== false;
    }

    /**
     * @param array<string, string> $parametros
     * @return list<array{dia_semana: int, hora_inicio: string, hora_fim: string, catraca_id: ?string}>
     */
    private function regras(string $filtro, array $parametros): array
    {
        $consulta = $this->pdo->prepare(<<<SQL
            SELECT dia_semana, hora_inicio::text AS hora_inicio, hora_fim::text AS hora_fim, catraca_id
              FROM regra_acesso WHERE {$filtro} AND ativo
            SQL);
        $consulta->execute($parametros);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }
}
