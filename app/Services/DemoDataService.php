<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoDataService
{
    public const SECTIONS = ['candidates', 'employers', 'training_centers', 'job_posts'];

    public function generate(): string
    {
        return DB::transaction(function () {
            $run = (string) Str::uuid();
            $tag = substr(str_replace('-', '', $run), 0, 12);
            $password = 'Demo@'.Str::random(12);
            $now = now();
            $insert = fn ($table, $data) => DB::table($table)->insertGetId([...$data, 'demo_run_id' => $run, 'created_at' => $now, 'updated_at' => $now]);
            $credential = function ($section, $table, $id, $name, $email, $pass) use ($run, $now) {
                DB::table('demo_credentials')->insert(['section' => $section, 'entity_table' => $table, 'entity_id' => $id, 'name' => $name, 'email' => $email, 'password' => Crypt::encryptString($pass), 'demo_run_id' => $run, 'created_at' => $now, 'updated_at' => $now]);
            };
            $account = function ($section, $role, $name, $email, $org = []) use ($insert, $credential, $password, $now) {
                $id = $insert('users', ['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'status' => 'active', 'email_verified_at' => $now]);
                DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => DB::table('roles')->where('key', $role)->value('id')]);
                if ($org) DB::table('organization_memberships')->insert([...$org, 'user_id' => $id, 'membership_role' => 'owner', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
                $credential($section, 'users', $id, $name, $email, $password);
                return $id;
            };
            // Reuse an existing demo administrator; never change any existing administrator's password.
            if (!DB::table('demo_credentials')->where('section', 'admin')->exists()) {
                $account('admin', 'superadmin', 'Demo Administrator', "admin.$tag@example.test");
            }
            $sectors = ['Manufacturing', 'Logistics', 'Retail', 'Hospitality', 'IT-ITES'];
            $roles = ['Machine Operator', 'Warehouse Associate', 'Retail Sales Associate', 'Front Office Associate', 'Data Entry Operator'];
            $skills = ['Machine operation, Safety', 'Inventory, Packing', 'Customer service, Sales', 'Customer service, Communication', 'Typing, Excel'];
            $cities = ['Jaipur', 'Ajmer', 'Kota', 'Udaipur', 'Jodhpur'];
            $companies = ['Aravali Precision Works', 'Desert Logistics', 'CityMart Retail', 'Lakeview Hospitality', 'Nimbus Business Services'];
            $centers = []; $jobs = [];
            for ($i = 0; $i < 5; $i++) {
                $email = "center".($i + 1).".$tag@example.test";
                $partner = $insert('training_partners', ['code' => "DM-TP-$tag-$i", 'name' => $cities[$i].' Skills Foundation', 'legal_name' => $cities[$i].' Skills Foundation', 'email' => $email, 'phone' => '900'.random_int(1000000, 9999999), 'state' => 'Rajasthan', 'district' => $cities[$i], 'address' => 'Skill Campus, '.$cities[$i], 'status' => 'verified', 'verified_at' => $now]);
                $center = $insert('training_centers', ['training_partner_id' => $partner, 'code' => "DM-TC-$tag-$i", 'name' => $cities[$i].' Skill Development Center', 'center_type' => 'Private', 'project_name' => 'Demo Skill Mission', 'spoc_name' => 'Center Coordinator '.($i + 1), 'spoc_email' => $email, 'spoc_phone' => '900'.random_int(1000000, 9999999), 'state' => 'Rajasthan', 'district' => $cities[$i], 'address' => 'Skill Campus, '.$cities[$i], 'pincode' => '302001', 'sector' => $sectors[$i], 'job_role' => $roles[$i], 'minimum_qualification' => '12th pass', 'batch_duration_hours' => 240, 'batch_capacity' => 20, 'placement_commitment_percent' => 75, 'accreditations' => json_encode(['NSQF']), 'status' => 'verified', 'verified_at' => $now, 'onboarding_step' => 3]);
                $owner = $account('training_centers', 'training_partner', $cities[$i].' Center Coordinator', $email, ['training_partner_id' => $partner, 'training_center_id' => $center]);
                $batches = [];
                foreach (['completed', 'active', 'planned'] as $b => $status) {
                    $start = $b === 0 ? now()->subDays(120) : ($b === 1 ? now()->subDays(45) : now()->addDays(15));
                    $end = $b === 0 ? now()->subDays(15 + $i) : ($b === 1 ? now()->addDays(10 + $i * 7) : now()->addDays(105));
                    $code = "DM-$tag-$i-$b";
                    $batch = $insert('training_batches', ['training_center_id' => $center, 'code' => $code, 'job_role' => $roles[$i], 'sector' => $sectors[$i], 'trainer' => 'Trainer '.($i + 1), 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(), 'capacity' => 20, 'status' => $status]);
                    $batches[] = ['id' => $batch, 'code' => $code, 'end' => $end->toDateString()];
                }
                $centers[] = compact('partner', 'center', 'owner', 'batches');
                $email = "employer".($i + 1).".$tag@example.test";
                $employer = $insert('employers', ['code' => "DM-EMP-$tag-$i", 'name' => $companies[$i], 'legal_name' => $companies[$i].' Private Limited', 'industry' => $sectors[$i], 'email' => $email, 'phone' => '900'.random_int(1000000, 9999999), 'contact_name' => 'Hiring Manager '.($i + 1), 'contact_designation' => 'HR Manager', 'address' => 'Business Park, '.$cities[$i], 'state' => 'Rajasthan', 'district' => $cities[$i], 'status' => 'verified', 'verified_at' => $now, 'description' => 'Demo employer recruiting trained and independent candidates.', 'terms_accepted_at' => $now, 'privacy_accepted_at' => $now]);
                $user = $account('employers', 'employer', $companies[$i].' HR', $email, ['employer_id' => $employer]);
                for ($j = 0; $j < 4; $j++) {
                    $jobs[$i][] = $insert('job_posts', ['employer_id' => $employer, 'title' => $roles[$i].' - '.['Day Shift', 'Evening Shift', 'Trainee', 'Associate'][$j], 'description' => 'Join '.$companies[$i].' in '.$cities[$i].'. Training, safe working conditions and career development provided.', 'industry' => $sectors[$i], 'state' => 'Rajasthan', 'district' => $cities[$i], 'location' => $cities[$i].' Business Park', 'openings' => 5 + $j, 'salary_min' => 14000 + $i * 1000, 'salary_max' => 24000 + $i * 1000, 'status' => 'published', 'published_at' => $now, 'created_by_user_id' => $user, 'job_type' => 'Full-time', 'workplace_type' => 'On-site', 'education' => '12th pass', 'experience' => 'Fresher', 'salary_type' => 'Monthly', 'skills' => $skills[$i], 'responsibilities' => 'Perform daily operations, follow safety procedures and maintain accurate records.', 'benefits' => 'Paid training, PF, ESI', 'screening_questions' => '[]', 'application_deadline' => now()->addDays(30 + $j * 7)->toDateString()]);
                }
            }
            $first = ['Aarav','Ananya','Rohan','Priya','Vikram','Neha','Arjun','Kavya','Rahul','Pooja'];
            $last = ['Sharma','Verma','Meena','Singh','Patel'];
            $pictures = glob(base_path('../documents/sampel/*.png')) ?: [];
            for ($n = 0; $n < 50; $n++) {
                $i = $n < 40 ? intdiv($n, 8) : ($n - 40) % 5;
                $linked = $n < 40; $b = $n % 8 < 4 ? 0 : 1;
                $network = $centers[$i]; $batch = $network['batches'][$b];
                $name = $first[$n % 10].' '.$last[intdiv($n, 10)];
                $email = 'candidate'.($n + 1).".$tag@example.test";
                $phone = (string) random_int(7000000000, 7999999999);
                while (DB::table('candidates')->where('whatsapp', $phone)->exists()) $phone = (string) random_int(7000000000, 7999999999);
                $photo = null;
                if ($pictures && $n % 3 !== 0) {
                    $photo = 'demo-profiles/'.basename($pictures[$n % count($pictures)]);
                    Storage::disk('public')->put($photo, file_get_contents($pictures[$n % count($pictures)]));
                }
                $id = $insert('candidates', ['candidate_code' => "DM-C-$tag-".($n + 1), 'first_name' => $first[$n % 10], 'last_name' => $last[intdiv($n, 10)], 'whatsapp' => $phone, 'email' => $email, 'password' => Hash::make($password), 'gender' => $n % 2 ? 'Female' : 'Male', 'age' => 20 + $n % 9, 'qualification' => '12th pass', 'state' => 'Rajasthan', 'district' => $cities[$i], 'current_location' => $cities[$i], 'permanent_location' => $cities[$i], 'preferred_locations' => json_encode([$cities[$i], 'Jaipur']), 'relocation_preference' => 'Yes', 'skills' => $skills[$i], 'experience_type' => 'Fresher', 'expected_monthly_salary' => 15000 + $i * 1000, 'availability' => $linked && $b === 1 ? 'Available after training' : 'Yes', 'preferred_industry' => $sectors[$i], 'languages' => json_encode(['Hindi', 'English']), 'training_status' => $linked ? ($b === 0 ? 'Completed' : 'Ongoing') : 'Not enrolled', 'training_partner_id' => $linked ? $network['partner'] : null, 'training_center_id' => $linked ? $network['center'] : null, 'training_batch_id' => $linked ? $batch['id'] : null, 'training_partner' => $linked ? $cities[$i].' Skills Foundation' : null, 'training_center' => $linked ? $cities[$i].' Skill Development Center' : null, 'batch_code' => $linked ? $batch['code'] : null, 'batch_end_date' => $linked ? $batch['end'] : null, 'created_by_user_id' => $linked ? $network['owner'] : null, 'profile_status' => 'active', 'photo_path' => $photo, 'registration_source' => $linked ? 'center' : 'public', 'location_consent' => true, 'photo_consent' => true, 'call_consent' => true, 'sms_consent' => true, 'whatsapp_consent' => true, 'email_consent' => true, 'terms_accepted_at' => $now, 'privacy_accepted_at' => $now, 'last_active_at' => $now]);
                $state = DB::table('states')->where('name', 'Rajasthan')->value('id');
                DB::table('candidates')->where('id', $id)->update(['state_id' => $state, 'district_id' => DB::table('districts')->where('state_id', $state)->where('name', $cities[$i])->value('id'), 'qualification_option_id' => DB::table('master_options')->where('type', 'qualification')->where('name', '12th pass')->value('id'), 'experience_level_option_id' => DB::table('master_options')->where('type', 'experience_level')->where('name', 'Fresher')->value('id'), 'industry_option_id' => DB::table('master_options')->where('type', 'industry')->where('name', $sectors[$i])->value('id')]);
                foreach (DB::table('master_options')->where('type', 'language')->whereIn('name', ['Hindi', 'English'])->pluck('id') as $language) DB::table('candidate_languages')->insert(['candidate_id' => $id, 'language_option_id' => $language]);
                $credential('candidates', 'candidates', $id, $name, $email, $password);
                if ($linked) DB::table('candidate_batch_assignments')->insert(['candidate_id' => $id, 'training_batch_id' => $batch['id'], 'assigned_by_user_id' => $network['owner'], 'created_at' => $now]);
                $application = DB::table('applications')->insertGetId(['candidate_id' => $id, 'job_post_id' => $jobs[$i][$n % 4], 'status' => 'interested', 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                if ($n % 5 === 0) DB::table('interviews')->insert(['application_id' => $application, 'scheduled_at' => now()->addDays(3 + $n % 7), 'mode' => 'Online', 'location' => 'Demo video interview', 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now]);
            }
            return $run;
        });
    }

    public function clean(string $section, bool $dummyOnly): void
    {
        DB::transaction(function () use ($section, $dummyOnly) {
            foreach ($section === 'all' ? self::SECTIONS : [$section] as $table) {
                $query = DB::table($table);
                if ($dummyOnly) $query->whereNotNull('demo_run_id');
                $ids = $query->pluck('id')->all();
                if ($table === 'training_centers') {
                    DB::table('candidates')->whereIn('training_center_id', $ids)->update(['training_partner_id' => null, 'training_center_id' => null, 'training_batch_id' => null, 'training_partner' => null, 'training_center' => null, 'batch_code' => null, 'batch_end_date' => null, 'training_status' => 'Not enrolled', 'availability' => 'Yes']);
                }
                $users = in_array($table, ['employers', 'training_centers']) ? DB::table('organization_memberships')->whereIn($table === 'employers' ? 'employer_id' : 'training_center_id', $ids)->pluck('user_id')->all() : [];
                if (in_array($table, ['employers', 'training_centers'])) DB::table('organization_memberships')->whereIn($table === 'employers' ? 'employer_id' : 'training_center_id', $ids)->delete();
                $this->deleteRows($table, $ids);
                foreach ($users as $user) {
                    $protected = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_id', $user)->whereIn('roles.key', ['admin', 'superadmin', 'agent'])->exists();
                    if (!$protected && !DB::table('organization_memberships')->where('user_id', $user)->exists()) $this->deleteRows('users', [$user]);
                }
            }
            if ($section === 'all') {
                $partners = DB::table('training_partners');
                if ($dummyOnly) $partners->whereNotNull('demo_run_id');
                $this->deleteRows('training_partners', $partners->pluck('id')->all());
                $protected = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->whereIn('roles.key', ['admin', 'superadmin'])->pluck('user_id');
                $accounts = DB::table('users')->whereNotIn('id', $protected);
                if ($dummyOnly) $accounts->whereNotNull('demo_run_id');
                $this->deleteRows('users', $accounts->pluck('id')->all());
                if (!$dummyOnly) DB::table('candidate_drafts')->delete();
            }
        });
    }

    // Explicit application relationships. Do not enumerate information_schema during cleanup:
    // the bundled MariaDB instance is unstable when inspecting its damaged dictionary.
    private function deleteRows(string $table, array $ids): void
    {
        $delete = [
            'candidates' => ['applications' => 'candidate_id', 'candidate_followups' => 'candidate_id', 'whatsapp_messages' => 'candidate_id'],
            'job_posts' => ['applications' => 'job_post_id'],
            'applications' => ['interviews' => 'application_id', 'placement_outcomes' => 'application_id'],
            'employers' => ['job_posts' => 'employer_id'],
            'training_centers' => ['training_batches' => 'training_center_id'],
            'training_batches' => ['candidate_batch_assignments' => 'training_batch_id'],
            'users' => ['candidate_followups' => 'agent_id', 'candidate_batch_assignments' => 'assigned_by_user_id', 'email_campaigns' => 'created_by'],
        ];
        $detach = [
            'applications' => ['candidate_followups' => ['application_id']],
            'employers' => ['whatsapp_messages' => ['employer_id']],
            'training_batches' => ['candidates' => ['training_batch_id'], 'candidate_batch_assignments' => ['previous_batch_id']],
            'training_partners' => ['candidates' => ['training_partner_id'], 'training_centers' => ['training_partner_id']],
            'users' => [
                'candidates' => ['created_by_user_id', 'assigned_agent_id'],
                'training_partners' => ['verified_by_user_id'], 'training_centers' => ['verified_by_user_id'],
                'employers' => ['verified_by_user_id'], 'candidate_consents' => ['recorded_by_user_id'],
                'candidate_documents' => ['uploaded_by_user_id'], 'job_posts' => ['created_by_user_id'],
                'applications' => ['submitted_by_user_id'], 'interviews' => ['agent_id'],
                'placement_outcomes' => ['verified_by_user_id'], 'audit_logs' => ['actor_user_id'],
                'whatsapp_messages' => ['actor_user_id'],
            ],
        ];
        foreach (array_chunk($ids, 200) as $chunk) {
            foreach ($delete[$table] ?? [] as $child => $column) {
                $this->deleteRows($child, DB::table($child)->whereIn($column, $chunk)->pluck('id')->all());
            }
            foreach ($detach[$table] ?? [] as $child => $columns) {
                foreach ($columns as $column) DB::table($child)->whereIn($column, $chunk)->update([$column => null]);
            }
            if ($table === 'users') {
                DB::table('sessions')->whereIn('user_id', $chunk)->delete();
                DB::table('password_reset_tokens')->whereIn('email', DB::table('users')->whereIn('id', $chunk)->select('email'))->delete();
            }
            DB::table('demo_credentials')->where('entity_table', $table)->whereIn('entity_id', $chunk)->delete();
            DB::table($table)->whereIn('id', $chunk)->delete();
        }
    }
}
