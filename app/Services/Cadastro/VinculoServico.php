<?php

namespace App\Services\Cadastro;

use App\Models\Pessoa;
use App\Models\Vinculo;
use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Vínculos pessoa x empresa x perfil, com validade. O vínculo nunca é apagado:
 * ao encerrar, a data de fim e o motivo ficam no histórico.
 */
class VinculoServico
{
    public function __construct(private readonly Auditoria $auditoria) {}

    /**
     * Combinações "empresa|perfil" que podem ser vinculadas hoje: empresa e
     * perfil ativos, e o perfil aceito pela empresa.
     *
     * @return array<string, string>
     */
    public function opcoes(): array
    {
        return DB::connection('pgsql')->table('empresa_perfil as ep')
            ->join('empresa as e', 'e.id', '=', 'ep.empresa_id')
            ->join('perfil_vinculo as p', 'p.id', '=', 'ep.perfil_id')
            ->where('e.ativo', true)
            ->where('p.ativo', true)
            ->orderBy('e.razao_social')
            ->orderBy('p.nome')
            ->get(['e.id as empresa_id', 'e.razao_social', 'p.id as perfil_id', 'p.nome as perfil'])
            ->mapWithKeys(fn (object $linha): array => [
                $linha->empresa_id.'|'.$linha->perfil_id => $linha->razao_social.' · '.$linha->perfil,
            ])
            ->all();
    }

    /** @param  array<string, mixed>  $entrada */
    public function criar(Pessoa $pessoa, array $entrada): Vinculo
    {
        if ($pessoa->anonimizada_em !== null || ! $pessoa->ativo) {
            throw ValidationException::withMessages([
                'pessoa' => 'A pessoa está inativa: reative o cadastro antes de criar vínculos.',
            ]);
        }

        $combinacao = (string) ($entrada['empresa_perfil'] ?? '');

        if (! array_key_exists($combinacao, $this->opcoes())) {
            throw ValidationException::withMessages([
                'empresa_perfil' => 'Selecione uma empresa ativa e um perfil aceito por ela.',
            ]);
        }

        [$empresaId, $perfilId] = explode('|', $combinacao, 2);

        $jaExiste = Vinculo::query()
            ->where('pessoa_id', $pessoa->id)
            ->where('empresa_id', $empresaId)
            ->where('perfil_id', $perfilId)
            ->where('status', '!=', 'encerrado')
            ->exists();

        if ($jaExiste) {
            throw ValidationException::withMessages([
                'empresa_perfil' => 'A pessoa já tem um vínculo ativo com esta empresa neste perfil.',
            ]);
        }

        $periodo = $this->validarPeriodo($entrada);

        return DB::connection('pgsql')->transaction(function () use ($pessoa, $empresaId, $perfilId, $periodo): Vinculo {
            $vinculo = Vinculo::query()->create([
                'pessoa_id' => $pessoa->id,
                'empresa_id' => $empresaId,
                'perfil_id' => $perfilId,
                'data_inicio' => $periodo['data_inicio'],
                'data_fim' => $periodo['data_fim'],
                'status' => 'ativo',
            ]);
            $this->auditoria->registrar('criar', 'vinculo', $vinculo->id, null, [
                'pessoa_id' => $pessoa->id,
                'empresa_id' => $empresaId,
                'perfil_id' => $perfilId,
                'data_inicio' => $periodo['data_inicio'],
                'data_fim' => $periodo['data_fim'],
            ]);

            return $vinculo;
        });
    }

    /** @param  array<string, mixed>  $entrada */
    public function alterarPeriodo(Vinculo $vinculo, array $entrada): void
    {
        $this->garantirNaoEncerrado($vinculo);
        $periodo = $this->validarPeriodo($entrada);

        DB::connection('pgsql')->transaction(function () use ($vinculo, $periodo): void {
            $antes = [
                'data_inicio' => $vinculo->data_inicio->format('Y-m-d'),
                'data_fim' => $vinculo->data_fim?->format('Y-m-d'),
            ];
            $vinculo->update($periodo);
            $this->auditoria->registrar('alterar', 'vinculo', $vinculo->id, $antes, $periodo);
        });
    }

