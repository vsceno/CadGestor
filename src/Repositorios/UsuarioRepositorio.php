<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use CadGestor\Seguranca\Papel;
use PDO;

/**
 * Acesso a usuario_sistema e usuario_papel.
 */
final class UsuarioRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function buscarPorEmail(string $email): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM usuario_sistema WHERE lower(email) = lower(:email)');
        $consulta->execute(['email' => $email]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null */
    public function buscarPorId(string $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM usuario_sistema WHERE id = :id');
        $consulta->execute(['id' => $id]);
        $usuario = $consulta->fetch(PDO::FETCH_ASSOC);

        if ($usuario === false) {
            return null;
        }

        $usuario['papeis'] = $this->papeis($id);

        return $usuario;
    }

    /** @return list<string> */
    public function papeis(string $usuarioId): array
    {
        $consulta = $this->pdo->prepare('SELECT papel FROM usuario_papel WHERE usuario_id = :id ORDER BY papel');
        $consulta->execute(['id' => $usuarioId]);

        return array_map('strval', $consulta->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT u.id, u.nome, u.email, u.ativo, u.deve_trocar_senha, u.ultimo_login_em,
                   COALESCE(array_to_string(array_agg(p.papel ORDER BY p.papel), ','), '') AS papeis
              FROM usuario_sistema u
              LEFT JOIN usuario_papel p ON p.usuario_id = u.id
             GROUP BY u.id
             ORDER BY u.ativo DESC, u.nome
            SQL)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function emailEmUso(string $email, ?string $excetoId = null): bool
    {
        $consulta = $this->pdo->prepare(
            'SELECT 1 FROM usuario_sistema WHERE lower(email) = lower(:email) AND id IS DISTINCT FROM CAST(:id AS uuid)'
        );
        $consulta->execute(['email' => $email, 'id' => $excetoId]);

        return $consulta->fetchColumn() !== false;
    }

    public function contarAdministradoresAtivos(?string $excetoId = null): int
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT count(*) FROM usuario_papel p JOIN usuario_sistema u ON u.id = p.usuario_id
             WHERE p.papel = 'administrador' AND u.ativo AND u.id IS DISTINCT FROM CAST(:id AS uuid)
            SQL);
        $consulta->execute(['id' => $excetoId]);

        return (int) $consulta->fetchColumn();
    }

    public function inserir(string $nome, string $email, string $senhaHash, bool $deveTrocarSenha): string
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO usuario_sistema (nome, email, senha_hash, deve_trocar_senha)
            VALUES (:nome, :email, :hash, :trocar)
            RETURNING id
            SQL);
        $consulta->execute([
            'nome' => $nome,
            'email' => $email,
            'hash' => $senhaHash,
            'trocar' => $deveTrocarSenha ? 'true' : 'false',
        ]);

        return (string) $consulta->fetchColumn();
    }

    public function atualizar(string $id, string $nome, string $email, bool $ativo): void
    {
        $this->pdo->prepare(
            'UPDATE usuario_sistema SET nome = :nome, email = :email, ativo = :ativo WHERE id = :id'
        )->execute(['id' => $id, 'nome' => $nome, 'email' => $email, 'ativo' => $ativo ? 'true' : 'false']);
    }

    /** @param list<Papel> $papeis */
    public function definirPapeis(string $id, array $papeis): void
    {
        $this->pdo->prepare('DELETE FROM usuario_papel WHERE usuario_id = :id')->execute(['id' => $id]);
        $insercao = $this->pdo->prepare('INSERT INTO usuario_papel (usuario_id, papel) VALUES (:id, :papel)');

        foreach ($papeis as $papel) {
            $insercao->execute(['id' => $id, 'papel' => $papel->value]);
        }
    }

    public function definirSenha(string $id, string $hash, bool $deveTrocar): void
    {
        $this->pdo->prepare(
            'UPDATE usuario_sistema SET senha_hash = :hash, deve_trocar_senha = :trocar WHERE id = :id'
        )->execute(['id' => $id, 'hash' => $hash, 'trocar' => $deveTrocar ? 'true' : 'false']);
    }

    public function registrarLogin(string $id): void
    {
        $this->pdo->prepare('UPDATE usuario_sistema SET ultimo_login_em = now() WHERE id = :id')
            ->execute(['id' => $id]);
    }
}
