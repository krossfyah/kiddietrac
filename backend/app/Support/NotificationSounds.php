<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * NOTIFICATION TONES AND VIBRATION, PER CATEGORY (2026-10-21).
 *
 * Anthony: "make the mobile app have different tones available for chat, email,
 * announcements etc that I can choose from - have a variety that are loud and noticable
 * with variations to the vibration on my phone as well (android and ios)".
 *
 * Each person picks a tone and a vibration pattern per category (notification_sounds).
 * FcmService asks soundFor() and sends the choice with the push:
 *
 *   Android, app build >= MIN_BUILD  data-only push carrying kt_category / kt_tone /
 *                                    kt_vib; KtMessagingService posts it on channel
 *                                    channelId(), created on the phone with that tone and
 *                                    that vibration (a channel's sound is fixed at
 *                                    creation, so every combination is its own channel).
 *   Android, older build             unchanged: the kt_alerts channel. Old installs keep
 *                                    the single chime until they update.
 *   iPhone                           aps.sound = "<tone>.wav". Apple allows no custom
 *                                    vibration on a remote push; the system buzz plays.
 *   Browser push                     unchanged: browsers do not let a site choose either.
 *
 * The tone files are kt_tone_*.wav, generated for KiddieTrac (no licence attached),
 * bundled in the app (res/raw, iOS bundle) and served from /sounds/ for previews.
 */
final class NotificationSounds
{
    /** App build (versionCode) that knows the per-category channels. */
    public const MIN_BUILD = 55;

    /** App build that can download library tones it does not carry (KtSounds, 3.11.1). */
    public const REMOTE_BUILD = 56;

    /* THE LIBRARY IS A FILE, NOT CODE (2026-10-21). Anthony: "add alot more sounds".
       parent-portal/sounds/catalog.json lists every tone (id, name, group, seconds,
       bundled) and /sounds/<id>.wav is the file; the app downloads a tone it does not
       carry the first time it is needed. Adding a sound = add the .wav and a catalogue
       line - no deploy of this class, no app release. The constant below is only the
       fallback when the catalogue cannot be read: the tones every app build carries. */
    public const CATALOG = '/../parent-portal/sounds/catalog.json';

    public const BUNDLED = [
        'kt_notify'        => 'KiddieTrac classic',
        'kt_tone_chime'    => 'Chime',
        'kt_tone_triple'   => 'Triple ping',
        'kt_tone_rise'     => 'Rising',
        'kt_tone_marimba'  => 'Marimba',
        'kt_tone_doorbell' => 'Doorbell',
        'kt_tone_pager'    => 'Pager',
        'kt_tone_alarm'    => 'Alarm',
        'kt_tone_klaxon'   => 'Klaxon',
        'kt_tone_siren'    => 'Siren',
        'kt_tone_urgent'   => 'Urgent beeps',
    ];

    private static ?array $library = null;

    /** id => [name, group, seconds, bundled], from the catalogue (cached per process). */
    public static function library(): array
    {
        if (self::$library !== null) {
            return self::$library;
        }
        $out = [];
        try {
            $raw = @file_get_contents(base_path() . self::CATALOG);
            $j = $raw ? json_decode($raw, true) : null;
            foreach (($j['tones'] ?? []) as $t) {
                $id = (string) ($t['id'] ?? '');
                if (! preg_match('/^[a-z0-9_]{1,40}$/', $id)) {
                    continue;
                }
                $out[$id] = ['name' => (string) ($t['name'] ?? $id), 'group' => (string) ($t['group'] ?? 'Other'),
                    'seconds' => (float) ($t['seconds'] ?? 0), 'bundled' => (bool) ($t['bundled'] ?? false)];
            }
        } catch (\Throwable $e) {
        }
        if (! $out) {
            foreach (self::BUNDLED as $id => $name) {
                $out[$id] = ['name' => $name, 'group' => 'Tones', 'seconds' => 0, 'bundled' => true];
            }
        }

        return self::$library = $out;
    }

    public static function isTone(string $id): bool
    {
        return isset(self::library()[$id]);
    }

    /** A tone this app build can play: library tones need build 56; older builds get a bundled one. */
    public static function toneForBuild(string $tone, ?int $build, string $category): string
    {
        $lib = self::library();
        if ($build === null || $build >= self::REMOTE_BUILD || ! empty($lib[$tone]['bundled']) || isset(self::BUNDLED[$tone])) {
            return $tone;
        }
        $def = self::CATEGORIES[$category][2] ?? 'kt_notify';
        if (! isset(self::BUNDLED[$def]) && isset(self::OLD_BUILD_TONE[$category])) {
            return self::OLD_BUILD_TONE[$category];
        }

        return isset(self::BUNDLED[$def]) ? $def : 'kt_notify';
    }

