<?php

declare(strict_types=1);

namespace CadGestor\Acesso;

use CadGestor\Seguranca\CifradorBiometria;
use PDO;

/**
 * Lado do dispositivo da fila de sincronização: a catraca busca o que deve
 * cadastrar/remover e confirma o resultado.
 *
 * O template é decifrado só no momento do envio, para a catraca dona da
 * operação, e trafega apenas por HTTPS (obrigatório em produção).
 */
final class SincronizacaoDispositivo
{
    private const MAXIMO_TENTATIVAS = 5;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CifradorBiometria $cifrador,
    ) {
    }

    /**
     * Reserva e devolve as próximas operações da catraca.
     *
     * @return list<array<string, mixed>>
     */
    public function pendentes(string $catracaId, int $limite = 50): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            UPDATE fila_sincronizacao f
               SET status = 'processando', tentativas = tentativas + 1
             WHERE f.id IN (
                   SELECT id FROM fila_sincronizacao
                    WHERE catraca_id = :c AND status IN ('pendente', 'falhou')
                      AND agendado_para <= now() AND tentativas < :max
                    ORDER BY created_at
                    LIMIT :limite
                    FOR UPDATE SKIP LOCKED
             )
            RETURNING f.id, f.operacao, f.pessoa_id, f.biometria_id, f.created_at
            SQL);
        $consulta->execute([
            'c' => $catracaId,
            'limite' => min(max($limite, 1), 200),
            'max' => self::MAXIMO_TENTATIVAS,
        ]);
        $operacoes = $consulta->fetchAll(PDO::FETCH_ASSOC);

        usort($operacoes, static fn (array $a, array $b): int => strcmp((string) $a['created_at'], $b['created_at']));

        return array_map(fn (array $op): array => $this->montar($op), $operacoes);
    }

    /** @return bool falso se a operação não pertence à catraca ou não está em processamento */
    public function confirmar(string $catracaId, string $operacaoId, bool $sucesso, ?string $erro): bool
    {
        $erroSeguro = $erro === null ? null : mb_substr(preg_replace('/[^\w .:\-]/u', '', $erro) ?? '', 0, 255);

        $consulta = $this->pdo->prepare(<<<'SQL'
            UPDATE fila_sincronizacao
               SET status = CASE WHEN :sucesso THEN 'concluido'
                                 WHEN tentativas >= :max THEN 'falhou'
                                 ELSE 'pendente' END,
                   processado_em = CASE WHEN :sucesso THEN now() ELSE processado_em END,
                   ultimo_erro = :erro,
                   agendado_para = CASE WHEN :sucesso THEN agendado_para
                                        ELSE now() + make_interval(mins => tentativas * 5) END
             WHERE id = :id AND catraca_id = :c AND status = 'processando'
            SQL);
        $consulta->execute([
            'sucesso' => $sucesso ? 'true' : 'false',
            'erro' => $sucesso ? null : $erroSeguro,
            'id' => $operacaoId,
            'c' => $catracaId,
            'max' => self::MAXIMO_TENTATIVAS,
        ]);

        if (!$sucesso && $consulta->rowCount() === 1) {
            $this->alertarFalhaDefinitiva($catracaId, $operacaoId);
        }

        return $consulta->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function montar(array $op): array
    {
        $dados = ['id' => $op['id'], 'operacao' => $op['operacao'], 'referencia' => $op['pessoa_id']];

        if ($op['operacao'] !== 'cadastrar' || $op['biometria_id'] === null) {
            return $dados;
        }

        $consulta = $this->pdo->prepare(
            'SELECT tipo, dedo, template_cifrado, nonce, versao_chave FROM biometria WHERE id = :id AND ativo'
        );
        $consulta->execute(['id' => $op['biometria_id']]);
        $b = $consulta->fetch(PDO::FETCH_ASSOC);

        if ($b === false) {
            // Biometria apagada depois do agendamento: nada a enviar.
            $this->pdo->prepare(<<<'SQL'
                UPDATE fila_sincronizacao
                   SET status = 'concluido', processado_em = now(), ultimo_erro = 'biometria_inexistente'
                 WHERE id = :id
                SQL)->execute(['id' => $op['id']]);

            return $dados + ['ignorar' => true];
        }

        $dedo = $b['dedo'] === null ? null : (int) $b['dedo'];
        $bytes = static fn (mixed $v): string => is_resource($v) ? (string) stream_get_contents($v) : (string) $v;
        $template = $this->cifrador->decifrar(
            $bytes($b['template_cifrado']),
            $bytes($b['nonce']),
            (int) $b['versao_chave'],
            CifradorBiometria::dadosAssociados((string) $op['pessoa_id'], (string) $b['tipo'], $dedo),
        );
        $base64 = base64_encode($template);
        sodium_memzero($template);

        return $dados + ['tipo' => $b['tipo'], 'dedo' => $dedo, 'template' => $base64];
    }

    private function alertarFalhaDefinitiva(string $catracaId, string $operacaoId): void
    {
        $consulta = $this->pdo->prepare("SELECT status FROM fila_sincronizacao WHERE id = :id");
        $consulta->execute(['id' => $operacaoId]);

        if ($consulta->fetchColumn() === 'falhou') {
            $this->pdo->prepare(<<<'SQL'
                INSERT INTO alerta (tipo, descricao, catraca_id)
                VALUES ('falha_sincronizacao', 'Operação de sincronização falhou após várias tentativas.', :c)
                SQL)->execute(['c' => $catracaId]);
        }
    }
}
