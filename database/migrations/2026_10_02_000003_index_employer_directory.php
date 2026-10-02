<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('employers', function(Blueprint $table) {
            $table->index(['deleted_at','created_at','id'],'employers_directory_date');
            $table->index(['deleted_at','status','created_at','id'],'employers_directory_status');
            $table->index(['deleted_at','industry','state'],'employers_directory_location');
        });
    }
    public function down(): void {
        Schema::table('employers', function(Blueprint $table) {
            $table->dropIndex('employers_directory_date');
            $table->dropIndex('employers_directory_status');
            $table->dropIndex('employers_directory_location');
        });
    }
};
