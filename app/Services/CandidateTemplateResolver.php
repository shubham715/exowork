<?php

namespace App\Services;

use App\Models\Candidate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CandidateTemplateResolver
{
    private ?array $catalog = null;

    public function describe(array $template): array
    {
        $this->catalog ??= WhatsAppTemplateCatalog::all();
        $local = DB::table('whatsapp_templates')->where('name', $template['name'])->where('language', $template['language'])->first();
        $spec = collect($this->catalog)->first(fn ($s) => ($s['name'] === $template['name'] || $s['key'] === $local?->system_key) && $s['body'] === $template['body'] && count($s['labels']) === $template['parameter_count']);
        if (! $spec) {
            return [...$template, 'context_mode' => 'manual', 'requires_job' => false, 'labels' => [], 'inputs' => []];
        }
        $key = $spec['key'];
        $requiresJob = in_array($key, ['job_opportunity', 'interview_availability', 'interview_scheduled', 'interview_reminder', 'selection_update', 'joining_update', 'retention_day_7', 'retention_day_15', 'retention_day_30']);
        $source = match ($key) {
            'welcome' => 'Candidate profile and account link', 'career_intro' => 'Candidate profile, video URL and account link',
            'training_reconnect' => 'Candidate profile and batch completion date', 'job_opportunity' => 'Candidate profile and selected job',
            'interview_availability' => 'Candidate application for the selected job',
            'interview_scheduled', 'interview_reminder' => 'Upcoming interview for each candidate and selected job',
            'selection_update' => 'Recorded selection or rejection for each candidate and selected job',
            'joining_update' => 'Recorded joining outcome for each candidate and selected job',
            default => 'Confirmed employment record for each candidate and selected job',
        };
        $inputs = $key === 'career_intro' ? [['key' => 'career_video_url', 'label' => 'Career video URL', 'type' => 'url', 'default' => app(CommunicationSettings::class)->get('whatsapp_workflow')['career_video_url'] ?? '']] : [];

        return [...$template, 'system_key' => $key, 'context_mode' => in_array($key, ['center_placement_update', 'auth_otp']) ? 'unsupported' : 'automatic', 'requires_job' => $requiresJob,
            'auto_fields' => array_values(array_filter(array_map(fn ($label, $index) => ['number' => $index + 1, 'label' => $label], $spec['labels'], array_keys($spec['labels'])), fn ($field) => ! in_array($field['label'], array_column($inputs, 'label')))),
            'labels' => $spec['labels'], 'auto_labels' => array_values(array_filter($spec['labels'], fn ($label) => ! in_array($label, array_column($inputs, 'label')))), 'source_description' => $source, 'job_scope' => $key === 'job_opportunity' ? 'live' : 'records', 'inputs' => $inputs];
    }

    public function resolve(string $key, Candidate $candidate, ?int $jobId, array $inputs): array
    {
        $name = trim($candidate->first_name.' '.$candidate->last_name);
        $url = fn ($path) => rtrim(config('app.url'), '/').$path;
        $proof = [];
        $missing = function ($message): never {
            throw ValidationException::withMessages(['context' => $message]);
        };
        if ($key === 'welcome') {
            $params = [$name, $url('/candidate/profile')];
        } elseif ($key === 'career_intro') {
            $video = trim($inputs['career_video_url'] ?? '');
            if (! filter_var($video, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($video, PHP_URL_SCHEME) ?? ''), ['http', 'https'])) {
                $missing('Enter a valid career video URL.');
            }
            $params = [$name, $video, $url('/candidate/opportunities')];
        } elseif ($key === 'training_reconnect') {
            if (! $candidate->batch_end_date) {
                $missing('Candidate has no recorded batch completion date.');
            }
            $params = [$name, $candidate->batch_end_date->format('d F Y'), $url('/candidate/profile')];
            $proof = ['batch_end_date' => $candidate->batch_end_date->format('Y-m-d')];
        } else {
            $jobs = app(CandidateJobOpportunity::class);
            $job = $jobs->query($key === 'job_opportunity')->where('j.id', $jobId ?? 0)->first();
            if (! $job) {
                $missing('Selected job is unavailable.');
            }
            $snapshot = $jobs->snapshot($job);
            $proof['job'] = $snapshot;
            if ($key === 'job_opportunity') {
                $params = [$name, $snapshot['role'], $snapshot['location'], $snapshot['salary'], $snapshot['url']];
            } else {
                $application = DB::table('applications')->where('candidate_id', $candidate->id)->where('job_post_id', $jobId)->first();
                if (! $application) {
                    $missing('Candidate has no application for the selected job.');
                }
                $proof['application'] = ['id' => $application->id, 'status' => $application->status];
                if ($key === 'interview_availability') {
                    if ($application->status !== 'interested') {
                        $missing('Application is not awaiting interview availability.');
                    }
                    $params = [$name, $snapshot['role'], $url('/candidate/interests')];
                } elseif (in_array($key, ['interview_scheduled', 'interview_reminder'])) {
                    $interviews = DB::table('interviews')->where('application_id', $application->id)->whereIn('status', ['scheduled', 'confirmed'])->where('scheduled_at', '>', now())->orderBy('scheduled_at')->get();
                    if (! $interviews->count()) {
                        $missing('No upcoming scheduled interview for this candidate and job.');
                    }
                    if ($interviews->count() > 1) {
                        $missing('Multiple upcoming interviews found. Resolve the interview schedule before sending.');
                    }
                    $interview = $interviews->first();
                    $proof['interview'] = ['id' => $interview->id, 'status' => $interview->status, 'scheduled_at' => $interview->scheduled_at, 'location' => $interview->location];
                    $when = Carbon::parse($interview->scheduled_at, 'UTC')->timezone('Asia/Kolkata')->format('d F Y, h:i A').' IST';
                    if ($key === 'interview_scheduled') {
                        if (! $interview->location) {
                            $missing('Interview location is missing.');
                        }
                        $employer = DB::table('employers')->find($job->employer_id);
                        $params = [$name, $snapshot['role'], $job->employer_name, $when, $interview->location, $employer->contact_name ?: 'EXOWORK coordinator', $snapshot['salary'], $url('/candidate/interviews')];
                    } else {
                        $params = [$name, $snapshot['role'], $when, $url('/candidate/interviews')];
                    }
                } else {
                    $placement = DB::table('placement_outcomes')->where('application_id', $application->id)->first();
                    if (! $placement) {
                        $missing('No recorded placement outcome for this candidate and job.');
                    }
                    $proof['placement'] = ['id' => $placement->id, 'status' => $placement->status, 'joined_on' => $placement->joined_on, 'expected_joining_on' => $placement->expected_joining_on];
                    if ($key === 'selection_update') {
                        if (! in_array($placement->status, ['selected', 'rejected'])) {
                            $missing('No current selection or rejection outcome.');
                        }
                        $params = [$name, $snapshot['role'], $job->employer_name, ucfirst($placement->status), $url('/candidate/joining')];
                    } elseif ($key === 'joining_update') {
                        if (! in_array($placement->status, ['joining_pending', 'joined'])) {
                            $missing('No current joining outcome.');
                        }
                        $date = $placement->joined_on ?: $placement->expected_joining_on;
                        if (! $date) {
                            $missing('Joining date is missing.');
                        }
                        $params = [$name, $snapshot['role'], $job->employer_name, ucfirst(str_replace('_', ' ', $placement->status)), Carbon::parse($date)->format('d F Y'), $url('/candidate/joining')];
                    } elseif (in_array($key, ['retention_day_7', 'retention_day_15', 'retention_day_30'])) {
                        if ($placement->status !== 'joined' || ! $placement->joined_on) {
                            $missing('Candidate has not joined this job.');
                        }
                        $day = (int) substr($key, strlen('retention_day_'));
                        if (Carbon::parse($placement->joined_on, 'Asia/Kolkata')->addDays($day)->startOfDay()->gt(now('Asia/Kolkata')->startOfDay())) {
                            $missing('Day '.$day.' employment check-in is not due yet.');
                        }
                        if (DB::table('retention_responses')->where('placement_outcome_id', $placement->id)->where('status', 'left')->exists()) {
                            $missing('Candidate has reported leaving this job.');
                        }
                        $params = [$name, $job->employer_name, $snapshot['role'], $url('/candidate/retention')];
                    } else {
                        $missing('This template is not supported for candidate recipients.');
                    }
                }
            }
        }
        $params = array_map(fn ($v) => trim(preg_replace('/[\r\n\t]+/', ' ', (string) $v)), $params);
        foreach ($params as $value) {
            if ($value === '' || mb_strlen($value) > 1024) {
                $missing('Required candidate or record value is missing or too long.');
            }
        }

        return ['parameters' => $params, 'proof' => $proof];
    }
}
