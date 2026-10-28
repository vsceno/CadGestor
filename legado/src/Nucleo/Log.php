<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

/**
 * Log em arquivo, um por dia, em storage/logs.
 *
 * Regra da casa: nunca registrar dados pessoais (nome, CPF, e-mail, IP de
 * titular, biometria). Registre identificadores técnicos e o contexto mínimo.
 */
final class Log
{
    public function __construct(private readonly string $diretorio)
    {
    }

    /** @param array<string, scalar|null> $contexto */
    public function registrar(string $nivel, string $mensagem, array $contexto = []): void
    {
        if (!is_dir($this->diretorio) && !@mkdir($this->diretorio, 0775, true) && !is_dir($this->diretorio)) {
            return;
        }

        $linha = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($nivel),
            str_replace(["\r", "\n"], ' ', $mensagem),
            $contexto === [] ? '' : json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        @file_put_contents(
            $this->diretorio . '/app-' . gmdate('Y-m-d') . '.log',
            $linha,
            FILE_APPEND | LOCK_EX,
        );
    }

    /** @param array<string, scalar|null> $contexto */
    public function erro(string $mensagem, array $contexto = []): void
    {
        $this->registrar('erro', $mensagem, $contexto);
    }
}
