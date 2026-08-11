<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Forms Manager — agencies upload a fillable PDF, assign it to roles (parents /
 * educators / home-visitors), and each assigned user opens + e-signs it in the
 * app. Completed sign-offs are tracked in a table with view / download / email.
 *
 * Two tables: managed_forms (the uploaded form + audiences) and
 * managed_form_signoffs (one row per user who signed).
 */
class ManagedFormController extends Controller
{
    private const ROLES = ['guardian', 'educator', 'home_visitor', 'centre_director'];

    private function agencyId(Request $request): int
    {
        $hdr = $request->header('X-Active-Agency-Id');
        if ($hdr !== null && ctype_digit((string) $hdr)) {
            return (int) $hdr;
        }
        $uid = (int) $request->user()->id;
        $aid = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->whereNotNull('agency_id')->value('agency_id');
        if (! $aid) {
            $cid = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->whereNotNull('centre_id')->value('centre_id');
            $aid = $cid ? DB::table('centres')->where('id', $cid)->value('agency_id') : null;
        }
        return (int) ($aid ?: 0);
    }

    private function roles(int $uid): array
    {
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->pluck('role')->all();
        if (DB::table('guardians')->where('user_id', $uid)->exists()) {
            $roles[] = 'guardian';
        }
        return array_values(array_unique($roles));
    }

    private function isAdmin(Request $request): bool
    {
        return (bool) array_intersect($this->roles((int) $request->user()->id), ['platform_admin', 'agency_admin', 'centre_director']);
    }

    /* ───────────── ADMIN: library ───────────── */

