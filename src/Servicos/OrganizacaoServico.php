<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Banco\Transacao;
use CadGestor\Dominio\Cnpj;
use CadGestor\Dominio\DadosCadastraisCliente;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\Papel;
use LogicException;
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
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly UsuarioServico $usuarios,
        private readonly UsuarioRepositorio $usuarioRepositorio,
        private readonly RecuperacaoContaServico $recuperacao,
        private readonly ContextoBanco $contextoBanco,
        private readonly Auditoria $auditoria,
    ) {
    }

    /**
     * Venda manual (contrato fora do site): cria a organização com o cadastro
     * completo, os perfis padrão, o termo modelo e a assinatura ativa no plano
     * escolhido, sem gateway.
     *
     * @param array<string, string> $entrada
     */
    public function criar(array $entrada): string
    {
        $plano = $this->assinaturas->plano($entrada['plano_id'] ?? '');

        try {
            [$nome, $cnpj, $dados] = $this->validar($entrada);
        } catch (ErroValidacao $erro) {
            throw $plano === null ? new ErroValidacao($erro->erros + ['plano' => 'Escolha um plano.']) : $erro;
        }

        if ($plano === null) {
            throw ErroValidacao::campo('plano', 'Escolha um plano.');
        }

        return Transacao::executar($this->pdo, function () use ($nome, $cnpj, $dados, $plano): string {
            $id = $this->organizacoes->inserir($nome, $cnpj, $dados);
            $this->organizacoes->provisionar($id);
            $this->assinaturas->inserir((string) $plano['id'], 'ativa', null, $id);
            $this->auditoria->registrar('criar', 'organizacao', $id, null, [
                'nome' => $nome,
                'cnpj' => $cnpj,
                'plano' => $plano['codigo'],
            ]);

            return $id;
        });
    }

    /**
     * Edição pela plataforma: razão social, CNPJ e dados cadastrais.
     *
     * @param array<string, string> $entrada
     */
    public function atualizar(string $id, array $entrada): void
    {
        $atual = $this->existente($id);
        [$nome, $cnpj, $dados] = $this->validar($entrada, $id);

        Transacao::executar($this->pdo, function () use ($id, $atual, $nome, $cnpj, $dados): void {
            $this->organizacoes->atualizar($id, $nome, $cnpj, $dados);
            $novos = ['nome' => $nome, 'cnpj' => $cnpj] + $dados;
            [$antes, $depois] = Auditoria::diferenca(array_intersect_key($atual, $novos), $novos);

            if ($depois !== null && $depois !== []) {
                $this->auditoria->registrar('alterar', 'organizacao', $id, $antes, $depois + [
                    'campos' => array_keys($depois),
                ]);
            }
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
     * A plataforma não vê nem escolhe a senha: a senha aleatória gerada é
     * descartada e o novo administrador recebe, no e-mail dele, um link para
     * definir a própria senha.
     */
    public function criarAdministrador(string $organizacaoId, string $nome, string $email): string
    {
        $this->existente($organizacaoId);

        return $this->contextoBanco->dentroDe($organizacaoId, function () use ($nome, $email): string {
            return Transacao::executar($this->pdo, function () use ($nome, $email): string {
                $id = $this->usuarios->criar($nome, $email, [Papel::Administrador->value])['id'];
                $usuario = $this->usuarioRepositorio->buscarPorId($id) ?? throw new LogicException('Usuário sumiu.');
                $this->recuperacao->enviarLinkRedefinicao($usuario, 'novo_administrador', true);

                return $id;
            });
        });
    }

    /** @return array<string, mixed> */
    private function existente(string $id): array
    {
        return $this->organizacoes->buscar($id) ?? throw ErroValidacao::campo('id', 'Organização não encontrada.');
    }

    /**
     * @param array<string, string> $entrada
     * @return array{0: string, 1: string, 2: array<string, string|null>}
     */
    private function validar(array $entrada, ?string $id = null): array
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $entrada['nome'] ?? '') ?? '');
        $cnpj = Cnpj::normalizar($entrada['cnpj'] ?? '');
        [$dados, $erros] = DadosCadastraisCliente::validar($entrada);

        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 150) {
            $erros['nome'] = 'Informe a razão social (3 a 150 caracteres).';
        } elseif ($this->organizacoes->nomeEmUso($nome, $id)) {
            $erros['nome'] = 'Já existe um cliente com esta razão social.';
        }

        if (!Cnpj::valido($cnpj)) {
            $erros['cnpj'] = 'CNPJ inválido. Confira os dígitos.';
        } elseif ($this->organizacoes->cnpjEmUso($cnpj, $id)) {
            $erros['cnpj'] = 'Já existe um cliente com este CNPJ.';
        }

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        return [$nome, $cnpj, $dados];
    }
}
