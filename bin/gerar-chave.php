<?php

/**
 * Gera a chave de cifra dos templates biométricos.
 *
 * Uso:
 *   php bin/gerar-chave.php            cria o arquivo (recusa se já existir)
 *   php bin/gerar-chave.php --rotacionar  acrescenta uma nova versão de chave
 *
 * Faça BACKUP do arquivo em local seguro e separado do backup do banco:
 * sem ele, os templates cifrados são irrecuperáveis.
 */

declare(strict_types=1);

use CadGestor\Nucleo\Ambiente;
use CadGestor\Seguranca\CifradorBiometria;

/** @var Ambiente $ambiente */
$ambiente = require __DIR__ . '/inicializar.php';

$caminho = CifradorBiometria::resolverCaminho($ambiente->texto('CHAVE_CIFRA_ARQUIVO'), dirname(__DIR__));

if (in_array('--rotacionar', $argv, true)) {
    $atual = CifradorBiometria::doArquivo($caminho)->versaoAtual();
    file_put_contents($caminho, CifradorBiometria::gerarLinhaChave($atual + 1) . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo 'Nova versão de chave: ' . ($atual + 1) . ". Novos cadastros já usam a nova chave.\n";
    exit(0);
}

if (file_exists($caminho)) {
    fwrite(STDERR, "Arquivo de chave já existe: {$caminho}\nUse --rotacionar para criar uma nova versão.\n");
    exit(1);
}

if (!is_dir(dirname($caminho))) {
    mkdir(dirname($caminho), 0700, true);
}

$conteudo = "# CadGestor - chaves de cifra de biometria. NÃO versionar. Fazer backup seguro.\n"
    . CifradorBiometria::gerarLinhaChave(1) . PHP_EOL;

file_put_contents($caminho, $conteudo, LOCK_EX);
@chmod($caminho, 0600);

echo "Chave criada em {$caminho}\n";
echo "Restrinja o acesso ao arquivo (no Windows: icacls) e faça backup separado do banco.\n";
