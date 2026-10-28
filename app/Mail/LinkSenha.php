<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Link para criar uma senha: redefinição ("esqueci minha senha" ou enviada
 * pela plataforma) ou primeira senha de uma conta nova.
 */
class LinkSenha extends Mailable
{
    public function __construct(
        public readonly string $nome,
        public readonly string $link,
        public readonly int $minutos,
        public readonly bool $contaNova = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->contaNova ? 'Seu acesso ao CadGestor' : 'Redefinição de senha do CadGestor');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.link-senha', with: [
            'primeiroNome' => explode(' ', trim($this->nome))[0],
            'validade' => $this->minutos >= 120 ? intdiv($this->minutos, 60).' horas' : $this->minutos.' minutos',
        ]);
    }
}
