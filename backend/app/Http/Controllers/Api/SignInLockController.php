<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\SignInLocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SIGN-IN LOCKS screen and popups (2026-11-10). The rules live in App\Support\SignInLocks; this is who may do what:
 *   - agency admins lock and unlock people and centres / home providers of the ACTIVE agency;
 *   - only a platform admin locks or unlocks a whole agency, or locks an agency admin;
 *   - nobody locks themselves, and a platform admin is never locked.
 * Every lock and unlock is written up in a popup with notes, shows who it reaches before it is confirmed, is audited,
 * and is emailed to the agency's admins and the directors of the centres involved (Anthony: "Lock and unlock should
 * send Admin/director an email outlining what has happened"). The locked people themselves get no email (Anthony:
 * "No email; screen message"); they read SignInLocks::message() when they try to sign in.
 */
class SignInLockController extends Controller
{
    private const APP = 'https://app.kiddietrac.com/dashboard.html#signin-locks';

    public function index(Request $request): JsonResponse
    {
        [$agencyId, $platform] = $this->agency($request);
        $rows = DB::table('sign_in_locks')->where('agency_id', $agencyId)
            ->where(fn ($q) => $q->whereNull('unlocked_at')->orWhere('unlocked_at', '>=', now()->subDays(90)))
            ->orderByRaw('unlocked_at IS NULL DESC')->orderByDesc('locked_at')->limit(200)->get();

        return response()->json(['locks' => $rows->map(fn ($l) => $this->shape($l))->values(), 'can_lock_agency' => $platform,
            'agency_id' => $agencyId, 'agency_name' => DB::table('agencies')->where('id', $agencyId)->value('name')]);
    }

    /** People and centres to choose from in "Lock sign-in". Members of this agency only. */
    public function targets(Request $request): JsonResponse
    {
        [$agencyId] = $this->agency($request);
        $q = trim((string) $request->query('q', ''));
        $centres = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        $people = [];
        if (mb_strlen($q) >= 2) {
            $ids = SignInLocks::candidates('agency', $agencyId, $agencyId);
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $match = DB::table('users')->whereIn('id', $ids ?: [0])
                ->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('email', 'like', $like)
                    ->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", [$like]))
                ->limit(25)->pluck('id')->all();
            $people = SignInLocks::people($match, $agencyId);
        }

        return response()->json(['centres' => $centres, 'people' => $people]);
    }

    /** Who a lock would hold, before it is made: the popup shows this. */
    public function preview(Request $request): JsonResponse
    {
        [$agencyId, $platform] = $this->agency($request, (int) $request->query('agency_id'));
        [$scope, $target] = $this->target($request->query('scope'), (int) $request->query('target_id'), $agencyId, $platform, (int) $request->user()->id);
        $r = SignInLocks::reach($scope, $target, $agencyId);
        $open = DB::table('sign_in_locks')->where('agency_id', $agencyId)->where('scope', $scope)->where('target_id', $target)->whereNull('unlocked_at')->first();

        return response()->json([
            'scope' => $scope, 'target_id' => $target, 'label' => SignInLocks::targetLabel($scope, $target, $agencyId),
            'held' => SignInLocks::people($r['held'], $agencyId), 'already' => SignInLocks::people($r['already'], $agencyId),
            'kept' => SignInLocks::people($r['kept'], $agencyId), 'open_lock' => $open ? $this->shape($open) : null,
        ]);
    }

    /**
     * Which of these people (ids=1,2,3) are locked out of the active agency - the status pills on Child detail, Family
     * detail and anywhere else a person's account state shows. Directors may read it for their agency: they see these
     * people already, and a "Signs in" pill that is wrong is worse than none.
     */
    public function lockedPeople(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $aid = (int) $request->header('X-Active-Agency-Id');
        $platform = SignInLocks::isPlatformAdmin($uid);
        if (! $aid && ! $platform) {
            $aid = (int) DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')->where('ra.user_id', $uid)
                ->where('ra.active', 1)->whereIn('ra.role', ['agency_admin', 'centre_director'])->selectRaw('COALESCE(ra.agency_id, c.agency_id) a')->value('a');
        }
        abort_unless($aid, 400, 'Select an agency first.');
        abort_unless($platform || DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')->where('ra.user_id', $uid)
            ->where('ra.active', 1)->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->where(fn ($q) => $q->where('ra.agency_id', $aid)->orWhere('c.agency_id', $aid))->exists(), 403, 'Not your agency.');
        $ids = array_slice(array_map('intval', explode(',', (string) $request->query('ids', ''))), 0, 500);

        return response()->json(['locked' => (object) SignInLocks::lockMap($ids, $aid)]);
    }

