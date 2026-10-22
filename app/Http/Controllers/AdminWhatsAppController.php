<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppCloud;
use App\Services\WhatsAppOutbox;
use App\Services\WhatsAppTemplateCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminWhatsAppController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->status === 'active' && $request->user()->hasPermission('masters.manage')
            && DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $request->user()->id)->whereIn('roles.key', ['admin', 'superadmin'])->exists(), 403);
    }

    private function audit(Request $request, string $action, int $id, array $changes = []): void
    {
        DB::table('audit_logs')->insert(['actor_user_id' => $request->user()->id, 'action' => 'whatsapp.'.$action, 'subject_type' => 'whatsapp', 'subject_id' => $id, 'changes' => json_encode($changes), 'ip_address' => $request->ip(), 'created_at' => now()]);
    }

    public function templates(Request $request, WhatsAppCloud $cloud)
    {
        $this->authorizeAdmin($request);
        $saved = DB::table('whatsapp_templates')->get()->keyBy('system_key');
        return response()->json(['templates' => array_map(fn ($t) => [...$t, ...(array) $saved[$t['key']], 'account_matches' => $saved[$t['key']]->account_id === $cloud->accountId()], WhatsAppTemplateCatalog::all()), 'provider' => config('whatsapp.provider'), 'configured' => ! $cloud->missing(), 'missing' => $cloud->missing(), 'automation' => app(\App\Services\CommunicationSettings::class)->get('whatsapp_workflow')])->header('Cache-Control', 'no-store');
    }

    public function workflow(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['career_video_url' => 'nullable|url:http,https|max:1024', 'preparation_video_url' => 'nullable|url:http,https|max:1024']);
        $settings = app(\App\Services\CommunicationSettings::class);
        $settings->save('whatsapp_workflow', array_replace($settings->get('whatsapp_workflow'), $data));
        $this->audit($request, 'workflow_settings', 0);
        return response()->json(['message' => 'Video links saved.']);
    }

    public function sync(Request $request, WhatsAppCloud $cloud)
    {
        $this->authorizeAdmin($request);
        $remote = collect($cloud->manageTemplates());
        foreach (WhatsAppTemplateCatalog::all() as $spec) {
            $saved = DB::table('whatsapp_templates')->where('system_key', $spec['key'])->first();
            if ($saved && ($saved->name !== $spec['name'] || $saved->body !== $spec['body'])) {
                DB::table('whatsapp_templates')->where('id', $saved->id)->update(['name' => $spec['name'], 'body' => $spec['body'], 'language' => $spec['language'], 'category' => $spec['category'], 'review_status' => 'pending', 'updated_at' => now()]);
            }
        }
        foreach (DB::table('whatsapp_templates')->get() as $t) {
            $meta = $remote->first(fn ($r) => $r['name'] === $t->name && $r['language'] === $t->language);
            $body = $meta ? (collect($meta['components'] ?? [])->firstWhere('type', 'BODY')['text'] ?? '') : '';
            $supported = $meta && ($meta['parameter_format'] ?? 'POSITIONAL') === 'POSITIONAL' && (config('whatsapp.provider') === 'gupshup' ? ($meta['supported'] ?? false) : count($meta['components'] ?? []) === 1);
            $status = $meta ? ($body === $t->body && $supported ? $meta['status'] : 'CONTENT_MISMATCH') : 'NOT_SUBMITTED';
            DB::table('whatsapp_templates')->where('id', $t->id)->update(['meta_id' => $meta['id'] ?? null, 'meta_status' => $status, 'category' => $meta['category'] ?? $t->category, 'review_status' => $t->account_id && $t->account_id !== $cloud->accountId() ? 'pending' : $t->review_status, 'account_id' => $cloud->accountId(), 'synced_at' => now(), 'updated_at' => now()]);
        }
        $this->audit($request, 'templates_synced', 0);
        return $this->templates($request, $cloud);
    }

    public function review(Request $request, int $id, WhatsAppCloud $cloud)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])]]);
        $t = DB::table('whatsapp_templates')->find($id);
        abort_unless($t, 404);
        if ($data['status'] === 'approved') {
            $this->sync($request, $cloud);
            $t = DB::table('whatsapp_templates')->find($id);
            abort_unless($t->meta_status === 'APPROVED', 422, 'Meta must approve this exact template content and language before you enable it. Submit the template, then sync Meta status.');
        }
        DB::table('whatsapp_templates')->where('id', $id)->update(['review_status' => $data['status'], 'updated_at' => now()]);
        $this->audit($request, 'template_'.$data['status'], $id);
        return response()->json(['message' => 'Template '. $data['status'].'.']);
    }

    public function submit(Request $request, int $id, WhatsAppCloud $cloud)
    {
        $this->authorizeAdmin($request);
        $t = DB::table('whatsapp_templates')->find($id);
        abort_unless($t, 404);
        $spec = WhatsAppTemplateCatalog::get($t->system_key);
        // Refresh first to prevent duplicate submissions or overwriting an approved template.
        $this->sync($request, $cloud);
        $fresh = DB::table('whatsapp_templates')->find($id);
        abort_if($fresh->meta_id, 422, 'This name already exists in Meta. Review it in WhatsApp Manager and sync its status.');
        $result = $cloud->submitTemplate(['name' => $t->name, 'language' => $t->language, 'category' => $t->category, 'body' => $t->body, 'examples' => $spec['examples']]);
        DB::table('whatsapp_templates')->where('id', $id)->update(['meta_id' => $result['id'] ?? null, 'meta_status' => $result['status'] ?? 'PENDING', 'account_id' => $cloud->accountId(), 'review_status' => 'pending', 'updated_at' => now()]);
        $this->audit($request, 'template_submitted', $id);
        return response()->json(['message' => 'Submitted to Meta for review.']);
    }

    public function test(Request $request, int $id, WhatsAppCloud $cloud, WhatsAppOutbox $outbox)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['request_id' => 'required|uuid', 'number' => 'required|string|max:30', 'consent' => 'accepted', 'parameters' => 'present|array|list|max:20', 'parameters.*' => 'required|string|max:1024|not_regex:/[\r\n\t]/']);
        $existing = DB::table('whatsapp_messages')->where('request_id', $data['request_id'])->first();
        if ($existing) {
            abort_unless($existing->actor_user_id === $request->user()->id && $existing->source === 'test', 409);
            return response()->json(['message' => $this->message($existing)]);
        }
        $t = DB::table('whatsapp_templates')->find($id);
        abort_unless($t, 404);
        abort_unless($t->review_status === 'approved', 422, 'Approve this template before testing.');
        $template = collect($cloud->templates())->first(fn ($r) => $r['name'] === $t->name && $r['language'] === $t->language && $r['body'] === $t->body);
        abort_unless($template && $template['parameter_count'] === count($data['parameters']), 422, 'Meta approval or template variables do not match. Sync and try again.');
        $number = $cloud->recipient($data['number']);
        abort_if(DB::table('whatsapp_opt_outs')->where('recipient', $number)->exists(), 422, 'This number has opted out.');
        $record = $outbox->enqueue($number, $template, $data['parameters'], ['request_id' => $data['request_id'], 'actor_user_id' => $request->user()->id, 'source' => 'test']);
        $this->audit($request, 'test_queued', $record->id);
        return response()->json(['message' => $this->message($record)], 202);
    }

    private function message(object $r): array
    {
        return [...collect((array) $r)->except(['payload', 'has_retry'])->all(), 'can_resend' => $r->status === 'failed' && (bool) $r->payload && ! ($r->has_retry ?? DB::table('whatsapp_messages')->where('retry_of', $r->id)->exists())];
    }

    public function logs(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['search' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(['queued', 'submitting', 'accepted', 'sent', 'delivered', 'read', 'failed', 'unknown', 'skipped'])], 'template' => 'nullable|string|max:100', 'source' => 'nullable|string|max:80', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'page' => 'nullable|integer|min:1']);
        $q = DB::table('whatsapp_messages as m')->leftJoin('candidates as c', 'c.id', '=', 'm.candidate_id')->leftJoin('users as u', 'u.id', '=', 'm.actor_user_id');
        if (! empty($data['search'])) {
            $needle = '%'.addcslashes($data['search'], '%_\\').'%';
            $q->where(fn ($q) => $q->where('m.recipient', 'like', $needle)->orWhere('m.template_name', 'like', $needle)->orWhere('c.candidate_code', 'like', $needle)->orWhere('c.first_name', 'like', $needle)->orWhere('c.last_name', 'like', $needle));
        }
        foreach (['status', 'source'] as $key) if (! empty($data[$key])) $q->where('m.'.$key, $data[$key]);
        if (! empty($data['template'])) $q->where('m.template_name', $data['template']);
        if (! empty($data['from'])) $q->where('m.created_at', '>=', \Carbon\Carbon::parse($data['from'], 'Asia/Kolkata')->utc());
        if (! empty($data['to'])) $q->where('m.created_at', '<', \Carbon\Carbon::parse($data['to'], 'Asia/Kolkata')->addDay()->utc());
        $rows = $q->orderByDesc('m.id')->select('m.*', 'c.first_name', 'c.last_name', 'c.candidate_code', 'u.name as actor_name')->selectSub(DB::table('whatsapp_messages as retry')->selectRaw('count(*)')->whereColumn('retry.retry_of', 'm.id'), 'has_retry')->paginate(20);
        $rows->through(fn ($r) => $this->message($r));
        return response()->json(['logs' => $rows, 'counts' => DB::table('whatsapp_messages')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $r = DB::table('whatsapp_messages')->find($id);
        abort_unless($r, 404);
        return response()->json(['message' => $this->message($r)])->header('Cache-Control', 'no-store');
    }

    public function replies(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100']);
        $rows = DB::table('whatsapp_inbound_messages as m')->leftJoin('candidates as c', 'c.id', '=', 'm.candidate_id')
            ->when(! empty($data['search']), function ($q) use ($data) {
                $needle = '%'.addcslashes($data['search'], '%_\\').'%';
                $q->where(fn ($q) => $q->where('m.sender', 'like', $needle)->orWhere('c.candidate_code', 'like', $needle));
            })->select('m.id', 'm.sender', 'm.type', 'm.content', 'm.outcome', 'm.created_at', 'c.first_name', 'c.last_name', 'c.candidate_code')
            ->orderByDesc('m.id')->paginate(20);
        $rows->through(function ($r) { $r->content = Crypt::decryptString($r->content); return $r; });
        return response()->json(['replies' => $rows])->header('Cache-Control', 'no-store, private');
    }

    public function resend(Request $request, WhatsAppOutbox $outbox, WhatsAppCloud $cloud)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['ids' => 'required|array|min:1|max:50', 'ids.*' => 'required|integer|distinct|min:1']);
        $results = [];
        foreach ($data['ids'] as $id) {
            $result = DB::transaction(function () use ($id, $request, $outbox, $cloud) {
                $r = DB::table('whatsapp_messages')->where('id', $id)->lockForUpdate()->first();
                if (! $r || $r->status !== 'failed' || ! $r->payload) return ['id' => $id, 'queued' => false, 'reason' => 'Only failed messages with saved variables can be resent.'];
                if ($r->provider !== config('whatsapp.provider', 'meta') || ($r->provider_account_id && $r->provider_account_id !== $cloud->accountId())) return ['id' => $id, 'queued' => false, 'reason' => 'The sender account changed. Create a new reviewed message.'];
                if (DB::table('whatsapp_messages')->where('retry_of', $id)->exists()) return ['id' => $id, 'queued' => false, 'reason' => 'A retry already exists. Open the latest attempt.'];
                if (DB::table('whatsapp_opt_outs')->where('recipient', $r->recipient)->exists()) return ['id' => $id, 'queued' => false, 'reason' => 'Recipient opted out.'];
                if ($r->candidate_id) {
                    $c = DB::table('candidates')->find($r->candidate_id);
                    if (! $c || $c->deleted_at || ! $c->whatsapp_consent || $c->profile_status !== 'active' || $cloud->recipient($c->whatsapp) !== $r->recipient) return ['id' => $id, 'queued' => false, 'reason' => 'Candidate consent, phone or profile changed.'];
                }
                $next = $outbox->enqueue($r->recipient, ['name' => $r->template_name, 'language' => $r->language, 'category' => $r->category], json_decode(Crypt::decryptString($r->payload), true)['parameters'], ['actor_user_id' => $request->user()->id, 'candidate_id' => $r->candidate_id, 'employer_id' => $r->employer_id, 'training_center_id' => $r->training_center_id, 'source' => $r->source, 'retry_of' => $r->id, 'event_key' => 'retry:'.$r->id]);
                $this->audit($request, 'resend_queued', $next->id, ['original_id' => $r->id]);
                return ['id' => $id, 'queued' => true, 'new_id' => $next->id];
            });
            $results[] = $result;
        }
        return response()->json(['results' => $results], 202);
    }
}
