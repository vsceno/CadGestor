<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use PDO;

/**
 * Acesso a organizacao (os clientes do CadGestor).
 *
 * Pelo RLS, a plataforma enxerga todas; um usuário de organização, só a dele.
 */
final class OrganizacaoRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Organizações com contagens (sem dados pessoais). Só no modo plataforma.
     *
     * @return list<array<string, mixed>>
     */
    public function listarComResumo(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT o.id, o.nome, o.ativo, o.created_at,
                   COALESCE(r.usuarios, 0) AS usuarios, COALESCE(r.empresas, 0) AS empresas,
                   COALESCE(r.pessoas, 0) AS pessoas, COALESCE(r.catracas, 0) AS catracas
              FROM organizacao o
              LEFT JOIN cg_resumo_organizacoes() r ON r.organizacao_id = o.id
             ORDER BY o.ativo DESC, o.nome
            SQL)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function buscar(string $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT id, nome, ativo, created_at FROM organizacao WHERE id = :id');
        $consulta->execute(['id' => $id]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function nomeEmUso(string $nome, ?string $excetoId = null): bool
    {
        $consulta = $this->pdo->prepare(
            'SELECT 1 FROM organizacao WHERE lower(nome) = lower(:nome) AND id IS DISTINCT FROM CAST(:id AS uuid)'
        );
        $consulta->execute(['nome' => $nome, 'id' => $excetoId]);

        return $consulta->fetchColumn() !== false;
    }

    public function inserir(string $nome): string
    {
        $consulta = $this->pdo->prepare('INSERT INTO organizacao (nome) VALUES (:nome) RETURNING id');
        $consulta->execute(['nome' => $nome]);

        return (string) $consulta->fetchColumn();
    }

    /** Cliente vindo do cadastro self-service, com o aceite dos Termos de Uso. */
    public function inserirCliente(string $nome, string $cnpj, string $versaoTermos, string $ip): string
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO organizacao (nome, cnpj, termos_versao, termos_aceitos_em, termos_aceitos_ip)
            VALUES (:nome, :cnpj, :versao, now(), CAST(:ip AS inet))
            RETURNING id
            SQL);
        $consulta->execute(['nome' => $nome, 'cnpj' => $cnpj, 'versao' => $versaoTermos, 'ip' => $ip]);

        return (string) $consulta->fetchColumn();
    }

    /** CNPJ já usado por algum cliente (consulta o sistema todo, não só a organização atual). */
    public function cnpjEmUso(string $cnpj): bool
    {
        $consulta = $this->pdo->prepare('SELECT cg_cnpj_cliente_em_uso(:cnpj)');
        $consulta->execute(['cnpj' => $cnpj]);

        return (bool) $consulta->fetchColumn();
    }

    /** Perfis padrão e termo modelo da organização nova (idempotente). */
    public function provisionar(string $id): void
    {
        $this->pdo->prepare('SELECT cg_provisionar_organizacao(:id)')->execute(['id' => $id]);
    }

    public function atualizar(string $id, string $nome): void
    {
        $this->pdo->prepare('UPDATE organizacao SET nome = :nome WHERE id = :id')
            ->execute(['id' => $id, 'nome' => $nome]);
    }

    public function definirAtivo(string $id, bool $ativo): void
    {
        $this->pdo->prepare('UPDATE organizacao SET ativo = :ativo WHERE id = :id')
            ->execute(['id' => $id, 'ativo' => $ativo ? 'true' : 'false']);
    }
}
