<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class CommunicationSettings
{
    public function get(string $channel): array
    {
        if (! Schema::hasTable('communication_settings')) {
            return [];
        }
        $payload = DB::table('communication_settings')->where('channel', $channel)->value('payload');

        return $payload ? json_decode(Crypt::decryptString($payload), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function save(string $channel, array $values): void
    {
        DB::table('communication_settings')->updateOrInsert(['channel' => $channel], [
            'payload' => Crypt::encryptString(json_encode($values, JSON_THROW_ON_ERROR)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->apply();
    }

    public function apply(): void
    {
        foreach ($this->get('whatsapp') as $key => $value) {
            config(['whatsapp.'.$key => $value]);
        }
        $smtp = $this->get('smtp');
        if ($smtp) {
            config(['mail.default' => $smtp['enabled'] ? 'smtp' : 'log']);
            config(['mail.mailers.smtp' => [
                'transport' => 'smtp', 'host' => $smtp['host'], 'port' => $smtp['port'],
                'scheme' => $smtp['encryption'] === 'ssl' ? 'smtps' : 'smtp',
                'require_tls' => $smtp['encryption'] === 'tls',
                'auto_tls' => $smtp['encryption'] !== 'none',
                'username' => $smtp['username'] ?? null, 'password' => $smtp['password'] ?? null,
                'timeout' => 20,
            ]]);
            config(['mail.from' => ['address' => $smtp['from_address'], 'name' => $smtp['from_name']]]);
            Mail::purge('smtp');
        }
    }
}
