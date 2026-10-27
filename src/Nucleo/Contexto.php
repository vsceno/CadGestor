<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

/**
 * Dados da requisição atual que vários serviços precisam (ex.: auditoria):
 * quem é o usuário e de qual IP ele veio.
 */
final class Contexto
{
    public ?string $usuarioId = null;

    public function __construct(public string $ip = '0.0.0.0')
    {
    }
}
