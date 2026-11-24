<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use App\Support\Telnyx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Platform -> Phone & voicemail (2026-09-29), platform admins only.
 *
 * The toll-free line (1-855-400-9996) answers with a TeXML script stored in Telnyx's
 * media library as KiddieTrac.xml. It was a hand-edited file with no voice set, so
 * changing the voice or wording needed a developer. Anthony asked to change the voice
 * himself and to see how many voicemails come in, get emailed or get deleted.
 *
 * Saving here rebuilds the script from these settings and uploads it to Telnyx, then
 * downloads it back to confirm. The settings are only stored once the upload is
 * confirmed, so the screen never claims a greeting the phone isn't playing. The script
 * stays hosted at Telnyx on purpose: calls are still answered if our server is slow.
 */
class PhoneLineController extends Controller
{
    private const MEDIA = 'KiddieTrac.xml';
    private const LINE_AGENCY = 2;          // the Telnyx account that owns the toll-free number
    public const NUMBER = '1-855-400-9996';
    public const INBOX = 'info@kiddietrac.com';

    public const DEFAULTS = [
        'voice' => '',
        'greeting' => 'Thank you for calling KiddieTrac. Our team is available Monday to Friday, 9 a.m. to 5 p.m. Eastern time. Please leave your name, phone number and a short message after the beep, and we will get back to you within one business day.',
        'no_message' => 'We did not receive a message. Please call again, or email info at kiddietrac dot com. Goodbye.',
        'goodbye' => 'Thank you. We will be in touch soon. Goodbye.',
        'max_length' => 600,   // 10 minutes (2026-10-23, Anthony: "make voicemails longer"); was 3
    ];

    /** Current settings (falling back to what the line said before this screen existed). */
    public static function config(): array
    {
        $c = [];
        foreach (self::DEFAULTS as $k => $v) {
            $c[$k] = PlatformSettings::get('voice.voicemail.' . $k, $v);
        }
        $c['max_length'] = (int) $c['max_length'];
        return $c;
    }

