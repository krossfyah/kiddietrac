<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckEventNotifier;
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
    private const EVENTS = [
        CheckEventNotifier::EVENT_KEY => [
            'label' => 'Sign in and sign out',
            'hint' => 'Tell me when my child is signed in or out, and who by.',
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifier = app(CheckEventNotifier::class);

        $out = [];
        foreach (self::EVENTS as $key => $meta) {
            $out[] = array_merge(['key' => $key], $meta, $notifier->prefsFor((int) $user->id));
        }

        return response()->json(['preferences' => $out]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => 'required|string|in:' . implode(',', array_keys(self::EVENTS)),
            'email' => 'required|boolean',
            'push' => 'required|boolean',
            'sms' => 'required|boolean',
        ]);

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
