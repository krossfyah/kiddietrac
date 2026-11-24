<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHO EDUCATORS AND HOME VISITORS MAY MESSAGE (2026-10-09).
 *
 * Anthony: "message centre should only limit educators to their parents and admins/directors
 * and super admin", then "this should be configurable in the settings to allowing educators
 * to message who including other staff members ... also to home visitors as well".
 *
 * Settings → Notifications → Message Centre, stored in agencies.settings->messaging_rules (the
 * CountersignReminders pattern). Applies only to people whose roles are educator and/or home
 * visitor; anyone holding director, agency admin or platform admin is never restricted here.
 * Someone with both restricted roles gets the more generous of the two.
 *
 *   families  rooms  = only families of children in rooms they are assigned to
 *                      (the canAccessChildId rule; nobody assigned to a room → centre rule)
 *             centre = every family at their centre(s), as before
 *   staff     which roles they may message
 *   existing  conversations a rule no longer allows: read_only (kept, cannot send) | hidden
 *
 * A thread (1:1 or group) stays writable while at least one OTHER participant is allowed, so
 * a group that includes the director keeps working.
 */
final class MessagingRules
{
    public const KEY = 'messaging_rules';
    public const STAFF_ROLES = ['agency_admin', 'centre_director', 'platform_admin', 'educator', 'home_visitor'];
    public const PRIVILEGED = ['agency_admin', 'centre_director', 'platform_admin'];
    public const RESTRICTED = ['educator', 'home_visitor'];
    public const READ_ONLY_MESSAGE = 'Conversations between educators are read-only here. Message your director instead.';

    private const ROLE_DEFAULT = [
        'families' => 'rooms',
        'staff' => ['agency_admin' => true, 'centre_director' => true, 'platform_admin' => true, 'educator' => false, 'home_visitor' => false],
    ];

    public static function defaults(): array
    {
        return ['educator' => self::ROLE_DEFAULT, 'home_visitor' => self::ROLE_DEFAULT, 'existing' => 'read_only'];
    }

    public static function read(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        return self::clean((isset($s[self::KEY]) && is_array($s[self::KEY])) ? $s[self::KEY] : []);
    }

    public static function clean(array $in): array
    {
        $out = self::defaults();
        foreach (self::RESTRICTED as $r) {
            $x = (array) ($in[$r] ?? []);
            if (in_array($x['families'] ?? null, ['rooms', 'centre'], true)) $out[$r]['families'] = $x['families'];
            foreach (self::STAFF_ROLES as $sr) {
                if (isset($x['staff'][$sr])) $out[$r]['staff'][$sr] = (bool) $x['staff'][$sr];
            }
        }
        if (in_array($in['existing'] ?? null, ['read_only', 'hidden'], true)) $out['existing'] = $in['existing'];
        return $out;
    }