    public function suspender(Vinculo $vinculo): void
    {
        $this->trocarStatus($vinculo, 'ativo', 'suspenso');
    }

    public function reativar(Vinculo $vinculo): void
    {
        $this->trocarStatus($vinculo, 'suspenso', 'ativo');
    }

    /** @param  array<string, mixed>  $entrada */
    public function encerrar(Vinculo $vinculo, array $entrada): void
    {
        $this->garantirNaoEncerrado($vinculo);

        $dados = Validator::make($entrada, [
            'data_encerramento' => [
                'nullable', 'date_format:Y-m-d', 'before_or_equal:today',
                'after_or_equal:'.$vinculo->data_inicio->format('Y-m-d'),
            ],
            'motivo' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'data_encerramento.after_or_equal' => 'A data de encerramento deve ser igual ou posterior ao início do vínculo.',
            'data_encerramento.before_or_equal' => 'A data de encerramento não pode estar no futuro.',
            'data_encerramento.date_format' => 'Data de encerramento inválida.',
            'motivo.required' => 'Informe o motivo do encerramento.',
            'motivo.min' => 'O motivo deve ter pelo menos 3 caracteres.',
        ])->validate();

        $data = ($dados['data_encerramento'] ?? '') === '' ? now()->toDateString() : $dados['data_encerramento'];
        $motivo = trim((string) $dados['motivo']);

        DB::connection('pgsql')->transaction(function () use ($vinculo, $data, $motivo): void {
            $antes = ['status' => $vinculo->status];
            $vinculo->forceFill([
                'status' => 'encerrado',
                'data_fim' => $data,
                'motivo_encerramento' => $motivo,
                'encerrado_em' => now(),
            ])->save();
            $this->auditoria->registrar('alterar', 'vinculo', $vinculo->id, $antes, [
                'status' => 'encerrado',
                'data_fim' => $data,
                'motivo_encerramento' => $motivo,
            ]);
        });
    }

    private function trocarStatus(Vinculo $vinculo, string $de, string $para): void
    {
        $this->garantirNaoEncerrado($vinculo);

        if ($vinculo->status !== $de) {
            throw ValidationException::withMessages([
                'status' => 'Operação não permitida na situação atual do vínculo.',
            ]);
        }

        DB::connection('pgsql')->transaction(function () use ($vinculo, $de, $para): void {
            $vinculo->forceFill(['status' => $para])->save();
            $this->auditoria->registrar('alterar', 'vinculo', $vinculo->id, ['status' => $de], ['status' => $para]);
        });
    }

    private function garantirNaoEncerrado(Vinculo $vinculo): void
    {
        if ($vinculo->status === 'encerrado') {
            throw ValidationException::withMessages([
                'status' => 'Vínculo encerrado não pode ser alterado: ele fica no histórico.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{data_inicio: string, data_fim: ?string}
     *
     * @throws ValidationException
     */
    private function validarPeriodo(array $entrada): array
    {
        $dados = Validator::make($entrada, [
            'data_inicio' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'data_fim' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01', 'after_or_equal:data_inicio'],
        ], [
            'data_inicio.required' => 'Informe a data de início.',
            'data_inicio.date_format' => 'Data de início inválida.',
            'data_inicio.after' => 'Data de início fora do período aceito.',
            'data_fim.date_format' => 'Data de validade inválida.',
            'data_fim.after_or_equal' => 'A validade deve ser igual ou posterior ao início.',
        ])->validate();

        return [
            'data_inicio' => $dados['data_inicio'],
            'data_fim' => ($dados['data_fim'] ?? '') === '' ? null : $dados['data_fim'],
        ];
    }
}
