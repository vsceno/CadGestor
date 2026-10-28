<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ConexaoBanco;
use CadGestor\Nucleo\Log;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use PDOException;

/**
 * Verificação de saúde (health check) para monitoramento.
 *
 * Responde só "está de pé ou não", sem versões nem detalhes de infraestrutura.
 */
final class SaudeController
{
    public function __construct(
        private readonly ConexaoBanco $banco,
        private readonly Log $log,
    ) {
    }

    /** @param array<string, string> $parametros */
    public function verificar(Requisicao $requisicao, array $parametros): Resposta
    {
        try {
            $schema = $this->banco->pdo()->query('SELECT current_schema()')->fetchColumn();
        } catch (PDOException $erro) {
            $this->log->erro('Health check: banco indisponível', ['sqlstate' => (string) $erro->getCode()]);

            return Resposta::json(['status' => 'erro', 'banco' => 'indisponivel'], 503);
        }

        if ($schema === null || $schema === false) {
            return Resposta::json(['status' => 'erro', 'banco' => 'conectado', 'schema' => 'ausente'], 503);
        }

        return Resposta::json(['status' => 'ok', 'banco' => 'conectado', 'schema' => $schema]);
    }
}
