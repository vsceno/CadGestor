<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

use RuntimeException;

/**
 * Renderizador de templates PHP da pasta views/.
 *
 * Dentro dos templates, toda saída dinâmica deve passar por e(), que escapa
 * HTML. Pense nele como uma luva: nada que veio do usuário toca a página sem ela.
 */
final class Visao
{
    private static string $diretorio = __DIR__ . '/../../views';

    /** @param array<string, mixed> $dados */
    public static function renderizar(string $template, array $dados = [], ?string $layout = 'layout/base'): string
    {
        $conteudo = self::incluir($template, $dados);

        if ($layout === null) {
            return $conteudo;
        }

        return self::incluir($layout, $dados + ['conteudo' => $conteudo]);
    }

    /** @param array<string, mixed> $dados */
    private static function incluir(string $template, array $dados): string
    {
        if (preg_match('#^[a-z0-9_/-]+$#', $template) !== 1) {
            throw new RuntimeException('Nome de template inválido.');
        }

        $arquivo = self::$diretorio . '/' . $template . '.php';

        if (!is_file($arquivo)) {
            throw new RuntimeException("Template não encontrado: {$template}");
        }

        return (static function (string $__arquivo, array $__dados): string {
            extract($__dados, EXTR_SKIP);
            ob_start();

            try {
                require $__arquivo;
            } catch (\Throwable $erro) {
                ob_end_clean();
                throw $erro;
            }

            return (string) ob_get_clean();
        })($arquivo, $dados);
    }
}
