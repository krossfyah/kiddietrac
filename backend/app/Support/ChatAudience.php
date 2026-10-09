<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHO HEARS ABOUT A FAMILY CHAT MESSAGE: THE OTHER SIDE (2026-10-09).
 *
 * Anthony: "I seen Lloydene sent out a message to a parent but it got sent to other
 * admins/directors - is this a bug?" It was. Every message and every nudge went to the
 * family AND every staff member at the provider AND every agency admin — so Lloydene's
 * nudge to Victoria's mother also buzzed Anthony, Safia (twice), Sukhman, the iLearn
 * integration account and Lloydene's own second account. A family thread has two sides:
 *
 *   · staff write  → the FAMILY is told (bell, push, missed-message email)
 *   · family write → the provider's STAFF and the agency's admins are told
 *
 * Never told: the sender, the sender's other accounts (same email), or a system mailbox.
 * Used by ChatController::insertMessage and the missed-message email, so the bell and
 * the email can never disagree about who a message was for.
 */
final class ChatAudience
{
    /** Is this user a guardian of the conversation's family (i.e. writing as the family)? */
    public static function isFamilySide(object $conv, int $userId): bool
    {
        return DB::table('guardians')->where('family_id', (int) $conv->family_id)
            ->where('user_id', $userId)->exists();
    }

    /** @return int[] */
    public static function guardianIds(object $conv): array
    {
        return DB::table('guardians')->where('family_id', (int) $conv->family_id)
            ->whereNotNull('user_id')->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** Active staff at the conversation's provider, plus the agency's admins. @return int[] */
    public static function staffIds(object $conv): array
    {
        $centreId = (int) $conv->centre_id;
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');

        return DB::table('role_assignments')->where('active', true)
            ->where(function ($q) use ($centreId, $agencyId) {
                $q->where('centre_id', $centreId)
                  ->orWhere(fn ($w) => $w->where('role', 'agency_admin')->where('agency_id', $agencyId));
            })
            ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** Integration and no-reply mailboxes are not people. */
    public static function isServiceEmail(?string $email): bool
    {
        $e = mb_strtolower(trim((string) $email));

        return $e === '' || str_contains($e, 'integration+')
            || str_starts_with($e, 'noreply@') || str_starts_with($e, 'no-reply@');
    }

    /**
     * The user ids to tell about a message from $senderId in $conv.
     *
     * @return int[]
     */
    public static function recipientIds(object $conv, int $senderId): array
    {
        $ids = self::isFamilySide($conv, $senderId) ? self::staffIds($conv) : self::guardianIds($conv);
        $ids = array_values(array_diff($ids, [$senderId]));
        if (! $ids) {
            return [];
        }
        $senderEmail = mb_strtolower(trim((string) DB::table('users')->where('id', $senderId)->value('email')));

        return DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')
            ->get(['id', 'email'])
            ->reject(fn ($u) => self::isServiceEmail($u->email)
                || ($senderEmail !== '' && mb_strtolower(trim((string) $u->email)) === $senderEmail))
            ->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
    }
}
