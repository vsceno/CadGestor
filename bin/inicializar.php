<?php

/**
 * Inicialização comum dos scripts de linha de comando.
 *
 * Retorna o Ambiente carregado. Erros são mostrados no terminal (operador
 * técnico) e o script termina com código 1.
 */

declare(strict_types=1);

use CadGestor\Nucleo\Ambiente;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

set_exception_handler(static function (Throwable $erro): void {
    fwrite(STDERR, PHP_EOL . '[ERRO] ' . $erro->getMessage() . PHP_EOL);
    exit(1);
});

$ambiente = Ambiente::carregar(dirname(__DIR__));
date_default_timezone_set($ambiente->texto('APP_FUSO'));

return $ambiente;
