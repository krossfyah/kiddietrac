<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SIGN-IN LOCKS (2026-11-10). Anthony: "add ability to suspend/lock user from logging in and unlock when we are ready and
 * lock provider/educator which locks all parents/users under that agency"; "when locking a provider/centre it should not
 * lock out parent who has a child with another centre"; "when unlocking provider/centre it should unlock all parents
 * associated that were initially locked".
 *
 * A lock pauses SIGN-IN only, inside ONE agency. It never touches users.status, roles, families or children, so lifting
 * it puts everything back exactly as it was, and email, texts and push carry on throughout (Anthony: "Sign-in only").
 * One row per lock in sign_in_locks:
 *   user   - one person at this agency. A person who also belongs to another agency keeps that one (person vs tenant).
 *   centre - a centre or home provider: everyone whose ONLY ties to this agency run through locked centres. A tie is a
 *            staff role at the centre, a family at the centre, or a child in one of its rooms (primary room or an open
 *            enrolment). A tie to any unlocked centre, or an agency-wide role (admin, home visitor), keeps them in.
 *   agency - everyone at the agency. Platform admins only.
 * Worked out LIVE from the open rows, never copied onto people: unlocking a centre restores exactly the people it held,
 * and anyone who also has their own lock (or every one of their centres locked) stays out until that is lifted too.
 * sign_in_lock_people records who a lock held when it was made, for the unlock report; it never decides access.
 * Platform admins are never locked. Enforced at every sign-in (refusal()) and on every API call (EnforceSignInLocks):
 * tokens are NOT deleted, so a signed-in person's next call answers 423 with who to contact and the app signs them out
 * (a deleted token would only have said "session expired").
 * EXCEPTIONS (sign_in_lock_exceptions, Anthony 2026-11-10: "is there an unlock feature to unlock users individually?" -
 * "yes"): one person let in despite ONE centre lock, while the centre stays locked for everyone else. Only centre locks
 * take exceptions - a person's own lock is simply unlocked, an agency lock is KiddieTrac's. An exception is tied to that
 * lock: another centre lock, their own lock or an agency lock still keeps them out, and it ends when the lock is lifted.
 */
final class SignInLocks
{
    public const SCOPES = ['user', 'centre', 'agency'];

    private const ROLE_WORDS = ['guardian' => 'Parent', 'educator' => 'Educator', 'centre_director' => 'Director',
        'home_visitor' => 'Home visitor', 'agency_admin' => 'Admin', 'auditor' => 'Auditor'];

    /** @var array<int, Collection> open locks per agency, for this request */
    private static array $open = [];
    private static ?bool $any = null;
    /** @var array<int, array<int, array<int, true>>> open exceptions per agency: [lock id][user id] */
    private static array $exc = [];

    public static function forget(): void
    {
        self::$open = [];
        self::$any = null;
        self::$exc = [];
    }

    /** Open exceptions at this agency, [lock id => [user id => true]]. */
    public static function exceptions(int $agencyId): array
    {
        if (! isset(self::$exc[$agencyId])) {
            $out = [];
            foreach (DB::table('sign_in_lock_exceptions')->where('agency_id', $agencyId)->whereNull('ended_at')->get(['lock_id', 'user_id']) as $e) {
                $out[(int) $e->lock_id][(int) $e->user_id] = true;
            }
            self::$exc[$agencyId] = $out;
        }

        return self::$exc[$agencyId];
    }

    /* SIGN OUT + PAUSE PUSH (2026-11-10, Anthony: "when locking their accounts did it automatically sign them out of all
       instances" -> "Sign out now + stop push"). Someone a lock leaves locked out EVERYWHERE loses every session at once,
       and app notifications pause while they stay that way. Someone with another, unlocked agency keeps both: they
       still use KiddieTrac there. Email and SMS are untouched (the lock is sign-in only). */
    private static int $freshAt = 0;

