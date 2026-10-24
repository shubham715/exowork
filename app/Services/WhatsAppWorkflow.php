<?php

namespace App\Services;

use App\Models\Candidate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WhatsAppWorkflow
{
    private array $settings = [];

    private ?float $deadline = null;

    public function run(?float $deadline = null): void
    {
        $this->deadline = $deadline;
        try {
            $this->runStages();
        } catch (WhatsAppWorkflowBudgetExceeded $e) {
            // The next cron run safely resumes deduplicated stage checks.
        } finally {
            $this->deadline = null;
        }
    }

    private function runStages(): void
    {
        app(CommunicationSettings::class)->apply();
        $this->settings = app(CommunicationSettings::class)->get('whatsapp_workflow');
        $cloud = app(WhatsAppCloud::class);
        if (config('whatsapp.provider') === 'gupshup' && ! config('whatsapp.gupshup_automation_enabled')) return;
        if ($cloud->missing()) return;
        $start = $this->settings['start_at'] ?? now()->toDateTimeString();
        Candidate::whereNull('deleted_at')->where('profile_status', 'active')->where('whatsapp_consent', true)->chunkById(100, function ($candidates) use ($start) {
            foreach ($candidates as $c) {
                if ($c->created_at->gte(Carbon::parse($start, 'UTC'))) $this->candidate('welcome', $c, [$c->first_name, url('/candidate/profile')], 'welcome:'.$c->id);
                if ($c->job_interest_status !== 'interested') continue;
                if ($c->training_status === 'Ongoing' && ! $c->batch_end_date) continue;
                $deferred = $c->training_status === 'Ongoing' && $c->batch_end_date && $c->batch_end_date->toDateString() > now('Asia/Kolkata')->addDays(15)->toDateString();
                if ($deferred) {
                    $this->candidate('training_reconnect', $c, [$c->first_name, $c->batch_end_date->format('d F Y'), url('/candidate/profile')], 'deferred:'.$c->id.':'.$c->batch_end_date->format('Y-m-d'));
                    continue;
                }
                if (! in_array($c->availability, ['Yes', 'Available after training'])) continue;
                if (! empty($this->settings['career_video_url'])) $this->candidate('career_intro', $c, [$c->first_name, $this->settings['career_video_url'], url('/candidate/opportunities')], 'career:'.$c->id);
                $jobs = DB::table('job_posts as j')->join('employers as e', 'e.id', '=', 'j.employer_id')->whereIn('j.status', ['active', 'published'])->where('e.status', 'verified')->whereNull('e.deleted_at')->whereNull('j.deleted_at')->where(fn ($q) => $q->whereNull('j.application_deadline')->orWhere('j.application_deadline', '>=', now('Asia/Kolkata')->toDateString()))->select('j.*', 'e.name as hidden_employer_name')->orderByDesc('j.id')->limit(100)->get();
                $sent = 0;
                foreach ($jobs as $job) {
                    if ($sent >= 3) break;
                    if (! app(JobEligibility::class)->matches($job, $c)) continue;
                    // Only structured role/location/salary; do not expose descriptions or employer identity.
                    if (DB::table('whatsapp_messages')->where('event_key', 'job:'.$c->id.':'.$job->id)->exists()) continue;
                    $role = $this->anonymousRole($job->title, $job->hidden_employer_name);
                    if ($this->candidate('job_opportunity', $c, [$c->first_name, $role, $job->district ?: $job->state ?: 'See your account', $this->salary($job), url('/candidate/opportunities')], 'job:'.$c->id.':'.$job->id)) $sent++;
                }
            }
        });
        DB::table('applications')->where('created_at', '>=', $start)->where('status', 'interested')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $r) {
                $c = Candidate::find($r->candidate_id); $job = DB::table('job_posts')->find($r->job_post_id);
                if ($c && $job) $this->candidate('interview_availability', $c, [$c->first_name, $job->title, url('/candidate/interests')], 'availability:'.$r->id);
            }
        });
        DB::table('interviews')->whereIn('status', ['scheduled', 'confirmed'])->where('scheduled_at', '>=', now())->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $r) {
                [$c, $job, $employer] = $this->context($r->application_id);
                if (! $c || ! $job || ! $employer) continue;
                $when = Carbon::parse($r->scheduled_at, 'UTC')->setTimezone('Asia/Kolkata');
                $event = 'interview:'.$r->id.':'.Carbon::parse($r->scheduled_at)->timestamp;
                if ($r->location) $this->candidate('interview_scheduled', $c, [$c->first_name, $job->title, $employer->name, $when->format('d F Y, h:i A').' IST', $r->location, $employer->contact_name ?: 'EXOWORK coordinator', $this->salary($job), url('/candidate/interviews')], $event);
                if ($when->toDateString() === now('Asia/Kolkata')->addDay()->toDateString()) $this->candidate('interview_reminder', $c, [$c->first_name, $job->title, $when->format('d F Y, h:i A').' IST', url('/candidate/interviews')], 'reminder:'.$event);
                if (! $r->attendance_response && DB::table('whatsapp_messages')->where('event_key', 'reminder:'.$event)->whereIn('status', ['delivered', 'read'])->where('updated_at', '<', now()->subHours(6))->exists()) app(EmploymentFollowups::class)->create($c->id, $r->application_id, 'interview_no_response:'.$event, 'No attendance response after 6 hours. Call before the interview.');
                $this->center($c, $job, $employer, 'Interview scheduled', $when->format('d F Y, h:i A').' IST', $event);
            }
        });
        DB::table('placement_outcomes')->where('updated_at', '>=', $this->settings['start_at'] ?? now())->orWhereNotNull('joined_on')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $r) {
                [$c, $job, $employer] = $this->context($r->application_id);
                if (! $c || ! $job || ! $employer) continue;
                $date = $r->joined_on ?: $r->expected_joining_on ?: 'To be confirmed';
                $event = 'placement:'.$r->id.':'.$r->status.':'.$date;
                if ($r->updated_at >= ($this->settings['start_at'] ?? now()->toDateTimeString())) {
                    if (in_array($r->status, ['selected', 'rejected'])) $this->candidate('selection_update', $c, [$c->first_name, $job->title, $employer->name, ucfirst($r->status), url('/candidate/joining')], $event);
                    if (in_array($r->status, ['joining_pending', 'joined'])) $this->candidate('joining_update', $c, [$c->first_name, $job->title, $employer->name, str_replace('_', ' ', ucfirst($r->status)), $date, url('/candidate/joining')], $event);
                    $this->center($c, $job, $employer, str_replace('_', ' ', ucfirst($r->status)), $date, $event);
                }
                if ($r->status === 'joining_pending' && $r->expected_joining_on && $r->expected_joining_on < now('Asia/Kolkata')->toDateString()) app(EmploymentFollowups::class)->create($c->id, $r->application_id, 'joining_pending:'.$r->id.':'.$r->expected_joining_on, 'High-risk follow-up: expected joining date has passed without confirmed joining.');
                if ($r->status !== 'joined' || ! $r->joined_on) continue;
                foreach ([7, 15, 30] as $day) {
                    if (Carbon::parse($r->joined_on, 'Asia/Kolkata')->addDays($day)->isSameDay(now('Asia/Kolkata')) && ! DB::table('retention_responses')->where('placement_outcome_id', $r->id)->where('status', 'left')->exists()) $this->candidate('retention_day_'.$day, $c, [$c->first_name, $employer->name, $job->title, url('/candidate/retention')], 'retention:'.$r->id.':'.$r->joined_on.':'.$day);
                    if (! DB::table('retention_responses')->where('placement_outcome_id', $r->id)->where('day', $day)->exists() && DB::table('whatsapp_messages')->where('event_key', 'retention:'.$r->id.':'.$r->joined_on.':'.$day)->whereIn('status', ['delivered', 'read'])->where('updated_at', '<', now()->subDay())->exists()) app(EmploymentFollowups::class)->create($c->id, $r->application_id, 'retention_no_response:'.$r->id.':'.$day, 'No response to delivered day '.$day.' employment check-in after 24 hours.');
                }
            }
        });
    }

    private function anonymousRole(string $title, string $employer): string
    {
        $role = str_ireplace($employer, '', $title);
        $role = preg_replace('/https?:\/\/\S+|[\w.+-]+@[\w.-]+|\+?[\d\s()-]{8,}/', '', $role);
        return trim($role, " \t\n\r\0\x0B-|,:;") ?: 'Job';
    }

    private function candidate(string $key, Candidate $c, array $parameters, string $event): bool
    {
        if ($this->deadline !== null && microtime(true) >= $this->deadline) throw new WhatsAppWorkflowBudgetExceeded();
        if ($c->deleted_at || $c->profile_status !== 'active' || ! $c->whatsapp_consent) return false;
        try { $number = app(WhatsAppCloud::class)->recipient($c->whatsapp); } catch (ValidationException $e) { return false; }
        if (DB::table('whatsapp_opt_outs')->where('recipient', $number)->exists() || DB::table('whatsapp_messages')->where('event_key', $event)->exists()) return false;
        return (bool) app(WhatsAppOutbox::class)->system($key, $number, array_map(fn ($v) => preg_replace('/[\r\n\t]+/', ' ', (string) $v), $parameters), ['candidate_id' => $c->id, 'event_key' => $event]);
    }

    private function center(Candidate $c, object $job, object $employer, string $status, string $date, string $event): void
    {
        if ($this->deadline !== null && microtime(true) >= $this->deadline) throw new WhatsAppWorkflowBudgetExceeded();
        $center = $c->training_center_id ? DB::table('training_centers')->find($c->training_center_id) : null;
        if (! $center || $center->deleted_at || ! DB::table('whatsapp_center_preferences')->where('training_center_id', $center->id)->where('consent', true)->exists() || ! $center->spoc_phone) return;
        try { $number = app(WhatsAppCloud::class)->recipient($center->spoc_phone); } catch (ValidationException $e) { return; }
        if (DB::table('whatsapp_opt_outs')->where('recipient', $number)->exists() || DB::table('whatsapp_messages')->where('event_key', 'center:'.$event)->exists()) return;
        app(WhatsAppOutbox::class)->system('center_placement_update', $number, [$center->name, trim($c->first_name.' '.$c->last_name), $c->candidate_code, $job->title, $employer->name, $status, $date, url('/center/interviews')], ['training_center_id' => $center->id, 'event_key' => 'center:'.$event]);
    }

    private function context(int $applicationId): array
    {
        $a = DB::table('applications')->find($applicationId);
        $c = $a ? Candidate::find($a->candidate_id) : null;
        $job = $a ? DB::table('job_posts')->whereNull('deleted_at')->find($a->job_post_id) : null;
        $employer = $job ? DB::table('employers')->whereNull('deleted_at')->where('status', 'verified')->find($job->employer_id) : null;
        return [$c, $job, $employer];
    }

    private function salary(object $job): string
    {
        return $job->salary_min || $job->salary_max ? 'INR '.number_format($job->salary_min ?: $job->salary_max).($job->salary_max && $job->salary_min && $job->salary_max !== $job->salary_min ? ' - '.number_format($job->salary_max) : '') : 'To be discussed';
    }
}
