<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SearchKeywords;
use App\Support\UserRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The words on every screen for the global search, learned from use (2026-11-09, see App\Support\SearchKeywords).
 *   GET  /search-keys?role=   the index for a role the person has (admins may read any role: they preview them)
 *   POST /diag/search-words   a browser's once-a-day report of a screen's words. Under diag/ so AuditActivity skips
 *                             it: the body is on-screen text, and nothing of it is stored unless it passes the gates.
 */
class SearchKeywordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $role = (string) $request->query('role', '');
        $mine = $this->roles($request);
        $admin = (bool) array_intersect($mine, ['agency_admin', 'platform_admin']);
        if (! in_array($role, SearchKeywords::ROLES, true) || (! $admin && ! in_array($role, $mine, true))) {
            $role = $mine[0] ?? 'guardian';
        }

        return response()->json(SearchKeywords::index($role))->header('Cache-Control', 'private, max-age=300');
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'max:30'],
            'hash' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,58}$/'],
            'tab' => ['nullable', 'string', 'max:64'],
            'phrases' => ['required', 'array', 'max:' . SearchKeywords::MAX_PHRASES],
            'phrases.*' => ['nullable', 'string', 'max:64'],
        ]);
        // Only a role the person really has: a preview ("view as") is not reported, so its words are never filed
        // under the wrong role.
        if (! in_array($data['role'], SearchKeywords::ROLES, true) || ! in_array($data['role'], $this->roles($request), true)) {
            return response()->json(['accepted' => [], 'known' => 0, 'rejected' => []]);
        }
        try {
            return response()->json(SearchKeywords::report($data['role'], $data['hash'], (string) ($data['tab'] ?? ''), $data['phrases']));
        } catch (\Throwable $e) {
            // Never the phrases themselves in the log: they are on-screen text.
            \Illuminate\Support\Facades\Log::warning('[search-words] report failed: ' . get_class($e) . ' ' . $e->getMessage());

            return response()->json(['accepted' => [], 'known' => 0, 'rejected' => []]);
        }
    }

    private function roles(Request $request): array
    {
        return array_values(array_unique(array_map('strval', UserRoles::names($request))));
    }
}
