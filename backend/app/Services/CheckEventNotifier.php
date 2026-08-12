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

    public function notify(int $childId, string $eventType, ?int $byUserId, $occurredAt = null): void
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
            $title = $isIn ? "✅ {$name} arrived" : "👋 {$name} left";
            $line = "{$name} was {$verb} at " . $when->format('g:i A')
                . ($by ? " by {$by}" : '')
                . ' · ' . $child->centre_name;

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

    private function push(int $userId, string $title, string $body): void
    {
        try {
            DB::table('notifications')->insert([
                'user_id' => $userId,
                'type' => 'checkin',
                'title' => $title,
                'body' => $body,
                'data' => json_encode(['link' => '#today']),
                'created_at' => now(),
            ]);
            app(FcmService::class)->sendToUser($userId, $title, $body, '#today');
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
            AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($to, $toName, $subject) {
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
