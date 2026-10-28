<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use PDO;

/**
 * Leitura de evento_acesso e alerta (e tratamento de alertas).
 */
final class EventoRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function recentes(int $limite = 15, ?string $catracaId = null): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT ev.id, ev.data_hora, ev.resultado, ev.motivo_negacao, ev.tipo_identificacao, ev.score, ev.offline,
                   p.nome AS pessoa_nome, c.nome AS catraca_nome, pv.nome AS perfil_nome
              FROM evento_acesso ev
              LEFT JOIN pessoa p ON p.id = ev.pessoa_id
              LEFT JOIN catraca c ON c.id = ev.catraca_id
              LEFT JOIN perfil_vinculo pv ON pv.id = ev.perfil_id
             WHERE CAST(:c AS uuid) IS NULL OR ev.catraca_id = CAST(:c AS uuid)
             ORDER BY ev.data_hora DESC
             LIMIT :limite
            SQL);
        $consulta->execute(['c' => $catracaId, 'limite' => $limite]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function alertas(bool $somentePendentes, string $tipo, int $limite, int $deslocamento): array
    {
        $filtro = "WHERE (NOT CAST(:pendentes AS boolean) OR a.tratado_em IS NULL)"
            . " AND (:tipo = '' OR a.tipo = :tipo)";
        $params = ['pendentes' => $somentePendentes ? 'true' : 'false', 'tipo' => $tipo];

        $total = $this->pdo->prepare("SELECT count(*) FROM alerta a {$filtro}");
        $total->execute($params);

        $consulta = $this->pdo->prepare(<<<SQL
            SELECT a.*, c.nome AS catraca_nome, p.nome AS pessoa_nome,
                   COALESCE(u.nome, CASE WHEN a.tratado_por IS NOT NULL THEN 'Suporte da plataforma' END)
                       AS tratado_por_nome
              FROM alerta a
              LEFT JOIN catraca c ON c.id = a.catraca_id
              LEFT JOIN pessoa p ON p.id = a.pessoa_id
              LEFT JOIN usuario_sistema u ON u.id = a.tratado_por
              {$filtro}
             ORDER BY a.tratado_em IS NOT NULL, a.data_hora DESC
             LIMIT :limite OFFSET :deslocamento
            SQL);
        $consulta->execute($params + ['limite' => $limite, 'deslocamento' => $deslocamento]);

        return [$consulta->fetchAll(PDO::FETCH_ASSOC), (int) $total->fetchColumn()];
    }

    public function tratarAlerta(string $id, string $usuarioId, string $observacao): bool
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            UPDATE alerta SET tratado_em = now(), tratado_por = :u, observacao = :o
             WHERE id = :id AND tratado_em IS NULL
            SQL);
        $consulta->execute(['id' => $id, 'u' => $usuarioId, 'o' => $observacao === '' ? null : $observacao]);

        return $consulta->rowCount() === 1;
    }
}
