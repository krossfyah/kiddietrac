<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TEXT THE CALLER BACK AFTER A VOICEMAIL (2026-10-23, Anthony: "with new voicemails - wire up
 * a text to reply saying someone from the kiddietrac sales or support team will be in touch
 * ... add this as a configuration in the settings for carrier settings").
 *
 * From the KiddieTrac line (1-855-400-9996, the Telnyx account that owns it), once per caller
 * per day, never to someone who has texted STOP to the line, never to a number that is not a
 * North American mobile-style number, and never to the line itself. {team} becomes the
 * department they reached ("sales", "support"), or "sales or support" for the general mailbox.
 * Settings: Settings → Phone & carriers → Voicemail → "Text the caller back".
 */
final class VoicemailReply
{
    private const LINE_AGENCY = 2;   // the Telnyx account that owns the toll-free number

    public const DEFAULT_TEXT = 'Hi, thanks for calling KiddieTrac! We have your voicemail, and someone from our {team} team will be in touch with you shortly, usually within one business day. If it is urgent, call us back at 1-855-400-9996. Reply STOP to opt out.';

    public static function config(): array
    {
        $on = PlatformSettings::get('voice.vm_reply.enabled', '1');
        $text = trim((string) PlatformSettings::get('voice.vm_reply.text', ''));
        return ['enabled' => (string) $on === '1', 'text' => $text !== '' ? $text : self::DEFAULT_TEXT, 'default_text' => self::DEFAULT_TEXT, 'custom' => $text !== ''];
    }

    public static function save(bool $enabled, ?string $text): array
    {
        PlatformSettings::set('voice.vm_reply.enabled', $enabled ? '1' : '0');
        $t = trim((string) $text);
        PlatformSettings::set('voice.vm_reply.text', $t === '' || $t === self::DEFAULT_TEXT ? '' : mb_substr($t, 0, 480));
        return self::config();
    }

    public static function render(string $tpl, ?string $deptName): string
    {
        $team = $deptName ? mb_strtolower($deptName) : 'sales or support';
        return trim(strtr($tpl, ['{team}' => $team, '{department}' => $deptName ?: 'KiddieTrac', '{number}' => '1-855-400-9996']));
    }

    /** Send it for this voicemail if every rule allows. Returns what happened, for the log. */
    public static function send(?string $from, ?string $deptName, int $voicemailId): string
    {
        try {
            $cfg = self::config();
            if (! $cfg['enabled']) return 'off';
            $to = Telnyx::e164((string) $from);
            if (! preg_match('/^\+1[2-9]\d{2}[2-9]\d{6}$/', $to)) return 'not a textable number';
            if (str_ends_with($to, '8554009996')) return 'own line';
            // Opted out: the last keyword this number texted the line was STOP.
            $kw = DB::table('sms_messages')->where('to_phone', $to)->whereIn('category', ['inbound_stop', 'inbound_start'])->orderByDesc('id')->value('category');
            if ($kw === 'inbound_stop') return 'opted out';
            // Once a day per caller.
            if (DB::table('sms_messages')->where('to_phone', $to)->where('category', 'voicemail_reply')->where('created_at', '>=', now()->subDay())->exists()) return 'already texted today';

            $body = self::render($cfg['text'], $deptName);
            $r = SmsGateway::deliver(self::LINE_AGENCY, $to, $body);
            DB::table('sms_messages')->insert([
                'agency_id' => self::LINE_AGENCY, 'direction' => 'out', 'to_user_id' => null, 'to_phone' => $to, 'body' => $body,
                'category' => 'voicemail_reply', 'provider' => $r['provider'] ?? null, 'provider_ref' => $r['ref'] ?? null,
                'status' => ! empty($r['ok']) ? 'sent' : 'failed', 'error' => $r['error'] ?? null, 'sent_at' => now(), 'created_at' => now(),
            ]);
            return ! empty($r['ok']) ? 'sent' : ('failed: ' . ($r['error'] ?? 'unknown'));
        } catch (\Throwable $e) {
            Log::warning('Voicemail reply text failed', ['voicemail' => $voicemailId, 'error' => $e->getMessage()]);
            return 'error';
        }
    }
}
