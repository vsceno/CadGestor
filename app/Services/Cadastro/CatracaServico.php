<?php

namespace App\Services\Cadastro;

use App\Models\Catraca;
use App\Services\Auditoria;
use App\Services\LimitesPlano;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catracas do cliente (uma ou mais). O token de conexão é gerado aqui e só o
 * hash SHA-256 fica no banco: o texto é devolvido uma única vez. Antes de
 * criar ou reativar, confere o limite de catracas do plano.
 */
class CatracaServico
{
    public const PREFIXO_TOKEN = 'cgt_';

    public function __construct(
        private readonly Auditoria $auditoria,
        private readonly LimitesPlano $limites,
    ) {}

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{catraca: Catraca, token: string}
     */
    public function criar(array $entrada): array
    {
        $this->limites->garantirCapacidade('catracas');
        $dados = $this->validar($entrada);
        $token = self::gerarToken();

        $catraca = DB::connection('pgsql')->transaction(function () use ($dados, $token): Catraca {
            $catraca = Catraca::query()->create($dados + ['token_hash' => self::hashToken($token)]);
            $this->auditoria->registrar('criar', 'catraca', $catraca->id, null, $dados);

            return $catraca;
        });

        return ['catraca' => $catraca, 'token' => $token];
    }

    /** @param  array<string, mixed>  $entrada */
    public function atualizar(Catraca $catraca, array $entrada): void
    {
        $entrada['empresa_id'] = $catraca->empresa_id; // A catraca não muda de empresa.
        $dados = $this->validar($entrada, $catraca);

        DB::connection('pgsql')->transaction(function () use ($catraca, $dados): void {
            $antes = $catraca->only(array_keys($dados));
            $catraca->update($dados);
            [$de, $para] = Auditoria::diferenca($antes, $catraca->only(array_keys($dados)));

            if ($para !== null && $para !== []) {
                $this->auditoria->registrar('alterar', 'catraca', $catraca->id, $de, $para);
            }
        });
    }

    /** Novo token: o anterior deixa de valer imediatamente. */
    public function renovarToken(Catraca $catraca): string
    {
        $token = self::gerarToken();

        DB::connection('pgsql')->transaction(function () use ($catraca, $token): void {
            $catraca->forceFill(['token_hash' => self::hashToken($token)])->save();
            $this->auditoria->registrar('alterar', 'catraca', $catraca->id, null, ['token_renovado' => true]);
        });

        return $token;
    }

    public function alterarSituacao(Catraca $catraca, bool $ativo): void
    {
        if ($ativo && ! $catraca->ativo) {
            $this->limites->garantirCapacidade('catracas');
        }

        DB::connection('pgsql')->transaction(function () use ($catraca, $ativo): void {
            $antes = $catraca->ativo;
            $catraca->forceFill(['ativo' => $ativo])->save();
            $this->auditoria->registrar('alterar', 'catraca', $catraca->id, ['ativo' => $antes], ['ativo' => $ativo]);
        });
    }

    private static function gerarToken(): string
    {
        return self::PREFIXO_TOKEN.bin2hex(random_bytes(32));
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validar(array $entrada, ?Catraca $atual = null): array
    {
        $entrada['nome'] = trim((string) preg_replace('/\s+/u', ' ', (string) ($entrada['nome'] ?? '')));
        $entrada['localizacao'] = trim((string) ($entrada['localizacao'] ?? ''));
        $entrada['identificador_dispositivo'] = trim((string) ($entrada['identificador_dispositivo'] ?? ''));

        $empresaRegra = $atual !== null
            ? Rule::in([$atual->empresa_id])
            : Rule::exists('empresa', 'id')->where('ativo', true);

        $dados = Validator::make($entrada, [
            'empresa_id' => ['required', 'uuid', $empresaRegra],
            'nome' => ['required', 'string', 'min:2', 'max:100'],
            'localizacao' => ['nullable', 'string', 'max:150'],
            'identificador_dispositivo' => ['required', 'regex:/^[A-Za-z0-9._:-]{3,100}$/', Rule::unique('catraca', 'identificador_dispositivo')->ignore($atual?->id)],
            'tipo_identificacao' => ['required', Rule::in(array_keys(Catraca::TIPOS_IDENTIFICACAO))],
            'modo_falha' => ['required', Rule::in(array_keys(Catraca::MODOS_FALHA))],
            'fabricante' => ['nullable', 'string', 'max:100'],
            'modelo' => ['nullable', 'string', 'max:100'],
        ], [
            'empresa_id.required' => 'Selecione a empresa.',
            'empresa_id.exists' => 'Selecione uma empresa ativa.',
            'identificador_dispositivo.regex' => 'Use de 3 a 100 caracteres: letras, números, ponto, hífen, dois-pontos.',
            'identificador_dispositivo.unique' => 'Já existe uma catraca com este identificador.',
            'tipo_identificacao.in' => 'Selecione o tipo de identificação.',
            'modo_falha.in' => 'Selecione o modo de falha.',
        ], ['empresa_id' => 'empresa', 'identificador_dispositivo' => 'identificador do dispositivo'])->validate();

        foreach (['localizacao', 'fabricante', 'modelo'] as $campo) {
            $dados[$campo] = ($dados[$campo] ?? '') === '' ? null : $dados[$campo];
        }

        return $dados;
    }
}
