<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tell the parents when their child is signed in or out (2026-07-13).
 *
 * Three channels, each independently switchable by the parent in their own
 * settings (notification_prefs, event_key = 'check_in_out'):
 *   • email — a short branded note
 *   • push  — the in-app / phone notification
 *   • SMS   — off by default, because it costs the agency money per message
 *
 * Every message says WHO signed the child in or out. That is the whole point of
 * the notification: "Aria was signed out at 5:10 PM by Anthony Hosein" is
 * reassuring; "Aria was signed out" is alarming.
 *
 * Nothing in here may throw: a failed notification must never roll back a
 * check-in that has already happened on the floor.
 */
class CheckEventNotifier
{
    public const EVENT_KEY = 'check_in_out';

    /** Defaults for a parent who has never touched their settings. */
    public const DEFAULTS = ['email' => true, 'push' => true, 'sms' => false];

    /**
     * @param string|null $source How the event was recorded — 'qr', 'kiosk', or null for
     *        a staff member tapping it in. "Aria arrived" and "Aria arrived, scanned at
     *        the door by her mother" answer different questions, and the second is the one
     *        asked when something looks wrong. (Anthony, 2026-08-26)
     */
    /* SIX SECONDS, STANDING IN A DOORWAY (2026-09-21).

       Cassandra's check-in took 6250ms and Eisha checked the same child in twice, six
       seconds apart - the second refused "Already checked in at 8:51 AM", with the
       browser recording that the reply never arrived at all. Three reports, one endpoint.

       The work was all here. notify() ran INSIDE the check-in request and, per guardian,
       made outbound HTTP calls that the educator had to wait for: FcmService to Google,
       then WebPushService to whatever endpoint the browser registered, then Telnyx if SMS
       is on - and Telnyx has been answering "Tollfree number is not verified" since
       mid-September, so that one is a round trip to a refusal. Two guardians is four to
       six blocking calls before the child is shown as arrived.

       An educator with a queue of families at the door taps, sees nothing happen, and
       taps again. That is where the duplicate came from.

       afterResponse(), NOT the queue. The email below already goes to the queue, and the
       queue here is a one-minute cron running --stop-when-empty: measured today, a job
       dispatched at 13:49:20 ran at 13:50:05, forty-five seconds later. That is fine for
       an email and wrong for "your child has arrived". afterResponse runs the moment the
       response has been handed to the educator's phone - measured in the SAME SECOND it
       was dispatched - so the tap is instant and the parent's push is not delayed at all.

       Console callers run it inline: a command or the queue worker has no HTTP response
       to come after, so deferring there would drop the notification entirely. */
    public function notify(int $childId, string $eventType, ?int $byUserId, $occurredAt = null, ?string $source = null): void
    {
        /* A timestamp, not "now" read later on: the deferred closure runs after the
           response and would otherwise stamp the notification a moment late. */
        $at = Carbon::parse($occurredAt ?: now())->toIso8601String();

        if (app()->runningInConsole()) {
            $this->deliver($childId, $eventType, $byUserId, $at, $source);

            return;
        }

        try {
            dispatch(function () use ($childId, $eventType, $byUserId, $at, $source) {
                app(self::class)->deliver($childId, $eventType, $byUserId, $at, $source);
            })->afterResponse();
        } catch (\Throwable $e) {
            /* If deferring is not available for any reason, send it the slow way rather
               than not at all - a late check-in notice beats a missing one. */
            $this->deliver($childId, $eventType, $byUserId, $at, $source);
        }
    }

