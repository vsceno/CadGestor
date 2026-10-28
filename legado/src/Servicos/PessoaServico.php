<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Dominio\Cpf;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\PessoaRepositorio;
use DateTimeImmutable;
use PDO;

/**
 * Cadastro de pessoas. Um CPF é uma única pessoa, que pode ter vínculos
 * com várias empresas.
 */
final class PessoaServico
{
    public const TIPOS_DOCUMENTO = [
        'rg' => 'RG',
        'cnh' => 'CNH',
        'passaporte' => 'Passaporte',
        'rnm' => 'RNM (estrangeiro)',
        'outro' => 'Outro',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PessoaRepositorio $pessoas,
        private readonly Auditoria $auditoria,
        private readonly LimitesPlano $limites,
    ) {
    }

    /** @param array<string, string> $entrada */
    public function criar(array $entrada): string
    {
        $this->limites->garantirCapacidade('pessoas');
        $dados = $this->validar($entrada);

        return Transacao::executar($this->pdo, function () use ($dados): string {
            $id = $this->pessoas->inserir($dados);
            // CPF e contatos não vão para a trilha (minimização): só o fato.
            $this->auditoria->registrar('criar', 'pessoa', $id, null, ['cadastro' => true]);

            return $id;
        });
    }

    /** @param array<string, string> $entrada */
    public function atualizar(string $id, array $entrada): void
    {
        $atual = $this->buscarEditavel($id);
        $dados = $this->validar($entrada, $id);

        Transacao::executar($this->pdo, function () use ($id, $atual, $dados): void {
            $this->pessoas->atualizar($id, $dados);
            $alterados = array_keys(array_filter(
                $dados,
                static fn (mixed $valor, string $campo): bool => ($atual[$campo] ?? null) !== $valor,
                ARRAY_FILTER_USE_BOTH,
            ));
            $this->auditoria->registrar('alterar', 'pessoa', $id, null, ['campos_alterados' => $alterados]);
        });
    }

    public function definirAtivo(string $id, bool $ativo): void
    {
        $atual = $this->buscarEditavel($id);

        if ($ativo && $atual['ativo'] !== true) {
            $this->limites->garantirCapacidade('pessoas');
        }

        Transacao::executar($this->pdo, function () use ($id, $ativo, $atual): void {
            $this->pessoas->definirAtivo($id, $ativo);
            $this->auditoria->registrar('alterar', 'pessoa', $id, ['ativo' => $atual['ativo']], ['ativo' => $ativo]);
        });
    }

    /** @return array<string, mixed> */
    private function buscarEditavel(string $id): array
    {
        $pessoa = $this->pessoas->buscar($id) ?? throw ErroValidacao::campo('id', 'Pessoa não encontrada.');

        if ($pessoa['anonimizada_em'] !== null) {
            throw ErroValidacao::campo('id', 'Registro anonimizado por política de retenção: não pode ser alterado.');
        }

        return $pessoa;
    }

    /**
     * @param array<string, string> $entrada
     * @return array<string, string|null>
     */
    private function validar(array $entrada, ?string $id = null): array
    {
        $texto = static fn (string $campo): string => trim(preg_replace('/\s+/u', ' ', $entrada[$campo] ?? '') ?? '');
        $opcional = static fn (string $campo): ?string => $texto($campo) === '' ? null : $texto($campo);

        $dados = [
            'nome' => $texto('nome'),
            'cpf' => Cpf::normalizar($entrada['cpf'] ?? ''),
            'documento_tipo' => $opcional('documento_tipo'),
            'documento_numero' => $opcional('documento_numero'),
            'documento_orgao' => $opcional('documento_orgao') === null
                ? null
                : mb_strtoupper($texto('documento_orgao')),
            'documento_uf' => $opcional('documento_uf') === null ? null : mb_strtoupper($texto('documento_uf')),
            'data_nascimento' => $opcional('data_nascimento'),
            'email' => $opcional('email') === null ? null : mb_strtolower($texto('email')),
            'telefone' => ($t = preg_replace('/\D/', '', $entrada['telefone'] ?? '') ?? '') === '' ? null : $t,
        ];
        $erros = [];

        if (mb_strlen($dados['nome']) < 3 || mb_strlen($dados['nome']) > 150 || !str_contains($dados['nome'], ' ')) {
            $erros['nome'] = 'Informe o nome completo.';
        }

        if (!Cpf::valido((string) $dados['cpf'])) {
            $erros['cpf'] = 'CPF inválido.';
        } elseif ($this->pessoas->cpfEmUso((string) $dados['cpf'], $id) !== null) {
            $erros['cpf'] = 'Já existe uma pessoa com este CPF. Use o cadastro existente para adicionar o vínculo.';
        }

        if ($dados['documento_tipo'] !== null && !array_key_exists($dados['documento_tipo'], self::TIPOS_DOCUMENTO)) {
            $erros['documento_tipo'] = 'Tipo de documento inválido.';
        }

        if (($dados['documento_tipo'] === null) !== ($dados['documento_numero'] === null)) {
            $erros['documento_numero'] = 'Informe o tipo e o número do documento (ou deixe ambos em branco).';
        } elseif ($dados['documento_numero'] !== null && mb_strlen($dados['documento_numero']) > 30) {
            $erros['documento_numero'] = 'Máximo de 30 caracteres.';
        }

        if ($dados['documento_uf'] !== null && !in_array($dados['documento_uf'], EmpresaServico::UFS, true)) {
            $erros['documento_uf'] = 'UF inválida.';
        }

        if ($dados['documento_orgao'] !== null && mb_strlen($dados['documento_orgao']) > 20) {
            $erros['documento_orgao'] = 'Máximo de 20 caracteres.';
        }

        if ($dados['data_nascimento'] !== null) {
            $data = DateTimeImmutable::createFromFormat('!Y-m-d', $dados['data_nascimento']);

            if (
                $data === false || $data->format('Y-m-d') !== $dados['data_nascimento']
                || $dados['data_nascimento'] <= '1900-01-01' || $data > new DateTimeImmutable('today')
            ) {
                $erros['data_nascimento'] = 'Data de nascimento inválida.';
            }
        }

        if ($dados['email'] !== null && filter_var($dados['email'], FILTER_VALIDATE_EMAIL) === false) {
            $erros['email'] = 'E-mail inválido.';
        }

        if ($dados['telefone'] !== null && preg_match('/^\d{10,11}$/', $dados['telefone']) !== 1) {
            $erros['telefone'] = 'Telefone com DDD (10 ou 11 dígitos).';
        }

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        return $dados;
    }
}
