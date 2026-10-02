<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WhatsAppCloud
{
    public function missing(): array
    {
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
        abort_if($this->missing(), 503, 'WhatsApp is not configured. Complete the WhatsApp environment settings; see docs/whatsapp-setup.html.');
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
                if (! $supported) {
                    continue;
                }
                preg_match_all('/\{\{(\d+)\}\}/', $body['text'] ?? '', $matches);
                $numbers = array_unique(array_map('intval', $matches[1]));
                sort($numbers);
                if ($numbers && $numbers !== range(1, count($numbers))) {
                    continue;
                }
                $templates[] = ['name' => $template['name'], 'language' => $template['language'], 'category' => $template['category'], 'body' => $body['text'], 'parameter_count' => count($numbers)];
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

    public function send(string $recipient, array $template, array $parameters)
    {
        $this->ready();
        $payload = ['name' => $template['name'], 'language' => ['code' => $template['language']]];
        if ($parameters) {
            $payload['components'] = [['type' => 'body', 'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => $value], $parameters)]];
        }

        // Do not automatically retry POST: a timed-out request may already have been sent.
        return $this->client()->post($this->url(config('whatsapp.phone_number_id').'/messages'), [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $recipient, 'type' => 'template', 'template' => $payload,
        ]);
    }
}
