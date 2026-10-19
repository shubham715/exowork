<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppCloud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class GupshupWebhookController extends Controller
{
    public function receive(Request $request, WhatsAppCloud $cloud)
    {
        $secret = (string) config('whatsapp.gupshup_webhook_secret');
        abort_unless(config('whatsapp.provider') === 'gupshup' && strlen($secret) >= 32
            && hash_equals($secret, (string) $request->header('X-Exowork-Webhook-Token')), 403);
        abort_unless($request->input('app') === config('whatsapp.gupshup_app_name') && $request->input('version') === 2, 422);
        if ($request->has('phone')) abort_unless((string) $request->input('phone') === (string) config('whatsapp.gupshup_source'), 422);
        abort_if(strlen($request->getContent()) > 262144, 413);
        $p = $request->input('payload');
        abort_unless(is_array($p) && is_array($p['payload'] ?? []) && is_numeric($request->input('timestamp')), 422);
        $account = $cloud->accountId();
        if ($request->input('type') === 'message-event') {
            $this->receipt($request, $p, $account);
        } elseif ($request->input('type') === 'message') {
            $this->message($request, $p, $account, $cloud);
        }
        return response()->json(['received' => true]);
    }

    private function receipt(Request $request, array $p, string $account): void
    {
        $status = $p['type'] ?? '';
        if (! in_array($status, ['enqueued', 'sent', 'delivered', 'read', 'failed'])) return;
        $id = $p['gsId'] ?? $p['id'] ?? null;
        if (! is_string($id) || strlen($id) > 255) return;
        $metaId = $status === 'enqueued' ? ($p['payload']['whatsappMessageId'] ?? null) : (isset($p['gsId']) ? ($p['id'] ?? null) : null);
        DB::transaction(function () use ($id, $metaId, $account, $status, $p, $request) {
            if (is_string($metaId) && strlen($metaId) <= 255) DB::table('whatsapp_provider_aliases')->insertOrIgnore(['meta_message_id' => $metaId, 'provider_message_id' => $id, 'account_id' => $account]);
            $alias = DB::table('whatsapp_provider_aliases')->where('meta_message_id', $id)->where('account_id', $account)->value('provider_message_id');
            $providerId = $alias ?: $id;
            $timestamp = $p['payload']['ts'] ?? floor((float) $request->input('timestamp', 0) / 1000);
            if (! is_numeric($timestamp) || $timestamp <= 0) return;
            $next = $status === 'enqueued' ? 'accepted' : $status;
            DB::table('whatsapp_receipts')->insertOrIgnore(['provider_message_id' => $providerId, 'status' => $next, 'status_timestamp' => (int) $timestamp, 'error_code' => null, 'created_at' => now(), 'updated_at' => now()]);
            $old = DB::table('whatsapp_receipts')->where('provider_message_id', $providerId)->lockForUpdate()->first();
            $rank = ['accepted' => 0, 'sent' => 1, 'failed' => 1, 'delivered' => 2, 'read' => 3];
            if ($timestamp >= $old->status_timestamp && $rank[$next] >= ($rank[$old->status] ?? 0)) {
                DB::table('whatsapp_receipts')->where('provider_message_id', $providerId)->update(['status' => $next, 'status_timestamp' => (int) $timestamp, 'error_code' => $next === 'failed' ? substr((string) ($p['payload']['code'] ?? 'GUPSHUP_FAILED'), 0, 40) : null, 'updated_at' => now()]);
            }
            app(WhatsAppWebhookController::class)->reconcile($providerId);
        });
    }

    private function message(Request $request, array $p, string $account, WhatsAppCloud $cloud): void
    {
        $id = $p['id'] ?? null;
        if (! is_string($id) || $id === '' || strlen($id) > 255 || ! is_string($p['source'] ?? null)) return;
        try { $sender = $cloud->recipient($p['source']); } catch (\Illuminate\Validation\ValidationException $e) { return; }
        $text = $p['payload']['text'] ?? $p['payload']['title'] ?? '';
        $postback = $p['payload']['postbackText'] ?? '';
        if (! is_string($text) || ! is_string($postback)) return;
        $context = $p['context']['gsId'] ?? $p['context']['id'] ?? null;
        if (! is_string($context) || strlen($context) > 255) $context = null;
        $timestamp = (int) floor((float) $request->input('timestamp', 0) / 1000);
        if ($timestamp <= 0 || $timestamp > now()->addMinutes(5)->timestamp) return;
        $local = str_starts_with($sender, (string) config('whatsapp.default_country_code')) ? substr($sender, strlen((string) config('whatsapp.default_country_code'))) : $sender;
        DB::transaction(function () use ($id, $account, $sender, $local, $text, $postback, $context, $timestamp, $p) {
            $candidate = DB::table('candidates')->whereNull('deleted_at')->where(fn ($q) => $q->where('whatsapp', $local)->orWhere('whatsapp', $sender)->orWhere('whatsapp', '+'.$sender))->lockForUpdate()->first();
            $inserted = DB::table('whatsapp_inbound_messages')->insertOrIgnore(['provider' => 'gupshup', 'account_id' => $account, 'provider_message_id' => $id, 'candidate_id' => $candidate?->id, 'sender' => $sender, 'type' => substr((string) ($p['type'] ?? 'unknown'), 0, 30), 'content' => Crypt::encryptString(mb_substr($text, 0, 10000)), 'context_id' => $context, 'message_timestamp' => $timestamp, 'outcome' => 'needs_review', 'created_at' => now(), 'updated_at' => now()]);
            if (! $inserted) return; // Provider retries must not reapply responses.
            $outcome = 'needs_review';
            if (in_array(mb_strtoupper(trim($text)), ['STOP', 'UNSUBSCRIBE', 'CANCEL', 'STOP WHATSAPP'])) {
                DB::table('whatsapp_opt_outs')->updateOrInsert(['recipient' => $sender], ['created_at' => now(), 'updated_at' => now()]);
                if ($candidate) DB::table('candidates')->where('id', $candidate->id)->update(['whatsapp_consent' => false, 'updated_at' => now()]);
                $centers = DB::table('training_centers')->whereNull('deleted_at')->where(fn ($q) => $q->where('spoc_phone', $sender)->orWhere('spoc_phone', '+'.$sender)->orWhere('spoc_phone', $local))->pluck('id');
                DB::table('whatsapp_center_preferences')->whereIn('training_center_id', $centers)->update(['consent' => false, 'updated_at' => now()]);
                $outcome = 'opted_out';
            } elseif ($candidate && $candidate->profile_status === 'active' && $candidate->whatsapp_consent
                && ! DB::table('whatsapp_opt_outs')->where('recipient', $sender)->exists()
                && preg_match('/^exowork:welcome:(\d+):(yes|no)$/', $postback, $match)) {
                $original = DB::table('whatsapp_messages')->where('id', $match[1])->where('provider', 'gupshup')->where('provider_account_id', $account)->where('candidate_id', $candidate->id)->where('recipient', $sender)->whereIn('source', ['welcome', 'manual'])->where('template_name', 'exowork_welcome_v1')->whereIn('status', ['submitting', 'accepted', 'unknown', 'sent', 'delivered', 'read'])->first();
                // Never infer interview/application decisions from an unscoped Yes/No. Do not overwrite a portal decision.
                if ($original && $candidate->job_interest_status === 'no_response'
                    && $timestamp >= \Carbon\Carbon::parse($original->created_at)->timestamp
                    && $timestamp <= \Carbon\Carbon::parse($original->created_at)->addDays(30)->timestamp) {
                    $outcome = $match[2] === 'yes' ? 'interested' : 'not_interested';
                    DB::table('candidates')->where('id', $candidate->id)->update(['job_interest_status' => $outcome, 'updated_at' => now()]);
                }
            }
            DB::table('whatsapp_inbound_messages')->where('account_id', $account)->where('provider_message_id', $id)->update(['outcome' => $outcome]);
        });
    }
}
