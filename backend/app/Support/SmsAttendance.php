<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "REPLY 1 RUNNING LATE, 2 ABSENT TODAY" (2026-11-25, Anthony: "with the text add an option to reply with 1 for late,
 * 2 for absent etc").
 *
 * The Safe Arrival "has X arrived?" text and the morning check-in reminder text end with a reply line. Each text opens
 * (or adds to) today's PROMPT for that parent and that phone: sms_attendance_prompts, one row per agency + parent +
 * phone + day, holding the children asked about in the order they are numbered. One child: 1 = running late,
 * 2 = absent. Several: 1 Ava late, 2 Ava absent, 3 Liam late, 4 Liam absent (the latest text says the numbering).
 *
 * A reply is taken here BEFORE it becomes a chat message, and only when:
 *   - the number that replied is exactly the number the prompt was texted to (last 10 digits), for the agency whose
 *     number it was (Telnyx names it in the route), today in the agency's timezone, and the prompted parent is one of
 *     the accounts on that phone;
 *   - the text is one of the answers ("1", "2.", " late ", "absent", a time while we wait for one).
 * Anything else - STOP/HELP (handled before this), "thanks", a question - goes to chat exactly as before.
 *
 * Answers are recorded exactly like the email buttons (AttendanceReply::answer -> AbsenceController::store /
 * SafeArrival::logLate as that parent), and a confirmation is texted back through SmsController::sendOne (every gate:
 * test agencies, do-not-contact, consent).
 *
 * Running late: the centre is told at once with a provisional time 30 minutes from now (marked "time not given yet"),
 * and the parent is asked "What time will Ava arrive?". A time sent back within 2 hours replaces it.
 */
final class SmsAttendance
{
    public const MAX_CHILDREN = 3;
    public const AWAIT_MINUTES = 120;
    public const PROVISIONAL_MINUTES = 30;

    public static function last10(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return strlen($d) >= 10 ? substr($d, -10) : '';
    }

    /**
     * Open (or add to) today's prompt for this parent's phone, and return the line to put at the end of the text:
     * " Reply 1 if running late, 2 if absent today." ('' when no prompt can be made: the text goes without it).
     */
    public static function promptLine(int $agencyId, int $guardianId, ?string $phone, int $childId, string $childFirst, string $source): string
    {
        $p10 = self::last10($phone);
        if ($p10 === '') {
            return '';
        }
        try {
            $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
            $date = AgencyTime::today($agencyId);
            $key = ['agency_id' => $agencyId, 'guardian_user_id' => $guardianId, 'phone10' => $p10, 'for_date' => $date];
            $row = DB::table('sms_attendance_prompts')->where($key)->first();
            $kids = $row ? (json_decode((string) $row->children, true) ?: []) : [];
            $kids = array_values(array_filter($kids, fn ($k) => (int) $k['id'] !== $childId));
            $kids[] = ['id' => $childId, 'first' => $childFirst];
            $kids = array_slice($kids, -self::MAX_CHILDREN);
            $vals = ['children' => json_encode($kids, JSON_UNESCAPED_UNICODE), 'source' => $source,
                'expires_at' => Carbon::parse($date, $tz)->endOfDay()->utc()->format('Y-m-d H:i:s'), 'updated_at' => now()];
            if ($row) {
                DB::table('sms_attendance_prompts')->where('id', $row->id)->update($vals);
            } else {
                DB::table('sms_attendance_prompts')->insert($key + $vals + ['created_at' => now()]);
            }

            return self::line($kids);
        } catch (\Throwable $e) {
            Log::warning('SMS attendance prompt not opened', ['child' => $childId, 'error' => $e->getMessage()]);

            return '';
        }
    }

    /** The reply line for these children (plain GSM-7: no emoji, no curly quotes, no dashes). */
    public static function line(array $kids): string
    {
        if (count($kids) === 1) {
            return 'Reply 1 if running late, 2 if absent today.';
        }
        $bits = [];
        $n = 1;
        foreach ($kids as $k) {
            $bits[] = $n . ' ' . $k['first'] . ' late';
            $bits[] = ($n + 1) . ' ' . $k['first'] . ' absent';
            $n += 2;
        }

        return 'Reply ' . implode(', ', $bits) . '.';
    }

    /**
     * An inbound text. Returns null when it is not an answer to a prompt (the caller files it as before), or
     * ['user_id' => the parent, 'reply' => the confirmation to text back, 'agency_id' => …].
     *
     * @param  iterable  $onThisPhone  the accounts whose phone is this number (SmsConsentController::usersByPhone)
     */
    public static function handle(string $from, string $text, ?int $agencyId, iterable $onThisPhone): ?array
    {
        $p10 = self::last10($from);
        if ($p10 === '' || ! self::ready()) {
            return null;
        }
        $ids = [];
        foreach ($onThisPhone as $u) {
            $ids[] = (int) $u->id;
        }
        if (! $ids) {
            return null;
        }
        $q = DB::table('sms_attendance_prompts')->where('phone10', $p10)->whereIn('guardian_user_id', $ids)
            ->where('expires_at', '>', now());
        if ($agencyId) {
            $q->where('agency_id', $agencyId);
        }
        $prompt = null;
        foreach ($q->orderByDesc('updated_at')->get() as $r) {
            if ((string) $r->for_date === AgencyTime::today((int) $r->agency_id)) {
                $prompt = $r;
                break;
            }
        }
        if (! $prompt) {
            return null;
        }
        $kids = json_decode((string) $prompt->children, true) ?: [];
        if (! $kids) {
            return null;
        }
        $ans = self::parse($text, count($kids), $prompt);
        if (! $ans) {
            return null;
        }
        $agency = (int) $prompt->agency_id;
        $uid = (int) $prompt->guardian_user_id;

        if ($ans['action'] === 'badtime') {
            $tz = AgencyTime::tz($agency) ?: 'America/Toronto';

            return ['user_id' => $uid, 'agency_id' => $agency,
                'reply' => 'That time has already passed. Please reply with a time later than now, e.g. ' . Carbon::now($tz)->addHour()->startOfHour()->format('g:i')];
        }
        if ($ans['action'] === 'time') {
            $kid = null;
            foreach ($kids as $k) {
                if ((int) $k['id'] === (int) $prompt->await_child_id) $kid = $k;
            }
            if (! $kid) {
                return null;
            }
            [$ok, $msg] = AttendanceReply::answer($agency, (int) $kid['id'], $uid, 'late', null, null, $ans['by'], 'text reply');
            if ($ok) {
                DB::table('sms_attendance_prompts')->where('id', $prompt->id)->update(['await_child_id' => null, 'await_until' => null,
                    'last_action' => 'late_time', 'updated_at' => now()]);
            }

            return ['user_id' => $uid, 'agency_id' => $agency, 'reply' => $ok ? $msg : $msg];
        }

        $kid = $kids[$ans['child']] ?? null;
        if (! $kid) {
            return null;
        }
        $first = (string) $kid['first'];
        if ($at = AttendanceReply::arrivedToday($agency, (int) $kid['id'])) {
            return ['user_id' => $uid, 'agency_id' => $agency, 'reply' => $first . ' was signed in at ' . $at . ' today, so nothing was changed.'];
        }

        if ($ans['action'] === 'absent') {
            [$ok, $msg] = AttendanceReply::answer($agency, (int) $kid['id'], $uid, 'absent', 'other', null, null, 'text reply');
            if ($ok) {
                DB::table('sms_attendance_prompts')->where('id', $prompt->id)->update(['await_child_id' => null, 'await_until' => null,
                    'last_action' => 'absent', 'updated_at' => now()]);
            }

            return ['user_id' => $uid, 'agency_id' => $agency, 'reply' => $msg];
        }

        // Running late: the centre is told now with a provisional time; the real one replaces it when it comes.
        $tz = AgencyTime::tz($agency) ?: 'America/Toronto';
        $soon = Carbon::now($tz)->addMinutes(self::PROVISIONAL_MINUTES);
        $by = $soon->copy()->setTime($soon->hour, 0)->addMinutes((int) (ceil($soon->minute / 5) * 5));   // the next 5 minutes
        if ($by->toDateString() !== Carbon::now($tz)->toDateString()) $by = Carbon::now($tz)->setTime(23, 55);
        [$ok, $msg] = AttendanceReply::answer($agency, (int) $kid['id'], $uid, 'late', null, 'Replied by text; time not given yet',
            $by->format('H:i'), 'text reply');
        if (! $ok) {
            return ['user_id' => $uid, 'agency_id' => $agency, 'reply' => $msg];
        }
        DB::table('sms_attendance_prompts')->where('id', $prompt->id)->update(['await_child_id' => (int) $kid['id'],
            'await_until' => now()->addMinutes(self::AWAIT_MINUTES), 'last_action' => 'late', 'updated_at' => now()]);

        return ['user_id' => $uid, 'agency_id' => $agency,
            'reply' => 'Thanks, the centre knows ' . $first . ' is running late. What time will ' . $first . ' arrive? Reply with a time, e.g. 9:30'];
    }

    /**
     * What the reply means: ['action' => late|absent, 'child' => index] or ['action' => time, 'by' => 'HH:MM'], or null.
     */
    public static function parse(string $text, int $kids, ?object $prompt = null): ?array
    {
        $t = mb_strtolower(trim($text));
        $t = trim(preg_replace('/[\s.!,;:)\]]+$/u', '', $t) ?? '');
        $t = trim(preg_replace('/^[\s(\[#]+/u', '', $t) ?? '');
        if ($t === '') {
            return null;
        }
        // A reply number always means its option ("2" while we wait for a time is "absent", not 2 PM).
        $isOption = preg_match('/^\d{1,2}$/', $t) && (int) $t >= 1 && (int) $t <= 2 * $kids;
        // Waiting for a time: "9:30", "930", "9.30", "10", "10am", "2:15 pm", "14:00".
        if (! $isOption && $prompt && $prompt->await_child_id && $prompt->await_until && Carbon::parse($prompt->await_until, 'UTC')->isFuture()) {
            $hm = self::time($t, (int) $prompt->agency_id);
            if ($hm === 'past') {
                return ['action' => 'badtime'];
            }
            if ($hm) {
                return ['action' => 'time', 'by' => $hm];
            }
        }
        if (preg_match('/^\d{1,2}$/', $t)) {
            $n = (int) $t;
            if ($n >= 1 && $n <= 2 * $kids) {
                return ['action' => $n % 2 === 1 ? 'late' : 'absent', 'child' => intdiv($n - 1, 2)];
            }

            return null;
        }
        if ($kids === 1) {
            $late = ['late', 'running late', 'retard', 'en retard', 'tarde', 'llega tarde', 'देर', 'देरी'];
            $absent = ['absent', 'absent today', 'absente', 'ausente', 'अनुपस्थित'];
            if (in_array($t, $late, true)) return ['action' => 'late', 'child' => 0];
            if (in_array($t, $absent, true)) return ['action' => 'absent', 'child' => 0];
        }

        return null;
    }

    /**
     * A time later than now today (agency zone) as HH:MM; 'past' when it is a time but not a later one; null when it is
     * not a time. Without am/pm, 7-11 is the morning and 1-6 the afternoon (a childcare day); "12" is noon.
     */
    public static function time(string $t, int $agencyId): ?string
    {
        $t = str_replace(['.', ' '], [':', ''], $t);
        if (! preg_match('/^(\d{1,2})(?::?(\d{2}))?(am|pm|a|p)?$/', $t, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $ap = $m[3] ?? '';
        if ($min > 59 || $h > 23) return null;
        if ($ap !== '' && ($h < 1 || $h > 12)) return null;
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $now = Carbon::now($tz);
        if ($ap !== '') {
            $hh = ($h % 12) + (str_starts_with($ap, 'p') ? 12 : 0);
        } elseif ($h >= 1 && $h <= 6) {
            $hh = $h + 12;
        } else {
            $hh = $h;
        }
        $at = $now->copy()->setTime($hh, $min);

        return $at->gt($now) ? $at->format('H:i') : 'past';
    }

    private static ?bool $ready = null;

    private static function ready(): bool
    {
        return self::$ready ??= \Illuminate\Support\Facades\Schema::hasTable('sms_attendance_prompts');
    }
}
