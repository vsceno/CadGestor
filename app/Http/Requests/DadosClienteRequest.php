<?php

namespace App\Http\Requests;

use App\Models\Organizacao;
use App\Support\Documento;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Dados cadastrais de um cliente (pessoa jurídica). Usado pela plataforma
 * (assistente de novo cliente e edição) e, sem razão social/CNPJ, pelo
 * administrador do próprio cliente.
 *
 * Os campos chegam formatados (máscaras) e são guardados só com dígitos.
 */
class DadosClienteRequest extends FormRequest
{
    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public function authorize(): bool
    {
        return true; // A permissão é conferida pelo middleware de papel da rota.
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::normalizar($this->all()));
    }

    /**
     * Tira as máscaras (CEP, telefone, CNPJ) e espaços sobrando; vazio vira null.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, string|null>
     */
    public static function normalizar(array $entrada): array
    {
        $valor = fn (string $campo): string => trim((string) ($entrada[$campo] ?? ''));
        $digitos = fn (string $campo): ?string => $valor($campo) === '' ? null : preg_replace('/\D/', '', $valor($campo));
        $texto = fn (string $campo): ?string => $valor($campo) === '' ? null : trim((string) preg_replace('/\s+/u', ' ', $valor($campo)));

        return [
            'nome' => $texto('nome'),
            'cnpj' => $valor('cnpj') === '' ? null : Documento::normalizarCnpj($valor('cnpj')),
            'nome_fantasia' => $texto('nome_fantasia'),
            'inscricao_municipal' => $texto('inscricao_municipal'),
            'inscricao_estadual' => $valor('inscricao_estadual') === '' ? null : mb_strtoupper((string) $texto('inscricao_estadual')),
            'cep' => $digitos('cep'),
            'logradouro' => $texto('logradouro'),
            'numero' => $texto('numero'),
            'complemento' => $texto('complemento'),
            'bairro' => $texto('bairro'),
            'municipio' => $texto('municipio'),
            'uf' => $valor('uf') === '' ? null : mb_strtoupper($valor('uf')),
            'telefone' => $digitos('telefone'),
            'email_financeiro' => $valor('email_financeiro') === '' ? null : mb_strtolower($valor('email_financeiro')),
            'contato_nome' => $texto('contato_nome'),
            'contato_telefone' => $digitos('contato_telefone'),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $cliente = $this->route('cliente');
        $clienteId = $cliente instanceof Organizacao ? $cliente->id : (is_string($cliente) ? $cliente : null);

        return [...self::regrasIdentificacao($clienteId), ...self::regrasContato()];
    }

    /**
     * Razão social e CNPJ (só a plataforma altera: fazem parte do contrato).
     *
     * @return array<string, mixed>
     */
    public static function regrasIdentificacao(?string $clienteId = null): array
    {
        return [
            'nome' => ['required', 'string', 'min:3', 'max:150'],
            'cnpj' => ['required', function (string $atributo, mixed $valor, Closure $falha) use ($clienteId): void {
                if (! Documento::cnpjValido((string) $valor)) {
                    $falha('CNPJ inválido. Confira os dígitos.');
                } elseif (DB::connection('pgsql')->scalar('SELECT cg_cnpj_cliente_em_uso(?, CAST(? AS uuid))', [$valor, $clienteId])) {
                    $falha('Já existe um cliente com este CNPJ.');
                }
            }],
        ];
    }

    /**
     * Endereço, inscrições e contatos (o administrador do cliente também altera).
     *
     * @return array<string, mixed>
     */
    public static function regrasContato(): array
    {
        $inscricao = ['nullable', 'string', 'max:20', 'regex:#^[0-9A-Za-z./-]{1,20}$#'];

        return [
            'nome_fantasia' => ['nullable', 'string', 'max:150'],
            'inscricao_municipal' => $inscricao,
            'inscricao_estadual' => $inscricao,
            'cep' => ['required', 'digits:8'],
            'logradouro' => ['required', 'string', 'min:2', 'max:200'],
            'numero' => ['required', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:100'],
            'bairro' => ['required', 'string', 'min:2', 'max:100'],
            'municipio' => ['required', 'string', 'min:2', 'max:100'],
            'uf' => ['required', 'in:'.implode(',', self::UFS)],
            'telefone' => ['required', 'digits_between:10,11'],
            'email_financeiro' => ['required', 'email', 'max:254'],
            'contato_nome' => ['nullable', 'string', 'max:150'],
            'contato_telefone' => ['nullable', 'digits_between:10,11'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return self::mensagens();
    }

    /** @return array<string, string> */
    public static function mensagens(): array
    {
        return [
            'cep.digits' => 'O CEP deve ter 8 dígitos.',
            'telefone.digits_between' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'contato_telefone.digits_between' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'uf.in' => 'Selecione a UF.',
            'inscricao_municipal.regex' => 'Use até 20 letras, números, ponto, barra ou hífen.',
            'inscricao_estadual.regex' => 'Use até 20 letras, números, ponto, barra ou hífen (ou "ISENTO").',
            'email_financeiro.email' => 'Informe o e-mail que recebe faturas e avisos de cobrança.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['nome' => 'razão social', 'email_financeiro' => 'e-mail financeiro', 'contato_nome' => 'responsável comercial'];
    }
}
