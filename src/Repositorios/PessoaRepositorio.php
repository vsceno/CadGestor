<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use PDO;

/**
 * Acesso a pessoa.
 */
final class PessoaRepositorio
{
    public const CAMPOS = [
        'nome', 'cpf', 'documento_tipo', 'documento_numero', 'documento_orgao', 'documento_uf', 'data_nascimento',
        'email', 'telefone',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Pesquisa por nome (parcial, sem acento/caixa) ou CPF (exato). Pessoas
     * anonimizadas não aparecem.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function pesquisar(string $busca, int $limite, int $deslocamento): array
    {
        $cpf = preg_replace('/\D/', '', $busca) ?? '';
        $porCpf = strlen($cpf) === 11;

        $filtro = 'WHERE p.anonimizada_em IS NULL';
        $params = [];

        if ($porCpf) {
            $filtro .= ' AND p.cpf = :cpf';
            $params['cpf'] = $cpf;
        } elseif ($busca !== '') {
            $filtro .= ' AND p.nome ILIKE :nome';
            $params['nome'] = '%' . addcslashes($busca, '%_\\') . '%';
        }

        $total = $this->pdo->prepare("SELECT count(*) FROM pessoa p {$filtro}");
        $total->execute($params);

        $consulta = $this->pdo->prepare(<<<SQL
            SELECT p.id, p.nome, p.cpf, p.ativo, p.data_nascimento,
                   (SELECT count(*) FROM vinculo v WHERE v.pessoa_id = p.id AND v.status = 'ativo') AS vinculos_ativos,
                   EXISTS (SELECT 1 FROM consentimento c WHERE c.pessoa_id = p.id AND c.revogado_em IS NULL)
                       AS consentimento_ativo,
                   (SELECT count(*) FROM biometria b WHERE b.pessoa_id = p.id AND b.ativo) AS biometrias
              FROM pessoa p
              {$filtro}
             ORDER BY p.ativo DESC, p.nome
             LIMIT :limite OFFSET :deslocamento
            SQL);
        $consulta->execute($params + ['limite' => $limite, 'deslocamento' => $deslocamento]);

        return [$consulta->fetchAll(PDO::FETCH_ASSOC), (int) $total->fetchColumn()];
    }

    /** @return array<string, mixed>|null */
    public function buscar(string $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM pessoa WHERE id = :id');
        $consulta->execute(['id' => $id]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function cpfEmUso(string $cpf, ?string $excetoId = null): ?string
    {
        $consulta = $this->pdo->prepare(
            'SELECT id FROM pessoa WHERE cpf = :cpf AND id IS DISTINCT FROM CAST(:id AS uuid)'
        );
        $consulta->execute(['cpf' => $cpf, 'id' => $excetoId]);
        $id = $consulta->fetchColumn();

        return $id === false ? null : (string) $id;
    }

    /** @param array<string, string|null> $dados */
    public function inserir(array $dados): string
    {
        $colunas = implode(', ', self::CAMPOS);
        $valores = implode(', ', array_map(static fn (string $c): string => ':' . $c, self::CAMPOS));

        $consulta = $this->pdo->prepare("INSERT INTO pessoa ({$colunas}) VALUES ({$valores}) RETURNING id");
        $consulta->execute(array_intersect_key($dados, array_flip(self::CAMPOS)));

        return (string) $consulta->fetchColumn();
    }

    /** @param array<string, string|null> $dados */
    public function atualizar(string $id, array $dados): void
    {
        $atribuicoes = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", self::CAMPOS));

        $this->pdo->prepare("UPDATE pessoa SET {$atribuicoes} WHERE id = :id")
            ->execute(array_intersect_key($dados, array_flip(self::CAMPOS)) + ['id' => $id]);
    }

    public function definirAtivo(string $id, bool $ativo): void
    {
        $this->pdo->prepare('UPDATE pessoa SET ativo = :ativo WHERE id = :id')
            ->execute(['id' => $id, 'ativo' => $ativo ? 'true' : 'false']);
    }
}
