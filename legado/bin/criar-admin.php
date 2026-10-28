<?php

/**
 * Cria o administrador inicial da PLATAFORMA (cadastra organizações e os
 * administradores de cada uma). A senha é gerada e exibida UMA vez.
 *
 * Uso: php bin/criar-admin.php --nome="Maria Souza" --email=maria@empresa.com.br
 */

declare(strict_types=1);

use CadGestor\Nucleo\Ambiente;
use CadGestor\Nucleo\ConexaoBanco;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Servicos\CriacaoAdministradorInicial;

/** @var Ambiente $ambiente */
$ambiente = require __DIR__ . '/inicializar.php';

$opcoes = getopt('', ['nome:', 'email:']);

if (!isset($opcoes['nome'], $opcoes['email']) || !is_string($opcoes['nome']) || !is_string($opcoes['email'])) {
    fwrite(STDERR, "Uso: php bin/criar-admin.php --nome=\"Nome Completo\" --email=email@dominio\n");
    exit(1);
}

$servico = new CriacaoAdministradorInicial(ConexaoBanco::paraMigracao($ambiente)->pdo(), new HashSenha());
$resultado = $servico->criar($opcoes['nome'], $opcoes['email']);

echo <<<TXT

    Administrador da plataforma criado.

      E-mail: {$opcoes['email']}
      Senha provisória: {$resultado['senha']}

    Anote a senha agora: ela não é gravada em lugar nenhum e não será
    exibida de novo. A troca será exigida no primeiro login.

    TXT;
