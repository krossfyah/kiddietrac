<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * QUICK NOTES - who may see and change what (2026-11-26, Anthony: "add a notepad widget option and a shortcut at the
 * top bar for quick notes that are logged for each user or the agency").
 *
 * Who has a notepad: staff (agency admin, centre director, educator, home visitor), sales reps and super admins.
 * Parents and auditors do not.
 *
 * The context of a request:
 *   - agency: the X-Active-Agency-Id agency when the person is a super admin or holds a STAFF role there (an admin
 *     role, or a role placed at one of its centres); with no header, the agency of their first staff role (never for a
 *     super admin: no header = the platform view); otherwise none (personal notes only - a super admin with no
 *     agency picked, a sales rep).
 *   - centres: the centres they work at in that agency (Visibility::centreIds: every open centre for admins and
 *     super admins, their own for directors, educators and home visitors).
 *
 * What a person sees:
 *   - PRIVATE notes: their own, made in this agency or with no agency (no agency picked: only the no-agency ones).
 *     Nobody else ever - super admins included. Anyone else asking is told the note does not exist.
 *   - AGENCY notes: the agency's, agency-wide (centre_id NULL) or at one of their centres. Never with no agency.
 * Every staff member who can see an agency note may edit it (his choice); deleting one is for its author, admins and
 * the directors of its centre. Everything fails closed.
 */
final class QuickNotes
{
    public const STAFF = ['agency_admin', 'centre_director', 'educator', 'home_visitor'];
    public const USERS = ['platform_admin', 'agency_admin', 'centre_director', 'educator', 'home_visitor', 'sales_rep'];
    public const COLOURS = ['yellow', 'green', 'blue', 'pink', 'purple', 'orange'];
    public const LINKS = ['child', 'family', 'staff'];

    /** @return array{uid:int, agency:?int, centres:int[], wide:bool, director:int[], platform:bool} */
    public static function ctx(Request $request): array
    {
        $key = 'kt.quick_notes.ctx';
        if ($request->attributes->has($key)) return $request->attributes->get($key);
        $user = $request->user();
        abort_unless($user, 401);
        $uid = (int) $user->id;
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->get(['role', 'agency_id', 'centre_id']);
        abort_unless($roles->contains(fn ($r) => in_array($r->role, self::USERS, true)), 403, 'Quick notes are for staff.');
        $platform = $roles->contains(fn ($r) => $r->role === 'platform_admin');

        $centreAgency = function ($cid) {
            static $m = [];
            if (! $cid) return null;
            return $m[$cid] ??= (int) DB::table('centres')->where('id', $cid)->value('agency_id') ?: null;
        };
        $staffIn = function (int $aid) use ($roles, $centreAgency): bool {
            foreach ($roles as $r) {
                if (! in_array($r->role, self::STAFF, true)) continue;
                if ((int) $r->agency_id === $aid || ($r->centre_id && $centreAgency($r->centre_id) === $aid)) return true;
            }
            return false;
        };

        $agency = null;
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header > 0) {
            $exists = DB::table('agencies')->where('id', $header)->whereNull('deleted_at')->exists();
            if ($exists && ($platform || $staffIn($header))) $agency = $header;
        } elseif (! $platform) {   // a super admin with no agency picked is in the platform view: personal notes only
            foreach ($roles as $r) {
                if (! in_array($r->role, self::STAFF, true)) continue;
                $a = $r->agency_id ? (int) $r->agency_id : $centreAgency($r->centre_id);
                if ($a) { $agency = $a; break; }
            }
        }

        $centres = $agency ? Visibility::centreIds($agency, $user) : [];
        $centres = $agency ? array_values(array_intersect($centres, Live::centreIds($agency))) : [];
        $wide = $agency && ($platform || $roles->contains(fn ($r) => $r->role === 'agency_admin' && (int) $r->agency_id === $agency));
        $director = $agency ? $roles->filter(fn ($r) => $r->role === 'centre_director' && $r->centre_id && $centreAgency($r->centre_id) === $agency)
            ->pluck('centre_id')->map(fn ($v) => (int) $v)->values()->all() : [];

        $ctx = ['uid' => $uid, 'agency' => $agency, 'centres' => $centres, 'wide' => (bool) $wide, 'director' => $director, 'platform' => $platform];
        $request->attributes->set($key, $ctx);
        return $ctx;
    }

    /** Constrain a quick_notes query (alias $p, e.g. 'n.') to what this person may see. */
    public static function scope($q, array $ctx, string $p = 'quick_notes.')
    {
        return $q->whereNull($p . 'deleted_at')->where(function ($w) use ($ctx, $p) {
            $w->where(function ($m) use ($ctx, $p) {
                $m->where($p . 'visibility', 'private')->where($p . 'owner_user_id', $ctx['uid'])
                  ->where(fn ($a) => $ctx['agency'] ? $a->where($p . 'agency_id', $ctx['agency'])->orWhereNull($p . 'agency_id') : $a->whereNull($p . 'agency_id'));
            });
            if ($ctx['agency']) {
                $w->orWhere(function ($s) use ($ctx, $p) {
                    $s->where($p . 'visibility', 'agency')->where($p . 'agency_id', $ctx['agency'])
                      ->where(fn ($c) => $c->whereNull($p . 'centre_id')->orWhereIn($p . 'centre_id', $ctx['centres'] ?: [0]));
                });
            }
        });
    }

    /** One note this person may see, or 404 (a private note of someone else's is "not found", never "forbidden"). */
    public static function find(Request $request, int $id): object
    {
        $ctx = self::ctx($request);
        $n = self::scope(DB::table('quick_notes'), $ctx)->where('id', $id)->first();
        abort_unless($n, 404, 'Note not found.');
        return $n;
    }

    public static function canDelete(array $ctx, object $n): bool
    {
        if ($n->visibility === 'private') return (int) $n->owner_user_id === $ctx['uid'];
        if ((int) $n->owner_user_id === $ctx['uid'] || $ctx['wide']) return true;
        return $n->centre_id ? in_array((int) $n->centre_id, $ctx['director'], true) : (bool) $ctx['director'];
    }

    /**
     * May this person see this record (to link a note to it, or to see the link)? Same answers as the record
     * screens: children still with us at their centres (Visibility::planningChildIds), families that have not left
     * at their centres, staff placed in their scope (CentreScope::people). Returns [label, centre_id] or null.
     */
    public static function record(Request $request, array $ctx, ?string $type, $id): ?array
    {
        $aid = $ctx['agency']; $id = (int) $id;
        if (! $aid || ! $type || $id <= 0 || ! in_array($type, self::LINKS, true)) return null;
        $user = $request->user();
        if ($type === 'child') {
            $c = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
                ->join('centres as ce', 'ce.id', '=', 'f.centre_id')->where('ce.agency_id', $aid)->where('c.id', $id)
                ->where(fn ($q) => Live::children($q, 'c'))->first(['c.first_name', 'c.last_name', 'c.preferred_name', 'f.centre_id']);
            if (! $c) return null;
            $ok = Visibility::planningChildIds($aid, $user);
            if ($ok !== null && ! in_array($id, $ok, true)) return null;
            return [trim(($c->preferred_name ?: $c->first_name) . ' ' . $c->last_name), (int) $c->centre_id];
        }
        if ($type === 'family') {
            $f = DB::table('families as f')->join('centres as ce', 'ce.id', '=', 'f.centre_id')->where('ce.agency_id', $aid)
                ->where('f.id', $id)->where(fn ($q) => Live::families($q, 'f'))->first(['f.family_name', 'f.centre_id']);
            if (! $f || ! in_array((int) $f->centre_id, $ctx['centres'], true)) return null;
            return [(string) $f->family_name, (int) $f->centre_id];
        }
        // staff
        $centreIds = AgencyRole::centreIds($aid);
        $isStaff = DB::table('role_assignments')->where('user_id', $id)->where('active', true)->whereIn('role', self::STAFF)
            ->where(fn ($q) => $q->where('agency_id', $aid)->orWhereIn('centre_id', $centreIds ?: [0]))->exists();
        if (! $isStaff) return null;
        $u = DB::table('users')->where('id', $id)->whereNull('deleted_at')->first(['first_name', 'last_name']);
        if (! $u) return null;
        if (! $ctx['wide']) {
            $people = CentreScope::peopleIds($ctx['centres']);
            if (! in_array($id, $people, true)) return null;
        }
        return [trim($u->first_name . ' ' . $u->last_name), null];
    }
}