    /** Android vibration patterns in ms: [wait, buzz, pause, buzz, ...]. */
    public const VIBRATIONS = [
        'off'       => ['label' => 'No vibration', 'pattern' => []],
        'short'     => ['label' => 'Short', 'pattern' => [0, 250]],
        'double'    => ['label' => 'Double tap', 'pattern' => [0, 250, 120, 250]],
        'heartbeat' => ['label' => 'Heartbeat', 'pattern' => [0, 150, 100, 150, 600, 150, 100, 150]],
        'long'      => ['label' => 'Long', 'pattern' => [0, 900, 300, 900]],
        'sos'       => ['label' => 'SOS', 'pattern' => [0, 150, 100, 150, 100, 150, 300, 450, 100, 450, 100, 450, 300, 150, 100, 150, 100, 150]],
        'insistent' => ['label' => 'Insistent', 'pattern' => [0, 900, 300, 900, 300, 1200, 300, 1200]],
    ];

    /** key => [label, hint, default tone, default vibration]. Order is the screen's order. */
    public const CATEGORIES = [
        'chat'         => ['Chats & messages', 'Messages from families, staff and the Message Centre.', 'kt_notify', 'double'],
        'nudge'        => ['👋 Nudges', 'Someone nudged you in a chat to get your attention.', 'kt_tone_urgent', 'sos'],
        'call'         => ['📞 Calls', 'Someone is calling you from a chat.', 'kt_tone_marimba', 'insistent'],
        'announcement' => ['Announcements', 'Agency and centre announcements.', 'kt_tone_chime', 'double'],
        'email'        => ['Email', 'New mail in your KiddieTrac email inbox.', 'kt_chime_soft_up', 'short'],   // a soft up-chime (2026-11-02, Anthony: "alert me via the bell with a soft chime")
        'checkin'      => ['Sign in & sign out', 'A child was signed in or out, and Safe Arrival "all clear" notices.', 'kt_tone_doorbell', 'short'],
        'safety'       => ['Safe Arrival, absences & incidents', 'A child who has not arrived, absences, incidents and walk alerts.', 'kt_tone_alarm', 'long'],
        'photos'       => ['Photos & daily moments', 'New photos, meals, naps and care updates.', 'kt_tone_marimba', 'short'],
        'billing'      => ['Invoices & payments', 'New invoices and payment updates.', 'kt_tone_rise', 'short'],
        'forms'        => ['Forms & documents', 'Forms to sign, signatures and documents.', 'kt_tone_triple', 'short'],
        'staff'        => ['Staff reminders', 'Clock in/out, time off and rewards.', 'kt_tone_pager', 'double'],
        'headcount'    => ['Headcount reminders', 'Time to count the children in your room.', 'kt_bell_school', 'heartbeat'],
        'general'      => ['Everything else', 'Any other KiddieTrac notification.', 'kt_notify', 'double'],
        'emergency'    => ['🚨 Emergency messages', 'Always rings repeatedly until answered (up to 2 minutes). You can choose the tone.', 'kt_tone_siren', 'insistent'],
    ];

    /** Bundled stand-in for a category whose default tone is a library tone, on builds that cannot download it. */
    private const OLD_BUILD_TONE = ['headcount' => 'kt_tone_doorbell', 'email' => 'kt_tone_chime'];

    /* Which category a push belongs to, by the code that sent it. One map instead of a
       tag at each of ~45 call sites: a new sender lands in "general" until it is listed
       here, and an explicit $extra['kt_category'] always wins. */
    private const SENDERS = [
        'MessageController' => 'chat', 'ChatController' => 'chat', 'TeamChatController' => 'chat',
        'WebChatController' => 'chat', 'VoiceMessageController' => 'chat', 'SmsInbound' => 'chat',
        'AnnouncementController' => 'announcement',
        'CheckEventNotifier' => 'checkin', 'PickupLoginController' => 'checkin', 'AutoSignOffNotices' => 'checkin',
        'CheckinReminderCommand' => 'checkin', 'SafeArrivalAllClear' => 'checkin',
        'SafeArrivalCommand' => 'safety', 'SafeArrivalCall' => 'safety', 'AbsenceController' => 'safety',
        'IncidentController' => 'safety', 'IncidentRemindersCommand' => 'safety', 'ParentIssueController' => 'safety',
        'SleepChecksAlertCommand' => 'safety', 'WalkController' => 'safety', 'HeadcountController' => 'safety',
        'ImmunizationRemindersCommand' => 'safety',
        'PhotoFeedController' => 'photos', 'CareController' => 'photos',
        'InvoiceController' => 'billing',
        'ManagedFormController' => 'forms', 'CountersignReminders' => 'forms', 'WithdrawalController' => 'forms',
        'ClockReminderCommand' => 'staff', 'HeadcountRemindCommand' => 'headcount', 'TimeOffReminders' => 'staff',
        'BioReminders' => 'staff', 'EducatorRewardsController' => 'staff', 'PortalTipsCommand' => 'staff',
        'DashboardExtrasController' => 'staff',
    ];

