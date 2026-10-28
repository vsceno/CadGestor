<?php

declare(strict_types=1);

namespace CadGestor\Dominio;

use CadGestor\Servicos\EmpresaServico;

/**
 * Validação dos dados cadastrais de um cliente (pessoa jurídica): endereço,
 * inscrições, telefone, e-mail financeiro e contato comercial.
 *
 * Usada pela plataforma (venda manual e edição) e pelo administrador do
 * próprio cliente. Razão social e CNPJ ficam fora: são validados por quem
 * pode alterá-los (a plataforma ou o cadastro self-service).
 */
final class DadosCadastraisCliente
{
    /** Campos que o administrador do cliente pode editar (o resto é da plataforma). */
    public const CAMPOS_CONTATO = [
        'nome_fantasia', 'inscricao_municipal', 'inscricao_estadual', 'cep', 'logradouro', 'numero',
        'complemento', 'bairro', 'municipio', 'uf', 'telefone', 'email_financeiro', 'contato_nome',
        'contato_telefone',
    ];

    /**
     * @param array<string, string> $entrada
     * @return array{0: array<string, string|null>, 1: array<string, string>} dados normalizados e erros
     */
    public static function validar(array $entrada): array
    {
        $texto = static fn (string $campo): string => trim(preg_replace('/\s+/u', ' ', $entrada[$campo] ?? '') ?? '');
        $opcional = static fn (string $campo): ?string => $texto($campo) === '' ? null : $texto($campo);
        $digitos = static fn (string $campo): string => preg_replace('/\D/', '', $entrada[$campo] ?? '') ?? '';

        $dados = [
            'nome_fantasia' => $opcional('nome_fantasia'),
            'inscricao_municipal' => $opcional('inscricao_municipal'),
            'inscricao_estadual' => $opcional('inscricao_estadual'),
            'cep' => $digitos('cep'),
            'logradouro' => $texto('logradouro'),
            'numero' => $texto('numero'),
            'complemento' => $opcional('complemento'),
            'bairro' => $texto('bairro'),
            'municipio' => $texto('municipio'),
            'uf' => mb_strtoupper($texto('uf')),
            'telefone' => $digitos('telefone'),
            'email_financeiro' => mb_strtolower($texto('email_financeiro')),
            'contato_nome' => $opcional('contato_nome'),
            'contato_telefone' => $digitos('contato_telefone') === '' ? null : $digitos('contato_telefone'),
        ];
        $erros = [];

        $tamanhos = ['logradouro' => [2, 200], 'numero' => [1, 20], 'bairro' => [2, 100], 'municipio' => [2, 100]];

        foreach ($tamanhos as $campo => [$minimo, $maximo]) {
            $tamanho = mb_strlen((string) $dados[$campo]);

            if ($tamanho < $minimo || $tamanho > $maximo) {
                $erros[$campo] = "Preencha este campo ({$minimo} a {$maximo} caracteres).";
            }
        }

        foreach (['nome_fantasia' => 150, 'complemento' => 100, 'contato_nome' => 150] as $campo => $maximo) {
            if ($dados[$campo] !== null && mb_strlen($dados[$campo]) > $maximo) {
                $erros[$campo] = "Máximo de {$maximo} caracteres.";
            }
        }

        foreach (['inscricao_municipal', 'inscricao_estadual'] as $campo) {
            if ($dados[$campo] !== null && preg_match('#^[0-9A-Za-z./-]{1,20}$#', $dados[$campo]) !== 1) {
                $erros[$campo] = 'Use até 20 letras, números, ponto, barra ou hífen (ou "ISENTO").';
            }
        }

        if (preg_match('/^\d{8}$/', (string) $dados['cep']) !== 1) {
            $erros['cep'] = 'CEP deve ter 8 dígitos.';
        }

        if (!in_array($dados['uf'], EmpresaServico::UFS, true)) {
            $erros['uf'] = 'Selecione a UF.';
        }

        if (preg_match('/^\d{10,11}$/', (string) $dados['telefone']) !== 1) {
            $erros['telefone'] = 'Telefone com DDD (10 ou 11 dígitos).';
        }

        if ($dados['contato_telefone'] !== null && preg_match('/^\d{10,11}$/', $dados['contato_telefone']) !== 1) {
            $erros['contato_telefone'] = 'Telefone com DDD (10 ou 11 dígitos).';
        }

        $email = (string) $dados['email_financeiro'];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $erros['email_financeiro'] = 'Informe o e-mail que recebe faturas e avisos de cobrança.';
        }

        return [$dados, $erros];
    }

    /**
     * O cadastro tem CNPJ, endereço completo, telefone e e-mail financeiro
     * (necessários para emitir a nota fiscal).
     *
     * @param array<string, mixed> $organizacao
     */
    public static function completo(array $organizacao): bool
    {
        $obrigatorios = [
            'cnpj', 'cep', 'logradouro', 'numero', 'bairro', 'municipio', 'uf', 'telefone', 'email_financeiro',
        ];

        foreach ($obrigatorios as $c) {
            if (($organizacao[$c] ?? null) === null || $organizacao[$c] === '') {
                return false;
            }
        }

        return true;
    }
}
