<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminMasterDataController extends Controller
{
    private const TYPES = ['qualification', 'experience_level', 'industry', 'language', 'state', 'district'];

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasPermission('masters.manage'), 403);
    }

    private function table(string $type): string
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        return match ($type) {
            'state' => 'states', 'district' => 'districts', default => 'master_options',
        };
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        return response()->json([
            'options' => DB::table('master_options')->orderBy('type')->orderBy('sort_order')->orderBy('name')->get(),
            'states' => DB::table('states')->orderBy('sort_order')->orderBy('name')->get(),
            'districts' => DB::table('districts')->orderBy('state_id')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $this->authorizeManage($request);
        $table = $this->table($type);
        $maxName = match ($type) {
            'experience_level' => 20, 'language' => 40,
            'qualification', 'industry' => 60,
            default => 80,
        };
        $data = $request->validate([
            'name' => ['required', 'string', "max:{$maxName}"],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'state_id' => [$type === 'district' ? 'required' : 'prohibited', 'integer', Rule::exists('states', 'id')->where('is_active', true)],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
        ]);
        $slug = $data['slug'] ?? Str::slug($data['name']);
        $query = DB::table($table)->where('slug', $slug);
        if ($type === 'district') $query->where('state_id', $data['state_id']);
        if ($table === 'master_options') $query->where('type', $type);
        if ($query->exists()) return response()->json(['message' => 'This slug already exists.'], 422);

        $row = [
            'name' => trim($data['name']), 'slug' => $slug,
            'sort_order' => $data['sort_order'] ?? 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if ($type === 'district') $row['state_id'] = $data['state_id'];
        if ($table === 'master_options') $row['type'] = $type;
        $id = DB::table($table)->insertGetId($row);

        return response()->json(['item' => DB::table($table)->find($id)], 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeManage($request);
        $table = $this->table($type);
        $maxName = match ($type) {
            'experience_level' => 20, 'language' => 40,
            'qualification', 'industry' => 60,
            default => 80,
        };
        $query = DB::table($table)->where('id', $id);
        if ($table === 'master_options') $query->where('type', $type);
        abort_unless($query->exists(), 404);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', "max:{$maxName}"],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            'slug' => ['prohibited'], 'state_id' => ['prohibited'],
        ]);
        $query->update([...$data, 'updated_at' => now()]);

        return response()->json(['item' => DB::table($table)->find($id)]);
    }
}
