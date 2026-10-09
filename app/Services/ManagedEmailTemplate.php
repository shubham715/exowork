<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManagedEmailTemplate
{
    public function render(string $key, array $values): ?array
    {
        if (! Schema::hasTable('email_templates')) {
            return null;
        }
        $template = DB::table('email_templates')->where('system_key', $key)->where('is_active', true)->first();
        if (! $template) {
            return null;
        }
        $values['app_name'] = config('app.name');
        $tokens = [];
        foreach ($values as $name => $value) {
            $tokens['{{'.$name.'}}'] = (string) $value;
        }

        return ['subject' => str_replace(["\r", "\n"], ' ', strtr($template->subject, $tokens)), 'body' => strtr($template->body, $tokens)];
    }
}
