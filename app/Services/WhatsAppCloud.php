<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WhatsAppCloud
{
    public function accountId(): string
    {
        return (string) config(config('whatsapp.provider') === 'gupshup' ? 'whatsapp.gupshup_app_id' : 'whatsapp.business_account_id');
    }
    public function missing(): array
    {
        if (config('whatsapp.provider') === 'gupshup') return app(GupshupWhatsApp::class)->missing();
        $missing = [];
        if (! config('whatsapp.enabled')) {
            $missing[] = 'WHATSAPP_ENABLED';
        }
        foreach (['access_token', 'phone_number_id', 'business_account_id', 'app_secret', 'verify_token'] as $key) {
            if (! config('whatsapp.'.$key)) {
                $missing[] = 'WHATSAPP_'.strtoupper($key);
            }
        }
        if (! preg_match('/^v\d+\.0$/', (string) config('whatsapp.api_version'))) {
            $missing[] = 'WHATSAPP_API_VERSION';
        }
        foreach (['phone_number_id', 'business_account_id'] as $key) {
            if (config('whatsapp.'.$key) && ! ctype_digit((string) config('whatsapp.'.$key))) {
                $missing[] = 'WHATSAPP_'.strtoupper($key);
            }
        }

        return array_unique($missing);
    }

    public function ready(): void
    {
        abort_if($this->missing(), 503, 'WhatsApp is not configured. Ask an administrator to complete Settings → Communication → WhatsApp API.');
    }

    private function client()
    {
        return Http::withToken(config('whatsapp.access_token'))->acceptJson()->connectTimeout(5)->timeout(20);
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.config('whatsapp.api_version').'/'.$path;
    }

    public function templates(): array
    {
        if (config('whatsapp.provider') === 'gupshup') return app(GupshupWhatsApp::class)->templates();
        $this->ready();
        $templates = [];
        $after = null;
        // Follow only cursors, never provider-supplied URLs containing tokens.
        for ($page = 0; $page < 20; $page++) {
            $query = ['fields' => 'name,language,status,category,components,parameter_format', 'limit' => 100];
            if ($after) {
                $query['after'] = $after;
            }
            $response = $this->client()->get($this->url(config('whatsapp.business_account_id').'/message_templates'), $query);
            abort_unless($response->successful(), 502, 'Meta could not load templates. Check the token, WABA ID and whatsapp_business_management permission.');
            foreach ($response->json('data', []) as $template) {
                if (($template['status'] ?? '') !== 'APPROVED') {
                    continue;
                }
                $body = collect($template['components'] ?? [])->firstWhere('type', 'BODY');
                $supported = $body && ($template['parameter_format'] ?? 'POSITIONAL') === 'POSITIONAL';
                foreach ($template['components'] ?? [] as $component) {
                    if (! in_array($component['type'], ['BODY', 'HEADER', 'FOOTER']) ||
                        ($component['type'] === 'HEADER' && (($component['format'] ?? '') !== 'TEXT' || str_contains($component['text'] ?? '', '{{')))) {
                        $supported = false;
                    }
                }
                if (($template['category'] ?? '') === 'AUTHENTICATION') $supported = WhatsAppAuthenticationTemplate::supported($template);
                if (! $supported) {
                    continue;
                }
                preg_match_all('/\{\{(\d+)\}\}/', $body['text'] ?? '', $matches);
                $numbers = array_unique(array_map('intval', $matches[1]));
                sort($numbers);
                if ($numbers && $numbers !== range(1, count($numbers))) {
                    continue;
                }
                $templates[] = ['name' => $template['name'], 'language' => $template['language'], 'category' => $template['category'], 'body' => $body['text'], 'parameter_count' => count($numbers), 'authentication'=>($template['category'] ?? '') === 'AUTHENTICATION'];
            }
            if (! $response->json('paging.next')) {
                return $templates;
            }
            $after = $response->json('paging.cursors.after');
            if (! $after) {
                break;
            }
        }
        abort(502, 'Template list is too large or incomplete. Narrow your WABA template library before sending.');
    }

    public function manageTemplates(): array
    {
        if (config('whatsapp.provider') === 'gupshup') return app(GupshupWhatsApp::class)->manageTemplates();
        $this->ready();
        $rows = [];
        $after = null;
        for ($page = 0; $page < 20; $page++) {
            $query = ['fields' => 'id,name,language,status,category,components,parameter_format', 'limit' => 100];
            if ($after) {
                $query['after'] = $after;
            }
            $response = $this->client()->get($this->url(config('whatsapp.business_account_id').'/message_templates'), $query);
            abort_unless($response->successful(), 502, 'Meta could not load templates. Check WABA ID, token and management permission.');
            $rows = array_merge($rows, $response->json('data', []));
            if (! $response->json('paging.next')) {
                return $rows;
            }
            $after = $response->json('paging.cursors.after');
            if (! $after) {
                break;
            }
        }
        abort(502, 'Meta returned an incomplete template list.');
    }

    public function submitTemplate(array $data): array
    {
        abort_if(config('whatsapp.provider') === 'gupshup', 422, 'Create or edit templates in the Gupshup dashboard, then sync their approval here.');
        $this->ready();
        $body = ['type' => 'BODY', 'text' => $data['body']];
        if ($data['examples']) {
            $body['example'] = ['body_text' => [$data['examples']]];
        }
        $payload = ['category' => $data['category'], 'components' => [$body]];
        if ($data['category'] === 'AUTHENTICATION') $payload['components'] = [['type'=>'BODY','add_security_recommendation'=>true],['type'=>'FOOTER','code_expiration_minutes'=>5],['type'=>'BUTTONS','buttons'=>[['type'=>'OTP','otp_type'=>'COPY_CODE','text'=>'Copy code']]]];
        if (empty($data['id'])) {
            $payload += ['name' => $data['name'], 'language' => $data['language'], 'parameter_format' => 'POSITIONAL'];
        }
        $path = $data['id'] ?? config('whatsapp.business_account_id').'/message_templates';
        $response = $this->client()->post($this->url($path), $payload);
        abort_unless($response->successful(), 422, 'Meta rejected the template. Check name uniqueness, language, category, text and sample values in WhatsApp Manager.');

        return $response->json();
    }

    public function deleteTemplate(string $name): array
    {
        abort_if(config('whatsapp.provider') === 'gupshup', 422, 'Manage template deletion in the Gupshup dashboard, then sync here.');
        $this->ready();
        $response = $this->client()->delete($this->url(config('whatsapp.business_account_id').'/message_templates'), ['name' => $name]);
        abort_unless($response->successful(), 422, 'Meta could not delete the template. Check permissions and current template state.');

        return $response->json();
    }

    public function recipient(string $number): string
    {
        $international = str_starts_with(trim($number), '+');
        $number = preg_replace('/[\s()+-]/', '', $number);
        if (! $international && strlen($number) === 10) {
            $number = config('whatsapp.default_country_code').$number;
        }
        if (! preg_match('/^[1-9]\d{7,14}$/', $number)) {
            throw ValidationException::withMessages(['candidate' => 'Candidate WhatsApp number must be a valid international number.']);
        }

        return $number;
    }

    public function send(string $recipient, array $template, array $parameters, ?int $messageId = null)
    {
        if (config('whatsapp.provider') === 'gupshup') return app(GupshupWhatsApp::class)->send($recipient, $template, $parameters, $messageId);
        $this->ready();
        $payload = ['name' => $template['name'], 'language' => ['code' => $template['language']]];
        if ($parameters) {
            $payload['components'] = [['type' => 'body', 'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => $value], $parameters)]];
        }

        if (!empty($template['authentication']) || ($template['category'] ?? '') === 'AUTHENTICATION') { $payload['components'][] = ['type'=>'button','sub_type'=>'url','index'=>'0','parameters'=>[['type'=>'text','text'=>$parameters[0]]]]; }

        // Do not automatically retry POST: a timed-out request may already have been sent.
        return $this->client()->post($this->url(config('whatsapp.phone_number_id').'/messages'), [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $recipient, 'type' => 'template', 'template' => $payload,
        ]);
    }
}
