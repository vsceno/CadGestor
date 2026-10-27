<?php

/**
 * Executa os seeds (dados iniciais idempotentes, como os perfis padrão).
 *
 * Uso: php bin/seed.php
 */

declare(strict_types=1);

use CadGestor\Banco\ExecutorSeeds;
use CadGestor\Nucleo\Ambiente;
use CadGestor\Nucleo\ConexaoBanco;

/** @var Ambiente $ambiente */
$ambiente = require __DIR__ . '/inicializar.php';

$executor = new ExecutorSeeds(
    ConexaoBanco::paraMigracao($ambiente)->pdo(),
    dirname(__DIR__) . '/database/seeds',
);

$executados = $executor->executar(static function (string $seed): void {
    echo "  executado: {$seed}\n";
});

printf("\n%d seed(s) executado(s).\n", count($executados));
