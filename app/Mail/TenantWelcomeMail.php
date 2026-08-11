<?php

namespace App\Mail;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de bienvenida al completar el onboarding del tenant (Fase 7.8,
 * disparado desde TenantOnboardingController::finishTeams()).
 */
class TenantWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Client $client,
        public string $recipientEmail
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: TenantMailSender::resolve($this->client),
            subject: "¡Bienvenido a Tikara, {$this->client->name}!",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.tenant-welcome',
            with: [
                'client' => $this->client,
                'portalUrl' => $this->portalUrl(),
            ],
        );
    }

    private function portalUrl(): ?string
    {
        if (! $this->client->portal_slug || ! config('tenancy.base_domain')) {
            return null;
        }

        $scheme = config('tenancy.portal_scheme', 'https');

        return "{$scheme}://{$this->client->portal_slug}.".config('tenancy.base_domain');
    }
}
