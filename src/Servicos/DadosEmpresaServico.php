<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Banco\Transacao;
use CadGestor\Dominio\DadosCadastraisCliente;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use LogicException;
use PDO;

/**
 * "Dados da empresa" do lado do cliente: o administrador mantém endereço,
 * inscrições e contatos da própria organização (usados na nota fiscal e na
 * cobrança). Razão social e CNPJ mudam só com a plataforma (contrato).
 */
final class DadosEmpresaServico
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OrganizacaoRepositorio $organizacoes,
        private readonly ContextoBanco $contextoBanco,
        private readonly Auditoria $auditoria,
    ) {
    }

    /** @return array<string, mixed> */
    public function atual(): array
    {
        $id = $this->contextoBanco->organizacaoId() ?? throw new LogicException('Sem organização no contexto.');

        return $this->organizacoes->buscar($id) ?? throw new LogicException('Organização do contexto não encontrada.');
    }

    /** @param array<string, string> $entrada */
    public function atualizar(array $entrada): void
    {
        $atual = $this->atual();
        [$dados, $erros] = DadosCadastraisCliente::validar($entrada);

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        Transacao::executar($this->pdo, function () use ($atual, $dados): void {
            $this->organizacoes->atualizarContatoDaPropria($dados);
            [$antes, $depois] = Auditoria::diferenca(array_intersect_key($atual, $dados), $dados);

            if ($depois !== null && $depois !== []) {
                $this->auditoria->registrar('alterar', 'organizacao', (string) $atual['id'], $antes, $depois + [
                    'campos' => array_keys($depois),
                ]);
            }
        });
    }
}
