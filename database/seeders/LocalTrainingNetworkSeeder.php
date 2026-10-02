<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocalTrainingNetworkSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('LocalTrainingNetworkSeeder may only run outside production.');
        }

        $partners = [
            ['code' => 'EXO-TP-0001', 'name' => 'ABC Skills Foundation', 'legal_name' => 'ABC Skills Foundation Private Limited', 'pan' => 'AAECA1234F', 'gst_udyam' => '08AAECA1234F1Z2', 'email' => 'operations@abcskills.example', 'phone' => '9876501001', 'authorized_person' => 'Amit Sharma', 'authorized_person_phone' => '9876501002', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'address' => 'Sitapura Industrial Area, Jaipur', 'status' => 'verified'],
            ['code' => 'EXO-TP-0002', 'name' => 'Rajasthan Technical Training Network', 'legal_name' => 'Rajasthan Technical Training Network', 'pan' => 'AAACR4321K', 'gst_udyam' => 'UDYAM-RJ-17-0012345', 'email' => 'network@rttn.example', 'phone' => '9876502001', 'authorized_person' => 'Meera Rathore', 'authorized_person_phone' => '9876502002', 'state' => 'Rajasthan', 'district' => 'Ajmer', 'address' => 'Civil Lines, Ajmer', 'status' => 'verified'],
            ['code' => 'EXO-TP-0003', 'name' => 'Udaan Livelihood Society', 'legal_name' => 'Udaan Livelihood Society', 'pan' => 'AAATU7890M', 'gst_udyam' => 'UDYAM-RJ-29-0098765', 'email' => 'contact@udaan.example', 'phone' => '9876503001', 'authorized_person' => 'Nisha Khan', 'authorized_person_phone' => '9876503002', 'state' => 'Rajasthan', 'district' => 'Tonk', 'address' => 'Near Roadways Depot, Tonk', 'status' => 'pending'],
        ];

        foreach ($partners as $partner) {
            DB::table('training_partners')->updateOrInsert(['code' => $partner['code']], [...$partner, 'created_at' => now(), 'updated_at' => now()]);
        }

        $partnerIds = DB::table('training_partners')->pluck('id', 'code');
        $centers = [
            ['training_partner_id' => $partnerIds['EXO-TP-0001'], 'code' => 'EXO-CTR-0086', 'name' => 'ABC Skill Development Center', 'center_type' => 'Private', 'project_name' => 'CSR Skills Initiative', 'spoc_name' => 'Amit Sharma', 'spoc_phone' => '9876501010', 'spoc_email' => 'jaipur@abcskills.example', 'website' => 'https://example.com/abc-skills', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'city_block' => 'Sanganer', 'address' => 'Plot 24, Sitapura Industrial Area, Jaipur', 'pincode' => '302022', 'nearby_railway_station' => 'Sanganer', 'nearby_bus_stand' => 'Sitapura', 'service_radius_km' => 60, 'sector' => 'Manufacturing', 'ssc_course' => 'Capital Goods Skill Council', 'job_role' => 'Machine Operator', 'minimum_qualification' => '10th pass', 'batch_duration_hours' => 320, 'batch_capacity' => 40, 'placement_commitment_percent' => 70, 'accreditations' => json_encode(['NSQF', 'SSC', 'NSDC']), 'infrastructure_evidence' => json_encode(['Exterior photograph', 'Classroom photograph', 'Machine lab photograph']), 'status' => 'verified', 'verified_at' => now()],
            ['training_partner_id' => $partnerIds['EXO-TP-0002'], 'code' => 'EXO-CTR-0062', 'name' => 'Government ITI Ajmer', 'center_type' => 'ITI', 'project_name' => 'State Skill Mission', 'spoc_name' => 'Rajesh Meena', 'spoc_phone' => '9876502010', 'spoc_email' => 'ajmer@rttn.example', 'website' => null, 'state' => 'Rajasthan', 'district' => 'Ajmer', 'city_block' => 'Ajmer City', 'address' => 'Makhupura Industrial Area, Ajmer', 'pincode' => '305002', 'nearby_railway_station' => 'Ajmer Junction', 'nearby_bus_stand' => 'Makhupura', 'service_radius_km' => 80, 'sector' => 'Logistics', 'ssc_course' => 'Logistics Sector Skill Council', 'job_role' => 'Warehouse Associate', 'minimum_qualification' => '10th pass', 'batch_duration_hours' => 240, 'batch_capacity' => 30, 'placement_commitment_percent' => 75, 'accreditations' => json_encode(['NSQF', 'DGT']), 'infrastructure_evidence' => json_encode(['Campus photograph', 'Classroom photograph']), 'status' => 'verified', 'verified_at' => now()],
            ['training_partner_id' => $partnerIds['EXO-TP-0003'], 'code' => 'EXO-CTR-0114', 'name' => 'Udaan Skills Tonk', 'center_type' => 'NGO', 'project_name' => 'DDU-GKY', 'spoc_name' => 'Nisha Khan', 'spoc_phone' => '9876503010', 'spoc_email' => 'tonk@udaan.example', 'website' => null, 'state' => 'Rajasthan', 'district' => 'Tonk', 'city_block' => 'Tonk City', 'address' => 'Near Roadways Depot, Tonk', 'pincode' => '304001', 'nearby_railway_station' => 'Banasthali Niwai', 'nearby_bus_stand' => 'Tonk Roadways', 'service_radius_km' => 50, 'sector' => 'Retail', 'ssc_course' => 'Retailers Association Skill Council of India', 'job_role' => 'Retail Sales Associate', 'minimum_qualification' => '10th pass', 'batch_duration_hours' => 200, 'batch_capacity' => 35, 'placement_commitment_percent' => 65, 'accreditations' => json_encode(['NSQF', 'SSC']), 'infrastructure_evidence' => json_encode(['Exterior photograph']), 'status' => 'pending', 'verified_at' => null],
        ];

        foreach ($centers as $center) {
            DB::table('training_centers')->updateOrInsert(['code' => $center['code']], [...$center, 'created_at' => now(), 'updated_at' => now()]);
        }

        $centerIds = DB::table('training_centers')->pluck('id', 'code');
        $batches = [
            ['training_center_id' => $centerIds['EXO-CTR-0086'], 'code' => 'MAN-26-08', 'job_role' => 'Machine Operator', 'starts_on' => '2026-08-01', 'ends_on' => '2026-11-30', 'capacity' => 40, 'status' => 'active'],
            ['training_center_id' => $centerIds['EXO-CTR-0062'], 'code' => 'LOG-26-07', 'job_role' => 'Warehouse Associate', 'starts_on' => '2026-07-15', 'ends_on' => '2026-10-15', 'capacity' => 30, 'status' => 'active'],
            ['training_center_id' => $centerIds['EXO-CTR-0114'], 'code' => 'RTL-26-10', 'job_role' => 'Retail Sales Associate', 'starts_on' => '2026-10-15', 'ends_on' => '2027-01-15', 'capacity' => 35, 'status' => 'planned'],
        ];
        foreach ($batches as $batch) {
            DB::table('training_batches')->updateOrInsert(['training_center_id' => $batch['training_center_id'], 'code' => $batch['code']], [...$batch, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
