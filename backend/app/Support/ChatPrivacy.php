<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHAT A CHAT MESSAGE SAYS STAYS INSIDE THE SIGNED-IN APP (2026-11-27, Anthony's choice).
 *
 *   1. At rest: message text is sealed by the database connection (App\Support\Sensitive::COLUMNS:
 *      messages, staff_messages, translations, reported-message excerpts). A bell row that copies a
 *      message is sealed too (Notify::write, rows flagged `kt_private`), so a database copy or backup
 *      shows ciphertext where a parent's or a colleague's words used to be.
 *   2. Lock screens: a phone or browser push names WHO wrote, never WHAT. Title "New message from
 *      Sarah", body "Tap to open and read it." (Android, iOS and web push alike, payload and data).
 *      A 🚨 emergency still rings exactly as before (EmergencyMessage) and still says it is urgent.
 *   3. Email: the unread-message emails list who wrote, when and in which conversation - no text,
 *      no attachment names - with the Open conversation button.
 *
 * Inside the app (chat screens, the bell, Chat audit) everything reads as before: the connection
 * decrypts on the way out. Search inside messages is done by ChatPrivacy::matches() over rows the
 * caller can already see (see ChatAuditController), because SQL cannot LIKE ciphertext.
 */
final class ChatPrivacy
{
    /** data key on a notifications row: "this row's body is somebody's message" (sealed, never pushed). */
    public const FLAG = 'kt_private';

    /** Bell types that may copy a message's text; the sweep seals their bodies (EncryptSensitiveCommand). */
    public const BELL_TYPES = ['chat', 'message', 'team_message', 'staff_message'];

    private const T = [
        'en' => ['from' => 'New message from %s', 'new' => 'New message', 'body' => 'Tap to open and read it.',
                 'urgent' => 'Urgent message. Tap to open it now.', 'centre' => 'your centre'],
        'fr' => ['from' => 'Nouveau message de %s', 'new' => 'Nouveau message', 'body' => 'Touchez pour l’ouvrir et le lire.',
                 'urgent' => 'Message urgent. Touchez pour l’ouvrir maintenant.', 'centre' => 'votre service de garde'],
        'es' => ['from' => 'Nuevo mensaje de %s', 'new' => 'Nuevo mensaje', 'body' => 'Toca para abrirlo y leerlo.',
                 'urgent' => 'Mensaje urgente. Toca para abrirlo ahora.', 'centre' => 'tu centro'],
        'hi' => ['from' => '%s का नया संदेश', 'new' => 'नया संदेश', 'body' => 'खोलकर पढ़ने के लिए टैप करें।',
                 'urgent' => 'ज़रूरी संदेश। अभी खोलने के लिए टैप करें।', 'centre' => 'आपका केंद्र'],
    ];

    private static function lang(?int $userId): string
    {
        try {
            $l = $userId ? (string) (MessageTranslator::forUser($userId) ?? '') : '';
        } catch (\Throwable $e) {
            $l = '';
        }
        return isset(self::T[$l]) ? $l : 'en';
    }

    private static function t(?int $userId, string $k): string
    {
        return self::T[self::lang($userId)][$k];
    }

    /** "Sarah" from "Sarah Anne Lee"; '' when there is nothing. */
    public static function first(?string $name): string
    {
        $n = trim((string) $name);
        return $n === '' ? '' : (string) strtok($n, ' ');
    }

    /** The lock-screen title: who, in the reader's language. $who is a first name (or the home as a family sees it). */
    public static function title(?int $userId, ?string $who, bool $emergency = false): string
    {
        $who = trim((string) $who);
        if ($emergency) {
            return EmergencyMessage::title($who !== '' ? $who : self::t($userId, 'centre'));
        }
        return $who !== '' ? sprintf(self::t($userId, 'from'), $who) : self::t($userId, 'new');
    }

    /** The lock-screen body: never the message. */
    public static function body(?int $userId, bool $emergency = false): string
    {
        return self::t($userId, $emergency ? 'urgent' : 'body');
    }

    /**
     * Web push goes out in batches; one batch per language so each reader gets their own words.
     *
     * @param  int[]  $userIds
     * @return array<string,int[]>  lang => ids
     */
    public static function byLanguage(array $userIds): array
    {
        $out = [];
        foreach ($userIds as $id) {
            $out[self::lang((int) $id)][] = (int) $id;
        }
        return $out;
    }

    /** A web-push payload for these readers with the text taken out. */
    public static function webPayload(array $payload, ?int $sampleUserId, ?string $who, bool $emergency = false): array
    {
        $payload['title'] = self::title($sampleUserId, $who, $emergency);
        $payload['body'] = self::body($sampleUserId, $emergency);
        return $payload;
    }

    /** Send one web push per language: $send(int[] $ids, string $title, string $body). */
    public static function webByLanguage(array $userIds, ?string $who, bool $emergency, callable $send): void
    {
        foreach (self::byLanguage(array_values(array_unique(array_map('intval', $userIds)))) as $ids) {
            $send($ids, self::title($ids[0], $who, $emergency), self::body($ids[0], $emergency));
        }
    }

    /** Mark a notifications row's data as private (body sealed, push generic). Accepts array or JSON. */
    public static function flag($data, ?string $pushWho = null)
    {
        $arr = is_array($data) ? $data : (is_string($data) && $data !== '' ? (json_decode($data, true) ?: []) : []);
        $arr[self::FLAG] = 1;
        if ($pushWho !== null && trim($pushWho) !== '') {
            $arr['kt_push_who'] = trim($pushWho);
        }
        return is_array($data) ? $arr : json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function isFlagged($data): bool
    {
        $arr = is_array($data) ? $data : (is_string($data) && $data !== '' ? (json_decode($data, true) ?: []) : []);
        return ! empty($arr[self::FLAG]);
    }

    /**
     * SEARCH WITHOUT PLAINTEXT. Case- and accent-insensitive "contains", on text the connection has
     * already decrypted. Used over a bounded, already-scoped set of rows (newest first).
     */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) {
                $s = preg_replace('/\p{Mn}+/u', '', $d) ?? $s;
            }
        }
        return $s;
    }

    public static function matches(?string $haystack, string $needle): bool
    {
        $n = self::normalize($needle);
        return $n !== '' && $haystack !== null && $haystack !== '' && str_contains(self::normalize($haystack), $n);
    }

    /** How many of the newest messages a body search reads per agency. Measured 2026-11-27: one decrypt ~12 µs here, so the cap is well under a second. */
    public const SEARCH_CAP = 20000;

    /** Family conversation ids in this agency whose messages contain $q. */
    public static function familyConversationsMatching(int $agencyId, string $q, int $cap = self::SEARCH_CAP): array
    {
        $hits = [];
        $rows = DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('centres as ce', 'ce.id', '=', 'c.centre_id')
            ->where('ce.agency_id', $agencyId)
            ->where('m.body', '!=', '')
            ->orderByDesc('m.id')->limit($cap)
            ->get(['m.conversation_id', 'm.body']);
        foreach ($rows as $r) {
            if (! isset($hits[(int) $r->conversation_id]) && self::matches((string) $r->body, $q)) {
                $hits[(int) $r->conversation_id] = true;
            }
        }
        return array_keys($hits);
    }

    /** Staff/private thread ids in this agency whose messages contain $q. */
    public static function staffThreadsMatching(int $agencyId, string $q, int $cap = self::SEARCH_CAP): array
    {
        $hits = [];
        $rows = DB::table('staff_messages as m')
            ->join('staff_threads as t', 't.id', '=', 'm.thread_id')
            ->where('t.agency_id', $agencyId)
            ->where('m.body', '!=', '')
            ->orderByDesc('m.id')->limit($cap)
            ->get(['m.thread_id', 'm.body']);
        foreach ($rows as $r) {
            if (! isset($hits[(int) $r->thread_id]) && self::matches((string) $r->body, $q)) {
                $hits[(int) $r->thread_id] = true;
            }
        }
        return array_keys($hits);
    }
}
