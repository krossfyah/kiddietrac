<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v22p57 — Drip campaign dispatcher.
 *  - Scans for trigger events that happened N days ago matching each
 *    active drip campaign's trigger_event + trigger_delay_days.
 *  - Inserts a drip_sends row + sends the email immediately.
 *  - Idempotent via uniqueness of (campaign_id, trigger_source_id).
 *
 * Schedule: every hour.
 */
final class DripDispatchCommand extends Command
{
    protected $signature = 'drip:dispatch {--dry-run}';
    protected $description = 'Send drip emails for trigger events that match active drip campaigns';

    public function handle(): int
    {
        $campaigns = DB::table('marketing_campaigns')
            ->whereNotNull('trigger_event')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get();

        $totalSent = 0;
        foreach ($campaigns as $c) {
            $delay = (int) $c->trigger_delay_days;
            $sources = $this->triggerSources($c, $delay);
            foreach ($sources as $s) {
                $exists = DB::table('drip_sends')
                    ->where('campaign_id', $c->id)
                    ->where('trigger_source_id', $s['id'])
                    ->where('trigger_source_type', $s['type'])
                    ->exists();
                if ($exists) continue;
                $sendId = DB::table('drip_sends')->insertGetId([
                    'campaign_id' => $c->id,
                    'to_user_id' => $s['user_id'] ?? null,
                    'to_email' => $s['email'],
                    'trigger_source_id' => $s['id'],
                    'trigger_source_type' => $s['type'],
                    'scheduled_at' => now(),
                    'status' => 'queued',
                    'created_at' => now(),
                ]);
                if ($this->option('dry-run')) {
                    $this->line(" would send '{$c->title}' to {$s['email']}");
                    continue;
                }
                try {
                    $bodyHtml = EmailTemplate::wrap((int) $c->agency_id, $c->body_html, [
                        'eyebrow' => 'A NOTE FROM US', 'title' => $c->subject,
                    ]);
                    AgencyMailer::forAgency((int) $c->agency_id)->mailer()
                        ->html($bodyHtml, function ($m) use ($s, $c) {
                            $m->to($s['email'])->subject($c->subject);
                        });
                    DB::table('drip_sends')->where('id', $sendId)->update([
                        'status' => 'sent', 'sent_at' => now(),
                    ]);
                    $totalSent++;
                } catch (\Throwable $e) {
                    DB::table('drip_sends')->where('id', $sendId)->update([
                        'status' => 'failed', 'error' => $e->getMessage(),
                    ]);
                    Log::warning('drip dispatch failed', ['campaign' => $c->id, 'err' => $e->getMessage()]);
                }
            }
        }
        $this->info("Drip dispatch: {$totalSent} email(s) sent across " . $campaigns->count() . ' campaign(s)');
        return 0;
    }

    private function triggerSources(object $c, int $delay): array
    {
        $cutoff = Carbon::now()->subDays($delay);
        $window = $cutoff->copy()->endOfDay();
        switch ($c->trigger_event) {
            case 'tour_booked':
                return DB::table('tour_bookings')
                    ->where('agency_id', $c->agency_id)
                    ->whereDate('tour_at', '=', $cutoff->toDateString())
                    ->select('id', 'parent_email as email', DB::raw('NULL as user_id'), DB::raw("'tour' as type"))
                    ->get()->toArray() ?: [];
            case 'enrollment_complete':
                return DB::table('children as ch')
                    ->join('families as f', 'f.id', '=', 'ch.family_id')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('c.agency_id', $c->agency_id)
                    ->whereDate('ch.enrolled_at', '=', $cutoff->toDateString())
                    ->select('ch.id', 'f.primary_email as email', DB::raw('NULL as user_id'), DB::raw("'enrollment' as type"))
                    ->get()->toArray() ?: [];
            case 'birthday':
                return DB::table('children as ch')
                    ->join('families as f', 'f.id', '=', 'ch.family_id')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('c.agency_id', $c->agency_id)
                    ->whereRaw('DATE_FORMAT(ch.date_of_birth, "%m-%d") = ?', [$cutoff->format('m-d')])
                    ->select('ch.id', 'f.primary_email as email', DB::raw('NULL as user_id'), DB::raw("'birthday' as type"))
                    ->get()->toArray() ?: [];
            default:
                return [];
        }
    }
}
