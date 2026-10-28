<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\PerfilRepositorio;
use PDO;

/**
 * Perfis de vínculo configuráveis (Funcionário, Aluno...). Perfis não são
 * excluídos: são desativados, preservando o histórico de vínculos.
 */
final class PerfilServico
{
    /**
     * Bases legais para dado sensível (art. 11 da LGPD) aceitas pelo sistema.
     * ATENÇÃO: a escolha deve ser validada pelo jurídico do cliente.
     */
    public const BASES_LEGAIS = [
        'consentimento' => 'Consentimento específico do titular (art. 11, I)',
        'obrigacao_legal' => 'Cumprimento de obrigação legal ou regulatória (art. 11, II, a)',
        'exercicio_regular_direitos' => 'Exercício regular de direitos em contrato (art. 11, II, d)',
        'prevencao_fraude_seguranca' => 'Prevenção à fraude e segurança do titular (art. 11, II, g)',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PerfilRepositorio $perfis,
        private readonly Auditoria $auditoria,
    ) {
    }

    /** @param array<string, string> $entrada */
    public function criar(array $entrada): string
    {
        $dados = $this->validar($entrada);

        return Transacao::executar($this->pdo, function () use ($dados): string {
            $id = $this->perfis->inserir($dados);
            $this->auditoria->registrar('criar', 'perfil_vinculo', $id, null, $dados);

            return $id;
        });
    }

    /** @param array<string, string> $entrada */
    public function atualizar(string $id, array $entrada): void
    {
        $atual = $this->perfis->buscar($id) ?? throw ErroValidacao::campo('id', 'Perfil não encontrado.');
        $dados = $this->validar($entrada, $id);

        Transacao::executar($this->pdo, function () use ($id, $atual, $dados): void {
            $this->perfis->atualizar($id, $dados);
            $this->auditoria->registrar('alterar', 'perfil_vinculo', $id, $atual, $dados);
        });
    }

    /**
     * @param array<string, string> $entrada
     * @return array{nome: string, descricao: ?string, base_legal: string, base_legal_validada: bool, ativo: bool}
     */
    private function validar(array $entrada, ?string $id = null): array
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $entrada['nome'] ?? '') ?? '');
        $descricao = trim($entrada['descricao'] ?? '');
        $baseLegal = $entrada['base_legal'] ?? '';
        $erros = [];

        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 60) {
            $erros['nome'] = 'Informe o nome do perfil (2 a 60 caracteres).';
        } elseif ($this->perfis->nomeEmUso($nome, $id)) {
            $erros['nome'] = 'Já existe um perfil com este nome.';
        }

        if (mb_strlen($descricao) > 255) {
            $erros['descricao'] = 'Máximo de 255 caracteres.';
        }

        if (!array_key_exists($baseLegal, self::BASES_LEGAIS)) {
            $erros['base_legal'] = 'Selecione a base legal.';
        }

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        return [
            'nome' => $nome,
            'descricao' => $descricao === '' ? null : $descricao,
            'base_legal' => $baseLegal,
            'base_legal_validada' => ($entrada['base_legal_validada'] ?? '') === '1',
            'ativo' => ($entrada['ativo'] ?? '') === '1',
        ];
    }
}