    /** <Say> for a text in the configured voice. Used by the greeting and by the callback's goodbye. */
    public static function say(string $text, ?string $voice = null): string
    {
        $voice = $voice ?? (string) self::config()['voice'];
        $attr = '';
        if ($voice !== '') {
            $attr = ' voice="' . htmlspecialchars($voice, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
            // Azure voices carry their language (Azure.en-CA-ClaraNeural); Polly ignores it.
            if (preg_match('/^Azure\.([a-z]{2}-[A-Z]{2})-/', $voice, $m)) $attr .= ' language="' . $m[1] . '"';
        }
        return '<Say' . $attr . '>' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</Say>';
    }

    /** The general voicemail: greeting, recording, "no message" line. */
    public static function voicemailBlock(array $c): string
    {
        $cb = htmlspecialchars('https://api.kiddietrac.com/api/v1/voice/voicemail/' . PlatformSettings::get('voice.voicemail_token'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return self::say($c['greeting'], $c['voice']) . "\n"
            . '<Record action="' . $cb . '" method="POST" recordingStatusCallback="' . $cb . '" recordingStatusCallbackMethod="POST" maxLength="' . (int) $c['max_length'] . '" timeout="15" playBeep="true" finishOnKey="*9" />' . "\n"
            . self::say($c['no_message'], $c['voice']) . "\n";
    }

    /**
     * The hosted script Telnyx answers with. With the call menu on (2026-10-22,
     * App\Support\PhoneMenu) it first offers the departments; a caller who presses nothing
     * gets the general voicemail exactly as before.
     */
    public static function script(array $c): string
    {
        $menu = \App\Support\PhoneMenu::config();
        $hasDept = (bool) array_filter($menu['departments'], fn ($d) => $d['enabled']);
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n<Response>\n"
            . ($menu['enabled'] && $hasDept ? \App\Support\PhoneMenu::gather($menu, (string) $c['voice'], true) . "\n" : '')
            . self::voicemailBlock($c) . "</Response>\n";
    }

    /**
     * Upload the script to Telnyx and confirm it is what Telnyx now serves. Null when it is
     * live; otherwise the reason, in words. The previous live copy is kept as a backup.
     */
    public static function publish(array $c): ?string
    {
        $key = Telnyx::apiKey(self::LINE_AGENCY);
        if ($key === '') return 'The Telnyx account for the phone line is not connected.';
        $xml = self::script($c);
        $url = 'https://api.telnyx.com/v2/media/' . self::MEDIA;
        try {
            $old = Http::withToken($key)->timeout(20)->get($url . '/download');
            if ($old->successful() && strlen($old->body()) > 50) {
                Storage::put('voice/KiddieTrac.xml.bak-' . now()->format('Ymd-His'), $old->body());
            }
        } catch (\Throwable $e) {
        }
        try {
            $r = Http::withToken($key)->timeout(30)->attach('media', $xml, self::MEDIA, ['Content-Type' => 'application/xml'])->put($url);
            if (! $r->successful()) {
                return 'Telnyx did not accept the new script (HTTP ' . $r->status() . '). The line still plays the previous one.';
            }
            $live = Http::withToken($key)->timeout(20)->get($url . '/download')->body();
        } catch (\Throwable $e) {
            return 'Telnyx could not be reached just now. The line still plays the previous script.';
        }
        if (trim($live) !== trim($xml)) {
            return 'Telnyx accepted the upload but is serving something different. Check again in a minute.';
        }
        Storage::put('voice/KiddieTrac.xml', $xml);
        return null;
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => self::config(),
            'voices' => $this->voices(),
            'number' => self::NUMBER,
            'inbox' => self::INBOX,
            'published_at' => PlatformSettings::get('voice.voicemail.published_at'),
            'published_by' => PlatformSettings::get('voice.voicemail.published_by'),
        ]);
    }

    /** GET /platform/phone/voicemail-reply — the text a caller gets after leaving a voicemail. */
    public function replyShow(): JsonResponse
    {
        $c = \App\Support\VoicemailReply::config();
        $c['preview_sales'] = \App\Support\VoicemailReply::render($c['text'], 'Sales');
        $c['preview_general'] = \App\Support\VoicemailReply::render($c['text'], null);
        $c['sent_30d'] = (int) \Illuminate\Support\Facades\DB::table('sms_messages')->where('category', 'voicemail_reply')->where('created_at', '>=', now()->subDays(30))->where('status', 'sent')->count();
        return response()->json($c);
    }

    /** PUT /platform/phone/voicemail-reply {enabled, text} */
    public function replySave(Request $request): JsonResponse
    {
        $d = $request->validate(['enabled' => ['required', 'boolean'], 'text' => ['nullable', 'string', 'max:480']]);
        \App\Support\VoicemailReply::save((bool) $d['enabled'], $d['text'] ?? null);
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'voice.voicemail_reply',
                'entity_type' => 'phone_line', 'entity_id' => null,
                'payload' => json_encode(['summary' => 'Voicemail reply text ' . ($d['enabled'] ? 'on' : 'off')]), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
        return $this->replyShow();
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'voice' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\-]*$/'],
            'greeting' => ['required', 'string', 'min:10', 'max:1000'],
            'no_message' => ['required', 'string', 'min:5', 'max:500'],
            'goodbye' => ['required', 'string', 'min:3', 'max:300'],
            'max_length' => ['required', 'integer', 'min:30', 'max:1800'],
        ]);
        $data['voice'] = (string) ($data['voice'] ?? '');
        $ids = array_column($this->voices(), 'id');
        if (count($ids) > 3 && ! in_array($data['voice'], $ids, true)) {
            return response()->json(['message' => 'That voice is not in Telnyx\'s list. Pick one from the menu.'], 422);
        }

        // Keeps the previous live copy, uploads, and confirms Telnyx serves the new one.
        $err = self::publish($data);
        if ($err !== null) {
            return response()->json(['message' => $err], 502);
        }

        foreach (['voice', 'greeting', 'no_message', 'goodbye', 'max_length'] as $k) {
            PlatformSettings::set('voice.voicemail.' . $k, (string) $data[$k]);
        }
        $who = trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? ''));
        PlatformSettings::set('voice.voicemail.published_at', now()->toIso8601String());
        PlatformSettings::set('voice.voicemail.published_by', $who);
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'voice.greeting_published',
                'entity_type' => 'phone_line', 'entity_id' => null,
                'payload' => json_encode(['summary' => 'Voicemail greeting published (voice: ' . ($data['voice'] ?: 'Telnyx default') . ', max ' . $data['max_length'] . 's)']),
                'created_at' => now()]);
        } catch (\Throwable $e) {
        }

        return $this->show();
    }

    /** Counts for a period plus the latest voicemails. ?days=7|30|90|365|all */
    public function voicemails(Request $request): JsonResponse
    {
        $days = $request->query('days', '30');
        $base = fn () => DB::table('voicemails')->when($days !== 'all', fn ($q) => $q->where('voicemails.created_at', '>=', now()->subDays(max(1, (int) $days))));
        $stats = [
            'received' => $base()->count(),
            'emailed' => $base()->where('email_status', 'sent')->count(),
            'email_failed' => $base()->where('email_status', 'failed')->count(),
            'recording_missing' => $base()->whereNull('stored_path')->whereNull('deleted_at')->count(),
            'listened' => $base()->whereNotNull('listened_at')->count(),
            'deleted' => $base()->whereNotNull('deleted_at')->count(),
            'total_seconds' => (int) $base()->sum('duration'),
        ];
        $stats['unheard'] = $base()->whereNull('listened_at')->whereNull('deleted_at')->count();
        $rows = $base()->leftJoin('users as u', 'u.id', '=', 'voicemails.deleted_by')->leftJoin('users as l', 'l.id', '=', 'voicemails.listened_by')
            ->orderByDesc('voicemails.id')->limit(200)
            ->get(['voicemails.id', 'voicemails.department', 'voicemails.from_number', 'voicemails.duration', 'voicemails.email_status', 'voicemails.emailed_at', 'voicemails.stored_path',
                'voicemails.listened_at', 'voicemails.deleted_at', 'voicemails.created_at',
                'u.first_name as deleted_by_first', 'l.first_name as listened_by_first']);
        foreach ($rows as $r) {
            $r->has_audio = (bool) $r->stored_path;
            unset($r->stored_path);
        }

        return response()->json(['stats' => $stats, 'voicemails' => $rows, 'days' => $days]);
    }

    /** The recording, streamed to the portal's player. The first play marks it listened. */
    public function audio(Request $request, int $id)
    {
        $vm = DB::table('voicemails')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($vm && $vm->stored_path && Storage::exists($vm->stored_path), 404);
        if (! $vm->listened_at) {
            DB::table('voicemails')->where('id', $id)->update(['listened_at' => now(), 'listened_by' => $request->user()->id]);
        }
        $ext = pathinfo($vm->stored_path, PATHINFO_EXTENSION);
        return response(Storage::get($vm->stored_path), 200, [
            'Content-Type' => $ext === 'wav' ? 'audio/wav' : 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="voicemail-' . $id . '.' . $ext . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Delete a voicemail: the audio file here and the recording at Telnyx. The row stays
     * (caller, time, who deleted it) so the counts and the audit trail survive. The copy
     * already emailed to the inbox is not touched.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $vm = DB::table('voicemails')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($vm, 404);
        if ($vm->stored_path) Storage::delete($vm->stored_path);
        $telnyx = false;
        try {
            if ($vm->recording_sid) {
                $r = Http::withToken(Telnyx::apiKey(self::LINE_AGENCY))->timeout(15)->delete('https://api.telnyx.com/v2/recordings/' . rawurlencode($vm->recording_sid));
                $telnyx = $r->successful() || $r->status() === 404;
            }
        } catch (\Throwable $e) {
        }
        DB::table('voicemails')->where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user()->id, 'stored_path' => null, 'recording_url' => '', 'updated_at' => now()]);
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'voice.voicemail_deleted',
                'entity_type' => 'voicemail', 'entity_id' => $id,
                'payload' => json_encode(['summary' => 'Deleted voicemail #' . $id . ' from ' . ($vm->from_number ?: 'unknown caller') . ($telnyx ? '' : ' (Telnyx copy not confirmed deleted)')]),
                'created_at' => now()]);
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true, 'telnyx_deleted' => $telnyx]);
    }

    private function voices(): array
    {
        $basic = [
            ['id' => '', 'label' => 'Telnyx default', 'language' => '', 'group' => 'Basic (standard rate)'],
            ['id' => 'woman', 'label' => 'Basic female', 'language' => '', 'group' => 'Basic (standard rate)'],
            ['id' => 'man', 'label' => 'Basic male', 'language' => '', 'group' => 'Basic (standard rate)'],
        ];
        $key = Telnyx::apiKey(self::LINE_AGENCY);
        if ($key === '') return $basic;
        try {
            return array_merge($basic, VoiceController::catalogue($key));
        } catch (\Throwable $e) {
            return $basic;
        }
    }
}
