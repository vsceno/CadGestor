<?php

/**
 * Front controller: toda requisição HTTP entra por aqui.
 */

declare(strict_types=1);

use CadGestor\Nucleo\Aplicacao;
use CadGestor\Nucleo\Requisicao;

$raiz = dirname(__DIR__);

require $raiz . '/vendor/autoload.php';

try {
    $app = Aplicacao::iniciar($raiz);
} catch (Throwable) {
    // Falha antes do tratador de erros existir (ex.: .env ausente ou inválido).
    // Nenhum detalhe é exibido; consulte o README.
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'CadGestor: configuração inválida ou ausente (.env).';
    exit;
}

$app->registrarRotas($raiz . '/config/rotas.php')
    ->atender(Requisicao::daGlobal())
    ->enviar();
