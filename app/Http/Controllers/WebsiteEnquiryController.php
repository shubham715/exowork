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

        $filters = $request->validate(['enquiry' => ['nullable', 'integer', 'min:1']]);
        $enquiries = DB::table('website_enquiries');
        if (isset($filters['enquiry'])) {
            $enquiries->where('id', $filters['enquiry']);
        }

        return response()->json($enquiries->orderByDesc('id')->paginate(25))
            ->header('Cache-Control', 'no-store, private');
    }
}
