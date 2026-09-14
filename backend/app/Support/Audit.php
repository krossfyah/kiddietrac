<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The one way an audit row gets written (2026-09-03).
 *
 * Every row used to be inserted straight onto the table at 72 separate call sites,
 * each deciding for itself what to put in `ip_address`, and the common form was
 *
 *     'ip_address' => request() ? request()->ip() : null,
 *
 * There is no request behind a cron, a queue worker or the scheduler, so those rows
 * stored NULL and the audit log showed a blank cell where every other row named
 * where the action came from. 3,424 of the rows on production were blank that way —
 * the nightly digests, the push fan-out, the iLearn integration sync.
 *
 * A blank is not "we don't know": it is the system acting. So say that. Rows with no
 * request behind them are filed under the literal 'system', which reads the same way
 * the actor column already does and, unlike NULL, can be searched and grouped.
 *
 * Anthony, 2026-09-03: "for aduit log add the ip address where actor is system
 * always to keep things consistent".
 */
final class Audit
{
    /** varchar(45) — an IPv6 address is at most 45 characters. */
    private const MAX = 45;

    /**
     * created_at is TIMESTAMP(3); .v is the millisecond that precision exists for.
     *
     * PUBLIC because email_logs is the twin of this table and now carries the same
     * precision. A Carbon handed straight to the query builder is formatted with the
     * connection's default 'Y-m-d H:i:s', which stores .000 into a TIMESTAMP(3) and
     * loses the fraction silently — the column looks widened and records nothing. Every
     * writer that wants a millisecond formats with THIS, so there is one answer to
     * "how does this platform stamp a time" rather than six copies of a format string.
     *
     * Stamped from PHP, never MySQL: the two run about seven hours apart on this host,
     * so a CURRENT_TIMESTAMP(3) default would be precise and wrong.
     */
    public const TS = 'Y-m-d H:i:s.v';

    /**
     * Where the current action is coming from.
     *
     * `request()` is bound even in console context in some Laravel setups, and it
     * answers `ip()` with the loopback or an empty string there — so an empty OR
     * loopback answer with no real server variables behind it is treated as no
     * request at all rather than reported as 127.0.0.1.
     */
    public static function ip(): string
    {
        if (app()->runningInConsole()) {
            return 'system';
        }

        $req = request();
        $ip = $req ? trim((string) $req->ip()) : '';

        return $ip !== '' ? substr($ip, 0, self::MAX) : 'system';
    }

    /**
     * One timestamp, at millisecond precision, or null to let the database stamp it.
     *
     * Accepts what the call sites actually pass: a Carbon or DateTime, a string, or a
     * unix timestamp. Anything unparseable — or absent — returns null, and write()
     * substitutes the current time rather than letting the row fall through to the
     * database's DEFAULT, which runs on a different clock. See write().
     */
    private static function stamp(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format(self::TS);
        }
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            return (new \DateTimeImmutable('@' . (int) $v))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format(self::TS);
        }
        if (is_string($v)) {
            try {
                return (new \DateTimeImmutable($v))->format(self::TS);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Insert one audit row, or a list of them.
     *
     * Only ever fills in a blank `ip_address`; a caller that already knows the
     * address (a webhook naming its sender, an impersonation recording the real
     * admin) keeps whatever it passed.
     */
    public static function write(array $row): void
    {
        if ($row === []) {
            return;
        }

        $rows = array_is_list($row) ? $row : [$row];
        $ip = null;

        foreach ($rows as $i => $r) {
            if (! is_array($r)) {
                continue;
            }
            if (trim((string) ($r['ip_address'] ?? '')) === '') {
                $rows[$i]['ip_address'] = $ip ??= self::ip();
            }

            /* KEEP THE MILLISECONDS.

               created_at is TIMESTAMP(3) because whole seconds cannot order this log:
               241 of the 300 newest rows share their second, and one holds 25. Nothing
               here normally sets the column — the DEFAULT CURRENT_TIMESTAMP(3) does,
               using the same clock the rows are ordered by — but a caller that passes
               a Carbon (the crash sink passes now()) would have it formatted by
               Laravel as 'Y-m-d H:i:s' and silently truncated back to the second.

               ONE CLOCK, AND IT IS THIS ONE. PHP runs in UTC here; MySQL's session
               timezone is SYSTEM, which on this host is MST — seven hours apart, and
               the column DEFAULT is MySQL's. A row that reaches that default is filed
               seven hours in the past and interleaved into the log as if it happened
               that morning, which in an audit trail is not a display problem but a
               false record of when something happened. Nothing detects it: 20:14 looks
               like a perfectly ordinary timestamp.

               Every one of the 14,729 rows on production is on PHP's clock today, and a
               walk in id order finds none going backwards. Stamping here — the one door
               every audit row goes through — is what keeps it that way no matter what a
               caller passes or forgets. */
            $rows[$i]['created_at'] = self::stamp($r['created_at'] ?? null)
                ?? now()->format(self::TS);
        }

        // An audit row must never be the reason a real action fails. This mirrors
        // what the individual call sites did behind their own try/catch.
        try {
            DB::table('audit_logs')->insert($rows);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
