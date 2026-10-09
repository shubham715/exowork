<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Services\WhatsAppCloud;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WhatsAppController extends Controller
{
    public function admin(Request $request, bool $send = false): void
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'active' && DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $user->id)->whereIn('roles.key', ['admin', 'superadmin'])->exists() && $user->hasPermission($send ? 'candidates.update' : 'candidates.view'), 403);
    }

    private function candidate(Request $request, string $code, bool $send = false): array
    {
        $employer = null;
        if ($request->is('admin-api/*')) {
            $this->admin($request, $send);
        } else {
            abort_unless($request->user()?->status === 'active', 403);
            $employer = app(EmployerWorkspaceController::class)->employer($request);
            abort_unless($employer->status === 'verified', 403, 'Company verification is required for WhatsApp messaging.');
        }
        $candidate = Candidate::whereNull('deleted_at')->where('candidate_code', $code)->firstOrFail();
        if ($employer) {
            if ($request->filled('job')) {
                $job = DB::table('job_posts')->where('employer_id', $employer->id)->whereNull('deleted_at')->find((int) $request->input('job'));
                abort_unless($job && in_array($job->status, ['active', 'published']) && (!$job->application_deadline || $job->application_deadline >= now('Asia/Kolkata')->toDateString()), 404);
                abort_unless($candidate->profile_status === 'active' && $candidate->terms_accepted_at && $candidate->privacy_accepted_at && app(\App\Services\JobEligibility::class)->matches($job, $candidate), 404);
                abort_if(DB::table('job_candidate_skips')->where('job_post_id', $job->id)->where('candidate_id', $candidate->id)->exists(), 404);
                return [$candidate, $employer];
            }
            abort_unless(DB::table('applications as a')->join('job_posts as j', 'j.id', '=', 'a.job_post_id')->where('a.candidate_id', $candidate->id)->where('j.employer_id', $employer->id)->whereNull('j.deleted_at')->whereExists(fn ($q) => $q->selectRaw('1')->from('interviews as i')->whereColumn('i.application_id', 'a.id'))->exists(), 404);
        }

        return [$candidate, $employer];
    }

    public function index(Request $request)
    {
        return app(AdminCandidateController::class)->index($request);
    }

    public function show(Request $request, string $code)
    {
        [$candidate] = $this->candidate($request, $code);

        return response()->json(['candidate' => ['photo_url' => $candidate->photo_path ? '/admin-api/candidates/'.rawurlencode($candidate->candidate_code).'/photo' : null, ...$candidate->only(['candidate_code', 'first_name', 'last_name', 'whatsapp', 'whatsapp_consent', 'qualification', 'district', 'state', 'skills', 'experience_type', 'availability', 'preferred_industry', 'training_center', 'created_at', 'email', 'profile_status', 'expected_monthly_salary', 'training_status', 'batch_code', 'languages', 'preferred_locations', 'relocation_preference'])]]);
    }

    public function context(Request $request, string $code, WhatsAppCloud $cloud)
    {
        [$candidate, $employer] = $this->candidate($request, $code);
        $messages = DB::table('whatsapp_messages')->where('candidate_id', $candidate->id)->when($employer, fn ($q) => $q->where('employer_id', $employer->id))->orderByDesc('id')->limit(20)->get(['id', 'template_name', 'language', 'status', 'error_code', 'created_at', 'updated_at']);
        $missing = $cloud->missing();
        $templates = [];
        $error = null;
        if (! $missing) {
            try {
                $templates = $cloud->templates();
                $templates = array_values(array_filter($templates, function ($t) {
                    $local = DB::table('whatsapp_templates')->where('name', $t['name'])->where('language', $t['language'])->first();
                    return ! $local || ($local->review_status === 'approved' && $local->account_id === (string) config('whatsapp.business_account_id') && $local->body === $t['body']);
                }));
            } catch (HttpException|ConnectionException $e) {
                $error = 'Unable to load approved templates from Meta. Check your credentials and network, then refresh.';
            }
        }

        return response()->json(['candidate' => ['code' => $candidate->candidate_code, 'name' => trim($candidate->first_name.' '.$candidate->last_name), 'consent' => $candidate->whatsapp_consent], 'configured' => ! $missing, 'missing' => $missing, 'templates' => $templates, 'template_error' => $error, 'messages' => $messages]);
    }

    public function send(Request $request, string $code, WhatsAppCloud $cloud)
    {
        [$candidate, $employer] = $this->candidate($request, $code, true);
        abort_unless($candidate->profile_status === 'active', 422, 'Activate this candidate profile before sending WhatsApp messages.');
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'template_name' => ['required', 'string', 'max:512'], 'language' => ['required', 'string', 'max:20'], 'parameters' => ['present', 'array', 'list', 'max:20'], 'parameters.*' => ['required', 'string', 'max:1024', 'not_regex:/[\r\n\t]/']]);
        $existing = DB::table('whatsapp_messages')->where('request_id', $data['request_id'])->first();
        if ($existing) {
            abort_unless($existing->candidate_id === $candidate->id && $existing->actor_user_id === $request->user()->id, 409);

            return $this->result($existing);
        }
        if (! $candidate->whatsapp_consent) {
            throw ValidationException::withMessages(['candidate' => 'Candidate has not consented to WhatsApp messages.']);
        }
        $recipient = $cloud->recipient($candidate->whatsapp);
        $template = collect($cloud->templates())->first(fn ($t) => $t['name'] === $data['template_name'] && $t['language'] === $data['language']);
        if (! $template || $template['parameter_count'] !== count($data['parameters'])) {
            throw ValidationException::withMessages(['template_name' => 'Choose an approved supported template and fill every variable in order.']);
        }
        abort_if(DB::table('whatsapp_messages')->where('recipient', $recipient)->where('created_at', '>', now()->subSeconds(6))->exists(), 429, 'Wait at least six seconds before messaging this candidate again.');
        $record = app(\App\Services\WhatsAppOutbox::class)->enqueue($recipient, $template, $data['parameters'], [
            'candidate_id' => $candidate->id, 'actor_user_id' => $request->user()->id, 'employer_id' => $employer?->id, 'request_id' => $data['request_id'], 'source' => 'manual',
        ]);
        abort_unless($record->candidate_id === $candidate->id && $record->actor_user_id === $request->user()->id, 409);
        DB::table('audit_logs')->insert(['actor_user_id' => $request->user()->id, 'action' => 'whatsapp.queued', 'subject_type' => 'candidate', 'subject_id' => $candidate->id, 'changes' => json_encode(['message_id' => $record->id, 'template' => $template['name']]), 'ip_address' => $request->ip(), 'created_at' => now()]);
        return $this->result($record);
    }

    private function result(object $record)
    {
        return response()->json(['message' => ['id' => $record->id, 'status' => $record->status, 'error_code' => $record->error_code], 'notice' => match ($record->status) {
            'queued' => 'Message queued. The worker will check consent and Meta approval before sending.',
            'skipped' => 'Message suppressed because recipient consent or eligibility changed.',
            'failed' => 'Meta rejected this message. Check the error code and setup guide.',
            'unknown', 'submitting' => 'Submission is uncertain. Do not resend until you check delivery in WhatsApp Manager.',
            default => 'Meta accepted the message. Delivery and read status will update through webhooks.',
        }], in_array($record->status, ['queued', 'skipped', 'failed', 'unknown', 'submitting']) ? 202 : 200);
    }
}