    /** For the user page: are they kept out of this agency, and by which lock. */
    public function status(Request $request): JsonResponse
    {
        [$agencyId] = $this->agency($request);
        $uid = (int) $request->query('user_id');
        abort_unless($uid && in_array($agencyId, SignInLocks::agenciesOf($uid), true), 404, 'That person is not part of this agency.');
        $lock = SignInLocks::blocking($uid, $agencyId);
        $own = DB::table('sign_in_locks')->where('agency_id', $agencyId)->where('scope', 'user')->where('target_id', $uid)->whereNull('unlocked_at')->first();

        $exc = DB::table('sign_in_lock_exceptions')->where('agency_id', $agencyId)->where('user_id', $uid)->whereNull('ended_at')->orderByDesc('id')->first();

        return response()->json(['locked' => (bool) $lock, 'lock' => $lock ? $this->shape($lock) : null, 'own_lock' => $own ? $this->shape($own) : null,
            'exception' => $exc ? $this->shapeException($exc) : null,
            'name' => SignInLocks::targetLabel('user', $uid, $agencyId), 'platform_admin' => SignInLocks::isPlatformAdmin($uid)]);
    }

    /**
     * Let ONE person sign in despite a centre / provider lock; the centre stays locked for everyone else. Notes required;
     * emailed to the admins and that centre's directors like a lock.
     */
    public function except(Request $request, int $lock): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|integer|min:1', 'notes' => 'required|string|min:3|max:4000']);
        $l = $this->lockFor($request, $lock);
        abort_if($l->unlocked_at, 409, 'This lock was already lifted.');
        abort_unless($l->scope === 'centre', 422, 'Only a centre or provider lock can let one person in. Unlock a person\'s own lock instead.');
        $agencyId = (int) $l->agency_id;
        $uid = (int) $data['user_id'];
        abort_unless(in_array($uid, SignInLocks::candidates('centre', (int) $l->target_id, $agencyId), true), 404, 'That person is not held by this lock.');
        abort_if(DB::table('sign_in_lock_exceptions')->where('lock_id', $l->id)->where('user_id', $uid)->whereNull('ended_at')->exists(),
            409, 'This person is already let in.');
        $actor = (int) $request->user()->id;
        $id = DB::table('sign_in_lock_exceptions')->insertGetId(['agency_id' => $agencyId, 'lock_id' => $l->id, 'user_id' => $uid,
            'notes' => trim($data['notes']), 'created_by_user_id' => $actor, 'created_at' => now()]);
        SignInLocks::forget();
        $still = SignInLocks::blocking($uid, $agencyId);
        $name = SignInLocks::targetLabel('user', $uid, $agencyId);
        $label = SignInLocks::targetLabel('centre', (int) $l->target_id, $agencyId);
        $this->audit($request, $agencyId, 'sign_in_lock.exception_added', (int) $l->id, 'Let ' . $name . ' sign in despite the ' . $label . ' lock');
        $emailed = $this->notifyException($l, $uid, true, $actor, trim($data['notes']), $still);

