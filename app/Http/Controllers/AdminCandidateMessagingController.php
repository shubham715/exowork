<?php

namespace App\Http\Controllers;

use App\Jobs\SendCandidateEmailCampaign;
use App\Models\Candidate;
use App\Services\CampaignEmailSender;
use App\Services\CandidateJobOpportunity;
use App\Services\CandidateMessageContent;
use App\Services\CandidateTemplateResolver;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppOutbox;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCandidateMessagingController extends Controller
{
    private function authorize(Request $request, bool $send = false, ?string $channel = null): void
    {
        app(WhatsAppController::class)->admin($request, $send);
        if ($send && $channel === 'email') {
            abort_unless($request->user()->hasPermission('masters.manage'), 403, 'Email sending permission is required.');
        }
    }

    private function candidates()
    {
        return Candidate::whereNull('deleted_at');
    }

    public function index(Request $request)
    {
        $this->authorize($request);
        $data = $request->validate(['search' => 'nullable|string|max:150', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|in:25,50,100']);
        $query = $this->candidates();
        if ($search = trim($data['search'] ?? '')) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($q) use ($search) {
                foreach (['first_name', 'last_name', 'email', 'whatsapp', 'candidate_code'] as $field) {
                    $q->orWhereRaw("LOWER($field) LIKE ? ESCAPE '!'", [$search]);
                }
                $name = DB::getDriverName() === 'mysql' ? "CONCAT(first_name, ' ', last_name)" : "first_name || ' ' || last_name";
                $q->orWhereRaw("LOWER($name) LIKE ? ESCAPE '!'", [$search]);
            });
        }
        $page = $query->select(['id', 'candidate_code', 'first_name', 'last_name', 'email', 'whatsapp', 'created_at', 'profile_status', 'email_consent', 'whatsapp_consent'])->orderByDesc('id')->paginate($data['per_page'] ?? 25);
        $page->getCollection()->each(fn ($c) => $c->setAttribute('registered_on', $c->created_at?->timezone('Asia/Kolkata')->format('F j, Y')));

        return response()->json([...$page->toArray(), 'can_whatsapp' => $request->user()->hasPermission('candidates.update'), 'can_email' => $request->user()->hasPermission('candidates.update') && $request->user()->hasPermission('masters.manage'),
            'counts' => ['all' => $this->candidates()->count(), 'whatsapp' => $this->candidates()->where('profile_status', 'active')->where('whatsapp_consent', true)->count(), 'email' => $this->candidates()->where('profile_status', 'active')->where('email_consent', true)->whereNotNull('email')->count()]])->header('Cache-Control', 'private, no-store');
    }

    private function templates(WhatsAppCloud $cloud): array
    {
        $templates = array_values(array_filter($cloud->templates(), function ($template) use ($cloud) {
            if (($template['category'] ?? '') === 'AUTHENTICATION') {
                return false;
            }
            $local = DB::table('whatsapp_templates')->where('name', $template['name'])->where('language', $template['language'])->first();

            return ! $local || ($local->review_status === 'approved' && $local->account_id === $cloud->accountId() && $local->body === $template['body']);
        }));
        $resolver = app(CandidateTemplateResolver::class);

        return array_values(array_filter(array_map(fn ($template) => $resolver->describe($template), $templates), fn ($template) => ($template['context_mode'] ?? '') !== 'unsupported'));
    }

    public function jobs(Request $request, CandidateJobOpportunity $jobs)
    {
        $this->authorize($request, true);
        abort_unless($request->user()->hasPermission('jobs.view'), 403);
        $data = $request->validate(['search' => 'nullable|string|max:150', 'template_key' => ['nullable', Rule::in(['job_opportunity', 'interview_availability', 'interview_scheduled', 'interview_reminder', 'selection_update', 'joining_update', 'retention_day_7', 'retention_day_15', 'retention_day_30'])]]);
        $query = $jobs->query(($data['template_key'] ?? 'job_opportunity') === 'job_opportunity');
        if ($search = trim($data['search'] ?? '')) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($q) use ($search, $term) {
                if (ctype_digit($search)) {
                    $q->orWhere('j.id', (int) $search);
                }
                foreach (['j.title', 'j.district', 'j.state'] as $field) {
                    $q->orWhereRaw("LOWER($field) LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }

        return response()->json(['jobs' => $query->orderByDesc('j.id')->limit(50)->get()->map(fn ($job) => $jobs->snapshot($job))])->header('Cache-Control', 'private, no-store');
    }

    public function context(Request $request, WhatsAppCloud $cloud)
    {
        $this->authorize($request);
        $channel = $request->validate(['channel' => 'required|in:email,whatsapp'])['channel'];
        $this->authorize($request, true, $channel);
        $templates = [];
        $error = null;
        if ($channel === 'whatsapp' && ! $cloud->missing()) {
            try {
                $templates = $this->templates($cloud);
            } catch (\Throwable $e) {
                $error = 'Approved templates could not be loaded. Check WhatsApp settings and try again.';
            }
        }

        return response()->json(['fields' => CandidateMessageContent::FIELDS, 'job_fields' => CandidateJobOpportunity::FIELDS, 'templates' => $templates,
            'configured' => $channel === 'email' ? config('mail.default') === 'smtp' : ! $cloud->missing(), 'template_error' => $error])->header('Cache-Control', 'private, no-store');
    }

    private function eligible(Candidate $candidate, string $channel, WhatsAppCloud $cloud): ?string
    {
        if ($candidate->deleted_at || $candidate->profile_status !== 'active') {
            return 'Profile is inactive or unavailable.';
        }
        if (! $candidate->{$channel === 'email' ? 'email_consent' : 'whatsapp_consent'}) {
            return 'No recorded '.$channel.' consent.';
        }
        if ($channel === 'email') {
            return filter_var($candidate->email, FILTER_VALIDATE_EMAIL) ? null : 'A valid email address is missing.';
        }
        try {
            $recipient = $cloud->recipient($candidate->whatsapp);
        } catch (\Throwable $e) {
            return 'A valid WhatsApp number is missing.';
        }

        return DB::table('whatsapp_opt_outs')->where('recipient', $recipient)->exists() ? 'Candidate opted out of WhatsApp.' : null;
    }

    public function preview(Request $request, WhatsAppCloud $cloud)
    {
        $data = $request->validate(['channel' => 'required|in:email,whatsapp', 'request_key' => 'required|uuid', 'ids' => 'required|array|list|min:1|max:500', 'ids.*' => 'required|integer|distinct',
            'inputs' => 'sometimes|array|max:5', 'inputs.career_video_url' => 'nullable|string|max:1024', 'job_id' => 'nullable|integer|min:1', 'template_name' => 'nullable|string|max:512', 'language' => 'nullable|string|max:20', 'variables' => 'sometimes|array|list|max:20', 'variables.*.field' => ['required', Rule::in([...array_keys(CandidateMessageContent::FIELDS), ...array_keys(CandidateJobOpportunity::FIELDS), 'custom'])], 'variables.*.value' => 'nullable|string|max:1024',
            'subject' => 'nullable|string|max:255', 'body' => 'nullable|string|max:30000']);
        $this->authorize($request, true, $data['channel']);
        if ($old = DB::table('candidate_message_batches')->find($data['request_key'])) {
            abort_unless($old->user_id === $request->user()->id, 404);
            abort_unless($old->status === 'preview' && $old->expires_at >= now()->toDateTimeString(), 422, 'Create a new preview.');

            return $this->previewResponse($old);
        }
        $template = null;
        $job = null;
        if ($data['channel'] === 'whatsapp') {
            $template = collect($this->templates($cloud))->first(fn ($t) => $t['name'] === ($data['template_name'] ?? '') && $t['language'] === ($data['language'] ?? ''));
            abort_unless($template, 422, 'Select an approved template.');
            if ($template['context_mode'] === 'automatic') {
                if ($template['requires_job']) {
                    abort_unless($request->user()->hasPermission('jobs.view'), 403);
                    $request->validate(['job_id' => 'required|integer|min:1']);
                    $job = app(CandidateJobOpportunity::class)->find((int) $data['job_id'], $template['system_key'] === 'job_opportunity');
                    abort_unless($job, 422, 'Select an available job from a verified employer.');
                }
                if ($template['system_key'] === 'career_intro') {
                    $request->validate(['inputs.career_video_url' => 'required|url:http,https|max:1024']);
                }
            } else {
                abort_unless(count($data['variables'] ?? []) === $template['parameter_count'], 422, 'Configure every template variable.');
                if (array_intersect(array_column($data['variables'] ?? [], 'field'), array_keys(CandidateJobOpportunity::FIELDS))) {
                    abort_unless($request->user()->hasPermission('jobs.view'), 403);
                    $request->validate(['job_id' => 'required|integer|min:1']);
                    $job = app(CandidateJobOpportunity::class)->find((int) $data['job_id']);
                    abort_unless($job, 422, 'Select an active job from a verified employer.');
                }
            }
        } else {
            abort_unless(config('mail.default') === 'smtp', 422, 'Enable SMTP in communication settings before sending email.');
            $request->validate(['subject' => 'required|string|max:255|not_regex:/[\r\n]/', 'body' => 'required|string|max:30000']);
            // Reject unsupported placeholders before resolving individual candidates.
            preg_match_all('/\{\{\s*(.*?)\s*\}\}/', $data['subject'].' '.$data['body'], $tokens);
            foreach ($tokens[1] as $token) {
                if (! array_key_exists(trim($token), CandidateMessageContent::FIELDS)) {
                    throw ValidationException::withMessages(['body' => 'Unknown placeholder {{'.$token.'}}. Use the listed placeholders.']);
                }
            }
        }
        $candidates = $this->candidates()->whereIn('id', $data['ids'])->get()->keyBy('id');
        abort_unless($candidates->count() === count($data['ids']), 422, 'Some selected candidates are unavailable. Refresh the list.');
        $rows = [];
        $seen = [];
        foreach ($data['ids'] as $id) {
            $candidate = $candidates[$id];
            $values = CandidateMessageContent::values($candidate);
            $row = ['id' => $id, 'name' => $values['name'], 'recipient' => $data['channel'] === 'email' ? $candidate->email : $candidate->whatsapp, 'body' => '', 'subject' => '', 'parameters' => [], 'error' => $this->eligible($candidate, $data['channel'], $cloud), 'request_id' => (string) Str::uuid()];
            try {
                if ($data['channel'] === 'whatsapp') {
                    $row['recipient'] = $cloud->recipient($candidate->whatsapp);
                    if ($template['context_mode'] === 'automatic') {
                        $resolved = app(CandidateTemplateResolver::class)->resolve($template['system_key'], $candidate, $job['id'] ?? null, $data['inputs'] ?? []);
                        $row['parameters'] = $resolved['parameters'];
                        $row['template_context'] = ['key' => $template['system_key'], 'job_id' => $job['id'] ?? null, 'inputs' => $data['inputs'] ?? [], 'resolved' => $resolved];
                    }
                    foreach ($template['context_mode'] === 'automatic' ? [] : ($data['variables'] ?? []) as $variable) {
                        $value = trim((string) ($variable['field'] === 'custom' ? ($variable['value'] ?? '') : (isset(CandidateJobOpportunity::FIELDS[$variable['field']]) ? CandidateJobOpportunity::value($job, $variable['field']) : ($values[$variable['field']] ?? ''))));
                        if ($value === '' || mb_strlen($value) > 1024 || preg_match('/[\r\n\t]/', $value)) {
                            throw ValidationException::withMessages(['variables' => 'A template variable is missing or invalid for this candidate.']);
                        }
                        $row['parameters'][] = $value;
                    }
                    $row['body'] = preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $row['parameters'][(int) $m[1] - 1] ?? $m[0], $template['body']);
                } else {
                    $row['subject'] = str_replace(["\r", "\n"], ' ', CandidateMessageContent::render($data['subject'], $values));
                    if (mb_strlen($row['subject']) > 255) {
                        throw ValidationException::withMessages(['subject' => 'Personalized subject exceeds 255 characters.']);
                    }
                    $row['body'] = CandidateMessageContent::render($data['body'], $values);
                }
            } catch (ValidationException $e) {
                $row['error'] ??= implode(' ', array_merge(...array_values($e->errors())));
            }
            $recipient = strtolower((string) $row['recipient']);
            if (! $row['error'] && isset($seen[$recipient])) {
                $row['error'] = 'Duplicate recipient in this selection.';
            }
            if (! $row['error']) {
                $seen[$recipient] = true;
            }
            $rows[] = $row;
        }
        DB::table('candidate_message_batches')->insertOrIgnore(['id' => $data['request_key'], 'user_id' => $request->user()->id, 'channel' => $data['channel'], 'status' => 'preview', 'payload' => Crypt::encryptString(json_encode(['rows' => $rows, 'template' => $template, 'job' => $job, 'account_id' => $cloud->accountId(), 'provider' => config('whatsapp.provider', 'meta'), 'subject' => $data['subject'] ?? '', 'body' => $data['body'] ?? ''], JSON_THROW_ON_ERROR)), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        $batch = DB::table('candidate_message_batches')->find($data['request_key']);
        abort_unless($batch && $batch->user_id === $request->user()->id, 404);

        return $this->previewResponse($batch);
    }

    private function previewResponse(object $batch)
    {
        $payload = json_decode(Crypt::decryptString($batch->payload), true, 512, JSON_THROW_ON_ERROR);
        $rows = array_map(fn ($row) => Arr::except($row, ['request_id', 'template_context']), $payload['rows']);

        return response()->json(['id' => $batch->id, 'channel' => $batch->channel, 'job' => $payload['job'] ?? null, 'rows' => $rows, 'ready' => count(array_filter($rows, fn ($r) => ! $r['error'])), 'skipped' => count(array_filter($rows, fn ($r) => (bool) $r['error']))])->header('Cache-Control', 'private, no-store');
    }

    private function batch(Request $request, string $id): object
    {
        $batch = DB::table('candidate_message_batches')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($batch, 404);
        $this->authorize($request, true, $batch->channel);

        return $batch;
    }

    public function send(Request $request, string $id, WhatsAppCloud $cloud)
    {
        $this->authorize($request, true);
        $batch = $this->batch($request, $id);
        if ($batch->status === 'submitted') {
            return $this->status($request, $id);
        }
        abort_unless($batch->expires_at >= now()->toDateTimeString(), 422, 'Preview expired. Review the messages again.');
        $payload = json_decode(Crypt::decryptString($batch->payload), true, 512, JSON_THROW_ON_ERROR);
        if ($batch->channel === 'whatsapp') {
            $fresh = collect($this->templates($cloud))->first(fn ($t) => $t['name'] === $payload['template']['name'] && $t['language'] === $payload['template']['language']);
            abort_unless($payload['account_id'] === $cloud->accountId() && $payload['provider'] === config('whatsapp.provider', 'meta') && $fresh && $fresh['body'] === $payload['template']['body'], 422, 'Template or sender changed. Create a new preview.');
        } else {
            abort_unless(config('mail.default') === 'smtp', 422, 'SMTP is not enabled.');
        }
        if ($payload['job'] ?? null) {
            abort_unless($request->user()->hasPermission('jobs.view'), 403);
            abort_unless(app(CandidateJobOpportunity::class)->find((int) $payload['job']['id'], (($payload['template']['system_key'] ?? '') === 'job_opportunity' || ($payload['template']['context_mode'] ?? '') === 'manual')) === $payload['job'], 422, 'Job availability or details changed. Select the job and preview again.');
        }
        DB::transaction(function () use ($request, $id, $cloud, $payload) {
            $batch = DB::table('candidate_message_batches')->where('id', $id)->lockForUpdate()->first();
            if ($batch->status === 'submitted') {
                return;
            }
            abort_unless($batch->expires_at >= now()->toDateTimeString(), 422, 'Preview expired. Review the messages again.');
            $result = ['queued' => 0, 'skipped' => 0, 'campaign_id' => null, 'message_ids' => [], 'rows' => []];
            foreach ($payload['rows'] as $row) {
                $candidate = $this->candidates()->find($row['id']);
                $reason = $row['error'] ?: (! $candidate ? 'Candidate unavailable.' : $this->eligible($candidate, $batch->channel, $cloud));
                if (! $reason && isset($row['template_context'])) {
                    $context = $row['template_context'];
                    try {
                        if (app(CandidateTemplateResolver::class)->resolve($context['key'], $candidate, $context['job_id'], $context['inputs']) !== $context['resolved']) {
                            $reason = 'Candidate or related record changed since preview. Review again.';
                        }
                    } catch (ValidationException $e) {
                        $reason = implode(' ', array_merge(...array_values($e->errors())));
                    }
                }
                $recipient = $candidate && ! $reason ? ($batch->channel === 'email' ? $candidate->email : $cloud->recipient($candidate->whatsapp)) : null;
                if (! $reason && $recipient !== $row['recipient']) {
                    $reason = 'Contact details changed after preview.';
                }
                if ($reason) {
                    $result['skipped']++;
                    $result['rows'][] = ['id' => $row['id'], 'name' => $row['name'], 'status' => 'skipped', 'error' => $reason];

                    continue;
                }
                if ($batch->channel === 'whatsapp') {
                    $record = app(WhatsAppOutbox::class)->enqueue($row['recipient'], $payload['template'], $row['parameters'], ['candidate_id' => $candidate->id, 'actor_user_id' => $request->user()->id, 'request_id' => $row['request_id'], 'source' => 'manual', 'preview_body' => $payload['template']['body'], 'job_snapshot' => (($payload['template']['system_key'] ?? '') === 'job_opportunity' || ($payload['template']['context_mode'] ?? '') === 'manual') ? ($payload['job'] ?? null) : null, 'template_context' => $row['template_context'] ?? null]);
                    $result['message_ids'][] = $record->id;
                } else {
                    if (! $result['campaign_id']) {
                        $result['campaign_id'] = DB::table('email_campaigns')->insertGetId(['request_key' => $id, 'created_by' => $request->user()->id, 'subject' => $payload['subject'], 'body' => $payload['body'], 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('email_deliveries')->insert(['campaign_id' => $result['campaign_id'], 'email' => $row['recipient'], 'name' => $row['name'], 'recipient_type' => 'candidate', 'recipient_id' => $candidate->id, 'status' => 'pending', 'rendered_payload' => Crypt::encryptString(json_encode(['subject' => $row['subject'], 'body' => $row['body']], JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);
                }
                $result['queued']++;
            }
            DB::table('candidate_message_batches')->where('id', $id)->update(['status' => 'submitted', 'result' => json_encode($result), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['actor_user_id' => $request->user()->id, 'action' => 'candidate.messages_submitted', 'subject_type' => 'candidate_message_batch', 'subject_id' => $batch->audit_id, 'changes' => json_encode(['batch' => $id, 'channel' => $batch->channel, 'queued' => $result['queued'], 'skipped' => $result['skipped']]), 'ip_address' => $request->ip(), 'created_at' => now()]);
            if ($result['campaign_id']) {
                SendCandidateEmailCampaign::dispatch($result['campaign_id'])->afterCommit();
            }
        });

        return $this->status($request, $id);
    }

    public function process(Request $request, string $id)
    {
        $this->authorize($request, true, 'email');
        $batch = $this->batch($request, $id);
        abort_unless($batch->channel === 'email' && $batch->status === 'submitted', 422, 'Submit the email preview before processing.');
        abort_unless(config('mail.default') === 'smtp', 422, 'SMTP is not enabled.');
        $result = json_decode($batch->result ?? '{}', true);
        if ($result['campaign_id'] ?? null) {
            app(CampaignEmailSender::class)->process($result['campaign_id']);
        }

        return $this->status($request, $id);
    }

    public function status(Request $request, string $id)
    {
        $this->authorize($request, true);
        $batch = $this->batch($request, $id);
        $result = json_decode($batch->result ?? '{}', true);
        if ($batch->channel === 'whatsapp') {
            $deliveries = DB::table('whatsapp_messages')->whereIn('id', $result['message_ids'] ?? [])->get(['candidate_id as id', 'status', 'error_message as error']);
        } else {
            $deliveries = DB::table('email_deliveries')->where('campaign_id', $result['campaign_id'] ?? 0)->get(['recipient_id as id', 'name', 'status', 'error']);
        }
        $rows = array_merge($result['rows'] ?? [], $deliveries->map(fn ($r) => (array) $r)->all());

        return response()->json(['id' => $id, 'channel' => $batch->channel, 'status' => $batch->status, 'queued' => $result['queued'] ?? 0, 'skipped' => $result['skipped'] ?? 0, 'rows' => $rows])->header('Cache-Control', 'private, no-store');
    }
}
