<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso ao administrador do cliente: o limite do plano foi atingido. */
class LimiteDoPlano extends Mailable
{
    public function __construct(
        public readonly string $plano,
        public readonly string $recurso,
        public readonly int $limite,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Limite do seu plano no CadGestor foi atingido');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.limite-do-plano');
    }
}
