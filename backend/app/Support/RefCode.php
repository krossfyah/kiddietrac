<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * VISIBLE REFERENCE IDS (2026-10-14).
 *
 * Anthony: "does kiddietrac have a unique id assigned to each user/child/staff? can this be made
 * visible on documents, records, portal etc?" He chose agency prefix + running number:
 *
 *     ILH-C-0042   child          ILH-F-0017   family
 *     ILH-S-0008   staff member   ILH-P-0031   parent / guardian
 *
 * WHY NOT THE DATABASE ID. users.id / children.id count up across every agency together, so
 * showing them tells one agency how many records the whole platform holds, and makes a
 * neighbour's record one digit away. The visible number counts per agency, per kind.
 *
 * ONLY THE NUMBER IS STORED (children.ref_no / families.ref_no + ref_agency_id; user_refs for
 * people, one row per agency and kind). The code is assembled from the agency's CURRENT prefix,
 * so changing the prefix changes it everywhere at once, and lookup() still accepts a code typed
 * with an old prefix — a printed form from last year must still find its child.
 *
 * A person's kind is S if they hold any staff role at the agency, P if they are a guardian
 * there. Someone who is both gets both (a staff member whose own child attends).
 *
 * ASSIGNMENT. Children, families and accounts are inserted from dozens of places with the query
 * builder, so there is no single insert to hook. Numbers are given by sweep() — every minute
 * (kiddietrac:ref-codes) — and immediately by the ensure*() calls on the record screens, so a
 * record never shows without one. Numbers are never reused or renumbered.
 */
final class RefCode
{
    public const TYPES = ['C' => 'child', 'F' => 'family', 'S' => 'staff', 'P' => 'parent'];

    private const STAFF_ROLES = ['agency_admin', 'centre_director', 'educator', 'home_visitor', 'auditor', 'cook', 'staff', 'bookkeeper', 'supervisor'];

    /** @var array<int,string> */
    private static array $prefixMemo = [];

    // ── Prefix ─────────────────────────────────────────────────────────────

    public static function prefix(int $agencyId): string
    {
        if (isset(self::$prefixMemo[$agencyId])) return self::$prefixMemo[$agencyId];
        $a = DB::table('agencies')->where('id', $agencyId)->first(['name', 'ref_prefix']);
        $p = $a ? (string) ($a->ref_prefix ?? '') : '';
        if ($p === '') $p = self::defaultPrefix((string) ($a->name ?? 'KT'), $agencyId);

        return self::$prefixMemo[$agencyId] = $p;
    }

