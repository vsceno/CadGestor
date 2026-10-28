<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use PDO;

/**
 * Fila de sincronização de templates com as catracas.
 *
 * O servidor não "empurra" nada para o dispositivo: ele anota numa fila o que
 * cada catraca precisa cadastrar ou remover, e a catraca busca e confirma pela
 * API. É como uma caixa de correio por catraca: se ela estiver desligada, as
 * cartas esperam até ela voltar.
 *
 * A organização de cada operação vem da catraca (e não do contexto da sessão),
 * para que o job de manutenção, que roda fora do RLS, também grave certo.
 */
final class SincronizacaoServico
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Envia uma biometria a todas as catracas ativas das empresas onde a pessoa tem vínculo vigente. */
    public function enviarBiometria(string $biometriaId): int
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO fila_sincronizacao (organizacao_id, catraca_id, pessoa_id, biometria_id, operacao)
            SELECT DISTINCT c.organizacao_id, c.id, b.pessoa_id, b.id, 'cadastrar'
              FROM biometria b
              JOIN vinculo v ON v.pessoa_id = b.pessoa_id AND v.status <> 'encerrado'
              JOIN catraca c ON c.empresa_id = v.empresa_id AND c.ativo
             WHERE b.id = :id AND b.ativo
            SQL);
        $consulta->execute(['id' => $biometriaId]);

        return $consulta->rowCount();
    }

    /** Novo vínculo: as biometrias ativas da pessoa vão para as catracas da empresa. */
    public function enviarBiometriasParaEmpresa(string $pessoaId, string $empresaId): int
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO fila_sincronizacao (organizacao_id, catraca_id, pessoa_id, biometria_id, operacao)
            SELECT c.organizacao_id, c.id, b.pessoa_id, b.id, 'cadastrar'
              FROM biometria b
              JOIN catraca c ON c.empresa_id = :empresa AND c.ativo
             WHERE b.pessoa_id = :pessoa AND b.ativo
            SQL);
        $consulta->execute(['pessoa' => $pessoaId, 'empresa' => $empresaId]);

        return $consulta->rowCount();
    }

    /**
     * Remove a pessoa das catracas das empresas com as quais ela não tem mais
     * vínculo em vigor (ex.: após encerrar um vínculo).
     */
    public function removerBiometriasSemVinculo(string $pessoaId): int
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO fila_sincronizacao (organizacao_id, catraca_id, pessoa_id, operacao)
            SELECT DISTINCT c.organizacao_id, c.id, CAST(:pessoa AS uuid), 'remover'
              FROM vinculo v
              JOIN catraca c ON c.empresa_id = v.empresa_id
             WHERE v.pessoa_id = :pessoa
               AND NOT EXISTS (
                   SELECT 1 FROM vinculo v2
                    WHERE v2.pessoa_id = v.pessoa_id AND v2.empresa_id = v.empresa_id AND v2.status <> 'encerrado'
               )
            SQL);
        $consulta->execute(['pessoa' => $pessoaId]);

        return $consulta->rowCount();
    }

    /**
     * Remove a pessoa de TODAS as catracas em que ela possa estar (revogação de
     * consentimento, exclusão por retenção).
     */
    public function removerDeTodas(string $pessoaId): int
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO fila_sincronizacao (organizacao_id, catraca_id, pessoa_id, operacao)
            SELECT DISTINCT c.organizacao_id, c.id, CAST(:pessoa AS uuid), 'remover'
              FROM vinculo v
              JOIN catraca c ON c.empresa_id = v.empresa_id
             WHERE v.pessoa_id = :pessoa
            SQL);
        $consulta->execute(['pessoa' => $pessoaId]);

        // Operações de cadastro ainda pendentes perdem o sentido.
        $this->pdo->prepare(<<<'SQL'
            UPDATE fila_sincronizacao
               SET status = 'concluido', processado_em = now(), ultimo_erro = 'cancelado_por_remocao'
             WHERE pessoa_id = :pessoa AND operacao = 'cadastrar' AND status IN ('pendente', 'falhou')
            SQL)->execute(['pessoa' => $pessoaId]);

        return $consulta->rowCount();
    }
}