    /** GET /admin/managed-forms — the agency's uploaded forms + sign-off counts. */
    public function index(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['forms' => []], 403);
        }
        $agencyId = $this->agencyId($request);
        $forms = DB::table('managed_forms')->where('agency_id', $agencyId)->orderByDesc('id')->get();
        $counts = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->where('f.agency_id', $agencyId)
            ->select('s.managed_form_id', DB::raw('COUNT(*) as n'))
            ->groupBy('s.managed_form_id')->pluck('n', 's.managed_form_id');
        $out = $forms->map(function ($f) use ($counts) {
            $f->audiences = $f->audiences ? (json_decode($f->audiences, true) ?: []) : [];
            $f->signoff_count = (int) ($counts[$f->id] ?? 0);
            return $f;
        });
        return response()->json(['forms' => $out]);
    }

    /** POST /admin/managed-forms — upload a PDF + assign to roles. */
    public function store(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No active agency.'], 422);
        }
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'file'        => ['required', 'file', 'mimes:pdf', 'max:15360'], // 15 MB
            'audiences'   => ['required'],
        ]);

        $audiences = $data['audiences'];
        if (is_string($audiences)) {
            $audiences = json_decode($audiences, true) ?: array_filter(array_map('trim', explode(',', $audiences)));
        }
        $audiences = array_values(array_intersect((array) $audiences, self::ROLES));
        if (empty($audiences)) {
            return response()->json(['message' => 'Pick at least one audience.'], 422);
        }

        $path = $request->file('file')->store('managed-forms/' . $agencyId, 'public');
        $id = DB::table('managed_forms')->insertGetId([
            'agency_id'     => $agencyId,
            'title'         => $data['title'],
            'description'   => $data['description'] ?? null,
            'file_url'      => '/storage/' . $path,
            'file_type'     => 'application/pdf',
            'file_size'     => $request->file('file')->getSize(),
            'audiences'     => json_encode($audiences),
            'active'        => 1,
            'created_by_id' => (int) $request->user()->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return response()->json(['id' => $id, 'message' => 'Form uploaded and assigned.']);
    }

    /** PATCH /admin/managed-forms/{id} — toggle active / edit audiences. */
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);
        $form = DB::table('managed_forms')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $form) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $patch = ['updated_at' => now()];
        if ($request->has('active')) {
            $patch['active'] = $request->boolean('active') ? 1 : 0;
        }
        if ($request->has('title')) {
            $patch['title'] = (string) $request->input('title');
        }
        if ($request->has('audiences')) {
            $aud = $request->input('audiences');
            if (is_string($aud)) $aud = json_decode($aud, true) ?: [];
            $patch['audiences'] = json_encode(array_values(array_intersect((array) $aud, self::ROLES)));
        }
        DB::table('managed_forms')->where('id', $id)->update($patch);
        return response()->json(['ok' => true]);
    }

    /** DELETE /admin/managed-forms/{id}. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);
        $form = DB::table('managed_forms')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $form) {
            return response()->json(['message' => 'Not found'], 404);
        }
        DB::table('managed_form_signoffs')->where('managed_form_id', $id)->delete();
        DB::table('managed_forms')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /** GET /admin/managed-forms/signoffs — completed sign-offs for the agency. */
    public function signoffs(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['signoffs' => []], 403);
        }
        $agencyId = $this->agencyId($request);
        $rows = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('f.agency_id', $agencyId)
            ->orderByDesc('s.signed_at')
            ->select([
                's.id', 's.managed_form_id', 's.signer_name', 's.signed_at',
                'f.title as form_title', 'f.file_url',
                'u.first_name', 'u.last_name', 'u.email',
            ])
            ->limit(500)
            ->get();
        return response()->json(['signoffs' => $rows]);
    }

    /** GET /admin/managed-forms/{id}/signoff/{signoffId} — one signed record (with signature). */
    public function signoffDetail(Request $request, int $id, int $signoffId): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);
        $row = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $signoffId)->where('f.agency_id', $agencyId)
            ->select(['s.*', 'f.title as form_title', 'f.file_url', 'u.first_name', 'u.last_name', 'u.email'])
            ->first();
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['signoff' => $row]);
    }

    /* ───────────── ROLE INBOX: assigned + sign ───────────── */

    /** GET /forms/assigned — forms the caller must e-sign (role match, not yet signed). */
    public function assigned(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agencyId = $this->agencyId($request);
        $roles = $this->roles($uid);
        if (empty($roles) || ! $agencyId) {
            return response()->json(['forms' => [], 'count' => 0]);
        }
        $signed = DB::table('managed_form_signoffs')->where('user_id', $uid)->pluck('managed_form_id')->all();
        $forms = DB::table('managed_forms')->where('agency_id', $agencyId)->where('active', 1)
            ->when(! empty($signed), fn ($q) => $q->whereNotIn('id', $signed))
            ->orderByDesc('id')->get()
            ->filter(function ($f) use ($roles) {
                $aud = $f->audiences ? (json_decode($f->audiences, true) ?: []) : [];
                return (bool) array_intersect($aud, $roles);
            })
            ->map(function ($f) {
                return ['id' => $f->id, 'title' => $f->title, 'description' => $f->description, 'file_url' => $f->file_url];
            })
            ->values();
        return response()->json(['forms' => $forms, 'count' => $forms->count()]);
    }

    /** POST /forms/{id}/sign — record the caller's e-signature. */
    public function sign(Request $request, int $id): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agencyId = $this->agencyId($request);
        $form = DB::table('managed_forms')->where('id', $id)->where('agency_id', $agencyId)->where('active', 1)->first();
        if (! $form) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $aud = $form->audiences ? (json_decode($form->audiences, true) ?: []) : [];
        if (! array_intersect($aud, $this->roles($uid))) {
            return response()->json(['message' => 'This form is not assigned to you.'], 403);
        }
        $data = $request->validate([
            'signature' => ['required', 'string'],   // base64 PNG
            'name'      => ['nullable', 'string', 'max:190'],
        ]);
        $u = DB::table('users')->where('id', $uid)->first();
        $name = $data['name'] ?: trim((string) (($u->first_name ?? '') . ' ' . ($u->last_name ?? '')));

        DB::table('managed_form_signoffs')->updateOrInsert(
            ['managed_form_id' => $id, 'user_id' => $uid],
            [
                'signer_name' => $name ?: null,
                'signature'   => mb_substr($data['signature'], 0, 400000),
                'signed_at'   => now(),
                'ip_address'  => substr((string) $request->ip(), 0, 45),
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );

        return response()->json(['ok' => true, 'message' => 'Signed. Thank you!']);
    }
}
