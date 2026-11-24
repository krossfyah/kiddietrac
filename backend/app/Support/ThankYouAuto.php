<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AUTOMATIC THANK-YOU AWARDS (2026-10-31, Anthony: "the thank-you email can you wire it up to have it automatic each
 * month or set a threshold or date, time etc").
 *
 * Once a month, on the agency's chosen day and time (agency time zone), the PREVIOUS month's educators who qualify
 * (≥ X points, the top N earners, or everyone active) get a thank-you award (App\Support\ThankYouAward: certificate
 * email + PDF, AI or written letter, bell + push). Two modes:
 *   - review (default): drafts are prepared and the agency admins get a bell + email "N thank-you awards are ready to
 *     review"; an admin edits / sends / skips them in Settings → Educator awards. Drafts expire after 14 days.
 *   - auto: they are sent straight away.
 * A month is processed once per agency (thank_you_auto_runs, unique agency+month) and once per educator
 * (thank_you_auto_drafts, unique agency+user+month). Switching it on mid-month never backfills: only the most
 * recent completed month is ever looked at.
 * Settings: agencies.settings.thank_you_auto (admin only). Off by default.
 */
final class ThankYouAuto
{
    public const KEY = 'thank_you_auto';
    public const ROLES = ['educator', 'home_visitor', 'centre_director'];
    public const DEFAULTS = [
        'enabled' => false,
        'when' => 'day',            // day | last | first_weekday
        'day' => 1,                 // 1..28 when 'day'
        'time' => '09:00',          // agency time
        'rule' => 'min_points',     // min_points | top | all_active
        'min_points' => 500,
        'top_n' => 3,
        'floor' => 1,               // with top / all_active: at least this many points
        'roles' => ['educator', 'home_visitor'],
        'exclude' => [],
        'signer' => null,
        'tone' => 'warm',
        'message' => '',
        'mode' => 'review',         // review | auto
        'pdf' => true,
        'bcc_managers' => true,
        'expire_days' => 14,
    ];

    public static function settings(int $agencyId): array
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $mine = is_array($s[self::KEY] ?? null) ? $s[self::KEY] : [];
        $o = array_merge(self::DEFAULTS, array_intersect_key($mine, self::DEFAULTS));
        $o['enabled'] = (bool) $o['enabled'];
        $o['when'] = in_array($o['when'], ['day', 'last', 'first_weekday'], true) ? $o['when'] : 'day';
        $o['day'] = max(1, min(28, (int) $o['day']));
        $o['time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $o['time']) ? $o['time'] : '09:00';
        $o['rule'] = in_array($o['rule'], ['min_points', 'top', 'all_active'], true) ? $o['rule'] : 'min_points';
        $o['min_points'] = max(1, (int) $o['min_points']);
        $o['top_n'] = max(1, min(50, (int) $o['top_n']));
        $o['floor'] = max(1, (int) $o['floor']);
        $o['roles'] = array_values(array_intersect(self::ROLES, (array) $o['roles'])) ?: ['educator', 'home_visitor'];
        $o['exclude'] = array_values(array_unique(array_map('intval', (array) $o['exclude'])));
        $o['signer'] = $o['signer'] ? (string) $o['signer'] : null;
        $o['tone'] = in_array($o['tone'], ThankYouAward::TONES, true) ? $o['tone'] : 'warm';
        $o['message'] = mb_substr(trim((string) $o['message']), 0, 600);
        $o['mode'] = $o['mode'] === 'auto' ? 'auto' : 'review';
        $o['pdf'] = (bool) $o['pdf'];
        $o['bcc_managers'] = (bool) $o['bcc_managers'];
        $o['expire_days'] = max(3, min(60, (int) $o['expire_days']));
        return $o;
    }

    /** Save; returns [new settings, plain-English list of what changed]. */
    public static function save(int $agencyId, array $in): array
    {
        $before = self::settings($agencyId);
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $s[self::KEY] = array_merge($before, array_intersect_key($in, self::DEFAULTS));
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        $after = self::settings($agencyId);
        $changes = [];
        foreach ($after as $k => $v) {
            if ($before[$k] !== $v) {
                $show = fn ($x) => is_bool($x) ? ($x ? 'on' : 'off') : (is_array($x) ? (implode(', ', $x) ?: 'none') : ((string) $x === '' ? 'blank' : (string) $x));
                $changes[] = str_replace('_', ' ', $k) . ': ' . $show($before[$k]) . ' → ' . $show($v);
            }
        }
        return [$after, $changes];
    }

    /** "On the 1st at 9:00 AM" etc. */
    public static function scheduleLabel(array $c): string
    {
        $t = Carbon::createFromFormat('H:i', $c['time'])->format('g:i A');
        if ($c['when'] === 'last') return 'on the last day of each month at ' . $t;
        if ($c['when'] === 'first_weekday') return 'on the first weekday of each month at ' . $t;
        return 'on the ' . Carbon::create(2026, 1, $c['day'])->format('jS') . ' of each month at ' . $t;
    }

    public static function ruleLabel(array $c): string
    {
        if ($c['rule'] === 'top') return 'the top ' . $c['top_n'] . ' earners' . ($c['floor'] > 1 ? ' with at least ' . number_format($c['floor']) . ' points' : '');
        if ($c['rule'] === 'all_active') return 'everyone with at least ' . number_format($c['floor']) . ' point' . ($c['floor'] === 1 ? '' : 's');
        return 'everyone with at least ' . number_format($c['min_points']) . ' points';
    }

    /** This month's run moment (agency tz) for the schedule. */
    public static function runAt(int $agencyId, array $c, ?Carbon $now = null): Carbon
    {
        $now = ($now ?: now())->copy()->setTimezone(AgencyTime::tz($agencyId));
        [$h, $m] = array_map('intval', explode(':', $c['time']));
        $d = $now->copy()->startOfMonth();
        if ($c['when'] === 'last') {
            $d = $now->copy()->endOfMonth()->startOfDay();
        } elseif ($c['when'] === 'first_weekday') {
            while ($d->isWeekend()) $d->addDay();
        } else {
            $d->day($c['day']);
        }
        return $d->setTime($h, $m);
    }

    /** The month an award run covers: the month before the run's month. */
    public static function targetMonth(int $agencyId, ?Carbon $now = null): string
    {
        $now = ($now ?: now())->copy()->setTimezone(AgencyTime::tz($agencyId));
        return $now->copy()->startOfMonth()->subMonth()->format('Y-m');
    }

    /**
     * Who qualifies for $month under the settings: [user_id => [user_id, name, first, role, points, by]] sorted by points.
     */
    public static function qualify(int $agencyId, string $month, ?array $c = null): array
    {
        $c = $c ?: self::settings($agencyId);
        $earners = array_filter(EducatorPoints::earners($agencyId),
            fn ($e) => in_array($e['role'], $c['roles'], true) && ! in_array((int) $e['user_id'], $c['exclude'], true));
        if (! $earners) return [];
        $res = EducatorPoints::compute($agencyId, $month, $earners);
        $rows = [];
        foreach ($res as $id => $r) {
            $rows[$id] = ['user_id' => (int) $id, 'name' => $r['name'], 'first' => trim((string) strtok($r['name'], ' ')),
                'role' => $r['role'], 'points' => (int) $r['total']];
        }
        uasort($rows, fn ($a, $b) => $b['points'] <=> $a['points'] ?: strcmp($a['name'], $b['name']));
        if ($c['rule'] === 'min_points') {
            $rows = array_filter($rows, fn ($r) => $r['points'] >= $c['min_points']);
        } else {
            $rows = array_filter($rows, fn ($r) => $r['points'] >= $c['floor']);
            if ($c['rule'] === 'top') $rows = array_slice($rows, 0, $c['top_n'], true);
        }
        return $rows;
    }

    /** Agency admins who should hear that drafts are ready (people, not service logins). */
    public static function managers(int $agencyId): array
    {
        $isPerson = fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL) && ! preg_match('/^(noreply|no-reply)@|integration\+/i', $e);
        return DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin')->where('ra.active', 1)
            ->whereNull('u.deleted_at')->whereNotIn('u.status', Audience::OFF_STATUSES)
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email'])
            ->filter(fn ($u) => $isPerson((string) $u->email))->unique('id')
            ->map(fn ($u) => ['id' => (int) $u->id, 'first' => trim((string) $u->first_name), 'name' => trim($u->first_name . ' ' . $u->last_name), 'email' => strtolower(trim($u->email))])
            ->values()->all();
    }

    /**
     * The scheduled pass for one agency. Returns a summary. $dry: count only. $force: ignore the schedule moment
     * (still once per month). Never backfills: only targetMonth().
     */
    public static function runAgency(int $agencyId, bool $dry = false, bool $force = false, ?Carbon $now = null): array
    {
        $c = self::settings($agencyId);
        $out = ['agency' => $agencyId, 'enabled' => $c['enabled'], 'month' => self::targetMonth($agencyId, $now), 'due' => false, 'qualify' => [], 'drafts' => 0, 'sent' => 0, 'failed' => 0];
        if (! $c['enabled'] && ! $dry) return $out;
        $runAt = self::runAt($agencyId, $c, $now);
        $nowTz = ($now ?: now())->copy()->setTimezone(AgencyTime::tz($agencyId));
        $out['run_at'] = $runAt->toDateTimeString();
        $out['due'] = $force || $nowTz->greaterThanOrEqualTo($runAt);
        $month = $out['month'];
        $already = DB::table('thank_you_auto_runs')->where('agency_id', $agencyId)->where('month', $month)->exists();
        $out['already'] = $already;
        $q = self::qualify($agencyId, $month, $c);
        $out['qualify'] = array_values(array_map(fn ($r) => ['first' => $r['first'], 'role' => $r['role'], 'points' => $r['points']], $q));
        if ($dry || ! $out['due'] || $already || ! $c['enabled']) return $out;

        // Claim the month first, so two overlapping passes can never both run it.
        try {
            $runId = DB::table('thank_you_auto_runs')->insertGetId(['agency_id' => $agencyId, 'month' => $month, 'mode' => $c['mode'],
                'qualified' => count($q), 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $out['already'] = true;
            return $out;
        }
        $signer = ThankYouAward::signer($agencyId, $c['signer']);
        if (! $signer) {
            self::audit($agencyId, null, 'thank_you_auto.no_signer', null, ['summary' => 'Automatic thank-you awards for ' . self::monthLabel($month) . ' were not prepared: this agency has no admin to sign them.']);
            return $out + ['error' => 'no signer'];
        }
        $expires = now()->addDays($c['expire_days']);
        foreach ($q as $uid => $r) {
            if (DB::table('thank_you_auto_drafts')->where('agency_id', $agencyId)->where('user_id', $uid)->where('month', $month)->exists()) continue;
            if (DB::table('educator_rewards')->where('agency_id', $agencyId)->where('user_id', $uid)->where('month', $month)->where('method', 'thank_you')->exists()) continue;
            $ctx = ThankYouAward::context($agencyId, (int) $uid, $month, $c['message'] ?: null, $signer);
            $n = ['note' => '', 'ai' => false];
            try { $n = ThankYouAward::note($agencyId, $ctx, $c['tone']); } catch (\Throwable $e) { report($e); }
            $draftId = DB::table('thank_you_auto_drafts')->insertGetId([
                'agency_id' => $agencyId, 'run_id' => $runId, 'user_id' => $uid, 'month' => $month, 'points' => (int) $ctx['points'],
                'letter' => $n['note'], 'letter_ai' => (bool) $n['ai'], 'message' => $c['message'] ?: null, 'signer' => $signer['key'],
                'status' => 'pending', 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $out['drafts']++;
            if ($c['mode'] === 'auto') {
                $res = self::sendDraft($draftId, null);
                ($res['ok'] ?? false) ? $out['sent']++ : $out['failed']++;
            }
        }
        DB::table('thank_you_auto_runs')->where('id', $runId)->update(['drafts' => $out['drafts'], 'sent' => $out['sent'], 'updated_at' => now()]);

        $names = implode(', ', array_map(fn ($r) => $r['name'] . ' (' . number_format($r['points']) . ' pts)', $q));
        if ($c['mode'] === 'review' && $out['drafts'] > 0) {
            self::tellManagers($agencyId, $month, $out['drafts'], array_values($q));
            self::audit($agencyId, null, 'thank_you_auto.drafts_ready', $runId, [
                'summary' => 'System prepared ' . $out['drafts'] . ' automatic thank-you award' . ($out['drafts'] === 1 ? '' : 's') . ' for ' . self::monthLabel($month)
                    . ' (' . self::ruleLabel($c) . ') for review: ' . $names . '. Scheduled job kt:thank-you-awards.',
                'month' => $month, 'count' => $out['drafts'],
            ]);
        } elseif ($c['mode'] === 'auto') {
            self::audit($agencyId, null, 'thank_you_auto.sent', $runId, [
                'summary' => 'System sent ' . $out['sent'] . ' automatic thank-you award' . ($out['sent'] === 1 ? '' : 's') . ' for ' . self::monthLabel($month)
                    . ' (' . self::ruleLabel($c) . ')' . ($out['failed'] ? ', ' . $out['failed'] . ' could not be emailed' : '') . ': ' . ($names ?: 'nobody qualified') . '. Scheduled job kt:thank-you-awards.',
                'month' => $month, 'sent' => $out['sent'], 'failed' => $out['failed'],
            ]);
        } else {
            self::audit($agencyId, null, 'thank_you_auto.none', $runId, [
                'summary' => 'Automatic thank-you awards for ' . self::monthLabel($month) . ': nobody qualified (' . self::ruleLabel($c) . '). Scheduled job kt:thank-you-awards.',
                'month' => $month,
            ]);
        }
        return $out;
    }

    /** Pending drafts past their expiry become "expired". Returns the count. */
    public static function expire(): int
    {
        $rows = DB::table('thank_you_auto_drafts')->where('status', 'pending')->where('expires_at', '<', now())->get(['id', 'agency_id', 'user_id', 'month']);
        foreach ($rows as $d) {
            DB::table('thank_you_auto_drafts')->where('id', $d->id)->update(['status' => 'expired', 'updated_at' => now()]);
            $name = self::name((int) $d->user_id);
            self::audit((int) $d->agency_id, null, 'thank_you_auto.expired', (int) $d->id, [
                'summary' => 'The automatic thank-you award draft for ' . $name . ' (' . self::monthLabel($d->month) . ') expired without being sent. Scheduled job kt:thank-you-awards.',
            ]);
        }
        return $rows->count();
    }

    /**
     * Send one draft (by an admin, or the system when $byUserId is null). The same path as a hand-sent award:
     * ThankYouAward::send, an educator_rewards "thank_you" row, bell + push, audit.
     */
    public static function sendDraft(int $draftId, ?int $byUserId): array
    {
        $d = DB::table('thank_you_auto_drafts')->where('id', $draftId)->first();
        if (! $d || $d->status !== 'pending') return ['ok' => false, 'message' => 'This draft was already handled.'];
        $agencyId = (int) $d->agency_id;
        $c = self::settings($agencyId);
        if (DB::table('educator_rewards')->where('agency_id', $agencyId)->where('user_id', $d->user_id)->where('month', $d->month)->where('method', 'thank_you')->exists()) {
            DB::table('thank_you_auto_drafts')->where('id', $draftId)->update(['status' => 'skipped', 'decided_by_id' => $byUserId, 'decided_at' => now(), 'updated_at' => now()]);
            return ['ok' => false, 'message' => 'They already received a thank-you award for that month.'];
        }
        $signer = ThankYouAward::signer($agencyId, $d->signer);
        if (! $signer) return ['ok' => false, 'message' => 'This agency has no admin to sign the award.'];
        // Claim it so two clicks can't send it twice.
        $claimed = DB::table('thank_you_auto_drafts')->where('id', $draftId)->where('status', 'pending')->update(['status' => 'sending', 'updated_at' => now()]);
        if (! $claimed) return ['ok' => false, 'message' => 'This draft was already handled.'];

        $uid = (int) $d->user_id;
        $ctx = ThankYouAward::context($agencyId, $uid, $d->month, $d->message ?: null, $signer);
        $ctx['note'] = (string) $d->letter;
        $ctx['auto'] = true;
        $mail = ['sent' => false];
        try {
            $mail = ThankYouAward::send($agencyId, $ctx, $uid, ['bcc' => $c['bcc_managers'], 'pdf' => $c['pdf']]);
        } catch (\Throwable $e) {
            report($e);
        }
        $ok = (bool) ($mail['sent'] ?? false);
        $cfg = EducatorPoints::settings($agencyId);
        $rid = DB::table('educator_rewards')->insertGetId([
            'agency_id' => $agencyId, 'user_id' => $uid, 'month' => $d->month, 'points' => (int) $ctx['points'],
            'amount' => 0, 'currency' => $cfg['currency'], 'method' => 'thank_you', 'status' => $ok ? 'sent' : 'failed',
            'reference' => mb_substr('Automatic · signed by ' . $signer['first'] . ' (' . $signer['title'] . ')', 0, 120),
            'note' => $d->message, 'letter' => $d->letter, 'awarded_by_id' => $byUserId, 'created_at' => now(), 'updated_at' => now(),
        ] + EducatorAwardHistory::snapshot($agencyId, $uid, $byUserId, 'auto'));
        DB::table('thank_you_auto_drafts')->where('id', $draftId)->update(['status' => $ok ? 'sent' : 'failed', 'reward_id' => $rid,
            'decided_by_id' => $byUserId, 'decided_at' => now(), 'updated_at' => now()]);
        $monthLabel = self::monthLabel($d->month);
        $body = mb_substr($signer['first'] . ' sent you a certificate of appreciation for ' . $monthLabel . '. Check your email.', 0, 240);
        Notify::write(['user_id' => $uid, 'type' => 'educator_reward', 'title' => '🏅 Thank you!', 'body' => $body,
            'data' => json_encode(['link' => '#my-points', 'award_id' => $rid])]);
        $push = 'not sent';
        try {
            $r = app(\App\Services\FcmService::class)->sendToUser($uid, '🏅 Thank you!', $body, '#my-points');
            $push = ! empty($r['suppressed']) ? 'suppressed' : ((($r['sent'] ?? 0) > 0) ? 'sent' : 'no device');
        } catch (\Throwable $e) {
            report($e);
        }
        $who = $byUserId ? self::name($byUserId) : 'System (scheduled: kt:thank-you-awards)';
        self::audit($agencyId, $byUserId, 'educator_reward.thanked', $rid, [
            'summary' => $who . ' sent ' . self::name($uid) . ' an automatic thank-you award for ' . $monthLabel . ' (' . number_format((int) $ctx['points']) . ' points), signed by '
                . $signer['first'] . ' (' . $signer['title'] . '), with a personal letter' . ($d->letter_ai ? ' (drafted with the AI writing helper)' : '')
                . (($mail['pdf'] ?? false) ? ' and a PDF certificate' : '') . '.'
                . ($ok ? ' Emailed ' . $mail['to_email'] . (! empty($mail['bcc_emails']) ? ', BCC ' . implode(', ', $mail['bcc_emails']) : '') . '.' : ' No email could be sent.'),
            'user_id' => $uid, 'month' => $d->month, 'points' => (int) $ctx['points'], 'signer' => $signer['key'], 'push' => $push,
            'automatic' => true, 'draft_id' => $draftId, 'not_emailed' => $mail['skipped'] ?? [],
        ]);
        return ['ok' => $ok, 'reward_id' => $rid, 'message' => $ok ? null : 'There is no email address to send it to. They still got an in-app notice.'];
    }

    /** Bell + email to the agency admins: N drafts are waiting. */
    private static function tellManagers(int $agencyId, string $month, int $count, array $people): void
    {
        $label = self::monthLabel($month);
        $title = '🏅 ' . $count . ' thank-you award' . ($count === 1 ? ' is' : 's are') . ' ready to review';
        $body = 'For ' . $label . ': ' . implode(', ', array_slice(array_map(fn ($p) => $p['first'], $people), 0, 6)) . ($count > 6 ? ' and ' . ($count - 6) . ' more' : '') . '. Review, edit and send them in Settings → Educator awards.';
        $link = '#educator-award-settings';   // Settings → Educator awards (the Automatic awards tab was removed 2026-11-02)
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $F = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;";
        foreach (self::managers($agencyId) as $m) {
            Notify::write(['user_id' => $m['id'], 'type' => 'educator_reward', 'title' => $title, 'body' => $body, 'data' => json_encode(['link' => $link])]);
            try { app(\App\Services\FcmService::class)->sendToUser($m['id'], $title, $body, $link); } catch (\Throwable $ex) { report($ex); }
            if (Suppression::isUser($m['id'])) continue;
            $rows = '';
            foreach ($people as $p) {
                $rows .= '<tr><td style="padding:9px 12px;border-bottom:1px solid #E2E8F0;' . $F . 'font-size:14px;color:#0F172A;">' . $e($p['first']) . '</td>'
                    . '<td align="right" style="padding:9px 12px;border-bottom:1px solid #E2E8F0;' . $F . 'font-size:14px;font-weight:700;color:#0F172A;">' . number_format($p['points']) . ' pts</td></tr>';
            }
            $btn = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 6px;"><tr><td class="kt-btn" bgcolor="#13708A" style="background:#13708A;border-radius:12px;">'
                . '<a class="kt-btn" href="https://app.kiddietrac.com/dashboard.html' . $link . '" style="display:inline-block;padding:13px 26px;' . $F . 'font-size:15px;font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:12px;">Review the awards →</a></td></tr></table>';
            $html = \App\Services\EmailTemplate::wrap($agencyId,
                '<div style="' . $F . 'font-size:15px;line-height:1.6;color:#334155;">Hi ' . $e($m['first'] ?: 'there') . ',<br>'
                . 'The automatic thank-you awards for <strong>' . $e($label) . '</strong> are ready. Each one has a personal letter you can read and edit before it goes out. Nothing is sent until you choose Send.</div>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="kt-panel" style="margin:16px 0 0;border:1px solid #E2E8F0;border-radius:12px;background:#FFFFFF;">' . $rows . '</table>'
                . $btn
                . '<div style="' . $F . 'font-size:12.5px;color:#64748B;">Drafts you don’t send expire after ' . self::settings($agencyId)['expire_days'] . ' days.</div>',
                ['eyebrow' => 'EDUCATOR APPRECIATION', 'title' => $count . ' thank-you award' . ($count === 1 ? '' : 's') . ' to review', 'subtitle' => $label,
                 'preheader' => 'The automatic thank-you awards for ' . $label . ' are ready to review.']);
            try {
                \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($msg) use ($m, $title) {
                    $msg->to($m['email'], $m['name'] ?: null)->subject(trim(preg_replace('/^\S+\s/u', '', $title)));
                });
            } catch (\Throwable $ex) {
                Log::warning('thank-you auto review email failed', ['user' => $m['id'], 'error' => $ex->getMessage()]);
            }
        }
    }

    public static function monthLabel(string $month): string
    {
        return Carbon::parse($month . '-01')->format('F Y');
    }

    public static function name(int $userId): string
    {
        $u = DB::table('users')->where('id', $userId)->first(['first_name', 'last_name']);
        return $u ? (trim($u->first_name . ' ' . $u->last_name) ?: 'a staff member') : 'a staff member';
    }

    public static function audit(int $agencyId, ?int $userId, string $action, ?int $id, array $payload): void
    {
        try {
            Audit::write(['user_id' => $userId, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'educator_reward',
                'entity_id' => $id, 'payload' => json_encode($payload), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }
}
