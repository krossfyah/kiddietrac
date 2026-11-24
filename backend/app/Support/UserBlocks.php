<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHO SOMEONE HAS BLOCKED IN MESSAGES (2026-11-03, App Store Guideline 1.2 - see ContentReportController).
 *
 * A block is personal: it hides the blocked person's messages from the one who blocked them and stops those messages
 * notifying them. Nobody else's view changes, the agency still sees every message in Audit log -> Chat conversations,
 * and an EMERGENCY message always gets through - a parent who blocked an educator must still hear about their child.
 */
class UserBlocks
{
    /** @var array<int, array<int, true>> per-request cache: blocker => [blocked => true] */
    private static array $cache = [];

    public static function enabled(): bool
    {
        static $on = null;
        return $on ??= Schema::hasTable('user_blocks');
    }

    /** The people $uid has blocked, as a set. */
    public static function blockedBy(int $uid): array
    {
        if (! self::enabled() || $uid <= 0) return [];
        if (! isset(self::$cache[$uid])) {
            self::$cache[$uid] = array_fill_keys(
                DB::table('user_blocks')->where('user_id', $uid)->pluck('blocked_user_id')->map(fn ($v) => (int) $v)->all(), true);
        }
        return self::$cache[$uid];
    }

    public static function forget(int $uid): void
    {
        unset(self::$cache[$uid]);
    }

    /** Does $viewer see this message hidden? Never their own, a system line, or an emergency. */
    public static function hides(int $viewer, ?int $senderId, bool $emergency = false, bool $system = false): bool
    {
        if ($emergency || $system || ! $senderId || $senderId === $viewer) return false;
        return isset(self::blockedBy($viewer)[(int) $senderId]);
    }

    /** What a hidden message says instead, so even a screen that knows nothing about blocks shows something sensible. */
    public static function hiddenText(?string $senderName): string
    {
        $first = trim(explode(' ', trim((string) $senderName))[0] ?? '') ?: 'this person';
        return '🚫 Message hidden — you blocked ' . $first . '. Tap ⋯ to unblock.';
    }

    /**
     * Drop the recipients who blocked the sender, so the message does not ring them. An emergency goes to everyone.
     * @param  iterable<int|string>  $recipientIds
     * @return array<int>
     */
    public static function keep(int $senderId, iterable $recipientIds, bool $emergency = false): array
    {
        $ids = [];
        foreach ($recipientIds as $r) { $ids[] = (int) $r; }
        if ($emergency || ! self::enabled() || ! $ids) return $ids;
        $blockers = DB::table('user_blocks')->where('blocked_user_id', $senderId)->whereIn('user_id', $ids)
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        return $blockers ? array_values(array_diff($ids, $blockers)) : $ids;
    }
}
