<?php

namespace App\Http\Controllers\Cadastro;

use Illuminate\Http\Request;

/**
 * Lê listas e linhas dos formulários de cadastro. Um valor que chega como
 * array onde se espera texto vira vazio: a validação do serviço recusa, sem erro 500.
 */
trait LeEntradas
{
    /** @return list<string> */
    protected function lista(Request $request, string $chave): array
    {
        return array_values(array_map(
            static fn (mixed $valor): string => is_scalar($valor) ? (string) $valor : '',
            (array) $request->input($chave, []),
        ));
    }

    /**
     * Linhas enviadas como "linhas[n][coluna]".
     *
     * @param  list<string>  $colunas
     * @return list<array<string, string>>
     */
    protected function linhas(Request $request, string $chave, array $colunas): array
    {
        $linhas = [];

        foreach ((array) $request->input($chave, []) as $linha) {
            $linha = is_array($linha) ? $linha : [];
            $linhas[] = array_combine($colunas, array_map(
                static fn (string $coluna): string => is_scalar($linha[$coluna] ?? null) ? (string) $linha[$coluna] : '',
                $colunas,
            ));
        }

        return $linhas;
    }
}
