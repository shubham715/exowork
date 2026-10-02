<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CenterBatchController extends CenterCandidateController
{
    public function batches(Request $request)
    {
        abort_unless($request->user()->hasPermission('batches.manage'), 403);
        $center = $this->center($request);
        $batches = DB::table('training_batches')->where('training_center_id', $center->id)->orderByDesc('id')->get();
        return response()->json(['center' => $center, 'batches' => $batches->map(fn ($b) => [
            'id' => $b->id, 'code' => $b->code, 'sector' => $b->sector ?? '', 'jobRole' => $b->job_role,
            'trainer' => $b->trainer ?? '', 'trainerPhone' => $b->trainer_phone ?? '',
            'startDate' => $b->starts_on ?? '', 'endDate' => $b->ends_on ?? '', 'capacity' => $b->capacity,
        ])]);
    }

    public function saveBatch(Request $request, ?int $id = null)
    {
        abort_unless($request->user()->hasPermission('batches.manage'), 403);
        $center = $this->center($request);
        if ($id) abort_unless(DB::table('training_batches')->where('training_center_id', $center->id)->where('id', $id)->exists(), 404);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', Rule::unique('training_batches', 'code')->where('training_center_id', $center->id)->ignore($id)],
            'sector' => ['required', 'string', 'max:255'], 'jobRole' => ['required', 'string', 'max:255'],
            'trainer' => ['required', 'string', 'max:255'], 'trainerPhone' => ['required', 'digits:10'],
            'startDate' => ['required', 'date_format:Y-m-d'], 'endDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'capacity' => ['required', 'integer', 'between:1,100000'],
        ]);
        DB::transaction(function () use ($id, $center, $data) {
            if ($id) {
                DB::table('training_batches')->where('id', $id)->lockForUpdate()->first();
                if (Candidate::where('training_batch_id', $id)->count() > $data['capacity']) {
                    throw ValidationException::withMessages(['capacity' => 'Capacity cannot be below the assigned candidate count.']);
                }
            }
            $record = ['training_center_id' => $center->id, 'code' => $data['code'], 'sector' => $data['sector'],
                'job_role' => $data['jobRole'], 'trainer' => $data['trainer'], 'trainer_phone' => $data['trainerPhone'],
                'starts_on' => $data['startDate'], 'ends_on' => $data['endDate'], 'capacity' => $data['capacity'], 'status' => 'active', 'updated_at' => now()];
            if ($id) {
                DB::table('training_batches')->where('id', $id)->update($record);
                Candidate::where('training_batch_id', $id)->update(['batch_code' => $data['code'], 'batch_end_date' => $data['endDate']]);
            } else DB::table('training_batches')->insert($record + ['created_at' => now()]);
        });
        return response()->json(['message' => 'Batch saved.'], $id ? 200 : 201);
    }

    public function assign(Request $request, int $id)
    {
        abort_unless($request->user()->hasPermission('batches.manage') && $request->user()->hasPermission('candidates.update'), 403);
        $center = $this->center($request);
        $data = $request->validate(['candidates' => ['required', 'array', 'min:1', 'max:1000'], 'candidates.*' => ['required', 'string', 'distinct']]);
        DB::transaction(function () use ($request, $center, $id, $data) {
            $batch = DB::table('training_batches')->where('training_center_id', $center->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($batch, 404);
            $candidates = Candidate::where('training_center_id', $center->id)->whereIn('candidate_code', $data['candidates'])->orderBy('id')->lockForUpdate()->get();
            if ($candidates->count() !== count($data['candidates'])) throw ValidationException::withMessages(['candidates' => 'Select candidates belonging to your center.']);
            $additional = $candidates->where('training_batch_id', '!=', $id)->count();
            if ($batch->capacity !== null && Candidate::where('training_batch_id', $id)->count() + $additional > $batch->capacity) throw ValidationException::withMessages(['candidates' => 'The batch does not have enough available seats.']);
            foreach ($candidates as $candidate) {
                if ($candidate->training_batch_id == $id) continue;
                DB::table('candidate_batch_assignments')->insert(['candidate_id' => $candidate->id, 'training_batch_id' => $id, 'previous_batch_id' => $candidate->training_batch_id, 'assigned_by_user_id' => $request->user()->id, 'created_at' => now()]);
                $candidate->update(['training_batch_id' => $id, 'batch_code' => $batch->code, 'batch_end_date' => $batch->ends_on,
                    'training_status' => $batch->ends_on && $batch->ends_on < now()->toDateString() ? 'Completed' : 'Ongoing']);
            }
        });
        return response()->json(['message' => 'Candidates assigned.']);
    }
}