        return response()->json(['exception' => $this->shapeException(DB::table('sign_in_lock_exceptions')->where('id', $id)->first()),
            'still_locked' => $still ? $this->shape($still) : null, 'emailed' => $emailed], 201);
    }

    /** End an exception: that person is held by the centre lock again. */
    public function endException(Request $request, int $exception): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:4000']);
        $e = DB::table('sign_in_lock_exceptions')->where('id', $exception)->first();
        abort_unless($e, 404, 'Not found.');
        $l = $this->lockFor($request, (int) $e->lock_id);
        abort_if($e->ended_at, 409, 'This was already ended.');
        $actor = (int) $request->user()->id;
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        DB::table('sign_in_lock_exceptions')->where('id', $e->id)->whereNull('ended_at')
            ->update(['ended_at' => now(), 'ended_by_user_id' => $actor, 'end_notes' => $notes]);
        SignInLocks::forget();
        $agencyId = (int) $l->agency_id;
        $locked = SignInLocks::blocking((int) $e->user_id, $agencyId);
        if ($locked) SignInLocks::signOut([(int) $e->user_id], (int) $l->id);   // locked again: out of every device now
        $name = SignInLocks::targetLabel('user', (int) $e->user_id, $agencyId);
        $this->audit($request, $agencyId, 'sign_in_lock.exception_ended', (int) $l->id,
            'Ended the exception for ' . $name . ' on the ' . SignInLocks::targetLabel($l->scope, (int) $l->target_id, $agencyId) . ' lock');
        $emailed = $this->notifyException($l, (int) $e->user_id, false, $actor, $notes, $locked);

        return response()->json(['locked_again' => (bool) $locked, 'emailed' => $emailed]);
    }

    /** One lock with the people it held and, while it is open, who lifting it would let back in. */
    public function show(Request $request, int $lock): JsonResponse
    {
        $l = $this->lockFor($request, $lock);
        $snap = DB::table('sign_in_lock_people')->where('lock_id', $l->id)->get(['user_id', 'already_locked']);
        $out = ['lock' => $this->shape($l), 'held' => SignInLocks::people($snap->where('already_locked', 0)->pluck('user_id')->map(fn ($v) => (int) $v)->all(), (int) $l->agency_id)];
        $out['exceptions'] = DB::table('sign_in_lock_exceptions')->where('lock_id', $l->id)->orderByRaw('ended_at IS NULL DESC')->orderByDesc('id')
            ->get()->map(fn ($e) => $this->shapeException($e))->values();
        if (! $l->unlocked_at) {
            [$restore, $still] = $this->afterUnlock($l);
            $out['would_restore'] = SignInLocks::people($restore, (int) $l->agency_id);
            $out['still_locked'] = SignInLocks::people($still, (int) $l->agency_id);
        }

        return response()->json($out);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => 'required|in:user,centre,agency', 'target_id' => 'required|integer|min:1',
            'notes' => 'required|string|min:3|max:4000', 'agency_id' => 'nullable|integer',
        ]);
        [$agencyId, $platform] = $this->agency($request, (int) ($data['agency_id'] ?? 0) ?: ($data['scope'] === 'agency' ? (int) $data['target_id'] : 0));
        $actor = (int) $request->user()->id;
        [$scope, $target] = $this->target($data['scope'], (int) $data['target_id'], $agencyId, $platform, $actor);

        $id = DB::transaction(function () use ($agencyId, $scope, $target, $data, $actor) {
            $dupe = DB::table('sign_in_locks')->where('agency_id', $agencyId)->where('scope', $scope)->where('target_id', $target)
                ->whereNull('unlocked_at')->lockForUpdate()->exists();
            abort_if($dupe, 409, 'This is already locked. Open it from Locked sign-ins to unlock it.');
            $r = SignInLocks::reach($scope, $target, $agencyId);
            $id = DB::table('sign_in_locks')->insertGetId(['agency_id' => $agencyId, 'scope' => $scope, 'target_id' => $target,
                'lock_notes' => trim($data['notes']), 'locked_by_user_id' => $actor, 'locked_at' => now(), 'held_count' => count($r['held'])]);
            $roles = collect(SignInLocks::people(array_merge($r['held'], $r['already']), $agencyId))->keyBy('id');
            $rows = [];
            foreach ([0 => $r['held'], 1 => $r['already']] as $already => $uids) {
                foreach ($uids as $uid) $rows[] = ['lock_id' => $id, 'user_id' => $uid, 'roles' => mb_substr((string) ($roles[$uid]['roles'] ?? ''), 0, 120),
                    'already_locked' => $already, 'created_at' => now()];
            }
            foreach (array_chunk($rows, 500) as $chunk) DB::table('sign_in_lock_people')->insert($chunk);
            return ['id' => $id, 'reach' => $r];
        });
        SignInLocks::forget();
        $lock = DB::table('sign_in_locks')->where('id', $id['id'])->first();
        $r = $id['reach'];
        $label = SignInLocks::targetLabel($scope, $target, $agencyId);
        $held = SignInLocks::people($r['held'], $agencyId);
        // Signed out of every device now, not on their next tap (2026-11-10, "Sign out now + stop push").
        $signedOut = SignInLocks::signOut(array_merge($r['held'], $r['already']), (int) $lock->id);
        $this->audit($request, $agencyId, 'sign_in_lock.locked', (int) $lock->id,
            'Paused sign-in for ' . $label . ': ' . SignInLocks::countWords($held) . (count($r['kept']) ? '; ' . count($r['kept']) . ' kept access' : '')
            . ($signedOut ? '; ' . $signedOut . ' signed out of every device' : ''));
        $emailed = $this->notify($lock, true, $actor, $held, SignInLocks::people($r['kept'], $agencyId), count($r['already']));

        return response()->json(['lock' => $this->shape($lock), 'held' => count($r['held']), 'kept' => count($r['kept']),
            'already' => count($r['already']), 'signed_out' => $signedOut, 'emailed' => $emailed], 201);
    }

    public function unlock(Request $request, int $lock): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:4000']);
        $l = $this->lockFor($request, $lock);
        abort_if($l->unlocked_at, 409, 'This lock was already lifted.');
        [$restore, $still] = $this->afterUnlock($l);
        $actor = (int) $request->user()->id;
        $done = DB::table('sign_in_locks')->where('id', $l->id)->whereNull('unlocked_at')->update([
            'unlocked_at' => now(), 'unlocked_by_user_id' => $actor, 'unlock_notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'restored_count' => count($restore)]);
        abort_unless($done, 409, 'This lock was already lifted.');
        // Its exceptions end with it: there is nothing left to be let in past.
        DB::table('sign_in_lock_exceptions')->where('lock_id', $l->id)->whereNull('ended_at')
            ->update(['ended_at' => now(), 'ended_by_user_id' => $actor, 'end_notes' => 'The lock was lifted.']);
        SignInLocks::forget();
        $l = DB::table('sign_in_locks')->where('id', $l->id)->first();
        $agencyId = (int) $l->agency_id;
        $label = SignInLocks::targetLabel($l->scope, (int) $l->target_id, $agencyId);
        $restored = SignInLocks::people($restore, $agencyId);
        $this->audit($request, $agencyId, 'sign_in_lock.unlocked', (int) $l->id,
            'Restored sign-in for ' . $label . ': ' . SignInLocks::countWords($restored) . (count($still) ? '; ' . count($still) . ' still paused by another lock' : ''));
        $emailed = $this->notify($l, false, $actor, $restored, [], count($still));

        return response()->json(['lock' => $this->shape($l), 'restored' => count($restore), 'still_locked' => count($still), 'emailed' => $emailed]);
    }

    /* ── helpers ──────────────────────────────────────────────────────────────────────────────────────────── */

    /**
     * Everyone this lock holds (who it held when made, plus anyone who has joined the centre since), split into who
     * lifting it lets back in and who stays out because of another open lock. Anyone who can already sign in (let in by
     * exception, or since moved to an unlocked centre) is in neither list.
     */
    private function afterUnlock(object $l): array
    {
        $agencyId = (int) $l->agency_id;
        $now = SignInLocks::reach($l->scope, (int) $l->target_id, $agencyId, (int) $l->id);
        $ids = DB::table('sign_in_lock_people')->where('lock_id', $l->id)->pluck('user_id')->map(fn ($v) => (int) $v)
            ->merge($now['held'])->merge($now['already'])->unique()->values()->all();
        $others = SignInLocks::open($agencyId)->reject(fn ($o) => (int) $o->id === (int) $l->id)->values();
        $restore = [];
        $still = [];
        foreach ($ids as $uid) {
            if (! SignInLocks::blocking($uid, $agencyId)) continue;   // already in: an exception, or now tied to an unlocked centre
            if ($others->isNotEmpty() && SignInLocks::blocking($uid, $agencyId, $others)) $still[] = $uid;
            else $restore[] = $uid;
        }

        return [$restore, $still];
    }

    /** [agency id, is platform admin]. A platform admin may name the agency; an agency admin works in their active one. */
    private function agency(Request $request, int $named = 0): array
    {
        $uid = (int) $request->user()->id;
        $platform = SignInLocks::isPlatformAdmin($uid);
        $aid = ($platform && $named) ? $named : (int) $request->header('X-Active-Agency-Id');
        if (! $aid && ! $platform) {
            $aid = (int) DB::table('role_assignments')->where('user_id', $uid)->where('role', 'agency_admin')->where('active', 1)->value('agency_id');
        }
        abort_unless($aid && DB::table('agencies')->where('id', $aid)->whereNull('deleted_at')->exists(), 400, 'Select an agency first.');
        abort_unless($platform || DB::table('role_assignments')->where('user_id', $uid)->where('agency_id', $aid)
            ->where('role', 'agency_admin')->where('active', 1)->exists(), 403, 'Only an agency admin can lock or unlock sign-in.');

        return [$aid, $platform];
    }

    /** Checks what is being locked belongs to this agency and that this person may lock it. */
    private function target($scope, int $target, int $agencyId, bool $platform, int $actor): array
    {
        abort_unless(in_array($scope, SignInLocks::SCOPES, true), 422, 'Choose a person, a centre or the agency.');
        if ($scope === 'agency') {
            abort_unless($platform, 403, 'Only KiddieTrac can lock a whole agency.');
            return ['agency', $agencyId];
        }
        if ($scope === 'centre') {
            abort_unless(DB::table('centres')->where('id', $target)->where('agency_id', $agencyId)->exists(), 404, 'That centre is not part of this agency.');
            return ['centre', $target];
        }
        abort_unless(in_array($agencyId, SignInLocks::agenciesOf($target), true), 404, 'That person is not part of this agency.');
        abort_if($target === $actor, 422, 'You cannot lock your own sign-in.');
        abort_if(SignInLocks::isPlatformAdmin($target), 422, 'A KiddieTrac platform admin cannot be locked.');
        $isAdmin = DB::table('role_assignments')->where('user_id', $target)->where('agency_id', $agencyId)->where('role', 'agency_admin')->where('active', 1)->exists();
        abort_if($isAdmin && ! $platform, 403, 'Only KiddieTrac can lock another agency admin.');

        return ['user', $target];
    }

    /** A lock of an agency this person may act in. A whole-agency lock is platform-only both ways. */
    private function lockFor(Request $request, int $id): object
    {
        $l = DB::table('sign_in_locks')->where('id', $id)->first();
        abort_unless($l, 404, 'Lock not found.');
        [$agencyId, $platform] = $this->agency($request, (int) $l->agency_id);
        abort_unless((int) $l->agency_id === $agencyId, 404, 'Lock not found.');
        abort_if($l->scope === 'agency' && ! $platform, 403, 'Only KiddieTrac can unlock a whole agency.');
        $isAdminLock = $l->scope === 'user' && DB::table('role_assignments')->where('user_id', $l->target_id)->where('agency_id', $agencyId)
            ->where('role', 'agency_admin')->where('active', 1)->exists();
        abort_if($isAdminLock && ! $platform && $request->isMethod('post'), 403, 'Only KiddieTrac can unlock an agency admin.');

        return $l;
    }

    private function shape(object $l): array
    {
        $tz = AgencyTime::tz((int) $l->agency_id);
        $fmt = fn ($t) => $t ? Carbon::parse($t, 'UTC')->setTimezone($tz)->format('M j, Y g:i A') : null;
        $name = fn ($id) => $id ? trim((string) DB::table('users')->where('id', $id)->selectRaw("CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) n")->value('n')) : null;

        return ['id' => (int) $l->id, 'scope' => $l->scope, 'target_id' => (int) $l->target_id, 'agency_id' => (int) $l->agency_id,
            'label' => SignInLocks::targetLabel($l->scope, (int) $l->target_id, (int) $l->agency_id), 'open' => ! $l->unlocked_at,
            'lock_notes' => $l->lock_notes, 'locked_at' => $fmt($l->locked_at), 'locked_by' => $name($l->locked_by_user_id),
            'unlock_notes' => $l->unlock_notes, 'unlocked_at' => $fmt($l->unlocked_at), 'unlocked_by' => $name($l->unlocked_by_user_id),
            'held_count' => (int) $l->held_count, 'restored_count' => $l->restored_count === null ? null : (int) $l->restored_count,
            'exception_count' => (int) DB::table('sign_in_lock_exceptions')->where('lock_id', $l->id)->whereNull('ended_at')->count()];
    }

    private function shapeException(object $e): array
    {
        $tz = AgencyTime::tz((int) $e->agency_id);
        $fmt = fn ($t) => $t ? Carbon::parse($t, 'UTC')->setTimezone($tz)->format('M j, Y g:i A') : null;
        $l = DB::table('sign_in_locks')->where('id', $e->lock_id)->first();

        return ['id' => (int) $e->id, 'lock_id' => (int) $e->lock_id, 'user_id' => (int) $e->user_id,
            'name' => SignInLocks::targetLabel('user', (int) $e->user_id, (int) $e->agency_id),
            'lock_label' => $l ? SignInLocks::targetLabel($l->scope, (int) $l->target_id, (int) $l->agency_id) : null,
            'notes' => $e->notes, 'at' => $fmt($e->created_at),
            'by' => $e->created_by_user_id ? SignInLocks::targetLabel('user', (int) $e->created_by_user_id, (int) $e->agency_id) : null,
            'open' => ! $e->ended_at, 'ended_at' => $fmt($e->ended_at), 'end_notes' => $e->end_notes];
    }

    /** Agency admins plus the directors of these centres, one row per address. */
    private function recipients(int $agencyId, array $centres)
    {
        return DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')->where('ra.active', 1)
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId))
                ->orWhere(fn ($d) => $d->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $centres ?: [0])))
            ->whereNull('u.deleted_at')->whereNotNull('u.email')->where('u.email', '!=', '')
            ->select('u.id', 'u.first_name', 'u.email')->distinct()->get()
            ->unique(fn ($u) => strtolower(trim($u->email)));
    }

    /** One email each to $to (never a shared To/CC), greeting by first name. Returns how many went to the mail layer. */
    private function send(int $agencyId, $to, string $subject, string $html): int
    {
        $n = 0;
        $p = 'margin:0 0 14px;font-size:15px;line-height:1.6;';
        foreach ($to as $u) {
            $first = trim((string) $u->first_name);
            try {
                \App\Services\AgencyMailer::forAgency($agencyId)->html(str_replace('<!--KT-GREETING-->', $first ? '<p style="' . $p . '">' . e($first) . ',</p>' : '', $html),
                    function ($m) use ($u, $subject, $agencyId) {
                        $m->to($u->email)->subject($subject);
                        $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                    });
                $n++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $n;
    }

    /** The exception email: who was let in past (or put back under) which centre lock, by whom, when, and the notes. */
    private function notifyException(object $l, int $uid, bool $added, int $actor, ?string $notes, ?object $stillLocked): int
    {
        try {
            $agencyId = (int) $l->agency_id;
            $to = $this->recipients($agencyId, [(int) $l->target_id]);
            if ($to->isEmpty()) return 0;
            $name = SignInLocks::targetLabel('user', $uid, $agencyId);
            $label = SignInLocks::targetLabel($l->scope, (int) $l->target_id, $agencyId);
            $roles = (SignInLocks::people([$uid], $agencyId)[0]['roles'] ?? '');
            $by = trim((string) DB::table('users')->where('id', $actor)->value('first_name')) ?: 'An administrator';
            $when = now()->setTimezone(AgencyTime::tz($agencyId));
            $stamp = $when->format('l, M j, Y') . ' at ' . $when->format('g:i A') . ' (' . $when->format('T') . ')';
            $p = 'margin:0 0 14px;font-size:15px;line-height:1.6;';
            $line = $added
                ? '<strong>' . e($name) . '</strong>' . ($roles ? ' (' . e($roles) . ')' : '') . ' can sign in again. ' . e($label) . ' stays locked for everyone else.'
                  . ($stillLocked ? '<br><strong>Note:</strong> they are still kept out by another lock (' . e(SignInLocks::targetLabel($stillLocked->scope, (int) $stillLocked->target_id, $agencyId)) . ').' : '')
                : '<strong>' . e($name) . '</strong>' . ($roles ? ' (' . e($roles) . ')' : '') . ' is no longer let in past the ' . e($label) . ' lock.'
                  . ($stillLocked ? ' They cannot sign in.' : ' They can still sign in through another centre.');
            $body = '<!--KT-GREETING--><p style="' . $p . '">' . e($by) . ' ' . ($added ? 'let one person in past' : 'ended an exception on') . ' the <strong>'
                . e($label) . '</strong> sign-in lock on ' . e($stamp) . '.</p>'
                . \App\Services\EmailTemplate::calloutBox($line, $added ? 'success' : 'warning')
                . ($notes ? '<p style="margin:14px 0 4px;font-size:13px;font-weight:700;color:#475569;">Notes</p>'
                    . '<p style="margin:0 0 14px;font-size:14.5px;line-height:1.6;">' . nl2br(e($notes)) . '</p>' : '')
                . \App\Services\EmailTemplate::button('Open Locked sign-ins', self::APP);
            $subject = ($added ? 'Sign-in exception: ' : 'Sign-in exception ended: ') . $name . ' (' . preg_replace('/\s*\([^)]*\)$/', '', $label) . ')';
            $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => $added ? 'SIGN-IN EXCEPTION' : 'EXCEPTION ENDED', 'title' => $subject,
                'subtitle' => DB::table('agencies')->where('id', $agencyId)->value('name'),
                'preheader' => $by . ($added ? ' let ' : ' ended the exception for ') . $name . '.',
            ]);

            return $this->send($agencyId, $to, $subject, $html);
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }

    /**
     * Email the agency's admins and the directors of the centres involved: what was locked or unlocked, by whom, when,
     * who it reaches, and the notes. Counts, not names - the list is on the Locked sign-ins screen. One email each,
     * never a shared To/CC. Returns how many were handed to the mail layer (it may still suppress some).
     */
    private function notify(object $l, bool $locked, int $actor, array $people, array $kept, int $otherLock): int
    {
        try {
            $agencyId = (int) $l->agency_id;
            $centres = match ($l->scope) {
                'centre' => [(int) $l->target_id],
                'user' => SignInLocks::ties((int) $l->target_id, $agencyId)['centres'],
                default => DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all(),
            };
            $to = $this->recipients($agencyId, $centres);
            if ($to->isEmpty()) return 0;

            $label = SignInLocks::targetLabel($l->scope, (int) $l->target_id, $agencyId);
            $by = trim((string) DB::table('users')->where('id', $actor)->value('first_name')) ?: 'An administrator';
            $when = Carbon::parse($locked ? $l->locked_at : $l->unlocked_at, 'UTC')->setTimezone(AgencyTime::tz($agencyId));
            $stamp = $when->format('l, M j, Y') . ' at ' . $when->format('g:i A') . ' (' . $when->format('T') . ')';
            $notes = $locked ? $l->lock_notes : $l->unlock_notes;
            $p = 'margin:0 0 14px;font-size:15px;line-height:1.6;';

            $lines = $locked
                ? '<strong>Can\'t sign in now:</strong> ' . e(SignInLocks::countWords($people)) . '.'
                  . (count($kept) ? '<br><strong>Kept access:</strong> ' . e(SignInLocks::countWords($kept))
                      . ($l->scope === 'centre' ? ' - they also have a child at, or work at, another centre, or an agency-wide role.' : '.') : '')
                  . ($otherLock ? '<br><strong>Already paused by another lock:</strong> ' . $otherLock . '.' : '')
                : '<strong>Can sign in again:</strong> ' . e(SignInLocks::countWords($people)) . '.'
                  . ($otherLock ? '<br><strong>Still paused by another lock:</strong> ' . $otherLock . '.' : '');
            $body = '<!--KT-GREETING--><p style="' . $p . '">' . e($by) . ' ' . ($locked ? 'paused' : 'restored') . ' sign-in for <strong>' . e($label) . '</strong> on ' . e($stamp) . '.</p>'
                . \App\Services\EmailTemplate::calloutBox($lines, $locked ? 'warning' : 'success')
                . ($notes ? '<p style="margin:14px 0 4px;font-size:13px;font-weight:700;color:#475569;">Notes</p>'
                    . '<p style="margin:0 0 14px;font-size:14.5px;line-height:1.6;">' . nl2br(e($notes)) . '</p>' : '')
                . '<p style="margin:14px 0;font-size:13.5px;line-height:1.6;color:#64748B;">'
                . ($locked
                    ? 'They cannot sign in or use the app until this is lifted, and anyone already signed in is stopped and signed out on their next tap, on every device. Emails, texts and emergency messages still reach them, and nothing on their records has changed. They were not emailed; they see a message to contact you when they try to sign in.'
                    : 'Everything is as it was before the lock: nothing on their records changed while it was in place.')
                . '</p>'
                . \App\Services\EmailTemplate::button('Open Locked sign-ins', self::APP);
            $subject = ($locked ? 'Sign-in paused: ' : 'Sign-in restored: ') . $label;
            $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => $locked ? 'SIGN-IN PAUSED' : 'SIGN-IN RESTORED', 'title' => $subject,
                'subtitle' => DB::table('agencies')->where('id', $agencyId)->value('name'),
                'preheader' => $by . ' ' . ($locked ? 'paused' : 'restored') . ' sign-in for ' . $label . '.',
            ]);

            return $this->send($agencyId, $to, $subject, $html);
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }

    private function audit(Request $request, int $agencyId, string $action, int $id, string $summary): void
    {
        try {
            Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => 'sign_in_lock', 'entity_id' => $id, 'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
