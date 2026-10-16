<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WHO IS TOLD WHEN THE NIGHTLY SIGN-OFF CLOSES SOMETHING (2026-10-15).
 *
 * Anthony, about Bruni (auto clocked out three evenings running): "was there a warning
 * email sent out to admin/director and educator ... the same email about children not
 * clocked out and auto clocked out and the impacts to families/parents and compliance".
 * There was not: kiddietrac:auto-signoff closed the records and told nobody; admins only
 * saw it in the next morning's daily summary. His decisions:
 *
 *   - A STAFF auto clock-out: the person (bell + push + email: what happened, why it
 *     matters, how to avoid it) and, the same evening, the centre's directors and the
 *     agency admins (bell + email).
 *   - The SAME person auto clocked out 3+ times in 7 days: an escalation to the directors
 *     and admins listing the dates, once a day at most.
 *   - A CHILD auto signed out: one summary per centre to its educators, home visitors and
 *     directors and the agency admins, AND each family's parents (bell + email) asking
 *     them to confirm the real pick-up time.
 *
 * Everything goes through AgencyMailer and Notify, so the agency's email switches, the
 * suppression list and switched-off accounts all still apply. A failure here never
 * undoes or stops the sign-off itself.
 */
final class AutoSignOffNotices
{
    private const REPEAT_COUNT = 3;
    private const REPEAT_DAYS = 7;

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** Live people with these roles AT the centre, plus the agency admins. */
    private static function staff(int $agencyId, int $centreId, array $centreRoles, ?int $except = null)
    {
        $q = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->whereNull('u.deleted_at')
            ->where(function ($w) use ($agencyId, $centreId, $centreRoles) {
                $w->where(fn ($x) => $x->where('ra.centre_id', $centreId)->whereIn('ra.role', $centreRoles))
                  ->orWhere(fn ($x) => $x->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin'));
            });
        $q = Audience::excludeService(Audience::excludeOff($q));
        if ($except) $q->where('u.id', '!=', $except);

        return $q->get(['u.id', 'u.email', 'u.first_name'])->unique('id')->values();
    }

    private static function send(int $agencyId, $people, string $subject, string $html, string $bellTitle, string $bellBody, string $link, string $type): void
    {
        $rows = [];
        foreach ($people as $p) {
            $rows[] = ['user_id' => (int) $p->id, 'type' => $type, 'title' => $bellTitle, 'body' => mb_substr($bellBody, 0, 1000),
                'data' => json_encode(['link' => $link])];
        }
        if ($rows) Notify::write($rows);
        $done = [];
        foreach ($people as $p) {
            $em = mb_strtolower(trim((string) $p->email));
            if (! filter_var($em, FILTER_VALIDATE_EMAIL) || isset($done[$em])) continue;
            $done[$em] = true;
            try { AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to($em)->subject($subject)); }
            catch (\Throwable $ex) { Log::warning('Auto sign-off notice failed', ['to' => $em, 'error' => $ex->getMessage()]); }
        }
    }

    private static function whyStaff(): string
    {
        return '<p style="margin:14px 0 4px;font-size:13.5px;font-weight:700;color:#334155">Why it matters</p>'
            . '<ul style="margin:0 0 0 18px;padding:0;font-size:14px;color:#334155;line-height:1.6">'
            . '<li><strong>Ratios and licensing</strong> — the record shows an educator on the floor until the automatic time, after they had gone home. An inspector reads that record.</li>'
            . '<li><strong>Payroll</strong> — the hours are a placeholder, not what was worked, until somebody corrects them.</li>'
            . '<li><strong>Reports and funding</strong> — staff hours feed attendance, CWELCC and funding reports.</li></ul>'
            . '<p style="margin:14px 0 4px;font-size:13.5px;font-weight:700;color:#334155">How to avoid it</p>'
            . '<p style="margin:0;font-size:14px;color:#334155;line-height:1.6">Clock out before you leave — the Clock in/out button in the KiddieTrac app. You will get a "still clocked in" reminder in the evening if you forget.</p>';
    }

