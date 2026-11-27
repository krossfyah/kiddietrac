<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A MARKETING CAMPAIGN'S NEWS POST IS ONLY FOR THE CAMPAIGN'S AUDIENCE (2026-11-27, Anthony).
 *
 * The leak: Marketing > Campaigns "Send now" (in-portal / both) wrote one announcements row scoped to the
 * whole centre or agency with audience 'parents', and every News reader shows a row to everyone in its
 * scope. So a staff-only campaign was read by every family, and a waitlist-only one by every enrolled
 * family too. Scope + audience columns cannot say "waitlist families" or "these 12 people".
 *
 * The rule: the campaign's people are resolved ONCE at send time (MarketingController::resolveAudience,
 * the same list the email goes to) and written to announcement_recipients. The row carries
 * ref_type = 'marketing_campaign'. Every reader then shows a campaign row only to a listed person.
 * FAIL CLOSED: a campaign row with no list (or no table) is shown to nobody.
 *
 * Staff who can manage the scope (they could have sent it) still see it on staff News, as for any post.
 */
class CampaignNews
{
    public const REF = 'marketing_campaign';

    /** The announcements.audience a campaign post carries (a second, coarse guard on the parent feed). */
    public static function audienceFor(?string $campaignAudience): string
    {
        return $campaignAudience === 'staff' ? 'staff' : 'parents';
    }

    public static function ready(): bool
    {
        static $ok = null;
        return $ok ??= Schema::hasTable('announcement_recipients');
    }

    /** Record who a campaign post is for. */
    public static function record(int $announcementId, array $userIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('announcement_recipients')->insertOrIgnore(array_map(fn ($u) => ['announcement_id' => $announcementId, 'user_id' => $u], $chunk));
        }
    }

    public static function isCampaign(object $a): bool
    {
        return ($a->ref_type ?? null) === self::REF;
    }

    /** Everyone a campaign post is for (empty when there is no list: nobody). */
    public static function recipients(int $announcementId): array
    {
        if (! self::ready()) return [];
        return DB::table('announcement_recipients')->where('announcement_id', $announcementId)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Limit an announcements query to rows this person may see as far as campaigns go: anything that is not a
     * campaign post, or a campaign post that lists them. $col is the announcements table name/alias.
     */
    public static function visibleTo($q, int $userId, string $col = 'announcements')
    {
        return $q->where(function ($w) use ($userId, $col) {
            $w->whereNull("$col.ref_type")->orWhere("$col.ref_type", '!=', self::REF);
            if (self::ready()) {
                $w->orWhereExists(fn ($e) => $e->from('announcement_recipients as cn_r')
                    ->whereColumn('cn_r.announcement_id', "$col.id")->where('cn_r.user_id', $userId));
            }
        });
    }

    /** Same, for "any of these people" (a family's guardians reading one daily summary). */
    public static function visibleToAny($q, array $userIds, string $col = 'announcements')
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds))) ?: [0];
        return $q->where(function ($w) use ($userIds, $col) {
            $w->whereNull("$col.ref_type")->orWhere("$col.ref_type", '!=', self::REF);
            if (self::ready()) {
                $w->orWhereExists(fn ($e) => $e->from('announcement_recipients as cn_r')
                    ->whereColumn('cn_r.announcement_id', "$col.id")->whereIn('cn_r.user_id', $userIds));
            }
        });
    }

    /** The parent-feed audience guard: only posts meant for families (NULL = an old row, which meant parents). */
    public static function familyAudience($q, string $col = 'announcements')
    {
        return $q->where(fn ($w) => $w->whereNull("$col.audience")->orWhereIn("$col.audience", ['parents', 'all']));
    }
}
