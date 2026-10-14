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
 * The call goes through VoiceController::callOne, so every gate a call has still applies
 * (do-not-contact, the agency's voice switch, the person's own "no calls", consent, handset
 * de-duplication). Guardians are called one at a time, primary first; when a call ends
 * without a key pressed, the next guardian is rung.
 */
final class SafeArrivalCall
{
    public const CATEGORY = 'safe_arrival';

    public const RESPONSES = ['1' => 'absent', '2' => 'on_the_way', '3' => 'dropped_off'];

    /** Ring the next guardian who has not been called for this check. Returns true if a call went out. */
    public static function callNext(object $check, ?int $startedBy = null): bool
    {
        if ($check->resolved_at || ($check->parent_response ?? null)) return false;
        $child = DB::table('children')->where('id', $check->child_id)->first(['id', 'first_name', 'preferred_name', 'family_id']);
        if (! $child) return false;

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

        $agencyId = (int) $check->agency_id;
        $voice = app(\App\Http\Controllers\Api\VoiceController::class);
        foreach ($guardians as $g) {
            if (in_array((int) $g->id, $called, true)) continue;
            $ok = $voice->callOne($agencyId, (int) $g->id, (string) $g->phone, self::script($check, $child),
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

    /** What the parent hears. Said slowly enough to follow on a car speaker. */
    public static function script(object $check, object $child): string
    {
        $name = $child->preferred_name ?: $child->first_name;
        $centre = (string) DB::table('centres')->where('id', $check->centre_id)->value('name');
        $tz = AgencyTime::tz((int) $check->agency_id);
        $at = $check->expected_time ? Carbon::parse(substr((string) $check->expected_time, 0, 5), $tz)->format('g:i A') : null;

        return "Hello. This is a Safe Arrival call from {$centre}, about {$name}. "
            . "{$name} was expected" . ($at ? " at {$at}" : '') . " and has not been signed in today. "
            . "If {$name} is absent today, press 1. "
            . "If {$name} is on the way and arriving late, press 2. "
            . "If you have already dropped {$name} off at the centre, press 3.";
    }

    /** A key was pressed on the call. Records it, tells the staff, returns what to say back. */
    public static function record(object $call, string $digit): string
    {
        $resp = self::RESPONSES[$digit] ?? null;
        $check = DB::table('safe_arrival_checks')->where('id', $call->ref_id)->first();
        if (! $resp || ! $check) return 'Sorry, that was not one of the choices. Please open KiddieTrac or call the centre. Goodbye.';
        $child = DB::table('children')->where('id', $check->child_id)->first(['id', 'first_name', 'preferred_name', 'last_name', 'family_id']);
        $name = $child ? ($child->preferred_name ?: $child->first_name) : 'your child';
        $full = $child ? trim($name . ' ' . $child->last_name) : 'A child';
        $parent = DB::table('users')->where('id', $call->to_user_id)->first(['first_name', 'last_name']);
        $who = $parent ? trim($parent->first_name . ' ' . $parent->last_name) : 'A parent';
        $agencyId = (int) $check->agency_id;

        // Someone already answered for this child (another guardian, or staff): keep the first answer.
        if (($check->parent_response ?? null) || $check->resolved_at) {
            return "Thank you. {$name} has already been accounted for today. Goodbye.";
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

        return match ($resp) {
            'absent' => "Thank you. {$name} is marked absent today, and the centre has been told. Goodbye.",
            'on_the_way' => "Thank you. The centre has been told {$name} is on the way. Goodbye.",
            default => "Thank you. {$name} is not signed in, so the centre has been alerted to find and sign {$name} in right away. "
                . 'If you can, please call the centre as well. Goodbye.',
        };
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
