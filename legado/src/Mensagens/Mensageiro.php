<?php

declare(strict_types=1);

namespace CadGestor\Mensagens;

/**
 * Envio de e-mails transacionais (confirmação de cadastro, boas-vindas).
 *
 * Lembretes de cobrança ficam com o gateway de pagamento. O provedor real
 * (SMTP, Brevo, Amazon SES...) entra como outra implementação desta interface.
 */
interface Mensageiro
{
    public function enviar(string $para, string $assunto, string $texto): void;
}
