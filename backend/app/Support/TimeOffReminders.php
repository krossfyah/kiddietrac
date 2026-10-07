<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\TimeOffController;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Reminders to approve time-off requests (2026-10-06).
 *
 * Anthony: "have the system send reminders to the admin/director to approve time off
 * requests - configurable to every day but can be configured based on how the agency
 * wants this done or can be disabled - add this to settings somewhere."
 *
 * A request already sends its approvers a bell and an email the moment it is made. What
 * was missing is the nudge when nobody acts: the request sat "pending" until the person
 * asked again. This sends each approver ONE digest of everything waiting on them, on the
 * agency's own schedule (Settings → Clock settings → Time-off approvals).
 *
 * Settings live in agencies.settings->timeoff_reminders, merged over DEFAULTS like the
 * clock reminders beside them. When each agency was last reminded lives in its own small
 * table, so the "already sent today?" check is one conditional UPDATE that two
 * overlapping runs cannot both win.
 */
final class TimeOffReminders
{
    public const DEFAULTS = [
        'enabled' => true,
        // daily | weekdays | weekly | every_n_days
        'frequency' => 'daily',
        // 1 = Monday … 7 = Sunday (ISO), for weekly
        'weekly_day' => 1,
        // for every_n_days
        'every_n_days' => 2,
        // agency timezone
        'time' => '09:00',
        // only requests that have been waiting at least this long
        'min_hours' => 24,
        // both | admins | directors
        'recipients' => 'both',
        'email' => true,
        'in_app' => true,
        'push' => false,
    ];

    public const FREQUENCIES = ['daily', 'weekdays', 'weekly', 'every_n_days'];
    public const RECIPIENTS = ['both', 'admins', 'directors'];

    public static function read(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $mine = (isset($s['timeoff_reminders']) && is_array($s['timeoff_reminders'])) ? $s['timeoff_reminders'] : [];

        return array_merge(self::DEFAULTS, array_intersect_key($mine, self::DEFAULTS));
    }

