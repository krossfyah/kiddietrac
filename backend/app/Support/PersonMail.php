<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EMAILS ON A PERSON'S RECORD (2026-11-07, Anthony: "allow agency mailboxes that are configured in email client to link
 * emails to parents, educators and any other user's accounts for tracking purposes - this can be a setting to configure
 * this as an option for audit trail purposes").
 *
 * An agency's own mailboxes (email_accounts.agency_id set, not Sales) can file their mail on the people it is about:
 *   - a parent (guardian), a staff member (agency_admin, centre_director, educator, home_visitor) or a directory contact
 *     - only people active in THAT mailbox's agency. Never across agencies; a deactivated account is never matched.
 *   - automatically, by address: mail FROM the person into the inbox, and mail TO them in Sent Items
 *     (kiddietrac:mail-watch, Microsoft mailboxes), and
 *   - by hand: "🔗 Link to person" on any open email (every kind of mailbox).
 *
 * Settings live in agencies.settings.email_link (all off until an admin turns it on - Admin > Email settings):
 *   enabled   bool   the feature at all
 *   auto      bool   match by address (else manual only)
 *   kinds     list   guardian | staff | contact
 *   sent      bool   also file what the agency sends (Sent Items)
 *
 * Only the facts are kept (email_person_links): who, when, from, to, the subject and Outlook's preview - the last two
 * sealed (App\Support\Sensitive). The email itself stays in the mailbox. Every link and unlink is audited; an unlink is a
 * stamp, never a delete, and an automatic pass never re-links something a person unlinked.
 */
final class PersonMail
{
    public const KINDS = ['guardian', 'staff', 'contact'];
    public const STAFF_ROLES = ['agency_admin', 'centre_director', 'educator', 'home_visitor'];
    private const CLOSED_USER = ['deactivated', 'suspended'];

    public static function settings(?int $agencyId): array
    {
        $def = ['enabled' => false, 'auto' => true, 'kinds' => self::KINDS, 'sent' => true, 'view_roles' => ['agency_admin'], 'view_users' => []];
        if (! $agencyId) return $def;
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode($raw, true) ?: []) : [];
        $e = (isset($s['email_link']) && is_array($s['email_link'])) ? $s['email_link'] : [];
        $kinds = array_values(array_intersect(self::KINDS, (array) ($e['kinds'] ?? self::KINDS)));

