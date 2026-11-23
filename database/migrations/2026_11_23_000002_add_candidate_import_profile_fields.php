<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable();
            foreach (['father_name', 'mother_name', 'training_course', 'employment_type'] as $field) {
                $table->string($field)->nullable();
            }
            foreach (['permanent', 'communication'] as $prefix) {
                foreach (['city_village', 'district', 'block', 'tehsil', 'municipality', 'pincode'] as $field) {
                    $table->string($prefix.'_'.$field)->nullable();
                }
            }
            $table->text('current_location')->change();
            $table->text('permanent_location')->change();
        });
    }

    public function down(): void
    {
        $columns = ['date_of_birth', 'father_name', 'mother_name', 'training_course', 'employment_type'];
        foreach (['permanent', 'communication'] as $prefix) {
            foreach (['city_village', 'district', 'block', 'tehsil', 'municipality', 'pincode'] as $field) {
                $columns[] = $prefix.'_'.$field;
            }
        }
        Schema::table('candidates', fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
