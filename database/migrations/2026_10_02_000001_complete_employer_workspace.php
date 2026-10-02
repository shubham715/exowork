<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('employers', function(Blueprint $t) {
   foreach (['website','gstin','pincode','state','district','contact_name','contact_designation','contact_phone','contact_department','document_path','document_name','review_remarks'] as $key) $t->string($key)->nullable();
   $t->text('description')->nullable();
   $t->timestamp('terms_accepted_at')->nullable(); $t->timestamp('privacy_accepted_at')->nullable();
   $t->string('notice_version',40)->nullable();
  });
  Schema::table('job_posts', function(Blueprint $t) {
   foreach (['department','job_type','workplace_type','education','experience','salary_type'] as $key) $t->string($key)->nullable();
   foreach (['responsibilities','skills','benefits','screening_questions'] as $key) $t->text($key)->nullable();
   $t->date('application_deadline')->nullable();
  });
 }
 public function down(): void {
  Schema::table('employers', fn(Blueprint $t) => $t->dropColumn(['website','gstin','pincode','state','district','contact_name','contact_designation','contact_phone','contact_department','document_path','document_name','review_remarks','description','terms_accepted_at','privacy_accepted_at','notice_version']));
  Schema::table('job_posts', fn(Blueprint $t) => $t->dropColumn(['department','job_type','workplace_type','education','experience','salary_type','responsibilities','skills','benefits','screening_questions','application_deadline']));
 }
};
