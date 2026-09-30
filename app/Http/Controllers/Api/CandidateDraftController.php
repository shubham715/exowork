<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCandidateDraftRequest;
use App\Models\CandidateDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CandidateDraftController extends Controller
{
    private const ALLOWED_FIELDS = [
        'firstName', 'lastName', 'whatsapp', 'alternate', 'email', 'gender', 'age',
        'qualification', 'state', 'district', 'currentLocation', 'permanentLocation',
        'preferredLocations', 'relocation', 'skills', 'experienceType', 'experienceDetails',
        'salary', 'availability', 'industry', 'languages', 'trainingStatus', 'trainingPartner',
        'trainingCenter', 'batchCode', 'batchEndDate', 'locationConsent', 'photoConsent',
        'callConsent', 'smsConsent', 'whatsappConsent', 'emailConsent',
    ];

    public function show(string $token): JsonResponse
    {
        $draft = $this->findActive($token);

        return response()->json([
            'draft' => [
                'data' => $draft->payload,
                'last_step' => $draft->last_step,
                'saved_at' => $draft->updated_at->toIso8601String(),
                'expires_at' => $draft->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function store(SaveCandidateDraftRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $token = $validated['token'] ?? Str::random(64);
        $hash = hash('sha256', $token);
        $draft = CandidateDraft::query()->where('token_hash', $hash)->first();

        if (! empty($validated['token']) && ! $draft) {
            abort(404, 'This draft is no longer available.');
        }

        $draft ??= new CandidateDraft(['token_hash' => $hash]);
        $draft->fill([
            'payload' => Arr::only($validated['data'], self::ALLOWED_FIELDS),
            'last_step' => $validated['last_step'],
            'registration_source' => $validated['registration_source'],
            'expires_at' => now()->addDays(30),
        ])->save();

        return response()->json([
            'message' => 'Draft saved securely.',
            'draft' => [
                'token' => $token,
                'saved_at' => $draft->updated_at->toIso8601String(),
                'expires_at' => $draft->expires_at->toIso8601String(),
            ],
        ], $draft->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(string $token): JsonResponse
    {
        $this->findActive($token)->delete();

        return response()->json(['message' => 'Draft deleted.']);
    }

    private function findActive(string $token): CandidateDraft
    {
        abort_unless(strlen($token) === 64, 404);

        return CandidateDraft::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }
}
