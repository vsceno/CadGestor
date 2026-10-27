<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Dominio\PoliticaRetencao;
use CadGestor\Repositorios\LgpdRepositorio;
use DateTimeImmutable;
use PDO;

/**
 * Jobs de manutenção (rodar diariamente com o papel de manutenção/owner):
 *
 *  1. vinculos  - encerra vínculos vencidos e agenda a remoção nas catracas;
 *  2. retencao  - aplica a política de retenção: apaga biometrias e
 *                 anonimiza titulares sem vínculo há mais de N dias;
 *  3. catracas  - alerta catracas sem contato com o servidor;
 *  4. limpeza   - remove dados técnicos antigos (tentativas de login e fila concluída).
 *
 * Com $simular = true nada é alterado: só conta o que seria feito.
 */
final class ManutencaoServico
{
    public const TAREFAS = ['vinculos', 'retencao', 'catracas', 'limpeza'];
    private const DIAS_DADOS_TECNICOS = 90;
    private const MINUTOS_SEM_CONTATO = 30;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PoliticaRetencao $retencao,
        private readonly LgpdRepositorio $lgpd,
        private readonly SincronizacaoServico $sincronizacao,
        private readonly Auditoria $auditoria,
    ) {
    }

    /**
     * @param list<string> $tarefas
     * @return array<string, int> contagem por tarefa
     */
    public function executar(array $tarefas, DateTimeImmutable $hoje, bool $simular = false): array
    {
        $resultado = [];

        foreach (array_intersect(self::TAREFAS, $tarefas) as $tarefa) {
            $resultado += match ($tarefa) {
                'vinculos' => ['vinculos_encerrados' => $this->encerrarVinculosVencidos($hoje, $simular)],
                'retencao' => $this->aplicarRetencao($hoje, $simular),
                'catracas' => ['catracas_sem_contato' => $this->alertarCatracasSemContato($simular)],
                'limpeza' => $this->limparDadosTecnicos($simular),
            };
        }

        if (!$simular) {
            $this->auditoria->registrar('job_manutencao', null, null, null, $resultado + [
                'retencao_dias' => $this->retencao->dias(),
            ]);
        }

        return $resultado;
    }

    public function encerrarVinculosVencidos(DateTimeImmutable $hoje, bool $simular): int
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT id, pessoa_id, status FROM vinculo
             WHERE status <> 'encerrado' AND data_fim IS NOT NULL AND data_fim < :hoje
            SQL);
        $consulta->execute(['hoje' => $hoje->format('Y-m-d')]);
        $vencidos = $consulta->fetchAll(PDO::FETCH_ASSOC);

        if ($simular) {
            return count($vencidos);
        }

        foreach ($vencidos as $vinculo) {
            Transacao::executar($this->pdo, function () use ($vinculo): void {
                $this->pdo->prepare(<<<'SQL'
                    UPDATE vinculo
                       SET status = 'encerrado', encerrado_em = now(),
                           motivo_encerramento = 'Encerrado automaticamente: validade expirada'
                     WHERE id = :id AND status <> 'encerrado'
                    SQL)->execute(['id' => $vinculo['id']]);
                $this->sincronizacao->removerBiometriasSemVinculo((string) $vinculo['pessoa_id']);
                $this->auditoria->registrar('job_manutencao', 'vinculo', (string) $vinculo['id'], [
                    'status' => $vinculo['status'],
                ], ['status' => 'encerrado', 'motivo' => 'validade_expirada']);
            });
        }

        return count($vencidos);
    }

    /** @return array{titulares_anonimizados: int, biometrias_excluidas: int} */
    public function aplicarRetencao(DateTimeImmutable $hoje, bool $simular): array
    {
        $anonimizados = 0;
        $biometrias = 0;

        foreach ($this->candidatosRetencao() as $pessoa) {
            $vinculos = $this->vinculosDaPessoa((string) $pessoa['id']);

            if (!$this->retencao->elegivel($vinculos, (string) $pessoa['created_at'], $hoje)) {
                continue;
            }

            $anonimizados++;

            if ($simular) {
                $biometrias += count($this->lgpd->biometriasAtivas((string) $pessoa['id']));
                continue;
            }

            $biometrias += Transacao::executar($this->pdo, fn (): int => $this->anonimizar((string) $pessoa['id']));
        }

        return ['titulares_anonimizados' => $anonimizados, 'biometrias_excluidas' => $biometrias];
    }

    public function alertarCatracasSemContato(bool $simular): int
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT c.id, c.nome FROM catraca c
             WHERE c.ativo
               AND COALESCE(c.ultimo_contato_em, c.created_at) < now() - make_interval(mins => :minutos)
               AND NOT EXISTS (
                   SELECT 1 FROM alerta a
                    WHERE a.catraca_id = c.id AND a.tipo = 'dispositivo_offline' AND a.tratado_em IS NULL
               )
            SQL);
        $consulta->execute(['minutos' => self::MINUTOS_SEM_CONTATO]);
        $catracas = $consulta->fetchAll(PDO::FETCH_ASSOC);

        if (!$simular) {
            $insercao = $this->pdo->prepare(<<<'SQL'
                INSERT INTO alerta (tipo, descricao, catraca_id)
                VALUES ('dispositivo_offline', :d, :c)
                SQL);

            foreach ($catracas as $catraca) {
                $insercao->execute([
                    'c' => $catraca['id'],
                    'd' => sprintf(
                        'Catraca sem contato com o servidor há mais de %d minutos.',
                        self::MINUTOS_SEM_CONTATO,
                    ),
                ]);
            }
        }

        return count($catracas);
    }

    /** @return array{tentativas_login_removidas: int, sincronizacoes_removidas: int} */
    public function limparDadosTecnicos(bool $simular): array
    {
        $dias = self::DIAS_DADOS_TECNICOS;
        $alvos = [
            'tentativas_login_removidas' => "FROM tentativa_login WHERE data_hora < now() - interval '{$dias} days'",
            'sincronizacoes_removidas' => "FROM fila_sincronizacao WHERE status = 'concluido' "
                . "AND processado_em < now() - interval '{$dias} days'",
        ];
        $resultado = [];

        foreach ($alvos as $chave => $filtro) {
            $resultado[$chave] = $simular
                ? (int) $this->pdo->query("SELECT count(*) {$filtro}")->fetchColumn()
                : (int) $this->pdo->exec("DELETE {$filtro}");
        }

        return $resultado;
    }

    /**
     * Elimina biometrias e anonimiza o titular. Eventos de acesso continuam
     * apontando para o registro anonimizado (estatística sem identificar).
     *
     * @return int biometrias excluídas
     */
    private function anonimizar(string $pessoaId): int
    {
        $excluidas = $this->lgpd->excluirBiometrias($pessoaId, 'retencao_vencida');

        $this->pdo->prepare(<<<'SQL'
            UPDATE consentimento
               SET revogado_em = COALESCE(revogado_em, now()), anonimizado_em = now(),
                   responsavel_nome = NULL, responsavel_cpf = NULL, responsavel_parentesco = NULL,
                   ip = '0.0.0.0', revogacao_ip = NULL
             WHERE pessoa_id = :p
            SQL)->execute(['p' => $pessoaId]);

        $this->pdo->prepare(<<<'SQL'
            UPDATE pessoa
               SET nome = 'Titular anonimizado', cpf = NULL, documento_tipo = NULL, documento_numero = NULL,
                   documento_orgao = NULL, documento_uf = NULL, data_nascimento = NULL, email = NULL,
                   telefone = NULL, ativo = false, anonimizada_em = now()
             WHERE id = :p AND anonimizada_em IS NULL
            SQL)->execute(['p' => $pessoaId]);

        $this->sincronizacao->removerDeTodas($pessoaId);
        $this->auditoria->registrar('anonimizar', 'pessoa', $pessoaId, null, [
            'motivo' => 'retencao_vencida',
            'biometrias_excluidas' => count($excluidas),
        ]);

        return count($excluidas);
    }

    /** @return list<array<string, mixed>> pessoas não anonimizadas sem vínculo em aberto sem data de fim */
    private function candidatosRetencao(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT p.id, p.created_at::date::text AS created_at
              FROM pessoa p
             WHERE p.anonimizada_em IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM vinculo v WHERE v.pessoa_id = p.id AND v.status <> 'encerrado' AND v.data_fim IS NULL
               )
            SQL)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{status: string, data_inicio: string, data_fim: ?string}> */
    private function vinculosDaPessoa(string $pessoaId): array
    {
        $consulta = $this->pdo->prepare(
            'SELECT status, data_inicio::text AS data_inicio, data_fim::text AS data_fim'
            . ' FROM vinculo WHERE pessoa_id = :p'
        );
        $consulta->execute(['p' => $pessoaId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }
}
