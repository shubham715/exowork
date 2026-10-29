<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  if (!\Illuminate\Support\Facades\Schema::hasColumn('whatsapp_templates','meta_error')) \Illuminate\Support\Facades\Schema::table('whatsapp_templates', fn ($table) => $table->text('meta_error')->nullable());
  $t=\App\Services\WhatsAppTemplateCatalog::get('auth_otp');
  DB::table('whatsapp_templates')->insertOrIgnore(['system_key'=>$t['key'],'name'=>$t['name'],'language'=>$t['language'],'category'=>$t['category'],'body'=>$t['body'],'created_at'=>now(),'updated_at'=>now()]);
 }
 public function down(): void { \Illuminate\Support\Facades\Schema::table('whatsapp_templates', fn ($table) => $table->dropColumn('meta_error')); DB::table('whatsapp_templates')->where('system_key','auth_otp')->delete(); }
};
