<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CampaignEmailSender
{
    public function process(int $id): void
    {
        $campaign = DB::table('email_campaigns')->find($id);
        if (! $campaign) {
            return;
        }
        $delivery = DB::transaction(function () use ($id) {
            $row = DB::table('email_deliveries')->where('campaign_id', $id)->where('status', 'pending')->lockForUpdate()->first();
            if ($row) {
                DB::table('email_deliveries')->where('id', $row->id)->update(['status' => 'sending', 'updated_at' => now()]);
            }

            return $row;
        });
        if (! $delivery) {
            return;
        }
        try {
            $query = $delivery->recipient_type === 'candidate'
                ? DB::table('candidates')->whereNull('deleted_at')->where('profile_status', 'active')->where('email_consent', true)
                : DB::table('users')->where('status', 'active')->whereExists(fn ($q) => $q->selectRaw('1')->from('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->whereColumn('user_roles.user_id', 'users.id')->where('roles.key', $delivery->recipient_type));
            if (! $query->where('id', $delivery->recipient_id)->where('email', $delivery->email)->exists()) {
                $status = 'skipped';
                $error = 'Recipient unavailable, email changed or consent withdrawn.';
            } else {
                $tokens = ['{{name}}' => $delivery->name, '{{email}}' => $delivery->email, '{{app_name}}' => config('app.name')];
                $content = $delivery->rendered_payload ? json_decode(Crypt::decryptString($delivery->rendered_payload), true, 512, JSON_THROW_ON_ERROR)
                    : ['subject' => strtr($campaign->subject, $tokens), 'body' => strtr($campaign->body, $tokens)];
                $subject = str_replace(["\r", "\n"], ' ', $content['subject']);
                Mail::raw($content['body'], fn ($mail) => $mail->to($delivery->email)->subject($subject));
                $status = 'accepted';
                $error = null;
            }
        } catch (\Throwable $e) {
            $status = 'unknown';
            $error = 'SMTP did not confirm acceptance. Check provider logs before sending again.';
        }
        DB::table('email_deliveries')->where('id', $delivery->id)->update(['status' => $status, 'error' => $error, 'updated_at' => now()]);
    }
}
