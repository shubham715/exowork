<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
class CandidateInvitation extends Mailable
{
    public function __construct(public string $centerName, public ?string $partnerName, public string $registrationUrl) {}
    public function envelope(): Envelope { return new Envelope(subject: 'You are invited to register with '.$this->centerName.' | EXOWORK'); }
    public function content(): Content { return new Content(view: 'emails.candidate-invitation', text: 'emails.candidate-invitation-text'); }
}
