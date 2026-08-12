<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Finds emails that were SENT but never written to the email log, and can backfill them.
 *
 * Why this exists: the global MessageSent listener in AppServiceProvider skips its
 * email_logs insert whenever a message carries the `X-KT-Logged` header, on the promise
 * that the sender writes its own row (senders do that to embed an open-tracking token,
 * which has to exist before the body is built). It is an honour system, and it failed
 * silently twice — the educator day-brief and absence notices set the header and never
 * inserted anything, so those emails simply did not appear in the log. Nobody notices
 * an absent row.
 *
 * The listener DOES record an `email.sent` audit_logs row for every send, before that
 * skip, so the audit trail is the independent witness. Comparing the two finds any
 * sender that forgets, including ones written in future.
 */
class MailLogReconcileCommand extends Command
{
    protected $signature = 'mail:reconcile-logs
        {--days=7 : How far back to look}
        {--all : Ignore --days and check all history}
        {--backfill : Write the missing rows into email_logs}';

    protected $description = 'Report (and optionally backfill) emails that were sent but never logged';

    public function handle(): int
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasTable('email_logs')) {
            $this->warn('audit_logs / email_logs not present — nothing to reconcile.');
            return self::SUCCESS;
        }

        $q = DB::table('audit_logs')->where('action', 'email.sent')->orderBy('created_at');
        if (! $this->option('all')) {
            $q->where('created_at', '>=', now()->subDays((int) $this->option('days')));
        }
        $rows = $q->get(['id', 'agency_id', 'created_at', 'payload']);
        $this->line('Checked '.count($rows).' sent-email audit records.');

        $missing = [];
        foreach ($rows as $r) {
            $p = json_decode((string) $r->payload, true) ?: [];
            $to = trim((string) ($p['to'] ?? ''));
            $subject = (string) ($p['subject'] ?? '');
            if ($to === '') continue;

            $at = Carbon::parse($r->created_at);
            // Same recipient + subject within a few minutes either side. The sender's own
            // insert happens just AFTER the send, so an exact timestamp match never works.
            $exists = DB::table('email_logs')
                ->where('to_email', $to)
                ->where('subject', $subject)
                ->whereBetween('created_at', [$at->copy()->subMinutes(10), $at->copy()->addMinutes(10)])
                ->exists();
            if (! $exists) {
                $missing[] = ['at' => $at, 'to' => $to, 'subject' => $subject, 'agency_id' => $r->agency_id];
            }
        }

        if (! $missing) {
            $this->info('✓ Every sent email has a log row.');
            return self::SUCCESS;
        }

        $this->warn(count($missing).' sent email(s) have NO email_logs row:');
        $grouped = [];
        foreach ($missing as $m) {
            // Collapse per-recipient variations ("Aria's day — Thu 23 Jul") into one line.
            $key = preg_replace('/\s+—.*$/u', '', $m['subject']);
            $grouped[$key] = ($grouped[$key] ?? 0) + 1;
        }
        arsort($grouped);
        foreach ($grouped as $subject => $count) {
            $this->line(sprintf('  %4dx  %s', $count, $subject));
        }

        if (! $this->option('backfill')) {
            $this->newLine();
            $this->line('Re-run with --backfill to add these to the email log.');
            return self::SUCCESS;
        }

        $written = 0;
        foreach ($missing as $m) {
            try {
                $row = [
                    'to_email' => mb_substr($m['to'], 0, 255),
                    'to_name' => null,
                    'from_email' => 'noreply@kiddietrac.com',
                    'subject' => $m['subject'],
                    // Flagged in the "Via" column so a reconstructed row is never mistaken
                    // for one captured at send time — the body was not retained.
                    'mailer' => 'reconstructed from audit trail',
                    'status' => 'sent',
                    'created_at' => $m['at'],
                ];
                if (Schema::hasColumn('email_logs', 'agency_id')) {
                    $agid = $m['agency_id'];
                    if (! $agid) {
                        try { $agid = \App\Support\AgencyMail::agencyOfEmail(trim(explode(',', $m['to'])[0])); }
                        catch (\Throwable $e) { $agid = null; }
                    }
                    $row['agency_id'] = $agid;
                }
                if (Schema::hasColumn('email_logs', 'tracking_token')) {
                    // A token would be pointless (the mail is long gone) but the column
                    // is used as the "tracking available" marker, so leave it null.
                    $row['opens'] = 0;
                }
                DB::table('email_logs')->insert($row);
                $written++;
            } catch (\Throwable $e) {
                $this->error('  could not backfill '.$m['to'].': '.$e->getMessage());
            }
        }
        $this->info("✓ Backfilled {$written} row(s), marked 'reconstructed from audit trail'.");
        $this->line("   To undo: DELETE FROM email_logs WHERE mailer = 'reconstructed from audit trail';");

        return self::SUCCESS;
    }
}
