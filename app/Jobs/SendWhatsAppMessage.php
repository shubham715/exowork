<?php

namespace App\Jobs;

use App\Http\Controllers\WhatsAppWebhookController;
use App\Services\CommunicationSettings;
use App\Services\WhatsAppCloud;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public int $messageId) {}

    public function handle(WhatsAppCloud $cloud): void
    {
        app(CommunicationSettings::class)->apply();
        $record = DB::table('whatsapp_messages')->find($this->messageId);
        if (! $record || $record->status !== 'queued' || $record->available_at > now()->toDateTimeString()) return;
        // Atomic claim prevents duplicate jobs or simultaneous workers from double sending.
        if (! DB::table('whatsapp_messages')->where('id', $record->id)->where('status', 'queued')->update(['status' => 'submitting', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()])) return;
        $postStarted = false;
        try {
            if ($record->provider !== config('whatsapp.provider', 'meta') || ($record->provider_account_id && $record->provider_account_id !== $cloud->accountId())) {
                $this->finish(['status' => 'skipped', 'error_code' => 'PROVIDER_CHANGED', 'error_message' => 'The sender account changed after this message was queued.']); return;
            }
            if ($record->provider === 'gupshup' && ! config('whatsapp.gupshup_automation_enabled') && ! in_array($record->source, ['test', 'manual'])) {
                $this->finish(['status' => 'skipped', 'error_code' => 'AUTOMATION_PAUSED', 'error_message' => 'Automatic stage messages are paused.']); return;
            }
            if (DB::table('whatsapp_opt_outs')->where('recipient', $record->recipient)->exists()) {
                $this->finish(['status' => 'skipped', 'error_code' => 'OPT_OUT', 'error_message' => 'Recipient opted out of WhatsApp.']); return;
            }
            if ($record->candidate_id) {
                $candidate = DB::table('candidates')->find($record->candidate_id);
                if (! $candidate || $candidate->deleted_at || $candidate->profile_status !== 'active' || ! $candidate->whatsapp_consent || $cloud->recipient($candidate->whatsapp) !== $record->recipient) {
                    $this->finish(['status' => 'skipped', 'error_code' => 'CONSENT_OR_PROFILE', 'error_message' => 'Candidate unavailable, phone changed, or consent withdrawn.']); return;
                }
            }
            if ($record->candidate_id && in_array($record->source, ['career_intro', 'training_reconnect', 'job_opportunity']) && $candidate->job_interest_status !== 'interested') {
                $this->finish(['status' => 'skipped', 'error_code' => 'INTEREST_CHANGED', 'error_message' => 'Candidate is no longer interested in job outreach.']); return;
            }
            if ($record->candidate_id && in_array($record->source, ['career_intro', 'job_opportunity']) && (! in_array($candidate->availability, ['Yes', 'Available after training']) || ($candidate->training_status === 'Ongoing' && (! $candidate->batch_end_date || $candidate->batch_end_date > now('Asia/Kolkata')->addDays(15)->toDateString())))) {
                $this->finish(['status' => 'skipped', 'error_code' => 'READINESS_CHANGED', 'error_message' => 'Candidate is not currently ready for placement outreach.']); return;
            }
            if ($record->source === 'job_opportunity' && $record->candidate_id) {
                $original = $this->original($record);
                $jobId = explode(':', $original->event_key ?? '')[2] ?? null;
                $job = $jobId ? DB::table('job_posts')->whereNull('deleted_at')->whereIn('status', ['active', 'published'])->find($jobId) : null;
                $model = \App\Models\Candidate::find($candidate->id);
                if (! $job || ($job->application_deadline && $job->application_deadline < now('Asia/Kolkata')->toDateString()) || ! DB::table('employers')->where('id', $job->employer_id)->whereNull('deleted_at')->where('status', 'verified')->exists() || ! app(\App\Services\JobEligibility::class)->matches($job, $model)) {
                    $this->finish(['status' => 'skipped', 'error_code' => 'JOB_UNAVAILABLE', 'error_message' => 'Job or candidate eligibility changed before sending.']); return;
                }
            }
            if (in_array($record->source, ['interview_scheduled', 'interview_reminder', 'selection_update', 'joining_update', 'center_placement_update', 'retention_day_7', 'retention_day_15', 'retention_day_30']) && ! $this->eventStillCurrent($this->original($record))) {
                $this->finish(['status' => 'skipped', 'error_code' => 'WORKFLOW_CHANGED', 'error_message' => 'Interview, placement or employment status changed before this message was sent.']); return;
            }
            if ($record->training_center_id) {
                $center = DB::table('training_centers')->find($record->training_center_id);
                if (! $center || $center->deleted_at || ! DB::table('whatsapp_center_preferences')->where('training_center_id', $center->id)->where('consent', true)->exists() || $cloud->recipient($center->spoc_phone ?? '') !== $record->recipient) {
                    $this->finish(['status' => 'skipped', 'error_code' => 'CENTER_CONSENT', 'error_message' => 'Center contact changed or WhatsApp consent is missing.']); return;
                }
            }
            $local = DB::table('whatsapp_templates')->where('name', $record->template_name)->where('language', $record->language)->first();
            if ($local && ($local->review_status !== 'approved' || $local->account_id !== $cloud->accountId())) {
                $this->finish(['status' => 'failed', 'error_code' => 'TEMPLATE_DISABLED', 'error_message' => 'Administrator approval or account binding is missing.']); return;
            }
            $template = collect($cloud->templates())->first(fn ($t) => $t['name'] === $record->template_name && $t['language'] === $record->language);
            $parameters = json_decode(Crypt::decryptString($record->payload), true, 512, JSON_THROW_ON_ERROR)['parameters'];
            if (! $template || count($parameters) !== $template['parameter_count'] || ($local && $template['body'] !== $local->body)) {
                $this->finish(['status' => 'failed', 'error_code' => 'TEMPLATE_NOT_APPROVED', 'error_message' => 'Template is not approved, its content changed, or variables do not match.']); return;
            }
            $postStarted = true;
            $response = $cloud->send($record->recipient, $template, $parameters, $record->id);
            $providerId = $response->json(config('whatsapp.provider') === 'gupshup' ? 'messageId' : 'messages.0.id');
            if ($response->successful() && is_string($providerId) && $providerId !== '') {
                $this->finish(['status' => 'accepted', 'provider_message_id' => $providerId, 'error_code' => null, 'error_message' => null]);
                app(WhatsAppWebhookController::class)->reconcile($providerId);
            } else {
                $code = substr((string) $response->json('error.code', config('whatsapp.provider') === 'gupshup' ? 'GUPSHUP_RESPONSE' : 'META_RESPONSE'), 0, 40);
                $this->finish(['status' => $response->serverError() || $response->successful() ? 'unknown' : 'failed', 'error_code' => $code, 'error_message' => self::error($code)]);
            }
        } catch (ConnectionException $e) {
            $this->finish(['status' => $postStarted ? 'unknown' : 'failed', 'error_code' => 'NETWORK_TIMEOUT', 'error_message' => $postStarted ? 'No acceptance confirmation. Check Meta before sending again.' : 'Could not contact Meta to verify the template.']);
        } catch (\Throwable $e) {
            $this->finish(['status' => $postStarted ? 'unknown' : 'failed', 'error_code' => 'SEND_UNAVAILABLE', 'error_message' => 'Check API settings, template approval and queue configuration.']);
            report($e);
        }
    }

    public static function error(string $code): string
    {
        return match ($code) {
            '190' => 'Access token expired or invalid. Update API settings.',
            '131030' => 'Recipient is not allowed by this test sender. Add the number in Meta API Setup.',
            '132001' => 'Template name or language is unavailable. Sync approval from Meta.',
            '132000' => 'Template variables do not match the approved template.',
            '131026' => 'Message could not be delivered. Check that the number has WhatsApp.',
            '130429', '131048' => 'Meta rate or quality limit reached. Review WhatsApp Manager before retrying.',
            default => config('whatsapp.provider') === 'gupshup' ? 'Provider error '.$code.'. Review Gupshup delivery details, credentials and wallet balance.' : 'Meta error '.$code.'. Review WhatsApp Manager and the API setup helper.',
        };
    }

    private function original(object $record): object
    {
        for ($n = 0; $record->retry_of && $n < 100; $n++) {
            $parent = DB::table('whatsapp_messages')->find($record->retry_of);
            if (! $parent) break;
            $record = $parent;
        }
        return $record;
    }

    private function eventStillCurrent(object $record): bool
    {
        // Test/manual sends use administrator-entered values, without a workflow event.
        if (! $record->event_key) return true;
        $event = preg_replace('/^(?:center:|reminder:)/', '', $record->event_key);
        $parts = explode(':', $event);
        if (($parts[0] ?? '') === 'interview') {
            $interview = DB::table('interviews')->find($parts[1] ?? 0);
            return $interview && in_array($interview->status, ['scheduled', 'confirmed']) && $interview->scheduled_at > now()->toDateTimeString() && (string) \Carbon\Carbon::parse($interview->scheduled_at, 'UTC')->timestamp === ($parts[2] ?? '');
        }
        if (($parts[0] ?? '') === 'placement') {
            $placement = DB::table('placement_outcomes')->find($parts[1] ?? 0);
            return $placement && $placement->status === ($parts[2] ?? '') && ($placement->joined_on ?: $placement->expected_joining_on ?: 'To be confirmed') === ($parts[3] ?? '');
        }
        if (($parts[0] ?? '') === 'retention') {
            $placement = DB::table('placement_outcomes')->find($parts[1] ?? 0);
            return $placement && $placement->status === 'joined' && $placement->joined_on === ($parts[2] ?? '') && ! DB::table('retention_responses')->where('placement_outcome_id', $placement->id)->where('status', 'left')->exists();
        }
        return true;
    }

    private function finish(array $values): void
    {
        DB::table('whatsapp_messages')->where('id', $this->messageId)->where('status', 'submitting')->update([...$values, 'updated_at' => now()]);
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('whatsapp_messages')->where('id', $this->messageId)->whereIn('status', ['queued', 'submitting'])->update(['status' => 'unknown', 'error_code' => 'WORKER_INTERRUPTED', 'error_message' => 'Worker interrupted; check Meta before resending.', 'updated_at' => now()]);
    }
}
