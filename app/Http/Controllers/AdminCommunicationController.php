<?php

namespace App\Http\Controllers;

use App\Services\CommunicationSettings;
use App\Services\WhatsAppCloud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCommunicationController extends Controller
{
    public function testGupshup(Request $request, CommunicationSettings $settings, \App\Services\GupshupWhatsApp $gupshup)
    {
        $this->authorizeAdmin($request);
        $settings->apply();
        abort_unless(config('whatsapp.provider') === 'gupshup', 422, 'Save Gupshup as the messaging provider first.');
        return response()->json($gupshup->testConnection())->header('Cache-Control', 'no-store');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->hasPermission('masters.manage'), 403);
    }

    public function index(Request $request, CommunicationSettings $settings)
    {
        $this->authorizeAdmin($request);
        $smtp = array_replace([
            'enabled' => config('mail.default') === 'smtp', 'host' => config('mail.mailers.smtp.host', ''),
            'port' => config('mail.mailers.smtp.port', 587), 'encryption' => config('mail.mailers.smtp.scheme') === 'smtps' || (int) config('mail.mailers.smtp.port') === 465 ? 'ssl' : 'tls',
            'username' => config('mail.mailers.smtp.username', ''), 'password' => config('mail.mailers.smtp.password'),
            'from_address' => config('mail.from.address'), 'from_name' => config('mail.from.name'),
        ], $settings->get('smtp'));
        $wa = config('whatsapp');
        foreach (['smtp' => ['password'], 'whatsapp' => ['access_token', 'app_secret', 'verify_token', 'gupshup_api_key', 'gupshup_customer_secret', 'gupshup_webhook_secret']] as $channel => $keys) {
            foreach ($keys as $key) {
                if ($channel === 'smtp') {
                    $smtp[$key.'_configured'] = ! empty($smtp[$key]);
                    unset($smtp[$key]);
                } else {
                    $wa[$key.'_configured'] = ! empty($wa[$key]);
                    unset($wa[$key]);
                }
            }
        }

        return response()->json(['smtp' => $smtp, 'whatsapp' => $wa, 'webhook_url' => url(config('whatsapp.provider') === 'gupshup' ? '/api/webhooks/gupshup' : '/api/webhooks/whatsapp'), 'templates' => DB::table('email_templates')->orderBy('name')->get()])->header('Cache-Control', 'no-store');
    }

    public function settings(Request $request, string $channel, CommunicationSettings $settings)
    {
        $this->authorizeAdmin($request);
        abort_unless(in_array($channel, ['smtp', 'whatsapp']), 404);
        $rules = $channel === 'smtp' ? [
            'enabled' => 'required|boolean', 'host' => 'required|string|max:253|regex:/^[a-zA-Z0-9.-]+$/',
            'port' => 'required|integer|between:1,65535', 'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => 'nullable|string|max:255', 'password' => 'nullable|string|max:4096',
            'from_address' => 'required|email|max:255', 'from_name' => 'required|string|max:100',
        ] : [
            'provider' => ['sometimes', Rule::in(['meta', 'gupshup'])],
            'gupshup_automation_enabled' => 'sometimes|boolean',
            'gupshup_app_id' => 'nullable|uuid', 'gupshup_app_name' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_]+$/',
            'gupshup_source' => 'nullable|string|regex:/^[1-9][0-9]{7,14}$/',
            'gupshup_api_key' => 'nullable|string|max:4096', 'gupshup_customer_secret' => 'nullable|string|max:4096',
            'gupshup_auth_mode' => ['sometimes', Rule::in(['auto', 'cat', 'api_key'])],
            'gupshup_webhook_secret' => 'nullable|string|min:32|max:255',
            'enabled' => 'required|boolean', 'api_version' => 'required|string|regex:/^v[0-9]+\.0$/',
            'phone_number_id' => 'nullable|string|max:40|regex:/^[0-9]+$/',
            'business_account_id' => 'nullable|string|max:40|regex:/^[0-9]+$/',
            'access_token' => 'nullable|string|max:4096', 'app_secret' => 'nullable|string|max:4096',
            'verify_token' => 'nullable|string|max:255', 'default_country_code' => 'required|string|regex:/^[1-9][0-9]{0,3}$/',
        ];
        $data = $request->validate($rules);
        $old = $channel === 'whatsapp' ? array_replace(config('whatsapp'), $settings->get($channel)) : array_replace(['password' => config('mail.mailers.smtp.password')], $settings->get($channel));
        foreach ($channel === 'smtp' ? ['password'] : ['access_token', 'app_secret', 'verify_token', 'gupshup_api_key', 'gupshup_customer_secret', 'gupshup_webhook_secret'] as $key) {
            if (empty($data[$key])) {
                $data[$key] = $old[$key] ?? null;
            }
        }
        if ($channel === 'whatsapp') {
            foreach (['provider', 'gupshup_app_id', 'gupshup_app_name', 'gupshup_source', 'gupshup_automation_enabled', 'gupshup_auth_mode'] as $key) $data[$key] = $data[$key] ?? $old[$key] ?? match ($key) { 'provider' => 'meta', 'gupshup_auth_mode' => 'auto', default => null };
        }
        if ($channel === 'whatsapp' && $data['enabled']) {
            $required = $data['provider'] === 'gupshup' ? ['gupshup_app_id', 'gupshup_app_name', 'gupshup_source', 'gupshup_webhook_secret'] : ['access_token', 'phone_number_id', 'business_account_id', 'app_secret', 'verify_token'];
            if ($data['provider'] === 'gupshup' && empty($data['gupshup_api_key']) && empty($data['gupshup_customer_secret'])) $required[] = 'gupshup_customer_secret';
            if ($data['provider'] === 'gupshup' && ($data['gupshup_auth_mode'] ?? 'auto') !== 'auto') $required[] = $data['gupshup_auth_mode'] === 'api_key' ? 'gupshup_api_key' : 'gupshup_customer_secret';
            foreach ($required as $key) {
                if (empty($data[$key])) {
                    throw ValidationException::withMessages([$key => 'Required before enabling WhatsApp.']);
                }
            }
        }
        if ($channel === 'whatsapp' && $data['provider'] === 'gupshup' && ! empty($data['gupshup_automation_enabled'])
            && (empty($old['gupshup_automation_enabled']) || ($old['provider'] ?? 'meta') !== 'gupshup')) {
            // Starting/resuming automation must not welcome a historical registration list.
            $settings->save('whatsapp_workflow', array_replace($settings->get('whatsapp_workflow'), ['start_at' => now()->toDateTimeString()]));
        }
        $settings->save($channel, $data);

        return response()->json(['message' => 'Settings saved.']);
    }

    public function saveTemplate(Request $request, ?int $id = null)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['name' => 'required|string|max:255', 'subject' => 'required|string|max:255', 'body' => 'required|string|max:30000', 'is_active' => 'required|boolean']);
        $systemKey = $id ? DB::table('email_templates')->where('id', $id)->value('system_key') : null;
        $extra = match ($systemKey) {
            'candidate_invitation' => ['center_name', 'partner_name', 'registration_url'],
            'password_reset' => ['reset_url', 'expiry_minutes'],
            default => [],
        };
        $this->validateTokens($data['subject'].' '.$data['body'], $extra, $systemKey === 'candidate_invitation' ? ['app_name'] : ['name', 'email', 'app_name']);
        $required = match ($systemKey) {
            'candidate_invitation' => 'registration_url', 'password_reset' => 'reset_url', default => null
        };
        if ($required && ! str_contains($data['body'], '{{'.$required.'}}')) {
            throw ValidationException::withMessages(['body' => 'Include {{'.$required.'}} in this system email.']);
        }
        if ($id) {
            abort_unless(DB::table('email_templates')->where('id', $id)->exists(), 404);
            DB::table('email_templates')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        } else {
            $id = DB::table('email_templates')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['id' => $id]);
    }

    public function deleteTemplate(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        abort_unless(DB::table('email_templates')->where('id', $id)->delete(), 404);

        return response()->json(['message' => 'Template deleted.']);
    }

    private function validateTokens(string $content, array $extra = [], array $base = ['name', 'email', 'app_name']): void
    {
        preg_match_all('/\{\{(.*?)\}\}/', $content, $matches);
        foreach ($matches[1] as $token) {
            if (! in_array($token, [...$base, ...$extra])) {
                throw ValidationException::withMessages(['body' => 'Use the placeholders listed for this template.']);
            }
        }
    }

    private function recipientsQuery(string $type)
    {
        if ($type === 'candidate') {
            return DB::table('candidates')->whereNull('deleted_at')->where('profile_status', 'active')->where('email_consent', true)->whereNotNull('email')->select('id', 'first_name', 'last_name', 'email');
        }

        return DB::table('users')->where('users.status', 'active')->whereExists(function ($q) use ($type) {
            $q->selectRaw('1')->from('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->whereColumn('user_roles.user_id', 'users.id')->where('roles.key', $type);
        })->select('users.id', 'users.name', 'users.email');
    }

    public function recipients(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['type' => ['required', Rule::in(['candidate', 'employer', 'training_partner', 'agent', 'admin', 'superadmin'])], 'search' => 'nullable|string|max:100']);
        $query = $this->recipientsQuery($data['type']);
        if (! empty($data['search'])) {
            $query->where(function ($q) use ($data) {
                $q->where('email', 'like', '%'.$data['search'].'%');
                if ($data['type'] === 'candidate') {
                    $q->orWhere('first_name', 'like', '%'.$data['search'].'%')->orWhere('last_name', 'like', '%'.$data['search'].'%');
                } else {
                    $q->orWhere('users.name', 'like', '%'.$data['search'].'%');
                }
            });
        }
        $rows = $query->orderBy('id')->paginate(50);
        $rows->through(fn ($r) => ['id' => $r->id, 'email' => $r->email, 'name' => $r->name ?? trim($r->first_name.' '.$r->last_name)]);

        return response()->json($rows);
    }

    private function requireSmtp(): void
    {
        abort_unless(config('mail.default') === 'smtp', 422, 'Enable and save SMTP settings before sending email.');
    }

    public function testSmtp(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['email' => 'required|email']);
        $this->requireSmtp();
        try {
            Mail::raw('Your EXOWORK SMTP settings are working.', fn ($mail) => $mail->to($data['email'])->subject('EXOWORK SMTP test'));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'SMTP test failed. Check host, port, encryption, credentials and sender verification.'], 422);
        }

        return response()->json(['message' => 'Test email accepted by SMTP. Check your inbox and spam folder.']);
    }

    public function createCampaign(Request $request)
    {
        $this->authorizeAdmin($request);
        $this->requireSmtp();
        $data = $request->validate([
            'request_key' => 'required|uuid', 'subject' => 'required|string|max:255', 'body' => 'required|string|max:30000',
            'recipients' => 'required|array|min:1|max:100', 'recipients.*.type' => ['required', Rule::in(['candidate', 'employer', 'training_partner', 'agent', 'admin', 'superadmin'])],
            'recipients.*.id' => 'required|integer|min:1',
        ]);
        $this->validateTokens($data['subject'].' '.$data['body']);
        $id = DB::transaction(function () use ($data, $request) {
            $existing = DB::table('email_campaigns')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->created_by === $request->user()->id, 403);

                return $existing->id;
            }
            $targets = [];
            foreach ($data['recipients'] as $target) {
                $row = $this->recipientsQuery($target['type'])->where('id', $target['id'])->first();
                if (! $row || ! filter_var($row->email, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['recipients' => 'A recipient is unavailable or has withdrawn email consent. Refresh the list.']);
                }
                $targets[strtolower($row->email)] = ['email' => $row->email, 'name' => $row->name ?? trim($row->first_name.' '.$row->last_name), 'recipient_type' => $target['type'], 'recipient_id' => $target['id']];
            }
            $id = DB::table('email_campaigns')->insertGetId(['request_key' => $data['request_key'], 'created_by' => $request->user()->id, 'subject' => $data['subject'], 'body' => $data['body'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($targets as $target) {
                DB::table('email_deliveries')->insert([...$target, 'campaign_id' => $id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            }

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function processCampaign(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $this->requireSmtp();
        $campaign = DB::table('email_campaigns')->where('id', $id)->first();
        abort_unless($campaign, 404);
        $delivery = DB::transaction(function () use ($id) {
            $row = DB::table('email_deliveries')->where('campaign_id', $id)->where('status', 'pending')->lockForUpdate()->first();
            if ($row) {
                DB::table('email_deliveries')->where('id', $row->id)->update(['status' => 'sending', 'updated_at' => now()]);
            }

            return $row;
        });
        if ($delivery) {
            $eligible = $this->recipientsQuery($delivery->recipient_type)->where('id', $delivery->recipient_id)->where('email', $delivery->email)->exists();
            $tokens = ['{{name}}' => $delivery->name, '{{email}}' => $delivery->email, '{{app_name}}' => config('app.name')];
            try {
                if ($eligible) {
                    $subject = str_replace(["\r", "\n"], ' ', strtr($campaign->subject, $tokens));
                    Mail::raw(strtr($campaign->body, $tokens), fn ($mail) => $mail->to($delivery->email)->subject($subject));
                    $status = 'accepted';
                    $error = null;
                } else {
                    $status = 'skipped';
                    $error = 'Recipient no longer eligible, email changed or consent withdrawn.';
                }
            } catch (\Throwable $e) {
                // SMTP may accept DATA before a connection is lost. Never automatically resend.
                $status = 'unknown';
                $error = 'SMTP did not confirm acceptance. Check provider logs before sending again.';
            }
            DB::table('email_deliveries')->where('id', $delivery->id)->update(['status' => $status, 'error' => $error, 'updated_at' => now()]);
        }

        return response()->json(['remaining' => DB::table('email_deliveries')->where('campaign_id', $id)->where('status', 'pending')->count(), 'deliveries' => DB::table('email_deliveries')->where('campaign_id', $id)->get()]);
    }

    public function history(Request $request)
    {
        $this->authorizeAdmin($request);

        return response()->json(DB::table('email_campaigns')->orderByDesc('id')->paginate(20)->through(function ($row) {
            $row->deliveries = DB::table('email_deliveries')->where('campaign_id', $row->id)->get();

            return $row;
        }));
    }

    public function whatsappTemplates(Request $request, WhatsAppCloud $cloud)
    {
        $this->authorizeAdmin($request);

        return response()->json(['templates' => $cloud->manageTemplates()]);
    }

    public function submitWhatsappTemplate(Request $request, WhatsAppCloud $cloud)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['id' => 'nullable|string|max:40|regex:/^[0-9]+$/', 'name' => 'required|string|max:100|regex:/^[a-z][a-z0-9_]*$/', 'language' => 'required|string|max:20|regex:/^[a-z]{2,3}(?:_[A-Z]{2})?$/', 'category' => ['required', Rule::in(['UTILITY', 'MARKETING'])], 'body' => 'required|string|max:1024', 'examples' => 'present|array|max:20', 'examples.*' => 'required|string|max:200']);
        if (! empty($data['id'])) {
            $existing = collect($cloud->manageTemplates())->firstWhere('id', $data['id']);
            abort_unless($existing && $existing['name'] === $data['name'] && $existing['language'] === $data['language'], 422, 'This template does not belong to the configured WhatsApp account.');
            abort_unless(count($existing['components'] ?? []) === 1 && ($existing['components'][0]['type'] ?? '') === 'BODY', 422, 'Edit templates with headers, media or buttons in WhatsApp Manager.');
        }
        preg_match_all('/\{\{(\d+)\}\}/', $data['body'], $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1])));
        sort($numbers);
        $leftover = preg_replace('/\{\{\d+\}\}/', '', $data['body']);
        if (($numbers && $numbers !== range(1, count($numbers))) || count($numbers) !== count($data['examples']) || str_contains($leftover, '{{') || str_contains($leftover, '}}')) {
            throw ValidationException::withMessages(['body' => 'Use consecutive placeholders {{1}}, {{2}}, etc. and provide one example for each.']);
        }

        return response()->json($cloud->submitTemplate($data), 201);
    }

    public function deleteWhatsappTemplate(Request $request, WhatsAppCloud $cloud)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['name' => 'required|string|max:100|regex:/^[a-z][a-z0-9_]*$/']);

        return response()->json($cloud->deleteTemplate($data['name']));
    }
}
