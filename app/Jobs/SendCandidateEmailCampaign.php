<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\CampaignEmailSender;
use App\Services\CommunicationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendCandidateEmailCampaign implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $campaignId) {}

    public function handle(CampaignEmailSender $sender): void
    {
        app(CommunicationSettings::class)->apply();
        if (config('mail.default') !== 'smtp') {
            return;
        }
        $campaign = DB::table('email_campaigns')->find($this->campaignId);
        $actor = $campaign ? User::find($campaign->created_by) : null;
        if (! $actor || $actor->status !== 'active' || ! $actor->hasPermission('masters.manage') || ! $actor->hasPermission('candidates.update') || ! DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $actor->id)->whereIn('roles.key', ['admin', 'superadmin'])->exists()) {
            DB::table('email_deliveries')->where('campaign_id', $this->campaignId)->where('status', 'pending')->update(['status' => 'skipped', 'error' => 'Sender permissions changed.', 'updated_at' => now()]);

            return;
        }
        for ($i = 0; $i < 2; $i++) {
            if (! DB::table('email_deliveries')->where('campaign_id', $this->campaignId)->where('status', 'pending')->exists()) {
                return;
            }
            $sender->process($this->campaignId);
        }
        if (DB::table('email_deliveries')->where('campaign_id', $this->campaignId)->where('status', 'pending')->exists()) {
            self::dispatch($this->campaignId)->delay(now()->addSecond());
        }
    }
}
