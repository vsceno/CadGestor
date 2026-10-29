<?php

namespace App\Services\Cadastro;

use App\Models\PerfilVinculo;
use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Perfis de vínculo do cliente. O nome é único (sem diferenciar maiúsculas) e
 * cada perfil tem uma base legal que o cliente precisa definir e validar.
 */
class PerfilServico
{
    public function __construct(private readonly Auditoria $auditoria) {}

    /** @param  array<string, mixed>  $entrada */
    public function criar(array $entrada): PerfilVinculo
    {
        $dados = $this->validar($entrada);

        return DB::connection('pgsql')->transaction(function () use ($dados): PerfilVinculo {
            $perfil = PerfilVinculo::query()->create($dados);
            $this->auditoria->registrar('criar', 'perfil_vinculo', $perfil->id, null, $dados);

            return $perfil;
        });
    }

    /** @param  array<string, mixed>  $entrada */
    public function atualizar(PerfilVinculo $perfil, array $entrada): void
    {
        $dados = $this->validar($entrada, $perfil);

        DB::connection('pgsql')->transaction(function () use ($perfil, $dados): void {
            $antes = $perfil->only(array_keys($dados));
            $perfil->update($dados);
            [$de, $para] = Auditoria::diferenca($antes, $perfil->only(array_keys($dados)));

            if ($para !== null && $para !== []) {
                $this->auditoria->registrar('alterar', 'perfil_vinculo', $perfil->id, $de, $para);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function validar(array $entrada, ?PerfilVinculo $atual = null): array
    {
        $entrada['nome'] = trim((string) preg_replace('/\s+/u', ' ', (string) ($entrada['nome'] ?? '')));
        $entrada['descricao'] = trim((string) ($entrada['descricao'] ?? ''));

        $dados = Validator::make($entrada, [
            'nome' => ['required', 'string', 'min:2', 'max:60', function (string $atributo, mixed $valor, \Closure $falha) use ($atual): void {
                $emUso = PerfilVinculo::query()
                    ->whereRaw('lower(nome) = lower(?)', [$valor])
                    ->when($atual !== null, fn ($consulta) => $consulta->whereKeyNot($atual->id))
                    ->exists();

                if ($emUso) {
                    $falha('Já existe um perfil com este nome.');
                }
            }],
            'descricao' => ['nullable', 'string', 'max:255'],
            'base_legal' => ['required', Rule::in(array_keys(PerfilVinculo::BASES_LEGAIS))],
        ], [
            'base_legal.required' => 'Selecione a base legal.',
            'base_legal.in' => 'Selecione a base legal.',
        ], ['base_legal' => 'base legal'])->validate();

        return [
            'nome' => $dados['nome'],
            'descricao' => ($dados['descricao'] ?? '') === '' ? null : $dados['descricao'],
            'base_legal' => $dados['base_legal'],
            'base_legal_validada' => filter_var($entrada['base_legal_validada'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'ativo' => filter_var($entrada['ativo'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
