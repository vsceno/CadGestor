<?php

/**
 * Funções auxiliares globais usadas nas views.
 */

declare(strict_types=1);

use CadGestor\Nucleo\Visao;

if (!function_exists('e')) {
    /**
     * Escapa um valor para saída segura em HTML.
     */
    function e(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Campo oculto com o token CSRF; obrigatório em todo formulário POST. */
    function csrf_campo(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Visao::compartilhado('csrf_token', '')) . '">';
    }

    /**
     * Mensagem de erro de um campo do formulário, se houver.
     *
     * @param array<string, string> $erros
     */
    function erro_campo(array $erros, string $campo): string
    {
        return isset($erros[$campo]) ? '<p class="erro-campo">' . e($erros[$campo]) . '</p>' : '';
    }

    /**
     * Atributo aria-invalid para campos com erro.
     *
     * @param array<string, string> $erros
     */
    function invalido(array $erros, string $campo): string
    {
        return isset($erros[$campo]) ? ' aria-invalid="true"' : '';
    }

    function marcado(bool $condicao): string
    {
        return $condicao ? ' checked' : '';
    }

    function selecionado(bool $condicao): string
    {
        return $condicao ? ' selected' : '';
    }

    /** Data (Y-m-d) no formato brasileiro. */
    function formatar_data(?string $data): string
    {
        if ($data === null || $data === '') {
            return '';
        }

        $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', substr($data, 0, 10));

        return $objeto === false ? $data : $objeto->format('d/m/Y');
    }

    /** Data/hora vinda do banco (UTC) convertida para o fuso da aplicação. */
    function formatar_data_hora(?string $valor, bool $comSegundos = false): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        try {
            $data = (new DateTimeImmutable($valor))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        } catch (Exception) {
            return $valor;
        }

        return $data->format($comSegundos ? 'd/m/Y H:i:s' : 'd/m/Y H:i');
    }

    function formatar_cpf(?string $cpf): string
    {
        if ($cpf === null || strlen($cpf) !== 11) {
            return (string) $cpf;
        }

        return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    }

    /** CPF parcialmente oculto, para listagens e relatórios (minimização). */
    function mascarar_cpf(?string $cpf): string
    {
        if ($cpf === null || strlen($cpf) !== 11) {
            return '';
        }

        return '***.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-**';
    }

    function formatar_cnpj(?string $cnpj): string
    {
        if ($cnpj === null || strlen($cnpj) !== 14) {
            return (string) $cnpj;
        }

        return substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/'
            . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2);
    }

    function formatar_cep(?string $cep): string
    {
        return $cep !== null && strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : (string) $cep;
    }

    function formatar_telefone(?string $telefone): string
    {
        if ($telefone === null || $telefone === '') {
            return '';
        }

        $ddd = substr($telefone, 0, 2);
        $numero = substr($telefone, 2);
        $corte = strlen($numero) - 4;

        return "({$ddd}) " . substr($numero, 0, $corte) . '-' . substr($numero, $corte);
    }

    /** Hora 'HH:MM:SS' do banco exibida como 'HH:MM'. */
    function formatar_hora(?string $hora): string
    {
        return $hora === null ? '' : substr($hora, 0, 5);
    }

    function nome_dia_semana(int $dia): string
    {
        return [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado',
            7 => 'Domingo'][$dia] ?? '';
    }
}
