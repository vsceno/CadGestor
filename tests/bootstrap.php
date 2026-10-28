<?php

/*
 * Preparação dos testes: recria o schema do banco de TESTE com todas as
 * migrações, uma única vez por execução. Recusa qualquer banco que não
 * termine em "_teste" (proteção contra apagar o banco de desenvolvimento).
 */

use App\Support\RecriadorBanco;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$banco = (string) config('database.connections.pgsql.database');

if (! str_ends_with($banco, '_teste')) {
    fwrite(STDERR, "Os testes só rodam num banco *_teste (configurado: {$banco}).\n");
    exit(1);
}

$app->make(RecriadorBanco::class)->recriar();
$app->flush();

// Devolve os tratadores de erro do PHP ao estado original (cada teste sobe o próprio app).
HandleExceptions::flushState();
