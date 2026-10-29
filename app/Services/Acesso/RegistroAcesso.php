<?php

namespace App\Services\Acesso;

use App\Models\Alerta;
use App\Models\Biometria;
use App\Models\Catraca;
use App\Models\EventoAcesso;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Grava cada passagem (online ou registrada pela catraca sem servidor) e abre
 * alerta para as negações, sem repetir o mesmo alerta a cada tentativa.
 */
class RegistroAcesso
{
    /** Janela em que a mesma negação não gera novo alerta na mesma catraca. */
    private const MINUTOS_SEM_REPETIR_ALERTA = 10;

    public function registrar(Catraca $catraca, ResultadoAcesso $decisao, string $tipo, ?string $score = null): EventoAcesso
    {
        $evento = EventoAcesso::query()->create([
            'catraca_id' => $catraca->id,
            'empresa_id' => $catraca->empresa_id,
            'pessoa_id' => $decisao->pessoaId,
            'vinculo_id' => $decisao->vinculoId,
            'perfil_id' => $decisao->perfilId,
            'data_hora' => now(),
            'tipo_identificacao' => $tipo,
            'resultado' => $decisao->resultado,
            'motivo_negacao' => $decisao->motivo,
            'score' => $score,
            'offline' => false,
        ]);

        if (! $decisao->liberou()) {
            $this->alertar($catraca, $evento);
        }

        return $evento;
    }

    /**
     * Eventos que a catraca decidiu sem servidor. Aqui só se registra o que aconteceu;
     * a decisão já foi tomada no dispositivo. Motivo inválido vira falha do sistema.
     *
     * @param  list<array<string, mixed>>  $eventos
     */
    public function registrarOffline(Catraca $catraca, array $eventos): int
    {
        foreach ($eventos as $evento) {
            $negado = $evento['resultado'] === 'negado';
            $motivo = $negado ? ($evento['motivo'] ?? 'falha_sistema') : null;

            EventoAcesso::query()->create([
                'catraca_id' => $catraca->id,
                'empresa_id' => $catraca->empresa_id,
                'pessoa_id' => $this->pessoaDaBiometria($evento['biometria_id'] ?? null),
                'data_hora' => CarbonImmutable::parse((string) $evento['data_hora']),
                'tipo_identificacao' => $evento['tipo_identificacao'],
                'resultado' => $evento['resultado'],
                'motivo_negacao' => $motivo,
                'offline' => true,
            ]);
        }

        return count($eventos);
    }

    private function alertar(Catraca $catraca, EventoAcesso $evento): void
    {
        // Só o motivo, sem nome: o alerta pode ser lido por toda a equipe da portaria.
        $descricao = 'Acesso negado na catraca: '.(ResultadoAcesso::ROTULOS[$evento->motivo_negacao] ?? 'motivo não informado').'.';

        $repetido = Alerta::query()
            ->where('catraca_id', $catraca->id)
            ->where('descricao', $descricao)
            ->where('data_hora', '>=', Carbon::now()->subMinutes(self::MINUTOS_SEM_REPETIR_ALERTA))
            ->exists();

        if ($repetido) {
            return;
        }

        Alerta::query()->create([
            'tipo' => 'acesso_negado',
            'descricao' => $descricao,
            'catraca_id' => $catraca->id,
            'pessoa_id' => $evento->pessoa_id,
            'evento_id' => $evento->id,
        ]);
    }

    private function pessoaDaBiometria(?string $biometriaId): ?string
    {
        if ($biometriaId === null) {
            return null;
        }

        // Mesmo biometria excluída depois serve para atribuir o evento que aconteceu antes.
        return Biometria::query()->whereKey($biometriaId)->value('pessoa_id');
    }
}
