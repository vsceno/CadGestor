<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Dominio\Cpf;
use CadGestor\Dominio\Idade;
use CadGestor\Nucleo\Contexto;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\LgpdRepositorio;
use CadGestor\Repositorios\PessoaRepositorio;
use DateTimeImmutable;
use PDO;

/**
 * Termo e consentimento LGPD para a finalidade "controle de acesso biométrico".
 *
 * - O aceite guarda a versão e o hash do termo, data/hora e IP.
 * - Menores de 18 anos: exige dados do responsável legal.
 * - Idade não verificável (sem data de nascimento): coleta bloqueada.
 * - Revogação: apaga as biometrias e agenda a remoção em todos os dispositivos.
 */
final class ConsentimentoServico
{
    public const PARENTESCOS = [
        'mae' => 'Mãe',
        'pai' => 'Pai',
        'tutor' => 'Tutor(a) legal',
        'guardiao' => 'Guardião(ã) judicial',
        'outro' => 'Outro responsável legal',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly LgpdRepositorio $lgpd,
        private readonly PessoaRepositorio $pessoas,
        private readonly Auditoria $auditoria,
        private readonly SincronizacaoServico $sincronizacao,
        private readonly Contexto $contexto,
    ) {
    }

    public function publicarTermo(string $versao, string $texto): string
    {
        $versao = trim($versao);
        $texto = trim(str_replace("\r\n", "\n", $texto));
        $erros = [];

        if (preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $versao) !== 1) {
            $erros['versao'] = 'Versão com até 20 caracteres (ex.: 1.1).';
        } elseif ($this->lgpd->versaoTermoExiste($versao)) {
            $erros['versao'] = 'Esta versão já foi publicada. Termos publicados não mudam: use outro número.';
        }

