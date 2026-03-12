<?php

namespace App\Mail;

use App\Models\Usuario;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SolicitudRechazada extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Usuario $usuario,
        public readonly string  $motivo = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '❌ Solicitud de acceso no aprobada — ' . $this->usuario->tenant?->nombre,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.solicitud-rechazada',
        );
    }
}