    /** A staff punch closed automatically. */
    public static function staffClosed(int $agencyId, int $punchId, int $userId, int $centreId, Carbon $in, Carbon $out): void
    {
        try {
            $u = DB::table('users')->where('id', $userId)->first(['id', 'email', 'first_name', 'last_name']);
            if (! $u) return;
            $name = trim($u->first_name . ' ' . $u->last_name);
            $centre = (string) DB::table('centres')->where('id', $centreId)->value('name');
            $day = $in->format('l j F');
            $box = EmailTemplate::calloutBox('<strong>Clocked in:</strong> ' . self::e($in->format('g:i A, l j F'))
                . '<br><strong>Clocked out automatically:</strong> ' . self::e($out->format('g:i A'))
                . '<br><strong>Centre:</strong> ' . self::e($centre), 'warning');

            // 1. The person.
            $html = EmailTemplate::wrap($agencyId, '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">Hi ' . self::e($u->first_name ?: 'there')
                . ', no clock-out was recorded for your shift on <b>' . self::e($day) . '</b>, so KiddieTrac closed it automatically at <b>'
                . self::e($out->format('g:i A')) . '</b>. That time is a placeholder, not when you left.</p>' . $box
                . '<p style="margin:14px 0 0;font-size:14px;color:#334155;line-height:1.6"><b>Please tell your director or administrator the time you actually left</b> so they can correct it.</p>'
                . self::whyStaff(),
                ['eyebrow' => 'TIME CLOCK', 'title' => 'You were clocked out automatically', 'subtitle' => $day, 'preheader' => 'No clock-out was recorded on ' . $day . '.']);
            self::send($agencyId, collect([$u]), 'You were clocked out automatically — ' . $day, $html,
                'You were clocked out automatically', 'No clock-out on ' . $day . ' — closed at ' . $out->format('g:i A') . '. Tell your director when you really left.', '#time-clock', 'clock_reminder');
            try { app(\App\Services\FcmService::class)->sendToUser($userId, 'You were clocked out automatically', 'No clock-out on ' . $day . '. Tell your director when you really left.', '#time-clock'); } catch (\Throwable $e) {}

            // 2. The directors and admins, the same evening.
            $managers = self::staff($agencyId, $centreId, ['centre_director'], $userId);
            $mhtml = EmailTemplate::wrap($agencyId, '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6"><b>' . self::e($name)
                . '</b> did not clock out on ' . self::e($day) . '. KiddieTrac closed the shift automatically at ' . self::e($out->format('g:i A')) . '.</p>' . $box
                . '<p style="margin:14px 0 0;font-size:14px;color:#334155;line-height:1.6">Correct the out time in <b>Timesheets</b> once you know when they left. ' . self::e($u->first_name) . ' has been told the same.</p>'
                . self::whyStaff(),
                ['eyebrow' => 'TIME CLOCK', 'title' => 'Auto clock-out: ' . $name, 'subtitle' => $centre . ' · ' . $day]);
            self::send($agencyId, $managers, 'Auto clock-out: ' . $name . ' — ' . $day, $mhtml,
                'Auto clock-out — ' . $name, 'No clock-out on ' . $day . ' at ' . $centre . '; closed at ' . $out->format('g:i A') . '. Correct it in Timesheets.', '#timesheets', 'clock');

            // 3. A habit, not a slip: escalate once a day.
            $dates = DB::table('time_punches')->where('user_id', $userId)->where('source', 'auto')
                ->where('punched_in_at', '>=', now()->subDays(self::REPEAT_DAYS))->orderBy('punched_in_at')->pluck('punched_in_at');
            if ($dates->count() >= self::REPEAT_COUNT && Cache::add('kt-autoclock-esc:' . $userId . ':' . now()->toDateString(), 1, now()->addDay())) {
                $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
                $list = $dates->map(fn ($d) => Carbon::parse($d, 'UTC')->setTimezone($tz)->format('D j M'))->implode(', ');
                $ehtml = EmailTemplate::wrap($agencyId, '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6"><b>' . self::e($name) . '</b> has been clocked out automatically <b>'
                    . $dates->count() . ' times in the last ' . self::REPEAT_DAYS . ' days</b>: ' . self::e($list) . '.</p>'
                    . EmailTemplate::calloutBox('Each of those days shows them on the floor until the automatic time, and their hours are placeholders until corrected. The daily reminders have not changed the habit — this is worth a conversation.', 'warning')
                    . '<p style="margin:14px 0 0;font-size:14px;color:#334155">Correct the times in <b>Timesheets</b>.</p>',
                    ['eyebrow' => 'TIME CLOCK', 'title' => 'Repeated missed clock-outs', 'subtitle' => $name . ' · ' . $centre]);
                self::send($agencyId, $managers, 'Repeated missed clock-outs: ' . $name . ' (' . $dates->count() . ' in ' . self::REPEAT_DAYS . ' days)', $ehtml,
                    'Repeated missed clock-outs — ' . $name, $dates->count() . ' automatic clock-outs in ' . self::REPEAT_DAYS . ' days: ' . $list . '.', '#timesheets', 'clock');
            }
        } catch (\Throwable $e) {
            Log::warning('Auto sign-off staff notice failed', ['punch' => $punchId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Children signed out automatically in this run, for one centre:
     * [['child_id'=>, 'family_id'=>, 'name'=>, 'in'=>Carbon local, 'out'=>Carbon local], ...]
     */
    public static function childrenClosed(int $agencyId, int $centreId, array $kids): void
    {
        if (! $kids || ! in_array($centreId, Live::centreIds($agencyId), true)) return;
        try {
            $c = DB::table('centres')->where('id', $centreId)->first(['name', 'phone', 'email']);
            $centre = (string) ($c->name ?? '');

            // 1. Staff and admins: one summary for the centre.
            $rows = '';
            foreach ($kids as $k) {
                $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #E2E8F0;font-size:14px">' . self::e($k['name']) . '</td>'
                    . '<td style="padding:6px 8px;border-bottom:1px solid #E2E8F0;font-size:14px">' . self::e($k['in']->format('D g:i A')) . '</td>'
                    . '<td style="padding:6px 8px;border-bottom:1px solid #E2E8F0;font-size:14px;color:#B45309"><b>' . self::e($k['out']->format('g:i A')) . '</b> (automatic)</td></tr>';
            }
            $n = count($kids);
            $html = EmailTemplate::wrap($agencyId, '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">'
                . ($n === 1 ? 'One child was' : $n . ' children were') . ' never signed out at <b>' . self::e($centre) . '</b>, so KiddieTrac signed '
                . ($n === 1 ? 'them' : 'them') . ' out automatically. The time shown is a placeholder, not the real pick-up.</p>'
                . '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 6px"><tr>'
                . '<th align="left" style="padding:6px 8px;font-size:12px;color:#64748B">Child</th><th align="left" style="padding:6px 8px;font-size:12px;color:#64748B">Signed in</th><th align="left" style="padding:6px 8px;font-size:12px;color:#64748B">Signed out</th></tr>'
                . $rows . '</table>'
                . '<p style="margin:14px 0 4px;font-size:13.5px;font-weight:700;color:#334155">Why it matters</p>'
                . '<ul style="margin:0 0 0 18px;padding:0;font-size:14px;color:#334155;line-height:1.6">'
                . '<li><strong>Attendance and ratios</strong> — the record shows the child in care until the automatic time, after they had gone home.</li>'
                . '<li><strong>Families</strong> — parents have been asked to confirm their real pick-up time.</li>'
                . '<li><strong>Subsidy and CWELCC claims</strong> — attendance figures feed the claims, so the times should be corrected.</li></ul>'
                . '<p style="margin:14px 0 0;font-size:14px;color:#334155;line-height:1.6">Correct the sign-out times in <b>Attendance</b>, and sign every child out at pick-up so this does not happen.</p>',
                ['eyebrow' => 'ATTENDANCE', 'title' => ($n === 1 ? $kids[0]['name'] . ' was' : $n . ' children were') . ' signed out automatically', 'subtitle' => $centre]);
            $staff = self::staff($agencyId, $centreId, ['educator', 'home_visitor', 'centre_director']);
            self::send($agencyId, $staff, ($n === 1 ? $kids[0]['name'] . ' was' : $n . ' children were') . ' signed out automatically — ' . $centre, $html,
                'Signed out automatically — ' . $centre, implode(', ', array_column($kids, 'name')) . ': no sign-out recorded. Correct the times in Attendance.', '#attendance', 'children_still_in');

            // 2. Each family: please confirm the real pick-up time.
            $contact = trim((($c->phone ?? '') ? 'call ' . self::e($c->phone) : '') . (($c->phone ?? '') && ($c->email ?? '') ? ' or ' : '')
                . (($c->email ?? '') ? 'email <a href="mailto:' . self::e($c->email) . '" style="color:#1F6FB2">' . self::e($c->email) . '</a>' : ''));
            $byFamily = [];
            foreach ($kids as $k) { if (! empty($k['family_id'])) $byFamily[(int) $k['family_id']][] = $k; }
            foreach ($byFamily as $familyId => $fk) {
                $parents = Audience::excludeOff(DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                    ->where('g.family_id', $familyId)->where('g.billing_hidden', 0)->whereNull('u.deleted_at'))
                    ->get(['u.id', 'u.email', 'u.first_name'])->unique('id')->values();
                if ($parents->isEmpty()) continue;
                $names = implode(' and ', array_column($fk, 'name'));
                $lines = '';
                foreach ($fk as $k) {
                    $lines .= '<strong>' . self::e($k['name']) . ':</strong> signed in ' . self::e($k['in']->format('g:i A, l j F'))
                        . ' · sign-out recorded automatically at ' . self::e($k['out']->format('g:i A')) . '<br>';
                }
                $phtml = EmailTemplate::wrap($agencyId, '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">No sign-out was recorded for <b>'
                    . self::e($names) . '</b> at ' . self::e($centre) . ', so our system filled one in automatically. That time is not when you picked up.</p>'
                    . EmailTemplate::calloutBox($lines, 'info')
                    . '<p style="margin:14px 0 0;font-size:14px;color:#334155;line-height:1.6"><b>Could you let the centre know the time you actually picked up?</b> It keeps your child\'s attendance record accurate'
                    . ' — it is used for ratios, your statements and any subsidy or CWELCC claim.' . ($contact ? ' Please ' . $contact . '.' : '') . '</p>'
                    . '<p style="margin:12px 0 0;font-size:13px;color:#64748B">Nothing is wrong and you do not need to do anything else. Thank you!</p>',
                    ['eyebrow' => 'ATTENDANCE', 'title' => 'Please confirm your pick-up time', 'subtitle' => $centre,
                     'preheader' => 'No sign-out was recorded for ' . $names . '.']);
                self::send($agencyId, $parents, 'Please confirm ' . $names . '\'s pick-up time — ' . $fk[0]['in']->format('l j F'), $phtml,
                    'Please confirm your pick-up time', 'No sign-out was recorded for ' . $names . ' on ' . $fk[0]['in']->format('l j F') . '. Please let the centre know when you picked up.', '#home', 'attendance');
            }
        } catch (\Throwable $e) {
            Log::warning('Auto sign-off children notice failed', ['centre' => $centreId, 'error' => $e->getMessage()]);
        }
    }
}
