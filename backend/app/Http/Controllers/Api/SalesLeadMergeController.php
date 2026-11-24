<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SalesLeadMerge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sales → Leads: likely duplicates, merge, "not the same" (2026-10-23). See App\Support\SalesLeadMerge. */
class SalesLeadMergeController extends Controller
{
    /** GET /sales/leads/duplicates */
    public function index(): JsonResponse
    {
        return response()->json(['groups' => SalesLeadMerge::groups()]);
    }

    /** POST /sales/leads/{lead}/merge {from} — merge lead {from} INTO {lead}. */
    public function merge(Request $request, int $lead): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'integer']]);
        return response()->json(SalesLeadMerge::merge($lead, (int) $data['from'], (int) $request->user()->id, $request->ip()));
    }

    /** POST /sales/leads/not-duplicates {ids: [..]} — stop suggesting these as one prospect. */
    public function dismiss(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:2', 'max:20'], 'ids.*' => ['integer']]);
        SalesLeadMerge::dismiss($data['ids'], (int) $request->user()->id);
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'sales.leads_not_duplicates', 'entity_type' => 'sales_lead', 'entity_id' => (int) $data['ids'][0],
            'payload' => json_encode(['summary' => 'Marked leads #' . implode(', #', $data['ids']) . ' as different prospects']), 'ip_address' => $request->ip(), 'created_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
