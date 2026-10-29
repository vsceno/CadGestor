<?php

namespace App\Console\Commands;

use App\Services\Manutencao\ManutencaoServico;
use Illuminate\Console\Command;

/**
 * Manutenção agendada (ver routes/console.php). Pode rodar uma tarefa só: --tarefa=catracas.
 */
class ExecutarManutencao extends Command
{
    protected $signature = 'manutencao:executar {--tarefa=* : vinculos, retencao ou catracas (padrão: todas)}';

    protected $description = 'Encerra vínculos vencidos, anonimiza titulares fora do prazo e avisa catracas sem contato';

    public function handle(ManutencaoServico $manutencao): int
    {
        $tarefas = $this->option('tarefa') ?: ManutencaoServico::TAREFAS;
        $invalidas = array_diff($tarefas, ManutencaoServico::TAREFAS);

        if ($invalidas !== []) {
            $this->components->error('Tarefa inválida: '.implode(', ', $invalidas).'.');

            return self::FAILURE;
        }

        foreach ($manutencao->executar($tarefas, now()) as $tarefa => $quantidade) {
            $this->components->info("{$tarefa}: {$quantidade}");
        }

        return self::SUCCESS;
    }
}
