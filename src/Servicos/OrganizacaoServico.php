<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Banco\Transacao;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Seguranca\Papel;
use PDO;

/**
 * Organizações (clientes do CadGestor), operadas pela plataforma.
 *
 * Organizações não são excluídas: são desativadas. Uma organização inativa
 * não consegue entrar no sistema e suas catracas são recusadas pela API.
 */
final class OrganizacaoServico
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OrganizacaoRepositorio $organizacoes,
        private readonly UsuarioServico $usuarios,
        private readonly ContextoBanco $contextoBanco,
        private readonly Auditoria $auditoria,
    ) {
    }

    /** Cria a organização já com os perfis padrão e o termo modelo. */
    public function criar(string $nome): string
    {
        $nome = $this->validar($nome);

        return Transacao::executar($this->pdo, function () use ($nome): string {
            $id = $this->organizacoes->inserir($nome);
            $this->organizacoes->provisionar($id);
            $this->auditoria->registrar('criar', 'organizacao', $id, null, ['nome' => $nome]);

            return $id;
        });
    }

    public function atualizar(string $id, string $nome): void
    {
        $atual = $this->existente($id);
        $nome = $this->validar($nome, $id);

        Transacao::executar($this->pdo, function () use ($id, $atual, $nome): void {
            $this->organizacoes->atualizar($id, $nome);
            $this->auditoria->registrar('alterar', 'organizacao', $id, ['nome' => $atual['nome']], ['nome' => $nome]);
        });
    }

    public function alterarSituacao(string $id, bool $ativo): void
    {
        $atual = $this->existente($id);

        Transacao::executar($this->pdo, function () use ($id, $atual, $ativo): void {
            $this->organizacoes->definirAtivo($id, $ativo);
            $this->auditoria->registrar('alterar', 'organizacao', $id, ['ativo' => $atual['ativo']], [
                'ativo' => $ativo,
            ]);
        });
    }

    /**
     * Cria um administrador DA organização. A operação roda no contexto dela,
     * então o cadastro aparece na auditoria do próprio cliente.
     *
     * @return array{id: string, senha: string}
     */
    public function criarAdministrador(string $organizacaoId, string $nome, string $email): array
    {
        $this->existente($organizacaoId);

        return $this->contextoBanco->dentroDe(
            $organizacaoId,
            fn (): array => $this->usuarios->criar($nome, $email, [Papel::Administrador->value]),
        );
    }

    /** @return array<string, mixed> */
    private function existente(string $id): array
    {
        return $this->organizacoes->buscar($id) ?? throw ErroValidacao::campo('id', 'Organização não encontrada.');
    }

    private function validar(string $nome, ?string $id = null): string
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? '');

        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 150) {
            throw ErroValidacao::campo('nome', 'Informe o nome da organização (3 a 150 caracteres).');
        }

        if ($this->organizacoes->nomeEmUso($nome, $id)) {
            throw ErroValidacao::campo('nome', 'Já existe uma organização com este nome.');
        }

        return $nome;
    }
}
