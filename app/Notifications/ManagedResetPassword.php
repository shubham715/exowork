<?php

namespace App\Notifications;

use App\Services\ManagedEmailTemplate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ManagedResetPassword extends ResetPassword
{
    public function toMail($notifiable)
    {
        $message = app(ManagedEmailTemplate::class)->render('password_reset', [
            'name' => $notifiable->name, 'email' => $notifiable->getEmailForPasswordReset(),
            'reset_url' => $this->resetUrl($notifiable),
            'expiry_minutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
        ]);
        if (! $message) {
            return $this->buildMailMessage($this->resetUrl($notifiable));
        }

        return (new MailMessage)->subject($message['subject'])->view(['html' => 'emails.managed', 'text' => 'emails.managed-text'], ['messageBody' => $message['body']]);
    }
}
