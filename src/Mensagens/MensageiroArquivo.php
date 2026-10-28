<?php

declare(strict_types=1);

namespace CadGestor\Mensagens;

use RuntimeException;

/**
 * "Envia" o e-mail gravando um arquivo .eml em storage/emails (pasta fora do
 * git). Só para desenvolvimento: o container recusa este mensageiro em produção.
 */
final class MensageiroArquivo implements Mensageiro
{
    public function __construct(private readonly string $diretorio)
    {
    }

    public function enviar(string $para, string $assunto, string $texto): void
    {
        if (!is_dir($this->diretorio) && !mkdir($this->diretorio, 0o700, true) && !is_dir($this->diretorio)) {
            throw new RuntimeException('Não foi possível criar a pasta de e-mails.');
        }

        $conteudo = sprintf(
            "To: %s\r\nSubject: =?UTF-8?B?%s?=\r\nDate: %s\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n%s\r\n",
            str_replace(["\r", "\n"], '', $para),
            base64_encode($assunto),
            date(DATE_RFC2822),
            $texto,
        );

        $arquivo = sprintf('%s/%s-%s.eml', $this->diretorio, date('Ymd-His'), bin2hex(random_bytes(4)));
        file_put_contents($arquivo, $conteudo, LOCK_EX);
    }
}
