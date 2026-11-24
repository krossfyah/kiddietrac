<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;

/**
 * NO EMAIL ADDRESSES IN laravel.log (2026-11-10, PIPEDA review). Some 40 Log:: calls pass a recipient ('to', 'email',
 * blocked_recipients ...) and exception messages repeat addresses too. The log is plain text, kept 14 days, copied
 * into backups and read while debugging, so one processor on the file channels masks every address before it is
 * written: "anthony@example.com" -> "an•••@example.com" - enough to tell accounts apart, not to contact or identify
 * anyone. The full address stays where it is protected and needed: email_logs, audit_logs, the user row.
 * Wired as a 'tap' on the single and daily channels in config/logging.php (never config:cache - env() is read live).
 */
final class MaskPersonalData
{
    private const RX = '/([A-Za-z0-9._%+\-]{1,2})[A-Za-z0-9._%+\-]*@([A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,})/';

    public function __invoke($logger): void
    {
        $logger->pushProcessor(function (LogRecord $r): LogRecord {
            return $r->with(message: self::mask($r->message), context: self::walk($r->context), extra: self::walk($r->extra));
        });
    }

    public static function mask(string $s): string
    {
        return str_contains($s, '@') ? (string) preg_replace(self::RX, '$1•••@$2', $s) : $s;
    }

    private static function walk(array $a, int $depth = 0): array
    {
        if ($depth > 6) return $a;
        foreach ($a as $k => $v) {
            if (is_string($v)) $a[$k] = self::mask($v);
            elseif (is_array($v)) $a[$k] = self::walk($v, $depth + 1);
            // An exception is formatted later by Monolog, out of reach here. Only one whose message holds an address
            // is flattened to a masked one-line string (that entry loses its stack trace; the rest keep theirs).
            elseif ($v instanceof \Throwable && str_contains($v->getMessage(), '@')) {
                $a[$k] = get_class($v) . ': ' . self::mask($v->getMessage()) . ' at ' . $v->getFile() . ':' . $v->getLine();
            }
        }

        return $a;
    }
}
