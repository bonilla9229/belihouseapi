<?php

namespace App\Mail;

use App\Models\Usuario;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CredencialesAprobado extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Usuario $usuario,
        public readonly string  $plainPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '✅ Tu acceso ha sido aprobado — ' . $this->usuario->tenant?->nombre,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.credenciales-aprobado',
        );
    }
}
