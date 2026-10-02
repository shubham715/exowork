<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppCloud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $token = (string) config('whatsapp.verify_token');
        abort_unless($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppCloud $cloud)
    {
        $secret = (string) config('whatsapp.app_secret');
        abort_unless($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature-256')), 403);
        if ($request->input('object') !== 'whatsapp_business_account') {
            return response()->json(['received' => true]);
        }
        foreach ($request->input('entry', []) as $entry) {
            if ((string) ($entry['id'] ?? '') !== (string) config('whatsapp.business_account_id')) {
                continue;
            }
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if (($change['field'] ?? '') !== 'messages' || (string) ($value['metadata']['phone_number_id'] ?? '') !== (string) config('whatsapp.phone_number_id')) {
                    continue;
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    if (! is_string($status['id'] ?? null) || ! in_array($status['status'] ?? '', ['sent', 'delivered', 'read', 'failed']) || ! ctype_digit((string) ($status['timestamp'] ?? ''))) {
                        continue;
                    }
                    DB::transaction(function () use ($status) {
                        DB::table('whatsapp_receipts')->insertOrIgnore(['provider_message_id' => $status['id'], 'status' => $status['status'], 'status_timestamp' => (int) $status['timestamp'], 'error_code' => isset($status['errors'][0]['code']) ? substr((string) $status['errors'][0]['code'], 0, 40) : null, 'created_at' => now(), 'updated_at' => now()]);
                        $receipt = DB::table('whatsapp_receipts')->where('provider_message_id', $status['id'])->lockForUpdate()->first();
                        if ($this->advances($receipt, $status['status'], (int) $status['timestamp'])) {
                            DB::table('whatsapp_receipts')->where('provider_message_id', $status['id'])->update(['status' => $status['status'], 'status_timestamp' => (int) $status['timestamp'], 'error_code' => isset($status['errors'][0]['code']) ? substr((string) $status['errors'][0]['code'], 0, 40) : null, 'updated_at' => now()]);
                        }
                        $this->reconcile($status['id']);
                    });
                }
                foreach ($value['messages'] ?? [] as $message) {
                    $text = trim(mb_strtoupper($message['text']['body'] ?? $message['button']['text'] ?? $message['interactive']['button_reply']['title'] ?? ''));
                    if (! in_array($text, ['STOP', 'UNSUBSCRIBE', 'CANCEL', 'STOP WHATSAPP'])) {
                        continue;
                    }
                    try {
                        $recipient = $cloud->recipient((string) ($message['from'] ?? ''));
                    } catch (ValidationException $e) {
                        continue;
                    }
                    $local = str_starts_with($recipient, (string) config('whatsapp.default_country_code')) ? substr($recipient, strlen((string) config('whatsapp.default_country_code'))) : $recipient;
                    DB::table('candidates')->where(fn ($q) => $q->where('whatsapp', $recipient)->orWhere('whatsapp', '+'.$recipient)->orWhere('whatsapp', $local))->whereNull('deleted_at')->update(['whatsapp_consent' => false, 'updated_at' => now()]);
                }
            }
        }

        return response()->json(['received' => true]);
    }

    private function advances(object $old, string $status, int $timestamp): bool
    {
        $rank = ['submitting' => 0, 'unknown' => 0, 'accepted' => 0, 'sent' => 1, 'failed' => 1, 'delivered' => 2, 'read' => 3];

        return $timestamp >= $old->status_timestamp && ($rank[$status] ?? 0) >= ($rank[$old->status] ?? 0);
    }

    public function reconcile(string $providerId): void
    {
        DB::transaction(function () use ($providerId) {
            $receipt = DB::table('whatsapp_receipts')->where('provider_message_id', $providerId)->first();
            $record = DB::table('whatsapp_messages')->where('provider_message_id', $providerId)->lockForUpdate()->first();
            if ($record && $receipt && $this->advances($record, $receipt->status, $receipt->status_timestamp)) {
                DB::table('whatsapp_messages')->where('id', $record->id)->update(['status' => $receipt->status, 'status_timestamp' => $receipt->status_timestamp, 'error_code' => $receipt->error_code, 'updated_at' => now()]);
            }
        });
    }
}
