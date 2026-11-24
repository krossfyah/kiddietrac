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
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifier = app(CheckEventNotifier::class);

        // These are all about a parent's own children (NotifyAudience::PREF_EVENTS): staff-only accounts get none.
        $sides = NotifyAudience::sides($request);
        $out = [];
        foreach (self::EVENTS as $key => $meta) {
            $meta['for'] = NotifyAudience::PREF_EVENTS[$key] ?? 'all';
            if (! NotifyAudience::shows($meta['for'], $sides)) {
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
            'key' => 'required|string|in:' . implode(',', array_keys(self::EVENTS)),
            'email' => 'required|boolean',
            'push' => 'required|boolean',
            'sms' => 'required|boolean',
        ]);

        abort_unless(NotifyAudience::shows(NotifyAudience::PREF_EVENTS[$data['key']] ?? 'all', NotifyAudience::sides($request)),
            422, 'That kind of notification is not sent to you.');

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
