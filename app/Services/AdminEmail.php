<?php

namespace App\Services;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AdminEmail
{
    public function send(string $event, string $name, string $path, array $details = [], bool $review = false): void
    {
        $recipient = trim((string) config('mail.admin_notification_email'));
        if ($recipient === '') {
            return;
        }

        $url = rtrim(config('app.url'), '/').$path;
        $mail = (new MailMessage)
            ->subject('EXOWORK | '.$event)
            ->greeting($event)
            ->line($name);

        foreach ($details as $label => $value) {
            $mail->line($label.': '.$value);
        }

        $mail->line($review
            ? 'Open the profile to review the details and record a decision.'
            : 'Open the admin panel to view the record.')
            ->action($review ? 'Open review' : 'View in admin panel', $url);

        DB::afterCommit(function () use ($mail, $recipient) {
            try {
                Mail::to($recipient)->send(new \App\Mail\AdminEventMail($mail));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
