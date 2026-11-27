<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\Features;
use App\Support\HrDashboard;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GET /hr/dashboard/stats?period=month|quarter|year&centre_id= — the HR dashboard's tiles and charts (App\Support\HrDashboard).
 * Directors and admins only (route group + hrNeedManager), the ACTIVE agency, directors their own centres (CentreScope),
 * a centre filter only inside that scope (else 404), closed centres never. Money (labour cost) only for pay viewers.
 * Cached 90 s per agency + user + scope + filter + period (15 s while old clock-ins are still being compared), so a busy
 * screen never recomputes on every click.
 */
final class HrDashboardController extends Controller
{
    use HrAccess;

    public function stats(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $period = (string) $request->query('period', 'month');
        abort_unless(in_array($period, HrDashboard::PERIODS, true), 422, 'Choose this month, this quarter or this year.');
        $scope = CentreScope::ids($request, $aid);
        $open = Live::centreIds($aid);
        $visible = $scope === null ? $open : array_values(array_intersect($scope, $open));
        $centre = $request->query('centre_id');
        if ($centre !== null && $centre !== '') {
            abort_unless(ctype_digit((string) $centre) && in_array((int) $centre, $visible, true), 404, 'That centre was not found.');
            $scope = [(int) $centre];
        }
        $pay = $this->hrIsManager($request) && Features::on($aid, 'payroll');   // as HrReportsController::canSeePay
        $me = (int) $request->user()->id;
        $today = AgencyTime::today($aid);
        $key = 'hrdash:v1:' . $aid . ':' . $me . ':' . md5(json_encode([$scope, $period, $pay, $today]));
        $data = Cache::get($key);
        $hit = is_array($data);
        if (! $hit) {
            $data = HrDashboard::build($aid, $scope, $period, $today, $pay, $me);
            // Clock-ins still being compared after this response: keep it only briefly so the next look has them.
            Cache::put($key, $data, ($data['tiles']['lateness']['unchecked'] ?? 0) ? 15 : 90);
        }
        $centres = DB::table('centres')->whereIn('id', $visible ?: [0])->orderBy('name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all();

        return response()->json($data + ['centres' => $centres, 'centre_id' => $centre ? (int) $centre : null, 'today' => $today, 'cached' => $hit,
            'can' => ['pay' => $pay, 'admin' => $this->hrIsAdmin($request)]]);
    }
}
