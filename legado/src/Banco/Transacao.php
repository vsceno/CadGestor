<?php

declare(strict_types=1);

namespace CadGestor\Banco;

use PDO;
use Throwable;

/**
 * Executa um bloco dentro de uma transação: ou tudo é gravado, ou nada.
 *
 * Se já houver uma transação aberta (ex.: chamada de dentro de outro
 * serviço), o bloco participa dela em vez de abrir outra.
 */
final class Transacao
{
    /**
     * @template T
     * @param callable(): T $operacao
     * @return T
     */
    public static function executar(PDO $pdo, callable $operacao): mixed
    {
        if ($pdo->inTransaction()) {
            return $operacao();
        }

        $pdo->beginTransaction();

        try {
            $resultado = $operacao();
            $pdo->commit();

            return $resultado;
        } catch (Throwable $erro) {
            $pdo->rollBack();
            throw $erro;
        }
    }
}
