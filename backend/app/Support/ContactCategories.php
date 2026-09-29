<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What kinds of texts and calls there are, which ones an agency sends, and which ones
 * each person wants (2026-09-29).
 *
 * Anthony: "add categories for text messages as well. Also wire up the same for each
 * user to choose what they want for text and voice calls through their user settings
 * profile".
 *
 * TWO LAYERS, both enforced where messages leave (SmsController::sendOne,
 * VoiceController::callOne), so no sending path can step round them:
 *
 *  AGENCY  agencies.settings.sms_categories / .voice_categories: which kinds this
 *          agency sends at all. Chosen in Carrier settings.
 *  PERSON  notification_prefs rows: which of those this person wants. Chosen in
 *          My profile. Texts use the `sms` column (sign in/out keeps its own
 *          historical row, check_in_out); calls use the `voice` column on rows keyed
 *          "call:<reason>".
 *
 * Only the kinds below are optional. Consent confirmations, STOP/HELP replies and
 * test sends are not in the list and are never blocked: carriers require the first
 * two, and a test is somebody checking their own setup.
 *
 * Defaults keep today's behaviour: sign in/out texts start OFF (they always have,
 * because each costs the agency money), every other kind starts ON for anyone who
 * has agreed to texts. Calls start ON for every reason; "Don't phone me at all"
 * (users.voice_opt_out) still overrides everything.
 */
final class ContactCategories
{
    /** key => [label, hint, prefs row, default for someone who never chose]. */
    public const SMS = [
        'checkin' => ['Sign in and sign out', 'When your child is signed in or out, and who by.', 'check_in_out', false],
        'checkin_reminder' => ['Sign-in reminders', 'A reminder when a child has not been signed in, or was never signed out.', 'checkin_reminder', true],
        'announcement' => ['Announcements', 'Announcements from your agency, when they are also sent by text.', 'announcement', true],
        'broadcast' => ['Text broadcasts', 'Messages the office sends to a group, a room or everyone by text.', 'broadcast', true],
        'chat_reply' => ['Replies from staff', 'A reply to a text you sent to the agency number.', 'chat_reply', true],
    ];

    public static function voice(): array
    {
        return \App\Http\Controllers\Api\VoiceController::CATEGORIES;
    }

    private static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');

        return $raw ? (json_decode((string) $raw, true) ?: []) : [];
    }

    /** The kinds of text this agency sends (all of them until it chooses). */
    public static function smsAllowed(int $agencyId): array
    {
        $s = self::settings($agencyId);
        if (! array_key_exists('sms_categories', $s) || ! is_array($s['sms_categories'])) {
            return array_keys(self::SMS);
        }

        return array_values(array_intersect(array_keys(self::SMS), $s['sms_categories']));
    }

    public static function isOptionalSms(string $category): bool
    {
        return array_key_exists($category, self::SMS);
    }

    /** Does this person want this kind of text / call? Unknown kinds are not optional. */
    public static function userWants(int $userId, string $channel, string $category): bool
    {
        if (! $userId || ! Schema::hasTable('notification_prefs')) {
            return true;
        }
        if ($channel === 'sms') {
            if (! isset(self::SMS[$category])) {
                return true;
            }
            [, , $rowKey, $default] = self::SMS[$category];
            $v = DB::table('notification_prefs')->where('user_id', $userId)->where('event_key', $rowKey)->value('sms');

            return $v === null ? $default : (bool) $v;
        }
        if (! array_key_exists($category, self::voice())) {
            return true;
        }
        $v = DB::table('notification_prefs')->where('user_id', $userId)->where('event_key', 'call:' . $category)->value('voice');

        return $v === null ? true : (bool) $v;
    }

    public static function setUserWants(int $userId, string $channel, string $category, bool $on): void
    {
        if ($channel === 'sms') {
            $rowKey = self::SMS[$category][2];
            $exists = DB::table('notification_prefs')->where('user_id', $userId)->where('event_key', $rowKey)->exists();
            if ($exists) {
                DB::table('notification_prefs')->where('user_id', $userId)->where('event_key', $rowKey)
                    ->update(['sms' => $on, 'updated_at' => now()]);
            } else {
                // A new row keeps the channels it does not govern at their existing defaults.
                DB::table('notification_prefs')->insert(['user_id' => $userId, 'event_key' => $rowKey,
                    'email' => true, 'push' => true, 'sms' => $on, 'updated_at' => now()]);
            }

            return;
        }
        DB::table('notification_prefs')->updateOrInsert(
            ['user_id' => $userId, 'event_key' => 'call:' . $category],
            ['email' => false, 'push' => false, 'sms' => false, 'voice' => $on, 'updated_at' => now()]
        );
    }
}
