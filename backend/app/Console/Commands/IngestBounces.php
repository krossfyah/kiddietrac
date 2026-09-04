<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read delivery failures out of the server mailbox and record them (2026-08-24).
 *
 * Before this, a bounce was invisible. email_logs only ever held 'sent' or 'suppressed',
 * so "sent" meant "handed to sendmail" — not "arrived". A wrong or dead address looked
 * exactly like a successful delivery, which is why an admin could not tell whether an
 * invite had actually reached anyone.
 *
 * The bounces were arriving all along: sendmail returns them to the ENVELOPE sender,
 * which defaults to the system account rather than noreply@, so they piled up unread in
 * ~/mail/new. This reads that maildir, matches each failure back to the email_logs row it
 * refers to, and marks it 'bounced' with the reason.
 *
 * Read-only with respect to mail — it never sends anything. Processed messages are moved
 * to cur/ so they are not counted twice; nothing is deleted, so the originals stay
 * available for support.
 */
class IngestBounces extends Command
{
    protected $signature = 'mail:ingest-bounces
        {--dry : parse and report without writing}
        {--path= : override the maildir (defaults to the home mailbox)}';

    protected $description = 'Record delivery failures from the mail server into email_logs and the audit trail';

    public function handle(): int
    {
        $base = (string) ($this->option('path') ?: (getenv('HOME') ?: '/home/' . get_current_user()) . '/mail');
        $newDir = rtrim($base, '/') . '/new';
        $curDir = rtrim($base, '/') . '/cur';

        if (! is_dir($newDir)) {
            $this->warn("No maildir at {$newDir}");

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');

        /* BOTH directories, not just new/.

           A maildir moves a message from new/ to cur/ as soon as anything reads the
           mailbox — webmail, an IMAP client, Dovecot's own indexer. This command used
           cur/ as its "done" pile, so any bounce that had been looked at before the
           hourly run landed in the done pile without ever being processed. Ten genuine
           delivery failures were sitting there unrecorded, and email_logs had never
           held one 'bounced' row since the feature shipped.

           Because cur/ can no longer mean "already handled", that is tracked in
           processed_bounces instead, and re-reading a file is free. */
        $files = array_merge(glob($newDir . '/*') ?: [], glob($curDir . '/*') ?: []);
        $seen = 0;
        $matched = 0;
        $unmatched = 0;
        $skipped = 0;
        $haveLedger = Schema::hasTable('processed_bounces');

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            try {
                $raw = (string) file_get_contents($file);
            } catch (Throwable $e) {
                continue;
            }

            /* Only genuine delivery-status notifications. Anything else in this mailbox
               is somebody's ordinary mail and must be left alone. */
            if (! preg_match('/^From:.*(Mailer-Daemon|MAILER-DAEMON|postmaster)/mi', $raw)) {
                continue;
            }
            $seen++;

            $recipient = $this->firstMatch($raw, [
                '/^Final-Recipient:\s*(?:rfc822;)?\s*(\S+@\S+)/mi',
                '/^X-Failed-Recipients:\s*(\S+@\S+)/mi',
                '/^Original-Recipient:\s*(?:rfc822;)?\s*(\S+@\S+)/mi',
                /* A "malformed recipient address" report carries no Final-Recipient at
                   all — the address never parsed, so the server had nothing structured
                   to name. It states the address in prose instead:
                       info'+'@'+'ilearnhcc.com: domain missing or malformed
                   Without this the whole class of bad-address failures counted as
                   "unmatched" and was dropped, which is the most important class to
                   see: it means WE built an address wrongly. */
                '/^\s*(\S+@\S+?):\s*(?:domain missing or malformed|malformed|unrouteable|unknown user)/mi',
            ]);
            if (! $recipient) {
                $unmatched++;
                continue;
            }
            $recipient = mb_strtolower(trim($recipient, " \t<>;,"));

            $status = $this->firstMatch($raw, ['/^Status:\s*([245]\.\d+\.\d+)/mi']) ?: '';
            $diag = $this->firstMatch($raw, ['/^Diagnostic-Code:\s*(.+)$/mi']) ?: '';
            $when = $this->firstMatch($raw, ['/^Date:\s*(.+)$/mi']) ?: '';

            /* 5.x.x is permanent (bad address); 4.x.x is a temporary defer that the
               server will retry, and marking those as failed would be misleading. */
            $permanent = str_starts_with($status, '5');
            /* A "malformed recipient address" report carries no Status: header at all,
               so the 5.x.x test above says nothing and it was recorded as `deferred` —
               i.e. as something the server would retry. It will not: the address itself
               is wrong and every future send to it fails the same way. Classify on the
               wording when there is no status code to read, so these surface as the
               permanent failures they are. Only when $status is empty — a genuine
               4.x.x defer must never be promoted to permanent by a stray word. */
            if (! $permanent && $status === '') {
                $permanent = (bool) preg_match(
                    '/malformed|unrouteable|unroutable|unknown user|user unknown|no such user|does not exist|domain missing/i',
                    $raw
                );
            }
            $reason = trim($status . ' ' . $diag) ?: 'Delivery failed';

            $this->line(sprintf(
                '  %-34s %-9s %s',
                mb_substr($recipient, 0, 32),
                $permanent ? 'permanent' : ($status ? 'temporary' : 'unknown'),
                mb_substr($reason, 0, 60)
            ));

            /* Already recorded? The fingerprint is the failure itself — who it was for,
               when it happened, the status code and a digest of the notice — rather than
               the filename, which changes as the mail server adds flags to it. */
            $fingerprint = hash('sha256', $recipient . '|' . $when . '|' . $status . '|' . md5($raw));
            if ($haveLedger && DB::table('processed_bounces')->where('fingerprint', $fingerprint)->exists()) {
                $seen--;                       // counted above; it is not new
                $skipped++;
                continue;
            }

            if ($dry) {
                continue;
            }

            try {
                /* Attach the failure to the most recent delivery we recorded for that
                   address. Matching on the newest 'sent' row is the closest we can get
                   without a per-message id in the bounce. */
                /* When the bounce says it happened. Without a usable date we cannot
                   safely attach it to any particular send. */
                $bouncedAt = null;
                if ($when !== '') {
                    try {
                        $bouncedAt = \Carbon\Carbon::parse($when)->utc();
                    } catch (Throwable $e) {
                        $bouncedAt = null;
                    }
                }

                $log = ($bouncedAt && Schema::hasTable('email_logs'))
                    ? DB::table('email_logs')
                        ->whereRaw('LOWER(TRIM(to_email)) = ?', [$recipient])
                        ->where('status', 'sent')
                        /* The send this bounce refers to: the last one at or before
                           the failure. A mail server reports back within minutes, so
                           a day either side is generous; anything older is a
                           different message entirely. */
                        ->where('created_at', '<=', $bouncedAt->copy()->addMinutes(10))
                        ->where('created_at', '>=', $bouncedAt->copy()->subDay())
                        ->orderByDesc('id')
                        ->first(['id'])
                    : null;

                if ($log) {
                    DB::table('email_logs')->where('id', $log->id)->update([
                        'status' => $permanent ? 'bounced' : 'deferred',
                        'error' => mb_substr($reason, 0, 480),
                    ]);
                    $matched++;
                } else {
                    /* Recorded in the audit trail below, but no delivery is
                       relabelled: an unattached bounce is better than a wrong one. */
                    $unmatched++;
                }

                if (Schema::hasTable('audit_logs')) {
                    \App\Support\Audit::write([
                        'user_id' => null,
                        'action' => $permanent ? 'email.bounced' : 'email.deferred',
                        'entity_type' => 'email',
                        'entity_id' => $log->id ?? null,
                        'payload' => json_encode([
                            'to' => $recipient,
                            'status' => $status,
                            'reason' => mb_substr($reason, 0, 300),
                            'bounced_at' => $when,
                        ]),
                        'created_at' => now(),
                    ]);
                }
                if ($haveLedger) {
                    DB::table('processed_bounces')->insert([
                        'fingerprint' => $fingerprint,
                        'recipient' => mb_substr($recipient, 0, 200),
                        'source_file' => mb_substr(basename($file), 0, 255),
                        'matched' => (bool) $log,
                        'processed_at' => now(),
                    ]);
                }
            } catch (Throwable $e) {
                $this->warn('  could not record: ' . $e->getMessage());
                continue;
            }

            /* Move to cur/ so the next run does not re-count it. Never delete: the raw
               bounce is the evidence if somebody disputes a delivery. */
            try {
                if (! is_dir($curDir)) {
                    @mkdir($curDir, 0700, true);
                }
                @rename($file, $curDir . '/' . basename($file) . ':2,S');
            } catch (Throwable $e) {
            }
        }

        $this->info(sprintf(
            '%sbounces seen %d, recorded %d, unmatched %d, already known %d',
            $dry ? '[dry] ' : '',
            $seen,
            $matched,
            $unmatched,
            $skipped
        ));

        return self::SUCCESS;
    }

    /** First capturing group that matches any of the given patterns. */
    private function firstMatch(string $haystack, array $patterns): ?string
    {
        foreach ($patterns as $p) {
            if (preg_match($p, $haystack, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }
}
