<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Link para confirmar o e-mail de uma conta nova (cadastro pelo site). */
class ConfirmacaoEmail extends Mailable
{
    public function __construct(
        public readonly string $nome,
        public readonly string $link,
        public readonly int $horas,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirme seu e-mail no CadGestor');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.confirmacao-email', with: [
            'primeiroNome' => explode(' ', trim($this->nome))[0],
        ]);
    }
}
