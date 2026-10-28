<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use CadGestor\Dominio\IntervaloHorario;
use PDO;

/**
 * Acesso a empresa, empresa_perfil e empresa_horario_padrao.
 */
final class EmpresaRepositorio
{
    public const CAMPOS = [
        'razao_social', 'nome_fantasia', 'cnpj', 'cep', 'logradouro', 'numero', 'complemento', 'bairro',
        'municipio', 'uf', 'responsavel_nome', 'responsavel_cargo', 'responsavel_email', 'responsavel_telefone',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Busca por razão social, nome fantasia ou CNPJ.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} [linhas, total]
     */
    public function listar(string $busca, ?bool $ativo, int $limite, int $deslocamento): array
    {
        $where = [];
        $params = [];

        if ($busca !== '') {
            $where[] = '(razao_social ILIKE :busca OR nome_fantasia ILIKE :busca OR cnpj LIKE :cnpj)';
            $params['busca'] = '%' . addcslashes($busca, '%_\\') . '%';
            $params['cnpj'] = '%' . (preg_replace('/[^0-9A-Z]/', '', mb_strtoupper($busca)) ?: '#') . '%';
        }

        if ($ativo !== null) {
            $where[] = 'ativo = :ativo';
            $params['ativo'] = $ativo ? 'true' : 'false';
        }

        $filtro = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $total = $this->pdo->prepare("SELECT count(*) FROM empresa {$filtro}");
        $total->execute($params);

        $consulta = $this->pdo->prepare(<<<SQL
            SELECT e.id, e.razao_social, e.nome_fantasia, e.cnpj, e.municipio, e.uf, e.ativo,
                   (SELECT count(*) FROM catraca c WHERE c.empresa_id = e.id AND c.ativo) AS catracas,
                   (SELECT count(*) FROM vinculo v WHERE v.empresa_id = e.id AND v.status = 'ativo') AS vinculos
              FROM empresa e
              {$filtro}
             ORDER BY e.ativo DESC, e.razao_social
             LIMIT :limite OFFSET :deslocamento
            SQL);
        $consulta->execute($params + ['limite' => $limite, 'deslocamento' => $deslocamento]);

        return [$consulta->fetchAll(PDO::FETCH_ASSOC), (int) $total->fetchColumn()];
    }

    /** @return list<array{id: string, razao_social: string}> empresas ativas, para selects */
    public function opcoes(): array
    {
        return $this->pdo->query('SELECT id, razao_social FROM empresa WHERE ativo ORDER BY razao_social')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function buscar(string $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM empresa WHERE id = :id');
        $consulta->execute(['id' => $id]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function cnpjEmUso(string $cnpj, ?string $excetoId = null): bool
    {
        $consulta = $this->pdo->prepare(
            'SELECT 1 FROM empresa WHERE cnpj = :cnpj AND id IS DISTINCT FROM CAST(:id AS uuid)'
        );
        $consulta->execute(['cnpj' => $cnpj, 'id' => $excetoId]);

        return $consulta->fetchColumn() !== false;
    }

    /** @param array<string, string|null> $dados */
    public function inserir(array $dados): string
    {
        $colunas = implode(', ', self::CAMPOS);
        $valores = implode(', ', array_map(static fn (string $c): string => ':' . $c, self::CAMPOS));

        $consulta = $this->pdo->prepare("INSERT INTO empresa ({$colunas}) VALUES ({$valores}) RETURNING id");
        $consulta->execute(array_intersect_key($dados, array_flip(self::CAMPOS)));

        return (string) $consulta->fetchColumn();
    }

    /** @param array<string, string|null> $dados */
    public function atualizar(string $id, array $dados): void
    {
        $atribuicoes = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", self::CAMPOS));

        $this->pdo->prepare("UPDATE empresa SET {$atribuicoes} WHERE id = :id")
            ->execute(array_intersect_key($dados, array_flip(self::CAMPOS)) + ['id' => $id]);
    }

    public function definirAtivo(string $id, bool $ativo): void
    {
        $this->pdo->prepare('UPDATE empresa SET ativo = :ativo WHERE id = :id')
            ->execute(['id' => $id, 'ativo' => $ativo ? 'true' : 'false']);
    }

    /** @return list<string> IDs dos perfis aceitos */
    public function perfisAceitos(string $empresaId): array
    {
        $consulta = $this->pdo->prepare('SELECT perfil_id FROM empresa_perfil WHERE empresa_id = :id');
        $consulta->execute(['id' => $empresaId]);

        return array_map('strval', $consulta->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array{id: string, nome: string}> perfis aceitos com nome, para selects */
    public function perfisAceitosComNome(string $empresaId): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT p.id, p.nome FROM empresa_perfil ep JOIN perfil_vinculo p ON p.id = ep.perfil_id
             WHERE ep.empresa_id = :id AND p.ativo ORDER BY p.nome
            SQL);
        $consulta->execute(['id' => $empresaId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<string> $perfisIds */
    public function definirPerfis(string $empresaId, array $perfisIds): void
    {
        $this->pdo->prepare('DELETE FROM empresa_perfil WHERE empresa_id = :id')->execute(['id' => $empresaId]);
        $insercao = $this->pdo->prepare('INSERT INTO empresa_perfil (empresa_id, perfil_id) VALUES (:e, :p)');

        foreach ($perfisIds as $perfilId) {
            $insercao->execute(['e' => $empresaId, 'p' => $perfilId]);
        }
    }

    /** @return list<array{dia_semana: int, hora_inicio: string, hora_fim: string}> */
    public function horarios(string $empresaId): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT dia_semana, hora_inicio::text AS hora_inicio, hora_fim::text AS hora_fim
              FROM empresa_horario_padrao WHERE empresa_id = :id ORDER BY dia_semana, hora_inicio
            SQL);
        $consulta->execute(['id' => $empresaId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<IntervaloHorario> $intervalos */
    public function definirHorarios(string $empresaId, array $intervalos): void
    {
        $this->pdo->prepare('DELETE FROM empresa_horario_padrao WHERE empresa_id = :id')->execute(['id' => $empresaId]);
        $insercao = $this->pdo->prepare(<<<'SQL'
            INSERT INTO empresa_horario_padrao (empresa_id, dia_semana, hora_inicio, hora_fim)
            VALUES (:e, :dia, :inicio, :fim)
            SQL);

        foreach ($intervalos as $intervalo) {
            $insercao->execute([
                'e' => $empresaId,
                'dia' => $intervalo->diaSemana,
                'inicio' => $intervalo->inicio,
                'fim' => $intervalo->fim,
            ]);
        }
    }
}
