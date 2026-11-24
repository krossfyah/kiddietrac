<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read delivery failures and record them (2026-08-24; Microsoft 365 mailboxes 2026-11-01).
 *
 * Before this, a bounce was invisible. email_logs only ever held 'sent' or 'suppressed',
 * so "sent" meant "handed over" — not "arrived". A wrong or dead address looked exactly
 * like a successful delivery, which is why an admin could not tell whether an invite had
 * actually reached anyone.
 *
 * TWO SOURCES.
 *
 *   1. The server maildir (~/mail). That was GoDaddy: sendmail returned failures to the
 *      system account. Kept for any host that still has one.
 *   2. The Microsoft 365 mailboxes we send FROM (2026-11-01). Since the move to Cloudways
 *      every message goes out through Graph as noreply@kiddietrac.com (or an agency's own
 *      M365 sender), so the failure notices land in THAT mailbox's inbox. ~/mail does not
 *      exist there, and this command had been a silent no-op every hour since the move —
 *      Anthony: "are undeliverable emails logged in the email error log in audit log?"
 *      They were not.
 *
 * Graph reading needs the app registration's Mail.Read APPLICATION permission (admin
 * consent). Without it Microsoft answers 403; that is reported here and in the log once a
 * day per mailbox rather than failing the run.
 *
 * MATCHING. Every logged email carries an open pixel with its email_logs.tracking_token,
 * and Microsoft's notice attaches the original message — so the token in the notice names
 * the exact row. Only when it is missing does this fall back to the newest send to that
 * address in the day before the failure.
 *
 * Read-only with respect to mail — it never sends, deletes or moves anything in a Microsoft
 * mailbox. What has been handled is tracked in processed_bounces.
 */
class IngestBounces extends Command
{
    protected $signature = 'mail:ingest-bounces
        {--dry : parse and report without writing}
        {--path= : override the maildir (defaults to the home mailbox)}
        {--days=3 : how far back to read the Microsoft 365 mailboxes}';

    protected $description = 'Record delivery failures (server maildir + Microsoft 365 sender mailboxes) into email_logs and the audit trail';

    private bool $dry = false;
    private bool $haveLedger = false;
    private array $n = ['seen' => 0, 'matched' => 0, 'unmatched' => 0, 'skipped' => 0];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry');
        $this->haveLedger = Schema::hasTable('processed_bounces');

        $this->ingestMaildir();
        $this->ingestGraph();

        $this->info(sprintf(
            '%sbounces seen %d, recorded %d, unmatched %d, already known %d',
            $this->dry ? '[dry] ' : '',
            $this->n['seen'],
            $this->n['matched'],
            $this->n['unmatched'],
            $this->n['skipped']
        ));

