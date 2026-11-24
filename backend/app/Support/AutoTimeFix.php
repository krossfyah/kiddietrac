<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "TELL US THE REAL TIME" LINKS (2026-10-22).
 *
 * Anthony: "with the emails sent out to the parents to confirm the pick up time when the
 * system automatically clocks them out - add an option to click on a link that will allow
 * them to fill in the time and have this updated in our systems. do the same for educators
 * who gets clocked out automatically." His decision: the time is applied AT ONCE, clearly
 * marked as entered from the email, and the centre is told and can adjust it.
 *
 * A link is bound to one record (a child's automatic check-out, or an educator's
 * automatically closed punch) AND one recipient. Only the SHA-256 of the token is stored;
 * it is single use and expires after 7 days. The page it opens (fix-time.html) shows only
 * that record's day, times and first name — nothing else is reachable through it.
 *
 * The record keeps is_automatic / source='auto' (nobody signed out at the time, and the
 * auto sign-off report must still say so) and gains the correction trail: the automatic
 * time it replaced, who entered the real one, from where, and their note.
 */
final class AutoTimeFix
{
    public const TTL_DAYS = 7;
    public const PAGE = 'https://app.kiddietrac.com/fix-time.html?t=';

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** Make a link for one record and one recipient. Returns the URL, or null on failure. */
    public static function issue(string $kind, int $recordId, int $recipientId, ?int $agencyId, ?int $centreId, Carbon $autoAt): ?string
    {
        try {
            $token = Str::random(40);
            DB::table('auto_time_fix_links')->insert([
                'kind' => $kind,
                'record_id' => $recordId,
                'agency_id' => $agencyId,
                'centre_id' => $centreId,
                'recipient_user_id' => $recipientId,
                'auto_at' => $autoAt->copy()->utc()->format('Y-m-d H:i:s'),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(self::TTL_DAYS),
                'created_at' => now(),
            ]);

            return self::PAGE . $token;
        } catch (\Throwable $e) {
            Log::warning('Auto time-fix link not issued', ['kind' => $kind, 'record' => $recordId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** The email button, or '' when no link could be made (the email still goes out). */
    public static function buttonHtml(?string $url, string $label, int $agencyId): string
    {
        if (! $url) {
            return '';
        }
        $colour = '#1F6080';
        try {
            $colour = (string) (Brand::forAgency($agencyId)['primary_color'] ?? $colour) ?: $colour;
        } catch (\Throwable $e) {
        }

        return '<div style="margin:16px 0 4px">' . EmailTemplate::button($label, $url, $colour) . '</div>'
            . '<p style="margin:6px 0 0;font-size:12px;color:#64748B">The link works once and expires in ' . self::TTL_DAYS . ' days. It only opens this record.</p>';
    }

    /** @return array{0: ?object, 1: string} [link row, state: ok|used|expired|gone|corrected] */
    public static function find(string $token): array
    {
        $token = preg_replace('/[^A-Za-z0-9]/', '', $token);
        if (strlen($token) < 30) {
            return [null, 'gone'];
        }
        $link = DB::table('auto_time_fix_links')->where('token_hash', hash('sha256', $token))->first();
        if (! $link) {
            return [null, 'gone'];
        }
        if ($link->used_at) {
            return [$link, 'used'];
        }
        if (Carbon::parse($link->expires_at)->isPast()) {
            return [$link, 'expired'];
        }
        $rec = self::record($link);
        if (! $rec) {
            return [$link, 'gone'];
        }
        // Somebody at the centre already put a real time on it: nothing left to fill in.
        if ($rec->correction_source || ! $rec->out_at || substr((string) $rec->out_at, 0, 16) !== substr((string) $link->auto_at, 0, 16)) {
            return [$link, 'corrected'];
        }

        return [$link, 'ok'];
    }

    /** The record behind a link, normalised: in_at, out_at (UTC strings), names, centre. */
    public static function record(object $link): ?object
    {
        if ($link->kind === 'child') {
            $out = DB::table('check_events')->where('id', $link->record_id)->where('event_type', 'check_out')->first();
            if (! $out) {
                return null;
            }
            $in = DB::table('check_events')->where('child_id', $out->child_id)->where('event_type', 'check_in')
                ->where('occurred_at', '<', $out->occurred_at)->orderByDesc('occurred_at')->first(['occurred_at']);
            $child = DB::table('children')->where('id', $out->child_id)->first(['id', 'first_name', 'preferred_name', 'family_id']);

            return (object) [
                'in_at' => $in->occurred_at ?? null, 'out_at' => $out->occurred_at,
                'correction_source' => $out->correction_source ?? null,
                'who' => $child ? ($child->preferred_name ?: $child->first_name) : 'your child',
                'child_id' => $child->id ?? null, 'family_id' => $child->family_id ?? null, 'room_id' => $out->room_id,
            ];
        }
        $p = DB::table('time_punches')->where('id', $link->record_id)->first();
        if (! $p) {
            return null;
        }
        $u = DB::table('users')->where('id', $p->user_id)->first(['first_name', 'last_name']);

        return (object) [
            'in_at' => $p->punched_in_at, 'out_at' => $p->punched_out_at,
            'correction_source' => $p->correction_source ?? null,
            'who' => $u ? trim($u->first_name . ' ' . $u->last_name) : 'you', 'user_id' => (int) $p->user_id,
        ];
    }

    /** What the public page may show, all times in the AGENCY's zone. */
    public static function describe(object $link): array
    {
        $tz = AgencyTime::tz($link->agency_id ? (int) $link->agency_id : null) ?: 'America/Toronto';
        $rec = self::record($link);
        $in = Carbon::parse((string) $rec->in_at, 'UTC')->setTimezone($tz);
        $auto = Carbon::parse((string) $link->auto_at, 'UTC')->setTimezone($tz);
        // The real time is on the sign-in day, after the sign-in and before the automatic time.
        $max = $auto->toDateString() === $in->toDateString() ? $auto->format('H:i') : '23:59';
        $brand = Brand::forAgency($link->agency_id ? (int) $link->agency_id : null);
        $wl = (bool) ($brand['white_label'] ?? false);
        // A white-label brand with no logo of its own inherits KiddieTrac's default image:
        // never show it — the page prints the brand's name instead.
        $own = fn ($u) => ($u && ! ($wl && str_contains((string) $u, 'kiddietrac.com'))) ? $u : null;

        return [
            'kind' => $link->kind,
            'who' => $rec->who,
            'centre' => (string) DB::table('centres')->where('id', $link->centre_id)->value('name'),
            'date' => $in->toDateString(),
            'date_label' => $in->format('l, F j, Y'),
            'in_label' => $in->format('g:i A'),
            'auto_label' => $auto->format('g:i A') . ($auto->toDateString() !== $in->toDateString() ? ' (' . $auto->format('D M j') . ')' : ''),
            'min' => $in->format('H:i'),
            'max' => $max,
            'timezone' => $tz,
            'brand' => [
                'name' => $brand['name'] ?? 'KiddieTrac',
                'logo' => $own($brand['logo_wordmark_url'] ?? null) ?: $own($brand['logo_square_url'] ?? null),
                'favicon' => $own($brand['favicon_url'] ?? null),
                'primary' => $brand['primary_color'] ?? '#1F6080',
                'white_label' => $wl,
            ],
        ];
    }

    /**
     * Apply a submitted time. Returns [ok, message]. All writes in one transaction with the
     * link row locked, so a double-tap cannot apply twice.
     *
     * @return array{0: bool, 1: string}
     */
    public static function apply(string $token, string $hhmm, ?string $note, ?string $ip = null): array
    {
        $token = preg_replace('/[^A-Za-z0-9]/', '', $token);
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm)) {
            return [false, 'Please choose a time.'];
        }
        $note = trim(mb_substr((string) $note, 0, 280));

        $result = DB::transaction(function () use ($token, $hhmm, $note, $ip) {
            $link = DB::table('auto_time_fix_links')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $link) {
                return [false, 'This link is not valid.', null];
            }
            [, $state] = self::find($token);
            if ($state !== 'ok') {
                return [false, self::stateMessage($state), null];
            }
            $rec = self::record($link);
            $tz = AgencyTime::tz($link->agency_id ? (int) $link->agency_id : null) ?: 'America/Toronto';
            $in = Carbon::parse((string) $rec->in_at, 'UTC')->setTimezone($tz);
            $auto = Carbon::parse((string) $link->auto_at, 'UTC')->setTimezone($tz);
            $when = Carbon::parse($in->toDateString() . ' ' . $hhmm . ':00', $tz);
            if ($when->lte($in)) {
                return [false, 'That time is before the ' . ($link->kind === 'child' ? 'sign-in' : 'clock-in') . ' at ' . $in->format('g:i A') . '. Please choose a later time.', null];
            }
            if ($when->gt($auto)) {
                return [false, 'That time is after the automatic time (' . $auto->format('g:i A') . '). Please choose a time at or before it.', null];
            }
            $utc = $when->copy()->utc()->format('Y-m-d H:i:s');
            $source = $link->kind === 'child' ? 'parent_email' : 'educator_email';
            $marker = ($link->kind === 'child' ? 'Pick-up time entered by parent from email' : 'Clock-out time entered by educator from email')
                . ' (automatic time was ' . $auto->format('g:i A') . ')' . ($note !== '' ? ': ' . $note : '');
            $fields = [
                'correction_source' => $source,
                'original_auto_at' => $link->auto_at,
                'corrected_by_user_id' => $link->recipient_user_id,
                'corrected_at' => now(),
                'correction_note' => $note !== '' ? $note : null,
            ];
            if ($link->kind === 'child') {
                DB::table('check_events')->where('id', $link->record_id)->update($fields + ['occurred_at' => $utc,
                    'notes' => mb_substr($marker, 0, 1000)]);
            } else {
                $old = (string) DB::table('time_punches')->where('id', $link->record_id)->value('notes');
                DB::table('time_punches')->where('id', $link->record_id)->update($fields + ['punched_out_at' => $utc,
                    'notes' => mb_substr(trim($old . ' [' . $marker . ']'), 0, 300)]);
            }
            DB::table('auto_time_fix_links')->where('id', $link->id)->update(['used_at' => now(), 'submitted_time' => $utc]);
            // Any other link for the same record (the other parent's copy) is now spent too.
            DB::table('auto_time_fix_links')->where('kind', $link->kind)->where('record_id', $link->record_id)
                ->whereNull('used_at')->update(['used_at' => now()]);

            return [true, 'ok', ['link' => $link, 'rec' => $rec, 'when' => $when, 'auto' => $auto, 'in' => $in, 'note' => $note, 'ip' => $ip]];
        });

        if (! $result[0]) {
            return [false, $result[1]];
        }
        self::after($result[2]);

        return [true, $result[2]['link']->kind === 'child'
            ? 'Thank you — the pick-up time is now ' . $result[2]['when']->format('g:i A') . '. The centre has been told.'
            : 'Thank you — your clock-out time is now ' . $result[2]['when']->format('g:i A') . '. Your director has been told.'];
    }

    public static function stateMessage(string $state): string
    {
        return match ($state) {
            'used' => 'This link has already been used — the time was entered. If it needs changing, please contact the centre.',
            'expired' => 'This link has expired. Please contact the centre to correct the time.',
            'corrected' => 'The centre has already corrected this time, so there is nothing left to fill in.',
            default => 'This link is not valid. Please use the link in your most recent email, or contact the centre.',
        };
    }

    /** Everything that follows a correction: day log, late pickup, audit, staff notices. Never throws. */
    private static function after(array $d): void
    {
        $link = $d['link'];
        $rec = $d['rec'];
        $agencyId = $link->agency_id ? (int) $link->agency_id : null;
        $centreId = (int) $link->centre_id;
        $when = $d['when'];
        $auto = $d['auto'];
        $minutes = (int) abs($auto->diffInMinutes($when));
        $submitter = DB::table('users')->where('id', $link->recipient_user_id)->first(['id', 'first_name', 'last_name', 'email']);
        $byName = $submitter ? trim($submitter->first_name . ' ' . $submitter->last_name) : 'Someone';
        $centre = (string) DB::table('centres')->where('id', $centreId)->value('name');
        $day = $d['in']->format('l j F');

        try {
            if ($link->kind === 'child') {
                // The day log said "Signed out automatically at …" — say what really happened.
                DB::table('daily_events')->where('child_id', $rec->child_id)->where('event_type', 'note')
                    ->where('payload', 'like', '%auto_sign_off%')->where('occurred_at', $link->auto_at)
                    ->update(['occurred_at' => $when->copy()->utc(), 'updated_at' => now(),
                        'notes' => 'Picked up at ' . $when->format('g:i A') . ' — entered by ' . $byName . ' (parent) from the email.'
                            . ' No sign-out was recorded at the time; the automatic time was ' . $auto->format('g:i A') . '.'
                            . ($d['note'] !== '' ? ' Note: ' . $d['note'] : '')]);
            }
        } catch (\Throwable $e) {
            Log::warning('Time-fix day log failed', ['error' => $e->getMessage()]);
        }

        /* A LATE PICKUP IS NEVER WAIVED SILENTLY. A time after the centre's close goes on the
           late-pickup list as PENDING for a director to decide, marked as parent-entered. A
           time before close is still sent to the centre (below) so they can check it. */
        $lateId = null;
        try {
            if ($link->kind === 'child') {
                $close = DB::table('centres')->where('id', $centreId)->value('close_time');
                if ($close) {
                    $closeAt = Carbon::parse($when->toDateString() . ' ' . $close, $when->getTimezone());
                    $late = (int) $closeAt->diffInMinutes($when, false);
                    if ($late >= 1) {
                        $lateId = DB::table('late_events')->insertGetId([
                            'child_id' => $rec->child_id, 'centre_id' => $centreId, 'kind' => 'departure',
                            'occurred_on' => $when->toDateString(), 'pickup_at' => $when->format('Y-m-d H:i:s'),
                            'close_time' => $close, 'minutes' => $late,
                            'suggested_fee' => $agencyId ? LatePickups::suggest($agencyId, $late) : null,
                            'note' => 'Pick-up time entered by the parent (' . $byName . ') from the automatic sign-out email — please verify.',
                            'recorded_by_id' => null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Time-fix late pickup flag failed', ['error' => $e->getMessage()]);
        }

        try {
            Audit::write([
                'user_id' => $link->recipient_user_id,
                'agency_id' => $agencyId,
                'action' => $link->kind === 'child' ? 'attendance.pickup_time_from_email' : 'staff.clock_out_from_email',
                'entity_type' => $link->kind === 'child' ? 'child' : 'time_punch',
                'entity_id' => $link->kind === 'child' ? $rec->child_id : $link->record_id,
                'payload' => json_encode([
                    'summary' => $link->kind === 'child'
                        ? $byName . ' entered ' . $rec->who . '\'s pick-up time from the email: ' . $when->format('g:i A') . ' on ' . $day . ' (automatic time was ' . $auto->format('g:i A') . ')' . ($lateId ? ' — after close, added to Late pickups for review.' : '.')
                        : $byName . ' entered their clock-out time from the email: ' . $when->format('g:i A') . ' on ' . $day . ' (automatic time was ' . $auto->format('g:i A') . ').',
                    'record_id' => $link->record_id, 'centre' => $centre,
                    'entered' => $when->format('Y-m-d H:i'), 'automatic' => $auto->format('Y-m-d H:i'),
                    'note' => $d['note'] !== '' ? $d['note'] : null, 'late_event_id' => $lateId,
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => $d['ip'],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Time-fix audit failed', ['error' => $e->getMessage()]);
        }

        // The centre is told, with a way to adjust it.
        try {
            if (! $agencyId) {
                return;
            }
            $roles = $link->kind === 'child' ? ['centre_director', 'educator'] : ['centre_director'];
            $people = Audience::excludeService(Audience::excludeOff(DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', true)->whereNull('u.deleted_at')
                ->where(function ($w) use ($agencyId, $centreId, $roles) {
                    $w->where(fn ($x) => $x->where('ra.centre_id', $centreId)->whereIn('ra.role', $roles))
                      ->orWhere(fn ($x) => $x->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin'));
                })))
                ->where('u.id', '!=', (int) $link->recipient_user_id)
                ->get(['u.id', 'u.email', 'u.first_name'])->unique('id')->values();
            if ($link->kind === 'child') {
                // Educators: only this home's own team (not agency office accounts).
                $team = CentreTeam::forCentres([$centreId])[$centreId] ?? [];
                $people = $people->filter(function ($p) use ($team, $agencyId, $centreId) {
                    $isEdOnly = DB::table('role_assignments')->where('user_id', $p->id)->where('active', true)
                        ->whereIn('role', ['centre_director', 'agency_admin'])->doesntExist();
                    return ! $isEdOnly || isset($team[(int) $p->id]);
                })->values();
            }
            $what = $link->kind === 'child'
                ? '<b>' . self::e($byName) . '</b> entered <b>' . self::e($rec->who) . '</b>\'s real pick-up time from the automatic sign-out email.'
                : '<b>' . self::e($byName) . '</b> entered their real clock-out time from the automatic clock-out email.';
            $box = EmailTemplate::calloutBox('<strong>' . ($link->kind === 'child' ? 'Signed in' : 'Clocked in') . ':</strong> ' . self::e($d['in']->format('g:i A, l j F'))
                . '<br><strong>' . ($link->kind === 'child' ? 'Picked up' : 'Clocked out') . ' (entered):</strong> ' . self::e($when->format('g:i A'))
                . '<br><strong>Automatic time it replaced:</strong> ' . self::e($auto->format('g:i A'))
                . ($d['note'] !== '' ? '<br><strong>Their note:</strong> ' . self::e($d['note']) : '')
                . '<br><strong>Centre:</strong> ' . self::e($centre), 'info');
            $late = $lateId ? EmailTemplate::calloutBox('This is after the centre\'s closing time, so it has been added to <b>Late pickups</b> as pending for a director to decide.', 'warning') : '';
            $adjust = $link->kind === 'child'
                ? 'It is already applied. If it is not right, correct it in the <b>Daily Overview</b> or <b>Attendance</b>.'
                : 'It is already applied to the timesheet. If it is not right, correct it in <b>Timesheets</b>.';
            $html = EmailTemplate::wrap($agencyId, '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">' . $what . '</p>' . $box . $late
                . '<p style="margin:14px 0 0;font-size:14px;color:#334155;line-height:1.6">' . $adjust . '</p>',
                ['eyebrow' => $link->kind === 'child' ? 'ATTENDANCE' : 'TIME CLOCK',
                 'title' => $link->kind === 'child' ? 'Pick-up time entered by a parent' : 'Clock-out time entered by ' . $byName,
                 'subtitle' => $centre . ' · ' . $day]);
            $subject = $link->kind === 'child'
                ? 'Pick-up time entered: ' . $rec->who . ' — ' . $when->format('g:i A') . ', ' . $d['in']->format('D j M')
                : 'Clock-out time entered: ' . $byName . ' — ' . $when->format('g:i A') . ', ' . $d['in']->format('D j M');
            $bellBody = ($link->kind === 'child' ? $rec->who . ' picked up at ' : $byName . ' clocked out at ') . $when->format('g:i A')
                . ' (entered from email; automatic time ' . $auto->format('g:i A') . ').';
            $linkHash = $link->kind === 'child' ? '#provider-day' : '#timesheets';
            $rows = [];
            foreach ($people as $p) {
                $rows[] = ['user_id' => (int) $p->id, 'type' => $link->kind === 'child' ? 'attendance' : 'clock',
                    'title' => $link->kind === 'child' ? 'Pick-up time entered by a parent' : 'Clock-out time entered',
                    'body' => mb_substr($bellBody, 0, 1000), 'data' => json_encode(['link' => $linkHash])];
            }
            if ($rows) {
                Notify::write($rows);
            }
            $done = [];
            foreach ($people as $p) {
                $em = mb_strtolower(trim((string) $p->email));
                if (! filter_var($em, FILTER_VALIDATE_EMAIL) || isset($done[$em])) {
                    continue;
                }
                $done[$em] = true;
                try {
                    AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to($em)->subject($subject));
                } catch (\Throwable $ex) {
                    Log::warning('Time-fix staff notice failed', ['to' => $em, 'error' => $ex->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Time-fix staff notices failed', ['error' => $e->getMessage()]);
        }
    }
}
