<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TEXT REPLIES: texts people send to an agency's number (2026-11-02).
 *
 * Anthony got a phone notification "Text reply from Bruni Meeser" (sms_messages #1867, iLearn) and could not find
 * the message: the link went to '#sms', the broadcast SEND screen. Then: "for SMS being responded to it should show
 * up in the message centre - for example safia sent out an announcement today which included SMS and someone
 * responded - Safia should see this response to her announcement in the message centre".
 *
 * So a reply BELONGS to whoever sent the text it answers (attribute(), filed on the row when it arrives), and lives:
 *   - in that person's Message Centre, "📱 Texts" tab (screen-chat.js + kt-sms-replies.js), as a conversation with
 *     the person who replied, headed by the announcement it answers;
 *   - and in SMS → Replies, the agency's overview for admins and directors.
 * Both read THESE rows (sms_messages), so there is one read state: reading it in either place reads it in both. No
 * chat row is forged to look as if the person used the app; a guardian's text is still bridged into their family
 * chat thread as before (SmsChatBridge, messages.via = 'sms'), and reading that bridged message counts as reading
 * the text (unread()).
 *
 * WHO SEES A REPLY (fail closed, always inside the ACTIVE agency):
 *   - agency admins / platform admin in that agency: all of the agency's replies;
 *   - a centre director: replies from people at their own centres (CentreScope), plus replies to their own texts;
 *   - anyone else on staff: replies to texts THEY sent (owner_user_id = them);
 *   - a parent, or nobody in particular: nothing.
 *   The KiddieTrac company line (LineTexts, filed under agency 2) is NOT agency correspondence: a stranger's text to
 *   it lives on Phone → Texts, and those rows and the line's own replies are excluded here. The two never merge.
 */
final class SmsReplies
{
    /** Roles that oversee an agency's replies (the SMS routes' own role gate). */
    public const OVERSEERS = ['centre_director', 'agency_admin', 'platform_admin'];

    /** Categories that belong to the company line's Phone → Texts, never to an agency inbox. */
    private const LINE_OURS = ['line_text', 'voicemail_reply', 'keyword_reply'];

    /** The agency in view, or null. SmsController::resolveAgencyId's rule, answering null instead of aborting. */
    public static function agencyFor(Request $r): ?int
    {
        $u = $r->user();
        if (! $u) {
            return null;
        }
        $active = (int) $r->header('X-Active-Agency-Id');
        if ($active && DB::table('role_assignments')->where('user_id', $u->id)->where('active', true)
            ->where(fn ($w) => $w->where('agency_id', $active)->orWhere('role', 'platform_admin')
                ->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $active)->select('id')))->exists()) {
            return $active;
        }
        if (DB::table('role_assignments')->where('user_id', $u->id)->where('role', 'platform_admin')->where('active', true)->exists()) {
            return null;   // a platform admin must choose an agency; never their first role's
        }
        $first = DB::table('role_assignments')->where('user_id', $u->id)->where('active', true)->value('agency_id');
        if (! $first) {
            $first = DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $u->id)->where('ra.active', true)->value('c.agency_id');
        }

        return $first ? (int) $first : null;
    }

    private static function viewAs(Request $r): string
    {
        return (string) $r->header('X-View-As-Role');
    }

    /** Admin / director IN this agency (or platform admin), and not previewing as someone else. */
    public static function oversees(Request $r, int $agencyId): bool
    {
        $u = $r->user();
        if (! $u || $agencyId <= 0) return false;
        $v = self::viewAs($r);
        if ($v !== '' && ! in_array($v, self::OVERSEERS, true)) return false;
        if (DB::table('role_assignments')->where('user_id', $u->id)->where('role', 'platform_admin')->where('active', true)->exists()) {
            return true;
        }
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();

        return DB::table('role_assignments')->where('user_id', $u->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director'])
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereIn('centre_id', $centres ?: [0]))->exists();
    }

    /** Staff of this agency at all (any staff role there). A parent or a preview as a parent is not. */
    public static function staffIn(Request $r, int $agencyId): bool
    {
        $u = $r->user();
        if (! $u || $agencyId <= 0) return false;
        if (self::viewAs($r) === 'guardian') return false;
        if (self::oversees($r, $agencyId)) return true;
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();

        return DB::table('role_assignments')->where('user_id', $u->id)->where('active', true)
            ->whereIn('role', ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'auditor'])
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereIn('centre_id', $centres ?: [0]))->exists();
    }

    /** Back-compat name used by the admin routes. */
    public static function mayUse(Request $r, int $agencyId): bool
    {
        return self::oversees($r, $agencyId);
    }

    /**
     * null = everyone in the agency (agency-wide caller). For a centre director, the user ids of the people at
     * their centres. [] means NOBODY - always `?: [0]`.
     */
    public static function people(Request $r, int $agencyId): ?array
    {
        // Wide only for an admin IN THIS agency (AgencyRole), never "admin somewhere" (kiddietrac-data-leakage-rule).
        if (array_intersect(AgencyRole::names($r), ['platform_admin', 'agency_admin'])) return null;
        $scope = Visibility::centreIds($agencyId, $r->user());
        $staff = DB::table('role_assignments')->whereIn('centre_id', $scope ?: [0])->where('active', true)->pluck('user_id');
        $parents = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $scope ?: [0])->pluck('g.user_id');

        return $staff->merge($parents)->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    /** Every inbound text of this agency (company line excluded). No person scope - see visible(). */
    public static function inbound(int $agencyId, ?array $people = null)
    {
        return DB::table('sms_messages as m')
            ->where('m.agency_id', $agencyId)
            ->where('m.direction', 'in')
            ->when($people !== null, fn ($q) => $q->whereIn('m.to_user_id', $people ?: [0]))
            ->when($agencyId === LineTexts::AGENCY, fn ($q) => $q->whereNotNull('m.to_user_id'));
    }

    /**
     * The inbound rows THIS caller may see, or null when they may see none. One rule for the Message Centre tab, the
     * SMS → Replies tab, the badge, and opening / reading / answering one.
     *
     * @return array{0:\Illuminate\Database\Query\Builder,1:bool} [query, oversees]
     */
    public static function visible(Request $r, int $agencyId): ?array
    {
        if (! self::staffIn($r, $agencyId)) return null;
        $me = (int) $r->user()->id;
        $q = self::inbound($agencyId);
        if (self::oversees($r, $agencyId)) {
            $people = self::people($r, $agencyId);
            if ($people !== null) {
                $q->where(fn ($w) => $w->whereIn('m.to_user_id', $people ?: [0])->orWhere('m.owner_user_id', $me));
            }

            return [$q, true];
        }
        $q->where('m.owner_user_id', $me);

        return [$q, false];
    }

    /** Unread = never read here, and not already read as the bridged chat message (a guardian's text in chat). */
    public static function whereUnread($q)
    {
        return $q->whereNull('m.read_at')->whereNotExists(function ($x) {
            $x->select(DB::raw(1))->from('messages as mm')
                ->whereColumn('mm.sender_id', 'm.to_user_id')->where('mm.via', 'sms')
                ->whereNotNull('mm.read_at')
                // The two tables use different collations (messages vs sms_messages): compare the bytes, exactly.
                ->whereRaw('BINARY mm.body = BINARY m.body')
                ->whereRaw('mm.created_at >= m.created_at - INTERVAL 1 DAY');
        });
    }

    /** The badge: unread rows of exactly what visible() lists. 0 for anybody without any. */
    public static function unread(Request $r): int
    {
        try {
            $agencyId = self::agencyFor($r);
            if (! $agencyId || ! self::planAllows($agencyId)) return 0;
            $v = self::visible($r, $agencyId);
            if (! $v) return 0;

            return (int) self::whereUnread($v[0])->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** The plan gate the API applies to admin/sms and sms-replies (EnforcePlanFeatures). */
    public static function planAllows(int $agencyId): bool
    {
        return \App\Http\Middleware\EnforcePlanFeatures::allows($agencyId, 'sms');
    }

    public static function last10(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);

        return strlen($d) > 10 ? substr($d, -10) : $d;
    }

    /**
     * WHOSE TEXT DOES THIS ANSWER? Filed on the inbound row when it arrives.
     *
     *   1. The last text we SENT to that number in this agency before it came in (not one a gate skipped).
     *   2. Its sender: sms_messages.sent_by_user_id (a reply typed in KiddieTrac, a broadcast).
     *   3. Otherwise, the announcement that text carried: announcement texts are "<title>: <body>", sent as the
     *      announcement is published - same agency, send_sms on, title matching, created within the hour before.
     *      Its author is the owner.
     *   Nothing found = no owner; the agency's admins and directors still see it.
     *
     * @return array{reply_to_sms_id:?int, owner_user_id:?int, announcement_id:?int}
     */
    public static function attribute(int $agencyId, string $phone, int $inboundId): array
    {
        $out = ['reply_to_sms_id' => null, 'owner_user_id' => null, 'announcement_id' => null];
        $d = self::last10($phone);
        if (strlen($d) < 7) return $out;
        $prev = DB::table('sms_messages')->where('agency_id', $agencyId)->where('direction', 'out')
            ->where('id', '<', $inboundId)->whereNotIn('status', ['skipped'])
            ->where(fn ($w) => $w->whereNull('category')->orWhereNotIn('category', self::LINE_OURS))
            ->whereRaw("RIGHT(REGEXP_REPLACE(to_phone, '[^0-9]', ''), 10) = ?", [$d])
            ->orderByDesc('id')->first(['id', 'category', 'body', 'sent_by_user_id', 'created_at']);
        if (! $prev) return $out;
        $out['reply_to_sms_id'] = (int) $prev->id;
        if ($prev->sent_by_user_id) {
            $out['owner_user_id'] = (int) $prev->sent_by_user_id;
        }
        if ($prev->category === 'announcement') {
            $ann = self::announcementFor($agencyId, (string) $prev->body, (string) $prev->created_at);
            if ($ann) {
                $out['announcement_id'] = (int) $ann->id;
                if (! $out['owner_user_id'] && $ann->created_by_id) $out['owner_user_id'] = (int) $ann->created_by_id;
            }
        }

        return $out;
    }

    /** The announcement an announcement text carried (see attribute()). */
    public static function announcementFor(int $agencyId, string $smsBody, string $sentAt): ?object
    {
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $cands = DB::table('announcements')->where('send_sms', 1)
            ->where('created_at', '<=', $sentAt)
            ->whereRaw('created_at >= ? - INTERVAL 1 HOUR', [$sentAt])
            ->where(function ($w) use ($agencyId, $centres) {
                $w->where('agency_id', $agencyId)
                  ->orWhere(fn ($x) => $x->where('scope_type', 'agency')->where('scope_id', $agencyId))
                  ->orWhere(fn ($x) => $x->where('scope_type', 'centre')->whereIn('scope_id', $centres ?: [0]));
            })
            ->orderByDesc('id')->limit(20)->get(['id', 'title', 'created_by_id']);
        foreach ($cands as $a) {
            $t = trim((string) $a->title);
            if ($t !== '' && str_starts_with($smsBody, $t . ':')) return $a;
        }

        return null;
    }

    /** File the attribution on an inbound row (SmsInbound::record). Never throws. */
    public static function file(int $rowId): ?array
    {
        try {
            $m = DB::table('sms_messages')->where('id', $rowId)->first(['id', 'agency_id', 'to_phone', 'direction']);
            if (! $m || $m->direction !== 'in') return null;
            $a = self::attribute((int) $m->agency_id, (string) $m->to_phone, (int) $m->id);
            DB::table('sms_messages')->where('id', $rowId)->update($a);

            return $a;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SMS reply attribution failed', ['row' => $rowId, 'e' => $e->getMessage()]);

            return null;
        }
    }

    /** "Text reply to your announcement …": titles for a set of announcement ids. */
    public static function announcementTitles(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (! $ids) return [];

        return DB::table('announcements')->whereIn('id', $ids)->pluck('title', 'id')->map(fn ($t) => (string) $t)->all();
    }

    /**
     * The conversation with one number, oldest first. An overseer sees every text with that number (director: their
     * centres' people only); anyone else sees their own replies and the texts of theirs being answered.
     */
    public static function thread(int $agencyId, object $row, bool $oversees, ?array $people, int $me)
    {
        $d = self::last10((string) $row->to_phone);
        $userId = $row->to_user_id ? (int) $row->to_user_id : null;
        $q = DB::table('sms_messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.to_user_id')
            ->leftJoin('users as s', 's.id', '=', 'm.sent_by_user_id')
            ->where('m.agency_id', $agencyId)
            ->where(function ($w) use ($d, $userId) {
                if (strlen($d) >= 7) $w->whereRaw("RIGHT(REGEXP_REPLACE(m.to_phone, '[^0-9]', ''), 10) = ?", [$d]);
                if ($userId) $w->orWhere('m.to_user_id', $userId);
                if (strlen($d) < 7 && ! $userId) $w->whereRaw('1 = 0');
            });
        if ($oversees) {
            if ($people !== null) {
                $q->where(fn ($w) => $w->whereIn('m.to_user_id', $people ?: [0])->orWhere('m.owner_user_id', $me)->orWhere('m.sent_by_user_id', $me));
            }
        } else {
            // Their replies to me, the texts of mine they answer, and what I sent them.
            $mine = DB::table('sms_messages')->where('agency_id', $agencyId)->where('direction', 'in')
                ->where('owner_user_id', $me)->whereNotNull('reply_to_sms_id')->pluck('reply_to_sms_id')->all();
            $q->where(fn ($w) => $w->where(fn ($x) => $x->where('m.direction', 'in')->where('m.owner_user_id', $me))
                ->orWhere('m.sent_by_user_id', $me)
                ->orWhereIn('m.id', $mine ?: [0]));
        }
        if ($agencyId === LineTexts::AGENCY) {
            $q->where(fn ($w) => $w->where('m.direction', '!=', 'in')->orWhereNotNull('m.to_user_id'))
              ->where(fn ($w) => $w->whereNull('m.category')->orWhereNotIn('m.category', self::LINE_OURS));
        }

        return $q->orderByDesc('m.id')->limit(200)->get([
            'm.id', 'm.direction', 'm.to_user_id', 'm.to_phone', 'm.body', 'm.category', 'm.status', 'm.error',
            'm.created_at', 'm.read_at', 'm.sent_by_user_id', 'm.announcement_id', 'm.owner_user_id',
            DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as person"),
            DB::raw("TRIM(COALESCE(s.first_name,'')) as sent_by"),
        ])->reverse()->values();
    }

    /** What kind of inbound row this is: a message, or an opt-out / opt-in / help keyword. */
    public static function kind(?string $category): string
    {
        return match ((string) $category) {
            'inbound_stop' => 'stop',
            'inbound_start' => 'start',
            'inbound_help' => 'help',
            default => 'message',
        };
    }

    /**
     * Can a reply be texted to this person right now? The same decisions sendOne makes, asked WITHOUT sending
     * (rollback-does-not-unsend: test the decision, never the sender). The send itself runs every gate again.
     *
     * @return array{ok:bool, reason:string, basis?:string} basis: 'consent' (they agreed to texts) or 'texted_us'
     */
    public static function canReply(int $agencyId, ?int $userId, string $phone): array
    {
        if (! $userId) {
            return ['ok' => false, 'reason' => 'This number is not linked to anyone in your agency, so there is no record that they agreed to texts. Call them, or add the number to their profile first.'];
        }
        if (self::stopped($agencyId, $userId, $phone)) {
            return ['ok' => false, 'reason' => 'They replied STOP, so they cannot be texted. If they text START, replies open again.'];
        }
        if (Suppression::isUser($userId)) {
            return ['ok' => false, 'reason' => 'This person is on do-not-contact, so texts to them are blocked.'];
        }
        if (! DB::table('agencies')->where('id', $agencyId)->value('sms_enabled')) {
            return ['ok' => false, 'reason' => 'Texting is switched off for this agency (Carrier settings → Text messages).'];
        }
        if (! in_array('chat_reply', ContactCategories::smsAllowed($agencyId), true)) {
            return ['ok' => false, 'reason' => '"Replies from staff" texts are switched off for this agency (Carrier settings → Text messages).'];
        }
        if (! ContactCategories::userWants($userId, 'sms', 'chat_reply')) {
            return ['ok' => false, 'reason' => 'They turned off "Replies from staff" texts in their settings.'];
        }
        $consent = Handset::smsConsent($agencyId, $userId, $phone);
        if (! $consent['ok']) {
            // They texted us: answering them is allowed (textedUs). The send path checks the same thing again.
            if (self::textedUs($agencyId, $userId, $phone)) {
                return ['ok' => true, 'reason' => '', 'basis' => 'texted_us'];
            }

            return ['ok' => false, 'reason' => 'They have not agreed to receive texts from your agency, so a reply cannot be texted. Ask them to agree to texts (SMS → Text alerts), or call them.'];
        }

        return ['ok' => true, 'reason' => '', 'basis' => 'consent'];
    }

    /**
     * THEY TEXTED US (2026-11-02, Anthony: "i should be able to reply to anyone text message"). A person who sends a
     * text to the agency's number started that conversation, so a direct reply to them is allowed without a recorded
     * opt-in - the rule the KiddieTrac line already uses (LineTexts::consentBasis, 'texted_us').
     *
     * Never: after STOP (stopped(), on any account on that handset), on do-not-contact, or when they said no to texts
     * AFTER their latest text to us (a later "no" wins, as in Handset::smsConsent). A "no" from before they texted us
     * is superseded by their own text. The agency switch and the "Replies from staff" kind are checked by the caller
     * (canReply) and by sendOne as usual.
     */
    public static function textedUs(int $agencyId, ?int $userId, string $phone): bool
    {
        $d = self::last10($phone);
        if (strlen($d) < 7) return false;
        if (self::stopped($agencyId, $userId, $phone)) return false;
        if ($userId && Suppression::isUser($userId)) return false;
        $lastIn = DB::table('sms_messages')->where('agency_id', $agencyId)->where('direction', 'in')
            ->where(fn ($w) => $w->whereNull('category')->orWhereNotIn('category', array_merge(['inbound_stop', 'inbound_start', 'inbound_help'], self::LINE_OURS)))
            ->whereRaw("RIGHT(REGEXP_REPLACE(to_phone, '[^0-9]', ''), 10) = ?", [$d])
            ->when($agencyId === LineTexts::AGENCY, fn ($q) => $q->whereNotNull('to_user_id'))
            ->max('created_at');
        if (! $lastIn) return false;
        $pool = Handset::accounts($phone)->pluck('id')->map(fn ($i) => (int) $i);
        if ($userId) $pool->push($userId);
        $pool = $pool->unique()->values()->all();
        if ($pool && DB::table('users')->whereIn('id', $pool)->where('sms_opt_out_at', '>', $lastIn)->exists()) {
            return false;
        }

        return true;
    }

    /** STOP wins, always: a STOP on any account on this handset, or this number's latest keyword here is STOP. */
    public static function stopped(int $agencyId, ?int $userId, string $phone): bool
    {
        $pool = Handset::accounts($phone)->pluck('id')->map(fn ($i) => (int) $i);
        if ($userId) $pool->push($userId);
        $pool = $pool->unique()->values()->all();
        if ($pool && DB::table('users')->whereIn('id', $pool)->where('sms_consent_source', 'sms')
            ->whereNotNull('sms_opt_out_at')->where('sms_opt_in', 0)->exists()) {
            return true;
        }
        $d = self::last10($phone);
        if (strlen($d) < 7) return false;
        $last = DB::table('sms_messages')->where('agency_id', $agencyId)->where('direction', 'in')
            ->whereIn('category', ['inbound_stop', 'inbound_start'])
            ->whereRaw("RIGHT(REGEXP_REPLACE(to_phone, '[^0-9]', ''), 10) = ?", [$d])
            ->orderByDesc('id')->value('category');

        return $last === 'inbound_stop';
    }
}
