<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AgencyRole;
use App\Support\Reminders;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reminder toasts (App\Support\Reminders, 2026-11-26).
 *
 *   GET  /me/reminders         the caller's own reminders for the next two hours, with dismissed/snoozed state
 *   POST /me/reminders/state   {keys:[...], action: dismiss|snooze, until?: ISO time (snooze, at most 24 h ahead)}
 *
 * Strictly the caller's own: the list is built from their meetings, calendar, sales items and tasks in the agency on
 * screen, and a state row belongs to the caller alone (a key they do not have simply never matches anything).
 */
final class ReminderController extends Controller
{
    use ResolvesCentreContext;

    public function index(Request $r): JsonResponse
    {
        $uid = (int) $r->user()->id;
        $aid = (int) ($this->resolveAgencyId($r) ?: 0) ?: null;
        $res = Reminders::forUser($uid, $aid, AgencyRole::names($r));
        return response()->json($res + ['now' => Carbon::now('UTC')->toIso8601String(), 'leads' => Reminders::LEADS]);
    }

    public function state(Request $r): JsonResponse
    {
        $d = $r->validate([
            'keys' => ['required', 'array', 'min:1', 'max:50'],
            'keys.*' => ['required', 'string', 'max:120', 'regex:/^[a-z]{1,6}:[A-Za-z0-9:_-]{1,90}@[0-9-]{4,12}$/'],
            'action' => ['required', 'in:dismiss,snooze'],
            'until' => ['nullable', 'date'],
        ]);
        if (! Schema::hasTable('reminder_states')) return response()->json(['ok' => false], 503);
        $uid = (int) $r->user()->id;
        $until = null;
        if ($d['action'] === 'snooze') {
            $until = isset($d['until']) ? Carbon::parse($d['until'])->utc() : Carbon::now('UTC')->addMinutes(5);
            $max = Carbon::now('UTC')->addDay();
            if ($until->gt($max)) $until = $max;
            if ($until->lt(Carbon::now('UTC'))) $until = Carbon::now('UTC')->addMinute();
        }
        $now = now();
        foreach (array_unique($d['keys']) as $k) {
            DB::table('reminder_states')->updateOrInsert(
                ['user_id' => $uid, 'item_key' => $k],
                ['state' => $d['action'] === 'dismiss' ? 'dismissed' : 'snoozed', 'snooze_until' => $until, 'updated_at' => $now, 'created_at' => $now],
            );
        }
        // Old rows are of no use once their item is over: keep the table small.
        DB::table('reminder_states')->where('user_id', $uid)->where('updated_at', '<', now()->subDays(14))->delete();
        return response()->json(['ok' => true, 'snooze_until' => $until ? $until->toIso8601String() : null]);
    }
}
