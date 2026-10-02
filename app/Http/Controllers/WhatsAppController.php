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
            abort_unless(DB::table('applications as a')->join('job_posts as j', 'j.id', '=', 'a.job_post_id')->where('a.candidate_id', $candidate->id)->where('j.employer_id', $employer->id)->whereNull('j.deleted_at')->whereExists(fn ($q) => $q->selectRaw('1')->from('interviews as i')->whereColumn('i.application_id', 'a.id'))->exists(), 404);
        }

        return [$candidate, $employer];
    }

    public function index(Request $request)
    {
        $this->admin($request);
        $search = trim((string) $request->query('search', ''));

        return response()->json(Candidate::whereNull('deleted_at')->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')->orWhere('candidate_code', 'like', '%'.$search.'%')->orWhere('whatsapp', 'like', '%'.$search.'%')))->orderByDesc('id')->paginate(20, ['candidate_code', 'first_name', 'last_name', 'whatsapp', 'whatsapp_consent', 'qualification', 'district', 'preferred_industry', 'availability', 'training_center', 'updated_at']));
    }

    public function show(Request $request, string $code)
    {
        [$candidate] = $this->candidate($request, $code);

        return response()->json(['candidate' => $candidate->only(['candidate_code', 'first_name', 'last_name', 'whatsapp', 'whatsapp_consent', 'qualification', 'district', 'state', 'skills', 'experience_type', 'availability', 'preferred_industry', 'training_center', 'created_at'])]);
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
            } catch (HttpException|ConnectionException $e) {
                $error = 'Unable to load approved templates from Meta. Check your credentials and network, then refresh.';
            }
        }

        return response()->json(['candidate' => ['code' => $candidate->candidate_code, 'name' => trim($candidate->first_name.' '.$candidate->last_name), 'consent' => $candidate->whatsapp_consent], 'configured' => ! $missing, 'missing' => $missing, 'templates' => $templates, 'template_error' => $error, 'messages' => $messages]);
    }

    public function send(Request $request, string $code, WhatsAppCloud $cloud)
    {
        [$candidate, $employer] = $this->candidate($request, $code, true);
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
        $inserted = DB::transaction(function () use ($candidate, $request, $employer, $data, $recipient, $template) {
            $fresh = DB::table('candidates')->where('id', $candidate->id)->lockForUpdate()->first();
            abort_unless($fresh && ! $fresh->deleted_at && $fresh->whatsapp_consent, 422, 'Candidate WhatsApp consent is no longer active.');
            if (DB::table('whatsapp_messages')->where('request_id', $data['request_id'])->exists()) {
                return false;
            }
            abort_if(DB::table('whatsapp_messages')->where('recipient', $recipient)->where('created_at', '>', now()->subSeconds(6))->exists(), 429, 'Wait at least six seconds before messaging this candidate again.');

            return DB::table('whatsapp_messages')->insertOrIgnore([
                'candidate_id' => $candidate->id, 'actor_user_id' => $request->user()->id, 'employer_id' => $employer?->id,
                'request_id' => $data['request_id'], 'recipient' => $recipient, 'template_name' => $template['name'], 'language' => $template['language'], 'category' => $template['category'], 'status' => 'submitting', 'created_at' => now(), 'updated_at' => now(),
            ]) > 0;
        });
        $record = DB::table('whatsapp_messages')->where('request_id', $data['request_id'])->first();
        abort_unless($record && $record->candidate_id === $candidate->id && $record->actor_user_id === $request->user()->id, 409);
        if (! $inserted) {
            return $this->result($record);
        }
        try {
            $response = $cloud->send($recipient, $template, $data['parameters']);
            $providerId = $response->json('messages.0.id');
            $changes = $response->successful() && is_string($providerId) && $providerId !== ''
                ? ['status' => 'accepted', 'provider_message_id' => $providerId]
                : ['status' => $response->serverError() || $response->successful() ? 'unknown' : 'failed', 'error_code' => substr((string) $response->json('error.code', 'META_RESPONSE'), 0, 40)];
        } catch (ConnectionException $e) {
            $changes = ['status' => 'unknown', 'error_code' => 'NETWORK_TIMEOUT'];
        }
        DB::table('whatsapp_messages')->where('id', $record->id)->update([...$changes, 'updated_at' => now()]);
        if (isset($changes['provider_message_id'])) {
            app(WhatsAppWebhookController::class)->reconcile($changes['provider_message_id']);
        }
        DB::table('audit_logs')->insert(['actor_user_id' => $request->user()->id, 'action' => 'whatsapp.submitted', 'subject_type' => 'candidate', 'subject_id' => $candidate->id, 'changes' => json_encode(['message_id' => $record->id, 'template' => $template['name']]), 'ip_address' => $request->ip(), 'created_at' => now()]);

        return $this->result(DB::table('whatsapp_messages')->find($record->id));
    }

    private function result(object $record)
    {
        return response()->json(['message' => ['id' => $record->id, 'status' => $record->status, 'error_code' => $record->error_code], 'notice' => match ($record->status) {
            'failed' => 'Meta rejected this message. Check the error code and setup guide.',
            'unknown', 'submitting' => 'Submission is uncertain. Do not resend until you check delivery in WhatsApp Manager.',
            default => 'Meta accepted the message. Delivery and read status will update through webhooks.',
        }], in_array($record->status, ['failed', 'unknown', 'submitting']) ? 202 : 200);
    }
}
