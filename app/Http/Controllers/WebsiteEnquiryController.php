<?php

namespace App\Http\Controllers;

use App\Services\AdminEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WebsiteEnquiryController extends Controller
{
    public function store(Request $request, AdminEmail $mail): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organization' => ['nullable', 'string', 'max:160'],
            'phone' => ['required', 'string', 'digits:10'],
            'audience' => ['required', Rule::in(['candidate', 'employer', 'training-center', 'other'])],
            'message' => ['nullable', 'string', 'max:1500'],
        ]);

        $id = DB::table('website_enquiries')->insertGetId([...$data, 'created_at' => now()]);
        $mail->send('New website enquiry', 'Enquiry #'.$id, '/admin/enquiries?enquiry='.$id);

        return response()->json(['reference' => $id], 201);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('permissions.manage'), 403);

        $filters = $request->validate([
            'enquiry' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:160'],
            'audience' => ['nullable', Rule::in(['candidate', 'employer', 'training-center', 'other'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
        ]);
        $enquiries = DB::table('website_enquiries');
        if (isset($filters['enquiry'])) $enquiries->where('id', $filters['enquiry']);
        if (!empty($filters['audience'])) $enquiries->where('audience', $filters['audience']);
        if ($search = trim($filters['search'] ?? '')) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $enquiries->where(function ($query) use ($search) {
                foreach (['name', 'organization', 'phone', 'message'] as $field) $query->orWhereRaw("LOWER($field) LIKE ? ESCAPE '!'", [$search]);
            });
        }
        $counts = DB::table('website_enquiries')->selectRaw('audience, COUNT(*) as total')->groupBy('audience')->pluck('total', 'audience')->all();
        $page = $enquiries->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));
        return response()->json([...$page->toArray(), 'counts' => ['all' => array_sum($counts), ...$counts]])
            ->header('Cache-Control', 'no-store, private');
    }
}
