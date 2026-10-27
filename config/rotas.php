<?php

/**
 * Tabela de rotas da aplicação.
 */

declare(strict_types=1);

use CadGestor\Http\Controllers\InicioController;
use CadGestor\Http\Controllers\SaudeController;
use CadGestor\Nucleo\Aplicacao;
use CadGestor\Nucleo\Roteador;

return static function (Roteador $rotas, Aplicacao $app): void {
    $inicio = new InicioController($app->ambiente, $app->banco);
    $saude = new SaudeController($app->banco, $app->log);

    $rotas->get('/', $inicio->index(...));
    $rotas->get('/saude', $saude->verificar(...));
};
