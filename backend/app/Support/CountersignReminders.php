<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reminders to counter-sign completed forms (2026-10-09).
 *
 * Anthony: "is there an email that gets sent to directors/admins to counter sign forms
 * that came back as a reminder - these should be daily emails and configuration on the
 * notifications in settings". There was none: a signed form sent one "Form submitted"
 * notice and then sat "Awaiting review" until somebody happened to open the Completed tab.
 *
 * Each admin/director gets ONE digest of the completed forms waiting for their
 * counter-signature, on the agency's schedule (Settings → Notifications). Only forms set to
 * "Requires counter-signature" count; a form sent back for correction is waiting on the
 * signer, not the office, so it is left out until it is re-signed.
 *
 * Built like App\Support\TimeOffReminders: settings in agencies.settings->countersign_reminders
 * over DEFAULTS, and the day's run claimed with one conditional UPDATE.
 */
final class CountersignReminders
{
    public const KEY = 'countersign_reminders';

    public const DEFAULTS = [
        'enabled' => true,
        // daily | weekdays | weekly | every_n_days
        'frequency' => 'daily',
        'weekly_day' => 1,
        'every_n_days' => 2,
        // agency timezone
        'time' => '09:00',
        // only forms signed at least this long ago
        'min_hours' => 12,
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
        $mine = (isset($s[self::KEY]) && is_array($s[self::KEY])) ? $s[self::KEY] : [];

        return array_merge(self::DEFAULTS, array_intersect_key($mine, self::DEFAULTS));
    }

