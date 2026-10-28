<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

/**
 * Geração de CSV compatível com o Excel em português (UTF-8 com BOM e ";").
 *
 * Protege contra injeção de fórmula: células que começam com = + - @ ou
 * tabulação recebem um apóstrofo na frente, para o Excel tratar como texto.
 */
final class Csv
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param list<string>             $cabecalhos
     * @param iterable<list<mixed>>    $linhas
     */
    public static function gerar(array $cabecalhos, iterable $linhas): string
    {
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, self::BOM);
        fputcsv($saida, array_map([self::class, 'celula'], $cabecalhos), ';', '"', '');

        foreach ($linhas as $linha) {
            fputcsv($saida, array_map([self::class, 'celula'], $linha), ';', '"', '');
        }

        rewind($saida);
        $conteudo = (string) stream_get_contents($saida);
        fclose($saida);

        return $conteudo;
    }

    public static function celula(mixed $valor): string
    {
        $texto = match (true) {
            $valor === null => '',
            is_bool($valor) => $valor ? 'sim' : 'não',
            default => (string) $valor,
        };

        if ($texto !== '' && (in_array($texto[0], ['=', '+', '-', '@', "\t", "\r"], true))) {
            // Números negativos legítimos continuam números.
            if (preg_match('/^-\d+([.,]\d+)?$/', $texto) !== 1) {
                return "'" . $texto;
            }
        }

        return $texto;
    }
}
