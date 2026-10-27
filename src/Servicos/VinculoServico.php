<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\PessoaRepositorio;
use CadGestor\Repositorios\VinculoRepositorio;
use DateTimeImmutable;
use PDO;

/**
 * Vínculos pessoa x empresa x perfil, com validade e histórico preservado.
 */
final class VinculoServico
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly VinculoRepositorio $vinculos,
        private readonly PessoaRepositorio $pessoas,
        private readonly Auditoria $auditoria,
        private readonly ?SincronizacaoServico $sincronizacao = null,
    ) {
    }

    /** @param string $empresaPerfil "empresa_id|perfil_id" */
    public function criar(string $pessoaId, string $empresaPerfil, string $inicio, string $fim): string
    {
        $pessoa = $this->pessoas->buscar($pessoaId) ?? throw ErroValidacao::campo('pessoa', 'Pessoa não encontrada.');

        if ($pessoa['anonimizada_em'] !== null || $pessoa['ativo'] !== true) {
            throw ErroValidacao::campo('empresa_perfil', 'A pessoa está inativa: reative o cadastro antes.');
        }

        [$empresaId, $perfilId] = array_pad(explode('|', $empresaPerfil, 2), 2, '');
        $combinacoes = array_map(
            static fn (array $o): string => $o['empresa_id'] . '|' . $o['perfil_id'],
            $this->vinculos->opcoesEmpresaPerfil(),
        );
        $erros = [];

        if (!in_array("{$empresaId}|{$perfilId}", $combinacoes, true)) {
            $erros['empresa_perfil'] = 'Selecione uma empresa ativa e um perfil aceito por ela.';
        } elseif ($this->vinculos->existeAtivoIgual($pessoaId, $empresaId, $perfilId)) {
            $erros['empresa_perfil'] = 'A pessoa já tem um vínculo ativo com esta empresa neste perfil.';
        }

        [$inicio, $fim] = $this->validarPeriodo($inicio, $fim, $erros);

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        $criar = function () use ($pessoaId, $empresaId, $perfilId, $inicio, $fim): string {
            $id = $this->vinculos->inserir($pessoaId, $empresaId, $perfilId, $inicio, $fim);
            $this->auditoria->registrar('criar', 'vinculo', $id, null, [
                'pessoa_id' => $pessoaId,
                'empresa_id' => $empresaId,
                'perfil_id' => $perfilId,
                'data_inicio' => $inicio,
                'data_fim' => $fim,
            ]);
            $this->sincronizacao?->enviarBiometriasParaEmpresa($pessoaId, $empresaId);

            return $id;
        };

        return Transacao::executar($this->pdo, $criar);
    }

    public function alterarPeriodo(string $id, string $inicio, string $fim): void
    {
        $vinculo = $this->buscarNaoEncerrado($id);
        $erros = [];
        [$inicio, $fim] = $this->validarPeriodo($inicio, $fim, $erros);

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        Transacao::executar($this->pdo, function () use ($id, $vinculo, $inicio, $fim): void {
            $this->vinculos->definirPeriodo($id, $inicio, $fim);
            $this->auditoria->registrar(
                'alterar',
                'vinculo',
                $id,
                ['data_inicio' => $vinculo['data_inicio'], 'data_fim' => $vinculo['data_fim']],
                ['data_inicio' => $inicio, 'data_fim' => $fim],
            );
        });
    }

    public function suspender(string $id): void
    {
        $this->trocarStatus($id, 'ativo', 'suspenso');
    }

    public function reativar(string $id): void
    {
        $this->trocarStatus($id, 'suspenso', 'ativo');
    }

    /**
     * Encerra o vínculo (sem apagar). A data de fim passa a ser a informada
     * (padrão: hoje) e conta para o prazo de retenção.
     */
    public function encerrar(string $id, string $motivo, ?string $data = null): void
    {
        $vinculo = $this->buscarNaoEncerrado($id);
        $data = $data === null || $data === '' ? (new DateTimeImmutable('today'))->format('Y-m-d') : $data;
        $motivo = trim($motivo);

        if (DateTimeImmutable::createFromFormat('!Y-m-d', $data) === false || $data < $vinculo['data_inicio']) {
            throw ErroValidacao::campo('data_fim', 'A data de encerramento deve ser igual ou posterior ao início.');
        }

        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 255) {
            throw ErroValidacao::campo('motivo', 'Informe o motivo do encerramento (3 a 255 caracteres).');
        }

        Transacao::executar($this->pdo, function () use ($id, $vinculo, $data, $motivo): void {
            $this->vinculos->encerrar($id, $data, $motivo);
            $this->auditoria->registrar('alterar', 'vinculo', $id, ['status' => $vinculo['status']], [
                'status' => 'encerrado',
                'data_fim' => $data,
                'motivo' => $motivo,
            ]);
            $this->sincronizacao?->removerBiometriasSemVinculo((string) $vinculo['pessoa_id']);
        });
    }

    private function trocarStatus(string $id, string $de, string $para): void
    {
        $vinculo = $this->buscarNaoEncerrado($id);

        if ($vinculo['status'] !== $de) {
            throw ErroValidacao::campo('status', 'Operação não permitida na situação atual do vínculo.');
        }

        Transacao::executar($this->pdo, function () use ($id, $de, $para): void {
            $this->vinculos->definirStatus($id, $para);
            $this->auditoria->registrar('alterar', 'vinculo', $id, ['status' => $de], ['status' => $para]);
        });
    }

    /** @return array<string, mixed> */
    private function buscarNaoEncerrado(string $id): array
    {
        $vinculo = $this->vinculos->buscar($id) ?? throw ErroValidacao::campo('id', 'Vínculo não encontrado.');

        if ($vinculo['status'] === 'encerrado') {
            throw ErroValidacao::campo('status', 'Vínculo encerrado não pode ser alterado (histórico).');
        }

        return $vinculo;
    }

    /**
     * @param array<string, string> $erros
     * @return array{0: string, 1: ?string}
     */
    private function validarPeriodo(string $inicio, string $fim, array &$erros): array
    {
        $dataValida = static fn (string $d): bool => ($o = DateTimeImmutable::createFromFormat('!Y-m-d', $d)) !== false
            && $o->format('Y-m-d') === $d && $d > '2000-01-01' && $d < '2100-01-01';

        if (!$dataValida($inicio)) {
            $erros['data_inicio'] = 'Informe a data de início.';
        }

        $fim = trim($fim) === '' ? null : trim($fim);

        if ($fim !== null && !$dataValida($fim)) {
            $erros['data_fim'] = 'Data de validade inválida.';
        } elseif ($fim !== null && $dataValida($inicio) && $fim < $inicio) {
            $erros['data_fim'] = 'A validade deve ser igual ou posterior ao início.';
        }

        return [$inicio, $fim];
    }
}
