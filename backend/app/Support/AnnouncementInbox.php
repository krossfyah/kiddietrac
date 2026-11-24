<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WHAT IS UNREAD IN THIS PERSON'S ANNOUNCEMENTS — ONE ANSWER (2026-11-09, Anthony: "anouncements has a few unread
 * announcements that I did not mark as read but the top bar doesnt show that indicator").
 *
 * The top-bar 📣 counted unread 'announcement' rows in the bell, while the Announcements screen shows read / unread
 * from announcement_user_state. Two different questions: opening the bell, or reading the alert, cleared the badge
 * while the screen still had the announcement in bold; an announcement with no alert row (sent before the person
 * joined, a personal invoice notice) was never counted at all. Now the badge, the screen and the sign-in popup all
 * read the SAME list the screen draws (indexProvider for staff, indexParent for families) and count what it calls
 * unread, so they cannot disagree.
 *
 * Unread = not read, not archived, sent, not sent by this person, in the last 30 days (the screen's own rule).
 */
final class AnnouncementInbox
{
    /** Is this request answered from a family's News (guardian) or the staff list? */
    public static function audience(Request $r): string
    {
        $view = (string) $r->header('X-View-As-Role');
        if ($view !== '') return $view === 'guardian' ? 'parent' : 'provider';
        $roles = DB::table('role_assignments')->where('user_id', (int) $r->user()->id)->where('active', true)->pluck('role')->all();
        $staff = array_intersect($roles, ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin']);

        return $staff ? 'provider' : (in_array('guardian', $roles, true) ? 'parent' : 'none');
    }

    private static function key(Request $r): string
    {
        return 'kt_ann_unread_' . (int) $r->user()->id . '_' . (int) $r->header('X-Active-Agency-Id') . '_' . (string) $r->header('X-View-As-Role');
    }

    /** Forget the cached answer (a read / archive / new announcement for this person). */
    public static function forget(int $userId): void
    {
        Cache::put('kt_ann_unread_gen_' . $userId, (int) Cache::get('kt_ann_unread_gen_' . $userId, 0) + 1, 86400);
    }

    /** The unread items, newest first, in the shape the list returns (plus `audience`). */
    public static function unread(Request $r): array
    {
        $uid = (int) $r->user()->id;
        $aud = self::audience($r);
        if ($aud === 'none') return [];
        $gen = (int) Cache::get('kt_ann_unread_gen_' . $uid, 0);

        return Cache::remember(self::key($r) . '_' . $gen, 20, function () use ($r, $uid, $aud) {
            try {
                $ctl = app(\App\Http\Controllers\Api\AnnouncementController::class);
                $d = $aud === 'parent' ? $ctl->indexParent($r)->getData(true) : $ctl->indexProvider($r)->getData(true);
                $items = $aud === 'parent' ? ($d['announcements'] ?? []) : array_merge($d['announcements'] ?? [], $d['personal'] ?? []);
                $ids = array_values(array_filter(array_map(fn ($a) => empty($a['personal']) ? (int) $a['id'] : 0, $items)));
                $byMe = $ids ? DB::table('announcements')->whereIn('id', $ids)->where('created_by_id', $uid)->pluck('id')->flip()->all() : [];
                $out = array_values(array_filter($items, fn ($a) => empty($a['read']) && empty($a['archived']) && ! empty($a['sent_at'])
                    && (! empty($a['personal']) || ! isset($byMe[(int) $a['id']]))));
                usort($out, fn ($x, $y) => strcmp((string) $y['sent_at'], (string) $x['sent_at']));

                return array_map(fn ($a) => $a + ['audience' => $aud], $out);
            } catch (\Throwable $e) {
                Log::warning('announcement inbox', ['e' => $e->getMessage()]);
                return [];
            }
        });
    }

    public static function count(Request $r): int
    {
        return count(self::unread($r));
    }
}
