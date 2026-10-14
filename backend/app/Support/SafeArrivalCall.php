<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\FcmService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SAFE ARRIVAL PHONE CALL (2026-10-13, Anthony: "for safe arrival did we add function to call
 * parents ... the call would be a call announcing their childs name to the parent and let them
 * choose ... and it should auto log this into the childs record ... and into the calendar as
 * well and notify admin/director and educator of the parents decision").
 *
 * His choices: call only AFTER the text has gone unanswered, and three keys:
 *
 *   1  absent today      -> a real absence (child_absences, the same record "Not attending
 *                           today" makes, so it is on the calendar and the roster), check closed
 *   2  on the way, late  -> noted on the check; the child is still expected and the staff
 *                           alert is pushed back by escalate_after_minutes from the answer
 *   3  already dropped off -> NOT signed in on the parent's word: a child the parent left at
 *                           the centre who is not on the register is either unrecorded or
 *                           missing, so the room and the director get an URGENT alert to find
 *                           and sign the child in. A check-in written from a phone keypress
 *                           would put a child in the ratio count who may not be in the room.
 *
 * Whoever answered, the room's educators, the centre's directors and the agency's admins are
 * told what the parent chose.
 *
 * EVERY WORD IS THE AGENCY'S (2026-10-13, Anthony: "make this more configurable"). The
 * wording, which choices are offered, how long to wait for a key, how many times to ask and
 * whether to ring every guardian or the primary only live in
 * agencies.settings.safe_arrival_call, edited on Safe Arrival → Settings. The keys themselves
 * stay fixed (1 absent, 2 on the way, 3 dropped off): what a key MEANS is what the staff are
 * told and what is recorded, so it is not something a sentence should be able to change.
 *
 * The call goes through VoiceController::callOne, so every gate a call has still applies
 * (do-not-contact, the agency's voice switch, the person's own "no calls", consent, handset
 * de-duplication). Guardians are called one at a time, primary first; when a call ends
 * without a key pressed, the next guardian is rung.
 */
final class SafeArrivalCall
{
    public const CATEGORY = 'safe_arrival';

    public const RESPONSES = ['1' => 'absent', '2' => 'on_the_way', '3' => 'dropped_off'];

    /** The words, as shipped. {child} {child_full} {centre} {room} {time} are filled in. */
    public const DEFAULTS = [
        'intro' => 'Hello. This is a Safe Arrival call from {centre}, about {child}. {child} was expected at {time} and has not been signed in today.',
        'prompt_absent' => 'If {child} is absent today, press 1.',
        'prompt_late' => 'If {child} is on the way and arriving late, press 2.',
        'prompt_dropped' => 'If you have already dropped {child} off at the centre, press 3.',
        'reply_absent' => 'Thank you. {child} is marked absent today, and the centre has been told. Goodbye.',
        'reply_late' => 'Thank you. The centre has been told {child} is on the way. Goodbye.',
        'reply_dropped' => 'Thank you. {child} is not signed in, so the centre has been alerted to find and sign {child} in right away. If you can, please call the centre as well. Goodbye.',
        'reply_no_answer' => 'We did not get an answer. Please open KiddieTrac or call the centre. Goodbye.',
        'reply_already' => 'Thank you. {child} has already been accounted for today. Goodbye.',
        'reply_invalid' => 'Sorry, that was not one of the choices.',
        'offer_late' => true,
        'offer_dropped' => true,
        'tries' => 2,          // times the question is asked when no key is pressed
        'wait_secs' => 10,     // seconds to wait for a key after the question
        'who' => 'all',        // all | primary
    ];

    public const TEXT_KEYS = ['intro', 'prompt_absent', 'prompt_late', 'prompt_dropped', 'reply_absent', 'reply_late',
        'reply_dropped', 'reply_no_answer', 'reply_already', 'reply_invalid'];

    public const MAX_TEXT = 400;

    /** The agency's call settings, every key present and in range. */
    public static function config(int $agencyId, ?array $draft = null): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $mine = is_array($s['safe_arrival_call'] ?? null) ? $s['safe_arrival_call'] : [];

        return self::normalise(array_merge($mine, $draft ?? []));
    }

    public static function normalise(array $in): array
    {
        $out = self::DEFAULTS;
        foreach (self::TEXT_KEYS as $k) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? '')));
            if ($v !== '') $out[$k] = mb_substr($v, 0, self::MAX_TEXT);
        }
        foreach (['offer_late', 'offer_dropped'] as $k) {
            if (array_key_exists($k, $in)) $out[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($in['tries'])) $out['tries'] = max(1, min(3, (int) $in['tries']));
        if (isset($in['wait_secs'])) $out['wait_secs'] = max(5, min(30, (int) $in['wait_secs']));
        if (isset($in['who'])) $out['who'] = $in['who'] === 'primary' ? 'primary' : 'all';

        return $out;
    }

    public static function save(int $agencyId, array $in): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $cfg = self::normalise($in);
        // Store only what differs from the defaults, so improving a default reaches everyone who never changed it.
        $s['safe_arrival_call'] = array_filter($cfg, fn ($v, $k) => $v !== self::DEFAULTS[$k], ARRAY_FILTER_USE_BOTH);
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s)]);

        return self::config($agencyId);
    }

    /** The keys the parent may press. 1 is always offered. */
    public static function digits(array $cfg): string
    {
        return '1' . ($cfg['offer_late'] ? '2' : '') . ($cfg['offer_dropped'] ? '3' : '');
    }

    public static function fill(string $tpl, array $vars): string
    {
        return trim(strtr($tpl, [
            '{child}' => $vars['child'] ?? 'your child',
            '{child_full}' => $vars['child_full'] ?? ($vars['child'] ?? 'your child'),
            '{centre}' => $vars['centre'] ?? 'your child care centre',
            '{room}' => $vars['room'] ?? '',
            '{time}' => $vars['time'] ?? 'the usual time',
        ]));
    }

    /** The question the parent hears: the intro, then one sentence per offered key. */
    public static function question(array $cfg, array $vars): string
    {
        $parts = [$cfg['intro'], $cfg['prompt_absent']];
        if ($cfg['offer_late']) $parts[] = $cfg['prompt_late'];
        if ($cfg['offer_dropped']) $parts[] = $cfg['prompt_dropped'];

        return self::fill(implode(' ', $parts), $vars);
    }

    public static function reply(array $cfg, string $resp, array $vars): string
    {
        $key = ['absent' => 'reply_absent', 'on_the_way' => 'reply_late', 'dropped_off' => 'reply_dropped',
            'no_answer' => 'reply_no_answer', 'already' => 'reply_already'][$resp] ?? 'reply_no_answer';

        return self::fill($cfg[$key], $vars);
    }

    /** What a test call says, with sample names, for the agency's draft wording. */
    public static function testVars(int $agencyId): array
    {
        $centre = (string) (Live::centres(DB::table('centres'))->where('agency_id', $agencyId)->orderBy('name')->value('name') ?: 'your centre');

        return ['child' => 'Test Child', 'child_full' => 'Test Child', 'centre' => $centre, 'room' => 'Test Room', 'time' => '9:00 AM'];
    }

    /** The names and times for one check. */
    public static function vars(object $check): array
    {
        $child = DB::table('children')->where('id', $check->child_id)->first(['first_name', 'preferred_name', 'last_name']);
        $name = $child ? ($child->preferred_name ?: $child->first_name) : 'your child';
        $tz = AgencyTime::tz((int) $check->agency_id);

        return [
            'child' => $name,
            'child_full' => $child ? trim($name . ' ' . $child->last_name) : $name,
            'centre' => (string) DB::table('centres')->where('id', $check->centre_id)->value('name'),
            'room' => (string) DB::table('rooms')->where('id', $check->room_id)->value('name'),
            'time' => $check->expected_time ? Carbon::parse(substr((string) $check->expected_time, 0, 5), $tz)->format('g:i A') : 'the usual time',
        ];
    }

    /** Ring the next guardian who has not been called for this check. Returns true if a call went out. */
    public static function callNext(object $check, ?int $startedBy = null): bool
    {
        if ($check->resolved_at || ($check->parent_response ?? null)) return false;
        $child = DB::table('children')->where('id', $check->child_id)->first(['id', 'family_id']);
        if (! $child) return false;
        $agencyId = (int) $check->agency_id;
        $cfg = self::config($agencyId);

        $called = DB::table('voice_calls')->where('ref_type', 'safe_arrival_check')->where('ref_id', $check->id)
            ->pluck('to_user_id')->map(fn ($x) => (int) $x)->all();
        // One call at a time: a call still ringing or speaking is left to finish.
        $live = DB::table('voice_calls')->where('ref_type', 'safe_arrival_check')->where('ref_id', $check->id)
            ->whereIn('status', ['queued', 'ringing', 'answered'])->where('created_at', '>=', now()->subMinutes(5))->exists();
        if ($live) return false;

        $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $child->family_id)->whereNull('u.deleted_at')
            ->where('g.billing_hidden', 0)              // parents, not pick-up-only logins
            ->whereNotNull('u.phone')->where('u.phone', '!=', '')
            ->orderByDesc('g.is_primary')->orderBy('g.id')
            ->get(['u.id', 'u.phone']);
        if ($cfg['who'] === 'primary') $guardians = $guardians->take(1);

        $script = self::question($cfg, self::vars($check));
        $voice = app(\App\Http\Controllers\Api\VoiceController::class);
        foreach ($guardians as $g) {
            if (in_array((int) $g->id, $called, true)) continue;
            $ok = $voice->callOne($agencyId, (int) $g->id, (string) $g->phone, $script,
                self::CATEGORY, $startedBy, false, false, ['type' => 'safe_arrival_check', 'id' => (int) $check->id]);
            DB::table('safe_arrival_checks')->where('id', $check->id)->update([
                'calls_placed' => DB::raw('COALESCE(calls_placed,0) + 1'),
                'call_started_at' => $check->call_started_at ?? now(), 'updated_at' => now(),
            ]);
            if ($ok) return true;
            $called[] = (int) $g->id;        // refused by a gate: try the next guardian now
        }

        return false;
    }

    /** Kept for callers that only have the check and child (the question with today's wording). */
    public static function script(object $check, object $child): string
    {
        return self::question(self::config((int) $check->agency_id), self::vars($check));
    }

    /** A key was pressed on the call. Records it, tells the staff, returns what to say back. */
    public static function record(object $call, string $digit): string
    {
        $check = DB::table('safe_arrival_checks')->where('id', $call->ref_id)->first();
        if (! $check) return 'Goodbye.';
        $cfg = self::config((int) $check->agency_id);
        $vars = self::vars($check);
        $resp = self::RESPONSES[$digit] ?? null;
        if (! $resp || ! str_contains(self::digits($cfg), $digit)) {
            return self::fill($cfg['reply_invalid'], $vars) . ' ' . self::reply($cfg, 'no_answer', $vars);
        }
        $name = $vars['child'];
        $full = $vars['child_full'];
        $parent = DB::table('users')->where('id', $call->to_user_id)->first(['first_name', 'last_name']);
        $who = $parent ? trim($parent->first_name . ' ' . $parent->last_name) : 'A parent';
        $agencyId = (int) $check->agency_id;

        // Someone already answered for this child (another guardian, or staff): keep the first answer.
        if (($check->parent_response ?? null) || $check->resolved_at) {
            return self::reply($cfg, 'already', $vars);
        }

        $upd = ['parent_response' => $resp, 'parent_response_at' => now(), 'parent_response_by_id' => $call->to_user_id, 'updated_at' => now()];
        if ($resp === 'absent') {
            DB::table('child_absences')->updateOrInsert(
                ['child_id' => $check->child_id, 'absent_on' => $check->check_date],
                ['reason' => 'other', 'note' => 'Reported by ' . $who . ' on the Safe Arrival phone call (pressed 1: absent today).',
                    'reported_by_id' => $call->to_user_id, 'created_at' => now()]
            );
            $upd += ['status' => 'absent', 'resolution' => 'absent', 'resolved_at' => now(), 'resolved_by_id' => $call->to_user_id,
                'note' => $who . ' answered the Safe Arrival call: absent today.'];
        } elseif ($resp === 'on_the_way') {
            $upd += ['note' => $who . ' answered the Safe Arrival call: on the way, arriving late.'];
        } else {
            $upd += ['status' => 'escalated', 'escalated_at' => $check->escalated_at ?? now(),
                'note' => $who . ' answered the Safe Arrival call: already dropped off — but ' . $name . ' is not signed in.'];
        }
        DB::table('safe_arrival_checks')->where('id', $check->id)->update($upd);
        DB::table('voice_calls')->where('id', $call->id)->update(['dtmf' => $digit, 'updated_at' => now()]);

        try {
            Audit::write([
                'user_id' => $call->to_user_id, 'agency_id' => $agencyId,
                'action' => 'safe_arrival.parent_answered', 'entity_type' => 'child', 'entity_id' => $check->child_id,
                'payload' => json_encode(['check_id' => $check->id, 'digit' => $digit, 'response' => $resp, 'voice_call_id' => $call->id,
                    'summary' => $who . ' answered the Safe Arrival call for ' . $full . ': ' . self::label($resp) . '.']),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
        self::tellStaff($check, $full, $who, $resp);

        return self::reply($cfg, $resp, $vars);
    }

    public static function label(string $resp): string
    {
        return match ($resp) {
            'absent' => 'absent today',
            'on_the_way' => 'on the way, arriving late',
            'dropped_off' => 'already dropped off (but not signed in)',
            default => $resp,
        };
    }

    /** Room educators, the centre's directors and the agency's admins: bell + push, email if push misses. */
    private static function tellStaff(object $check, string $child, string $who, string $resp): void
    {
        try {
            $agencyId = (int) $check->agency_id;
            $ids = DB::table('educator_rooms as er')->join('users as u', 'u.id', '=', 'er.user_id')
                ->where('er.room_id', $check->room_id)->whereNull('u.deleted_at')->where('u.status', 'active')->pluck('u.id')->all();
            $ids = array_merge($ids,
                DB::table('role_assignments')->where('active', 1)->where('role', 'centre_director')->where('centre_id', $check->centre_id)->pluck('user_id')->all(),
                DB::table('role_assignments')->where('active', 1)->where('role', 'agency_admin')->where('agency_id', $agencyId)->pluck('user_id')->all());
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (! $ids) return;

            $urgent = $resp === 'dropped_off';
            $title = match ($resp) {
                'absent' => "Safe Arrival: {$child} is absent today",
                'on_the_way' => "Safe Arrival: {$child} is on the way",
                default => "🚨 Safe Arrival: {$child} dropped off but NOT signed in",
            };
            $body = $who . ' answered the Safe Arrival phone call: ' . self::label($resp) . '.'
                . match ($resp) {
                    'absent' => ' The absence is recorded.',
                    'on_the_way' => ' Still expected — sign them in when they arrive.',
                    default => ' Find the child now and sign them in. If they cannot be found, follow your missing-child procedure.',
                };
            foreach ($ids as $uid) {
                $res = [];
                try {
                    Notify::write(['user_id' => $uid, 'type' => 'safe_arrival', 'title' => $title, 'body' => $body,
                        'data' => json_encode(['link' => '#safe-arrival', 'child_id' => $check->child_id]), 'created_at' => now()]);
                    $res = app(FcmService::class)->sendToUser($uid, $title, $body, '#safe-arrival');
                } catch (\Throwable $e) {
                }
                if (! $urgent || (int) ($res['sent'] ?? 0) > 0) continue;
                // An urgent one that reached no phone goes by email too.
                $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name', 'email']);
                if (! $u || ! $u->email) continue;
                $html = \App\Services\EmailTemplate::wrap($agencyId,
                    '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($body) . '</p>',
                    ['eyebrow' => 'SAFE ARRIVAL', 'title' => $title, 'subtitle' => 'Attendance', 'preheader' => $body]);
                $to = (string) $u->email; $toName = trim($u->first_name . ' ' . $u->last_name);
                dispatch(function () use ($agencyId, $to, $toName, $html, $title) {
                    \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $toName, $title) {
                        $m->to($to, $toName ?: null)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($title);
                    });
                })->onQueue('mail');
            }
        } catch (\Throwable $e) {
            Log::warning('Safe Arrival call: staff notice failed', ['check' => $check->id, 'error' => $e->getMessage()]);
        }
    }
}
