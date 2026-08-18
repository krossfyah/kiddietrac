<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Small per-user store for interface state that should follow the person, not the browser.
 *
 * Deliberately generic rather than a chat-dock endpoint: the same question ("where did I
 * leave this?") will come up for other panels, and a second single-purpose table for each
 * one is how a schema turns into a junk drawer.
 *
 * Strictly the caller's OWN row — there is no path here to read anybody else's, by id or
 * otherwise. Interface state is low-stakes, but "low-stakes" is exactly the reasoning that
 * produces a leak somewhere it matters.
 */
final class UiPrefsController extends Controller
{
    /** Keys this endpoint will store. An unknown key is ignored rather than persisted. */
    private const ALLOWED = ['chat_dock'];

    /** Guards against a client pushing something large into a shared table. */
    private const MAX_BYTES = 4000;

    private function ensureTable(): void
    {
        if (Schema::hasTable('user_ui_prefs')) return;
        Schema::create('user_ui_prefs', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('pref_key', 48);
            $t->text('value')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->unique(['user_id', 'pref_key'], 'ui_pref_once');
        });
    }

    /** GET /me/ui-prefs */
    public function show(Request $request): JsonResponse
    {
        $this->ensureTable();
        $out = [];
        foreach (DB::table('user_ui_prefs')->where('user_id', $request->user()->id)->get(['pref_key', 'value']) as $r) {
            $out[$r->pref_key] = json_decode((string) $r->value, true);
        }
        return response()->json(['prefs' => (object) $out]);
    }

    /** PUT /me/ui-prefs — merges the keys sent; anything omitted is left alone. */
    public function update(Request $request): JsonResponse
    {
        $this->ensureTable();
        $uid = (int) $request->user()->id;
        $saved = [];

        foreach (self::ALLOWED as $key) {
            if (! $request->has($key)) {
                continue;
            }
            $value = $request->input($key);
            // null means "forget this" — the client uses it when the dock is closed.
            if ($value === null) {
                DB::table('user_ui_prefs')->where(['user_id' => $uid, 'pref_key' => $key])->delete();
                $saved[] = $key;
                continue;
            }
            $encoded = json_encode($value);
            if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
                continue;
            }
            DB::table('user_ui_prefs')->updateOrInsert(
                ['user_id' => $uid, 'pref_key' => $key],
                ['value' => $encoded, 'updated_at' => now()],
            );
            $saved[] = $key;
        }

        return response()->json(['ok' => true, 'saved' => $saved]);
    }
}
