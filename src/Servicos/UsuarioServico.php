<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Seguranca\Papel;
use CadGestor\Seguranca\PoliticaSenha;
use PDO;

/**
 * Cadastro de usuários do sistema (operadores) e troca de senha.
 */
final class UsuarioServico
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UsuarioRepositorio $usuarios,
        private readonly HashSenha $hashSenha,
        private readonly Auditoria $auditoria,
    ) {
    }

    /**
     * Cria o usuário com senha provisória (exibida uma vez, troca obrigatória).
     *
     * @param list<string> $papeis
     * @return array{id: string, senha: string}
     */
    public function criar(string $nome, string $email, array $papeis): array
    {
        [$nome, $email, $listaPapeis] = $this->validar($nome, $email, $papeis);
        $senha = $this->hashSenha->gerarSenhaAleatoria();

        $id = Transacao::executar($this->pdo, function () use ($nome, $email, $listaPapeis, $senha): string {
            $id = $this->usuarios->inserir($nome, $email, $this->hashSenha->gerar($senha), true);
            $this->usuarios->definirPapeis($id, $listaPapeis);
            $this->auditoria->registrar('criar', 'usuario_sistema', $id, null, [
                'nome' => $nome,
                'papeis' => array_map(static fn (Papel $p): string => $p->value, $listaPapeis),
            ]);

            return $id;
        });

        return ['id' => $id, 'senha' => $senha];
    }

    /** @param list<string> $papeis */
    public function atualizar(
        string $id,
        string $nome,
        string $email,
        array $papeis,
        bool $ativo,
        string $editorId,
    ): void {
        $atual = $this->usuarioDaOrganizacao($id);
        [$nome, $email, $listaPapeis] = $this->validar($nome, $email, $papeis, $id);

        $continuaAdmin = $ativo && in_array(Papel::Administrador, $listaPapeis, true);
        $eraAdmin = $atual['ativo'] === true && in_array('administrador', $atual['papeis'], true);

        if ($eraAdmin && !$continuaAdmin) {
            if ($id === $editorId) {
                throw ErroValidacao::campo('papeis', 'Você não pode remover o próprio acesso de administrador.');
            }

            if ($this->usuarios->contarAdministradoresAtivos($id) === 0) {
                throw ErroValidacao::campo('papeis', 'O sistema precisa de pelo menos um administrador ativo.');
            }
        }

        Transacao::executar($this->pdo, function () use ($id, $nome, $email, $listaPapeis, $ativo, $atual): void {
            $this->usuarios->atualizar($id, $nome, $email, $ativo);
            $this->usuarios->definirPapeis($id, $listaPapeis);
            $this->auditoria->registrar('alterar', 'usuario_sistema', $id, [
                'nome' => $atual['nome'],
                'email' => $atual['email'],
                'ativo' => $atual['ativo'],
                'papeis' => $atual['papeis'],
            ], [
                'nome' => $nome,
                'email' => $email,
                'ativo' => $ativo,
                'papeis' => array_map(static fn (Papel $p): string => $p->value, $listaPapeis),
            ]);
        });
    }

    /** @return string nova senha provisória */
    public function redefinirSenha(string $id): string
    {
        $this->usuarioDaOrganizacao($id);
        $senha = $this->hashSenha->gerarSenhaAleatoria();
        $this->usuarios->definirSenha($id, $this->hashSenha->gerar($senha), true);
        $this->auditoria->registrar('senha_alterada', 'usuario_sistema', $id, null, ['provisoria' => true]);

        return $senha;
    }

    public function trocarPropriaSenha(
        string $id,
        #[\SensitiveParameter] string $senhaAtual,
        #[\SensitiveParameter] string $novaSenha,
        #[\SensitiveParameter] string $confirmacao,
    ): void {
        $usuario = $this->usuarios->buscarPorId($id) ?? throw ErroValidacao::campo('senha_atual', 'Usuário inválido.');

        if (!$this->hashSenha->verificar($senhaAtual, (string) $usuario['senha_hash'])) {
            throw ErroValidacao::campo('senha_atual', 'Senha atual incorreta.');
        }

        $erro = PoliticaSenha::validar($novaSenha, (string) $usuario['email']);

        if ($erro !== null) {
            throw ErroValidacao::campo('nova_senha', $erro);
        }

        if (!hash_equals($novaSenha, $confirmacao)) {
            throw ErroValidacao::campo('confirmacao', 'A confirmação não confere com a nova senha.');
        }

        if ($this->hashSenha->verificar($novaSenha, (string) $usuario['senha_hash'])) {
            throw ErroValidacao::campo('nova_senha', 'A nova senha deve ser diferente da atual.');
        }

        $this->usuarios->definirSenha($id, $this->hashSenha->gerar($novaSenha), false);
        $this->auditoria->registrar('senha_alterada', 'usuario_sistema', $id, null, ['provisoria' => false]);
    }

    /**
     * Usuário editável pela tela de usuários: da organização do contexto. A
     * conta de um usuário da plataforma (em modo suporte) não aparece aqui.
     *
     * @return array<string, mixed>
     */
    private function usuarioDaOrganizacao(string $id): array
    {
        $usuario = $this->usuarios->buscarPorId($id);

        if ($usuario === null || $usuario['organizacao_id'] === null) {
            throw ErroValidacao::campo('id', 'Usuário não encontrado.');
        }

        return $usuario;
    }

    /**
     * @param list<string> $papeis
     * @return array{0: string, 1: string, 2: list<Papel>}
     */
    private function validar(string $nome, string $email, array $papeis, ?string $id = null): array
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? '');
        $email = mb_strtolower(trim($email));
        $erros = [];

        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 150) {
            $erros['nome'] = 'Informe o nome completo (3 a 150 caracteres).';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $erros['email'] = 'Informe um e-mail válido.';
        } elseif ($this->usuarios->emailEmUso($email, $id)) {
            $erros['email'] = 'Já existe um usuário com este e-mail.';
        }

        // Só papéis de organização: o papel da plataforma nunca vem da tela de usuários.
        $listaPapeis = array_values(array_unique(array_filter(
            array_map(static fn (string $p): ?Papel => Papel::tryFrom($p), $papeis),
            static fn (?Papel $p): bool => $p !== null && $p !== Papel::Plataforma,
        ), SORT_REGULAR));

        if ($listaPapeis === []) {
            $erros['papeis'] = 'Selecione pelo menos um papel.';
        }

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        return [$nome, $email, $listaPapeis];
    }
}