    /** Read-modify-write the settings blob: other features keep their own keys in it. */
    public static function write(int $agencyId, array $next): array
    {
        $clean = array_merge(self::DEFAULTS, array_intersect_key($next, self::DEFAULTS));
        DB::transaction(function () use ($agencyId, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['timeoff_reminders'] = $clean;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return $clean;
    }

    public static function lastSent(int $agencyId): ?object
    {
        return DB::table('timeoff_reminder_runs')->where('agency_id', $agencyId)->first();
    }

    /**
     * Is a reminder due for this agency right now? Due once per scheduled day, at or after
     * the agency's chosen time in its own timezone. A run that was missed (the server was
     * down at 9:00) still goes out later the same day; earlier days are never caught up.
     */
    public static function isDue(int $agencyId, array $s, ?Carbon $now = null): bool
    {
        if (empty($s['enabled'])) return false;
        $tz = AgencyTime::tz($agencyId);
        $now = ($now ?: now())->copy()->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', (string) ($s['time'] ?: '09:00')) + [0, 0]);
        if ($now->lt($now->copy()->setTime($h, $m))) return false;

        $dow = (int) $now->dayOfWeekIso;
        $freq = in_array($s['frequency'], self::FREQUENCIES, true) ? $s['frequency'] : 'daily';
        if ($freq === 'weekdays' && $dow > 5) return false;
        if ($freq === 'weekly' && $dow !== (int) $s['weekly_day']) return false;

        $last = self::lastSent($agencyId);
        $today = $now->toDateString();
        if ($last && $last->last_sent_on && (string) $last->last_sent_on >= $today) return false;
        if ($freq === 'every_n_days' && $last && $last->last_sent_on) {
            $n = max(1, (int) $s['every_n_days']);
            if (Carbon::parse((string) $last->last_sent_on, $tz)->diffInDays($now->copy()->startOfDay()) < $n) return false;
        }

        return true;
    }

    /** Claim today's run for the agency. Only one caller can get true per agency per day. */
    public static function claim(int $agencyId, ?Carbon $now = null): bool
    {
        $today = ($now ?: now())->copy()->setTimezone(AgencyTime::tz($agencyId))->toDateString();
        DB::table('timeoff_reminder_runs')->insertOrIgnore(['agency_id' => $agencyId, 'last_sent_on' => null, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('timeoff_reminder_runs')->where('agency_id', $agencyId)
            ->where(fn ($q) => $q->whereNull('last_sent_on')->orWhere('last_sent_on', '<', $today))
            ->update(['last_sent_on' => $today, 'last_sent_at' => now(), 'updated_at' => now()]) === 1;
    }

    /** Pending requests old enough to remind about, with the requester's name and centre(s). */
    public static function pending(int $agencyId, array $s): Collection
    {
        $cut = now()->subHours(max(0, (int) $s['min_hours']));
        $rows = DB::table('time_off_requests as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.agency_id', $agencyId)->where('t.status', 'pending')
            ->where('t.created_at', '<=', $cut)
            ->whereNull('u.deleted_at')
            ->orderBy('t.start_at')
            ->get(['t.*', 'u.first_name', 'u.last_name']);
        foreach ($rows as $r) {
            $r->staff_name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'A team member';
            // The request's own centre, else every centre the requester works at.
            $r->centre_ids = $r->centre_id ? [(int) $r->centre_id]
                : DB::table('role_assignments')->where('user_id', $r->user_id)->where('agency_id', $agencyId)
                    ->where('active', true)->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        }

        return $rows;
    }

    /**
     * approver user id => [user object, requests they can act on].
     *
     * Agency admins can decide any request; a centre director only those from their own
     * centre. Nobody is reminded to approve their OWN request. If the agency chose
     * "directors only" and a request's centre has no director, it goes to the admins
     * instead — a reminder that reaches nobody would leave the request exactly where it was.
     */
    public static function approvers(int $agencyId, array $s, Collection $requests): array
    {
        $ra = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $agencyId)->where('ra.active', true)
            ->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->whereNull('u.deleted_at')
            ->get(['ra.user_id', 'ra.role', 'ra.centre_id', 'u.email', 'u.first_name', 'u.last_name']);
        $admins = $ra->where('role', 'agency_admin');
        $directors = $ra->where('role', 'centre_director');
        $mode = in_array($s['recipients'], self::RECIPIENTS, true) ? $s['recipients'] : 'both';

        $out = [];
        $add = function ($p, $req) use (&$out) {
            if ((int) $p->user_id === (int) $req->user_id) return;
            if (! isset($out[$p->user_id])) $out[$p->user_id] = ['user' => $p, 'requests' => []];
            $out[$p->user_id]['requests'][$req->id] = $req;
        };
        foreach ($requests as $req) {
            $dirs = $directors->filter(fn ($d) => $d->centre_id && in_array((int) $d->centre_id, $req->centre_ids, true));
            $useAdmins = $mode !== 'directors' || $dirs->filter(fn ($d) => (int) $d->user_id !== (int) $req->user_id)->isEmpty();
            if ($mode !== 'admins') foreach ($dirs as $d) $add($d, $req);
            if ($useAdmins) foreach ($admins as $a) $add($a, $req);
        }
        foreach ($out as &$o) $o['requests'] = array_values($o['requests']);

        return $out;
    }

    /** [subject, html] for one approver's digest. */
    public static function digest(int $agencyId, object $approver, array $requests, bool $test = false): array
    {
        $tz = AgencyTime::tz($agencyId);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $n = count($requests);
        $rows = '';
        foreach ($requests as $r) {
            $created = Carbon::parse((string) $r->created_at, 'UTC')->setTimezone($tz);
            $waiting = $created->diffForHumans(now()->setTimezone($tz), ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 1]);
            $when = TimeOffController::whenPhrase($r) . ', ' . Carbon::parse((string) $r->start_at)->format('Y');
            $days = '';
            if (! empty($r->all_day) || empty($r->start_time)) {
                $d = Carbon::parse((string) $r->start_at)->startOfDay()->diffInDays(Carbon::parse((string) $r->end_at)->startOfDay()) + 1;
                $days = (int) $d . ' day' . ((int) $d === 1 ? '' : 's');
            } else {
                $mins = Carbon::parse(substr((string) $r->start_time, 0, 8))->diffInMinutes(Carbon::parse(substr((string) $r->end_time, 0, 8)));
                $days = rtrim(rtrim(number_format($mins / 60, 1), '0'), '.') . ' h';
            }
            $links = '';
            if (! $test) {
                try {
                    $mk = fn ($a) => URL::temporarySignedRoute('timeoff.act', now()->addDays(14), ['id' => $r->id, 'u' => $approver->user_id, 'a' => $a]);
                    $links = '<a href="' . $e($mk('approved')) . '" style="display:inline-block;padding:6px 12px;border-radius:8px;background:#16A34A;color:#fff;text-decoration:none;font-size:13px;font-weight:700;margin-right:6px">Approve</a>'
                        . '<a href="' . $e($mk('denied')) . '" style="display:inline-block;padding:6px 12px;border-radius:8px;background:#fff;color:#B91C1C;border:1px solid #FCA5A5;text-decoration:none;font-size:13px;font-weight:700">Decline</a>';
                } catch (\Throwable $ex) { $links = ''; }
            }
            $rows .= '<tr><td style="padding:12px 0;border-top:1px solid #E2E8F0">'
                . '<div style="font-size:15px;font-weight:700;color:#0F172A">' . $e($r->staff_name) . ' · ' . $e(ucfirst((string) $r->request_type)) . '</div>'
                . '<div style="font-size:14px;color:#334155;margin-top:2px">' . $e($when) . ' · ' . $e($days) . '</div>'
                . '<div style="font-size:12.5px;color:#64748B;margin-top:2px">Asked ' . $e($created->format('M j, g:i A')) . ' · waiting ' . $e($waiting) . '</div>'
                . (! empty($r->reason) ? '<div style="font-size:13px;color:#475569;margin-top:4px">“' . $e(mb_substr((string) $r->reason, 0, 240)) . '”</div>' : '')
                . ($links ? '<div style="margin-top:8px">' . $links . '</div>' : '')
                . '</td></tr>';
        }
        $app = 'https://app.kiddietrac.com/dashboard.html#time-off';
        $body = '<p style="margin:0 0 6px;font-size:15px;color:#334155;line-height:1.6">'
            . ($n === 1 ? 'A time-off request is' : $n . ' time-off requests are') . ' waiting for your decision.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:8px 0 4px">' . $rows . '</table>'
            . '<div style="margin:16px 0 0"><a href="' . $app . '" style="display:inline-block;padding:11px 18px;border-radius:10px;background:#1F6080;color:#fff;text-decoration:none;font-weight:700;font-size:14px">Open Time off requests</a></div>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">You get this because you approve time off. Your agency sets how often it is sent (Settings → Clock settings → Time-off approvals).</p>';
        $subject = ($test ? '[Test] ' : '') . ($n === 1 ? '1 time-off request is' : $n . ' time-off requests are') . ' waiting for your approval';
        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'ACTION NEEDED', 'title' => 'Time off waiting for approval',
            'subtitle' => $n . ' pending', 'preheader' => $subject,
        ]);

        return [$subject, $html];
    }

