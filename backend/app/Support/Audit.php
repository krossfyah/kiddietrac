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
