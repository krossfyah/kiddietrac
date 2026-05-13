<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\Observation;
use App\Services\AiObservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AiObservationController v21.
 *
 * Endpoints:
 *   POST /provider/observations/structure       - call AI to structure raw text
 *   POST /provider/observations/save            - save (with optional educator edits)
 *   GET  /provider/observations                 - list (for educator's children)
 */
class AiObservationController extends Controller
{
    public function __construct(protected AiObservationService $ai)
    {
    }

    /**
     * POST /provider/observations/structure
     * Body: { child_id, raw_text }
     * Returns the AI-structured output WITHOUT saving. Educator can review/edit before save.
     */
    public function structure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer|exists:children,id',
            'raw_text' => 'required|string|min:10|max:3000',
        ]);

        $child = Child::find($data['child_id']);
        if (! $child) return response()->json(['error' => 'Child not found'], 404);

        $result = $this->ai->structure($child, $data['raw_text']);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'error'  => 'AI structuring failed',
                'detail' => $result['error'] ?? 'Unknown error',
            ], 502);
        }

        return response()->json([
            'success'    => true,
            'structured' => $result['structured'],
            'meta'       => [
                'model'       => $result['model'] ?? null,
                'tokens_used' => $result['tokens_used'] ?? null,
            ],
        ]);
    }

    /**
     * POST /provider/observations/save
     * Body: { child_id, raw_text, structured: {domain, hdlh_milestones, parent_summary},
     *         shared_with_family: bool, ai_generated: bool }
     */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id'                       => 'required|integer|exists:children,id',
            'raw_text'                       => 'required|string|min:10|max:3000',
            'structured'                     => 'required|array',
            'structured.domain'              => 'required|string|max:60',
            'structured.hdlh_milestones'     => 'nullable|array',
            'structured.parent_summary'      => 'required|string|max:2000',
            'shared_with_family'             => 'nullable|boolean',
            'ai_generated'                   => 'nullable|boolean',
            'ai_model_used'                  => 'nullable|string|max:80',
            'ai_tokens_used'                 => 'nullable|integer',
        ]);

        $observation = Observation::create([
            'child_id'             => $data['child_id'],
            'recorded_by_id'       => $request->user()->id,
            'observed_at'          => now(),
            'domain'               => $data['structured']['domain'],
            'body'                 => $data['structured']['parent_summary'],
            'raw_text'             => $data['raw_text'],
            'hdlh_milestones'      => $data['structured']['hdlh_milestones'] ?? [],
            'family_summary'       => $data['structured']['parent_summary'],
            'ai_generated'         => $data['ai_generated'] ?? true,
            'ai_model_used'        => $data['ai_model_used'] ?? null,
            'ai_tokens_used'       => $data['ai_tokens_used'] ?? null,
            'ai_processed_at'      => now(),
            'educator_reviewed_at' => now(),
            'shared_with_family'   => $data['shared_with_family'] ?? false,
        ]);

        return response()->json(['data' => $observation], 201);
    }

    /**
     * GET /provider/observations - list recent for this educator's scope
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min(100, max(5, (int) $request->query('limit', 30)));

        $q = Observation::query()
            ->where('recorded_by_id', $user->id)
            ->orderByDesc('observed_at')
            ->limit($limit);

        if ($childId = $request->query('child_id')) {
            $q->where('child_id', (int) $childId);
        }

        $rows = $q->get();

        // Hydrate child names
        $childIds = $rows->pluck('child_id')->unique()->all();
        $children = DB::table('children')
            ->whereIn('id', $childIds)
            ->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id');

        $out = $rows->map(function ($o) use ($children) {
            return [
                'id'                 => $o->id,
                'child_id'           => $o->child_id,
                'child_name'         => $children[$o->child_id] ?? '-',
                'observed_at'        => $o->observed_at,
                'domain'             => $o->domain,
                'body'               => $o->body,
                'family_summary'     => $o->family_summary,
                'hdlh_milestones'    => $o->hdlh_milestones,
                'ai_generated'       => (bool) $o->ai_generated,
                'shared_with_family' => (bool) $o->shared_with_family,
            ];
        });

        return response()->json(['observations' => $out]);
    }
}
