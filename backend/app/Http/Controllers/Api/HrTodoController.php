<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyRole;
use App\Support\CentreScope;
use App\Support\HrLeave;
use App\Support\HrTodo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HR Phase 4 batch 10 (2026-11-27): the person's own My HR to-do and their role's starter path (App\Support\HrTodo).
 * Routes sit under hr/ (the `hr` add-on MAP) in the all-staff group. Everything is the caller's own: their agency
 * (resolveAgencyId, HR on), their own items; an outsider (super admin, integration, reseller) is not staff here and
 * gets an empty list.
 */
final class HrTodoController extends Controller
{
    use HrAccess;

    private const PREF = 'hr_starter_hidden';

    private function staff(Request $request, int $aid): bool
    {
        return in_array((int) $request->user()->id, HrLeave::staffIds($aid), true);
    }

    /** GET /hr/me/todo */
    public function todo(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        if (! $this->staff($request, $aid)) return response()->json(['is_staff' => false, 'items' => [], 'count' => 0]);
        $items = HrTodo::items($request, $aid, $me);

        return response()->json(['is_staff' => true, 'items' => $items, 'count' => count($items), 'overdue' => count(array_filter($items, fn ($i) => $i['overdue']))]);
    }

    /** The starter path this person walks: the one for the role they are using now (?role=), if they hold it. */
    private function path(Request $request): ?string
    {
        $roles = AgencyRole::names($request);
        $want = (string) $request->query('role', '');
        $of = fn (string $r) => match ($r) { 'agency_admin', 'platform_admin' => 'admin', 'centre_director' => 'director', 'educator' => 'educator', 'home_visitor' => 'home_visitor', default => null };
        if ($want !== '' && in_array($want, $roles, true) && $of($want)) return $of($want);
        foreach (['agency_admin', 'centre_director', 'educator', 'home_visitor'] as $r) {
            if (in_array($r, $roles, true)) return $of($r);
        }

        return null;
    }

    /** GET /hr/me/starter?role= */
    public function starter(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        $path = $this->path($request);
        if (! $path || ! $this->staff($request, $aid)) return response()->json(['path' => null, 'steps' => [], 'hidden' => true]);
        $scope = $path === 'admin' ? null : (CentreScope::ids($request, $aid) ?? []);
        $hidden = (array) (json_decode((string) DB::table('user_ui_prefs')->where('user_id', $me)->where('pref_key', self::PREF)->value('value'), true) ?: []);

        return response()->json(HrTodo::starter($request, $aid, $me, $path, $scope) + ['hidden' => in_array($path . ':' . $aid, $hidden, true)]);
    }

    /** POST /hr/me/starter {path, hidden}: hide or bring back the card (per agency, per path; it is the person's own choice). */
    public function hide(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        $d = $request->validate(['path' => 'required|in:' . implode(',', HrTodo::PATHS), 'hidden' => 'required|boolean']);
        $row = DB::table('user_ui_prefs')->where('user_id', $me)->where('pref_key', self::PREF)->value('value');
        $list = array_values(array_filter((array) (json_decode((string) $row, true) ?: []), 'is_string'));
        $k = $d['path'] . ':' . $aid;
        $list = array_values(array_diff($list, [$k]));
        if ($d['hidden']) $list[] = $k;
        DB::table('user_ui_prefs')->updateOrInsert(['user_id' => $me, 'pref_key' => self::PREF], ['value' => json_encode(array_slice($list, -40)), 'updated_at' => now()]);

        return response()->json(['ok' => true, 'hidden' => (bool) $d['hidden']]);
    }
}