    private static array $cache = [];

    /** The category of the push being sent now, from the calling class. */
    public static function categoryFromCaller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $f) {
            $cls = $f['class'] ?? '';
            if ($cls === '' || str_ends_with($cls, 'FcmService') || str_ends_with($cls, 'NotificationSounds')) {
                continue;
            }
            $short = substr($cls, (int) strrpos($cls, '\\') + 1);
            if (isset(self::SENDERS[$short])) {
                return self::SENDERS[$short];
            }
        }

        return 'general';
    }

    /** This person's choice per category, defaults filled in. */
    public static function forUser(int $userId): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }
        $saved = [];
        try {
            foreach (DB::table('notification_sounds')->where('user_id', $userId)->get(['category', 'tone', 'vibration']) as $r) {
                $saved[$r->category] = $r;
            }
        } catch (\Throwable $e) {
            // Table missing or unreachable: everyone gets the defaults.
        }
        $out = [];
        foreach (self::CATEGORIES as $key => [$label, $hint, $tone, $vib]) {
            $s = $saved[$key] ?? null;
            $out[$key] = [
                'tone' => ($s && self::isTone((string) $s->tone)) ? $s->tone : $tone,
                'vibration' => ($s && isset(self::VIBRATIONS[$s->vibration])) ? $s->vibration : $vib,
                'custom' => (bool) $s,
            ];
        }
        // Emergency always repeats until answered: the vibration is not optional there.
        $out['emergency']['vibration'] = 'insistent';

        return self::$cache[$userId] = $out;
    }

    public static function forget(int $userId): void
    {
        unset(self::$cache[$userId]);
    }

    /**
     * Everything a push needs for this person and category.
     *
     * @return array{category:string, tone:string, vibration:string, channel:string, pattern:int[]}
     */
    public static function soundFor(int $userId, string $category): array
    {
        if (! isset(self::CATEGORIES[$category])) {
            $category = 'general';
        }
        $c = self::forUser($userId)[$category];

        return [
            'category' => $category,
            'tone' => $c['tone'],
            'vibration' => $c['vibration'],
            'channel' => self::channelId($category, $c['tone'], $c['vibration']),
            'pattern' => self::VIBRATIONS[$c['vibration']]['pattern'],
        ];
    }

    /** "kt2_chat_kt_tone_siren_long": one Android channel per combination. */
    public static function channelId(string $category, string $tone, string $vibration): string
    {
        return 'kt2_' . $category . '_' . $tone . '_' . $vibration;
    }

    /** The screen's catalogue. */
    public static function catalogue(): array
    {
        $cats = [];
        foreach (self::CATEGORIES as $key => [$label, $hint, $tone, $vib]) {
            $cats[] = ['key' => $key, 'label' => $label, 'hint' => $hint, 'default_tone' => $tone, 'default_vibration' => $vib,
                'vibration_locked' => $key === 'emergency'];
        }
        $tones = [];
        $groups = [];
        foreach (self::library() as $k => $t) {
            $tones[] = ['key' => $k, 'label' => $t['name'], 'group' => $t['group'], 'seconds' => $t['seconds'],
                'bundled' => $t['bundled'], 'url' => '/sounds/' . $k . '.wav'];
            $groups[$t['group']] = true;
        }
        $vibs = [];
        foreach (self::VIBRATIONS as $k => $v) {
            $vibs[] = ['key' => $k, 'label' => $v['label'], 'pattern' => $v['pattern']];
        }

        return ['categories' => $cats, 'tones' => $tones, 'groups' => array_keys($groups), 'vibrations' => $vibs,
            'remote_build' => self::REMOTE_BUILD];
    }
}
