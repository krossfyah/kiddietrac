<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Your shift was closed for you" — shown once, on the next sign-in.
 *
 * Told to the PERSON rather than only recorded against them. An educator who forgets to
 * clock out has a shift closed at the agency's cut-off time, which is a guess: it is
 * almost never the minute they actually left, and it is the number their hours are counted
 * from. They should know it happened while they can still remember the day.
 *
 * Acknowledged per punch, so it is a reminder rather than a nag: dismissing it marks
 * everything up to that punch as seen, and it does not return unless it happens again.
 */
final class AutoSignOffNoticeController extends Controller
{
    private const PREF = 'auto_signoff_seen';

    private function seenUpTo(int $uid): int
    {
        if (! Schema::hasTable('user_ui_prefs')) {
            return 0;
        }
        $v = DB::table('user_ui_prefs')->where('user_id', $uid)->where('pref_key', self::PREF)->value('value');
        return (int) (json_decode((string) $v, true)['last_id'] ?? 0);
    }

    /** GET /auth/me/auto-signoff-notice */
    public function show(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! Schema::hasColumn('time_punches', 'source')) {
            return response()->json(['pending' => 0]);
        }

        $rows = DB::table('time_punches')
            ->where('user_id', $uid)->where('source', 'auto')
            ->where('id', '>', $this->seenUpTo($uid))
            // Only recent ones: a closure from three months ago is history, not a nudge,
            // and nobody can usefully correct a shift they cannot remember.
            ->whereDate('punched_in_at', '>=', Carbon::now()->subDays(30)->toDateString())
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'centre_id', 'punched_in_at', 'punched_out_at']);

        if ($rows->isEmpty()) {
            return response()->json(['pending' => 0]);
        }

        $tz = AgencyTime::tzForCentre((int) ($rows->first()->centre_id ?? 0)) ?: 'America/Toronto';

        return response()->json([
            'pending' => $rows->count(),
            'last_id' => (int) $rows->max('id'),
            'shifts' => $rows->map(fn ($r) => [
                'date' => Carbon::parse($r->punched_in_at)->timezone($tz)->format('D j M'),
                'in' => Carbon::parse($r->punched_in_at)->timezone($tz)->format('g:i A'),
                'closed_at' => $r->punched_out_at
                    ? Carbon::parse($r->punched_out_at)->timezone($tz)->format('g:i A') : null,
            ])->values(),
        ]);
    }

    /** POST /auth/me/auto-signoff-notice/ack */
    public function ack(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $lastId = (int) $request->input('last_id', 0);
        abort_if($lastId <= 0, 422, 'Nothing to acknowledge.');

        if (! Schema::hasTable('user_ui_prefs')) {
            Schema::create('user_ui_prefs', function ($t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('pref_key', 48);
                $t->text('value')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['user_id', 'pref_key'], 'ui_pref_once');
            });
        }

        DB::table('user_ui_prefs')->updateOrInsert(
            ['user_id' => $uid, 'pref_key' => self::PREF],
            ['value' => json_encode(['last_id' => $lastId]), 'updated_at' => now()],
        );

        return response()->json(['ok' => true]);
    }
}
