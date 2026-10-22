<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class GupshupWhatsApp
{
    public const WELCOME_BODY = 'Hello {{1}}, welcome to EXOWORK. Are you interested in finding a job with our placement support? Choose Yes or No below. Reply STOP to stop WhatsApp messages.';

    public function missing(): array
    {
        $missing = config('whatsapp.enabled') ? [] : ['WHATSAPP_ENABLED'];
        foreach (['app_id', 'app_name', 'source', 'webhook_secret'] as $key) {
            if (! config('whatsapp.gupshup_'.$key)) $missing[] = 'GUPSHUP_'.strtoupper($key);
        }
        if (! config('whatsapp.gupshup_api_key') && ! config('whatsapp.gupshup_customer_secret')) $missing[] = 'GUPSHUP_CUSTOMER_SECRET';
        if (strlen((string) config('whatsapp.gupshup_webhook_secret')) < 32) $missing[] = 'GUPSHUP_WEBHOOK_SECRET';
        if (config('whatsapp.gupshup_source') && ! preg_match('/^[1-9]\d{7,14}$/', config('whatsapp.gupshup_source'))) $missing[] = 'GUPSHUP_SOURCE';
        return array_unique($missing);
    }

    private function client()
    {
        $client = Http::acceptJson()->connectTimeout(5)->timeout(20);
        $secret = config('whatsapp.gupshup_customer_secret');
        if (! $secret) return $client->withHeaders(['apikey' => config('whatsapp.gupshup_api_key')]);
        // Cache encrypted CATs; refresh before expiry. Changing account/secret invalidates the cache key.
        $key = 'gupshup-cat:'.hash('sha256', config('whatsapp.gupshup_app_id').'|'.$secret);
        $encrypted = Cache::get($key);
        if (! $encrypted) {
            $encrypted = Cache::lock($key.':lock', 30)->block(5, function () use ($key, $secret) {
                if ($cached = Cache::get($key)) return $cached;
                $response = Http::acceptJson()->connectTimeout(5)->timeout(20)->withHeaders(['X-Customer-Secret' => $secret])->post('https://api.gupshup.io/wa/app/'.rawurlencode(config('whatsapp.gupshup_app_id')).'/auth/app-token');
                abort_unless($response->successful() && is_string($response->json('token')) && is_string($response->json('expiresAt')), 502, 'Gupshup token generation failed. Check the account secret and its expiry.');
                $expiry = \Carbon\Carbon::parse($response->json('expiresAt'))->subMinutes(5);
                abort_unless($expiry->isFuture(), 502, 'Gupshup returned an expired token.');
                $encrypted = Crypt::encryptString($response->json('token'));
                Cache::put($key, $encrypted, $expiry);
                return $encrypted;
            });
        }
        return $client->withToken(Crypt::decryptString($encrypted));
    }

    public function manageTemplates(): array
    {
        abort_if($this->missing(), 503, 'Complete the Gupshup connection settings first.');
        $rows = [];
        for ($page = 0; $page < 20; $page++) {
            $response = $this->client()->get('https://api.gupshup.io/wa/app/'.rawurlencode(config('whatsapp.gupshup_app_id')).'/template', ['pageNo' => $page, 'pageSize' => 100]);
            abort_unless($response->successful() && is_array($response->json('templates')), 502, 'Gupshup could not load templates. Check the app ID and credentials.');
            $batch = $response->json('templates');
            foreach ($batch as $row) {
                if (! is_array($row) || ! is_string($row['id'] ?? null) || ! is_string($row['elementName'] ?? null) || ! is_string($row['languageCode'] ?? null) || ! is_string($row['status'] ?? null) || ! is_string($row['category'] ?? null)) continue;
                $meta = is_array($row['containerMeta'] ?? null) ? $row['containerMeta'] : json_decode($row['containerMeta'] ?? '{}', true);
                // Never infer the body from Gupshup's flattened data (it may include headers/buttons).
                if (! is_array($meta) || ! is_string($meta['data'] ?? null)) continue;
                $components = [['type' => 'BODY', 'text' => $meta['data']]];
                $supported = ($row['templateType'] ?? 'TEXT') === 'TEXT' && empty($meta['header']) && empty($meta['mediaType']) && empty($meta['cards'])
                    && ! in_array($row['elementName'], ['exowork_welcome', 'exowork_welcome_v1', 'exowork_registration_v2', 'exowork_career_intro', 'exowork_training_reconnect', 'exowork_job_opportunity', 'exowork_interview_availability']);
                if (! empty($meta['footer'])) $supported = false;
                $buttons = $meta['buttons'] ?? [];
                // Current workflow decisions use authenticated portal links, not WhatsApp buttons.
                if ($buttons) {
                    $supported = false;
                    $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
                }
                $rows[] = ['id' => $row['id'], 'name' => $row['elementName'], 'language' => $row['languageCode'], 'status' => strtoupper($row['status']), 'category' => strtoupper($row['category']), 'parameter_format' => 'POSITIONAL', 'components' => $components, 'supported' => $supported];
            }
            if (count($batch) < 100) return $rows;
        }
        abort(502, 'Gupshup template list is incomplete.');
    }

    public function templates(): array
    {
        $templates = [];
        foreach ($this->manageTemplates() as $row) {
            if ($row['status'] !== 'APPROVED' || ! $row['supported']) continue;
            $body = $row['components'][0]['text'];
            preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
            $numbers = array_values(array_unique(array_map('intval', $matches[1])));
            sort($numbers);
            if ($numbers && $numbers !== range(1, count($numbers))) continue;
            $templates[] = [...$row, 'body' => $body, 'parameter_count' => count($numbers)];
        }
        return $templates;
    }

    public function send(string $recipient, array $template, array $parameters, ?int $messageId)
    {
        abort_if($this->missing(), 503, 'Gupshup is not configured.');
        $payload = ['channel' => 'whatsapp', 'source' => config('whatsapp.gupshup_source'), 'destination' => $recipient, 'src.name' => config('whatsapp.gupshup_app_name'), 'template' => json_encode(['id' => $template['id'], 'params' => $parameters], JSON_THROW_ON_ERROR)];
        // Never retry a send automatically: a timeout may already have submitted it.
        return $this->client()->asForm()->post('https://api.gupshup.io/wa/api/v1/template/msg', $payload);
    }
}
