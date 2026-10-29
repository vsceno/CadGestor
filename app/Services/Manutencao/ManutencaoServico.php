<?php

namespace App\Services\Manutencao;

use App\Models\Alerta;
use App\Models\Catraca;
use App\Models\Pessoa;
use App\Services\Auditoria;
use App\Services\Lgpd\ExclusaoBiometria;
use App\Support\ContextoBanco;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Manutenção diária, cliente por cliente. Cada tarefa roda no contexto do próprio
 * cliente, então o isolamento do banco (RLS) vale também aqui:
 *  - vínculos: encerra os cuja validade já passou;
 *  - retenção: anonimiza titulares sem vínculo em aberto há mais que o prazo.
 *    Biometria é apagada, o consentimento é revogado e os eventos ficam sem identificação;
 *  - catracas: avisa quando uma catraca que já falou com o servidor fica sem contato.
 */
final class ManutencaoServico
{
    public const TAREFAS = ['vinculos', 'retencao', 'catracas'];

    public function __construct(
        private readonly ContextoBanco $contexto,
        private readonly Auditoria $auditoria,
        private readonly ExclusaoBiometria $exclusao,
    ) {}

    /**
     * Roda as tarefas em todos os clientes ativos.
     *
     * @param  list<string>  $tarefas
     * @return array<string, int> quantas linhas cada tarefa tratou, somando os clientes
     */
    public function executar(array $tarefas, CarbonInterface $agora): array
    {
        $totais = array_fill_keys($tarefas, 0);

        foreach ($this->organizacoes() as $organizacao) {
            foreach ($this->executarNaOrganizacao($organizacao, $tarefas, $agora) as $tarefa => $quantidade) {
                $totais[$tarefa] += $quantidade;
            }
        }

        return $totais;
    }

    /**
     * @param  list<string>  $tarefas
     * @return array<string, int>
     */
    public function executarNaOrganizacao(string $organizacaoId, array $tarefas, CarbonInterface $agora): array
    {
        $this->contexto->definir($organizacaoId);

        try {
            $resultado = [];

            foreach ($tarefas as $tarefa) {
                $resultado[$tarefa] = match ($tarefa) {
                    'vinculos' => $this->encerrarVinculosVencidos($agora),
                    'retencao' => $this->anonimizarSemVinculo($agora),
                    'catracas' => $this->avisarCatracasSemContato($agora),
                    default => throw new InvalidArgumentException("Tarefa de manutenção desconhecida: {$tarefa}."),
                };
            }

            return $resultado;
        } finally {
            $this->contexto->limpar();
        }
    }

    /**
     * Lista os clientes ativos. Só o id é lido, no modo plataforma, e nenhum dado de cliente.
     *
     * @return list<string>
     */
    private function organizacoes(): array
    {
        $this->contexto->definir(null, null, true);

        try {
            return array_map('strval', DB::connection('pgsql')->table('organizacao')
                ->where('ativo', true)
                ->orderBy('created_at')
                ->pluck('id')
                ->all());
        } finally {
            $this->contexto->limpar();
        }
    }

    private function encerrarVinculosVencidos(CarbonInterface $agora): int
    {
        $ids = DB::connection('pgsql')->table('vinculo')
            ->whereIn('status', ['ativo', 'suspenso'])
            ->whereNotNull('data_fim')
            ->whereDate('data_fim', '<', $agora->toDateString())
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            DB::connection('pgsql')->update(<<<'SQL'
                UPDATE vinculo
                   SET status = 'encerrado', encerrado_em = ?, motivo_encerramento = ?
                 WHERE id = ? AND status IN ('ativo', 'suspenso')
                SQL, [$agora, 'Validade vencida (manutenção automática).', $id]);

            $this->auditoria->registrar('alterar', 'vinculo', (string) $id, null, ['status' => 'encerrado']);
        }

        return count($ids);
    }

    private function anonimizarSemVinculo(CarbonInterface $agora): int
    {
        $limite = $agora->copy()->subDays((int) config('manutencao.dias_retencao'));

        $ids = DB::connection('pgsql')->select(<<<'SQL'
            SELECT p.id
              FROM pessoa p
             WHERE p.anonimizada_em IS NULL
               AND NOT EXISTS (SELECT 1 FROM vinculo v WHERE v.pessoa_id = p.id AND v.status <> 'encerrado')
               AND COALESCE((SELECT max(v.encerrado_em) FROM vinculo v WHERE v.pessoa_id = p.id), p.created_at) < ?
            SQL, [$limite]);

        foreach ($ids as $linha) {
            $this->anonimizar((string) $linha->id, $agora);
        }

        return count($ids);
    }

    /**
     * Apaga as biometrias, revoga o consentimento e tira os dados de identificação.
     * O registro da pessoa e os vínculos encerrados ficam, para manter o histórico íntegro.
     */
    private function anonimizar(string $pessoaId, CarbonInterface $agora): void
    {
        $pessoa = Pessoa::query()->findOrFail($pessoaId);

        $this->exclusao->excluir($pessoa, 'retencao_vencida');

        DB::connection('pgsql')->update(
            'UPDATE consentimento SET revogado_em = ? WHERE pessoa_id = ? AND revogado_em IS NULL',
            [$agora, $pessoaId],
        );

        DB::connection('pgsql')->update(<<<'SQL'
            UPDATE pessoa
               SET nome = 'Titular anonimizado', cpf = NULL, documento_tipo = NULL, documento_numero = NULL,
                   documento_orgao = NULL, documento_uf = NULL, data_nascimento = NULL,
                   email = NULL, telefone = NULL, ativo = false, anonimizada_em = ?
             WHERE id = ? AND anonimizada_em IS NULL
            SQL, [$agora, $pessoaId]);

        $this->auditoria->registrar('anonimizar', 'pessoa', $pessoaId, null, ['motivo' => 'retencao_vencida']);
    }

    private function avisarCatracasSemContato(CarbonInterface $agora): int
    {
        $minutos = (int) config('manutencao.minutos_sem_contato');
        $limite = $agora->copy()->subMinutes($minutos);

        $avisos = 0;

        $catracas = Catraca::query()
            ->where('ativo', true)
            ->whereNotNull('ultimo_contato_em')
            ->where('ultimo_contato_em', '<', $limite)
            ->get();

        foreach ($catracas as $catraca) {
            $repetido = Alerta::query()
                ->where('catraca_id', $catraca->id)
                ->where('tipo', 'dispositivo_offline')
                ->where('data_hora', '>=', $limite)
                ->exists();

            if ($repetido) {
                continue;
            }

            Alerta::query()->create([
                'tipo' => 'dispositivo_offline',
                'descricao' => "Catraca sem contato com o servidor há mais de {$minutos} minutos.",
                'catraca_id' => $catraca->id,
            ]);
            $avisos++;
        }

        return $avisos;
    }
}
