<?php

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "WE MISSED {CHILD} TODAY" (2026-11-24, Anthony: "when children do not show up after the safe arrival checks - does an
 * email get sent out to the family (bcc educator/director/admin) to advise that the child has not show up and has been
 * marked absent (write it nicely)").
 *
 * The family letter sent when Safe Arrival ran its course, nobody signed the child in, no absence was reported and the
 * child is marked absent. Warm, not alarming: what we expected, what we tried, what we recorded, and how to correct it.
 * The child is named, never "he" or "she". Staff are signed as the centre's team (no staff names in email). The room's
 * educators, the centre's director(s) and the agency's admins are BCC'd so they know the family was told.
 *
 * build() is pure (no mail, no database writes) so it can be previewed and tested; send() requires its recipients.
 */
class SafeArrivalAbsentMail
{
    /**
     * $d: child (first name), guardian (first name), centre, date (Y-m-d), expected (g:i A), tried (list of
     *     ['at' => 'g:i A', 'what' => '…']), marked_at (g:i A), phone (centre or agency phone, shown as typed).
     */
    public static function build(int $agencyId, array $d): array
    {
        $child = trim((string) ($d['child'] ?? 'your child'));
        $guardian = trim((string) ($d['guardian'] ?? ''));
        $centre = trim((string) ($d['centre'] ?? 'the centre'));
        $day = Carbon::parse((string) ($d['date'] ?? now()->toDateString()))->format('l, F j');
        $e = fn ($s) => e((string) $s);

        $steps = '';
        $all = array_merge(
            [['at' => $d['expected'] ?? '', 'what' => 'Expected at ' . $centre]],
            (array) ($d['tried'] ?? []),
            [['at' => $d['marked_at'] ?? '', 'what' => 'Marked absent for today']]
        );
        foreach ($all as $i => $s) {
            $last = $i === count($all) - 1;
            $steps .= '<tr>'
                . '<td class="kt-sa-time" style="padding:6px 12px 6px 0;font-size:13px;font-weight:700;color:#0F172A;white-space:nowrap;vertical-align:top;">' . $e($s['at']) . '</td>'
                . '<td class="kt-sa-what" style="padding:6px 0;font-size:13.5px;color:' . ($last ? '#B45309' : '#334155') . ';' . ($last ? 'font-weight:700;' : '') . 'line-height:1.45;">' . $e($s['what']) . '</td>'
                . '</tr>';
        }

        $phone = trim((string) ($d['phone'] ?? ''));
        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">' . ($guardian !== '' ? 'Hi ' . $e($guardian) . ',' : 'Hello,') . '</p>'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">We were looking forward to seeing <strong>' . $e($child) . '</strong> at '
            . $e($centre) . ' today, but ' . $e($child) . ' wasn\'t signed in. We reached out to check, and as we didn\'t hear back, '
            . 'we\'ve marked ' . $e($child) . ' as <strong>absent for ' . $e($day) . '</strong>.</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.65;">We hope everything is okay at home. 💛</p>'
            . '<div class="kt-sa-box" style="margin:0 0 18px;padding:12px 16px;border:1px solid #E2E8F0;border-radius:12px;background:#F8FAFC;">'
            . '<div class="kt-sa-eyebrow" style="font-size:11px;font-weight:800;letter-spacing:.08em;color:#64748B;margin-bottom:6px;">WHAT HAPPENED TODAY</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;">' . $steps . '</table></div>'
            // "Add a reason / Running late after all" (2026-11-25, AttendanceReply): this parent's own link, today only.
            . (string) ($d['actions'] ?? '')
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;"><strong>Did ' . $e($child) . ' attend after all, or is something we should know?</strong> '
            . self::contact($phone, trim((string) ($d['email'] ?? ''))) . ', and we\'ll update today\'s record.</p>'
            . '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#475569;">Next time, you can tap <strong>“Not attending today”</strong> in the KiddieTrac app '
            . 'before drop-off time. It lets the team know right away and saves you these check-in messages.</p>'
            . '<p style="margin:18px 0 0;font-size:15px;line-height:1.65;">Thank you for helping us keep every child safe,<br><strong>The ' . $e(trim((string) ($d['team'] ?? '')) ?: $centre) . ' team</strong></p>';

        $title = 'We missed ' . $child . ' today';
        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow_icon' => SafeArrival::EMAIL_ICON, 'eyebrow' => 'SAFE ARRIVAL', 'title' => $title,
            'subtitle' => 'Marked absent · ' . $day,
            'preheader' => $child . ' wasn\'t signed in today, so we\'ve marked ' . $child . ' absent. Reply if that isn\'t right.',
        ]);
        // Dark mode for the timeline box (the frame handles the rest); Outlook.com gets the same through [data-ogsc].
        $dark = '.kt-sa-box{background:#1E293B !important;border-color:#334155 !important}.kt-sa-time{color:#F1F5F9 !important}'
            . '.kt-sa-what{color:#CBD5E1 !important}.kt-sa-eyebrow{color:#94A3B8 !important}';
        $style = '<style>@media (prefers-color-scheme:dark){' . $dark . '}'
            . preg_replace('/(^|\})\s*\./', '$1[data-ogsc] .', $dark) . '</style>';
        $html = stripos($html, '</head>') !== false ? str_ireplace('</head>', $style . '</head>', $html) : $style . $html;
        $html = AttendanceReply::withDarkCss($html);

        return ['subject' => $title, 'html' => $html];
    }

    /** "Just call us at … or email …": the footer says noreply@ is not read, so the letter names a phone and an inbox. */
    private static function contact(string $phone, string $email): string
    {
        $bits = [];
        if ($phone !== '') $bits[] = 'call us at <strong>' . e($phone) . '</strong>';
        if ($email !== '') $bits[] = 'email <a href="mailto:' . e($email) . '" style="color:#0E7490;font-weight:700;text-decoration:none;">' . e($email) . '</a>';
        return $bits ? 'Just ' . implode(' or ', $bits) : 'Just let the team know in the KiddieTrac app';
    }

    /** How an absence Safe Arrival recorded is recognised (so a late sign-in can take it back). */
    public const NOTE = 'Safe Arrival: not signed in and no absence reported after every check';

    /**
     * Mark the child of an open arrival check absent and tell the family (BCC the staff who were alerted). Returns a
     * one-line summary. $dry works it out and writes nothing. A test agency never sends (TestAgency::blocks), and an
     * agency with notifications switched off still records the absence but mails nobody.
     */
    public static function markAbsent(int $agencyId, array $cfg, object $c, string $tz, bool $dry, bool $quiet, string $when): string
    {
        $child = DB::table('children')->where('id', $c->child_id)->first(['id', 'first_name', 'preferred_name', 'family_id']);
        if (! $child) return 'child not found';
        $first = trim((string) ($child->preferred_name ?: $child->first_name));
        $centre = DB::table('centres')->where('id', $c->centre_id)->value('name') ?: 'the centre';
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['name', 'contact_phone', 'contact_email']);
        $at = fn ($t) => $t ? Carbon::parse($t)->timezone($tz)->format('g:i A') : '';

        $tried = [];
        if ($c->parents_notified_at ?? null) $tried[] = ['at' => $at($c->parents_notified_at), 'what' => 'We sent you a check-in message'];
        if ($c->call_started_at ?? null) $tried[] = ['at' => $at($c->call_started_at), 'what' => 'We tried calling'];
        if ($c->escalated_at ?? null) $tried[] = ['at' => $at($c->escalated_at), 'what' => 'The ' . $centre . ' team was alerted'];
        if (($c->up_alerted_at ?? null) && $c->up_alerted_at != $c->escalated_at) $tried[] = ['at' => $at($c->up_alerted_at), 'what' => 'The office was alerted'];

        $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $child->family_id)->whereNull('u.deleted_at')->whereNotNull('u.email')->where('u.email', '<>', '')
            ->get(['u.id', 'u.email', 'u.first_name']);
        $to = [];
        $toId = [];
        foreach ($guardians as $g) {
            $to[mb_strtolower(trim((string) $g->email))] = (string) $g->first_name;
            $toId[mb_strtolower(trim((string) $g->email))] ??= (int) $g->id;
        }
        $names = array_values(array_filter(array_map(fn ($n) => (string) (strtok(trim($n), ' ') ?: ''), $to)));
        $staff = SafeArrival::recipients($agencyId, (int) $c->centre_id, $c->room_id ? (int) $c->room_id : null, $cfg, true, true);
        $bcc = [];
        foreach (DB::table('users')->whereIn('id', $staff ?: [0])->whereNull('deleted_at')->get(['id', 'email']) as $u) {
            $e = mb_strtolower(trim((string) $u->email));
            if ($e !== '' && ! isset($to[$e]) && SafeArrival::staffWants((int) $u->id, 'email')) $bcc[$e] = $e;
        }
        $phone = preg_replace('/\D+/', '', (string) ($agency->contact_phone ?? ''));
        $phone = strlen($phone) === 10 ? '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6) : trim((string) ($agency->contact_phone ?? ''));
        $d = ['child' => $first, 'guardian' => implode(' and ', array_slice($names, 0, 2)), 'centre' => $centre,
            'date' => (string) $c->check_date, 'expected' => $c->expected_time ? Carbon::parse($c->check_date . ' ' . $c->expected_time, $tz)->format('g:i A') : '',
            'tried' => $tried, 'marked_at' => now($tz)->format('g:i A'), 'phone' => $phone, 'email' => (string) ($agency->contact_email ?? ''),
            // Signed by the agency: a home centre is often named after its provider, and staff last names never go in email.
            'team' => preg_replace('/\s+(inc|ltd|llc|corp)\.?$/i', '', trim((string) ($agency->name ?? '')))];
        $line = $first . ': marked absent (' . $when . '), family ' . count($to) . ' address(es), ' . count($bcc) . ' staff BCC';
        if ($dry) return '[dry] ' . $line;

        DB::table('child_absences')->updateOrInsert(['child_id' => $c->child_id, 'absent_on' => $c->check_date],
            ['reason' => 'other', 'note' => self::NOTE . ' (' . $when . ').', 'reported_by_id' => null, 'created_at' => now()]);
        $sent = false;
        if ($to && ! $quiet && ! TestAgency::blocks($agencyId)) {
            try {
                /* One letter per parent (2026-11-25): each carries that parent's own "Add a reason / Running late after
                   all" link, so an answer is recorded as theirs. The staff copy (BCC) rides on the first letter only. */
                $today = (string) $c->check_date === AgencyTime::today($agencyId);
                $staffCopy = array_values($bcc);
                foreach ($to as $addr => $gname) {
                    $dd = $d;
                    $dd['guardian'] = (string) (strtok(trim($gname), ' ') ?: '');
                    if ($today && ! empty($toId[$addr])) {
                        $dd['actions'] = AttendanceReply::buttonsHtml(
                            AttendanceReply::issue($agencyId, (int) $c->child_id, $toId[$addr], (string) $c->check_date, 'safe_arrival_no_show'), $first, true);
                    }
                    self::send($agencyId, [$addr => $gname], $staffCopy, $agency->contact_email ?? null, $dd);
                    $staffCopy = [];
                    $sent = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Safe Arrival absent email failed', ['check' => $c->id, 'error' => $e->getMessage()]);
            }
        }
        DB::table('safe_arrival_checks')->where('id', $c->id)->whereNull('resolved_at')->update([
            'status' => 'absent', 'resolution' => 'no_show', 'resolved_at' => now(), 'updated_at' => now(),
            'note' => 'Marked absent by Safe Arrival (' . $when . ').' . ($sent ? ' The family was emailed.' : ($to ? ' No email sent (notifications off).' : ' No family email on file.')),
        ]);
        SafeArrivalAllClear::markRead(SafeArrivalAllClear::alertRows('arrival', $c));
        try {
            Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'safe_arrival.marked_absent', 'entity_type' => 'child', 'entity_id' => (int) $c->child_id,
                'payload' => json_encode(['check_id' => (int) $c->id, 'summary' => "Safe Arrival marked {$first} absent for {$c->check_date} ({$when})"
                    . ($sent ? '; family emailed, ' . count($bcc) . ' staff copied' : '; no family email sent')], JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }

        return $line . ($sent ? ', emailed' : ', not emailed');
    }

    /** The child turned up after all: take back the absence Safe Arrival recorded (staff absences are never touched). */
    public static function clearIfArrived(int $agencyId, int $childId, string $date, ?string $arrivedAt, bool $dry): bool
    {
        $q = DB::table('child_absences')->where('child_id', $childId)->whereDate('absent_on', $date)->where('note', 'like', self::NOTE . '%');
        if (! $q->exists()) return false;
        if ($dry) return true;
        $q->delete();
        DB::table('safe_arrival_checks')->where('child_id', $childId)->where('check_date', $date)->where('resolution', 'no_show')
            ->update(['status' => 'arrived', 'resolution' => 'arrived_late', 'arrived_at' => $arrivedAt, 'updated_at' => now()]);
        try {
            Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'safe_arrival.absence_cleared', 'entity_type' => 'child', 'entity_id' => $childId,
                'payload' => json_encode(['summary' => "Signed in after Safe Arrival marked them absent on {$date}: the absence was removed"]), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }

        return true;
    }

    /** Send to the family; $bcc are staff addresses. $replyTo is the centre's or agency's own inbox. */
    public static function send(int $agencyId, array $to, array $bcc, ?string $replyTo, array $d, ?string $subjectPrefix = null): void
    {
        if (! $to) throw new \InvalidArgumentException('SafeArrivalAbsentMail::send needs a recipient.');
        $m = self::build($agencyId, $d);
        $subject = ($subjectPrefix ?? '') . $m['subject'];
        AgencyMailer::forAgency($agencyId)->html($m['html'], function ($msg) use ($to, $bcc, $replyTo, $subject) {
            foreach ($to as $addr => $name) $msg->to($addr, (string) (strtok(trim((string) $name), ' ') ?: '') ?: null);
            foreach ($bcc as $addr) $msg->bcc($addr);
            if ($replyTo) $msg->replyTo($replyTo);
            $msg->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($subject);
        });
    }
}
