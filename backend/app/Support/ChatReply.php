<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REPLY TO ONE MESSAGE (2026-11-01, Anthony: "how do you reply to that specific message if there are
 * multiple messages - similiar to how whatsap behaves").
 *
 * One place that decides what a reply may quote and what a quote says, for all three message tables:
 * family chats (messages), colleague / parent threads (staff_messages) and website chats
 * (web_chat_messages).
 *
 * P0, DATA LEAKAGE: a reply may only point at a message in the SAME conversation. The caller has already
 * checked that the person may see that conversation (and so its agency); holding every quote to that one
 * conversation id is what stops a quote carrying text out of another thread or another agency. Anything
 * else - another thread's id, a deleted message, junk - is dropped to null, never an error, so a stale
 * reply cannot block the message itself. Quotes are read the same way: only ids in the conversation
 * being shown are ever looked up.
 */
class ChatReply
{
    private const KINDS = [
        'family' => ['table' => 'messages',          'conv' => 'conversation_id', 'soft' => true],
        'staff'  => ['table' => 'staff_messages',    'conv' => 'thread_id',       'soft' => false],
        'web'    => ['table' => 'web_chat_messages', 'conv' => 'web_chat_id',     'soft' => false],
    ];

    private static array $has = [];

    /** Is the column there yet? (The code ships with the migration, but must not 500 if it runs first.) */
    public static function enabled(string $kind): bool
    {
        if (! isset(self::KINDS[$kind])) {
            return false;
        }
        $t = self::KINDS[$kind]['table'];
        if (! array_key_exists($t, self::$has)) {
            try { self::$has[$t] = Schema::hasColumn($t, 'reply_to_id'); } catch (\Throwable $e) { self::$has[$t] = false; }
        }
        return self::$has[$t];
    }

    /** The id to store, or null: only a live message in this same conversation. */
    public static function valid(string $kind, int $convId, $replyToId): ?int
    {
        $id = is_numeric($replyToId) ? (int) $replyToId : 0;
        if ($id <= 0 || $convId <= 0 || ! self::enabled($kind)) {
            return null;
        }
        $k = self::KINDS[$kind];
        $q = DB::table($k['table'])->where('id', $id)->where($k['conv'], $convId);
        if ($k['soft']) {
            $q->whereNull('deleted_at');
        }
        if ($kind !== 'web') {
            $q->where(function ($w) { $w->whereNull('is_system')->orWhere('is_system', false); });
        }
        return $q->exists() ? $id : null;
    }

    /**
     * id => ['id', 'author_first_name', 'snippet', 'deleted'] for the quoted ids, read from $convId only.
     * An id that is not in this conversation any more comes back as deleted (it reads "Original message").
     * $opts['visitor'] = the website visitor's name, $opts['viewer'] = 'visitor' to call them "You".
     */
    public static function quotes(string $kind, int $convId, array $ids, array $opts = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids || ! self::enabled($kind)) {
            return [];
        }
        $k = self::KINDS[$kind];
        $rows = DB::table($k['table'])->whereIn('id', $ids)->where($k['conv'], $convId)->get()->keyBy('id');

        $names = [];
        if ($kind !== 'web') {
            $uids = $rows->pluck('sender_id')->filter()->unique()->all();
            if ($uids) {
                $names = DB::table('users')->whereIn('id', $uids)->pluck('first_name', 'id')->all();
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;
            if (! $r || ($k['soft'] && ! empty($r->deleted_at))) {
                $out[$id] = ['id' => $id, 'author_first_name' => null, 'snippet' => null, 'deleted' => true];
                continue;
            }
            if ($kind === 'web') {
                $author = match ((string) $r->sender) {
                    'visitor' => ($opts['viewer'] ?? '') === 'visitor' ? 'You' : (self::first((string) ($opts['visitor'] ?? '')) ?: 'Visitor'),
                    'bot'     => 'Maya',
                    'agent'   => self::first((string) ($r->author ?? '')) ?: 'KiddieTrac team',
                    default   => 'KiddieTrac',
                };
                $snippet = self::snippet((string) $r->body, null);
            } else {
                $author = self::first((string) ($names[$r->sender_id] ?? '')) ?: 'Someone';
                $snippet = self::snippet((string) $r->body, $r->attachments ?? null);
            }
            $out[$id] = ['id' => $id, 'author_first_name' => $author, 'snippet' => $snippet, 'deleted' => false];
        }
        return $out;
    }

    /** One quote, or null. */
    public static function one(string $kind, int $convId, ?int $id, array $opts = []): ?array
    {
        if (! $id) {
            return null;
        }
        return self::quotes($kind, $convId, [$id], $opts)[$id] ?? null;
    }

    /** "Replying to Anna: “See you at 5”" - for push notifications and emails. First names only. */
    public static function line(?array $q): string
    {
        if (! $q) {
            return '';
        }
        if (! empty($q['deleted'])) {
            return 'Replying to an earlier message';
        }
        return 'Replying to ' . ($q['author_first_name'] ?: 'a message') . ': “' . $q['snippet'] . '”';
    }

    public static function snippet(string $body, $attachments): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', preg_replace('/\*\*([^*]+)\*\*/u', '$1', strip_tags($body))));
        if ($t !== '') {
            return mb_strlen($t) > 90 ? mb_substr($t, 0, 89) . '…' : $t;
        }
        $att = is_string($attachments) ? (json_decode($attachments, true) ?: []) : (array) $attachments;
        $a = $att[0] ?? null;
        if (! $a) {
            return 'Message';
        }
        $mime = (string) ($a['mime'] ?? '');
        $url = (string) ($a['url'] ?? '');
        if (str_starts_with($mime, 'audio/') || ($a['type'] ?? '') === 'audio' || preg_match('/\.(webm|mp3|m4a|ogg|wav|aac)$/i', $url)) {
            return '🎤 Voice note';
        }
        if (str_starts_with($mime, 'image/') || ($a['type'] ?? '') === 'image' || preg_match('/\.(jpe?g|png|gif|webp|bmp|heic)$/i', $url)) {
            return '📷 Photo';
        }
        return '📎 Attachment';
    }

    private static function first(string $name): string
    {
        $name = trim($name);
        return $name === '' ? '' : (preg_split('/\s+/u', $name)[0] ?? '');
    }
}
