<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Seguranca\HashSenha;
use DomainException;
use PDO;
use Throwable;

/**
 * Cria o primeiro administrador do sistema.
 *
 * A senha não é escolhida nem fica em arquivo: é gerada aleatoriamente,
 * exibida uma única vez no terminal e o usuário é obrigado a trocá-la no
 * primeiro login (deve_trocar_senha).
 */
final class CriacaoAdministradorInicial
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly HashSenha $hashSenha,
    ) {
    }

    /**
     * @return array{id: string, senha: string}
     * @throws DomainException quando os dados são inválidos ou já existe administrador ativo
     */
    public function criar(string $nome, string $email): array
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? '');
        $email = mb_strtolower(trim($email));

        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 150) {
            throw new DomainException('Informe um nome entre 3 e 150 caracteres.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new DomainException('Informe um e-mail válido.');
        }

        $senha = $this->hashSenha->gerarSenhaAleatoria();

        $this->pdo->beginTransaction();

        try {
            // Trava a tabela de papéis para que duas execuções simultâneas
            // não criem dois "primeiros" administradores.
            $this->pdo->exec('LOCK TABLE usuario_papel IN SHARE ROW EXCLUSIVE MODE');

            if ($this->existeAdministradorAtivo()) {
                throw new DomainException(
                    'Já existe um administrador ativo. Use a tela de usuários para criar outros.'
                );
            }

            $existe = $this->pdo->prepare('SELECT 1 FROM usuario_sistema WHERE lower(email) = :email');
            $existe->execute(['email' => $email]);

            if ($existe->fetchColumn() !== false) {
                throw new DomainException('Já existe um usuário com este e-mail.');
            }

            $insercao = $this->pdo->prepare(<<<'SQL'
                INSERT INTO usuario_sistema (nome, email, senha_hash, deve_trocar_senha)
                VALUES (:nome, :email, :senha_hash, true)
                RETURNING id
                SQL);
            $insercao->execute([
                'nome' => $nome,
                'email' => $email,
                'senha_hash' => $this->hashSenha->gerar($senha),
            ]);
            $id = (string) $insercao->fetchColumn();

            $this->pdo
                ->prepare("INSERT INTO usuario_papel (usuario_id, papel) VALUES (:id, 'administrador')")
                ->execute(['id' => $id]);

            // A trilha registra o fato, sem repetir nome/e-mail (minimização).
            $this->pdo->prepare(<<<'SQL'
                INSERT INTO auditoria (acao, tabela, registro_id, dados_depois)
                VALUES ('seed', 'usuario_sistema', :id, :dados)
                SQL)->execute([
                'id' => $id,
                'dados' => json_encode(['papel' => 'administrador', 'deve_trocar_senha' => true]),
            ]);

            $this->pdo->commit();
        } catch (Throwable $erro) {
            $this->pdo->rollBack();
            throw $erro;
        }

        return ['id' => $id, 'senha' => $senha];
    }

    public function existeAdministradorAtivo(): bool
    {
        return (bool) $this->pdo->query(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                  FROM usuario_papel p
                  JOIN usuario_sistema u ON u.id = p.usuario_id
                 WHERE p.papel = 'administrador' AND u.ativo
            )
            SQL)->fetchColumn();
    }
}
