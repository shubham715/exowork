<?php

namespace App\Mail;

use App\Services\ManagedEmailTemplate;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CandidateInvitation extends Mailable
{
    public function __construct(public string $centerName, public ?string $partnerName, public string $registrationUrl) {}

    private function managed(): ?array
    {
        return app(ManagedEmailTemplate::class)->render('candidate_invitation', [
            'center_name' => $this->centerName, 'partner_name' => $this->partnerName,
            'registration_url' => $this->registrationUrl,
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->managed()['subject'] ?? 'You are invited to register with '.$this->centerName.' | EXOWORK');
    }

    public function content(): Content
    {
        $message = $this->managed();

        return $message ? new Content(view: 'emails.managed', text: 'emails.managed-text', with: ['messageBody' => $message['body']]) : new Content(view: 'emails.candidate-invitation', text: 'emails.candidate-invitation-text');
    }
}
