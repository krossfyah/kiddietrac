<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * EVERY BADGE FROM ONE ANSWER (2026-10-30, Anthony: "app notification counters not reset on phones when opening up the
 * notifications etc - this needs to be in realtime").
 *
 * What was wrong:
 *   - Opening Notifications changed nothing on the server: the counters were "unread", and only tapping each row (or
 *     "Read all") cleared one. The bottom Inbox hid while the screen was open and came straight back on leaving.
 *   - Six scripts asked six questions every 15 s and disagreed: the bell counted every unread alert (38), the bottom
 *     Inbox counted the unread among the newest 200 (17).
 *   - Phones: pushes set the iPhone icon badge to a hard 1, and nothing ever cleared it or the delivered notifications.
 *
 * Now: a counter means "new since you last looked". Opening the inbox marks everything SEEN
 * (users.notifications_seen_id, the newest notification id at that moment - an id, because rows are stamped in two time zones); the rows stay unread - highlighted in the list - until tapped. Every badge in the
 * portal and the phone's icon read this one answer, GET /badges.
 */
final class Badges
{
    private const PHOTOS = '/photo|image|gallery|picture/';
    private const BILLING = '/invoice|billing|payment|receipt/';

    public static function seenId(int $userId): int
    {
        // No Schema::hasColumn here (2026-11-13): it cost an information_schema read on every badge poll, twice.
        return (int) DB::table('users')->where('id', $userId)->value('notifications_seen_id');
    }

    /** The person opened their inbox: everything they have now counts as seen. */
    public static function markSeen(int $userId): void
    {
        $max = (int) DB::table('notifications')->where('user_id', $userId)->max('id');
        DB::table('users')->where('id', $userId)->update(['notifications_seen_id' => $max]);
    }

    /**
     * Opening a chat reads the "new message" alerts it raised for this person (2026-10-30). Before, the messages were
     * marked read but their 💬 alert stayed unread in the inbox forever - 613 of them across the platform.
     * $key: conversation_id (family chats) or thread_id (staff / private threads), as the alerts' data carries it.
     */
    public static function readAlerts(int $userId, string $key, int $id, array $types): int
    {
        try {
            return DB::table('notifications')->where('user_id', $userId)->whereNull('read_at')->whereIn('type', $types)
                ->where(function ($q) use ($key, $id) {
                    $q->where('data', 'like', '%"' . $key . '":' . $id . ',%')->orWhere('data', 'like', '%"' . $key . '":' . $id . '}%');
                })->update(['read_at' => now()]);
        } catch (\Throwable $e) { Log::warning('badges: readAlerts', ['e' => $e->getMessage()]); return 0; }
    }

    /** Unread notifications that arrived after the person last opened the inbox. */
    private static function unseen(int $userId, ?int $seen = null)
    {
        $q = DB::table('notifications')->where('user_id', $userId)->whereNull('read_at');
        if ($seen = $seen ?? self::seenId($userId)) $q->where('id', '>', $seen);
        return $q;
    }

    /** Everything the portal's badges show, for the signed-in person. Each part fails alone. */
    public static function forRequest(Request $r): array
    {
        $uid = (int) $r->user()->id;
        $out = ['notifications' => 0, 'notifications_unread' => 0, 'cat' => ['photos' => 0, 'billing' => 0, 'other' => 0],
            'announcements' => 0, 'chats' => 0, 'parent_messages' => 0, 'team_threads' => 0, 'web_chats' => 0, 'sms_replies' => 0, 'newest_id' => null, 'seen_id' => self::seenId($uid)];
        try {
            $rows = self::unseen($uid, $out['seen_id'])->orderByDesc('id')->limit(1000)->get(['type', 'title', 'body', 'data']);
            foreach ($rows as $n) {
                $s = mb_strtolower($n->type . ' ' . $n->title . ' ' . $n->body . ' ' . (is_string($n->data) ? $n->data : json_encode($n->data)));
                if (preg_match(self::PHOTOS, $s)) $out['cat']['photos']++;
                elseif (preg_match(self::BILLING, $s)) $out['cat']['billing']++;
                else $out['cat']['other']++;
            }
            $out['notifications'] = $rows->count();
            $out['notifications_unread'] = DB::table('notifications')->where('user_id', $uid)->whereNull('read_at')->count();
            /* The Announcements screen's own unread (2026-11-09): it counted unread 'announcement' alerts, so opening
               the bell cleared the 📣 while the screen still had unread ones, and one with no alert never counted. */
            $out['announcements'] = \App\Support\AnnouncementInbox::count($r);
            // The newest notification the person has (2026-10-30): the page fetches the full list only when this moves.
            $out['newest_id'] = (int) DB::table('notifications')->where('user_id', $uid)->max('id');
        } catch (\Throwable $e) { Log::warning('badges: notifications', ['e' => $e->getMessage()]); }
        foreach ([
            'chats' => [\App\Http\Controllers\Api\ChatController::class, 'unreadCount'],
            'parent_messages' => [\App\Http\Controllers\Api\MessageController::class, 'unreadCount'],
            'team_threads' => [\App\Http\Controllers\Api\TeamChatController::class, 'unreadCount'],
        ] as $k => [$cls, $m]) {
            try { $out[$k] = (int) (app($cls)->{$m}($r)->getData(true)['unread'] ?? 0); }
            catch (\Throwable $e) { Log::warning('badges: ' . $k, ['e' => $e->getMessage()]); }
        }
        /* WEBSITE CHATS (2026-11-01): the open ones with a visitor message nobody has opened - the same "unread" the
           Website tab and the chat dock show. Only for whoever sees that inbox: a super admin acting as one (not while
           viewing as another role). Nothing else counted them, so a visitor waiting moved no badge at all. */
        try {
            if (! $r->header('X-View-As-Role') && in_array('platform_admin', \App\Support\AgencyRole::names($r), true)) {
                $out['web_chats'] = (int) DB::table('web_chats as c')->where('c.status', 'open')
                    ->whereRaw("(select max(m.id) from web_chat_messages m where m.web_chat_id = c.id and m.sender = 'visitor') > coalesce(c.staff_read_id, 0)")
                    ->count();
            }
        } catch (\Throwable $e) { Log::warning('badges: web_chats', ['e' => $e->getMessage()]); }
        /* SMS -> REPLIES (2026-11-02): unread texts in to the agency's number, exactly the rows that list shows
           (App\Support\SmsReplies::unread - same scope, same plan gate). Message Centre's 📱 Texts tab lists them,
           so they count with the other messages (total, KT.badges.msgs) - and reading one reads its alert. */
        try { $out['sms_replies'] = \App\Support\SmsReplies::unread($r); }
        catch (\Throwable $e) { Log::warning('badges: sms_replies', ['e' => $e->getMessage()]); }
        /* UNREAD MAIL (2026-11-04): the inbox counts on the ✉️ Email client and 📮 Sales email top-bar icons - stored
           counts (App\Support\MailUnread), never a call to the mail server here. Not added to the total: the app icon
           number stays alerts + messages. */
        try { $out += \App\Support\MailUnread::forUser($uid); }
        catch (\Throwable $e) { Log::warning('badges: mail', ['e' => $e->getMessage()]); }
        $out['total'] = self::total($out);
        return $out;
    }

    /** The app icon's number: new alerts + unread messages (a guardian's are inside chats too, so not added twice). */
    public static function total(array $b): int
    {
        return (int) $b['notifications'] + max((int) $b['chats'], (int) $b['parent_messages']) + (int) $b['team_threads'] + (int) ($b['web_chats'] ?? 0) + (int) ($b['sms_replies'] ?? 0);
    }

    /** For a push being sent now: the person's icon number (without a request; chats via the same controllers). */
    public static function totalFor(int $userId): int
    {
        try {
            $u = \App\Models\User::find($userId);
            if (! $u) return 1;
            $r = Request::create('/api/v1/badges', 'GET');
            $r->setUserResolver(fn () => $u);
            return self::total(self::forRequest($r));
        } catch (\Throwable $e) { return 1; }
    }

    /**
     * Put the new number on this person's iPhones now (badge-only APNs through FCM, no alert, no sound), so reading on
     * one device clears the icon on the others. Android has no icon number of its own: its dot follows the delivered
     * notifications, which the app clears on the phone itself. At most once per 5 s per person, and only when it changed.
     */
    public static function syncIos(int $userId, int $n): array
    {
        $key = 'kt_badge_ios_' . $userId;
        $last = Cache::get($key);
        if ($last !== null && (int) $last['n'] === $n && time() - (int) $last['at'] < 600) return ['skipped' => 'unchanged'];
        if ($last !== null && time() - (int) $last['at'] < 5 && (int) $last['n'] === $n) return ['skipped' => 'recent'];
        $tokens = DB::table('device_tokens')->where('user_id', $userId)->where('platform', 'ios')->pluck('token')->all();
        Cache::put($key, ['n' => $n, 'at' => time()], 3600);
        if (! $tokens) return ['skipped' => 'no_ios'];
        if (\App\Support\Suppression::isUser($userId)) return ['skipped' => 'suppressed'];
        if (\App\Support\SignInLocks::pushPaused($userId)) return ['skipped' => 'sign_in_locked'];
        return app(\App\Services\FcmService::class)->badgeOnly($tokens, $n);
    }
}
