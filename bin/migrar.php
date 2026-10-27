<?php

/**
 * Aplica as migrações pendentes.
 *
 * Uso:
 *   php bin/migrar.php            aplica as pendentes
 *   php bin/migrar.php --status   apenas lista aplicadas e pendentes
 */

declare(strict_types=1);

use CadGestor\Banco\Migrador;
use CadGestor\Nucleo\Ambiente;
use CadGestor\Nucleo\ConexaoBanco;

/** @var Ambiente $ambiente */
$ambiente = require __DIR__ . '/inicializar.php';

$migrador = new Migrador(
    ConexaoBanco::paraMigracao($ambiente)->pdo(),
    dirname(__DIR__) . '/database/migracoes',
);

if (in_array('--status', $argv, true)) {
    $aplicadas = $migrador->aplicadas();
    $pendentes = $migrador->pendentes();

    foreach ($migrador->disponiveis() as $versao => $migracao) {
        $situacao = isset($aplicadas[$versao]) ? 'aplicada em ' . $aplicadas[$versao]['aplicada_em'] : 'PENDENTE';
        printf("  %s_%-40s %s\n", $versao, $migracao['nome'], $situacao);
    }

    printf("\n%d aplicada(s), %d pendente(s).\n", count($aplicadas), count($pendentes));
    exit(0);
}

$aplicadas = $migrador->migrar(static function (string $migracao): void {
    echo "  aplicada: {$migracao}\n";
});

echo $aplicadas === []
    ? "Nada a fazer: banco já está atualizado.\n"
    : sprintf("\n%d migração(ões) aplicada(s) com sucesso.\n", count($aplicadas));