    /**
     * Send this agency's digests. Returns a summary. Caller decides whether it is due and
     * has claimed the run; this only sends.
     */
    public static function sendAgency(int $agencyId, array $s): array
    {
        $res = ['agency_id' => $agencyId, 'pending' => 0, 'approvers' => 0, 'emails' => 0, 'in_app' => 0, 'push' => 0, 'skipped' => null];
        if (! Suppression::agencyNotificationsEnabled($agencyId)) { $res['skipped'] = 'agency notifications are switched off'; }
        $reqs = self::pending($agencyId, $s);
        $res['pending'] = $reqs->count();
        if (! $reqs->count()) { $res['skipped'] = $res['skipped'] ?: 'nothing pending'; return $res; }
        if ($res['skipped']) return $res;

        $map = self::approvers($agencyId, $s, $reqs);
        foreach ($map as $uid => $o) {
            $p = $o['user']; $list = $o['requests'];
            if (! $list || Suppression::isUser((int) $uid)) continue;
            $res['approvers']++;
            [$subject, $html] = self::digest($agencyId, $p, $list);
            if (! empty($s['email']) && filter_var((string) $p->email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $name = trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? ''));
                    AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($p, $name, $subject) {
                        $m->to($p->email, $name ?: null)->subject($subject);
                    });
                    $res['emails']++;
                } catch (\Throwable $ex) {
                    Log::warning('time-off reminder email failed', ['agency' => $agencyId, 'user' => $uid, 'error' => $ex->getMessage()]);
                }
            }
            if (! empty($s['in_app'])) {
                Notify::write(['user_id' => (int) $uid, 'type' => 'time_off_reminder', 'title' => $subject,
                    'body' => implode(' · ', array_map(fn ($r) => $r->staff_name . ' (' . TimeOffController::whenPhrase($r) . ')', array_slice($list, 0, 4))) . (count($list) > 4 ? ' …' : ''),
                    'data' => json_encode(['link' => '#time-off']), 'created_at' => now()]);
                $res['in_app']++;
            }
            if (! empty($s['push'])) {
                try { app(\App\Services\FcmService::class)->sendToUser((int) $uid, $subject, 'Open Time off requests to decide.', '#time-off'); $res['push']++; }
                catch (\Throwable $ex) { Log::warning('time-off reminder push failed', ['user' => $uid, 'error' => $ex->getMessage()]); }
            }
        }
        try {
            Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'timeoff.reminders_sent', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Time-off reminder sent to ' . $res['approvers'] . ' approver(s) about ' . $res['pending'] . ' pending request(s)'] + $res),
                'created_at' => now()]);
        } catch (\Throwable $ex) {}

        return $res;
    }
}