        return self::SUCCESS;
    }

    /* ─────────────────────────── 1. server maildir ─────────────────────────── */

    private function ingestMaildir(): void
    {
        $base = (string) ($this->option('path') ?: (getenv('HOME') ?: '/home/' . get_current_user()) . '/mail');
        $newDir = rtrim($base, '/') . '/new';
        $curDir = rtrim($base, '/') . '/cur';

        if (! is_dir($newDir)) {
            $this->line("No maildir at {$newDir} (normal on Cloudways)");

            return;
        }

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

            $when = $this->firstMatch($raw, ['/^Date:\s*(.+)$/mi']) ?: '';
            $recorded = $this->recordNotice($raw, $when, basename($file), 'file');

            /* Move to cur/ so the next run does not re-count it. Never delete: the raw
               bounce is the evidence if somebody disputes a delivery. */
            if ($recorded && ! $this->dry) {
                try {
                    if (! is_dir($curDir)) {
                        @mkdir($curDir, 0700, true);
                    }
                    if (str_starts_with($file, $newDir)) {
                        @rename($file, $curDir . '/' . basename($file) . ':2,S');
                    }
                } catch (Throwable $e) {
                }
            }
        }
    }

    /* ─────────────────────── 2. Microsoft 365 sender mailboxes ─────────────────────── */

    /** Notices from the mail system itself, by sender or by the wording Microsoft and others use. */
    private const NDR_FROM = '/postmaster|mailer-daemon|microsoftexchange[0-9a-f]*@/i';
    private const NDR_SUBJECT = '/^(undeliverable|undelivered|delivery has failed|delivery status notification|mail delivery failed|returned mail|failure notice|delivery failure|non[- ]delivery)/i';

    private function ingestGraph(): void
    {
        foreach ($this->graphMailboxes() as $mb) {
            $token = $this->graphToken($mb['cfg']);
            if (! $token) {
                $this->warn("  {$mb['mailbox']}: no Graph token (check the app registration secret)");
                continue;
            }

            $since = now()->subDays(max(1, (int) $this->option('days')))->utc()->format('Y-m-d\TH:i:s\Z');
            $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($mb['mailbox']) . '/messages';
            $query = [
                '$filter' => "receivedDateTime ge {$since}",
                '$select' => 'id,subject,from,receivedDateTime,internetMessageId',
                '$orderby' => 'receivedDateTime desc',
                '$top' => 100,
            ];
            $pages = 0;
            $checked = 0;
            while ($url && $pages++ < 10) {
                try {
                    $r = Http::withToken($token)->timeout(30)->get($url, $query);
                } catch (Throwable $e) {
                    $this->warn("  {$mb['mailbox']}: could not reach Microsoft: " . mb_substr($e->getMessage(), 0, 120));
                    break;
                }
                if ($r->status() === 403 || $r->status() === 401) {
                    $msg = "{$mb['mailbox']}: Microsoft refused to let the app read this mailbox ({$r->status()}). "
                        . 'Bounces cannot be recorded until the app registration is granted the Mail.Read application permission (admin consent).';
                    $this->warn('  ' . $msg);
                    if (Cache::add('bounces.graph.denied.' . md5($mb['mailbox']), 1, 86400)) {
                        Log::warning('mail:ingest-bounces ' . $msg);
                    }
                    break;
                }
                if ($r->status() === 404) {
                    $this->line("  {$mb['mailbox']}: not a mailbox in the tenant, skipped");
                    break;
                }
                if (! $r->ok()) {
                    $this->warn("  {$mb['mailbox']}: Graph {$r->status()} " . mb_substr((string) $r->body(), 0, 160));
                    break;
                }

                foreach ((array) $r->json('value') as $m) {
                    $checked++;
                    $from = (string) ($m['from']['emailAddress']['address'] ?? '');
                    $subject = (string) ($m['subject'] ?? '');
                    if (! preg_match(self::NDR_FROM, $from) && ! preg_match(self::NDR_SUBJECT, trim($subject))) {
                        continue;
                    }
                    $source = 'graph:' . $mb['mailbox'] . ':' . ((string) ($m['internetMessageId'] ?? '') ?: (string) $m['id']);
                    if ($this->haveLedger && DB::table('processed_bounces')->where('source_file', mb_substr($source, 0, 255))->exists()) {
                        $this->n['skipped']++;
                        continue;
                    }

                    // The whole notice as MIME: the delivery-status part and the original message.
                    try {
                        $mime = Http::withToken($token)->timeout(30)
                            ->get('https://graph.microsoft.com/v1.0/users/' . rawurlencode($mb['mailbox']) . '/messages/' . rawurlencode((string) $m['id']) . '/$value');
                    } catch (Throwable $e) {
                        continue;
                    }
                    if (! $mime->ok()) {
                        continue;
                    }
                    $this->recordNotice((string) $mime->body(), (string) ($m['receivedDateTime'] ?? ''), $source, 'graph');
                }

                $url = $r->json('@odata.nextLink');
                $query = [];   // the next link carries its own query
            }
            $this->line("  {$mb['mailbox']}: checked {$checked} message(s)");
        }
    }

    /**
     * The Microsoft 365 mailboxes we send from: the platform sender, the team mailboxes a
     * message may be sent as, and any agency that sends through its own Microsoft 365.
     *
     * @return array<int, array{mailbox:string, cfg:array}>
     */
    private function graphMailboxes(): array
    {
        $out = [];
        try {
            $s = \App\Support\PlatformSettings::all();
            $cfg = [
                'tenant' => $s['mail.graph.tenant'] ?? null,
                'client_id' => $s['mail.graph.client_id'] ?? null,
                'client_secret' => \App\Support\PlatformSettings::getSecret('mail.graph.client_secret'),
            ];
            $from = strtolower(trim((string) ($s['mail.graph.from'] ?? ($s['mail.from'] ?? ''))));
            if ($cfg['tenant'] && $cfg['client_id'] && $cfg['client_secret'] && $from !== '') {
                $boxes = [$from];
                if (class_exists(\App\Mail\GraphTransport::class) && defined(\App\Mail\GraphTransport::class . '::SEND_AS')) {
                    $boxes = array_merge($boxes, \App\Mail\GraphTransport::SEND_AS);
                }
                foreach (array_unique($boxes) as $b) {
                    $out[$b] = ['mailbox' => $b, 'cfg' => $cfg];
                }
            }
        } catch (Throwable $e) {
        }

        try {
            foreach (DB::table('agencies')->pluck('id') as $aid) {
                $c = \App\Support\AgencyMail::configFor((int) $aid);
                if ($c && ($c['provider'] ?? '') === 'graph') {
                    $b = strtolower((string) $c['from']);
                    $out[$b] ??= ['mailbox' => $b, 'cfg' => $c['graph']];
                }
            }
        } catch (Throwable $e) {
        }

        return array_values($out);
    }

    private function graphToken(array $cfg): ?string
    {
        $key = 'bounces.graph.token.' . md5(($cfg['tenant'] ?? '') . '|' . ($cfg['client_id'] ?? ''));
        if ($t = Cache::get($key)) {
            return $t;
        }
        try {
            $r = Http::asForm()->timeout(20)
                ->post('https://login.microsoftonline.com/' . rawurlencode((string) $cfg['tenant']) . '/oauth2/v2.0/token', [
                    'client_id' => $cfg['client_id'],
                    'client_secret' => $cfg['client_secret'],
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]);
        } catch (Throwable $e) {
            return null;
        }
        $t = $r->json('access_token');
        if ($t) {
            Cache::put($key, $t, 3000);
        }

        return $t ?: null;
    }

    /* ─────────────────────────── shared: one notice ─────────────────────────── */

    /**
     * Parse one delivery notice and record every failed recipient in it.
     * Returns true when it was a notice we could read (recorded, or already known).
     */
    private function recordNotice(string $raw, string $when, string $source, string $kind): bool
    {
        $text = $this->readable($raw);

        $recipients = $this->failedRecipients($text);
        if (! $recipients) {
            $this->n['unmatched']++;
            $this->line('  ? could not read a recipient from ' . mb_substr($source, 0, 80));

            return false;
        }

        $bouncedAt = null;
        if ($when !== '') {
            try {
                $bouncedAt = \Carbon\Carbon::parse($when)->utc();
            } catch (Throwable $e) {
            }
        }

        // The original message's open pixel names the exact email_logs row.
        $token = null;
        if (preg_match('#/e/o/([A-Za-z0-9]{32})#', $text, $tm)) {
            $token = $tm[1];
        }

        foreach ($recipients as $recipient => $info) {
            $this->n['seen']++;
            [$status, $diag] = $info;

            /* 5.x.x is permanent (bad address); 4.x.x is a temporary defer that the
               server will retry, and marking those as failed would be misleading. */
            $permanent = str_starts_with($status, '5');
            /* A "malformed recipient address" report carries no Status: header at all.
               Classify on the wording only when there is no status code to read, so a
               genuine 4.x.x defer is never promoted to permanent by a stray word. */
            if (! $permanent && $status === '') {
                $permanent = (bool) preg_match(
                    '/malformed|unrouteable|unroutable|unknown user|user unknown|no such user|does not exist|domain missing|recipientnotfound|couldn\'t be found|wasn\'t found/i',
                    $text
                );
            }
            $reason = trim($status . ' ' . $diag) ?: 'Delivery failed';

            $this->line(sprintf(
                '  %-34s %-9s %s',
                mb_substr($recipient, 0, 32),
                $permanent ? 'permanent' : ($status ? 'temporary' : 'unknown'),
                mb_substr($reason, 0, 70)
            ));

            /* Already recorded? The fingerprint is the failure itself — who it was for,
               when it happened, the status code and a digest of the notice. */
            $fingerprint = hash('sha256', $recipient . '|' . $when . '|' . $status . '|' . md5($raw));
            if ($this->haveLedger && DB::table('processed_bounces')->where('fingerprint', $fingerprint)->exists()) {
                $this->n['seen']--;
                $this->n['skipped']++;
                continue;
            }

            $log = $this->findLog($recipient, $token, $bouncedAt);
            if ($this->dry) {
                $this->line('      ' . ($log ? "would mark email_logs #{$log->id}" . ($token && $log->tracking_token === $token ? ' (pixel match)' : ' (address+time match)') : 'no matching send'));
                $log ? $this->n['matched']++ : $this->n['unmatched']++;
                continue;
            }

            try {
                if ($log) {
                    $upd = [
                        'status' => $permanent ? 'bounced' : 'deferred',
                        'error' => mb_substr($reason, 0, 480),
                    ];
                    // When it bounced, not when it was sent - the Email errors date column shows this.
                    if (Schema::hasColumn('email_logs', 'bounced_at')) {
                        $upd['bounced_at'] = ($bouncedAt ?: now()->utc())->format('Y-m-d H:i:s.v');
                    }
                    DB::table('email_logs')->where('id', $log->id)->update($upd);
                    $this->n['matched']++;
                } else {
                    /* Recorded in the audit trail below, but no delivery is relabelled:
                       an unattached bounce is better than a wrong one. */
                    $this->n['unmatched']++;
                }

                if (Schema::hasTable('audit_logs')) {
                    \App\Support\Audit::write([
                        'user_id' => null,
                        // The agency of the send that bounced — never guessed from the address.
                        'agency_id' => $log->agency_id ?? null,
                        'action' => $permanent ? 'email.bounced' : 'email.deferred',
                        'entity_type' => 'email',
                        'entity_id' => $log->id ?? null,
                        'payload' => json_encode([
                            'to' => $recipient,
                            'subject' => $log->subject ?? null,
                            'status' => $status,
                            'reason' => mb_substr($reason, 0, 300),
                            'bounced_at' => $when,
                            'source' => $kind,
                        ]),
                        'created_at' => now(),
                    ]);
                }
                if ($this->haveLedger) {
                    DB::table('processed_bounces')->insert([
                        'fingerprint' => $fingerprint,
                        'recipient' => mb_substr($recipient, 0, 200),
                        'source_file' => mb_substr($source, 0, 255),
                        'matched' => (bool) $log,
                        'processed_at' => now(),
                    ]);
                }
            } catch (Throwable $e) {
                $this->warn('  could not record: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * The email this failure is about. The pixel token is exact; it is only trusted when the
     * failed address really was a To recipient of that row (a BCC copy bouncing must not
     * mark the family's delivery as failed). Otherwise the newest send to that address in
     * the day before the failure.
     */
    private function findLog(string $recipient, ?string $token, ?\Carbon\Carbon $bouncedAt): ?object
    {
        if (! Schema::hasTable('email_logs')) {
            return null;
        }
        $cols = ['id', 'agency_id', 'subject', 'to_email', 'tracking_token'];
        if ($token) {
            $row = DB::table('email_logs')->where('tracking_token', $token)->first($cols);
            if ($row && str_contains(mb_strtolower((string) $row->to_email), $recipient)) {
                return $row;
            }
        }
        if (! $bouncedAt) {
            return null;
        }

        return DB::table('email_logs')
            ->where(function ($q) use ($recipient) {
                $q->whereRaw('LOWER(TRIM(to_email)) = ?', [$recipient])
                  ->orWhereRaw('LOWER(to_email) LIKE ?', ['%' . $recipient . '%']);
            })
            ->whereIn('status', ['sent', 'deferred'])
            /* The send this bounce refers to: the last one at or before the failure. A
               mail server reports back within minutes, so a day either side is generous;
               anything older is a different message entirely. */
            ->where('created_at', '<=', $bouncedAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'))
            ->where('created_at', '>=', $bouncedAt->copy()->subDay()->format('Y-m-d H:i:s'))
            ->orderByDesc('id')
            ->first($cols);
    }

    /**
     * Every failed recipient with its status code and diagnostic. A notice can name several,
     * each in its own Final-Recipient block.
     *
     * @return array<string, array{0:string,1:string}>
     */
    private function failedRecipients(string $text): array
    {
        $out = [];
        $blocks = preg_split('/(?=^Final-Recipient:)/mi', $text) ?: [];
        foreach ($blocks as $b) {
            if (! preg_match('/^Final-Recipient:\s*(?:rfc822;)?\s*<?([^\s<>;]+@[^\s<>;]+)>?/mi', $b, $m)) {
                continue;
            }
            $r = mb_strtolower(trim($m[1], " \t<>;,"));
            $status = $this->firstMatch($b, ['/^Status:\s*([245]\.\d+\.\d+)/mi']) ?: '';
            $diag = $this->firstMatch($b, ['/^Diagnostic-Code:\s*(.+)$/mi']) ?: '';
            if (str_starts_with($status, '2')) {
                continue;   // 2.x.x is a delivery/relay report, not a failure
            }
            $out[$r] ??= [$status, $diag];
        }
        if ($out) {
            return $out;
        }

        $r = $this->firstMatch($text, [
            '/^X-Failed-Recipients:\s*(\S+@\S+)/mi',
            '/^Original-Recipient:\s*(?:rfc822;)?\s*(\S+@\S+)/mi',
            /* A "malformed recipient address" report carries no Final-Recipient at all — the
               address never parsed — and states it in prose instead:
                   info'+'@'+'ilearnhcc.com: domain missing or malformed */
            '/^\s*(\S+@\S+?):\s*(?:domain missing or malformed|malformed|unrouteable|unknown user)/mi',
        ]);
        if ($r) {
            $r = mb_strtolower(trim($r, " \t<>;,"));
            $out[$r] = [
                $this->firstMatch($text, ['/^Status:\s*([245]\.\d+\.\d+)/mi', '/\b([45]\.\d{1,3}\.\d{1,3})\b/']) ?: '',
                $this->firstMatch($text, ['/^Diagnostic-Code:\s*(.+)$/mi']) ?: '',
            ];
        }

        return $out;
    }

    /**
     * The notice as searchable text: quoted-printable soft breaks joined and base64 parts
     * decoded, so a recipient or the pixel URL split across encoded lines is still found.
     */
    private function readable(string $raw): string
    {
        $t = preg_replace("/=\r?\n/", '', $raw) ?? $raw;
        if (preg_match_all('/(?:^[A-Za-z0-9+\/=]{40,}\r?\n){3,}/m', $raw, $mm)) {
            foreach ($mm[0] as $chunk) {
                $d = base64_decode(preg_replace('/\s+/', '', $chunk), true);
                if ($d !== false && $d !== '') {
                    $t .= "\n" . $d;
                }
            }
        }

        return str_replace("\r\n", "\n", $t);
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
