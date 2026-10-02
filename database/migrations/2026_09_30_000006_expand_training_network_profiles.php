<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_partners', function (Blueprint $table) {
            $table->string('pan', 10)->nullable()->after('legal_name');
            $table->string('gst_udyam', 40)->nullable()->after('pan');
            $table->string('authorized_person')->nullable()->after('phone');
            $table->string('authorized_person_phone', 20)->nullable()->after('authorized_person');
            $table->text('review_remarks')->nullable()->after('status');
        });

        Schema::table('training_centers', function (Blueprint $table) {
            $table->string('center_type', 40)->nullable()->after('name');
            $table->string('project_name', 100)->nullable()->after('center_type');
            $table->string('spoc_name')->nullable()->after('project_name');
            $table->string('spoc_phone', 20)->nullable()->after('spoc_name');
            $table->string('spoc_email')->nullable()->after('spoc_phone');
            $table->string('website')->nullable()->after('spoc_email');
            $table->string('city_block', 100)->nullable()->after('district');
            $table->string('pincode', 6)->nullable()->after('address');
            $table->string('nearby_railway_station')->nullable()->after('pincode');
            $table->string('nearby_bus_stand')->nullable()->after('nearby_railway_station');
            $table->unsignedSmallInteger('service_radius_km')->nullable()->after('nearby_bus_stand');
            $table->string('sector', 100)->nullable()->after('service_radius_km');
            $table->string('ssc_course')->nullable()->after('sector');
            $table->string('job_role')->nullable()->after('ssc_course');
            $table->string('minimum_qualification', 100)->nullable()->after('job_role');
            $table->unsignedInteger('batch_duration_hours')->nullable()->after('minimum_qualification');
            $table->unsignedSmallInteger('batch_capacity')->nullable()->after('batch_duration_hours');
            $table->unsignedTinyInteger('placement_commitment_percent')->nullable()->after('batch_capacity');
            $table->json('accreditations')->nullable()->after('placement_commitment_percent');
            $table->json('infrastructure_evidence')->nullable()->after('accreditations');
            $table->text('review_remarks')->nullable()->after('status');
            $table->foreignId('verified_by_user_id')->nullable()->after('review_remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('training_centers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn([
                'center_type', 'project_name', 'spoc_name', 'spoc_phone', 'spoc_email', 'website',
                'city_block', 'pincode', 'nearby_railway_station', 'nearby_bus_stand', 'service_radius_km',
                'sector', 'ssc_course', 'job_role', 'minimum_qualification', 'batch_duration_hours',
                'batch_capacity', 'placement_commitment_percent', 'accreditations', 'infrastructure_evidence',
                'review_remarks', 'verified_at',
            ]);
        });

        Schema::table('training_partners', function (Blueprint $table) {
            $table->dropColumn(['pan', 'gst_udyam', 'authorized_person', 'authorized_person_phone', 'review_remarks']);
        });
    }
};