    /** The part that talks to Google, the push endpoints and the carrier. Never in a request. */
    public function deliver(int $childId, string $eventType, ?int $byUserId, $occurredAt = null, ?string $source = null): void
    {
        try {
            $child = DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
                ->join('agencies as a', 'a.id', '=', 'ce.agency_id')
                ->where('c.id', $childId)
                ->select([
                    'c.id', 'c.first_name', 'c.preferred_name', 'c.family_id',
                    'ce.name as centre_name', 'a.id as agency_id', 'a.timezone as tz',
                ])
                ->first();

            if (! $child) return;

            $tz = $child->tz ?: 'America/Toronto';
            $when = Carbon::parse($occurredAt ?: now())->timezone($tz);
            $name = $child->preferred_name ?: $child->first_name;

            $by = $byUserId
                ? DB::table('users')->where('id', $byUserId)
                    ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")
                    ->value('n')
                : null;
            $by = $by ? trim($by) : null;

            $isIn = $eventType === 'check_in';
            $verb = $isIn ? 'signed in' : 'signed out';
            $how = $source === 'qr' ? ' · 📱 via QR code'
                 : ($source === 'kiosk' ? ' · 📱 at the kiosk' : '');
            $title = $isIn ? "✅ {$name} arrived" : "👋 {$name} left";
            if ($source === 'qr') {
                $title .= ' (QR)';
            }
            $line = "{$name} was {$verb} at " . $when->format('g:i A')
                . ($by ? " by {$by}" : '')
                . ' · ' . $child->centre_name . $how;

            $guardians = DB::table('guardians as g')
                ->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $child->family_id)
                ->whereNull('u.deleted_at')
                ->get(['u.id', 'u.email', 'u.phone', 'u.first_name', 'u.last_name']);

            foreach ($guardians as $guardian) {
                $prefs = $this->prefsFor((int) $guardian->id);

                if ($prefs['push']) {
                    $this->push((int) $guardian->id, $title, $line);
                }
                if ($prefs['email'] && $guardian->email) {
                    $this->email((int) $child->agency_id, (string) $guardian->email,
                        trim(($guardian->first_name ?? '') . ' ' . ($guardian->last_name ?? '')),
                        $title, $name, $verb, $when->format('g:i A'), $when->format('l, j F Y'),
                        $by, (string) $child->centre_name);
                }
                if ($prefs['sms'] && $guardian->phone) {
                    $this->sms((int) $child->agency_id, (int) $guardian->id, (string) $guardian->phone, $line);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Check-event notification failed', ['child' => $childId, 'error' => $e->getMessage()]);
        }
    }

    /** A parent's channel preferences, falling back to the defaults. */
    public function prefsFor(int $userId): array
    {
        if (! Schema::hasTable('notification_prefs')) return self::DEFAULTS;

        $row = DB::table('notification_prefs')
            ->where('user_id', $userId)
            ->where('event_key', self::EVENT_KEY)
            ->first();

        if (! $row) return self::DEFAULTS;

        return [
            'email' => (bool) $row->email,
            'push' => (bool) $row->push,
            'sms' => (bool) $row->sms,
        ];
    }

    /**
     * The same event, for the people working the room.
     *
     * notify() above reaches GUARDIANS only, so until now a QR scan at the door told the
     * family and nobody on staff — the educator had to be watching the roster to know a
     * child had arrived. Separate method rather than a flag inside notify(), because the
     * audience, the wording and the delivery are all different.
     */
    /* The staff-side twin of notify(), and it blocks in exactly the same way: it ends in
       push() per recipient, which is an HTTP call to Google and another to the browser's
       push endpoint. Its callers are the kiosk and the QR scanner - the two places where
       somebody is standing at a door holding a phone up to a code - so it gets the same
       treatment. Deferred past the response; run inline from the console, where there is
       no response to come after. (2026-09-21) */
    public function notifyStaff(int $childId, ?int $roomId, ?int $centreId, string $eventType, ?int $byUserId, ?string $source = null): void
    {
        if (! app()->runningInConsole()) {
            try {
                dispatch(function () use ($childId, $roomId, $centreId, $eventType, $byUserId, $source) {
                    app(self::class)->deliverStaff($childId, $roomId, $centreId, $eventType, $byUserId, $source);
                })->afterResponse();

                return;
            } catch (\Throwable $e) { /* fall through and send it inline */ }
        }

        $this->deliverStaff($childId, $roomId, $centreId, $eventType, $byUserId, $source);
    }

    /** The part that talks to Google and the push endpoints. Never in a request. */
    public function deliverStaff(int $childId, ?int $roomId, ?int $centreId, string $eventType, ?int $byUserId, ?string $source = null): void
    {
        try {
            $child = DB::table('children')->where('id', $childId)
                ->first(['first_name', 'preferred_name', 'last_name', 'primary_room_id', 'family_id']);
            if (! $child) return;

            $roomId = $roomId ?: (int) ($child->primary_room_id ?? 0);
            if (! $centreId) {
                $centreId = (int) DB::table('families')->where('id', $child->family_id)->value('centre_id');
            }
            $name = trim((string) (($child->preferred_name ?: $child->first_name) . ' ' . ($child->last_name ?? ''))) ?: 'A child';

            $by = $byUserId
                ? DB::table('users')->where('id', $byUserId)
                    ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")->value('n')
                : null;

            $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
            $tz = \App\Support\AgencyTime::tz($agencyId ?: null);
            $at = Carbon::now()->timezone($tz)->format('g:i A');

            $isIn = $eventType === 'check_in';
            $how = $source === 'qr' ? "\u{1F4F1} QR " : ($source === 'kiosk' ? "\u{1F4F1} Kiosk " : '');
            $title = $how . ($isIn ? 'check-in' : 'check-out') . " \u{2014} {$name}";
            $body = $name . ($isIn ? ' was signed in' : ' was signed out') . ' at ' . $at
                . ($source === 'qr' ? ' by QR scan' : ($source === 'kiosk' ? ' at the kiosk' : ''))
                . ($by ? ' by ' . $by : '') . '.';

            $recipients = [];
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('educator_rooms') && $roomId) {
                    $recipients = DB::table('educator_rooms as er')
                        ->join('users as u', 'u.id', '=', 'er.user_id')
                        ->where('er.room_id', $roomId)
                        ->whereNull('u.deleted_at')->where('u.status', 'active')
                        ->pluck('u.id')->all();
                }
            } catch (\Throwable $e) { /* fall through to centre staff */ }

            if ($centreId) {
                $recipients = array_merge($recipients, DB::table('role_assignments')
                    ->whereIn('role', ['centre_director', 'agency_admin'])
                    ->where('centre_id', $centreId)->where('active', 1)
                    ->pluck('user_id')->all());
            }

            foreach (array_unique(array_filter(array_map('intval', $recipients))) as $uid) {
                \App\Support\Notify::write([
                    'user_id' => $uid,
                    'type' => 'attendance',
                    'title' => $title,
                    'body' => mb_substr($body, 0, 200),
                    'data' => json_encode([
                        'link' => '#child-detail?id=' . $childId,
                        'child_id' => $childId, 'source' => $source ?: 'staff',
                        'event_type' => $eventType,
                    ]),
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            /* Announcing an arrival must never fail the arrival itself. */
            Log::warning('Staff check-event notification failed', ['child' => $childId, 'error' => $e->getMessage()]);
        }
    }

    private function push(int $userId, string $title, string $body): void
    {
        try {
            \App\Support\Notify::write([
                'user_id' => $userId,
                'type' => 'checkin',
                'title' => $title,
                'body' => $body,
                'data' => json_encode(['link' => '#today']),
                'created_at' => now(),
            ]);
            app(FcmService::class)->sendToUser($userId, $title, $body, '#today', false, false, false);   // web push sent below

            /* FcmService only queries device_tokens WHERE platform IN ('android','ios').
               Measured 2026-08-26: every one of the 9 guardians with a registered token
               has a `web` one, so the FCM call above always found no device and the
               parent was never told their child had arrived. Both transports, each in
               its own try, so a fault in one cannot silence the other. */
            try {
                app(\App\Services\WebPushService::class)->sendToUsers([$userId], [
                    'title' => $title,
                    'body'  => $body,
                    'icon'  => '/icon-192.png',
                    'url'   => '/dashboard.html#today',
                    'tag'   => 'checkin-' . $userId,
                ]);
            } catch (\Throwable $we) {
                Log::warning('Web push for check-event failed', ['user' => $userId, 'error' => $we->getMessage()]);
            }
        } catch (\Throwable $e) {
        }
    }

    private function email(int $agencyId, string $to, string $toName, string $title, string $childName,
                           string $verb, string $time, string $date, ?string $by, string $centre): void
    {
        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
            . '<strong>' . e($childName) . '</strong> was ' . e($verb) . ' at <strong>' . e($time) . '</strong>'
            . ($by ? ' by <strong>' . e($by) . '</strong>' : '') . '.</p>'
            . EmailTemplate::calloutBox(
                '<strong>Centre:</strong> ' . e($centre) . '<br>'
                . '<strong>When:</strong> ' . e($date) . ' at ' . e($time)
                . ($by ? '<br><strong>By:</strong> ' . e($by) : ''),
                'info'
            )
            . '<p style="margin:16px 0 0;font-size:12.5px;color:#64748B;line-height:1.5;">'
            . 'You can turn these alerts off, or switch on text messages, in the KiddieTrac app under Settings → Notifications.</p>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'SIGN IN / OUT',
            'title' => $title,
            'subtitle' => $date,
            'preheader' => $childName . ' was ' . $verb . ' at ' . $time . '.',
        ]);

        $subject = $title . ' — ' . $time;

        dispatch(function () use ($agencyId, $to, $toName, $html, $subject) {
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $toName, $subject) {
                $m->to($to, $toName ?: null)
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject);
            });
        })->onQueue('mail');

    }

    private function sms(int $agencyId, int $userId, string $phone, string $line): void
    {
        try {
            app(\App\Http\Controllers\Api\SmsController::class)
                ->sendOne($agencyId, $userId, $phone, $line, 'checkin');
        } catch (\Throwable $e) {
            // Twilio may not be configured for this agency — that's fine, the
            // parent still gets email and push.
        }
    }
}
