<?php

namespace App\Services\Cadastro;

use App\Http\Requests\DadosClienteRequest;
use App\Models\Empresa;
use App\Models\EmpresaHorario;
use App\Models\PerfilVinculo;
use App\Services\Auditoria;
use App\Support\Documento;
use App\Support\IntervaloHorario;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Empresas atendidas pelo cliente: cadastro, situação, perfis aceitos e
 * horário padrão de funcionamento. Tudo fica na trilha de auditoria do cliente.
 */
class EmpresaServico
{
    private const OPCIONAIS = ['nome_fantasia', 'complemento', 'responsavel_cargo'];

    public function __construct(private readonly Auditoria $auditoria) {}

    /** @param  array<string, mixed>  $entrada */
    public function criar(array $entrada): Empresa
    {
        $dados = $this->validar($entrada);

        return DB::connection('pgsql')->transaction(function () use ($dados): Empresa {
            // "ativo" vai explícito: o default do banco não volta para o modelo devolvido.
            $empresa = Empresa::query()->create($dados + ['ativo' => true]);
            $this->auditoria->registrar('criar', 'empresa', $empresa->id, null, $dados);

            return $empresa;
        });
    }

    /** @param  array<string, mixed>  $entrada */
    public function atualizar(Empresa $empresa, array $entrada): void
    {
        $dados = $this->validar($entrada, $empresa);

        DB::connection('pgsql')->transaction(function () use ($empresa, $dados): void {
            $antes = $empresa->only(array_keys($dados));
            $empresa->update($dados);
            [$de, $para] = Auditoria::diferenca($antes, $empresa->only(array_keys($dados)));

            if ($para !== null && $para !== []) {
                $this->auditoria->registrar('alterar', 'empresa', $empresa->id, $de, $para);
            }
        });
    }

    public function alterarSituacao(Empresa $empresa, bool $ativo): void
    {
        DB::connection('pgsql')->transaction(function () use ($empresa, $ativo): void {
            $antes = $empresa->ativo;
            $empresa->forceFill(['ativo' => $ativo])->save();
            $this->auditoria->registrar('alterar', 'empresa', $empresa->id, ['ativo' => $antes], ['ativo' => $ativo]);
        });
    }

    /** @param  list<string>  $perfisIds  só perfis ativos da organização são aceitos */
    public function definirPerfis(Empresa $empresa, array $perfisIds): void
    {
        $validos = PerfilVinculo::query()->where('ativo', true)->pluck('id')->all();
        $selecionados = array_values(array_intersect(array_unique($perfisIds), $validos));
        $antes = $empresa->perfis()->pluck('perfil_vinculo.id')->all();

        DB::connection('pgsql')->transaction(function () use ($empresa, $selecionados, $antes): void {
            DB::connection('pgsql')->table('empresa_perfil')->where('empresa_id', $empresa->id)->delete();

            foreach ($selecionados as $perfilId) {
                DB::connection('pgsql')->table('empresa_perfil')->insert([
                    'empresa_id' => $empresa->id,
                    'perfil_id' => $perfilId,
                ]);
            }

            $this->auditoria->registrar('alterar', 'empresa', $empresa->id, ['perfis' => $antes], ['perfis' => $selecionados]);
        });
    }

    /**
     * @param  list<string>  $dias
     * @param  list<string>  $inicios
     * @param  list<string>  $fins
     */
    public function definirHorarios(Empresa $empresa, array $dias, array $inicios, array $fins): void
    {
        $intervalos = self::montarIntervalos($dias, $inicios, $fins);

        DB::connection('pgsql')->transaction(function () use ($empresa, $intervalos): void {
            EmpresaHorario::query()->where('empresa_id', $empresa->id)->delete();

            foreach ($intervalos as $intervalo) {
                EmpresaHorario::query()->create([
                    'empresa_id' => $empresa->id,
                    'dia_semana' => $intervalo->diaSemana,
                    'hora_inicio' => $intervalo->inicio,
                    'hora_fim' => $intervalo->fim,
                ]);
            }

            $this->auditoria->registrar('alterar', 'empresa_horario_padrao', $empresa->id, null, [
                'horarios' => array_map(
                    static fn (IntervaloHorario $i): string => "{$i->diaSemana} {$i->inicio}-{$i->fim}",
                    $intervalos,
                ),
            ]);
        });
    }

