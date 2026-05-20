<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Dompdf\Dompdf;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v22p57 — Year-end portfolio PDF auto-emailed to each family in December.
 *  php artisan portfolio:year-end {--year=}
 *  Scheduled: yearly Dec 15 at 09:00.
 */
final class YearEndPortfolioCommand extends Command
{
    protected $signature = 'portfolio:year-end {--year=} {--dry-run}';
    protected $description = 'Generate + email year-end portfolio PDFs to every enrolled family';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: Carbon::now()->year);
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();
        $dry = (bool) $this->option('dry-run');

        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.family_id',
                'f.family_name', 'f.primary_email', 'c.agency_id', 'c.name as centre_name')
            ->get();

        $this->info("Found {$children->count()} enrolled children");
        if ($dry) {
            $children->take(5)->each(fn ($c) => $this->line("  would email: {$c->first_name} {$c->last_name} -> {$c->primary_email}"));
            return 0;
        }

        $ok = 0; $skip = 0; $err = 0;
        foreach ($children as $ch) {
            try {
                if (!$ch->primary_email) { $skip++; continue; }
                $observations = DB::table('observations')
                    ->where('child_id', $ch->id)
                    ->whereBetween('observed_at', [$start, $end])
                    ->orderBy('observed_at')
                    ->select('observed_at', 'framework', 'domain', 'body')
                    ->get();
                if ($observations->isEmpty()) { $skip++; continue; }
                $milestones = DB::table('milestone_records')->where('child_id', $ch->id)->get();
                $logs = DB::table('daily_care_logs')->where('child_id', $ch->id)
                    ->whereBetween('occurred_at', [$start, $end])->count();
                $agency = DB::table('agencies')->where('id', $ch->agency_id)->first();

                $html = view('pdf.portfolio', [
                    'child' => DB::table('children')->where('id', $ch->id)->first(),
                    'family' => DB::table('families')->where('id', $ch->family_id)->first(),
                    'agency' => $agency,
                    'observations' => $observations,
                    'milestones' => $milestones,
                    'logs' => collect(),
                ])->render();
                $dompdf = new Dompdf();
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('letter', 'portrait');
                $dompdf->render();
                $pdfBytes = $dompdf->output();

                $body = EmailTemplate::wrap((int) $ch->agency_id, '
                    <p>Happy holidays from ' . htmlspecialchars($ch->centre_name) . '!</p>
                    <p>Attached is ' . htmlspecialchars($ch->first_name) . '\'s year-end portfolio for ' . $year . ' — every observation, milestone, and ' . $logs . ' care moment we shared this year.</p>
                    <p>Thank you for trusting us with your family. We look forward to ' . ($year + 1) . '!</p>
                ', [
                    'eyebrow' => "YEAR-END PORTFOLIO",
                    'title' => $ch->first_name . "'s " . $year . " portfolio",
                    'subtitle' => $ch->centre_name,
                ]);

                AgencyMailer::forAgency((int) $ch->agency_id)->mailer()
                    ->html($body, function ($m) use ($ch, $year, $pdfBytes) {
                        $m->to($ch->primary_email, $ch->family_name)
                          ->subject("[$year Portfolio] {$ch->first_name}'s year-end memories")
                          ->attachData($pdfBytes, "{$ch->first_name}-{$year}-portfolio.pdf", ['mime' => 'application/pdf']);
                    });
                $ok++;
            } catch (\Throwable $e) {
                Log::warning('year-end portfolio failed', ['child' => $ch->id, 'msg' => $e->getMessage()]);
                $err++;
            }
        }
        $this->info("Year-end portfolio: ok={$ok} skipped={$skip} failed={$err}");
        return 0;
    }
}
