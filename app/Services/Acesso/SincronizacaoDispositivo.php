<?php

namespace App\Services\Acesso;

use App\Models\Alerta;
use App\Models\Catraca;
use App\Models\FilaSincronizacao;
use App\Services\Lgpd\BiometriaServico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fila de sincronização com a catraca: entrega os templates a cadastrar e as
 * remoções pendentes, e recebe o resultado de cada uma. Falha repetida vira alerta.
 */
class SincronizacaoDispositivo
{
    private const TENTATIVAS_ATE_ALERTA = 5;

    public function __construct(private readonly BiometriaServico $biometrias) {}

    /**
     * Pega as operações pendentes da catraca e as marca como em processamento.
     *
     * @return list<array<string, mixed>>
     */
    public function pendentes(Catraca $catraca, int $limite): array
    {
        return DB::connection('pgsql')->transaction(function () use ($catraca, $limite): array {
            $linhas = DB::connection('pgsql')->select(<<<'SQL'
                SELECT f.id, f.operacao, f.biometria_id, b.tipo, b.dedo
                  FROM fila_sincronizacao f
                  LEFT JOIN biometria b ON b.id = f.biometria_id
                 WHERE f.catraca_id = ? AND f.status IN ('pendente', 'falhou') AND f.agendado_para <= now()
                 ORDER BY f.agendado_para
                 LIMIT ?
                   FOR UPDATE OF f SKIP LOCKED
                SQL, [$catraca->id, $limite]);

            if ($linhas === []) {
                return [];
            }

            FilaSincronizacao::query()->whereIn('id', array_column($linhas, 'id'))->update(['status' => 'processando']);

            $operacoes = [];

            foreach ($linhas as $linha) {
                $operacao = [
                    'id' => $linha->id,
                    'operacao' => $linha->operacao,
                    'biometria_id' => $linha->biometria_id,
                    'tipo' => $linha->tipo,
                    'dedo' => $linha->dedo === null ? null : (int) $linha->dedo,
                ];

                if ($linha->operacao === 'cadastrar') {
                    try {
                        $operacao['template'] = base64_encode($this->biometrias->template((string) $linha->biometria_id));
                    } catch (RuntimeException) {
                        // Biometria excluída depois da fila: o template não existe mais, então a operação não se conclui.
                        $this->marcarFalha($linha->id, 'template_indisponivel');

                        continue;
                    }
                }

                $operacoes[] = $operacao;
            }

            return $operacoes;
        });
    }

    /** Devolve false quando a operação não é desta catraca. */
    public function resultado(Catraca $catraca, string $operacaoId, bool $sucesso, ?string $erro): bool
    {
        $operacao = FilaSincronizacao::query()->where('catraca_id', $catraca->id)->whereKey($operacaoId)->first();

        if ($operacao === null) {
            return false;
        }

        if ($sucesso) {
            $operacao->forceFill(['status' => 'concluido', 'processado_em' => now(), 'ultimo_erro' => null])->save();

            return true;
        }

        $tentativas = $this->marcarFalha($operacao->id, $erro ?? 'erro_desconhecido');

        if ($tentativas === self::TENTATIVAS_ATE_ALERTA) {
            Alerta::query()->create([
                'tipo' => 'falha_sincronizacao',
                'descricao' => 'Uma operação de sincronização de biometria falhou '.$tentativas.' vezes seguidas.',
                'catraca_id' => $catraca->id,
            ]);
        }

        return true;
    }

    /** Registra a falha, reagenda com espera crescente e devolve o número de tentativas. */
    private function marcarFalha(string $operacaoId, string $erro): int
    {
        $operacao = FilaSincronizacao::query()->whereKey($operacaoId)->firstOrFail();
        $tentativas = (int) $operacao->tentativas + 1;

        $operacao->forceFill([
            'status' => 'falhou',
            'tentativas' => $tentativas,
            'ultimo_erro' => Str::limit($erro, 255, ''),
            'agendado_para' => now()->addMinutes(min(60, 5 * $tentativas)),
        ])->save();

        return $tentativas;
    }
}
