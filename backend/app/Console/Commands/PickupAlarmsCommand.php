<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Notify;
use App\Support\PickupPlan;
use App\Support\SafeDeparture;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * parents:pickup-alarms - every minute. Rings a parent's phone (bell + push, Notify) when the pickup alarm they set
 * comes due: lead_minutes before today's pickup time (see App\Support\PickupPlan), only while the child is signed in.
 * Once per pickup time (sent_key); moving the time sets it again. Not sent more than 30 minutes late (a job that was
 * down does not ring for a pickup long past).
 */
final class PickupAlarmsCommand extends Command
{
    protected $signature = 'parents:pickup-alarms {--dry : list what would ring, change nothing} {--user= : only this parent} {--now= : pretend it is this UTC time, "Y-m-d H:i" (testing)}';
    protected $description = "Ring parents' pickup alarms (the countdown on the parent Today screen)";

    private const LATE_LIMIT_MIN = 30;

    public function handle(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('pickup_alarms')) return self::SUCCESS;
        $dry = (bool) $this->option('dry');
        $now = $this->option('now') ? Carbon::createFromFormat('Y-m-d H:i', (string) $this->option('now'), 'UTC') : Carbon::now();
        $rows = DB::table('pickup_alarms')->where('alarm_on', true)
            ->when($this->option('user'), fn ($q) => $q->where('user_id', (int) $this->option('user')))
            ->orderBy('user_id')->get();
        $lists = [];
        foreach ($rows as $s) {
            // Still this child's guardian, at an open family and centre (PickupPlan::children is the person check).
            $k = PickupPlan::children((int) $s->user_id, (int) $s->child_id)->first();
            if (! $k) continue;
            $a = (int) $k->agency_id;
            $lists[$a] ??= SafeDeparture::today($a);
            $plan = PickupPlan::build($k, $lists[$a][(int) $k->id] ?? null, $s, $now);
            if ($plan['status'] !== 'in') continue;
            $pickupAt = Carbon::parse($plan['pickup_at']);
            $key = $pickupAt->format('Y-m-d H:i');
            if ($s->sent_key === $key) continue;
            $ringAt = $pickupAt->copy()->subMinutes((int) $s->lead_minutes);
            if ($now->lt($ringAt) || $now->gt($ringAt->copy()->addMinutes(self::LATE_LIMIT_MIN))) continue;

            $left = max(0, (int) ceil($now->diffInSeconds($pickupAt, false) / 60));
            $title = '⏰ Time to pick up ' . $plan['first_name'];
            $body = 'Pickup at ' . $plan['pickup_label'] . ($left > 0 ? ', ' . PickupPlan::inWords($left) : ' — now') . ' · ' . \App\Support\FamilyFacingName::place((int) ($k->centre_id ?? 0) ?: null, (string) $plan['centre_name']) . '.';
            $this->line(($dry ? '[dry] ' : '') . "user {$s->user_id} child {$s->child_id}: {$title} / {$body}");
            if ($dry) continue;
            // Claim it first so two overlapping runs never ring twice.
            $claimed = DB::table('pickup_alarms')->where('id', $s->id)->where(fn ($q) => $q->whereNull('sent_key')->orWhere('sent_key', '<>', $key))
                ->update(['sent_key' => $key]);
            if (! $claimed) continue;
            Notify::write(['user_id' => (int) $s->user_id, 'type' => 'pickup_alarm', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => '#dashboard', 'child_id' => (int) $k->id])]);
        }

        return self::SUCCESS;
    }
}