    /** Sign these people out of every device if they are now locked out everywhere. Returns how many were signed out. */
    public static function signOut(array $uids, int $lockId): int
    {
        $n = 0;
        try {
            self::forget();
            DB::table('sign_in_lock_revoked_tokens')->where('created_at', '<', now()->subDays(30))->delete();
            foreach (array_unique(array_map('intval', $uids)) as $uid) {
                if (! $uid || ! self::atSignIn($uid)) continue;
                $toks = DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)
                    ->where('tokenable_id', $uid)->get(['id', 'token']);
                if ($toks->isEmpty()) continue;
                // Only the hash Sanctum already stored, so an open screen can still be told why (revokedAnswer).
                $rows = $toks->map(fn ($t) => ['token_hash' => $t->token, 'user_id' => $uid, 'lock_id' => $lockId, 'created_at' => now()])->all();
                foreach (array_chunk($rows, 500) as $c) DB::table('sign_in_lock_revoked_tokens')->insertOrIgnore($c);
                DB::table('personal_access_tokens')->whereIn('id', $toks->pluck('id')->all())->delete();
                $n++;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $n;
    }

    /** A request whose session a lock ended: 423 with the agency's words while still locked, else null (plain 401). */
    public static function revokedAnswer(?string $bearer): ?JsonResponse
    {
        try {
            if (! $bearer || ! str_contains($bearer, '|')) return null;
            [, $plain] = explode('|', $bearer, 2);
            $uid = DB::table('sign_in_lock_revoked_tokens')->where('token_hash', hash('sha256', $plain))->value('user_id');
            if (! $uid) return null;
            self::forget();
            $l = self::atSignIn((int) $uid);

            return $l ? response()->json(['access_locked' => true, 'message' => self::message((int) $l->agency_id)], 423) : null;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    /** No push to someone locked out everywhere. Workers live long: locks re-read every 30 s. A fault silences no one. */
    public static function pushPaused(int $uid): bool
    {
        try {
            if (time() - self::$freshAt > 30) { self::forget(); self::$freshAt = time(); }

            return self::atSignIn($uid) !== null;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    public static function anyOpen(): bool
    {
        return self::$any ??= DB::table('sign_in_locks')->whereNull('unlocked_at')->exists();
    }

    public static function open(int $agencyId): Collection
    {
        return self::$open[$agencyId] ??= DB::table('sign_in_locks')->where('agency_id', $agencyId)
            ->whereNull('unlocked_at')->orderBy('id')->get();
    }

    public static function isPlatformAdmin(int $uid): bool
    {
        return DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', 1)->exists();
    }

    /** Every agency this person belongs to: a staff role (on the agency or one of its centres) or a family. */
    public static function agenciesOf(int $uid): array
    {
        $ra = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $uid)->where('ra.active', 1)
            ->selectRaw('COALESCE(ra.agency_id, c.agency_id) as aid')->pluck('aid');
        $fam = Live::families(DB::table('guardians as g')->join('families', 'families.id', '=', 'g.family_id'))
            ->join('centres as c', 'c.id', '=', 'families.centre_id')
            ->where('g.user_id', $uid)->pluck('c.agency_id');

        return $ra->merge($fam)->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /**
     * How one person is tied to one agency. wide = an agency-wide staff role (not a centre's). centres = every centre of
     * this agency they reach through a staff role, a family, or a child's room - a child whose room is at another centre
     * than the family's counts for both.
     */
    public static function ties(int $uid, int $agencyId): array
    {
        $agencyCentres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (! $agencyCentres) return ['wide' => false, 'centres' => []];

        $wide = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->where('agency_id', $agencyId)
            ->whereNull('centre_id')->where('role', '!=', 'guardian')->exists();
        $centres = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->whereIn('centre_id', $agencyCentres)->pluck('centre_id')->all();

        $families = Live::families(DB::table('families'))->whereIn('centre_id', $agencyCentres)
            ->whereIn('id', DB::table('guardians')->where('user_id', $uid)->select('family_id'))->get(['id', 'centre_id']);
        if ($families->isNotEmpty()) {
            foreach ($families as $f) $centres[] = $f->centre_id;
            $kids = Live::children(DB::table('children'))->whereIn('family_id', $families->pluck('id'))->get(['id', 'primary_room_id']);
            if ($kids->isNotEmpty()) {
                $rooms = $kids->pluck('primary_room_id')->filter()->all();
                $enrolled = Live::enrolments(DB::table('enrollments'), 'enrollments', AgencyTime::today($agencyId))
                    ->whereIn('child_id', $kids->pluck('id'))->pluck('room_id')->filter()->all();
                $rooms = array_values(array_unique(array_merge($rooms, $enrolled)));
                if ($rooms) $centres = array_merge($centres, DB::table('rooms')->whereIn('id', $rooms)->pluck('centre_id')->all());
            }
        }
        $centres = array_values(array_intersect(array_unique(array_map('intval', array_filter($centres))), $agencyCentres));

        return ['wide' => $wide, 'centres' => $centres];
    }

    /** The open lock that keeps this person out of this agency, or null. $locks overrides the open set (previews). */
    public static function blocking(int $uid, int $agencyId, ?Collection $locks = null): ?object
    {
        $locks = $locks ?? self::open($agencyId);
        if ($locks->isEmpty() || self::isPlatformAdmin($uid)) return null;
        foreach ($locks as $l) if ($l->scope === 'user' && (int) $l->target_id === $uid) return $l;
        foreach ($locks as $l) if ($l->scope === 'agency') return $l;

        $exc = self::exceptions($agencyId);
        $byCentre = $locks->where('scope', 'centre')->reject(fn ($l) => isset($exc[(int) $l->id][$uid]))   // let in by exception
            ->keyBy(fn ($l) => (int) $l->target_id);
        if ($byCentre->isEmpty()) return null;
        $t = self::ties($uid, $agencyId);
        if ($t['wide'] || ! $t['centres']) return null;
        foreach ($t['centres'] as $c) if (! $byCentre->has($c)) return null;   // a tie to an unlocked centre keeps them in

        return $byCentre->get($t['centres'][0]);
    }

    /**
     * Who of these people is kept out of this agency, for every screen that shows a person's status (Anthony 2026-11-10:
     * "two users are now locked but the status on those user accounts didn't change"). users.status stays 'active' under
     * a lock on purpose, so the lock is shown BESIDE it: [user id => {lock_id, scope, label, since, by, exception}].
     * Members of this agency only; no open lock here = no work.
     */
    public static function lockMap(array $uids, int $agencyId): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (! $uids || self::open($agencyId)->isEmpty()) return [];
        $members = array_flip(self::candidates('agency', $agencyId, $agencyId));
        $tz = AgencyTime::tz($agencyId);
        $out = [];
        foreach ($uids as $uid) {
            if (! isset($members[$uid])) continue;
            $l = self::blocking($uid, $agencyId);
            if (! $l) continue;
            $out[$uid] = ['lock_id' => (int) $l->id, 'scope' => $l->scope,
                'label' => self::targetLabel($l->scope, (int) $l->target_id, $agencyId),
                'since' => \Illuminate\Support\Carbon::parse($l->locked_at, 'UTC')->setTimezone($tz)->format('M j, Y g:i A')];
        }

        return $out;
    }

    /** The lock to show at sign-in: only when EVERY agency they belong to has them locked. */
    public static function atSignIn(int $uid): ?object
    {
        if (! self::anyOpen() || self::isPlatformAdmin($uid)) return null;
        $aids = self::agenciesOf($uid);
        if (! $aids) return null;
        $first = null;
        foreach ($aids as $aid) {
            $l = self::blocking($uid, $aid);
            if (! $l) return null;
            $first ??= $l;
        }

        return $first;
    }

    /** One of this person's agencies where they are NOT locked, for someone who belongs to more than one. */
    public static function openAgencyFor(int $uid): ?int
    {
        foreach (self::agenciesOf($uid) as $aid) if (! self::blocking($uid, $aid)) return $aid;

        return null;
    }

    /** The answer for a sign-in a lock refuses (423), or null to carry on. A fault here never blocks a sign-in. */
    public static function refusal(int $uid, ?Request $request = null, string $via = 'login'): ?JsonResponse
    {
        try {
            $l = self::atSignIn($uid);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
        if (! $l) return null;
        try {
            Audit::write(['user_id' => $uid, 'agency_id' => (int) $l->agency_id, 'action' => 'login_blocked', 'entity_type' => 'user',
                'entity_id' => $uid, 'payload' => json_encode(['reason' => 'access_locked', 'lock_id' => (int) $l->id, 'via' => $via,
                    'summary' => 'Sign-in refused: access is paused (sign-in lock #' . $l->id . ')']), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['access_locked' => true, 'message' => self::message((int) $l->agency_id)], 423);
    }

    /** What a locked person reads. Never the reason: the notes are for the agency, not for them. */
    public static function message(int $agencyId): string
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first(['name', 'contact_email', 'contact_phone']);
        $name = trim((string) ($a->name ?? '')) ?: 'your childcare provider';
        $reach = implode(' or ', array_filter([trim((string) ($a->contact_email ?? '')), trim((string) ($a->contact_phone ?? ''))]));

        return 'Your access to ' . $name . ' is paused for now. Please contact ' . $name . ($reach ? ' (' . $reach . ')' : '')
            . ' if you have any questions.';
    }

    /** Everyone a lock of this kind could reach: the people tied to the target (filtered by blocking() after). */
    public static function candidates(string $scope, int $targetId, int $agencyId): array
    {
        if ($scope === 'user') return [$targetId];
        $centres = $scope === 'agency'
            ? DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all()
            : [$targetId];
        $rooms = DB::table('rooms')->whereIn('centre_id', $centres ?: [0])->pluck('id')->all();

        $staff = DB::table('role_assignments')->where('active', 1)->where('role', '!=', 'platform_admin')
            ->where(function ($q) use ($scope, $agencyId, $centres) {
                $q->whereIn('centre_id', $centres ?: [0]);
                if ($scope === 'agency') $q->orWhere('agency_id', $agencyId);
            })->pluck('user_id');
        $families = Live::families(DB::table('families'))->whereIn('centre_id', $centres ?: [0])->pluck('id');
        if ($rooms) {
            $kidFamilies = Live::children(DB::table('children'))->where(function ($q) use ($rooms, $agencyId) {
                $q->whereIn('primary_room_id', $rooms)
                  ->orWhereIn('id', Live::enrolments(DB::table('enrollments'), 'enrollments', AgencyTime::today($agencyId))
                      ->whereIn('room_id', $rooms)->select('child_id'));
            })->pluck('family_id');
            $families = $families->merge($kidFamilies);
        }
        $parents = DB::table('guardians')->whereIn('family_id', $families->unique()->all() ?: [0])->whereNotNull('user_id')->pluck('user_id');

        return DB::table('users')->whereIn('id', $staff->merge($parents)->unique()->all() ?: [0])->whereNull('deleted_at')
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Who this lock would hold, with every other open lock of the agency in the picture:
     *   held    - newly kept out by it;
     *   already - kept out already by another lock (they stay out after it is lifted unless that one is lifted too);
     *   kept    - tied to the target but keeping access (a child at another centre, an agency-wide role, a platform admin).
     */
    public static function reach(string $scope, int $targetId, int $agencyId, ?int $exceptLockId = null): array
    {
        $others = self::open($agencyId)->reject(fn ($l) => $exceptLockId && (int) $l->id === $exceptLockId)->values();
        $with = $others->concat([(object) ['id' => 0, 'scope' => $scope, 'target_id' => $targetId, 'agency_id' => $agencyId]]);
        $out = ['held' => [], 'already' => [], 'kept' => []];
        foreach (self::candidates($scope, $targetId, $agencyId) as $uid) {
            $before = $others->isEmpty() ? null : self::blocking($uid, $agencyId, $others);
            if ($before) { $out['already'][] = $uid; continue; }
            $out[self::blocking($uid, $agencyId, $with) ? 'held' : 'kept'][] = $uid;
        }

        return $out;
    }

    /** id, name and roles at this agency, for the popup, the list and the snapshot. Full names: admins only see this. */
    public static function people(array $uids, int $agencyId): array
    {
        if (! $uids) return [];
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all() ?: [0];
        $roles = DB::table('role_assignments')->whereIn('user_id', $uids)->where('active', 1)->where('role', '!=', 'guardian')
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereIn('centre_id', $centres))
            ->get(['user_id', 'role'])->groupBy('user_id');
        $parents = array_flip(DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('g.user_id', $uids)->whereIn('f.centre_id', $centres)->pluck('g.user_id')->map(fn ($v) => (int) $v)->all());
        $out = [];
        foreach (DB::table('users')->whereIn('id', $uids)->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'email']) as $u) {
            $words = collect($roles->get($u->id, collect()))->pluck('role')->unique()->map(fn ($r) => self::ROLE_WORDS[$r] ?? ucfirst(str_replace('_', ' ', $r)))->values()->all();
            if (isset($parents[(int) $u->id])) $words[] = 'Parent';
            $out[] = ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name) ?: (string) $u->email,
                'roles' => implode(', ', array_unique($words)) ?: 'No role'];
        }

        return $out;
    }

    /** "3 parents, 1 educator" from people(). */
    public static function countWords(array $people): string
    {
        $n = [];
        foreach ($people as $p) foreach (explode(', ', $p['roles']) as $r) $n[$r] = ($n[$r] ?? 0) + 1;
        if (! $n) return 'nobody';
        $bits = [];
        foreach ($n as $r => $c) $bits[] = $c . ' ' . strtolower($r) . ($c === 1 ? '' : 's');

        return implode(', ', $bits);
    }

    /** What a lock is on, in words: "Sunny Meadows (centre)". */
    public static function targetLabel(string $scope, int $targetId, int $agencyId): string
    {
        if ($scope === 'agency') return (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'The agency') . ' (whole agency)';
        if ($scope === 'centre') {
            $c = DB::table('centres')->where('id', $targetId)->value('name') ?: 'Centre #' . $targetId;
            $settings = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
            $word = $settings['centre_term'] ?? 'centre';
            return $c . ' (' . ($word === 'center' ? 'center' : $word) . ')';
        }
        $u = DB::table('users')->where('id', $targetId)->first(['first_name', 'last_name', 'email']);

        return $u ? (trim($u->first_name . ' ' . $u->last_name) ?: (string) $u->email) : 'User #' . $targetId;
    }
}
