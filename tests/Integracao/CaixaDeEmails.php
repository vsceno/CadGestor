<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Mensagens\Mensageiro;

/**
 * Mensageiro de teste: guarda os e-mails em memória.
 */
final class CaixaDeEmails implements Mensageiro
{
    /** @var list<array{para: string, assunto: string, texto: string}> */
    public array $enviados = [];

    public function enviar(string $para, string $assunto, string $texto): void
    {
        $this->enviados[] = ['para' => $para, 'assunto' => $assunto, 'texto' => $texto];
    }

    /** Token do último link com o caminho informado (ex.: "redefinir-senha"). */
    public function ultimoToken(string $caminho): ?string
    {
        foreach (array_reverse($this->enviados) as $email) {
            if (preg_match('#/' . preg_quote($caminho, '#') . '\?token=([0-9a-f]{64})#', $email['texto'], $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
