<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminReviewQueueController extends Controller
{
    private function permissions(Request $request): array {
        $user = $request->user();
        $permissions = [
            'centers' => $user->hasPermission('partners.view') || $user->hasPermission('partners.manage'),
            'employers' => $user->hasPermission('employers.view') || $user->hasPermission('employers.manage'),
            'candidates' => $user->status === 'active' && $user->hasPermission('candidates.view') && DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $user->id)->whereIn('roles.key', ['admin', 'superadmin'])->exists(),
        ];
        abort_unless(in_array(true, $permissions, true), 403);
        return $permissions;
    }

    private function query(Request $request, string $kind) {
        $table = match ($kind) { 'centers' => 'training_centers', 'candidates' => 'candidates', default => 'employers' };
        return DB::table("$table as p")->whereNull('p.deleted_at')
            ->where(function ($query) use ($kind) {
                if ($kind === 'candidates') return $query->whereNotNull('p.candidate_code');
                $query->where('p.status', 'pending');
                // Older center signups were active without a verification decision.
                if ($kind === 'centers') $query->orWhere(fn ($q) => $q->where('p.status', 'active')->whereNull('p.verified_at'));
            })
            ->leftJoin('admin_review_reads as r', function ($join) use ($request, $kind) {
                $join->on('r.subject_id', '=', 'p.id')->where('r.kind', $kind)->where('r.user_id', $request->user()->id)
                    ->whereRaw('r.profile_version = '.$this->version($kind));
            });
    }

    private function version(string $kind): string {
        return $kind === 'candidates' ? "coalesce(p.created_at, '2000-01-01 00:00:00')" : "coalesce(p.updated_at, p.created_at, '2000-01-01 00:00:00')";
    }

    public function index(Request $request) {
        $permissions = $this->permissions($request);
        $counts = ['centers' => 0, 'employers' => 0];
        $unread = 0;
        $items = collect();
        foreach ($permissions as $kind => $allowed) {
            if (!$allowed) continue;
            $query = $this->query($request, $kind);
            $counts[$kind] = (clone $query)->count();
            if ($kind === 'candidates') $query->whereNull('r.id');
            $unread += (clone $query)->whereNull('r.id')->count();
            $fields = $kind === 'candidates' ? ['p.id', 'p.first_name', 'p.last_name', 'p.candidate_code', 'p.created_at', 'p.updated_at'] : ['p.id', 'p.name', 'p.created_at', 'p.updated_at'];
            $items = $items->concat((clone $query)->select($fields)
                ->selectRaw($this->version($kind)." as version, case when r.id is null then 1 else 0 end as unread")
                ->orderByRaw('case when r.id is null then 0 else 1 end')->orderByDesc('p.updated_at')->limit(12)->get()
                ->map(fn ($row) => [...(array) $row, 'kind' => $kind, 'unread' => (bool) $row->unread,
                    'name' => $kind === 'candidates' ? trim($row->first_name.' '.$row->last_name) : $row->name,
                    'url' => $kind === 'candidates' ? '/admin/candidates/'.$row->candidate_code : '/admin/'.($kind === 'centers' ? 'centers' : 'employers').'?status=pending&review='.$row->id]));
        }
        return response()->json([
            'counts' => $counts, 'unread_count' => $unread,
            'items' => $items->sort(function ($a, $b) {
                return ($b['unread'] <=> $a['unread']) ?: strcmp($b['version'], $a['version']);
            })->take(12)->values(),
            'permissions' => [...$permissions, 'manage_centers' => $request->user()->hasPermission('partners.manage'), 'manage_employers' => $request->user()->hasPermission('employers.manage')],
            'user' => ['name' => $request->user()->name, 'email' => $request->user()->email],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function read(Request $request) {
        $permissions = $this->permissions($request);
        $data = $request->validate(['items' => ['required', 'array', 'max:12'], 'items.*.kind' => ['required', Rule::in(['centers', 'employers', 'candidates'])], 'items.*.id' => ['required', 'integer'], 'items.*.version' => ['required', 'string', 'max:40']]);
        DB::transaction(function () use ($request, $permissions, $data) {
            foreach ($data['items'] as $item) {
                abort_unless($permissions[$item['kind']], 403);
                $row = $this->query($request, $item['kind'])->where('p.id', $item['id'])->selectRaw($this->version($item['kind']).' as version')->first();
                // A profile edited after the notification was displayed remains unread.
                if (!$row || $row->version !== $item['version']) continue;
                DB::table('admin_review_reads')->updateOrInsert(['user_id' => $request->user()->id, 'kind' => $item['kind'], 'subject_id' => $item['id']], ['profile_version' => $row->version, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        return $this->index($request);
    }
}