        return ['enabled' => ! empty($e['enabled']), 'auto' => ($e['auto'] ?? true) !== false, 'kinds' => $kinds, 'sent' => ($e['sent'] ?? true) !== false,
            'view_roles' => array_values(array_intersect(self::VIEW_ROLES, (array) ($e['view_roles'] ?? ['agency_admin']))),
            'view_users' => array_values(array_unique(array_map('intval', array_filter((array) ($e['view_users'] ?? []), 'is_numeric'))))];
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        $now = self::settings($agencyId);
        $new = [
            'enabled' => array_key_exists('enabled', $in) ? (bool) $in['enabled'] : $now['enabled'],
            'auto' => array_key_exists('auto', $in) ? (bool) $in['auto'] : $now['auto'],
            'kinds' => array_key_exists('kinds', $in) ? array_values(array_intersect(self::KINDS, (array) $in['kinds'])) : $now['kinds'],
            'sent' => array_key_exists('sent', $in) ? (bool) $in['sent'] : $now['sent'],
            'view_roles' => array_key_exists('view_roles', $in) ? array_values(array_intersect(self::VIEW_ROLES, (array) $in['view_roles'])) : $now['view_roles'],
            // Only the agency's own admins and directors can be named.
            'view_users' => array_key_exists('view_users', $in)
                ? array_values(array_intersect(array_column(self::viewerCandidates($agencyId), 'id'), array_map('intval', (array) $in['view_users'])))
                : $now['view_users'],
        ];
        DB::transaction(function () use ($agencyId, $new) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode($raw, true) ?: []) : [];
            $s['email_link'] = $new;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return $new;
    }

    /* WHO SEES LINKED EMAILS (2026-11-06, Anthony: "wire this up to choose who see's them" - roles plus extra people;
       viewers only see, linking and unlinking stay with agency admins and the mailbox's owner).
         view_roles  agency_admin (the whole agency) and/or centre_director (people and families at their own centres)
         view_users  named admins/directors who see the whole agency's linked emails, whatever the roles say
       Platform admins always see. */
    public const VIEW_ROLES = ['agency_admin', 'centre_director'];

    /** Admins and directors of the agency who can be named as extra viewers. @return array<int, array{id:int,name:string,role:string}> */
    public static function viewerCandidates(int $agencyId): array
    {
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $rows = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->whereNull('u.deleted_at')->whereNotIn('u.status', self::CLOSED_USER)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId))
                ->orWhere(fn ($w) => $w->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $centres ?: [0])))
            ->orderBy('u.first_name')->get(['u.id', 'u.first_name', 'u.preferred_name', 'u.last_name', 'ra.role']);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            // An admin is listed as an admin even if they also direct a centre.
            if (isset($out[$id]) && $out[$id]['role'] === 'agency_admin') continue;
            $out[$id] = ['id' => $id, 'name' => trim(($r->preferred_name ?: $r->first_name) . ' ' . $r->last_name), 'role' => $r->role];
        }

        return array_values($out);
    }

    /**
     * What this caller may see of the agency's linked emails.
     * @return array<int>|null|false  null = the whole agency, int[] = these centres, false = nothing
     */
    public static function viewScope(\Illuminate\Http\Request $request, int $agencyId)
    {
        $user = $request->user();
        if (! $user || ! $agencyId) return false;
        $roles = AgencyRole::names($request);
        if (in_array('platform_admin', $roles, true)) return null;
        $s = self::settings($agencyId);
        if (in_array((int) $user->id, $s['view_users'], true)
            && in_array((int) $user->id, array_column(self::viewerCandidates($agencyId), 'id'), true)) {
            return null;
        }
        if (in_array('agency_admin', $roles, true) && in_array('agency_admin', $s['view_roles'], true)) return null;
        if (in_array('centre_director', $roles, true) && in_array('centre_director', $s['view_roles'], true)) {
            return Visibility::centreIds($agencyId, $user);
        }

        return false;
    }

    /** May a caller with this scope see this person's linked emails? Fails closed. */
    public static function scopeHasPerson($scope, string $type, int $id): bool
    {
        if ($scope === null) return true;
        if (! is_array($scope) || ! $scope) return false;
        if ($type === 'contact') {
            $c = DB::table('contacts')->where('id', $id)->value('centre_id');
            return $c !== null && in_array((int) $c, $scope, true);
        }
        // A parent of a family at one of these centres, or staff assigned to one of them.
        if (DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->where('g.user_id', $id)->whereIn('f.centre_id', $scope)->exists()) {
            return true;
        }

        return DB::table('role_assignments')->where('user_id', $id)->where('active', 1)->whereIn('centre_id', $scope)->exists()
            || DB::table('educator_rooms as er')->join('rooms as r', 'r.id', '=', 'er.room_id')
                ->where('er.user_id', $id)->whereIn('r.centre_id', $scope)->exists();
    }

    public static function scopeHasFamily($scope, int $familyId): bool
    {
        if ($scope === null) return true;
        if (! is_array($scope) || ! $scope) return false;
        $c = DB::table('families')->where('id', $familyId)->value('centre_id');

        return $c !== null && in_array((int) $c, $scope, true);
    }

    /** An agency's own mailbox (not Sales, not a mailbox with no agency) whose agency has linking on. */
    public static function linkable(object $acct): bool
    {
        return ! empty($acct->agency_id) && empty($acct->shared_scope) && self::settings((int) $acct->agency_id)['enabled'];
    }

    public static function norm(?string $addr): string
    {
        $a = mb_strtolower(trim((string) $addr));

        return (str_contains($a, '@') && mb_strlen($a) <= 190) ? $a : '';
    }

    /**
     * The live people in $agencyId with this address, of the given kinds:
     * [['type' => 'user'|'contact', 'id', 'name', 'as' => 'guardian'|'staff'|'contact', 'family_id' => ?int]].
     */
    public static function peopleFor(int $agencyId, string $addr, array $kinds): array
    {
        $a = self::norm($addr);
        if ($a === '' || ! $agencyId) return [];
        $out = [];
        if (in_array('guardian', $kinds, true) || in_array('staff', $kinds, true)) {
            $roles = array_merge(in_array('guardian', $kinds, true) ? ['guardian'] : [], in_array('staff', $kinds, true) ? self::STAFF_ROLES : []);
            $rows = DB::table('users as u')->join('role_assignments as r', 'r.user_id', '=', 'u.id')
                ->whereNull('u.deleted_at')->whereNotIn('u.status', self::CLOSED_USER)
                ->where('r.active', 1)->where('r.agency_id', $agencyId)->whereIn('r.role', $roles)
                ->whereRaw('LOWER(TRIM(u.email)) = ?', [$a])
                ->get(['u.id', 'u.first_name', 'u.last_name', 'u.preferred_name', 'r.role']);
            foreach ($rows as $r) {
                $as = $r->role === 'guardian' ? 'guardian' : 'staff';
                $k = 'user:' . $r->id;
                if (isset($out[$k]) && $out[$k]['as'] === 'staff') continue;   // staff wins: one row per person
                $out[$k] = ['type' => 'user', 'id' => (int) $r->id, 'name' => self::name($r), 'as' => $as,
                    'family_id' => $as === 'guardian' ? self::familyOf((int) $r->id, $agencyId) : null];
            }
        }
        if (in_array('contact', $kinds, true)) {
            $rows = DB::table('contacts')->whereNull('deleted_at')->where('agency_id', $agencyId)->whereRaw('LOWER(TRIM(email)) = ?', [$a])
                ->get(['id', 'first_name', 'last_name', 'company']);
            foreach ($rows as $c) {
                $out['contact:' . $c->id] = ['type' => 'contact', 'id' => (int) $c->id, 'name' => trim(self::name($c) ?: (string) $c->company), 'as' => 'contact', 'family_id' => null];
            }
        }

        return array_values($out);
    }

    /** One person by type and id, checked to be live in $agencyId; null when not. */
    public static function person(int $agencyId, string $type, int $id): ?array
    {
        if ($type === 'user') {
            $rows = DB::table('users as u')->join('role_assignments as r', 'r.user_id', '=', 'u.id')
                ->whereNull('u.deleted_at')->whereNotIn('u.status', self::CLOSED_USER)
                ->where('u.id', $id)->where('r.active', 1)->where('r.agency_id', $agencyId)
                ->whereIn('r.role', array_merge(['guardian'], self::STAFF_ROLES))
                ->get(['u.id', 'u.first_name', 'u.last_name', 'u.preferred_name', 'u.email', 'r.role']);
            if (! $rows->count()) return null;
            $staff = $rows->first(fn ($r) => $r->role !== 'guardian');
            $r = $staff ?: $rows->first();

            return ['type' => 'user', 'id' => (int) $r->id, 'name' => self::name($r), 'as' => $staff ? 'staff' : 'guardian',
                'family_id' => $staff ? null : self::familyOf((int) $r->id, $agencyId), 'email' => (string) $r->email];
        }
        if ($type === 'contact') {
            $c = DB::table('contacts')->whereNull('deleted_at')->where('agency_id', $agencyId)->where('id', $id)->first(['id', 'first_name', 'last_name', 'company', 'email']);

            return $c ? ['type' => 'contact', 'id' => (int) $c->id, 'name' => trim(self::name($c) ?: (string) $c->company), 'as' => 'contact', 'family_id' => null, 'email' => (string) $c->email] : null;
        }

        return null;
    }

    /** People in the agency for the picker, by name or address (live only, at most 20). */
    public static function search(int $agencyId, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
        $out = [];
        $rows = DB::table('users as u')->join('role_assignments as r', 'r.user_id', '=', 'u.id')
            ->whereNull('u.deleted_at')->whereNotIn('u.status', self::CLOSED_USER)
            ->where('r.active', 1)->where('r.agency_id', $agencyId)->whereIn('r.role', array_merge(['guardian'], self::STAFF_ROLES))
            ->where(fn ($w) => $w->whereRaw("LOWER(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) LIKE ?", [$like])
                ->orWhereRaw('LOWER(u.preferred_name) LIKE ?', [$like])->orWhereRaw('LOWER(u.email) LIKE ?', [$like]))
            ->orderBy('u.first_name')->limit(40)->get(['u.id', 'u.first_name', 'u.last_name', 'u.preferred_name', 'u.email', 'r.role']);
        foreach ($rows as $r) {
            $k = 'user:' . $r->id;
            $as = $r->role === 'guardian' ? 'guardian' : 'staff';
            if (isset($out[$k]) && $out[$k]['as'] === 'staff') continue;
            $out[$k] = ['type' => 'user', 'id' => (int) $r->id, 'name' => self::name($r), 'as' => $as, 'email' => (string) $r->email];
        }
        $rows = DB::table('contacts')->whereNull('deleted_at')->where('agency_id', $agencyId)
            ->where(fn ($w) => $w->whereRaw("LOWER(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) LIKE ?", [$like])
                ->orWhereRaw('LOWER(company) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]))
            ->orderBy('first_name')->limit(20)->get(['id', 'first_name', 'last_name', 'company', 'email']);
        foreach ($rows as $c) {
            $out['contact:' . $c->id] = ['type' => 'contact', 'id' => (int) $c->id, 'name' => trim(self::name($c) ?: (string) $c->company), 'as' => 'contact', 'email' => (string) $c->email];
        }

        return array_slice(array_values($out), 0, 20);
    }

    private static function name(object $r): string
    {
        $first = trim((string) (($r->preferred_name ?? '') ?: ($r->first_name ?? '')));

        return trim($first . ' ' . trim((string) ($r->last_name ?? '')));
    }

    /** The guardian's family in this agency (live family on a centre of the agency), or null. */
    private static function familyOf(int $userId, int $agencyId): ?int
    {
        $id = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('g.user_id', $userId)->whereNull('f.deleted_at')->where('c.agency_id', $agencyId)
            ->orderByDesc('g.is_primary')->value('f.id');

        return $id ? (int) $id : null;
    }

    public static function key(?string $internetMessageId, string $fallback): string
    {
        return hash('sha256', (string) ($internetMessageId ?: $fallback));
    }

    /**
     * The facts kept about a Graph message: key, uid, direction, from, to, subject, preview, attachments, when.
     * $dir 'in' (Inbox) or 'out' (Sent Items).
     */
    public static function graphMeta(array $m, string $dir): array
    {
        $to = [];
        foreach (['toRecipients', 'ccRecipients', 'bccRecipients'] as $f) {
            foreach ((array) ($m[$f] ?? []) as $r) { $x = self::norm($r['emailAddress']['address'] ?? ''); if ($x !== '') $to[$x] = true; }
        }
        $when = (string) (($m['receivedDateTime'] ?? '') ?: ($m['sentDateTime'] ?? ''));

        return [
            'key' => self::key($m['internetMessageId'] ?? null, (string) ($m['id'] ?? '')),
            'uid' => (string) ($m['id'] ?? ''),
            'direction' => $dir,
            'from_addr' => self::norm($m['from']['emailAddress']['address'] ?? ''),
            'from_name' => mb_substr(trim((string) ($m['from']['emailAddress']['name'] ?? '')), 0, 190),
            'to' => array_keys($to),
            'subject' => mb_substr(trim((string) ($m['subject'] ?? '')), 0, 250),
            'preview' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($m['bodyPreview'] ?? ''))), 0, 600),
            'has_attachments' => ! empty($m['hasAttachments']),
            'at' => $when !== '' ? Carbon::parse($when)->utc() : now(),
        ];
    }

    /**
     * Automatic pass over one message: every live person (of the agency's kinds) the message is from (in) or to (out).
     * $own: the agency's own addresses, never matched. Returns the people linked (or, dry, that would be).
     */
    public static function autoLink(object $acct, array $meta, array $own, bool $dry = false): array
    {
        if (! self::linkable($acct)) return [];
        $s = self::settings((int) $acct->agency_id);
        if (! $s['auto'] || ! $s['kinds']) return [];
        if ($meta['direction'] === 'out' && ! $s['sent']) return [];
        $addrs = $meta['direction'] === 'in' ? [$meta['from_addr']] : $meta['to'];
        $done = [];
        foreach (array_unique($addrs) as $addr) {
            if ($addr === '' || isset($own[$addr])) continue;
            foreach (self::peopleFor((int) $acct->agency_id, $addr, $s['kinds']) as $p) {
                // Never twice, and never back after someone unlinked it.
                $have = DB::table('email_person_links')->where('agency_id', $acct->agency_id)->where('message_key', $meta['key'])
                    ->where('person_type', $p['type'])->where('person_id', $p['id'])->exists();
                if ($have) continue;
                if ($dry || self::write($acct, $meta, $p, 'auto', null)) $done[] = $p;
            }
        }

        return $done;
    }

    /** A person linked by hand. Re-links a row that was unlinked. Returns the link id, or null when already linked. */
    public static function manualLink(object $acct, array $meta, array $p, int $by): ?int
    {
        $row = DB::table('email_person_links')->where('agency_id', $acct->agency_id)->where('message_key', $meta['key'])
            ->where('person_type', $p['type'])->where('person_id', $p['id'])->first(['id', 'unlinked_at']);
        if ($row && ! $row->unlinked_at) return null;
        if ($row) {
            DB::table('email_person_links')->where('id', $row->id)->update(['unlinked_at' => null, 'unlinked_by' => null, 'source' => 'manual', 'linked_by' => $by, 'updated_at' => now()]);
            self::audit($acct, $p, 'email.linked', (int) $row->id, $meta, 'manual', $by);

            return (int) $row->id;
        }

        return self::write($acct, $meta, $p, 'manual', $by);
    }

    public static function unlink(object $link, int $by): void
    {
        DB::table('email_person_links')->where('id', $link->id)->whereNull('unlinked_at')->update(['unlinked_at' => now(), 'unlinked_by' => $by, 'updated_at' => now()]);
        $acct = (object) ['id' => $link->account_id, 'agency_id' => $link->agency_id];
        $p = self::person((int) $link->agency_id, (string) $link->person_type, (int) $link->person_id)
            ?: ['type' => $link->person_type, 'id' => (int) $link->person_id, 'name' => '', 'as' => $link->matched_as, 'family_id' => $link->family_id];
        self::audit($acct, $p, 'email.unlinked', (int) $link->id, ['subject' => (string) $link->subject, 'direction' => $link->direction, 'at' => $link->received_at], $link->source, $by);
    }

    private static function write(object $acct, array $meta, array $p, string $source, ?int $by): ?int
    {
        try {
            $now = now();
            $ok = DB::table('email_person_links')->insertOrIgnore([
                'agency_id' => (int) $acct->agency_id, 'account_id' => (int) $acct->id, 'message_key' => $meta['key'],
                'provider_uid' => mb_substr((string) $meta['uid'], 0, 600), 'direction' => $meta['direction'],
                'from_addr' => $meta['from_addr'] ?: null, 'from_name' => $meta['from_name'] ?: null,
                'to_addrs' => $meta['to'] ? implode(', ', array_slice($meta['to'], 0, 40)) : null,
                'subject' => $meta['subject'] !== '' ? $meta['subject'] : null, 'preview' => $meta['preview'] !== '' ? $meta['preview'] : null,
                'has_attachments' => ! empty($meta['has_attachments']), 'received_at' => $meta['at'],
                'person_type' => $p['type'], 'person_id' => $p['id'], 'family_id' => $p['family_id'] ?? null, 'matched_as' => $p['as'] ?? null,
                'source' => $source, 'linked_by' => $by, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if (! $ok) return null;
            $id = (int) DB::table('email_person_links')->where('agency_id', $acct->agency_id)->where('message_key', $meta['key'])
                ->where('person_type', $p['type'])->where('person_id', $p['id'])->value('id');
            self::audit($acct, $p, 'email.linked', $id, $meta, $source, $by);

            return $id;
        } catch (Throwable $e) {
            Log::warning('PersonMail: could not link', ['account' => $acct->id ?? null, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private static function audit(object $acct, array $p, string $action, int $linkId, array $meta, string $source, ?int $by): void
    {
        $what = $action === 'email.linked' ? 'linked to' : 'unlinked from';
        $dir = ($meta['direction'] ?? 'in') === 'out' ? 'sent' : 'received';
        Audit::write([
            'user_id' => $by, 'agency_id' => (int) $acct->agency_id, 'action' => $action,
            'entity_type' => $p['type'] === 'contact' ? 'contact' : 'user', 'entity_id' => (int) $p['id'],
            'payload' => json_encode([
                'summary' => 'Email ' . $dir . ' ' . $what . ' ' . ($p['name'] ?: ($p['type'] . ' ' . $p['id'])) . ($source === 'auto' ? ' (matched by address)' : ''),
                'link_id' => $linkId, 'account_id' => (int) $acct->id, 'family_id' => $p['family_id'] ?? null, 'source' => $source,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }

    /** The emails linked to a person (newest first), for their record. */
    public static function forPerson(int $agencyId, string $type, int $id, int $limit = 100): array
    {
        return self::rows(DB::table('email_person_links')->where('agency_id', $agencyId)->where('person_type', $type)->where('person_id', $id), $limit);
    }

    public static function forFamily(int $agencyId, int $familyId, int $limit = 100): array
    {
        return self::rows(DB::table('email_person_links')->where('agency_id', $agencyId)->where('family_id', $familyId), $limit);
    }

    /* HOW MANY (2026-11-11, Anthony: "in the email subtab add a count on how many emails"). The list is capped; the
       count is every linked email on the record, so the tab says the true number even when only the newest are sent. */
    public static function countForPerson(int $agencyId, string $type, int $id): int
    {
        return (int) DB::table('email_person_links')->where('agency_id', $agencyId)->where('person_type', $type)->where('person_id', $id)->whereNull('unlinked_at')->count();
    }

    public static function countForFamily(int $agencyId, int $familyId): int
    {
        return (int) DB::table('email_person_links')->where('agency_id', $agencyId)->where('family_id', $familyId)->whereNull('unlinked_at')->count();
    }

    private static function rows($q, int $limit): array
    {
        $rows = $q->whereNull('unlinked_at')->orderByDesc('received_at')->limit($limit)->get();
        $boxes = DB::table('email_accounts')->whereIn('id', $rows->pluck('account_id')->unique())->get(['id', 'email_address', 'user_id'])->keyBy('id');
        $names = DB::table('users')->whereIn('id', $rows->pluck('linked_by')->filter()->unique())->get(['id', 'first_name', 'preferred_name'])->keyBy('id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'direction' => $r->direction, 'from' => $r->from_name ?: $r->from_addr, 'from_addr' => $r->from_addr,
            'to' => $r->to_addrs, 'subject' => $r->subject ?: '(no subject)', 'preview' => (string) $r->preview,
            'has_attachments' => (bool) $r->has_attachments, 'at' => $r->received_at ? Carbon::parse($r->received_at, 'UTC')->toIso8601String() : null,
            'mailbox' => $boxes[$r->account_id]->email_address ?? null, 'account_id' => (int) $r->account_id,
            'mailbox_owner_id' => isset($boxes[$r->account_id]) ? (int) $boxes[$r->account_id]->user_id : null,
            'uid' => $r->provider_uid, 'person_type' => $r->person_type, 'person_id' => (int) $r->person_id, 'matched_as' => $r->matched_as,
            'source' => $r->source, 'linked_by' => $r->linked_by ? trim((string) (($names[$r->linked_by]->preferred_name ?? '') ?: ($names[$r->linked_by]->first_name ?? ''))) : null,
        ])->all();
    }
}