    public static function write(int $agencyId, array $next): array
    {
        $clean = array_merge(self::DEFAULTS, array_intersect_key($next, self::DEFAULTS));
        DB::transaction(function () use ($agencyId, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s[self::KEY] = $clean;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return $clean;
    }

    public static function lastSent(int $agencyId): ?object
    {
        return DB::table('countersign_reminder_runs')->where('agency_id', $agencyId)->first();
    }

    /** Due once per scheduled day, at or after the agency's time in its own timezone. */
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

    public static function claim(int $agencyId, ?Carbon $now = null): bool
    {
        $today = ($now ?: now())->copy()->setTimezone(AgencyTime::tz($agencyId))->toDateString();
        DB::table('countersign_reminder_runs')->insertOrIgnore(['agency_id' => $agencyId, 'last_sent_on' => null, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('countersign_reminder_runs')->where('agency_id', $agencyId)
            ->where(fn ($q) => $q->whereNull('last_sent_on')->orWhere('last_sent_on', '<', $today))
            ->update(['last_sent_on' => $today, 'last_sent_at' => now(), 'updated_at' => now()]) === 1;
    }

    /**
     * Completed forms waiting for the office: signed, not counter-signed, not sent back,
     * on a form that requires a counter-signature, signed at least min_hours ago. Each row
     * carries the signer's provider(s) — so a director is reminded only about their own.
     * A signer who has left (no live family, no active role) is not someone to chase.
     */
    public static function pending(int $agencyId, array $s): Collection
    {
        $cut = now()->subHours(max(0, (int) $s['min_hours']));
        $rows = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('f.agency_id', $agencyId)
            ->where('f.requires_countersign', 1)
            ->whereNotNull('s.signed_at')
            ->whereNull('s.countersigned_at')
            ->whereNull('s.correction_requested_at')
            ->where('s.signed_at', '<=', $cut)
            ->whereNull('u.deleted_at')
            ->orderBy('s.signed_at')
            ->get(['s.id', 's.user_id', 's.signed_at', 's.signer_name', 'f.id as form_id', 'f.title', 'u.first_name', 'u.last_name']);

        $out = collect();
        foreach ($rows as $r) {
            $fam = Live::families(DB::table('families as fa')->join('guardians as g', 'g.family_id', '=', 'fa.id'), 'fa')
                ->where('g.user_id', $r->user_id)->pluck('fa.centre_id')->all();
            $staff = DB::table('role_assignments')->where('user_id', $r->user_id)->where('agency_id', $agencyId)
                ->where('active', true)->whereNotNull('centre_id')->pluck('centre_id')->all();
            $isStaff = DB::table('role_assignments')->where('user_id', $r->user_id)->where('active', true)
                ->whereIn('role', ['educator', 'home_visitor', 'centre_director', 'agency_admin'])->exists();
            if (! $fam && ! $isStaff) continue;   // left: nobody to chase
            $r->centre_ids = array_values(array_unique(array_map('intval', array_merge($fam, $staff))));
            $r->person = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ($r->signer_name ?: 'Someone');
            $out->push($r);
        }

        return $out;
    }

    /** reviewer user id => ['user' => row, 'items' => [...]]. Same rules as time-off approvers. */
    public static function reviewers(int $agencyId, array $s, Collection $items): array
    {
        $ra = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $agencyId)->where('ra.active', true)
            ->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->whereNull('u.deleted_at')
            ->get(['ra.user_id', 'ra.role', 'ra.centre_id', 'u.email', 'u.first_name', 'u.last_name'])
            // An integration / no-reply mailbox with an admin role is not a reviewer.
            ->reject(fn ($p) => ChatAudience::isServiceEmail($p->email));
        $admins = $ra->where('role', 'agency_admin');
        $directors = $ra->where('role', 'centre_director');
        $mode = in_array($s['recipients'], self::RECIPIENTS, true) ? $s['recipients'] : 'both';

        $out = [];
        $add = function ($p, $it) use (&$out) {
            if ((int) $p->user_id === (int) $it->user_id) return;   // never your own form
            if (! isset($out[$p->user_id])) $out[$p->user_id] = ['user' => $p, 'items' => []];
            $out[$p->user_id]['items'][$it->id] = $it;
        };
        foreach ($items as $it) {
            $dirs = $directors->filter(fn ($d) => $d->centre_id && in_array((int) $d->centre_id, $it->centre_ids, true));
            $useAdmins = $mode !== 'directors' || $dirs->filter(fn ($d) => (int) $d->user_id !== (int) $it->user_id)->isEmpty();
            if ($mode !== 'admins') foreach ($dirs as $d) $add($d, $it);
            if ($useAdmins) foreach ($admins as $a) $add($a, $it);
        }
        foreach ($out as &$o) $o['items'] = array_values($o['items']);

        return $out;
    }

    /** [subject, html] — grouped by form, oldest first, so a long list stays readable. */
    public static function digest(int $agencyId, array $items, bool $test = false): array
    {
        $tz = AgencyTime::tz($agencyId);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $n = count($items);
        $byForm = [];
        foreach ($items as $it) { $byForm[$it->title][] = $it; }
        $rows = '';
        foreach ($byForm as $title => $list) {
            usort($list, fn ($a, $b) => strcmp((string) $a->signed_at, (string) $b->signed_at));
            $lines = '';
            foreach (array_slice($list, 0, 6) as $it) {
                $at = Carbon::parse((string) $it->signed_at, 'UTC')->setTimezone($tz);
                $waiting = $at->diffForHumans(now()->setTimezone($tz), ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 1]);
                $lines .= '<div style="font-size:13.5px;color:#334155;margin-top:3px">' . $e($it->person)
                    . ' <span style="color:#64748B">· signed ' . $e($at->format('M j, g:i A')) . ' · waiting ' . $e($waiting) . '</span></div>';
            }
            if (count($list) > 6) {
                $lines .= '<div style="font-size:12.5px;color:#64748B;margin-top:3px">and ' . (count($list) - 6) . ' more</div>';
            }
            $rows .= '<tr><td style="padding:12px 0;border-top:1px solid #E2E8F0">'
                . '<div style="font-size:15px;font-weight:700;color:#0F172A">' . $e($title)
                . ' <span style="font-weight:600;color:#1D4ED8">(' . count($list) . ')</span></div>' . $lines . '</td></tr>';
        }
        $app = 'https://app.kiddietrac.com/dashboard.html#forms-manager';
        $body = '<p style="margin:0 0 6px;font-size:15px;color:#334155;line-height:1.6">'
            . ($n === 1 ? 'A completed form is' : $n . ' completed forms are') . ' waiting for your review and counter-signature.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:8px 0 4px">' . $rows . '</table>'
            . '<div style="margin:16px 0 0"><a href="' . $app . '" style="display:inline-block;padding:11px 18px;border-radius:10px;background:#1F6080;color:#fff;text-decoration:none;font-weight:700;font-size:14px">Open Forms → Completed</a></div>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">You get this because you review forms. Your agency sets how often it is sent (Settings → Notifications). '
            . 'Forms set to need no counter-signature are never included.</p>';
        $subject = ($test ? '[Test] ' : '') . ($n === 1 ? '1 form is' : $n . ' forms are') . ' waiting for your counter-signature';
        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'ACTION NEEDED', 'title' => 'Forms waiting for counter-signature',
            'subtitle' => $n . ' waiting', 'preheader' => $subject,
        ]);

        return [$subject, $html];
    }

