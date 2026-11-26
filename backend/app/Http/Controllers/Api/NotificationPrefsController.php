<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckEventNotifier;
use App\Support\NotifyAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A parent's own notification preferences (2026-07-13).
 *
 * Currently one event: check_in_out — told when their child is signed in or out.
 * Email and push default ON; SMS defaults OFF, because every text costs the
 * agency money and nobody should be opted into that silently.
 *
 * Always scoped to the caller: you can only read and set your own preferences.
 */
class NotificationPrefsController extends Controller
{
    public const EVENTS = [
        CheckEventNotifier::EVENT_KEY => [
            'label' => 'Sign in and sign out',
            'hint' => 'Tell me when my child is signed in or out, and who by.',
        ],
        // Instant moments (2026-10-31): in-app + phone only - an email per nap would be noise.
        \App\Support\ChildMomentNotify::PREF_MOMENTS => [
            'label' => 'Daily moments',
            'hint' => 'Meals, naps, diapers, moods, activities, medication and milestones, as soon as they are logged.',
            'channels' => ['push'],
        ],
        \App\Support\ChildMomentNotify::PREF_OBSERVATIONS => [
            'label' => 'Learning observations',
            'hint' => 'When an educator shares an observation about my child\'s learning.',
            'channels' => ['push'],
        ],
        \App\Support\ChildMomentNotify::PREF_PHOTOS => [
            'label' => 'Photos & videos',
            'hint' => 'When a new photo or video of my child is shared.',
            'channels' => ['push'],
        ],
        // Directors and admins choose how Safe Arrival reaches them (2026-11-24). Calls default off.
        \App\Support\SafeArrival::PREF_KEY => [
            'label' => 'Safe Arrival alerts',
            'hint' => 'When a child has not arrived or been picked up as expected, the parents\' answers, and the all-clear. Room educators are always told.',
            'channels' => ['push', 'email', 'sms', 'voice'],
            'roles' => ['agency_admin', 'centre_director', 'platform_admin'],   // audience: NotifyAudience::PREF_EVENTS
        ],
    ];

    /** EVENTS plus the staff topics an admin or director can switch off (StaffNotifyPrefs, 2026-11-26). */
    public static function events(): array
    {
        $all = self::EVENTS;
        foreach (\App\Support\StaffNotifyPrefs::TOPICS as $key => $t) {
            $all[$key] = ['label' => $t[0], 'hint' => $t[1], 'channels' => $t[3], 'roles' => $t[4], 'for' => 'staff'];
        }
        return $all;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifier = app(CheckEventNotifier::class);

        // These are all about a parent's own children (NotifyAudience::PREF_EVENTS): staff-only accounts get none.
        $sides = NotifyAudience::sides($request);
        $out = [];
        foreach (self::events() as $key => $meta) {
            $meta['for'] = $meta['for'] ?? (NotifyAudience::PREF_EVENTS[$key] ?? 'all');
            if (! NotifyAudience::shows($meta['for'], $sides, $meta['roles'] ?? null)) {
                continue;
            }
            if ($key === \App\Support\SafeArrival::PREF_KEY) {
                $out[] = array_merge(['key' => $key], $meta, \App\Support\SafeArrival::staffPrefs((int) $user->id));
                continue;
            }
            if (isset(\App\Support\StaffNotifyPrefs::TOPICS[$key])) {
                $p = \App\Support\StaffNotifyPrefs::prefs((int) $user->id, $key);
                $out[] = array_merge(['key' => $key], $meta, ['push' => $p['push'], 'email' => $p['email'], 'sms' => false]);
                continue;
            }
            if ($key === CheckEventNotifier::EVENT_KEY) {
                $out[] = array_merge(['key' => $key], $meta, $notifier->prefsFor((int) $user->id));
                continue;
            }
            $out[] = array_merge(['key' => $key], $meta, [
                'email' => false, 'sms' => false, 'push' => \App\Support\ChildMomentNotify::wants((int) $user->id, $key),
            ]);
        }

        return response()->json(['preferences' => $out, 'sides' => $sides]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => 'required|string|in:' . implode(',', array_keys(self::events())),
            'email' => 'required|boolean',
            'push' => 'required|boolean',
            'sms' => 'required|boolean',
            'voice' => 'sometimes|boolean',
        ]);

        $meta = self::events()[$data['key']];
        abort_unless(NotifyAudience::shows($meta['for'] ?? (NotifyAudience::PREF_EVENTS[$data['key']] ?? 'all'), NotifyAudience::sides($request), $meta['roles'] ?? null),
            422, 'That kind of notification is not sent to you.');

        if ($data['key'] === \App\Support\SafeArrival::PREF_KEY) {
            DB::table('notification_prefs')->updateOrInsert(
                ['user_id' => $request->user()->id, 'event_key' => $data['key']],
                ['email' => (bool) $data['email'], 'push' => (bool) $data['push'], 'sms' => (bool) $data['sms'],
                 'voice' => (bool) ($data['voice'] ?? false), 'updated_at' => now()]
            );
            \App\Support\SafeArrival::forgetPrefs((int) $request->user()->id);
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'action' => 'notification_prefs.safe_arrival',
                'entity_type' => 'user', 'entity_id' => $request->user()->id,
                'payload' => json_encode(['summary' => 'Safe Arrival alerts: app ' . ($data['push'] ? 'on' : 'off') . ', email ' . ($data['email'] ? 'on' : 'off')
                    . ', texts ' . ($data['sms'] ? 'on' : 'off') . ', calls ' . (! empty($data['voice']) ? 'on' : 'off')]), 'created_at' => now()]);

            return response()->json(['ok' => true]);
        }

        if (isset(\App\Support\StaffNotifyPrefs::TOPICS[$data['key']])) {
            $ch = \App\Support\StaffNotifyPrefs::TOPICS[$data['key']][3];
            $push = in_array('push', $ch, true) ? (bool) $data['push'] : true;
            $email = in_array('email', $ch, true) ? (bool) $data['email'] : true;
            DB::table('notification_prefs')->updateOrInsert(
                ['user_id' => $request->user()->id, 'event_key' => $data['key']],
                ['email' => $email, 'push' => $push, 'sms' => false, 'updated_at' => now()]
            );
            \App\Support\StaffNotifyPrefs::forget((int) $request->user()->id);
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'action' => 'notification_prefs.staff_topic',
                'entity_type' => 'user', 'entity_id' => $request->user()->id,
                'payload' => json_encode(['summary' => \App\Support\StaffNotifyPrefs::TOPICS[$data['key']][0] . ': app ' . ($push ? 'on' : 'off') . ', email ' . ($email ? 'on' : 'off'),
                    'key' => $data['key']]), 'created_at' => now()]);

            return response()->json(['ok' => true]);
        }

        if ($data['key'] !== CheckEventNotifier::EVENT_KEY) {   // in-app/phone-only categories
            $data['email'] = false;
            $data['sms'] = false;
        }
        DB::table('notification_prefs')->updateOrInsert(
            ['user_id' => $request->user()->id, 'event_key' => $data['key']],
            [
                'email' => (bool) $data['email'],
                'push' => (bool) $data['push'],
                'sms' => (bool) $data['sms'],
                'updated_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }
}
