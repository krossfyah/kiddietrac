<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\AbsenceController;
use App\Models\User;
use App\Services\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "MARK ABSENT / RUNNING LATE" FROM THE EMAIL (2026-11-25, Anthony: "with the missed child email to parents that did
 * not attend can you add buttons to the email to quickly allow parents to mark child absent, late (you can add a popup
 * to allow them to add notes) which logs appropriately - full sweep").
 *
 * The emails that tell a family their child has not arrived carry two buttons that open attendance-reply.html:
 *   - "Has {child} arrived?" (CheckinReminderCommand, morning) and the Safe Arrival ask (SafeArrivalCommand::askParents):
 *     Mark absent / Running late.
 *   - "We missed {child} today" (SafeArrivalAbsentMail, already marked absent): Add a reason / Running late after all.
 *
 * A link is bound to ONE child, ONE guardian (the recipient) and ONE day, and expires at the end of that day in the
 * agency's timezone. Only the SHA-256 of the token is stored. It is not single use: a parent can change their answer
 * the same day (absent -> late or back), and the page always shows what is on file first.
 *
 * Opening the link (GET) never changes anything - email scanners prefetch links. Only the page's POST acts, and it acts
 * exactly as the parent would in the app, as that guardian:
 *   - absent  -> AbsenceController::store (centre and family told, Safe Arrival closes its check). Safe Arrival's own
 *                automatic absence for that day is replaced by the parent's, never duplicated (one row per child per day).
 *   - late    -> SafeArrival::logLate (educators told, Safe Arrival waits until that time). An absence on file for today
 *                is taken back first (the app's "actually coming in"); Safe Arrival's automatic one also reopens its check
 *                so the child is still looked for if the new time passes.
 * A child already signed in today: nothing changes and the page says so.
 */
final class AttendanceReply
{
    public const PAGE = 'https://app.kiddietrac.com/attendance-reply.html?t=';
    public const REASONS = ['sick', 'appointment', 'holiday', 'family', 'other'];
    public const SOURCES = ['checkin_reminder', 'safe_arrival_ask', 'safe_arrival_no_show'];

    /** A link for one child, one guardian, one day. Null when none can be made (the email still goes, without buttons). */
    public static function issue(int $agencyId, int $childId, int $guardianId, string $date, string $source): ?string
    {
        try {
            $familyId = (int) DB::table('children')->where('id', $childId)->value('family_id');
            if (! $familyId || ! in_array($source, self::SOURCES, true)) {
                return null;
            }
            $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
            $token = Str::random(40);
            DB::table('attendance_reply_links')->insert([
                'token_hash' => hash('sha256', $token),
                'agency_id' => $agencyId, 'child_id' => $childId, 'family_id' => $familyId, 'guardian_user_id' => $guardianId,
                'for_date' => $date, 'source' => $source,
                'expires_at' => Carbon::parse($date, $tz)->endOfDay()->utc()->format('Y-m-d H:i:s'),
                'created_at' => now(),
            ]);

            return self::PAGE . $token;
        } catch (\Throwable $e) {
            Log::warning('Attendance reply link not issued', ['child' => $childId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The two email buttons for a link ('' without one). $noShow: the child is already marked absent, so the first
     * button adds the family's reason and the second says the child is coming after all.
     */
    public static function buttonsHtml(?string $url, string $child, bool $noShow = false): string
    {
        if (! $url) {
            return '';
        }
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $btn = fn (string $label, string $href, string $bg) => '<a href="' . $e($href) . '" class="kt-ar-btn" style="display:inline-block;background:' . $bg
            . ';color:#FFFFFF;padding:12px 20px;border-radius:10px;font-weight:800;font-size:14.5px;line-height:1.2;text-decoration:none;margin:4px 4px;">'
            . $e($label) . '</a>';
        $a = $btn($noShow ? '📝 Add a reason or note' : '🚫 Mark absent', $url . '&a=absent', '#B45309');
        $l = $btn($noShow ? '🕒 Running late after all' : '🕒 Running late', $url . '&a=late', '#1F6080');

        return '<div class="kt-ar" style="margin:18px 0 6px;padding:14px 12px 12px;border:1px solid #E2E8F0;border-radius:14px;background:#F8FAFC;text-align:center;">'
            . '<div class="kt-ar-q" style="font-size:14px;font-weight:800;color:#0F172A;margin:0 0 8px;">'
            . ($noShow ? 'Let us know what happened' : 'Let the team know in one tap') . '</div>'
            . $a . $l
            . '<div class="kt-ar-small" style="font-size:12px;color:#64748B;margin-top:8px;line-height:1.45;">Works today only, just for '
            . $e($child) . '. You can add a note.</div></div>';
    }

    /** Dark-mode rules for the button box (Apple Mail / iOS through prefers-color-scheme, Outlook.com through [data-ogsc]). */
    public static function darkCss(): string
    {
        $dark = '.kt-ar{background:#1E293B !important;border-color:#334155 !important}.kt-ar-q{color:#F1F5F9 !important}.kt-ar-small{color:#94A3B8 !important}.kt-ar a.kt-ar-btn{color:#FFFFFF !important}';

        return '<style>@media (prefers-color-scheme:dark){' . $dark . '}' . preg_replace('/(^|\})\s*\./', '$1[data-ogsc] .', $dark) . '</style>';
    }

    /** Put darkCss() into a built email. */
    public static function withDarkCss(string $html): string
    {
        if (strpos($html, 'class="kt-ar"') === false) {
            return $html;
        }

        return stripos($html, '</head>') !== false ? str_ireplace('</head>', self::darkCss() . '</head>', $html) : self::darkCss() . $html;
    }

    /** @return array{0:?object,1:string} the link and its state: ok | invalid | expired | gone */
    public static function find(string $token): array
    {
        $token = preg_replace('/[^A-Za-z0-9]/', '', $token);
        if (strlen($token) < 30) {
            return [null, 'invalid'];
        }
        $link = DB::table('attendance_reply_links')->where('token_hash', hash('sha256', $token))->first();
        if (! $link) {
            return [null, 'invalid'];
        }
        if ($link->expires_at && Carbon::parse($link->expires_at, 'UTC')->isPast()) {
            return [$link, 'expired'];
        }
        if ($link->for_date !== AgencyTime::today((int) $link->agency_id)) {
            return [$link, 'expired'];
        }
        if (! self::kid($link)) {
            return [$link, 'gone'];
        }

        return [$link, 'ok'];
    }

    public static function stateMessage(string $state): string
    {
        return match ($state) {
            'expired' => 'This link was for an earlier day. Open KiddieTrac to tell the centre about today.',
            'gone' => 'This link is no longer available. Please contact the centre.',
            default => 'This link is not valid. Please use the buttons in your most recent email, or open KiddieTrac.',
        };
    }

    /** The child as the guardian sees them in the app (live family, open centre), or null. */
    private static function kid(object $link): ?array
    {
        foreach (ParentLine::children([(int) $link->guardian_user_id]) as $k) {
            if ($k['child_id'] === (int) $link->child_id && $k['agency_id'] === (int) $link->agency_id) {
                return $k;
            }
        }

        return null;
    }

    /** Signed in today (any check-in on the agency's day)? Returns the time, or null. */
    private static function arrivedAt(object $link, string $tz): ?string
    {
        [$from, $to] = AgencyTime::dayRange((int) $link->agency_id, $link->for_date);
        $at = DB::table('check_events')->where('child_id', $link->child_id)->where('event_type', 'check_in')
            ->whereBetween('occurred_at', [$from, $to])->where('occurred_at', '<=', now())->orderBy('occurred_at')->value('occurred_at');

        return $at ? Carbon::parse($at, 'UTC')->setTimezone($tz)->format('g:i A') : null;
    }

    /** What is on file for today, for the page. */
    public static function current(object $link): array
    {
        $tz = AgencyTime::tz((int) $link->agency_id) ?: 'America/Toronto';
        $arrived = self::arrivedAt($link, $tz);
        $abs = DB::table('child_absences')->where('child_id', $link->child_id)->whereDate('absent_on', $link->for_date)->first();
        $late = SafeArrival::lateQuery([(int) $link->child_id], (string) $link->for_date, 'arrival')->first();
        $auto = $abs && str_starts_with((string) ($abs->note ?? ''), SafeArrivalAbsentMail::NOTE);

        return [
            'arrived' => $arrived,
            'absent' => $abs ? [
                'auto' => $auto,
                'reason' => $auto ? null : ($abs->reason ?: null),
                'note' => $auto ? null : (self::plainNote((string) ($abs->note ?? '')) ?: null),
                'by_you' => ! $auto && (int) ($abs->reported_by_id ?? 0) === (int) $link->guardian_user_id,
            ] : null,
            'late' => ($late && ! $abs) ? ['by' => substr((string) $late->arrive_by, 0, 5),
                'by_label' => Carbon::parse($link->for_date . ' ' . substr((string) $late->arrive_by, 0, 5), $tz)->format('g:i A'),
                'note' => $late->note ?: null] : null,
        ];
    }

    private const TAG = ' (from the email link)';

    private static function plainNote(string $n): string
    {
        $n = trim($n);
        if ($n === 'Reported from the email link.') {
            return '';
        }

        return str_ends_with($n, self::TAG) ? substr($n, 0, -strlen(self::TAG)) : $n;
    }

    /** Everything the public page may show: the child's first name, the centre, today, what is on file. */
    public static function describe(object $link): array
    {
        $tz = AgencyTime::tz((int) $link->agency_id) ?: 'America/Toronto';
        $kid = self::kid($link);
        $now = Carbon::now($tz);
        $row = null;
        try {
            $row = SafeArrival::lateRow((int) $link->agency_id, (int) $link->child_id, 'arrival');
        } catch (\Throwable $e) {
        }
        $centre = (string) DB::table('centres')->where('id', $row['centre_id'] ?? ($kid['centre_id'] ?? 0))->value('name');

        return [
            'child' => $kid['first_name'] ?? '',
            'centre' => $centre,
            'date' => (string) $link->for_date,
            'date_label' => Carbon::parse($link->for_date, $tz)->format('l, F j'),
            'expected' => ! empty($row['expected']) ? Carbon::parse($link->for_date . ' ' . $row['expected'], $tz)->format('g:i A') : null,
            'now' => $now->format('H:i'),
            'can_late' => $row !== null && $now->format('H:i') < '23:30',
            'source' => (string) $link->source,
            'reasons' => self::REASONS,
            'current' => self::current($link),
            'timezone' => $tz,
            'brand' => self::brand((int) $link->agency_id),
        ];
    }

    public static function brand(?int $agencyId): array
    {
        $brand = Brand::forAgency($agencyId);
        $wl = (bool) ($brand['white_label'] ?? false);
        $own = fn ($u) => ($u && ! ($wl && str_contains((string) $u, 'kiddietrac.com'))) ? $u : null;

        return [
            'name' => $brand['name'] ?? 'KiddieTrac',
            'logo' => $own($brand['logo_wordmark_url'] ?? null) ?: $own($brand['logo_square_url'] ?? null),
            'favicon' => $own($brand['favicon_url'] ?? null),
            'primary' => $brand['primary_color'] ?? '#1F6080',
            'white_label' => $wl,
        ];
    }

    /**
     * Record the parent's answer. [ok, message]. One answer at a time per link (a double tap waits for the first).
     *
     * @return array{0:bool,1:string}
     */
    public static function apply(string $token, string $action, ?string $reason, ?string $note, ?string $by): array
    {
        [$link, $state] = self::find($token);
        if ($state !== 'ok') {
            return [false, self::stateMessage($state)];
        }
        $lock = Cache::lock('attendance-reply:' . $link->id, 15);
        if (! $lock->get()) {
            return [false, 'Still saving your last answer. Please try again in a moment.'];
        }
        try {
            return self::applyLocked($link, $action, $reason, $note, $by);
        } finally {
            $lock->release();
        }
    }

    private static function applyLocked(object $link, string $action, ?string $reason, ?string $note, ?string $by): array
    {
        $agencyId = (int) $link->agency_id;
        $childId = (int) $link->child_id;
        $uid = (int) $link->guardian_user_id;
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $kid = self::kid($link);
        $first = $kid['first_name'] ?? 'your child';
        $note = trim(mb_substr((string) $note, 0, 280));
        $user = User::find($uid);
        if (! $user) {
            return [false, self::stateMessage('gone')];
        }
        if ($arrived = self::arrivedAt($link, $tz)) {
            return [false, $first . ' was signed in at ' . $arrived . ' today, so nothing was changed.'];
        }
        $date = (string) $link->for_date;

        if ($action === 'absent') {
            $reason = in_array($reason, self::REASONS, true) ? $reason : 'other';
            $req = Request::create('/api/v1/parent/absences', 'POST', ['child_id' => $childId, 'reason' => $reason,
                'note' => $note !== '' ? $note . self::TAG : 'Reported from the email link.', 'date' => $date]);
            $req->setUserResolver(fn () => $user);
            $req->headers->set('X-Active-Agency-Id', (string) $agencyId);
            $res = app(AbsenceController::class)->store($req);
            if ($res->getStatusCode() !== 200) {
                return [false, 'Sorry, that could not be recorded. Please contact the centre.'];
            }
            // A "running late" notice for today is taken back quietly: the centre was just told about the absence.
            try {
                if (SafeArrival::lateQuery([$childId], $date, 'arrival')->exists()) {
                    SafeArrival::cancelLate($agencyId, $childId, 'arrival', $uid, 'email');
                }
            } catch (\Throwable $e) {
            }
            self::touch($link, 'absent');
            self::audit($agencyId, $uid, 'attendance_reply.absent', $childId,
                "{$first} reported absent today ({$date}) from the email link" . ' - ' . ucfirst($reason) . ($note !== '' ? ': ' . $note : ''));

            return [true, 'Thank you. ' . $first . ' is marked absent today, and the centre has been told.'];
        }

        if ($action !== 'late') {
            return [false, 'Choose Mark absent or Running late.'];
        }
        if (! $by || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $by)) {
            return [false, 'Please choose the time ' . $first . ' will arrive.'];
        }
        if (Carbon::parse($date . ' ' . $by, $tz)->lte(Carbon::now($tz))) {
            return [false, 'Choose a time later than now.'];
        }

        // An absence on file for today is taken back first (the app's "actually, they are coming in").
        $abs = DB::table('child_absences')->where('child_id', $childId)->whereDate('absent_on', $date)->first();
        $reopened = null;
        if ($abs) {
            DB::table('child_absences')->where('id', $abs->id)->delete();
            if (str_starts_with((string) ($abs->note ?? ''), SafeArrivalAbsentMail::NOTE)) {
                // Safe Arrival marked them absent: its check opens again so they are still looked for after the new time.
                $reopened = DB::table('safe_arrival_checks')->where('child_id', $childId)->where('check_date', $date)
                    ->where('resolution', 'no_show')->first();
                if ($reopened) {
                    DB::table('safe_arrival_checks')->where('id', $reopened->id)->update([
                        'status' => 'escalated', 'resolution' => null, 'resolved_at' => null, 'updated_at' => now(),
                        'note' => trim(($reopened->note ? $reopened->note . ' ' : '') . 'Reopened: a parent said running late (email link).'),
                    ]);
                }
            }
        }
        [$err] = SafeArrival::logLate($agencyId, $childId, 'arrival', $by, $note !== '' ? $note : null, $uid, 'parent');
        if ($err) {
            // Put back exactly what was there.
            if ($abs) {
                DB::table('child_absences')->insert((array) $abs);
            }
            if ($reopened) {
                DB::table('safe_arrival_checks')->where('id', $reopened->id)->update([
                    'status' => $reopened->status, 'resolution' => $reopened->resolution, 'resolved_at' => $reopened->resolved_at,
                    'note' => $reopened->note, 'updated_at' => now(),
                ]);
            }
            if ($err === 'That child is not on today\'s list.') {
                $err = $first . ' isn\'t expected at the centre today, so there is no arrival time to change. You can still mark ' . $first . ' absent.';
            }

            return [false, $err];
        }
        $byLabel = Carbon::parse($date . ' ' . $by, $tz)->format('g:i A');
        self::touch($link, 'late');
        self::audit($agencyId, $uid, 'attendance_reply.late', $childId,
            "{$first}: running late, here by {$byLabel} (from the email link)" . ($abs ? '; the absence on file was taken back' . ($reopened ? ' and Safe Arrival reopened' : '') : '')
            . ($note !== '' ? ' - ' . $note : ''));

        return [true, 'Thank you. The centre knows ' . $first . ' will be there by ' . $byLabel . '.'];
    }

    private static function touch(object $link, string $action): void
    {
        DB::table('attendance_reply_links')->where('id', $link->id)
            ->update(['last_action' => $action, 'last_action_at' => now(), 'uses' => DB::raw('uses + 1')]);
    }

    public static function audit(?int $agencyId, ?int $userId, string $action, ?int $childId, string $summary): void
    {
        try {
            Audit::write(['user_id' => $userId, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'child',
                'entity_id' => $childId, 'payload' => json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }
}
