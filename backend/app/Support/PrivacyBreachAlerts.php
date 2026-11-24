<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\EmailTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * TELL THE OFFICE ABOUT A PRIVACY BREACH (2026-11-06). Anthony: "privacy breaches section wire up email notifications
 * to admins and directors - full sweep". He chose:
 *   - who: the agency's admins, and the directors of the breach's centre (every director when it is agency-wide);
 *     directors can OPEN those records, read-only (PrivacyBreachController), so the link in the email works for them;
 *   - when: a breach is recorded; the real-risk-of-significant-harm decision becomes Yes (reporting is now due);
 *     it is reported to the Commissioner; it is closed; and a daily 8 AM digest of overdue breaches
 *     (privacy:breach-reminders);
 *   - what: a SUMMARY only - reference, title, centre, dates, status, what is still to do, and a link. Never what
 *     happened, who received it or the information involved: email is less protected than the portal.
 * Each alert is an email plus a bell row, which also rings their phone (Notify::write pushes every row).
 * The person who made the change is not told about their own change.
 *
 * PLATFORM ADMINS (2026-11-13, Anthony: "privacy breach notifications should be turnt off for agencies and only for
 * platform admin" - then "add configuration open to choose who gets alerted, right now turn off admins/directors").
 * The 'platform' switch (on by default, changed by platform admins only) alerts every platform admin about every
 * agency's breaches; the agency's admins and directors are now OFF by default and an agency can still switch them on.
 *
 * TESTS: set PrivacyBreachAlerts::$redirect = ['user_id' => .., 'email' => ..] and everything goes to that one person
 * only, subject "[TEST]"; with 'hold' => true the emails wait in $outbox (send them after a rolled-back test).
 */
final class PrivacyBreachAlerts
{
    /**
     * agencies.settings.privacy_breach_alerts (2026-11-06, Anthony: "wire up settings for the breach to allow who gets
     * emails, notifications etc"). Set by agency admins on Privacy breaches -> Alert settings.
     *   admins / directors  - the role-wide switches (directors are still only told about their own centres)
     *   muted               - admins/directors left out by name
     *   extra_emails        - up to 5 more addresses (a privacy officer or outside advisor): the summary email only,
     *                         no bell and no link, since they cannot open the register
     *   email / bell        - the channels (bell = the in-app row + the phone push)
     *   events              - which moments alert; reminders + reminder_hour + overdue_days drive the daily digest
     */
    public const DEFAULTS = [
        'platform' => true,
        'admins' => false,
        'directors' => false,
        'muted' => [],
        'extra_emails' => [],
        'email' => true,
        'bell' => true,
        'events' => ['recorded' => true, 'risk' => true, 'reported' => true, 'closed' => true],
        'reminders' => true,
        'reminder_hour' => 8,
        'overdue_days' => 7,
    ];

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;

        return self::clean(array_replace_recursive(self::DEFAULTS, (array) ($all['privacy_breach_alerts'] ?? [])));
    }

    public static function save(int $agencyId, array $in): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $cur = self::settings($agencyId);
        // Lists are replaced, not merged by index.
        foreach (['muted', 'extra_emails'] as $k) if (array_key_exists($k, $in)) $cur[$k] = [];
        $all['privacy_breach_alerts'] = self::clean(array_replace_recursive($cur, $in));
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all), 'updated_at' => now()]);

        return self::settings($agencyId);
    }

    private static function clean(array $s): array
    {
        $out = [];
        foreach (['platform', 'admins', 'directors', 'email', 'bell', 'reminders'] as $k) $out[$k] = (bool) $s[$k];
        foreach (array_keys(self::DEFAULTS['events']) as $k) $out['events'][$k] = (bool) ($s['events'][$k] ?? true);
        $out['muted'] = array_values(array_unique(array_filter(array_map('intval', (array) $s['muted']))));
        $mails = array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) $s['extra_emails']);
        $out['extra_emails'] = array_slice(array_values(array_unique(array_filter($mails, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)))), 0, 5);
        $out['reminder_hour'] = max(0, min(23, (int) $s['reminder_hour']));
        $out['overdue_days'] = max(1, min(30, (int) $s['overdue_days']));

        return $out;
    }

    /** Everyone who CAN get alerts (the settings list): [['user_id','name','role','centres']] - before switches and mutes. */
    public static function eligible(int $agencyId): array
    {
        $names = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id')->all();
        $out = [];
        foreach (self::rows($agencyId) as $r) {
            $id = (int) $r->id;
            $out[$id] = $out[$id] ?? ['user_id' => $id, 'name' => trim($r->first_name . ' ' . mb_substr((string) $r->last_name, 0, 1) . ($r->last_name ? '.' : '')),
                'admin' => false, 'centres' => []];
            if (in_array((string) $r->role, self::ADMIN, true)) $out[$id]['admin'] = true;
            elseif ($r->centre_id) $out[$id]['centres'][] = (string) ($names[$r->centre_id] ?? 'A centre');
            else $out[$id]['centres'][] = 'All centres';
        }
        uasort($out, fn ($a, $b) => [$b['admin'], $a['name']] <=> [$a['admin'], $b['name']]);

        return array_values($out);
    }

    /** The admin and director role rows of real, active people. */
    private static function rows(int $agencyId)
    {
        $rows = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.agency_id', $agencyId)->where('r.active', 1)
            ->whereIn('r.role', array_merge(self::ADMIN, self::DIRECTOR))
            ->whereNull('u.deleted_at')
            ->whereNotIn('u.status', ['invited', 'not_invited', 'deactivated', 'suspended'])
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'r.role', 'r.centre_id'])
            // Service accounts (the iLearn integration login and the like) are not people.
            ->reject(fn ($r) => preg_match('/^(integration|noreply|no-reply|system|api)[+@]/i', (string) $r->email));
        if (PublicDemo::isAgency($agencyId)) $rows = $rows->filter(fn ($r) => PublicDemo::isUser((int) $r->id));

        return $rows;
    }

    public static ?array $redirect = null;
    public static array $log = [];
    public static array $outbox = [];

    // Exactly the roles PrivacyBreachController lets in: whoever is emailed can open the record.
    private const ADMIN = ['agency_admin'];
    private const DIRECTOR = ['centre_director'];

    /**
     * Admins of the agency plus the directors of this centre ($centreId null = an agency-wide breach: every director).
     * [['user_id','email','first_name','centre_ids' => int[]|null]] - centre_ids null for an agency-wide person.
     */
    public static function people(int $agencyId, ?int $centreId, ?array $s = null): array
    {
        $s = $s ?? self::settings($agencyId);
        if (self::$redirect) {
            return [['user_id' => (int) self::$redirect['user_id'], 'email' => (string) self::$redirect['email'],
                'first_name' => (string) (self::$redirect['first_name'] ?? 'there'), 'centre_ids' => null]];
        }
        $out = [];
        foreach (self::rows($agencyId) as $r) {
            $id = (int) $r->id;
            $isAdmin = in_array((string) $r->role, self::ADMIN, true);
            // The switches act per ROLE: someone who is both keeps the role still switched on.
            if ($isAdmin ? ! $s['admins'] : ! $s['directors']) continue;
            if (in_array($id, $s['muted'], true)) continue;
            $wide = $isAdmin || $r->centre_id === null;
            $out[$id] = $out[$id] ?? ['user_id' => $id, 'email' => mb_strtolower(trim((string) $r->email)), 'first_name' => (string) $r->first_name, 'centre_ids' => []];
            if ($wide) $out[$id]['centre_ids'] = null;
            elseif ($out[$id]['centre_ids'] !== null) $out[$id]['centre_ids'][] = (int) $r->centre_id;
        }
        // A director hears about their own centres and agency-wide breaches only.
        $out = array_filter($out, fn ($p) => self::sees($p, $centreId));
        // Platform admins: every breach of every agency (the switch, not the mute list - they are not the agency's people).
        if ($s['platform']) {
            foreach (DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.role', 'platform_admin')->where('r.active', 1)->whereNull('u.deleted_at')
                ->whereNotIn('u.status', ['invited', 'not_invited', 'deactivated', 'suspended'])
                ->get(['u.id', 'u.first_name', 'u.email']) as $r) {
                $id = (int) $r->id;
                if (isset($out[$id])) { $out[$id]['platform'] = true; continue; }
                $out[$id] = ['user_id' => $id, 'email' => mb_strtolower(trim((string) $r->email)), 'first_name' => (string) $r->first_name,
                    'centre_ids' => null, 'platform' => true];
            }
        }

        return array_values($out);
    }

    /** The extra addresses as people: summary email only (no bell, no link), every breach. */
    private static function extras(array $s): array
    {
        if (self::$redirect) return [];

        return array_map(fn ($e) => ['user_id' => 0, 'email' => $e, 'first_name' => '', 'centre_ids' => null, 'extra' => true], $s['extra_emails']);
    }

    /** Can this person see a breach at this centre? Mirrors PrivacyBreachController's read rule. */
    public static function sees(array $person, ?int $centreId): bool
    {
        return $person['centre_ids'] === null || $centreId === null || in_array($centreId, $person['centre_ids'], true);
    }

    /** A breach was just recorded. */
    public static function recorded(int $breachId, int $actorId): void
    {
        $aid = (int) DB::table('privacy_breaches')->where('id', $breachId)->value('agency_id');
        if (! $aid || (! self::$redirect && ! self::settings($aid)['events']['recorded'])) return;
        self::event($breachId, $actorId, '🔓 Privacy breach recorded', 'A privacy breach was recorded', 'was recorded', 'NEW PRIVACY BREACH');
    }

    /**
     * After an update: what changed that people must hear about. $before is the row as it was.
     * Several at once (decided Yes and reported in one save) is ONE email naming them all.
     */
    public static function changed(object $before, int $actorId): void
    {
        try {
            $after = DB::table('privacy_breaches')->where('id', $before->id)->first();
            if (! $after) return;
            $what = [];
            if ($after->rrosh === 'yes' && $before->rrosh !== 'yes') $what[] = 'risk';
            if (($after->opc_reported_on && ! $before->opc_reported_on) || ($after->status === 'reported' && $before->status !== 'reported')) $what[] = 'reported';
            if ($after->status === 'closed' && $before->status !== 'closed') $what[] = 'closed';
            $on = self::settings((int) $after->agency_id)['events'];
            if (! self::$redirect) $what = array_values(array_filter($what, fn ($w) => $on[$w]));
            if (! $what) return;
            $lead = $what[0];
            $title = ['risk' => '⚠️ Privacy breach: real risk of significant harm', 'reported' => '📨 Privacy breach reported to the Commissioner',
                'closed' => '✅ Privacy breach closed'][$lead];
            $said = ['risk' => 'was assessed as a real risk of significant harm, so it must be reported to the Privacy Commissioner and the people affected notified as soon as feasible',
                'reported' => 'was reported to the Privacy Commissioner', 'closed' => 'was closed'];
            $sentence = implode('; it also ', array_map(fn ($w) => $said[$w], $what));
            $eyebrow = ['risk' => 'ACTION NEEDED', 'reported' => 'PRIVACY BREACH UPDATE', 'closed' => 'PRIVACY BREACH UPDATE'][$lead];
            $subject = ['risk' => 'Action needed - privacy breach has a real risk of harm', 'reported' => 'Privacy breach reported to the Commissioner',
                'closed' => 'Privacy breach closed'][$lead];
            self::event((int) $before->id, $actorId, $title, $subject, $sentence, $eyebrow);
        } catch (\Throwable $e) {
            Log::warning('privacy breach change alert failed', ['breach' => $before->id ?? null, 'msg' => $e->getMessage()]);
        }
    }

    private static function event(int $breachId, int $actorId, string $title, string $subjectLead, string $sentence, string $eyebrow): void
    {
        try {
            $b = DB::table('privacy_breaches')->where('id', $breachId)->first();
            if (! $b) return;
            $aid = (int) $b->agency_id;
            if (! self::$redirect && ! Suppression::agencyNotificationsEnabled($aid)) return;
            $s = self::settings($aid);
            $centreId = $b->centre_id ? (int) $b->centre_id : null;
            $centre = $centreId ? (string) DB::table('centres')->where('id', $centreId)->value('name') : null;
            $link = '#privacy-breaches?id=' . $b->id;
            $todo = self::toDo($b);
            $body = $b->ref . ' · ' . $b->title . ($todo ? ' · Still to do: ' . implode('; ', $todo) : '');
            $rows = [];
            $people = array_values(array_filter(self::people($aid, $centreId, $s), fn ($p) => self::$redirect || $p['user_id'] !== $actorId));
            $agencyName = (string) DB::table('agencies')->where('id', $aid)->value('name');
            if ($s['bell'] || self::$redirect) {
                foreach ($people as $p) {
                    $rows[] = ['user_id' => $p['user_id'], 'type' => 'compliance', 'title' => $title,
                        'body' => mb_substr((! empty($p['platform']) ? $agencyName . ' · ' : '') . $body, 0, 480),
                        'data' => json_encode(['link' => $link, 'kind' => 'privacy_breach', 'breach_id' => (int) $b->id, 'agency_id' => $aid]), 'created_at' => now()];
                }
            }
            if ($rows && (! self::$redirect || (int) self::$redirect['user_id'] > 0)) Notify::write($rows);
            $subject = (self::$redirect ? '[TEST] ' : '') . $subjectLead . ': ' . $b->ref . ($centre ? ' · ' . $centre : '');
            foreach (($s['email'] || self::$redirect) ? array_merge($people, self::extras($s)) : [] as $p) {
                if ($p['email'] === '' || ! filter_var($p['email'], FILTER_VALIDATE_EMAIL)) continue;
                $intro = 'Privacy breach <b>' . self::e($b->ref) . '</b> ' . self::e($sentence) . '.';
                $where = $centre ? $centre : 'Agency-wide';
                self::mail($aid, $p['email'], (! empty($p['platform']) ? $agencyName . ' - ' : '') . $subject,
                    self::html($aid, $p, $intro, [$b], $eyebrow, $title, ! empty($p['platform']) ? $agencyName . ' · ' . $where : $where));
            }
            self::$log[] = ['title' => $title, 'subject' => $subject, 'to' => array_column($people, 'user_id')];
        } catch (\Throwable $e) {
            Log::warning('privacy breach alert failed', ['breach' => $breachId, 'msg' => $e->getMessage()]);
        }
    }

    /* ── THE DAILY DIGEST ───────────────────────────────────────────────────────────────────────────────────── */

    /** Breaches that are overdue today: a risk decision 7+ days late, or a Yes not yet reported / notified. */
    public static function overdue(int $agencyId, ?int $days = null): array
    {
        $cut = \Carbon\Carbon::parse(AgencyTime::today($agencyId))->subDays($days ?? self::settings($agencyId)['overdue_days'])->toDateString();

        return DB::table('privacy_breaches')->where('agency_id', $agencyId)
            ->where(function ($w) use ($cut) {
                $w->where(fn ($x) => $x->where('status', '!=', 'closed')->where('rrosh', 'undetermined')->where('discovered_on', '<=', $cut))
                  ->orWhere(fn ($x) => $x->where('rrosh', 'yes')->where(fn ($y) => $y->whereNull('opc_reported_on')->orWhereNull('individuals_notified_on')));
            })->orderBy('discovered_on')->get()->all();
    }

    /** One digest email + bell per person for one agency. $dry builds without sending. Returns [['to','subject','count']]. */
    public static function digest(int $agencyId, bool $dry = false): array
    {
        if (! self::$redirect && ! Suppression::agencyNotificationsEnabled($agencyId)) return [];
        $s = self::settings($agencyId);
        if (! $s['reminders'] && ! self::$redirect) return [];
        $due = self::overdue($agencyId, $s['overdue_days']);
        if (! $due) return [];
        $names = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id')->all();
        $sent = [];
        foreach (array_merge(self::people($agencyId, null, $s), self::extras($s)) as $p) {
            $mine = array_values(array_filter($due, fn ($b) => self::sees($p, $b->centre_id ? (int) $b->centre_id : null)));
            if (! $mine) continue;
            $extra = ! empty($p['extra']);
            if ($extra && ! $s['email']) continue;
            $n = count($mine);
            $title = '🔓 ' . $n . ' privacy breach' . ($n === 1 ? ' needs' : 'es need') . ' action';
            $subject = (self::$redirect ? '[TEST] ' : '') . (! empty($p['platform']) ? DB::table('agencies')->where('id', $agencyId)->value('name') . ' - ' : '')
                . ($n === 1 ? 'Privacy breach ' . $mine[0]->ref . ' needs action' : $n . ' privacy breaches need action');
            if (! $dry) {
                if (! $extra && ($s['bell'] || self::$redirect) && (! self::$redirect || (int) self::$redirect['user_id'] > 0)) {
                    Notify::write(['user_id' => $p['user_id'], 'type' => 'compliance', 'title' => $title,
                        'body' => mb_substr(implode(' · ', array_map(fn ($b) => $b->ref . ': ' . implode('; ', self::toDo($b)), $mine)), 0, 480),
                        'data' => json_encode(['link' => '#privacy-breaches', 'kind' => 'privacy_breach_digest']), 'created_at' => now()]);
                }
                if (($s['email'] || self::$redirect) && $p['email'] !== '' && filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                    $intro = $n === 1 ? 'This privacy breach still has steps outstanding:' : 'These privacy breaches still have steps outstanding:';
                    $intro .= ' PIPEDA expects a decision on the risk of harm promptly, and reporting and notification as soon as feasible when the risk is real.';
                    self::mail($agencyId, $p['email'], $subject, self::html($agencyId, $p, self::e($intro), $mine, 'PRIVACY BREACHES', $title, null, $names));
                }
            }
            $sent[] = ['to' => $p['email'], 'subject' => $subject, 'count' => $n];
        }

        return $sent;
    }

    /* ── helpers ───────────────────────────────────────────────────────────────────────────────────────────── */

    /** What still has to happen, in words (same rules as the register's "Still to do"). */
    public static function toDo(object $b): array
    {
        $due = [];
        if ($b->status !== 'closed' && $b->rrosh === 'undetermined') $due[] = 'Decide whether there is a real risk of significant harm';
        if ($b->rrosh === 'yes' && ! $b->opc_reported_on) $due[] = 'Report to the Privacy Commissioner';
        if ($b->rrosh === 'yes' && ! $b->individuals_notified_on) $due[] = 'Notify the people affected';
        if ($b->status !== 'closed' && ! $b->contained_on) $due[] = 'Record when it was contained';

        return $due;
    }

    private static function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

    private static function day(?string $ymd): string
    {
        return $ymd ? \Carbon\Carbon::parse(substr($ymd, 0, 10))->format('M j, Y') : '—';
    }

    /** The email: greeting, one sentence, a card per breach (summary fields only), the button. */
    private static function html(int $aid, array $p, string $intro, array $breaches, string $eyebrow, string $title, ?string $subtitle, ?array $names = null): string
    {
        $names = $names ?? DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id')->all();
        $status = ['open' => 'Open', 'assessing' => 'Assessing', 'reported' => 'Reported', 'closed' => 'Closed'];
        $risk = ['undetermined' => 'Not decided yet', 'yes' => 'Yes', 'no' => 'No'];
        $cards = '';
        foreach ($breaches as $b) {
            $todo = self::toDo($b);
            $line = fn ($k, $v) => '<tr><td class="kt-muted" style="padding:3px 12px 3px 0;font-size:13px;color:#64748B;white-space:nowrap;vertical-align:top;">' . $k . '</td>'
                . '<td style="padding:3px 0;font-size:13.5px;color:#0F172A;vertical-align:top;">' . $v . '</td></tr>';
            $cards .= '<table role="presentation" class="kt-panel" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:separate;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;margin:0 0 12px;">'
                . '<tr><td style="padding:14px 16px;">'
                . '<div style="font-size:15px;font-weight:800;color:#0F172A;">' . self::e($b->ref) . ' · ' . self::e($b->title) . '</div>'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;border-collapse:collapse;">'
                . $line('Centre', self::e($b->centre_id ? ($names[$b->centre_id] ?? 'A centre') : 'Agency-wide'))
                . $line('Discovered', self::e(self::day($b->discovered_on)))
                . $line('Status', self::e($status[$b->status] ?? $b->status))
                . $line('Real risk of harm', self::e($risk[$b->rrosh] ?? $b->rrosh))
                . $line('Still to do', $todo ? '<b style="color:#B45309;">' . implode('<br>', array_map([self::class, 'e'], $todo)) . '</b>' : 'Nothing outstanding')
                . '</table></td></tr></table>';
        }
        $url = 'https://app.kiddietrac.com/dashboard.html#privacy-breaches' . (count($breaches) === 1 ? '?id=' . (int) $breaches[0]->id : '');
        $extra = ! empty($p['extra']);
        $bodyHtml = '<p style="margin:0 0 12px;font-size:15px;color:#334155">Hi ' . self::e($p['first_name'] ?: 'there') . ',</p>'
            . '<p style="margin:0 0 14px;font-size:15px;color:#334155">' . $intro . '</p>'
            . $cards
            // An extra address (privacy officer, advisor) cannot open the register, so no button that leads to a sign-in.
            . ($extra ? '' : '<p style="margin:4px 0 14px;">' . EmailTemplate::button('Open Privacy breaches', $url) . '</p>')
            . '<p class="kt-muted" style="margin:0;font-size:12.5px;color:#64748B">This is a summary only. What happened and who was involved stay in KiddieTrac, where only admins and the centre\'s directors can open them.'
            . ($extra ? ' You get these because an admin added your address to the privacy breach alerts; ask them for the full record.' : '') . '</p>';

        return EmailTemplate::wrap($aid, $bodyHtml, ['eyebrow' => $eyebrow, 'title' => preg_replace('/^\W+\s*/u', '', $title),
            'subtitle' => $subtitle ?? (string) DB::table('agencies')->where('id', $aid)->value('name'), 'preheader' => strip_tags($intro)]);
    }

    private static function mail(int $agencyId, string $to, string $subject, string $html): void
    {
        $build = function ($m) use ($to, $subject) { $m->to($to)->subject($subject); };
        try {
            if (self::$redirect) {
                if (! empty(self::$redirect['hold'])) { self::$outbox[] = [$to, $subject, $html]; return; }
                Mail::html($html, function ($m) use ($build) { MailScope::platform($m); $build($m); });
                return;
            }
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, $build);
        } catch (\Throwable $e) {
            Log::warning('privacy breach email failed', ['to' => $to, 'msg' => $e->getMessage()]);
        }
    }
}
