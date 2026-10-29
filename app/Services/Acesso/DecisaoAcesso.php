<?php

namespace App\Services\Acesso;

use App\Models\Biometria;
use App\Models\Catraca;
use App\Models\EmpresaHorario;
use App\Models\RegraAcesso;
use App\Models\Vinculo;
use Carbon\CarbonInterface;

/**
 * Decisão de acesso. Hierarquia: regras do perfil na empresa > horário padrão da
 * empresa. Uma pessoa passa se qualquer um dos seus vínculos válidos permitir.
 * A comparação biométrica é feita no dispositivo: aqui chega só o id da biometria
 * que ele reconheceu.
 */
class DecisaoAcesso
{
    public function decidir(Catraca $catraca, ?string $biometriaId, CarbonInterface $agora): ResultadoAcesso
    {
        if (! $catraca->ativo) {
            return ResultadoAcesso::negado('catraca_inativa');
        }

        if ($biometriaId === null) {
            return ResultadoAcesso::negado('pessoa_nao_identificada');
        }

        $biometria = Biometria::query()->with('pessoa')->whereKey($biometriaId)->where('ativo', true)->first();

        if ($biometria === null) {
            return ResultadoAcesso::negado('biometria_nao_confere');
        }

        $pessoa = $biometria->pessoa;

        if (! $pessoa->ativo || $pessoa->anonimizada_em !== null) {
            return ResultadoAcesso::negado('pessoa_inativa', $pessoa->id);
        }

        $hoje = $agora->toDateString();
        $vinculos = Vinculo::query()
            ->with('perfil')
            ->where('pessoa_id', $pessoa->id)
            ->where('empresa_id', $catraca->empresa_id)
            ->where('status', 'ativo')
            ->whereDate('data_inicio', '<=', $hoje)
            ->where(fn ($consulta) => $consulta->whereNull('data_fim')->orWhereDate('data_fim', '>=', $hoje))
            ->get()
            ->filter(fn (Vinculo $vinculo): bool => $vinculo->perfil->ativo);

        if ($vinculos->isEmpty() || ! $catraca->empresa->ativo) {
            return ResultadoAcesso::negado('sem_vinculo_ativo', $pessoa->id);
        }

        $primeiroMotivo = null;

        foreach ($vinculos as $vinculo) {
            $motivo = $this->motivoDoPerfil($catraca, $vinculo, $agora);

            if ($motivo === null) {
                return ResultadoAcesso::liberado($pessoa->id, $vinculo->id, $vinculo->perfil_id);
            }

            $primeiroMotivo ??= $motivo;
        }

        return ResultadoAcesso::negado($primeiroMotivo ?? 'sem_vinculo_ativo', $pessoa->id);
    }

    /** Null quando o perfil do vínculo passa agora; senão, o motivo da negação. */
    private function motivoDoPerfil(Catraca $catraca, Vinculo $vinculo, CarbonInterface $agora): ?string
    {
        $dia = $agora->dayOfWeekIso;
        $hora = $agora->format('H:i:s');

        $regras = RegraAcesso::query()
            ->where('empresa_id', $catraca->empresa_id)
            ->where('perfil_id', $vinculo->perfil_id)
            ->where('ativo', true)
            ->get();

        // Sem regra própria do perfil: vale o horário padrão da empresa, em todas as catracas.
        if ($regras->isEmpty()) {
            $janelas = EmpresaHorario::query()
                ->where('empresa_id', $catraca->empresa_id)
                ->where('dia_semana', $dia)
                ->get();

            return $janelas->contains(fn (EmpresaHorario $janela): bool => $this->dentroDoIntervalo($janela->hora_inicio, $janela->hora_fim, $hora))
                ? null
                : 'fora_do_horario';
        }

        $desta = $regras->filter(fn (RegraAcesso $regra): bool => $regra->catraca_id === null || $regra->catraca_id === $catraca->id);

        if ($desta->isEmpty()) {
            return 'perfil_nao_permitido';
        }

        $liberado = $desta->contains(fn (RegraAcesso $regra): bool => (int) $regra->dia_semana === $dia
            && $this->dentroDoIntervalo($regra->hora_inicio, $regra->hora_fim, $hora));

        return $liberado ? null : 'fora_do_horario';
    }

    /**
     * Horas no formato HH:MM:SS, comparadas como texto. O fim "24:00:00" (fim do dia)
     * é maior que qualquer hora válida, então a comparação já cobre o caso.
     */
    private function dentroDoIntervalo(string $inicio, string $fim, string $hora): bool
    {
        return $inicio <= $hora && $fim > $hora;
    }
}
