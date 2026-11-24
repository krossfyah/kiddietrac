<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE DEMO PHONE RINGS AND TEXTS (2026-10-30, Anthony: "have the ability to ring the phone for safe arrival and text
 * and receive notifications for the demo ... full user experience").
 *
 * The website's demo phone (kiddietrac.com/demo-phone.html + the portal's kt-demo-frame.js) is signed in to one of the
 * fictional Brightwood accounts (App\Support\PublicDemo). A call or a text to a demo-only person of a PUBLIC demo agency
 * never reaches a carrier: the two places every call and text pass through hand it here instead -
 *   - VoiceController::callOne  -> ring(): a voice_calls row (provider "web-demo") and a "call" event;
 *   - SmsController::sendOne    -> text(): an sms_messages row (provider "web-demo", status sent) and a "sms" event.
 * kt-demo-frame.js polls GET /demo/phone/events and the phone shows the incoming-call screen (ringtone, answer, the
 * spoken words, the keypad) or the Messages banner and thread. An answer goes back through the real code: a key on a
 * Safe Arrival call is App\Support\SafeArrivalCall::record(), exactly as the carrier's webhook would call it; a reply
 * text is App\Support\SmsInbound::record(), which files it and bridges it into the family's chat with the staff.
 *
 * Nothing here can reach anyone else: both hooks require BOTH a public demo agency and a demo-only person, and the
 * events are only ever read back by that same person's own session. kt:reset-web-demo clears it all nightly.
 */
final class WebDemoPhone
{
    public const PROVIDER = 'web-demo';

    /** Seconds before the next ring()/text() shows on the phone (a scripted scenario sets it; one use). */
    public static int $nextDelay = 0;

    public static function applies(int $agencyId, int $userId): bool
    {
        return $userId > 0 && PublicDemo::isAgency($agencyId) && PublicDemo::isUser($userId) && Schema::hasTable('web_demo_events');
    }

    public static function push(int $userId, string $kind, array $payload, int $delaySecs = 0): int
    {
        return (int) DB::table('web_demo_events')->insertGetId([
            'user_id' => $userId, 'kind' => $kind, 'payload' => json_encode($payload),
            'deliver_at' => now()->addSeconds(max(0, $delaySecs)), 'created_at' => now(),
        ]);
    }

    private static function delay(): int
    {
        $d = self::$nextDelay; self::$nextDelay = 0; return $d;
    }

    /** Who the call or text is from, as the phone shows it: the centre the person belongs to, else the agency. */
    public static function caller(int $agencyId, int $userId): array
    {
        $c = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('g.user_id', $userId)->first(['c.name', 'c.phone'])
            ?: DB::table('role_assignments as r')->join('centres as c', 'c.id', '=', 'r.centre_id')->where('r.user_id', $userId)->where('r.active', 1)->first(['c.name', 'c.phone']);
        $agency = (string) DB::table('agencies')->where('id', $agencyId)->value('name');
        $centre = $c->name ?? null;
        $line = (string) ($c->phone ?? '');
        return ['name' => $centre ?: $agency, 'agency' => $agency, 'number' => $line];
    }

    /** A call to a demo person: logged like a real one, rung on the demo phone. Returns true (placed). */
    public static function ring(int $agencyId, int $userId, string $phone, string $script, string $category, ?int $startedBy, array $refCols): bool
    {
        $rowId = DB::table('voice_calls')->insertGetId($refCols + [
            'agency_id' => $agencyId, 'to_user_id' => $userId, 'to_phone' => $phone, 'body' => $script,
            'category' => $category, 'provider' => self::PROVIDER, 'client_state' => 'kt_demo_' . bin2hex(random_bytes(10)),
            'status' => 'ringing', 'started_by_id' => $startedBy, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $digits = '';
        if (($refCols['ref_type'] ?? '') === 'safe_arrival_check') {
            $cfg = SafeArrivalCall::config($agencyId);
            $digits = '1' . (! empty($cfg['offer_late']) ? '2' : '') . (! empty($cfg['offer_dropped']) ? '3' : '');
        }
        $from = self::caller($agencyId, $userId);
        self::push($userId, 'call', [
            'call_id' => $rowId, 'from' => $from['name'], 'number' => $from['number'],
            'label' => $category === SafeArrivalCall::CATEGORY ? 'Safe Arrival' : ($category === 'emergency' ? 'Emergency' : 'Call'),
            'script' => $script, 'digits' => $digits,
        ], self::delay());
        return true;
    }

    /** A text to a demo person: logged as sent, delivered to the demo phone's Messages. Returns true (sent). */
    public static function text(int $agencyId, int $userId, string $phone, string $body, string $category, ?int $sentBy = null): bool
    {
        $row = [
            'agency_id' => $agencyId, 'direction' => 'out', 'to_user_id' => $userId, 'to_phone' => $phone, 'body' => $body,
            'category' => $category, 'provider' => self::PROVIDER, 'status' => 'sent', 'sent_at' => now(), 'created_at' => now(),
        ];
        if ($sentBy && Schema::hasColumn('sms_messages', 'sent_by_user_id')) $row['sent_by_user_id'] = $sentBy;
        $id = (int) DB::table('sms_messages')->insertGetId($row);
        $from = self::caller($agencyId, $userId);
        self::push($userId, 'sms', ['sms_id' => $id, 'from' => $from['name'], 'number' => $from['number'], 'body' => $body], self::delay());
        return true;
    }
}
