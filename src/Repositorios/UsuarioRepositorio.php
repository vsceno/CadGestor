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

    /**
     * Login: localiza a conta pelo e-mail em qualquer organização (antes do
     * login ainda não se sabe qual é a organização, e o RLS esconderia a linha).
     *
     * @return array{usuario_id: string, organizacao_id: ?string}|null
     */
    public function localizarParaLogin(string $email): ?array
    {
        $consulta = $this->pdo->prepare('SELECT usuario_id, organizacao_id FROM cg_login_usuario(:email)');
        $consulta->execute(['email' => $email]);
        $conta = $consulta->fetch(PDO::FETCH_ASSOC);

        return $conta === false ? null : [
            'usuario_id' => (string) $conta['usuario_id'],
            'organizacao_id' => $conta['organizacao_id'] === null ? null : (string) $conta['organizacao_id'],
        ];
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

    /**
     * Usuários de uma organização: a informada (modo plataforma) ou a do
     * contexto atual. Usuários da plataforma nunca aparecem nesta lista.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(?string $organizacaoId = null): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT u.id, u.nome, u.email, u.ativo, u.deve_trocar_senha, u.ultimo_login_em,
                   COALESCE(array_to_string(array_agg(p.papel ORDER BY p.papel), ','), '') AS papeis
              FROM usuario_sistema u
              LEFT JOIN usuario_papel p ON p.usuario_id = u.id
             WHERE u.organizacao_id = COALESCE(CAST(:organizacao AS uuid), cg_org_atual())
             GROUP BY u.id
             ORDER BY u.ativo DESC, u.nome
            SQL);
        $consulta->execute(['organizacao' => $organizacaoId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** O e-mail é único no sistema todo (o login é só por e-mail), não só na organização. */
    public function emailEmUso(string $email, ?string $excetoId = null): bool
    {
        $consulta = $this->pdo->prepare('SELECT cg_email_em_uso(:email, CAST(:id AS uuid))');
        $consulta->execute(['email' => $email, 'id' => $excetoId]);

        return (bool) $consulta->fetchColumn();
    }

    public function contarAdministradoresAtivos(?string $excetoId = null): int
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT count(*) FROM usuario_papel p JOIN usuario_sistema u ON u.id = p.usuario_id
             WHERE p.papel = 'administrador' AND u.ativo AND u.id IS DISTINCT FROM CAST(:id AS uuid)
               AND u.organizacao_id = cg_org_atual()
            SQL);
        $consulta->execute(['id' => $excetoId]);

        return (int) $consulta->fetchColumn();
    }

    /** O usuário pertence à organização do contexto atual (DEFAULT cg_org_atual()). */
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

    /**
     * Usuário do cadastro self-service: só entra depois de confirmar o e-mail.
     * Do token, o banco guarda só o hash.
     */
    public function inserirAguardandoConfirmacao(
        string $nome,
        string $email,
        string $senhaHash,
        string $tokenHash,
        string $expiraEm,
    ): string {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO usuario_sistema (nome, email, senha_hash, deve_trocar_senha, email_verificado_em,
                                         verificacao_token_hash, verificacao_expira_em)
            VALUES (:nome, :email, :hash, false, NULL, :token, CAST(:expira AS timestamptz))
            RETURNING id
            SQL);
        $consulta->execute([
            'nome' => $nome,
            'email' => $email,
            'hash' => $senhaHash,
            'token' => $tokenHash,
            'expira' => $expiraEm,
        ]);

        return (string) $consulta->fetchColumn();
    }

    /**
     * Confirma o e-mail pelo token do link (chega sem sessão nem organização).
     *
     * @return array{usuario_id: string, organizacao_id: string}|null
     */
    public function confirmarEmail(string $tokenHash): ?array
    {
        $consulta = $this->pdo->prepare('SELECT usuario_id, organizacao_id FROM cg_confirmar_email(:token)');
        $consulta->execute(['token' => $tokenHash]);
        $linha = $consulta->fetch(PDO::FETCH_ASSOC);

        return $linha === false ? null : [
            'usuario_id' => (string) $linha['usuario_id'],
            'organizacao_id' => (string) $linha['organizacao_id'],
        ];
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
