<?php

namespace App\Mail;

use App\Models\Usuario;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NuevaSolicitudAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Usuario $admin,
        public readonly Usuario $solicitante,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🔔 Nueva solicitud de acceso pendiente — ' . $this->solicitante->tenant?->nombre,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.nueva-solicitud-admin',
        );
    }
}
