<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BroadcastAudience;
use App\Support\ContactCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * My profile → "Texts and phone calls" (2026-09-29). Which kinds of texts and calls
 * the signed-in person wants, among the kinds their agency sends. See
 * App\Support\ContactCategories. Always the caller's own settings, never anyone else's.
 */
class ContactPrefsController extends Controller
{
    /** The agency whose list to show: the selected one if the caller belongs to it, else their first. */
    private function agencyFor(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $h = (int) $request->header('X-Active-Agency-Id');
        if ($h && DB::table('role_assignments')->where('user_id', $uid)->where('agency_id', $h)->where('active', true)->exists()) {
            return $h;
        }

        return (int) DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereNotNull('agency_id')->orderBy('id')->value('agency_id');
    }

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $uid = (int) $u->id;
        $agencyId = $this->agencyFor($request);
        $smsOn = $agencyId ? ContactCategories::smsAllowed($agencyId) : [];
        $voiceOn = $agencyId ? \App\Http\Controllers\Api\VoiceController::allowedCategories($agencyId) : [];

        $sms = [];
        foreach (ContactCategories::SMS as $k => [$label, $hint]) {
            if (! in_array($k, $smsOn, true)) {
                continue;
            }
            $sms[] = ['key' => $k, 'label' => $label, 'hint' => $hint, 'on' => ContactCategories::userWants($uid, 'sms', $k)];
        }
        $voice = [];
        foreach (ContactCategories::voice() as $k => $label) {
            if (! in_array($k, $voiceOn, true)) {
                continue;
            }
            $voice[] = ['key' => $k, 'label' => $label, 'urgent' => BroadcastAudience::isEmergency($k),
                'on' => ContactCategories::userWants($uid, 'voice', $k)];
        }

        $row = DB::table('users')->where('id', $uid)->first(['phone', 'sms_opt_in', 'voice_opt_out']);

        return response()->json([
            'has_phone' => trim((string) ($row->phone ?? '')) !== '',
            'sms_opted_in' => (int) ($row->sms_opt_in ?? 0) === 1,
            'voice_opt_out' => (int) ($row->voice_opt_out ?? 0) === 1,
            'agency_voice_enabled' => (bool) ($agencyId ? DB::table('agencies')->where('id', $agencyId)->value('voice_enabled') : false),
            'sms' => $sms,
            'voice' => $voice,
        ]);
    }

    /** PUT /me/contact-prefs {channel: sms|voice, key, on} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => 'required|string|in:sms,voice',
            'key' => 'required|string|max:40',
            'on' => 'required|boolean',
        ]);
        $valid = $data['channel'] === 'sms' ? array_keys(ContactCategories::SMS) : array_keys(ContactCategories::voice());
        abort_unless(in_array($data['key'], $valid, true), 422, 'Unknown kind of message.');

        ContactCategories::setUserWants((int) $request->user()->id, $data['channel'], $data['key'], (bool) $data['on']);
        $this->audit($request, 'contact_prefs.' . $data['channel'], ['key' => $data['key'], 'on' => (bool) $data['on']]);

        return response()->json(['ok' => true]);
    }

    /** PUT /me/voice-opt-out {opt_out} — "Don't phone me at all". Beats every reason, emergencies included. */
    public function voiceOptOut(Request $request): JsonResponse
    {
        $data = $request->validate(['opt_out' => 'required|boolean']);
        DB::table('users')->where('id', $request->user()->id)
            ->update(['voice_opt_out' => $data['opt_out'] ? 1 : 0, 'updated_at' => now()]);
        $this->audit($request, 'contact_prefs.voice_opt_out', ['opt_out' => (bool) $data['opt_out']]);

        return response()->json(['ok' => true]);
    }

    private function audit(Request $request, string $action, array $payload): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'agency_id' => $this->agencyFor($request) ?: null,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => (int) $request->user()->id,
                'payload' => json_encode($payload),
            ]);
        } catch (\Throwable $e) {
            // Auditing never blocks a person changing their own settings.
        }
    }
}
