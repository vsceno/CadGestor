<?php

/**
 * Jobs de manutenção diária (vínculos vencidos, retenção LGPD, catracas sem
 * contato e limpeza técnica). Roda com o papel de manutenção (owner).
 *
 * Uso:
 *   php bin/manutencao.php                       executa todas as tarefas
 *   php bin/manutencao.php --simular             só mostra o que seria feito
 *   php bin/manutencao.php --tarefa=retencao     executa uma tarefa
 *   php bin/manutencao.php --simular --data=2027-02-01   prevê o efeito numa data futura
 *
 * Agendamento no Windows (todo dia às 02:00), ver README.
 */

declare(strict_types=1);

use CadGestor\Dominio\PoliticaRetencao;
use CadGestor\Nucleo\Ambiente;
use CadGestor\Nucleo\ConexaoBanco;
use CadGestor\Nucleo\Contexto;
use CadGestor\Nucleo\Log;
use CadGestor\Repositorios\LgpdRepositorio;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\ManutencaoServico;
use CadGestor\Servicos\SincronizacaoServico;

/** @var Ambiente $ambiente */
$ambiente = require __DIR__ . '/inicializar.php';

$opcoes = getopt('', ['simular', 'tarefa:', 'data:']);
$simular = isset($opcoes['simular']);
$hoje = new DateTimeImmutable('today');

// --data só vale em simulação: "o que aconteceria nesta data?"
if (isset($opcoes['data']) && is_string($opcoes['data'])) {
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $opcoes['data']);

    if (!$simular || $data === false) {
        fwrite(STDERR, "Use --data=AAAA-MM-DD somente junto com --simular.\n");
        exit(1);
    }

    $hoje = $data;
}
$tarefas = isset($opcoes['tarefa']) && is_string($opcoes['tarefa'])
    ? [$opcoes['tarefa']]
    : ManutencaoServico::TAREFAS;

if (array_diff($tarefas, ManutencaoServico::TAREFAS) !== []) {
    fwrite(STDERR, 'Tarefas válidas: ' . implode(', ', ManutencaoServico::TAREFAS) . PHP_EOL);
    exit(1);
}

$pdo = ConexaoBanco::paraMigracao($ambiente)->pdo();
$servico = new ManutencaoServico(
    $pdo,
    new PoliticaRetencao($ambiente->inteiro('RETENCAO_DIAS', 30)),
    new LgpdRepositorio($pdo),
    new SincronizacaoServico($pdo),
    new Auditoria($pdo, new Contexto('127.0.0.1')),
);

$inicio = microtime(true);
$resultado = $servico->executar($tarefas, $hoje, $simular);

echo ($simular ? 'SIMULAÇÃO para ' . $hoje->format('d/m/Y') . " (nada foi alterado)\n" : "Manutenção concluída\n");

foreach ($resultado as $chave => $quantidade) {
    printf("  %-30s %d\n", str_replace('_', ' ', $chave), $quantidade);
}

printf("  (%.2fs)\n", microtime(true) - $inicio);

// Log técnico só com contagens (sem dados pessoais).
(new Log(dirname(__DIR__) . '/storage/logs'))->registrar(
    'info',
    $simular ? 'Manutenção simulada' : 'Manutenção executada',
    $resultado,
);
