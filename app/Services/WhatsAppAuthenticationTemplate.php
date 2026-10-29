<?php
namespace App\Services;

class WhatsAppAuthenticationTemplate
{
    public static function supported(array $template): bool
    {
        if (($template['category'] ?? '') !== 'AUTHENTICATION') return false;
        $components = collect($template['components'] ?? []);
        $body = $components->firstWhere('type', 'BODY');
        $buttons = $components->firstWhere('type', 'BUTTONS')['buttons'] ?? [];
        if (!$body || count($buttons) !== 1 || $components->contains(fn ($c) => !in_array($c['type'], ['BODY', 'FOOTER', 'BUTTONS']))) return false;
        $button = $buttons[0];
        return (($button['type'] ?? '') === 'OTP' && ($button['otp_type'] ?? '') === 'COPY_CODE')
            || (($button['type'] ?? '') === 'URL' && str_contains($button['url'] ?? '', 'https://www.whatsapp.com/otp/code/') && str_contains($button['url'] ?? '', 'otp_type=COPY_CODE'));
    }
}
