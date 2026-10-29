<?php

namespace App\Services\Cadastro;

use App\Http\Requests\DadosClienteRequest;
use App\Models\Pessoa;
use App\Services\Auditoria;
use App\Services\LimitesPlano;
use App\Support\Documento;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro de pessoas. O CPF é único dentro do cliente e não muda depois de
 * cadastrado. Nada é excluído: a pessoa é desativada. A trilha registra o fato
 * e os nomes dos campos alterados, nunca os dados pessoais.
 */
class PessoaServico
{
    private const OPCIONAIS = [
        'documento_tipo', 'documento_numero', 'documento_orgao', 'documento_uf',
        'data_nascimento', 'email', 'telefone',
    ];

    public function __construct(
        private readonly Auditoria $auditoria,
        private readonly LimitesPlano $limites,
    ) {}

    /** @param  array<string, mixed>  $entrada */
    public function criar(array $entrada): Pessoa
    {
        $this->limites->garantirCapacidade('pessoas');
        $dados = $this->validar($entrada);

        return DB::connection('pgsql')->transaction(function () use ($dados): Pessoa {
            $pessoa = Pessoa::query()->create($dados + ['ativo' => true]);
            $this->auditoria->registrar('criar', 'pessoa', $pessoa->id, null, ['cadastro' => true]);

            return $pessoa;
        });
    }

    /** @param  array<string, mixed>  $entrada */
    public function atualizar(Pessoa $pessoa, array $entrada): void
    {
        $this->garantirEditavel($pessoa);
        $dados = $this->validar($entrada, $pessoa);

        DB::connection('pgsql')->transaction(function () use ($pessoa, $dados): void {
            $pessoa->update($dados);
            $alterados = array_values(array_diff(array_keys($pessoa->getChanges()), ['updated_at']));

            if ($alterados !== []) {
                $this->auditoria->registrar('alterar', 'pessoa', $pessoa->id, null, ['campos_alterados' => $alterados]);
            }
        });
    }

    /** Desativar tira a pessoa da contagem do plano; reativar confere a vaga. */
    public function definirAtivo(Pessoa $pessoa, bool $ativo): void
    {
        $this->garantirEditavel($pessoa);

        if ($ativo && ! $pessoa->ativo) {
            $this->limites->garantirCapacidade('pessoas');
        }

        DB::connection('pgsql')->transaction(function () use ($pessoa, $ativo): void {
            $antes = $pessoa->ativo;
            $pessoa->forceFill(['ativo' => $ativo])->save();
            $this->auditoria->registrar('alterar', 'pessoa', $pessoa->id, ['ativo' => $antes], ['ativo' => $ativo]);
        });
    }

    private function garantirEditavel(Pessoa $pessoa): void
    {
        if ($pessoa->anonimizada_em !== null) {
            throw ValidationException::withMessages([
                'pessoa' => 'Registro anonimizado por política de retenção: não pode ser alterado.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validar(array $entrada, ?Pessoa $atual = null): array
    {
        $entrada = array_map(
            static fn (mixed $valor): mixed => is_string($valor) ? trim((string) preg_replace('/\s+/u', ' ', $valor)) : $valor,
            $entrada,
        );
        $entrada['cpf'] = Documento::normalizarCpf((string) ($entrada['cpf'] ?? ''));
        $entrada['telefone'] = preg_replace('/\D/', '', (string) ($entrada['telefone'] ?? '')) ?? '';
        $entrada['email'] = mb_strtolower((string) ($entrada['email'] ?? ''));
        $entrada['documento_orgao'] = mb_strtoupper((string) ($entrada['documento_orgao'] ?? ''));
        $entrada['documento_uf'] = mb_strtoupper((string) ($entrada['documento_uf'] ?? ''));

        $regras = [
            'nome' => ['required', 'string', 'min:3', 'max:150', 'regex:/^\S+\s+\S+/'],
            'documento_tipo' => ['nullable', Rule::in(array_keys(Pessoa::TIPOS_DOCUMENTO)), 'required_with:documento_numero'],
            'documento_numero' => ['nullable', 'string', 'max:30', 'required_with:documento_tipo'],
            'documento_orgao' => ['nullable', 'string', 'max:20'],
            'documento_uf' => ['nullable', Rule::in(DadosClienteRequest::UFS)],
            'data_nascimento' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:today'],
            'email' => ['nullable', 'email', 'max:254'],
            'telefone' => ['nullable', 'digits_between:10,11'],
        ];

        // O CPF é a identidade da pessoa: só é recebido no cadastro.
        if ($atual === null) {
            $regras['cpf'] = ['required', function (string $atributo, mixed $valor, Closure $falha): void {
                if (! Documento::cpfValido((string) $valor)) {
                    $falha('CPF inválido.');

                    return;
                }

                if (Pessoa::query()->where('cpf', $valor)->exists()) {
                    $falha('Já existe uma pessoa com este CPF. Use o cadastro existente para adicionar o vínculo.');
                }
            }];
        }

        $dados = Validator::make($entrada, $regras, [
            'nome.regex' => 'Informe o nome completo (nome e sobrenome).',
            'documento_tipo.required_with' => 'Informe o tipo do documento.',
            'documento_numero.required_with' => 'Informe o número do documento.',
            'data_nascimento.before_or_equal' => 'A data de nascimento não pode estar no futuro.',
            'data_nascimento.after' => 'Data de nascimento inválida.',
            'email.email' => 'Informe um e-mail válido.',
            'telefone.digits_between' => 'Telefone com DDD (10 ou 11 dígitos).',
        ], [
            'documento_tipo' => 'tipo do documento', 'documento_numero' => 'número do documento',
            'documento_orgao' => 'órgão emissor', 'documento_uf' => 'UF do documento',
            'data_nascimento' => 'data de nascimento',
        ])->validate();

        if ($atual !== null) {
            unset($dados['cpf']);
        }

        foreach (self::OPCIONAIS as $campo) {
            $dados[$campo] = ($dados[$campo] ?? '') === '' ? null : $dados[$campo];
        }

        return $dados;
    }
}
