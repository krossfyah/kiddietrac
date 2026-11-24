<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STAFF CERTIFICATE EXPIRY ALERTS (2026-11-03).
 *
 * Anthony: "when staff certifications expire or about to expire what alerts are sent out to both admin/director and
 * educators?" - only the holder got a weekly bell (60 days out to 30 days after), no email, nobody else. Then:
 * "wire up settings for staff certifications ... send emails out as well with options with days in advance leading up
 * to the expiry date configurable in the settings". His picks: holder + centre director + agency admin, bell + push +
 * email, steps 60/30/14/7/1 + the day it expires, then weekly while it stays expired; settings inside Notification
 * settings (screen-notification-settings.js, tab "Staff certifications").
 *
 * agencies.settings.cert_alerts = DEFAULTS below. Run daily by expiry:warn (08:00).
 *
 * Each certificate gets ONE alert per step. The step is the band it is in today (e.g. 20 days out = the 30-day step),
 * so a day the job missed, or a certificate added late, still gets the step it is in - never a burst of every step
 * it skipped. cert_alert_log remembers what went (cert, agency, step). A certificate that has been renewed - the same
 * person holds a newer one of the same type - is never chased. Closed accounts are never chased.
 * Holders: their own bell + email. Directors (of the holder's centres) and agency admins: ONE roll-up bell + email per
 * run listing every certificate at a step, so a busy Monday is one message, not twenty.
 */
final class CertExpiryAlerts
{
    public const DEFAULTS = [
        'enabled' => true,
        'steps' => [60, 30, 14, 7, 1],   // days before expiry
        'on_day' => true,                 // the day it expires (and the first week after)
        'weekly_after' => true,           // then every 7 days until renewed
        'holder' => true,
        'directors' => true,
        'admins' => true,
        'email' => true,
    ];

    private const STAFF_ROLES = ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'cook', 'staff'];

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;

        return self::clean(array_merge(self::DEFAULTS, (array) ($all['cert_alerts'] ?? [])));
    }

    public static function save(int $agencyId, array $in): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $all['cert_alerts'] = self::clean(array_merge(self::settings($agencyId), $in));
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all), 'updated_at' => now()]);

        return self::settings($agencyId);
    }

    private static function clean(array $s): array
    {
        $steps = array_values(array_unique(array_filter(array_map('intval', (array) $s['steps']), fn ($d) => $d >= 1 && $d <= 365)));
        rsort($steps);
        $out = ['steps' => array_slice($steps, 0, 8)];
        foreach (['enabled', 'on_day', 'weekly_after', 'holder', 'directors', 'admins', 'email'] as $k) $out[$k] = (bool) $s[$k];

        return $out;
    }

    /** Which step a certificate is at today (null = none). $d = days until expiry (negative once expired). */
    public static function stepKey(int $d, array $s): ?string
    {
        if ($d > 0) {
            $asc = $s['steps']; sort($asc);
            foreach ($asc as $step) if ($d <= $step) return 'b' . $step;

            return null;
        }
        if ($d > -7) return $s['on_day'] ? 'd0' : null;

        return $s['weekly_after'] ? 'w' . intdiv(-$d, 7) : null;
    }

    public static function phrase(int $d, Carbon $exp): string
    {
        if ($d > 0) return 'expires in ' . $d . ' day' . ($d === 1 ? '' : 's') . ' (' . $exp->format('M j, Y') . ')';
        if ($d === 0) return 'expires today';

        return 'expired ' . (-$d) . ' day' . ($d === -1 ? '' : 's') . ' ago (' . $exp->format('M j, Y') . ')';
    }

    /**
     * What this agency's alerts would be today, not yet sent: [{cert_id, user_id, name, first_name, email, label,
     * days, phrase, key, centre_ids}]. With $settings = the agency's (or ones being previewed).
     */
    public static function plan(int $agencyId, ?array $settings = null): array
    {
        $s = $settings ?? self::settings($agencyId);
        if (! $s['enabled']) return [];
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();

        // Staff of this agency (directly or through one of its centres), still with us.
        $ra = DB::table('role_assignments')->where('active', 1)->whereIn('role', self::STAFF_ROLES)
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereIn('centre_id', $centreIds ?: [0]))
            ->whereIn('user_id', fn ($q) => Audience::excludeService(Audience::excludeOff($q->from('users as u')->whereNull('u.deleted_at'), 'u'))->select('u.id'))
            ->get(['user_id', 'centre_id']);
        if ($ra->isEmpty()) return [];
        $centresOf = [];
        foreach ($ra as $r) {
            $centresOf[(int) $r->user_id] = $centresOf[(int) $r->user_id] ?? [];
            if ($r->centre_id && in_array((int) $r->centre_id, $centreIds, true)) $centresOf[(int) $r->user_id][] = (int) $r->centre_id;
        }

        $certs = DB::table('staff_certifications')->where('active', 1)->whereIn('user_id', array_keys($centresOf))
            ->where(fn ($w) => $w->whereNull('agency_id')->orWhere('agency_id', $agencyId))
            ->get(['id', 'user_id', 'cert_type', 'title', 'expires_at']);
        // Renewed = a newer one of the same type for the same person (or one that never expires).
        $latest = [];
        foreach ($certs as $c) {
            $k = $c->user_id . '|' . strtolower((string) $c->cert_type);
            $cur = $latest[$k] ?? null;
            $exp = $c->expires_at ? (string) $c->expires_at : '9999-12-31';
            if (! $cur || $exp > $cur[0] || ($exp === $cur[0] && $c->id > $cur[1]->id)) $latest[$k] = [$exp, $c];
        }

        $today = AgencyTime::todayDate($agencyId)->startOfDay();
        $sent = Schema::hasTable('cert_alert_log')
            ? DB::table('cert_alert_log')->where('agency_id', $agencyId)->get(['cert_id', 'step_key'])->map(fn ($r) => $r->cert_id . '|' . $r->step_key)->flip()->all()
            : [];
        $users = DB::table('users')->whereIn('id', array_keys($centresOf))->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');

        $out = [];
        foreach ($latest as [$expStr, $c]) {
            if (! $c->expires_at) continue;
            $exp = Carbon::parse((string) $c->expires_at)->startOfDay();
            $d = (int) $today->diffInDays($exp, false);
            $key = self::stepKey($d, $s);
            if ($key === null || isset($sent[$c->id . '|' . $key])) continue;
            $u = $users[(int) $c->user_id] ?? null;
            if (! $u) continue;
            $out[] = [
                'cert_id' => (int) $c->id, 'user_id' => (int) $c->user_id,
                'name' => trim($u->first_name . ' ' . $u->last_name), 'first_name' => (string) $u->first_name, 'email' => (string) $u->email,
                'label' => $c->title ?: str_replace('_', ' ', (string) $c->cert_type),
                'days' => $d, 'phrase' => self::phrase($d, $exp), 'key' => $key,
                'centre_ids' => array_values(array_unique($centresOf[(int) $c->user_id] ?? [])),
            ];
        }
        usort($out, fn ($a, $b) => $a['days'] <=> $b['days']);

        return $out;
    }

    /** Directors of these centres + agency admins, as the settings allow: [user_id => {id, first_name, email}]. */
    public static function managers(int $agencyId, array $centreIds, array $s): array
    {
        if (! $s['directors'] && ! $s['admins']) return [];
        $q = Audience::excludeService(Audience::excludeOff(DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->whereNull('u.deleted_at')
            ->where(function ($w) use ($agencyId, $centreIds, $s) {
                if ($s['directors'] && $centreIds) $w->orWhere(fn ($x) => $x->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $centreIds));
                if ($s['admins']) $w->orWhere(fn ($x) => $x->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId));
            }), 'u'));

        return $q->get(['u.id', 'u.first_name', 'u.email'])->unique('id')->keyBy('id')->all();
    }

    /** Send today's alerts for every agency (or only work it out, $dry). Returns counts + the plan. */
    public static function run(bool $dry = false, ?int $onlyAgency = null): array
    {
        $report = [];
        $agencies = DB::table('agencies')->whereNull('deleted_at')->when($onlyAgency, fn ($q) => $q->where('id', $onlyAgency))->get(['id', 'name']);
        foreach ($agencies as $a) {
            $aid = (int) $a->id;
            $s = self::settings($aid);
            $items = self::plan($aid, $s);
            if (! $items) continue;
            $byManager = [];
            $holderBells = 0; $mail = 0;
            foreach ($items as $it) {
                if ($s['holder']) {
                    if (! $dry) {
                        Notify::write(['agency_id' => $aid, 'user_id' => $it['user_id'], 'type' => 'compliance',
                            'title' => '🎓 Your ' . $it['label'] . ' certificate ' . ($it['days'] < 0 ? 'has expired' : ($it['days'] === 0 ? 'expires today' : 'expires in ' . $it['days'] . ' day' . ($it['days'] === 1 ? '' : 's'))),
                            'body' => ucfirst($it['phrase']) . '. When you renew it, add the new certificate under My certificates.',
                            'data' => json_encode(['hash' => 'my-certificates', 'cert_id' => $it['cert_id']]), 'created_at' => now()]);
                        if ($s['email'] && filter_var($it['email'], FILTER_VALIDATE_EMAIL)) $mail += self::mailHolder($aid, (string) $a->name, $it);
                    }
                    $holderBells++;
                }
                foreach (self::managers($aid, $it['centre_ids'], $s) as $m) {
                    if ((int) $m->id === $it['user_id']) continue;   // their own certificate came to them as the holder
                    $byManager[(int) $m->id] = $byManager[(int) $m->id] ?? ['m' => $m, 'items' => []];
                    $byManager[(int) $m->id]['items'][] = $it;
                }
            }
            foreach ($byManager as $mid => $g) {
                if ($dry) continue;
                $n = count($g['items']);
                Notify::write(['agency_id' => $aid, 'user_id' => $mid, 'type' => 'compliance',
                    'title' => '🎓 ' . $n . ' staff certificate' . ($n === 1 ? '' : 's') . ' need' . ($n === 1 ? 's' : '') . ' attention',
                    'body' => implode(' · ', array_map(fn ($i) => $i['name'] . ': ' . $i['label'] . ' ' . $i['phrase'], array_slice($g['items'], 0, 3))) . ($n > 3 ? ' · and ' . ($n - 3) . ' more' : ''),
                    'data' => json_encode(['hash' => 'certifications']), 'created_at' => now()]);
                if ($s['email'] && filter_var((string) $g['m']->email, FILTER_VALIDATE_EMAIL)) $mail += self::mailManager($aid, (string) $a->name, $g['m'], $g['items']);
            }
            if (! $dry && Schema::hasTable('cert_alert_log')) {
                foreach ($items as $it) {
                    DB::table('cert_alert_log')->insertOrIgnore(['cert_id' => $it['cert_id'], 'agency_id' => $aid, 'step_key' => $it['key'], 'sent_at' => now()]);
                }
            }
            $report[] = ['agency_id' => $aid, 'agency' => $a->name, 'certificates' => count($items), 'holder_bells' => $holderBells,
                'managers' => count($byManager), 'emails' => $mail, 'items' => $items,
                'manager_ids' => array_keys($byManager)];
        }

        return $report;
    }

    private static function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

    private static function mailHolder(int $aid, string $agency, array $it): int
    {
        $html = \App\Services\EmailTemplate::wrap($aid,
            '<p style="margin:0 0 12px;font-size:15px;color:#334155">Hi ' . self::e($it['first_name'] ?: 'there') . ',</p>'
            . '<p style="margin:0 0 12px;font-size:15px;color:#334155">Your <b>' . self::e($it['label']) . '</b> certificate ' . self::e($it['phrase']) . '.</p>'
            . '<p style="margin:0;font-size:14px;color:#334155">When you renew it, add the new certificate in KiddieTrac under <b>My certificates</b> so your centre has it on file.</p>',
            ['eyebrow' => 'CERTIFICATE REMINDER', 'title' => $it['label'] . ' certificate', 'subtitle' => $agency]);
        $subject = 'Your ' . $it['label'] . ' certificate ' . ($it['days'] < 0 ? 'has expired' : ($it['days'] === 0 ? 'expires today' : 'expires in ' . $it['days'] . ' day' . ($it['days'] === 1 ? '' : 's')));
        try { \App\Services\AgencyMailer::forAgency($aid)->html($html, fn ($m) => $m->to($it['email'])->subject($subject)); return 1; }
        catch (\Throwable $e) { report($e); return 0; }
    }

    private static function mailManager(int $aid, string $agency, $m, array $items): int
    {
        $rows = '';
        foreach ($items as $i) {
            $col = $i['days'] < 0 ? '#B91C1C' : ($i['days'] <= 14 ? '#B45309' : '#334155');
            $rows .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #E2E8F0;font-size:14px;color:#334155">' . self::e($i['name']) . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #E2E8F0;font-size:14px;color:#334155">' . self::e($i['label']) . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #E2E8F0;font-size:14px;color:' . $col . '">' . self::e(ucfirst($i['phrase'])) . '</td></tr>';
        }
        $n = count($items);
        $html = \App\Services\EmailTemplate::wrap($aid,
            '<p style="margin:0 0 12px;font-size:15px;color:#334155">Hi ' . self::e($m->first_name ?: 'there') . ',</p>'
            . '<p style="margin:0 0 12px;font-size:15px;color:#334155">' . ($n === 1 ? 'A staff certificate needs' : $n . ' staff certificates need') . ' attention:</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 14px">' . $rows . '</table>'
            . '<p style="margin:0;font-size:14px;color:#334155">The full list is in KiddieTrac → Certifications. Staff have been reminded too.</p>',
            ['eyebrow' => 'STAFF CERTIFICATES', 'title' => ($n === 1 ? '1 certificate' : $n . ' certificates') . ' to renew', 'subtitle' => $agency]);
        $subject = $n === 1 ? 'Staff certificate: ' . $items[0]['name'] . ' - ' . $items[0]['label'] . ' ' . ($items[0]['days'] < 0 ? 'has expired' : 'expiring') : $n . ' staff certificates expiring or expired';
        try { \App\Services\AgencyMailer::forAgency($aid)->html($html, fn ($x) => $x->to((string) $m->email)->subject($subject)); return 1; }
        catch (\Throwable $e) { report($e); return 0; }
    }
}
