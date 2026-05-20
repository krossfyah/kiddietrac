<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p51 — Daily expiry-warning scan for certifications + background checks.
 * Notifies the holder + their agency_admin if anything expires within 60 days.
 * De-duped via notification.body containing the cert id (we don't want to
 * spam every day for the same expiring cert — fired once per (cert, calendar week)).
 */
final class ExpiryWarningCommand extends Command
{
    protected $signature = 'expiry:warn {--days=60 : Warn ahead this many days}';
    protected $description = 'Daily warning emails for expiring background checks and certifications';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = Carbon::now()->addDays($days)->endOfDay();
        $now = Carbon::now();
        $weekKey = Carbon::now()->format('o-W');

        $rows = DB::table('background_checks')
            ->where('expires_at', '<=', $cutoff)
            ->where('expires_at', '>=', $now->subDays(30))
            ->get();

        $sent = 0;
        foreach ($rows as $bgc) {
            $dedupe = "bgc:{$bgc->id}:{$weekKey}";
            $already = DB::table('notifications')
                ->where('user_id', $bgc->user_id)
                ->where('body', 'like', "%[{$dedupe}]%")
                ->exists();
            if ($already) continue;

            $exp = Carbon::parse($bgc->expires_at);
            $daysOut = max(0, (int) $now->diffInDays($exp, false));
            $title = $exp->isPast()
                ? "Background check expired ({$bgc->check_type})"
                : "Background check expires in {$daysOut} day(s) ({$bgc->check_type})";

            DB::table('notifications')->insert([
                'user_id'    => $bgc->user_id,
                'type'       => 'compliance',
                'title'      => $title,
                'body'       => "Reference: {$bgc->reference}. Expires: " . $exp->format('M j, Y') . " [{$dedupe}]",
                'link_url'   => '#background-checks',
                'created_at' => now(),
            ]);
            $sent++;
        }
        $this->info("Sent {$sent} expiry warnings");
        return 0;
    }
}
