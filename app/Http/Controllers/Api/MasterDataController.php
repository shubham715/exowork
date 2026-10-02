<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MasterDataController extends Controller
{
    public function index(): JsonResponse
    {
        $options = DB::table('master_options')->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'type', 'slug', 'name'])
            ->groupBy('type');

        return response()->json([
            'qualifications' => $options->get('qualification', collect())->values(),
            'experience_levels' => $options->get('experience_level', collect())->values(),
            'industries' => $options->get('industry', collect())->values(),
            'languages' => $options->get('language', collect())->values(),
            'states' => DB::table('states')->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name']),
        ]);
    }

    public function districts(string $slug): JsonResponse
    {
        $state = DB::table('states')->where('slug', $slug)->where('is_active', true)->first(['id', 'slug', 'name']);
        abort_unless($state, 404);

        return response()->json([
            'state' => $state,
            'districts' => DB::table('districts')->where('state_id', $state->id)
                ->where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'slug', 'name']),
        ]);
    }
}
