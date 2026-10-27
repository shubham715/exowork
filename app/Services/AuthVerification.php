<?php
namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AuthVerification
{
    public function settings(): array
    {
        return array_replace(['login_enabled' => true, 'registration_enabled' => true, 'template_name' => '', 'template_language' => 'en_US', 'gupshup_template_id' => ''], app(CommunicationSettings::class)->get('authentication'));
    }

    public function requireRegistration(Request $request, string $role, string $phone): void
    {
        if (!$this->settings()['registration_enabled']) return;
        $proof = Cache::get('registration-proof:'.hash('sha256', (string)$request->input('verification_token')));
        if (!$proof || $proof['role'] !== $role || $proof['phone'] !== $phone) {
            throw ValidationException::withMessages(['phone' => 'Verify your WhatsApp number before registering.', 'whatsapp' => 'Verify your WhatsApp number before registering.']);
        }
    }

    public function consume(Request $request): void
    {
        Cache::forget('registration-proof:'.hash('sha256', (string)$request->input('verification_token')));
    }
}
