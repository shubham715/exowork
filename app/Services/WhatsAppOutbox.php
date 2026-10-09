<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsAppOutbox
{
    public function enqueue(string $recipient, array $template, array $parameters, array $context = []): object
    {
        $id = DB::transaction(function () use ($recipient, $template, $parameters, $context) {
            $key = $context['request_id'] ?? (string) Str::uuid();
            $previous = DB::table('whatsapp_messages')->where('recipient', $recipient)->orderByDesc('available_at')->lockForUpdate()->first();
            $available = \Carbon\Carbon::parse($context['available_at'] ?? now());
            if ($previous && $previous->available_at && \Carbon\Carbon::parse($previous->available_at)->addSeconds(6)->gt($available)) $available = \Carbon\Carbon::parse($previous->available_at)->addSeconds(6);
            $row = [
                'request_id' => $key, 'candidate_id' => $context['candidate_id'] ?? null,
                'actor_user_id' => $context['actor_user_id'] ?? null, 'employer_id' => $context['employer_id'] ?? null,
                'training_center_id' => $context['training_center_id'] ?? null, 'recipient' => $recipient,
                'template_name' => $template['name'], 'language' => $template['language'], 'category' => $template['category'],
                'payload' => Crypt::encryptString(json_encode(['parameters' => $parameters], JSON_THROW_ON_ERROR)),
                'source' => $context['source'] ?? 'manual', 'event_key' => $context['event_key'] ?? null,
                'retry_of' => $context['retry_of'] ?? null, 'status' => 'queued',
                'available_at' => $available, 'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('whatsapp_messages')->insertOrIgnore($row);
            $record = DB::table('whatsapp_messages')->where('request_id', $key)->first();
            if (! $record && ! empty($context['event_key'])) {
                $record = DB::table('whatsapp_messages')->where('event_key', $context['event_key'])->first();
            }
            abort_unless($record, 409, 'Unable to create message.');
            return $record->id;
        });
        // The scheduler also recovers committed outbox rows if dispatch is interrupted.
        $available = DB::table('whatsapp_messages')->where('id', $id)->value('available_at');
        SendWhatsAppMessage::dispatch($id)->delay(\Carbon\Carbon::parse($available))->afterCommit();
        return DB::table('whatsapp_messages')->find($id);
    }

    public function system(string $key, string $recipient, array $parameters, array $context): ?object
    {
        $template = DB::table('whatsapp_templates')->where('system_key', $key)->first();
        if (! $template || $template->review_status !== 'approved' || $template->meta_status !== 'APPROVED'
            || $template->account_id !== (string) config('whatsapp.business_account_id')) {
            return null;
        }
        return $this->enqueue($recipient, (array) $template, $parameters, [...$context, 'source' => $key]);
    }
}
