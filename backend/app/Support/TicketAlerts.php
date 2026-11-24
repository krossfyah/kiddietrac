<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SUPPORT TICKETS ALERT THE PLATFORM ONLY (2026-11-24).
 *
 * Anthony: "when support tickets are logged they need to only be alerted and sent to platform admin and not to the
 * agency". Before this a ticket rang the bell of every admin and director of its agency, and account / billing /
 * enrolment / maintenance / policy tickets were emailed to them instead of to KiddieTrac.
 *
 * Now every ticket alert (raised, a raiser's reply, escalated, SLA, crash, server error) goes to:
 *   - the in-app bell (and phone push) of every active platform admin;
 *   - email to the HQ inbox (SUPPORT_INBOX, default info@kiddietrac.com) AND each platform admin's own address
 *     (Anthony chose "Both").
 * Nobody at the agency is alerted. The person who raised a ticket still hears replies and resolutions on their own
 * ticket - that is them being answered, not the agency being alerted.
 *
 * Test agencies (App\Support\TestAgency) send nothing, with ONE exception Anthony chose: these platform alerts. While
 * run() is active, the test-agency gates let through mail whose every recipient is in addresses() and push to a
 * platform admin - and nothing else.
 */
final class TicketAlerts
{
    private static bool $active = false;

    /** @return int[] active platform admins */
    public static function adminIds(): array
    {
        return DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.role', 'platform_admin')->where('ra.active', 1)
            ->whereNull('u.deleted_at')->where('u.status', 'active')
            ->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return string[] lower-cased: the HQ inbox + every platform admin's own email */
    public static function addresses(): array
    {
        $list = [strtolower(trim((string) env('SUPPORT_INBOX', 'info@kiddietrac.com')))];
        $ids = self::adminIds();
        if ($ids) {
            foreach (DB::table('users')->whereIn('id', $ids)->whereNotNull('email')->pluck('email') as $e) {
                $list[] = strtolower(trim((string) $e));
            }
        }

        return array_values(array_unique(array_filter($list)));
    }

    /** Inside a platform ticket alert? (asked by the test-agency gates) */
    public static function allowsUser(int $userId): bool
    {
        return self::$active && in_array($userId, self::adminIds(), true);
    }

    /** @param string[] $to */
    public static function allowsAddresses(array $to): bool
    {
        if (! self::$active || ! $to) {
            return false;
        }
        $ok = self::addresses();
        foreach ($to as $e) {
            if (! in_array(strtolower(trim((string) $e)), $ok, true)) {
                return false;
            }
        }

        return true;
    }

    /** Run a platform alert send (lets it through the test-agency gates; see the class note). */
    public static function asPlatform(callable $fn)
    {
        return self::run($fn);
    }

    private static function run(callable $fn)
    {
        $prev = self::$active;
        self::$active = true;
        try {
            return $fn();
        } finally {
            self::$active = $prev;
        }
    }

    /** Bell (+ push) for every platform admin except $exceptUserId. Best-effort. */
    public static function bell(int $ticketId, string $title, string $body, ?int $exceptUserId = null): int
    {
        return (int) self::run(function () use ($ticketId, $title, $body, $exceptUserId) {
            $n = 0;
            foreach (self::adminIds() as $uid) {
                if ($exceptUserId && $uid === $exceptUserId) {
                    continue;
                }
                try {
                    Notify::write([
                        'user_id' => $uid, 'type' => 'support_ticket',
                        'title' => mb_substr($title, 0, 190), 'body' => mb_substr($body, 0, 200),
                        'data' => json_encode(['link' => '#tickets', 'ticket_id' => $ticketId]),
                        'created_at' => now(),
                    ]);
                    $n++;
                } catch (\Throwable $e) {
                    Log::warning('ticket bell failed', ['ticket' => $ticketId, 'user' => $uid, 'error' => $e->getMessage()]);
                }
            }

            return $n;
        });
    }

    /** One email per address (HQ inbox + each platform admin). KiddieTrac-branded HTML expected. Best-effort. */
    public static function email(string $subject, string $html, ?string $replyTo = null): int
    {
        return (int) self::run(function () use ($subject, $html, $replyTo) {
            $n = 0;
            foreach (self::addresses() as $to) {
                try {
                    \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($to, $subject, $replyTo) {
                        MailScope::platform($m);   // KiddieTrac's own alert: Platform in the email log
                        $m->to($to)->subject($subject);
                        if ($replyTo) {
                            $m->replyTo($replyTo);
                        }
                        // Operational, action-needed: reach the platform even where an agency muted bulk mail.
                        $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                    });
                    $n++;
                } catch (\Throwable $e) {
                    Log::warning('ticket alert mail to '.$to.' failed: '.$e->getMessage());
                }
            }

            return $n;
        });
    }
}