    /** Initials of the agency's name, 2–4 letters: "iLearn Home Childcare Inc" → ILH. */
    public static function defaultPrefix(string $name, ?int $exceptAgencyId = null): string
    {
        $clean = preg_replace('/\(.*?\)/', ' ', $name);
        $words = preg_split('/[^A-Za-z0-9]+/', (string) $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $skip = ['inc', 'ltd', 'llc', 'corp', 'co', 'the', 'of', 'and', 'demo', 'limited', 'incorporated'];
        $words = array_values(array_filter($words, fn ($w) => ! in_array(strtolower($w), $skip, true)));
        $p = strtoupper(implode('', array_map(fn ($w) => $w[0], array_slice($words, 0, 4))));
        if (strlen($p) < 2) $p = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $clean) . 'KT', 0, 3));

        return substr($p, 0, 4);
    }

    /** A prefix an admin may choose: 2–6 letters/digits, starting with a letter, not taken. */
    public static function validPrefix(string $p, int $agencyId): ?string
    {
        $p = strtoupper(trim($p));
        if (! preg_match('/^[A-Z][A-Z0-9]{1,5}$/', $p)) return 'Use 2 to 6 letters or digits, starting with a letter.';
        foreach (DB::table('agencies')->where('id', '!=', $agencyId)->whereNull('deleted_at')->get(['id', 'name', 'ref_prefix']) as $o) {
            if (self::prefix((int) $o->id) === $p) return 'Another agency already uses ' . $p . '.';
        }

        return null;
    }

    public static function setPrefix(int $agencyId, string $p): void
    {
        $old = self::prefix($agencyId);
        $p = strtoupper(trim($p));
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        // Remember earlier prefixes so codes printed before the change still look up.
        $hist = array_values(array_unique(array_filter(array_merge((array) ($s['ref_prefix_history'] ?? []), [$old]), fn ($x) => $x && $x !== $p)));
        $s['ref_prefix_history'] = $hist;
        DB::table('agencies')->where('id', $agencyId)->update(['ref_prefix' => $p, 'settings' => json_encode($s)]);
        unset(self::$prefixMemo[$agencyId]);
    }

    public static function format(int $agencyId, string $type, ?int $no): ?string
    {
        if (! $no) return null;

        return self::prefix($agencyId) . '-' . $type . '-' . str_pad((string) $no, 4, '0', STR_PAD_LEFT);
    }

    // ── Numbers ────────────────────────────────────────────────────────────

    /** The next number for this agency and kind — atomic, never reused. */
    private static function next(int $agencyId, string $type): int
    {
        DB::statement('INSERT IGNORE INTO agency_ref_counters (agency_id, type, last_no) VALUES (?, ?, 0)', [$agencyId, $type]);
        DB::update('UPDATE agency_ref_counters SET last_no = LAST_INSERT_ID(last_no + 1) WHERE agency_id = ? AND type = ?', [$agencyId, $type]);

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
    }

    private static function familyAgency(int $familyId): ?int
    {
        $a = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')->where('f.id', $familyId)->value('c.agency_id');

        return $a ? (int) $a : null;
    }

    public static function ensureFamily(int $familyId): ?string
    {
        $f = DB::table('families')->where('id', $familyId)->first(['id', 'ref_no', 'ref_agency_id']);
        if (! $f) return null;
        if ($f->ref_no && $f->ref_agency_id) return self::format((int) $f->ref_agency_id, 'F', (int) $f->ref_no);
        $agency = self::familyAgency($familyId);
        if (! $agency) return null;
        $no = self::next($agency, 'F');
        $n = DB::table('families')->where('id', $familyId)->whereNull('ref_no')->update(['ref_no' => $no, 'ref_agency_id' => $agency]);
        if (! $n) return self::ensureFamily($familyId);     // someone else numbered it first

        return self::format($agency, 'F', $no);
    }

    public static function ensureChild(int $childId): ?string
    {
        $c = DB::table('children')->where('id', $childId)->first(['id', 'family_id', 'ref_no', 'ref_agency_id']);
        if (! $c) return null;
        if ($c->ref_no && $c->ref_agency_id) return self::format((int) $c->ref_agency_id, 'C', (int) $c->ref_no);
        $agency = $c->family_id ? self::familyAgency((int) $c->family_id) : null;
        if (! $agency) return null;
        $no = self::next($agency, 'C');
        $n = DB::table('children')->where('id', $childId)->whereNull('ref_no')->update(['ref_no' => $no, 'ref_agency_id' => $agency]);
        if (! $n) return self::ensureChild($childId);

        return self::format($agency, 'C', $no);
    }

    /** A person's code at an agency. $type null = staff if they are staff there, else parent. */
    public static function ensureUser(int $userId, int $agencyId, ?string $type = null): ?string
    {
        $type = $type ?: (self::isStaff($userId, $agencyId) ? 'S' : (self::isGuardian($userId, $agencyId) ? 'P' : null));
        if (! $type) return null;
        $row = DB::table('user_refs')->where('user_id', $userId)->where('agency_id', $agencyId)->where('type', $type)->first();
        if ($row) return self::format($agencyId, $type, (int) $row->ref_no);
        $no = self::next($agencyId, $type);
        $ok = DB::table('user_refs')->insertOrIgnore(['user_id' => $userId, 'agency_id' => $agencyId, 'type' => $type, 'ref_no' => $no, 'created_at' => now()]);
        if (! $ok) return self::ensureUser($userId, $agencyId, $type);

        return self::format($agencyId, $type, $no);
    }

    public static function isStaff(int $userId, int $agencyId): bool
    {
        return DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $userId)->where('ra.active', 1)->where('ra.role', '!=', 'guardian')->where('ra.role', '!=', 'platform_admin')
            ->where(fn ($q) => $q->where('ra.agency_id', $agencyId)->orWhere('c.agency_id', $agencyId))->exists();
    }

    public static function isGuardian(int $userId, int $agencyId): bool
    {
        return DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('g.user_id', $userId)->where('c.agency_id', $agencyId)->exists();
    }

    // ── Reading many at once (lists, exports) ──────────────────────────────

    /** @param int[] $ids @return array<int,string> child id => code */
    public static function children(array $ids): array
    {
        return self::rows('children', 'C', $ids);
    }

    /** @param int[] $ids @return array<int,string> family id => code */
    public static function families(array $ids): array
    {
        return self::rows('families', 'F', $ids);
    }

    private static function rows(string $table, string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) return [];
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table($table)->whereIn('id', $chunk)->whereNotNull('ref_no')->get(['id', 'ref_no', 'ref_agency_id']) as $r) {
                $out[(int) $r->id] = self::format((int) $r->ref_agency_id, $type, (int) $r->ref_no);
            }
        }

        return $out;
    }

    /** @param int[] $userIds @return array<int,string> user id => code at this agency (staff code preferred) */
    public static function users(array $userIds, int $agencyId, ?string $prefer = null): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (! $userIds) return [];
        $out = [];
        $rows = DB::table('user_refs')->where('agency_id', $agencyId)->whereIn('user_id', $userIds)->get(['user_id', 'type', 'ref_no']);
        foreach ($rows as $r) {
            $uid = (int) $r->user_id;
            $code = self::format($agencyId, $r->type, (int) $r->ref_no);
            $want = $prefer ?: 'S';
            if (! isset($out[$uid]) || $r->type === $want) $out[$uid] = $code;
        }

        return $out;
    }

    /** Every code a person has at an agency: ['S' => ..., 'P' => ...]. */
    public static function userCodes(int $userId, int $agencyId): array
    {
        $out = [];
        foreach (DB::table('user_refs')->where('user_id', $userId)->where('agency_id', $agencyId)->get(['type', 'ref_no']) as $r) {
            $out[$r->type] = self::format($agencyId, $r->type, (int) $r->ref_no);
        }

        return $out;
    }

    /** @param int[] $userIds @return array<int, array{S?:string,P?:string}> every code each person has at the agency */
    public static function userCodesMany(array $userIds, int $agencyId): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (! $userIds || ! $agencyId) return [];
        $out = [];
        foreach (array_chunk($userIds, 1000) as $chunk) {
            foreach (DB::table('user_refs')->where('agency_id', $agencyId)->whereIn('user_id', $chunk)->get(['user_id', 'type', 'ref_no']) as $r) {
                $out[(int) $r->user_id][$r->type] = self::format($agencyId, $r->type, (int) $r->ref_no);
            }
        }

        return $out;
    }

    // ── Lookup ─────────────────────────────────────────────────────────────

    /**
     * "ILH-C-0042" (or "ilh c 42", or an old prefix) → ['type' => 'C', 'id' => child id, 'agency_id' => …],
     * only within the given agency. Null when it is not a code or not found there.
     */
    public static function lookup(string $code, int $agencyId): ?array
    {
        if (! preg_match('/^\s*([A-Za-z][A-Za-z0-9]{1,5})[\s\-_.#]*([CFSPcfsp])[\s\-_.#]*0*(\d{1,7})\s*$/', $code, $m)) return null;
        $prefix = strtoupper($m[1]); $type = strtoupper($m[2]); $no = (int) $m[3];
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $hist = (array) ((json_decode((string) $raw, true) ?: [])['ref_prefix_history'] ?? []);
        if ($prefix !== self::prefix($agencyId) && ! in_array($prefix, $hist, true)) return null;
        $id = match ($type) {
            'C' => DB::table('children')->where('ref_agency_id', $agencyId)->where('ref_no', $no)->value('id'),
            'F' => DB::table('families')->where('ref_agency_id', $agencyId)->where('ref_no', $no)->value('id'),
            default => DB::table('user_refs')->where('agency_id', $agencyId)->where('type', $type)->where('ref_no', $no)->value('user_id'),
        };

        return $id ? ['type' => $type, 'kind' => self::TYPES[$type], 'id' => (int) $id, 'agency_id' => $agencyId,
            'code' => self::format($agencyId, $type, $no)] : null;
    }

    // ── Search ─────────────────────────────────────────────────────────────

    /**
     * Records whose ID matches what was typed — in full, in part or loosely. Returns
     * ['C' => [child ids], 'F' => [...], 'S' => [user ids], 'P' => [...]], or null when the
     * text does not look like an ID at all (so callers search names as usual).
     *
     *   "ILH-C-0042" / "ilh c 42" / "ILHC42"  → that child
     *   "ILH-C-00" / "ILH-C-4"                → children whose number starts 0…/4…
     *   "C-0042" / "c42"                      → child 42 (any kind letter works)
     *   "ILH-" / "ILH C"                      → that agency's records (a short list)
     *   "42" / "0042"                         → every kind numbered 42 (and 420…)
     *
     * Only this agency's prefix (or one it used before) counts; another agency's code finds
     * nothing. Numbers match from the left, so leading zeros never get in the way.
     */
    public static function searchIds(string $q, int $agencyId, int $limit = 25): ?array
    {
        $raw = trim($q);
        $qn = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
        if ($qn === '' || strlen($qn) > 14) return null;
        $prefixes = [self::prefix($agencyId)];
        $settings = DB::table('agencies')->where('id', $agencyId)->value('settings');
        foreach ((array) ((json_decode((string) $settings, true) ?: [])['ref_prefix_history'] ?? []) as $h) $prefixes[] = strtoupper((string) $h);
        usort($prefixes, fn ($a, $b) => strlen($b) - strlen($a));

        $type = null; $digits = ''; $isId = false;
        foreach ($prefixes as $p) {
            if ($p !== '' && str_starts_with($qn, $p) && preg_match('/^([CFSP])?(\d*)$/', substr($qn, strlen($p)), $m)) {
                // A bare prefix ("ILH") is also a word; only treat it as an ID once more is typed.
                if ($m[1] === '' && $m[2] === '' && ! preg_match('/[\s\-_.#]$/', $raw)) break;
                $type = $m[1] ?: null; $digits = $m[2]; $isId = true;
                break;
            }
        }
        if (! $isId && preg_match('/^([CFSP])(\d{1,7})$/', $qn, $m)) { $type = $m[1]; $digits = $m[2]; $isId = true; }
        if (! $isId && preg_match('/^\d{1,7}$/', $qn)) { $digits = $qn; $isId = true; }
        if (! $isId) return null;

        /* "0042" / "00" are read against the zero-padded ID (0042 → 42 only; 00 → 1–99);
           "42" / "4" against the plain number (42, 420…; 4, 40–49…). */
        $num = ltrim($digits, '0');
        $padded = $digits !== '' && $digits[0] === '0';
        $numCond = function ($q) use ($digits, $num, $padded) {
            if ($digits !== '') {
                $padded ? $q->whereRaw("LPAD(ref_no, 4, '0') LIKE ?", [$digits . '%'])
                        : $q->whereRaw('CAST(ref_no AS CHAR) LIKE ?', [$num . '%']);
            }
            return $q->orderByRaw('ref_no = ? DESC', [(int) ($num ?: 0)])->orderBy('ref_no');
        };
        $want = $type ? [$type] : ['C', 'F', 'S', 'P'];
        $out = ['C' => [], 'F' => [], 'S' => [], 'P' => []];
        foreach ($want as $t) {
            if ($t === 'C' || $t === 'F') {
                $out[$t] = $numCond(DB::table($t === 'C' ? 'children' : 'families')->where('ref_agency_id', $agencyId)->whereNotNull('ref_no'))
                    ->limit($limit)->pluck('id')->map(fn ($x) => (int) $x)->all();
            } else {
                $out[$t] = $numCond(DB::table('user_refs')->where('agency_id', $agencyId)->where('type', $t))
                    ->limit($limit)->pluck('user_id')->map(fn ($x) => (int) $x)->all();
            }
        }

        return $out;
    }

    // ── Sweep ──────────────────────────────────────────────────────────────

    /** Number everything that has no number yet, oldest first. Returns counts by kind. */
    public static function sweep(?int $onlyAgency = null): array
    {
        $n = ['F' => 0, 'C' => 0, 'S' => 0, 'P' => 0];
        $fam = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')->whereNull('f.ref_no')
            ->when($onlyAgency, fn ($q) => $q->where('c.agency_id', $onlyAgency))->orderBy('f.id')->pluck('f.id');
        foreach ($fam as $id) { if (self::ensureFamily((int) $id)) $n['F']++; }

        $kids = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->whereNull('ch.ref_no')->when($onlyAgency, fn ($q) => $q->where('c.agency_id', $onlyAgency))->orderBy('ch.id')->pluck('ch.id');
        foreach ($kids as $id) { if (self::ensureChild((int) $id)) $n['C']++; }

        // Staff: any active non-guardian role tied to an agency (directly or via its centre).
        $staff = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->whereNotIn('ra.role', ['guardian', 'platform_admin'])
            ->selectRaw('ra.user_id, COALESCE(ra.agency_id, c.agency_id) as agency_id, MIN(u.id) as o')
            ->groupBy('ra.user_id', DB::raw('COALESCE(ra.agency_id, c.agency_id)'))->orderBy('o')->get();
        $have = DB::table('user_refs')->get(['user_id', 'agency_id', 'type'])->map(fn ($r) => $r->user_id . ':' . $r->agency_id . ':' . $r->type)->flip();
        foreach ($staff as $s) {
            if (! $s->agency_id || ($onlyAgency && (int) $s->agency_id !== $onlyAgency)) continue;
            if (isset($have[$s->user_id . ':' . $s->agency_id . ':S'])) continue;
            if (self::ensureUser((int) $s->user_id, (int) $s->agency_id, 'S')) $n['S']++;
        }
        // Parents/guardians: linked to a family at the agency.
        $par = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->when($onlyAgency, fn ($q) => $q->where('c.agency_id', $onlyAgency))
            ->selectRaw('g.user_id, c.agency_id')->groupBy('g.user_id', 'c.agency_id')->orderBy('g.user_id')->get();
        foreach ($par as $p) {
            if (isset($have[$p->user_id . ':' . $p->agency_id . ':P'])) continue;
            if (self::ensureUser((int) $p->user_id, (int) $p->agency_id, 'P')) $n['P']++;
        }

        return $n;
    }
}
