<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use CadGestor\Dominio\IntervaloHorario;
use PDO;

/**
 * Acesso a regra_acesso (permissões de horário por perfil na empresa ou por
 * vínculo específico).
 */
final class RegraAcessoRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function contarDaEmpresa(string $empresaId): int
    {
        $consulta = $this->pdo->prepare(
            'SELECT count(*) FROM regra_acesso WHERE empresa_id = :id AND perfil_id IS NOT NULL AND ativo'
        );
        $consulta->execute(['id' => $empresaId]);

        return (int) $consulta->fetchColumn();
    }

    /**
     * Regras de perfil da empresa, agrupadas por perfil.
     *
     * @return array<string, list<array{dia_semana: int, hora_inicio: string, hora_fim: string, catraca_id: ?string}>>
     */
    public function porPerfilDaEmpresa(string $empresaId): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT perfil_id, dia_semana, hora_inicio::text AS hora_inicio, hora_fim::text AS hora_fim, catraca_id
              FROM regra_acesso
             WHERE empresa_id = :id AND perfil_id IS NOT NULL AND ativo
             ORDER BY dia_semana, hora_inicio
            SQL);
        $consulta->execute(['id' => $empresaId]);
        $agrupado = [];

        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $agrupado[(string) $linha['perfil_id']][] = $linha;
        }

        return $agrupado;
    }

    /** @return list<array{dia_semana: int, hora_inicio: string, hora_fim: string, catraca_id: ?string}> */
    public function doVinculo(string $vinculoId): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT dia_semana, hora_inicio::text AS hora_inicio, hora_fim::text AS hora_fim, catraca_id
              FROM regra_acesso WHERE vinculo_id = :id AND ativo ORDER BY dia_semana, hora_inicio
            SQL);
        $consulta->execute(['id' => $vinculoId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array{0: IntervaloHorario, 1: ?string}> $regras [intervalo, catraca_id] */
    public function definirDoPerfil(string $empresaId, string $perfilId, array $regras): void
    {
        $this->pdo->prepare('DELETE FROM regra_acesso WHERE empresa_id = :e AND perfil_id = :p')
            ->execute(['e' => $empresaId, 'p' => $perfilId]);
        $this->inserirRegras($empresaId, $perfilId, null, $regras);
    }

    /** @param list<array{0: IntervaloHorario, 1: ?string}> $regras [intervalo, catraca_id] */
    public function definirDoVinculo(string $empresaId, string $vinculoId, array $regras): void
    {
        $this->pdo->prepare('DELETE FROM regra_acesso WHERE vinculo_id = :v')->execute(['v' => $vinculoId]);
        $this->inserirRegras($empresaId, null, $vinculoId, $regras);
    }

    /** @param list<array{0: IntervaloHorario, 1: ?string}> $regras */
    private function inserirRegras(string $empresaId, ?string $perfilId, ?string $vinculoId, array $regras): void
    {
        $insercao = $this->pdo->prepare(<<<'SQL'
            INSERT INTO regra_acesso (empresa_id, perfil_id, vinculo_id, catraca_id, dia_semana, hora_inicio, hora_fim)
            VALUES (:e, :p, :v, :c, :dia, :inicio, :fim)
            SQL);

        foreach ($regras as [$intervalo, $catracaId]) {
            $insercao->execute([
                'e' => $empresaId,
                'p' => $perfilId,
                'v' => $vinculoId,
                'c' => $catracaId,
                'dia' => $intervalo->diaSemana,
                'inicio' => $intervalo->inicio,
                'fim' => $intervalo->fim,
            ]);
        }
    }
}
