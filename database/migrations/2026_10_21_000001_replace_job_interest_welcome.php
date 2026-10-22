<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (\App\Services\WhatsAppTemplateCatalog::all() as $spec) {
            DB::table('whatsapp_templates')->where('system_key', $spec['key'])->update([
                'name' => $spec['name'], 'body' => $spec['body'], 'language' => $spec['language'],
                'category' => $spec['category'], 'review_status' => 'pending', 'meta_status' => 'NOT_SUBMITTED',
                'meta_id' => null, 'synced_at' => null, 'updated_at' => now(),
            ]);
        }
        // Queued old messages contain different variables and must not send after deployment.
        $retired = [...array_map(fn ($spec) => 'exowork_'.$spec['key'], \App\Services\WhatsAppTemplateCatalog::all()), 'exowork_welcome_v1', 'exowork_registration_v2'];
        DB::table('whatsapp_messages')->where('status', 'queued')->whereIn('template_name', $retired)->update([
            'status' => 'skipped', 'error_code' => 'TEMPLATE_RETIRED',
            'error_message' => 'This template was replaced by the formatted workflow catalog.', 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Do not restore retired outreach or replay cancelled messages on rollback.
    }
};
