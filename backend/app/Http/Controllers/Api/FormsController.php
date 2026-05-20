<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p43 — Custom forms builder.
 *
 * Agency admins + directors define forms with a JSON schema:
 *   schema_json = [
 *     { id, type: 'text'|'textarea'|'email'|'number'|'date'|'select'|'checkbox'|'radio',
 *       label, required?, placeholder?, help?, options?[] }
 *   ]
 * Parents (and optionally staff) submit responses; responses are stored as
 * { field_id: value } JSON.
 *
 * Endpoints:
 *   GET    /admin/forms                 List forms in this agency
 *   POST   /admin/forms                 Create
 *   GET    /admin/forms/{id}            Show + responses count
 *   PATCH  /admin/forms/{id}            Update title/desc/schema/audience/status
 *   DELETE /admin/forms/{id}            Soft delete
 *   GET    /admin/forms/{id}/responses  Paginated list of responses
 *   GET    /forms                       Parent-facing: published forms in audience
 *   POST   /forms/{id}/submit           Parent-facing: submit response
 */
final class FormsController extends Controller
{
    // ── Agency resolver (mirrors AdminController's lazy version) ──────────
    private function getAgencyId(Request $request): ?int
    {
        $userId = $request->user()->id;
        $isPlatform = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatform) {
            if ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists()) {
                return $activeId;
            }
            return (int) DB::table('agencies')->whereNull('deleted_at')->orderBy('id')->value('id');
        }
        $byAgencyAdmin = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'agency_admin')->where('active', true)->value('agency_id');
        if ($byAgencyAdmin) return (int) $byAgencyAdmin;
        $directorCentre = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'centre_director')->where('active', true)->value('centre_id');
        if ($directorCentre) return (int) DB::table('centres')->where('id', $directorCentre)->value('agency_id');
        return null;
    }

    // ── Admin CRUD ────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['forms' => []]);

        $rows = DB::table('custom_forms')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();
        return response()->json(['forms' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('custom_forms')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        return response()->json(['form' => $row]);
    }

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'schema' => ['required', 'array', 'min:1'],
            'schema.*.id' => ['required', 'string', 'max:60'],
            'schema.*.type' => ['required', 'in:text,textarea,email,number,date,select,checkbox,radio'],
            'schema.*.label' => ['required', 'string', 'max:200'],
            'schema.*.required' => ['nullable', 'boolean'],
            'schema.*.placeholder' => ['nullable', 'string', 'max:200'],
            'schema.*.help' => ['nullable', 'string', 'max:500'],
            'schema.*.options' => ['nullable', 'array'],
            'audience' => ['required', 'in:all_families,active_families,waitlist,prospects,staff'],
            'centre_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $id = DB::table('custom_forms')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'schema_json' => json_encode($data['schema']),
            'audience' => $data['audience'],
            'status' => $data['status'] ?? 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Form saved'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('custom_forms')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'schema' => ['sometimes', 'array', 'min:1'],
            'audience' => ['sometimes', 'in:all_families,active_families,waitlist,prospects,staff'],
            'status' => ['sometimes', 'in:draft,published,archived'],
        ]);
        if (isset($data['schema'])) {
            $data['schema_json'] = json_encode($data['schema']);
            unset($data['schema']);
        }
        $data['updated_at'] = now();
        DB::table('custom_forms')->where('id', $id)->update($data);
        return response()->json(['message' => 'Form updated']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('custom_forms')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        DB::table('custom_forms')->where('id', $id)->update(['deleted_at' => now()]);
        return response()->json(['message' => 'Form deleted']);
    }

    public function listResponses(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $form = DB::table('custom_forms')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$form) return response()->json(['message' => 'Not found'], 404);
        $rows = DB::table('custom_form_responses as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.submitted_by_user_id')
            ->where('r.form_id', $id)
            ->orderByDesc('r.submitted_at')
            ->limit(500)
            ->get([
                'r.id', 'r.response_json', 'r.submitted_at',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email, 'unknown') as submitter_name"),
                'u.email as submitter_email',
            ]);
        return response()->json([
            'form' => $form,
            'responses' => $rows,
            'count' => $rows->count(),
        ]);
    }

    // ── Parent-facing ─────────────────────────────────────────────────────

    /**
     * GET /forms — published forms whose audience matches this caller.
     */
    public function publishedForUser(Request $request): JsonResponse
    {
        $user = $request->user();

        // Resolve which agency this caller belongs to (via guardian/family/centre/agency)
        $familyIds = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->all();
        if (empty($familyIds)) return response()->json(['forms' => []]);
        $centreIds = DB::table('families')->whereIn('id', $familyIds)->whereNull('deleted_at')->pluck('centre_id')->all();
        if (empty($centreIds)) return response()->json(['forms' => []]);
        $agencyIds = DB::table('centres')->whereIn('id', $centreIds)->pluck('agency_id')->unique()->all();

        $rows = DB::table('custom_forms')
            ->whereIn('agency_id', $agencyIds)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($centreIds) {
                $q->whereNull('centre_id')->orWhereIn('centre_id', $centreIds);
            })
            ->whereIn('audience', ['all_families', 'active_families', 'waitlist', 'prospects'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        // Also indicate whether THIS user has already submitted each one
        $submittedIds = DB::table('custom_form_responses')
            ->whereIn('form_id', $rows->pluck('id'))
            ->where('submitted_by_user_id', $user->id)
            ->pluck('form_id')->all();
        $rows = $rows->map(function ($f) use ($submittedIds) {
            $f->already_submitted = in_array($f->id, $submittedIds);
            return $f;
        });

        return response()->json(['forms' => $rows]);
    }

    /**
     * POST /forms/{id}/submit — record a parent's response.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $form = DB::table('custom_forms')->where('id', $id)->whereNull('deleted_at')->where('status', 'published')->first();
        if (!$form) return response()->json(['message' => 'Form not available'], 404);

        $schema = json_decode((string) $form->schema_json, true) ?: [];
        $data = $request->validate(['response' => ['required', 'array']]);
        $response = $data['response'];

        // Coarse validation against the schema's required + type
        foreach ($schema as $field) {
            $fid = $field['id'] ?? null;
            if (!$fid) continue;
            $val = $response[$fid] ?? null;
            if (!empty($field['required']) && ($val === null || $val === '' || $val === [])) {
                return response()->json(['message' => 'Field "' . ($field['label'] ?? $fid) . '" is required.'], 422);
            }
        }

        DB::table('custom_form_responses')->insert([
            'form_id' => $id,
            'submitted_by_user_id' => $request->user()->id,
            'response_json' => json_encode($response),
            'submitted_at' => now(),
        ]);
        DB::table('custom_forms')->where('id', $id)->increment('response_count');
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => 'form.submitted',
            'entity_type' => 'custom_form',
            'entity_id' => $id,
            'payload' => json_encode(['form_title' => $form->title]),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Response saved'], 201);
    }
}