    public static function sendAgency(int $agencyId, array $s): array
    {
        $res = ['agency_id' => $agencyId, 'pending' => 0, 'reviewers' => 0, 'emails' => 0, 'in_app' => 0, 'push' => 0, 'skipped' => null];
        if (! Suppression::agencyNotificationsEnabled($agencyId)) { $res['skipped'] = 'agency notifications are switched off'; }
        $items = self::pending($agencyId, $s);
        $res['pending'] = $items->count();
        if (! $items->count()) { $res['skipped'] = $res['skipped'] ?: 'nothing waiting'; return $res; }
        if ($res['skipped']) return $res;

        $mailed = [];   // one email per inbox: two accounts on one address get one copy
        foreach (self::reviewers($agencyId, $s, $items) as $uid => $o) {
            $p = $o['user']; $list = $o['items'];
            if (! $list || Suppression::isUser((int) $uid)) continue;
            $res['reviewers']++;
            [$subject, $html] = self::digest($agencyId, $list);
            $inbox = mb_strtolower(trim((string) $p->email));
            if (! empty($s['email']) && filter_var((string) $p->email, FILTER_VALIDATE_EMAIL) && ! isset($mailed[$inbox])) {
                $mailed[$inbox] = true;
                try {
                    $name = trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? ''));
                    AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($p, $name, $subject) {
                        $m->to($p->email, $name ?: null)->subject($subject);
                    });
                    $res['emails']++;
                } catch (\Throwable $ex) {
                    Log::warning('countersign reminder email failed', ['agency' => $agencyId, 'user' => $uid, 'error' => $ex->getMessage()]);
                }
            }
            if (! empty($s['in_app'])) {
                Notify::write(['user_id' => (int) $uid, 'type' => 'countersign_reminder', 'title' => $subject,
                    'body' => implode(' · ', array_map(fn ($it) => $it->title . ' (' . $it->person . ')', array_slice($list, 0, 3))) . (count($list) > 3 ? ' …' : ''),
                    'data' => json_encode(['link' => '#forms-manager']), 'created_at' => now()]);
                $res['in_app']++;
            }
            if (! empty($s['push'])) {
                try { app(\App\Services\FcmService::class)->sendToUser((int) $uid, $subject, 'Open Forms → Completed to review.', '#forms-manager'); $res['push']++; }
                catch (\Throwable $ex) { Log::warning('countersign reminder push failed', ['user' => $uid, 'error' => $ex->getMessage()]); }
            }
        }
        try {
            Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'forms.countersign_reminders_sent', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Counter-signature reminder sent to ' . $res['reviewers'] . ' reviewer(s) about ' . $res['pending'] . ' waiting form(s)'] + $res),
                'created_at' => now()]);
        } catch (\Throwable $ex) {}

        return $res;
    }
}
