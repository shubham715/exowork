<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;

class AdminEventMail extends Mailable
{
    public function __construct(private MailMessage $message) {}

    public function build(): self
    {
        return $this->subject($this->message->subject)
            ->markdown('emails.admin-event', $this->message->data());
    }
}
