<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CandidateMasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $options = [
            'qualification' => ['8th pass', '10th pass', '12th pass', 'ITI', 'Diploma', 'Graduate', 'Postgraduate'],
            'experience_level' => ['Fresher', 'Experienced'],
            'industry' => ['Manufacturing', 'Logistics', 'Retail', 'Automotive', 'IT-ITES', 'Hospitality'],
            'language' => ['Hindi', 'English', 'Rajasthani', 'Bengali', 'Marathi', 'Tamil', 'Telugu', 'Gujarati', 'Punjabi', 'Urdu', 'Other'],
        ];
        foreach ($options as $type => $names) {
            foreach ($names as $index => $name) {
                DB::table('master_options')->insertOrIgnore([
                    'type' => $type, 'slug' => Str::slug($name), 'name' => $name,
                    'sort_order' => $index + 1, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $states = [
            'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa',
            'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala',
            'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland',
            'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
            'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Andaman and Nicobar Islands',
            'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi',
            'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
        ];
        foreach ($states as $index => $name) {
            DB::table('states')->insertOrIgnore([
                'slug' => Str::slug($name), 'name' => $name,
                'sort_order' => $index + 1, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $districts = [
            'Rajasthan' => ['Jaipur', 'Jodhpur', 'Udaipur', 'Ajmer', 'Alwar', 'Kota'],
            'Delhi' => ['Central Delhi', 'New Delhi', 'North Delhi', 'South Delhi'],
            'Haryana' => ['Gurugram', 'Faridabad', 'Hisar', 'Rohtak'],
            'Uttar Pradesh' => ['Lucknow', 'Noida', 'Ghaziabad', 'Kanpur Nagar'],
            'Maharashtra' => ['Mumbai City', 'Pune', 'Nagpur'],
            'Gujarat' => ['Ahmedabad', 'Surat', 'Vadodara'],
            'Madhya Pradesh' => ['Bhopal', 'Indore', 'Gwalior'],
            'Karnataka' => ['Bengaluru Urban', 'Mysuru'],
        ];
        foreach ($districts as $stateName => $names) {
            $stateId = DB::table('states')->where('slug', Str::slug($stateName))->value('id');
            foreach ($names as $index => $name) {
                DB::table('districts')->insertOrIgnore([
                    'state_id' => $stateId, 'slug' => Str::slug($name), 'name' => $name,
                    'sort_order' => $index + 1, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
}
