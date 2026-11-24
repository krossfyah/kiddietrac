<?php

namespace App\Console\Commands;

use App\Support\SalesCampaigns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every minute (2026-10-26): send the next batch of sales campaign emails, credit website bookings to the campaign
 * that reached the person, and (every 10 minutes, when Sales -> Email is connected) pick up replies.
 * A small batch per minute keeps sales@ well inside Microsoft 365 sending limits and looks like a person, not a blast.
 */
class SalesCampaignsSend extends Command
{
    protected $signature = 'kiddietrac:sales-campaigns {--batch=30}';
    protected $description = 'Send queued sales campaign emails, and record bookings and replies from recipients';

    public function handle(): int
    {
        $budget = max(1, (int) $this->option('batch'));
        $sent = 0;
        /* SCHEDULED CAMPAIGNS (2026-10-27): start one when its time has come AND it is approved. Never unapproved;
           never more than 12 hours late (a campaign approved long after its day does not suddenly go out). */
        if (\Illuminate\Support\Facades\Schema::hasColumn('sales_campaigns', 'scheduled_for')) {
            foreach (DB::table('sales_campaigns')->whereIn('status', ['draft', 'paused'])->whereNull('deleted_at')->whereNotNull('scheduled_for')->whereNotNull('approved_at')
                ->where('scheduled_for', '<=', now())->where('scheduled_for', '>=', now()->subHours(12))->get() as $due) {
                DB::table('sales_campaigns')->where('id', $due->id)->whereIn('status', ['draft', 'paused'])->update([
                    'status' => 'sending', 'sent_by' => $due->approved_by ?: $due->created_by, 'send_started_at' => $due->send_started_at ?: now(), 'updated_at' => now()]);
                $this->info('started scheduled campaign ' . $due->id);
            }
        }
        foreach (DB::table('sales_campaigns')->where('status', 'sending')->whereNull('deleted_at')->orderBy('id')->get() as $c) {
            if ($budget <= 0) break;
            $rows = DB::table('sales_campaign_recipients')->where('campaign_id', $c->id)->where('status', 'queued')->orderBy('id')->limit($budget)->get();
            foreach ($rows as $r) {
                // Claim it first, so an overlapping run can never send the same person twice.
                if (! DB::table('sales_campaign_recipients')->where('id', $r->id)->where('status', 'queued')->update(['status' => 'sending', 'updated_at' => now()])) continue;
                // A subscriber email never goes twice to the same person, by any path (2026-10-27).
                if (! empty($c->template) && \Illuminate\Support\Facades\Schema::hasTable('subscriber_campaign_sends')
                    && DB::table('subscriber_campaign_sends')->where('campaign', $c->template)->where('email', mb_strtolower($r->email))->exists()) {
                    DB::table('sales_campaign_recipients')->where('id', $r->id)->update(['status' => 'skipped', 'skip_reason' => 'already received it', 'updated_at' => now()]);
                    continue;
                }
                if (SalesCampaigns::suppressed($r->email)) {
                    DB::table('sales_campaign_recipients')->where('id', $r->id)->update(['status' => 'skipped', 'skip_reason' => 'unsubscribed', 'updated_at' => now()]);
                    continue;
                }
                [$ok, $err] = SalesCampaigns::sendOne($c, $r);
                DB::table('sales_campaign_recipients')->where('id', $r->id)->update($ok
                    ? ['status' => 'sent', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]
                    : ['status' => 'failed', 'error' => $err, 'updated_at' => now()]);
                $budget--; $sent++;
            }
            if (! DB::table('sales_campaign_recipients')->where('campaign_id', $c->id)->whereIn('status', ['queued', 'sending'])->exists()) {
                DB::table('sales_campaigns')->where('id', $c->id)->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            }
            SalesCampaigns::recount((int) $c->id);
        }
        // A claimed row whose run died mid-send goes back in the queue after 10 minutes.
        DB::table('sales_campaign_recipients')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'queued', 'updated_at' => now()]);

        $booked = SalesCampaigns::attributeBookings();
        $replied = 0;
        if (Cache::add('kt.sales_campaigns.reply_check', 1, 540)) $replied = SalesCampaigns::detectReplies();
        if ($sent || $booked || $replied) $this->info("sent {$sent}, bookings {$booked}, replies {$replied}");
        return self::SUCCESS;
    }
}
