<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CandidateSessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Candidate $candidate */
        $candidate = $request->user('candidate');
        $firstName = $candidate->first_name;
        $lastName = $candidate->last_name;

        return response()->json([
            'candidate' => [
                'first_name' => $firstName,
                'full_name' => trim($firstName.' '.$lastName),
                'candidate_code' => $candidate->candidate_code,
                'initials' => Str::upper(Str::substr($firstName, 0, 1).Str::substr($lastName, 0, 1)),
            ],
        ]);
    }
}
