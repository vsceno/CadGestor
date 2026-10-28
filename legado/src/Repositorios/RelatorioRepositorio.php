<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use PDO;

/**
 * Consultas dos relatórios de acesso e da trilha de auditoria.
 *
 * O período é informado em datas locais (APP_FUSO) e convertido para o
 * intervalo em UTC no próprio SQL: [início 00:00 local, fim + 1 dia 00:00 local).
 */
final class RelatorioRepositorio
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $fuso,
    ) {
    }

    /**
     * @param array{inicio: string, fim: string, empresa: ?string, perfil: ?string, catraca: ?string,
     *     resultado: ?string} $f
     * @return list<array<string, mixed>>
     */
    public function resumoPorPerfil(array $f): array
    {
        return $this->resumo('pv.nome', 'LEFT JOIN perfil_vinculo pv ON pv.id = ev.perfil_id', $f);
    }

    /** @return list<array<string, mixed>> */
    public function resumoPorEmpresa(array $f): array
    {
        return $this->resumo('em.razao_social', 'LEFT JOIN empresa em ON em.id = ev.empresa_id', $f);
    }

    /** @return list<array<string, mixed>> */
    public function resumoPorMotivo(array $f): array
    {
        [$where, $params] = $this->filtro($f);

        $consulta = $this->pdo->prepare(<<<SQL
            SELECT ev.motivo_negacao AS motivo, count(*) AS total
              FROM evento_acesso ev
              {$where} AND ev.resultado = 'negado'
             GROUP BY ev.motivo_negacao
             ORDER BY total DESC
            SQL);
        $consulta->execute($params);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function resumoPorDia(array $f): array
    {
        [$where, $params] = $this->filtro($f);

        $consulta = $this->pdo->prepare(<<<SQL
            SELECT (ev.data_hora AT TIME ZONE :fuso_dia)::date AS dia,
                   count(*) FILTER (WHERE ev.resultado = 'liberado') AS liberados,
                   count(*) FILTER (WHERE ev.resultado = 'negado') AS negados
              FROM evento_acesso ev
              {$where}
             GROUP BY 1 ORDER BY 1
            SQL);
        $consulta->execute($params + ['fuso_dia' => $this->fuso]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Eventos detalhados (com nome da pessoa e CPF, que a view/CSV mascaram).
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function eventos(array $f, ?int $limite, int $deslocamento = 0): array
    {
        [$where, $params] = $this->filtro($f);

        $total = $this->pdo->prepare("SELECT count(*) FROM evento_acesso ev {$where}");
        $total->execute($params);

        $paginar = $limite === null ? '' : 'LIMIT :limite OFFSET :deslocamento';
        $consulta = $this->pdo->prepare(<<<SQL
            SELECT ev.id, ev.data_hora, ev.resultado, ev.motivo_negacao, ev.tipo_identificacao, ev.score, ev.offline,
                   p.nome AS pessoa_nome, p.cpf AS pessoa_cpf, pv.nome AS perfil_nome,
                   em.razao_social AS empresa_nome, c.nome AS catraca_nome
              FROM evento_acesso ev
              LEFT JOIN pessoa p ON p.id = ev.pessoa_id
              LEFT JOIN perfil_vinculo pv ON pv.id = ev.perfil_id
              LEFT JOIN empresa em ON em.id = ev.empresa_id
              LEFT JOIN catraca c ON c.id = ev.catraca_id
              {$where}
             ORDER BY ev.data_hora DESC
             {$paginar}
            SQL);
        $consulta->execute($params + ($limite === null ? [] : ['limite' => $limite, 'deslocamento' => $deslocamento]));

        return [$consulta->fetchAll(PDO::FETCH_ASSOC), (int) $total->fetchColumn()];
    }

    /**
     * @param array{inicio: string, fim: string, acao: string, tabela: string, usuario: ?string} $f
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function auditoria(array $f, ?int $limite, int $deslocamento = 0): array
    {
        $where = <<<'SQL'
            WHERE a.data_hora >= (CAST(:inicio AS date)::timestamp AT TIME ZONE :fuso)
              AND a.data_hora < ((CAST(:fim AS date) + 1)::timestamp AT TIME ZONE :fuso)
              AND (:acao = '' OR a.acao = :acao)
              AND (:tabela = '' OR a.tabela = :tabela)
              AND (CAST(:usuario AS uuid) IS NULL OR a.usuario_id = CAST(:usuario AS uuid))
            SQL;
        $params = [
            'inicio' => $f['inicio'],
            'fim' => $f['fim'],
            'fuso' => $this->fuso,
            'acao' => $f['acao'],
            'tabela' => $f['tabela'],
            'usuario' => $f['usuario'],
        ];

        $total = $this->pdo->prepare("SELECT count(*) FROM auditoria a {$where}");
        $total->execute($params);

        $paginar = $limite === null ? '' : 'LIMIT :limite OFFSET :deslocamento';
        $consulta = $this->pdo->prepare(<<<SQL
            SELECT a.id, a.data_hora, a.acao, a.tabela, a.registro_id, a.dados_antes::text AS dados_antes,
                   a.dados_depois::text AS dados_depois, host(a.ip) AS ip,
                   -- Usuário da plataforma (ex.: criou o administrador): o RLS esconde a conta,
                   -- mas o cliente sabe quem foi.
                   COALESCE(u.nome, CASE WHEN a.usuario_id IS NOT NULL THEN 'Equipe da plataforma' END)
                       AS usuario_nome
              FROM auditoria a LEFT JOIN usuario_sistema u ON u.id = a.usuario_id
              {$where}
             ORDER BY a.data_hora DESC
             {$paginar}
            SQL);
        $consulta->execute($params + ($limite === null ? [] : ['limite' => $limite, 'deslocamento' => $deslocamento]));

        return [$consulta->fetchAll(PDO::FETCH_ASSOC), (int) $total->fetchColumn()];
    }

    /** @return list<string> */
    public function tabelasAuditadas(): array
    {
        return array_map('strval', $this->pdo->query(
            'SELECT DISTINCT tabela FROM auditoria WHERE tabela IS NOT NULL ORDER BY tabela'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array{id: string, nome: string}> */
    public function opcoes(string $tabela): array
    {
        $sql = match ($tabela) {
            'empresa' => 'SELECT id, razao_social AS nome FROM empresa ORDER BY razao_social',
            'perfil' => 'SELECT id, nome FROM perfil_vinculo ORDER BY nome',
            'catraca' => 'SELECT c.id, c.nome || \' · \' || e.razao_social AS nome FROM catraca c '
                . 'JOIN empresa e ON e.id = c.empresa_id ORDER BY e.razao_social, c.nome',
            'usuario' => 'SELECT id, nome FROM usuario_sistema ORDER BY nome',
            default => throw new \InvalidArgumentException('Opção desconhecida.'),
        };

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function resumo(string $agrupador, string $juncao, array $f): array
    {
        [$where, $params] = $this->filtro($f);

        $consulta = $this->pdo->prepare(<<<SQL
            SELECT COALESCE({$agrupador}, '(não identificado)') AS grupo,
                   count(*) AS total,
                   count(*) FILTER (WHERE ev.resultado = 'liberado') AS liberados,
                   count(*) FILTER (WHERE ev.resultado = 'negado') AS negados,
                   count(DISTINCT ev.pessoa_id) AS pessoas
              FROM evento_acesso ev
              {$juncao}
              {$where}
             GROUP BY 1
             ORDER BY total DESC, grupo
            SQL);
        $consulta->execute($params);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{inicio: string, fim: string, empresa: ?string, perfil: ?string, catraca: ?string,
     *     resultado: ?string} $f
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filtro(array $f): array
    {
        $where = <<<'SQL'
            WHERE ev.data_hora >= (CAST(:inicio AS date)::timestamp AT TIME ZONE :fuso)
              AND ev.data_hora < ((CAST(:fim AS date) + 1)::timestamp AT TIME ZONE :fuso)
              AND (CAST(:empresa AS uuid) IS NULL OR ev.empresa_id = CAST(:empresa AS uuid))
              AND (CAST(:perfil AS uuid) IS NULL OR ev.perfil_id = CAST(:perfil AS uuid))
              AND (CAST(:catraca AS uuid) IS NULL OR ev.catraca_id = CAST(:catraca AS uuid))
              AND (CAST(:resultado AS text) IS NULL OR ev.resultado = :resultado)
            SQL;

        return [$where, [
            'inicio' => $f['inicio'],
            'fim' => $f['fim'],
            'fuso' => $this->fuso,
            'empresa' => $f['empresa'],
            'perfil' => $f['perfil'],
            'catraca' => $f['catraca'],
            'resultado' => $f['resultado'],
        ]];
    }
}