    /**
     * @param  list<string>  $dias
     * @param  list<string>  $inicios
     * @param  list<string>  $fins
     * @return list<IntervaloHorario>
     *
     * @throws ValidationException
     */
    public static function montarIntervalos(array $dias, array $inicios, array $fins): array
    {
        $intervalos = [];

        foreach ($dias as $i => $dia) {
            $inicio = $inicios[$i] ?? '';
            $fim = $fins[$i] ?? '';

            if ($inicio === '' && $fim === '') {
                continue;
            }

            try {
                $intervalos[] = IntervaloHorario::deFormulario((string) $dia, (string) $inicio, (string) $fim);
            } catch (InvalidArgumentException $erro) {
                throw ValidationException::withMessages(['horarios' => 'Linha '.($i + 1).': '.$erro->getMessage()]);
            }
        }

        $sobreposicao = IntervaloHorario::verificarSobreposicao($intervalos);

        if ($sobreposicao !== null) {
            throw ValidationException::withMessages(['horarios' => $sobreposicao]);
        }

        return $intervalos;
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validar(array $entrada, ?Empresa $atual = null): array
    {
        $entrada = array_map(static fn (mixed $valor): mixed => is_string($valor) ? trim((string) preg_replace('/\s+/u', ' ', $valor)) : $valor, $entrada);
        $entrada['cnpj'] = Documento::normalizarCnpj((string) ($entrada['cnpj'] ?? ''));
        $entrada['cep'] = preg_replace('/\D/', '', (string) ($entrada['cep'] ?? '')) ?? '';
        $entrada['responsavel_telefone'] = preg_replace('/\D/', '', (string) ($entrada['responsavel_telefone'] ?? '')) ?? '';
        $entrada['uf'] = mb_strtoupper((string) ($entrada['uf'] ?? ''));
        $entrada['responsavel_email'] = mb_strtolower((string) ($entrada['responsavel_email'] ?? ''));

        $dados = Validator::make($entrada, [
            'razao_social' => ['required', 'string', 'min:3', 'max:200'],
            'nome_fantasia' => ['nullable', 'string', 'max:200'],
            'cnpj' => ['required', function (string $atributo, mixed $valor, Closure $falha) use ($atual): void {
                if (! Documento::cnpjValido((string) $valor)) {
                    $falha('CNPJ inválido. Confira os dígitos (aceita o formato alfanumérico).');

                    return;
                }

                $emUso = Empresa::query()->where('cnpj', $valor)
                    ->when($atual !== null, fn ($consulta) => $consulta->whereKeyNot($atual->id))
                    ->exists();

                if ($emUso) {
                    $falha('Já existe uma empresa com este CNPJ.');
                }
            }],
            'cep' => ['required', 'digits:8'],
            'logradouro' => ['required', 'string', 'min:2', 'max:200'],
            'numero' => ['required', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:100'],
            'bairro' => ['required', 'string', 'min:2', 'max:100'],
            'municipio' => ['required', 'string', 'min:2', 'max:100'],
            'uf' => ['required', Rule::in(DadosClienteRequest::UFS)],
            'responsavel_nome' => ['required', 'string', 'min:3', 'max:150'],
            'responsavel_cargo' => ['nullable', 'string', 'max:100'],
            'responsavel_email' => ['required', 'email', 'max:254'],
            'responsavel_telefone' => ['required', 'digits_between:10,11'],
        ], [
            'cep.digits' => 'O CEP deve ter 8 dígitos.',
            'uf.in' => 'Selecione a UF.',
            'responsavel_telefone.digits_between' => 'Telefone com DDD (10 ou 11 dígitos).',
            'responsavel_email.email' => 'Informe um e-mail válido.',
        ], [
            'razao_social' => 'razão social', 'responsavel_nome' => 'nome do responsável',
            'responsavel_email' => 'e-mail do responsável', 'responsavel_telefone' => 'telefone do responsável',
        ])->validate();

        foreach (self::OPCIONAIS as $campo) {
            $dados[$campo] = ($dados[$campo] ?? '') === '' ? null : $dados[$campo];
        }

        return $dados;
    }
}
