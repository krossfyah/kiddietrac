<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\HrGoogleJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Human Resources > HR settings > Google Jobs (2026-11-27, seo1127). See App\Support\HrGoogleJobs.
 *  GET hr/hiring/google-jobs   the switch, how many open posts would be listed, the public page address
 *  PUT hr/hiring/google-jobs   {enabled: bool}  agency admins only; audited hr.google_jobs_on / hr.google_jobs_off
 */
final class HrGoogleJobsController extends Controller
{
    use HrAccess;

    public function show(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);

        return response()->json($this->state($request, $aid));
    }

    public function save(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['enabled' => 'required|boolean']);
        $was = HrGoogleJobs::enabled($aid);
        $on = HrGoogleJobs::save($aid, (bool) $d['enabled']);
        if ($was !== $on) {
            $this->hrNote($aid, $on ? 'hr.google_jobs_on' : 'hr.google_jobs_off', 'agency', $aid, ['enabled' => $on]);
        }

        return response()->json($this->state($request, $aid));
    }

    private function state(Request $request, int $aid): array
    {
        $slug = (string) DB::table('agencies')->where('id', $aid)->value('slug');

        return ['enabled' => HrGoogleJobs::enabled($aid), 'can_change' => $this->hrIsAdmin($request),
            'open_posts' => HrGoogleJobs::openPosts($aid)->count(),
            'jobs_url' => $slug !== '' ? 'https://app.kiddietrac.com/jobs.html?a=' . rawurlencode($slug) : null];
    }
}
