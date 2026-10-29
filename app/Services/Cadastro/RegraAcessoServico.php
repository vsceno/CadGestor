<?php

namespace App\Services\Cadastro;

use App\Models\Empresa;
use App\Models\PerfilVinculo;
use App\Models\RegraAcesso;
use App\Services\Auditoria;
use App\Support\IntervaloHorario;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Regras de acesso de um perfil numa empresa. Cada linha é um dia, um horário
 * e a catraca (ou todas). Não aceita horários sobrepostos na mesma catraca,
 * nem catraca de outra empresa, nem perfil que a empresa não aceita.
 */
class RegraAcessoServico
{
    public function __construct(private readonly Auditoria $auditoria) {}

    /**
     * @param  list<array{dia: string, inicio: string, fim: string, catraca: ?string}>  $linhas
     *
     * @throws ValidationException
     */
    public function salvar(Empresa $empresa, PerfilVinculo $perfil, array $linhas): void
    {
        if (! $empresa->perfis()->whereKey($perfil->id)->exists()) {
            throw ValidationException::withMessages(['perfil' => 'Este perfil não é aceito pela empresa.']);
        }

        $catracasDaEmpresa = $empresa->catracas()->pluck('id')->all();
        $regras = [];
        $porCatraca = [];

        foreach ($linhas as $i => $linha) {
            if (($linha['inicio'] ?? '') === '' && ($linha['fim'] ?? '') === '') {
                continue;
            }

            try {
                $intervalo = IntervaloHorario::deFormulario((string) $linha['dia'], (string) $linha['inicio'], (string) $linha['fim']);
            } catch (InvalidArgumentException $erro) {
                throw ValidationException::withMessages(['regras' => 'Linha '.($i + 1).': '.$erro->getMessage()]);
            }

            $catracaId = ($linha['catraca'] ?? '') === '' ? null : (string) $linha['catraca'];

            if ($catracaId !== null && ! in_array($catracaId, $catracasDaEmpresa, true)) {
                throw ValidationException::withMessages(['regras' => 'Linha '.($i + 1).': a catraca não pertence a esta empresa.']);
            }

            $regras[] = [$intervalo, $catracaId];
            $porCatraca[$catracaId ?? 'todas'][] = $intervalo;
        }

        foreach ($porCatraca as $intervalos) {
            $conflito = IntervaloHorario::verificarSobreposicao($intervalos);

            if ($conflito !== null) {
                throw ValidationException::withMessages(['regras' => $conflito]);
            }
        }

        DB::connection('pgsql')->transaction(function () use ($empresa, $perfil, $regras): void {
            RegraAcesso::query()->where('empresa_id', $empresa->id)->where('perfil_id', $perfil->id)->delete();

            foreach ($regras as [$intervalo, $catracaId]) {
                RegraAcesso::query()->create([
                    'empresa_id' => $empresa->id,
                    'perfil_id' => $perfil->id,
                    'catraca_id' => $catracaId,
                    'dia_semana' => $intervalo->diaSemana,
                    'hora_inicio' => $intervalo->inicio,
                    'hora_fim' => $intervalo->fim,
                ]);
            }

            $this->auditoria->registrar('alterar', 'regra_acesso', $perfil->id, null, [
                'empresa_id' => $empresa->id,
                'regras' => array_map(
                    static fn (array $r): string => "{$r[0]->diaSemana} {$r[0]->inicio}-{$r[0]->fim} ".($r[1] ?? 'todas'),
                    $regras,
                ),
            ]);
        });
    }
}