    public static function write(int $agencyId, array $next): array
    {
        $clean = self::clean($next);
        DB::transaction(function () use ($agencyId, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s[self::KEY] = $clean;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        return $clean;
    }

    private static array $memo = [];

    private static function roles(int $uid): array
    {
        return self::$memo['roles'][$uid] ??= DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->pluck('role')->unique()->values()->all();
    }

    /** The agency to judge this person by: the active agency if they belong to it, else their own. */
    public static function agencyFor(int $uid): ?int
    {
        $active = 0;
        try { $active = (int) request()->header('X-Active-Agency-Id'); } catch (\Throwable $e) {}
        $mine = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $uid)->where('ra.active', true)
            ->selectRaw('COALESCE(ra.agency_id, c.agency_id) as a')->pluck('a')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($active && in_array($active, $mine, true)) return $active;
        return $mine[0] ?? null;
    }

    /**
     * The rules for this person, or null when they are not restricted (directors, admins,
     * platform admins, parents). Union of the rules for each restricted role they hold.
     */
    public static function forUser(int $uid): ?array
    {
        $roles = self::roles($uid);
        if (array_intersect($roles, self::PRIVILEGED)) return null;
        $mine = array_values(array_intersect($roles, self::RESTRICTED));
        if (! $mine) return null;
        $agencyId = self::agencyFor($uid);
        if (! $agencyId) return null;
        $all = self::read($agencyId);
        $r = ['families' => 'rooms', 'staff' => array_fill_keys(self::STAFF_ROLES, false), 'existing' => $all['existing'], 'agency_id' => $agencyId];
        foreach ($mine as $role) {
            if ($all[$role]['families'] === 'centre') $r['families'] = 'centre';
            foreach ($all[$role]['staff'] as $k => $v) { if ($v) $r['staff'][$k] = true; }
        }
        return $r;
    }

    /**
     * Families this person may message, or null for "no room limit" (centre rule). Under the
     * rooms rule: families of children in a room they are assigned to — open enrolment or
     * primary room — within their agency. No room assignment at all → null (centre rule).
     */
    public static function familyIds(int $uid, ?array $rules = null): ?array
    {
        $rules = $rules ?? self::forUser($uid);
        if (! $rules || $rules['families'] === 'centre') return null;
        if (isset(self::$memo['fam'][$uid])) return self::$memo['fam'][$uid];
        $rooms = DB::table('educator_rooms')->where('user_id', $uid)->pluck('room_id')->all();
        if (! $rooms) return self::$memo['fam'][$uid] = null;
        $kids = DB::table('enrollments')->whereIn('room_id', $rooms)->whereNull('end_date')->pluck('child_id')->all();
        $kids = array_merge($kids, DB::table('children')->whereIn('primary_room_id', $rooms)->pluck('id')->all());
        return self::$memo['fam'][$uid] = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->whereIn('c.id', $kids ?: [0])->whereNull('c.deleted_at')->where('ce.agency_id', $rules['agency_id'])
            ->pluck('f.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** May this restricted person message this family? (true when unrestricted) */
    public static function familyAllowed(int $uid, int $familyId): bool
    {
        $fams = self::familyIds($uid);
        return $fams === null || in_array($familyId, $fams, true);
    }

    /** May $uid message user $target (a colleague, a super admin, or a parent)? */
    public static function personAllowed(int $uid, int $target, ?array $rules = null): bool
    {
        $rules = $rules ?? self::forUser($uid);
        if (! $rules) return true;
        $troles = self::roles($target);
        foreach ($troles as $tr) {
            if (! empty($rules['staff'][$tr])) return true;
        }
        // A parent: allowed when one of their families is allowed.
        $famIds = DB::table('guardians')->where('user_id', $target)->pluck('family_id')->map(fn ($v) => (int) $v)->all();
        if ($famIds) {
            $allowed = self::familyIds($uid, $rules);
            if ($allowed === null) return true;   // centre rule: the existing centre checks decide
            return (bool) array_intersect($famIds, $allowed);
        }
        return false;
    }

    /** Keep only the people this person may message. */
    public static function filterPeople(int $uid, array $ids): array
    {
        $rules = self::forUser($uid);
        if (! $rules) return $ids;
        return array_values(array_filter($ids, fn ($t) => self::personAllowed($uid, (int) $t, $rules)));
    }

    /** A staff thread is writable while at least one other participant is allowed. */
    public static function threadWritable(int $uid, int $threadId): bool
    {
        $rules = self::forUser($uid);
        if (! $rules) return true;
        $others = DB::table('staff_thread_participants')->where('thread_id', $threadId)->where('user_id', '!=', $uid)->pluck('user_id')->all();
        foreach ($others as $o) {
            if (self::personAllowed($uid, (int) $o, $rules)) return true;
        }
        return false;
    }

    /*
     * THE CENTRE OFFICE THREAD (2026-11-06). Anthony: "allow parent to message its educator(s) or agency (everyone at
     * agency admin/directors - would get the messages)". A parent's message to the office is a family conversation
     * marked channel='office' (one per family). Only the agency's admins, the centre's directors and platform admins
     * hear about it (ChatAudience::staffIds) or can list, count, open, answer, archive, report or translate it; to
     * every other staff member - educators on any rule, auditors - it does not exist. The family always sees it.
     */
    public const OFFICE = 'office';

    /** Is this an office thread? Loads channel/centre_id when the caller selected fewer columns. */
    public static function isOffice(object $conv): bool
    {
        if (! property_exists($conv, 'channel') || ! property_exists($conv, 'centre_id')) {
            $row = DB::table('conversations')->where('id', (int) ($conv->id ?? 0))->first(['channel', 'centre_id']);
            $conv->channel = $row->channel ?? null;
            $conv->centre_id = $row->centre_id ?? null;
        }
        return ($conv->channel ?? null) === self::OFFICE;
    }

    /** Centres whose office threads this person may see: null = every centre (platform admin). @return int[]|null */
    public static function officeCentreIds(int $uid): ?array
    {
        if (array_key_exists($uid, self::$memo['office'] ?? [])) return self::$memo['office'][$uid];
        $rows = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereIn('role', self::PRIVILEGED)->get(['role', 'agency_id', 'centre_id']);
        if ($rows->contains(fn ($r) => $r->role === 'platform_admin')) return self::$memo['office'][$uid] = null;
        $ids = $rows->where('role', 'centre_director')->pluck('centre_id')->filter()->map(fn ($v) => (int) $v)->all();
        $agencies = $rows->where('role', 'agency_admin')->pluck('agency_id')->filter()->all();
        if ($agencies) {
            $ids = array_merge($ids, DB::table('centres')->whereIn('agency_id', $agencies)->pluck('id')->map(fn ($v) => (int) $v)->all());
        }
        return self::$memo['office'][$uid] = array_values(array_unique($ids));
    }

    /** True when $conv is an office thread this person must not see (they are not its family or its office). */
    public static function officeHiddenFrom(int $uid, object $conv): bool
    {
        if (! self::isOffice($conv)) return false;
        $ids = self::officeCentreIds($uid);
        if ($ids === null || in_array((int) $conv->centre_id, $ids, true)) return false;
        return ! DB::table('guardians')->where('family_id', (int) ($conv->family_id ?? 0))->where('user_id', $uid)->exists();
    }

    /** Limit a staff `conversations` query to the office threads this person may see. */
    public static function scopeOffice($query, int $uid, string $table = 'conversations'): void
    {
        $ids = self::officeCentreIds($uid);
        if ($ids === null) return;
        $query->where(function ($w) use ($table, $ids) {
            $w->whereNull($table . '.channel')->orWhere($table . '.channel', '!=', self::OFFICE)
              ->orWhereIn($table . '.centre_id', $ids ?: [0]);
        });
    }

    /** 'ok' | 'read_only' | 'hidden' for a family conversation. */
    public static function conversationState(int $uid, object $conv): string
    {
        if (self::officeHiddenFrom($uid, $conv)) return 'hidden';
        $rules = self::forUser($uid);
        if (! $rules) return 'ok';
        if (self::familyAllowed($uid, (int) $conv->family_id)) return 'ok';
        if ($rules['existing'] === 'hidden') return 'hidden';
        return self::tookPart($uid, (int) $conv->id) ? 'read_only' : 'hidden';
    }

    /**
     * READ-ONLY HISTORY IS THE EDUCATOR'S OWN HISTORY (2026-10-29). Anthony, from an educator's
     * iPhone: "why is it showing other chats that is not relevant to this user". 'existing' =
     * read_only was meant to keep the conversations a rule no longer allows - chats the educator
     * had been writing in - but it kept EVERY family thread at the centre: a new educator with one
     * room saw three family chats (other rooms, written by the director) and opened one into a
     * read-only page. Out-of-room threads now stay only where this person sent a message.
     */
    public static function tookPart(int $uid, int $conversationId): bool
    {
        return DB::table('messages')->where('conversation_id', $conversationId)->where('sender_id', $uid)->exists();
    }

    /** Limit a `conversations` query to what a restricted person may see (see tookPart). */
    public static function scopeConversations($query, int $uid, array $rules, array $allowedFams, string $table = 'conversations'): void
    {
        $query->where(function ($w) use ($uid, $rules, $allowedFams, $table) {
            $w->whereIn($table . '.family_id', $allowedFams ?: [0]);
            if ($rules['existing'] !== 'hidden') {
                $w->orWhereIn($table . '.id', DB::table('messages')->where('sender_id', $uid)->select('conversation_id'));
            }
        });
    }

    /** 'ok' | 'read_only' | 'hidden' for a staff thread. */
    public static function threadState(int $uid, int $threadId): string
    {
        $rules = self::forUser($uid);
        if (! $rules || self::threadWritable($uid, $threadId)) return 'ok';
        return $rules['existing'] === 'hidden' ? 'hidden' : 'read_only';
    }

    public static function familyMessage(): string
    {
        return 'This family is outside the rooms you are assigned to, so this conversation is read-only. Your director can message them.';
    }
}
