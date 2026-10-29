<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class GupshupWhatsApp
{
    private function usesApiKey(): bool
    {
        return config('whatsapp.gupshup_auth_mode', 'auto') === 'api_key'
            || (config('whatsapp.gupshup_auth_mode', 'auto') === 'auto' && ! config('whatsapp.gupshup_customer_secret'));
    }

    private function tokenUrl(): string
    {
        return 'https://api.gupshup.io/wa/app/'.rawurlencode(config('whatsapp.gupshup_app_id')).'/auth/app-token';
    }

    private function requestToken(string $secret, ?callable $inspect = null)
    {
        $client = Http::acceptJson()->connectTimeout(5)->timeout(20)
            ->withHeaders(['X-Customer-Secret' => $secret])
            ->withBody('', 'application/x-www-form-urlencoded');
        if ($inspect) $client->beforeSending($inspect);
        return $client->post($this->tokenUrl());
    }

    public function testConnection(): array
    {
        $apiKey = $this->usesApiKey();
        $secret = (string) config($apiKey ? 'whatsapp.gupshup_api_key' : 'whatsapp.gupshup_customer_secret');
        abort_unless($secret !== '' && \Illuminate\Support\Str::isUuid(config('whatsapp.gupshup_app_id')), 422, 'Save a valid app ID and the credential for your selected authentication method before testing.');
        $result = ['request' => null, 'success' => false];
        try {
            $inspect = function (\Illuminate\Http\Client\Request $request) use (&$result, $apiKey) {
                $sentSecret = $request->header($apiKey ? 'apikey' : 'X-Customer-Secret')[0] ?? '';
                $masked = strlen($sentSecret) > 16 ? substr($sentSecret, 0, 4).'…'.substr($sentSecret, -4) : '[REDACTED]';
                $headers = [];
                foreach ($request->headers() as $name => $values) {
                    $headers[$name] = strtolower($name) === ($apiKey ? 'apikey' : 'x-customer-secret') ? $masked
                        : (in_array(strtolower($name), ['accept', 'content-type', 'content-length', 'user-agent', 'host'], true) ? implode(', ', $values) : '[REDACTED]');
                }
                $result['request'] = ['method' => $request->method(), 'url' => $request->url(), 'headers' => $headers, 'body' => $request->body(),
                    'credentials_source' => 'Effective server configuration (saved admin settings override environment)',
                    ($apiKey ? 'api_key' : 'customer_secret') => ['preview' => $masked, 'length' => strlen($sentSecret), 'sha256_prefix' => substr(hash('sha256', $sentSecret), 0, 12),
                        'has_surrounding_whitespace' => $sentSecret !== trim($sentSecret), 'has_non_printable_characters' => (bool) preg_match('/[^\x20-\x7E]/', $sentSecret)]];
            };
            $response = $apiKey
                ? $this->client()->beforeSending($inspect)->get('https://api.gupshup.io/wa/app/'.rawurlencode(config('whatsapp.gupshup_app_id')).'/template', ['pageNo' => 0, 'pageSize' => 1])
                : $this->requestToken($secret, $inspect);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return $result + ['response' => ['status' => null, 'body' => 'Connection failed or timed out. No provider response received.']];
        }
        // Do not expose response headers (cookies) or raw non-JSON bodies (HTML may echo credentials).
        $returnedToken = $response->json('token');
        $redact = function ($value, $key = '') use (&$redact, $secret, $returnedToken) {
            if (preg_match('/token|secret|password|authorization|cookie|api.?key/i', (string) $key)) return '[REDACTED]';
            if (is_array($value)) {
                foreach ($value as $k => $v) $value[$k] = $redact($v, $k);
                return $value;
            }
            if (is_string($value)) {
                foreach ([$secret, is_string($returnedToken) ? $returnedToken : '', (string) config('whatsapp.gupshup_api_key'), (string) config('whatsapp.gupshup_webhook_secret')] as $credential) {
                    if ($credential !== '') $value = str_replace($credential, '[REDACTED]', $value);
                }
                return preg_replace('/\b[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]+\b/', '[REDACTED]', $value);
            }
            return $value;
        };
        $json = $response->json();
        $result['response'] = ['status' => $response->status(), 'content_type' => $response->header('Content-Type'), 'body' => is_array($json) ? $redact($json) : '[Non-JSON response omitted]'];
        if ($apiKey) {
            $result['success'] = $response->successful() && is_array($response->json('templates'));
            return $result;
        }
        try {
            $expiresAt = $response->json('expiresAt');
            $expiry = (is_int($expiresAt) || (is_string($expiresAt) && ctype_digit($expiresAt)))
                ? \Carbon\Carbon::createFromTimestampMs($expiresAt, 'UTC')->subMinutes(5)
                : \Carbon\Carbon::parse(is_string($expiresAt) ? $expiresAt : throw new \UnexpectedValueException())->subMinutes(5);
            $token = $response->json('token');
            $result['success'] = $response->successful() && is_string($token) && trim($token) !== '' && $expiry->isFuture();
            if ($result['success']) {
                Cache::put('gupshup-cat:'.hash('sha256', config('whatsapp.gupshup_app_id').'|'.$secret), Crypt::encryptString($token), $expiry);
            }
        } catch (\Throwable $e) {
            $result['success'] = false;
        }
        return $result;
    }

    public const WELCOME_BODY = 'Hello {{1}}, welcome to EXOWORK. Are you interested in finding a job with our placement support? Choose Yes or No below. Reply STOP to stop WhatsApp messages.';

    public function missing(): array
    {
        $missing = config('whatsapp.enabled') ? [] : ['WHATSAPP_ENABLED'];
        foreach (['app_id', 'app_name', 'source', 'webhook_secret'] as $key) {
            if (! config('whatsapp.gupshup_'.$key)) $missing[] = 'GUPSHUP_'.strtoupper($key);
        }
        if (! config('whatsapp.gupshup_api_key') && ! config('whatsapp.gupshup_customer_secret')) $missing[] = 'GUPSHUP_CUSTOMER_SECRET';
        if (! config($this->usesApiKey() ? 'whatsapp.gupshup_api_key' : 'whatsapp.gupshup_customer_secret')) $missing[] = $this->usesApiKey() ? 'GUPSHUP_API_KEY' : 'GUPSHUP_CUSTOMER_SECRET';
        if (strlen((string) config('whatsapp.gupshup_webhook_secret')) < 32) $missing[] = 'GUPSHUP_WEBHOOK_SECRET';
        if (config('whatsapp.gupshup_source') && ! preg_match('/^[1-9]\d{7,14}$/', config('whatsapp.gupshup_source'))) $missing[] = 'GUPSHUP_SOURCE';
        return array_unique($missing);
    }

    private function client()
    {
        $client = Http::acceptJson()->connectTimeout(5)->timeout(20);
        $secret = config('whatsapp.gupshup_customer_secret');
        if ($this->usesApiKey()) return $client->withHeaders(['apikey' => config('whatsapp.gupshup_api_key')]);
        // Cache encrypted CATs; refresh before expiry. Changing account/secret invalidates the cache key.
        $key = 'gupshup-cat:'.hash('sha256', config('whatsapp.gupshup_app_id').'|'.$secret);
        $encrypted = Cache::get($key);
        if (! $encrypted) {
            $encrypted = Cache::lock($key.':lock', 30)->block(5, function () use ($key, $secret) {
                if ($cached = Cache::get($key)) return $cached;
                // Match the console's successful request: an empty form body, not JSON [].
                $response = $this->requestToken($secret);
                // Never include the provider response body: it can contain credentials or tokens.
                abort_unless($response->successful(), 502, match ($response->status()) {
                    400, 401 => 'Gupshup token request rejected (HTTP '.$response->status().'). Check that Customer Secret contains the account secret, not an app token or API key, and belongs to this app owner.',
                    403 => 'Gupshup token access denied (HTTP 403). Check app ownership and whether this app requires partner authentication.',
                    404 => 'Gupshup token endpoint returned HTTP 404. Check the saved Gupshup app ID.',
                    409, 429 => 'Gupshup token limit reached (HTTP '.$response->status().'). Check active Customer App Tokens in Gupshup Settings; avoid repeated token generation.',
                    default => 'Gupshup token request failed (HTTP '.$response->status().'). Retry later or ask Gupshup support to check the token endpoint.',
                });
                $token = $response->json('token');
                $expiresAt = $response->json('expiresAt');
                abort_unless(is_string($token) && trim($token) !== '' && (is_int($expiresAt) || (is_string($expiresAt) && trim($expiresAt) !== '')), 502, 'Gupshup token response was incomplete (HTTP '.$response->status().'): expected token and expiresAt. Contact Gupshup support; do not share the response body publicly.');
                try {
                    // The live API returns Unix milliseconds; older responses used ISO dates.
                    $expiry = (is_int($expiresAt) || ctype_digit($expiresAt))
                        ? \Carbon\Carbon::createFromTimestampMs($expiresAt, 'UTC')
                        : \Carbon\Carbon::parse($expiresAt);
                    $expiry->subMinutes(5);
                } catch (\Throwable $e) {
                    abort(502, 'Gupshup token response contained an invalid expiry date. Contact Gupshup support.');
                }
                abort_unless($expiry->isFuture(), 502, 'Gupshup returned a token that is expired or expires within five minutes. Check the live server clock and Gupshup token expiry.');
                $encrypted = Crypt::encryptString($token);
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
                if (!is_array($meta)) $meta = [];
                if (!is_string($meta['data'] ?? null) && strtoupper($row['status']) === 'APPROVED') continue;
                $components = [['type' => 'BODY', 'text' => $meta['data'] ?? '']];
                $supported = ($row['templateType'] ?? 'TEXT') === 'TEXT' && empty($meta['header']) && empty($meta['mediaType']) && empty($meta['cards'])
                    && ! in_array($row['elementName'], ['exowork_welcome', 'exowork_welcome_v1', 'exowork_registration_v2', 'exowork_career_intro', 'exowork_training_reconnect', 'exowork_job_opportunity', 'exowork_interview_availability']);
                if (! empty($meta['footer'])) $supported = false;
                $buttons = $meta['buttons'] ?? [];
                // Current workflow decisions use authenticated portal links, not WhatsApp buttons.
                if ($buttons) {
                    $supported = false;
                    $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
                }
                if (strtoupper($row['category']) === 'AUTHENTICATION') $supported = empty($meta['header']) && empty($meta['mediaType']) && empty($meta['cards']) && WhatsAppAuthenticationTemplate::supported(['category'=>'AUTHENTICATION','components'=>$components]);
                $rows[] = ['id' => $row['id'], 'name' => $row['elementName'], 'language' => $row['languageCode'], 'status' => strtoupper($row['status']), 'category' => strtoupper($row['category']), 'parameter_format' => 'POSITIONAL', 'components' => $components, 'supported' => $supported, 'authentication'=>strtoupper($row['category']) === 'AUTHENTICATION', 'reason'=>is_string($row['reason'] ?? null) && $row['reason'] !== 'NONE' ? substr($row['reason'],0,1000) : null];
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
        if (!empty($template['authentication']) || ($template['category'] ?? '') === 'AUTHENTICATION') {
            abort_unless(count($parameters) === 1 && preg_match('/^[0-9]{6}$/', (string)$parameters[0]), 422, 'Authentication templates require one six-digit code.');
            $parameters = [$parameters[0], $parameters[0]]; // Body and copy-code button.
        }
        $payload = ['channel' => 'whatsapp', 'source' => config('whatsapp.gupshup_source'), 'destination' => $recipient, 'src.name' => config('whatsapp.gupshup_app_name'), 'template' => json_encode(['id' => $template['id'], 'params' => $parameters], JSON_THROW_ON_ERROR)];
        // Never retry a send automatically: a timeout may already have submitted it.
        return $this->client()->asForm()->post('https://api.gupshup.io/wa/api/v1/template/msg', $payload);
    }
}
