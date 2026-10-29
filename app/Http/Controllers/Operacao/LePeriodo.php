<?php

namespace App\Http\Controllers\Operacao;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Período e filtros de eventos (dias inteiros, no fuso da aplicação).
 * Os filtros chegam por POST na busca: data, catraca e resultado não vão para a URL.
 */
trait LePeriodo
{
    /** Período máximo de consulta e de exportação. */
    private const DIAS_MAXIMOS = 366;

    /**
     * @return array{inicio: Carbon, fim: Carbon, catraca: ?string, resultado: ?string}
     */
    protected function filtros(Request $request, int $diasPadrao): array
    {
        $dados = $request->validate([
            'inicio' => ['nullable', 'date_format:Y-m-d'],
            'fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'catraca' => ['nullable', 'uuid'],
            'resultado' => ['nullable', Rule::in(['liberado', 'negado'])],
        ]);

        $inicio = Carbon::parse($dados['inicio'] ?? now()->subDays($diasPadrao - 1)->toDateString())->startOfDay();
        $fim = Carbon::parse($dados['fim'] ?? now()->toDateString())->endOfDay();

        if ($inicio->diffInDays($fim) > self::DIAS_MAXIMOS) {
            throw ValidationException::withMessages([
                'fim' => 'O período máximo é de '.self::DIAS_MAXIMOS.' dias.',
            ]);
        }

        return [
            'inicio' => $inicio,
            'fim' => $fim,
            'catraca' => $dados['catraca'] ?? null,
            'resultado' => $dados['resultado'] ?? null,
        ];
    }
}