        if (mb_strlen($texto) < 200) {
            $erros['texto'] = 'O termo deve ter pelo menos 200 caracteres.';
        }

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        return Transacao::executar($this->pdo, function () use ($versao, $texto): string {
            $id = $this->lgpd->inserirTermo($versao, $texto);
            $this->auditoria->registrar('criar', 'termo_consentimento', $id, null, [
                'versao' => $versao,
                'hash_texto' => hash('sha256', $texto),
            ]);

            return $id;
        });
    }

    /**
     * @param array{canal?: string, aceite_titular?: string, aceite_responsavel?: string,
     *     responsavel_nome?: string, responsavel_cpf?: string, responsavel_parentesco?: string} $entrada
     */
    public function registrar(string $pessoaId, array $entrada): string
    {
        $pessoa = $this->pessoas->buscar($pessoaId) ?? throw ErroValidacao::campo('pessoa', 'Pessoa não encontrada.');

        if ($pessoa['anonimizada_em'] !== null || $pessoa['ativo'] !== true) {
            throw ErroValidacao::campo('pessoa', 'Pessoa inativa: consentimento não pode ser registrado.');
        }

        $termo = $this->lgpd->termoVigente()
            ?? throw ErroValidacao::campo('termo', 'Não há termo de consentimento publicado. Peça ao administrador.');

        if ($this->lgpd->consentimentoAtivo($pessoaId) !== null) {
            throw ErroValidacao::campo('termo', 'A pessoa já possui consentimento ativo.');
        }

        $faixa = Idade::classificar($pessoa['data_nascimento'], new DateTimeImmutable('now'));

        if ($faixa === 'desconhecida') {
            throw ErroValidacao::campo(
                'data_nascimento',
                'Idade não verificável: informe a data de nascimento antes de coletar o consentimento.',
            );
        }

        $erros = [];
        $canal = $entrada['canal'] ?? 'presencial';

        if (!in_array($canal, ['presencial', 'digital'], true)) {
            $erros['canal'] = 'Canal inválido.';
        }

        $menor = $faixa === 'menor';
        $responsavel = ['nome' => null, 'cpf' => null, 'parentesco' => null];

        if ($menor) {
            $responsavel = [
                'nome' => trim($entrada['responsavel_nome'] ?? ''),
                'cpf' => Cpf::normalizar($entrada['responsavel_cpf'] ?? ''),
                'parentesco' => $entrada['responsavel_parentesco'] ?? '',
            ];

            if (mb_strlen($responsavel['nome']) < 5 || !str_contains($responsavel['nome'], ' ')) {
                $erros['responsavel_nome'] = 'Informe o nome completo do responsável legal.';
            }

            if (!Cpf::valido($responsavel['cpf'])) {
                $erros['responsavel_cpf'] = 'CPF do responsável inválido.';
            } elseif ($responsavel['cpf'] === $pessoa['cpf']) {
                $erros['responsavel_cpf'] = 'O responsável não pode ser o próprio titular.';
            }

            if (!array_key_exists($responsavel['parentesco'], self::PARENTESCOS)) {
                $erros['responsavel_parentesco'] = 'Selecione o vínculo do responsável.';
            }

            if (($entrada['aceite_responsavel'] ?? '') !== '1') {
                $erros['aceite_responsavel'] = 'O responsável legal precisa declarar o aceite do termo.';
            }
        } elseif (($entrada['aceite_titular'] ?? '') !== '1') {
            $erros['aceite_titular'] = 'O titular precisa declarar que leu e aceita o termo.';
        }

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        $gravar = function () use ($pessoaId, $termo, $canal, $menor, $responsavel): string {
            $id = $this->lgpd->inserirConsentimento([
                'pessoa' => $pessoaId,
                'termo' => $termo['id'],
                'versao' => $termo['versao'],
                'hash' => $termo['hash_texto'],
                'ip' => $this->contexto->ip,
                'canal' => $canal,
                'menor' => $menor ? 'true' : 'false',
                'resp_nome' => $responsavel['nome'],
                'resp_cpf' => $responsavel['cpf'],
                'resp_parentesco' => $responsavel['parentesco'],
                'registrado_por' => $this->contexto->usuarioId,
            ]);
            $this->auditoria->registrar('consentimento_aceito', 'consentimento', $id, null, [
                'pessoa_id' => $pessoaId,
                'versao_termo' => $termo['versao'],
                'titular_menor' => $menor,
                'canal' => $canal,
            ]);

            return $id;
        };

        return Transacao::executar($this->pdo, $gravar);
    }

    /**
     * Revoga o consentimento ativo: apaga as biometrias e agenda a remoção em
     * todas as catracas onde a pessoa possa estar cadastrada.
     *
     * @return int quantidade de biometrias excluídas
     */
    public function revogar(string $pessoaId): int
    {
        $consentimento = $this->lgpd->consentimentoAtivo($pessoaId)
            ?? throw ErroValidacao::campo('consentimento', 'Não há consentimento ativo para revogar.');

        return Transacao::executar($this->pdo, function () use ($pessoaId, $consentimento): int {
            // Biometrias primeiro: o trigger do banco não permite biometria ativa sem consentimento.
            $excluidas = $this->lgpd->excluirBiometrias($pessoaId, 'revogacao_consentimento');
            $this->lgpd->revogarConsentimento((string) $consentimento['id'], $this->contexto->ip);
            $operacoes = $this->sincronizacao->removerDeTodas($pessoaId);

            $consentimentoId = (string) $consentimento['id'];
            $this->auditoria->registrar('consentimento_revogado', 'consentimento', $consentimentoId, null, [
                'pessoa_id' => $pessoaId,
                'biometrias_excluidas' => count($excluidas),
                'remocoes_agendadas' => $operacoes,
            ]);

            foreach ($excluidas as $biometriaId) {
                $this->auditoria->registrar('biometria_excluida', 'biometria', $biometriaId, null, [
                    'motivo' => 'revogacao_consentimento',
                ]);
            }

            return count($excluidas);
        });
    }
}
