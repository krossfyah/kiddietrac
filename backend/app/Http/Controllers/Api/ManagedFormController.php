<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

    /** Addressed to this user by ROLE audience, or named individually. */
    private function mayUseForm(object $form, int $uid): bool
    {
        $named = DB::table('managed_form_recipients')
            ->where('managed_form_id', $form->id)->where('user_id', $uid)->exists();
        if ($named) return true;
        $aud = $form->audiences ? (json_decode($form->audiences, true) ?: []) : [];
        return (bool) array_intersect($aud, $this->roles($uid));
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
        // Who uploaded each form. created_by_id was already stored but never
        // surfaced, so the library could not answer "who added this, and when".
        $uploaderIds = $forms->pluck('created_by_id')->filter()->unique()->all();
        $uploaders = empty($uploaderIds) ? collect() : DB::table('users')->whereIn('id', $uploaderIds)
            ->get(['id', 'first_name', 'last_name'])->keyBy('id');
        // How many people were named individually (vs reached by role audience).
        $named = DB::table('managed_form_recipients')
            ->whereIn('managed_form_id', $forms->pluck('id')->all())
            ->select('managed_form_id', DB::raw('COUNT(*) as n'))
            ->groupBy('managed_form_id')->pluck('n', 'managed_form_id');

        $out = $forms->map(function ($f) use ($counts, $uploaders, $named) {
            $f->audiences = $f->audiences ? (json_decode($f->audiences, true) ?: []) : [];
            $f->signoff_count = (int) ($counts[$f->id] ?? 0);
            $u = $uploaders[$f->created_by_id] ?? null;
            $f->uploaded_by = $u ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) : null;
            $f->named_count = (int) ($named[$f->id] ?? 0);
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
            'audiences'   => ['nullable'],   // optional when specific people are named
            'recipient_ids' => ['nullable'],
            // Opt-in PER FORM, chosen by the admin at upload. Most uploads are
            // read-and-sign notices where typing into the page makes no sense.
            'fillable'    => ['nullable'],
            'reusable'    => ['nullable'],
        ]);

        $recipientIds = $request->input('recipient_ids');
        if (is_string($recipientIds)) $recipientIds = json_decode($recipientIds, true);
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', (array) $recipientIds))));

        $audiences = $data['audiences'] ?? [];
        if (is_string($audiences)) {
            $audiences = json_decode($audiences, true) ?: array_filter(array_map('trim', explode(',', $audiences)));
        }
        $audiences = array_values(array_intersect((array) $audiences, self::ROLES));
        // NOTE: no longer "audiences required" — a form may instead be addressed to
        // named people. The combined check below enforces that it reaches somebody.

        // A form has to reach SOMEBODY: either a role audience or named people.
        if (empty($audiences) && empty($recipientIds)) {
            return response()->json([
                'message' => 'Choose at least one audience or pick specific people.',
                'errors' => ['audiences' => ['Choose at least one audience or pick specific people.']],
            ], 422);
        }

        $path = $request->file('file')->store('managed-forms/' . $agencyId, 'public');
        $id = DB::table('managed_forms')->insertGetId([
            'agency_id'     => $agencyId,
            'title'         => $data['title'],
            'description'   => $data['description'] ?? null,
            'file_url'      => '/storage/' . $path,
            'fillable'      => filter_var($request->input('fillable', false), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'reusable'      => filter_var($request->input('reusable', false), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'file_type'     => 'application/pdf',
            'file_size'     => $request->file('file')->getSize(),
            'audiences'     => json_encode($audiences),
            'active'        => 1,
            'created_by_id' => (int) $request->user()->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        if (!empty($recipientIds)) {
            $now = now();
            foreach ($recipientIds as $rid) {
                DB::table('managed_form_recipients')->insertOrIgnore([
                    'managed_form_id' => $id, 'user_id' => $rid, 'created_at' => $now,
                ]);
            }
        }
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
        // description was not editable, so a typo in it meant re-uploading the PDF.
        if ($request->has('description')) {
            $patch['description'] = (string) $request->input('description');
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
                'f.title as form_title', 'f.file_url', 's.filled_file_url',
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
            ->select(['s.*', 'f.title as form_title', 'f.file_url', 'f.fillable', 'u.first_name', 'u.last_name', 'u.email'])
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
        // Only a SIGNED form leaves the list. A draft keeps its place — with the
        // answers so far — so the user can come back and finish it.
        $signed = DB::table('managed_form_signoffs')->where('user_id', $uid)
            ->whereNotNull('signed_at')->pluck('managed_form_id')->all();
        // A REUSABLE form is never "done" — an educator fills it again for the next
        // child or the next week — so signing it must not remove it from the list.
        $namedFor = DB::table('managed_form_recipients')->where('user_id', $uid)
            ->pluck('managed_form_id')->map(fn ($v) => (int) $v)->all();
        $reusableIds = DB::table('managed_forms')->where('reusable', 1)->pluck('id')->all();
        $signed = array_values(array_diff($signed, $reusableIds));
        $drafts = DB::table('managed_form_signoffs')->where('user_id', $uid)
            ->whereNull('signed_at')->pluck('field_values', 'managed_form_id')->all();
        $forms = DB::table('managed_forms')->where('agency_id', $agencyId)->where('active', 1)
            ->when(! empty($signed), fn ($q) => $q->whereNotIn('id', $signed))
            ->orderByDesc('id')->get()
            ->filter(function ($f) use ($roles, $namedFor) {
                // Addressed to me if my ROLE is in the audience, or if I was named
                // individually. A form with named recipients and no audience reaches
                // exactly those people and nobody else.
                if (in_array((int) $f->id, $namedFor, true)) return true;
                $aud = $f->audiences ? (json_decode($f->audiences, true) ?: []) : [];
                return (bool) array_intersect($aud, $roles);
            })
            ->map(function ($f) use ($drafts) {
                $raw = $drafts[$f->id] ?? null;
                return [
                    'id' => $f->id, 'title' => $f->title, 'description' => $f->description,
                    'file_url' => $f->file_url, 'fillable' => (bool) ($f->fillable ?? false),
                    'reusable' => (bool) ($f->reusable ?? false),
                    'draft_values' => $raw ? (json_decode($raw, true) ?: null) : null,
                ];
            })
            ->values();
        return response()->json(['forms' => $forms, 'count' => $forms->count()]);
    }

    /**
     * POST /managed-forms/{id}/draft — save answers WITHOUT signing.
     * A draft is simply a signoff row with signed_at NULL: the form stays in the
     * user's list, and reopening it restores what they typed. Signing later fills
     * the same row in, so a person can never end up with two records for one form.
     */
    public function draft(Request $request, int $id): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agencyId = $this->agencyId($request);
        $form = DB::table('managed_forms')->where('id', $id)->where('agency_id', $agencyId)->where('active', 1)->first();
        if (! $form) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $this->mayUseForm($form, $uid)) {
            return response()->json(['message' => 'This form is not assigned to you.'], 403);
        }
        // Never let a draft overwrite a completed submission.
        // Work on the OPEN (unsigned) row. For a reusable form the user may already
        // have signed submissions on file; those must never be touched.
        $open = DB::table('managed_form_signoffs')->where('managed_form_id', $id)
            ->where('user_id', $uid)->whereNull('signed_at')->first();
        if (! $open && ! ($form->reusable ?? false)) {
            $done = DB::table('managed_form_signoffs')->where('managed_form_id', $id)
                ->where('user_id', $uid)->whereNotNull('signed_at')->exists();
            if ($done) {
                return response()->json(['message' => 'This form has already been signed.'], 409);
            }
        }
        $data = $request->validate(['field_values' => ['nullable', 'array']]);
        $payload = [
            'field_values' => !empty($data['field_values']) ? json_encode($data['field_values']) : null,
            'signed_at'    => null,
            'updated_at'   => now(),
        ];
        if ($open) {
            DB::table('managed_form_signoffs')->where('id', $open->id)->update($payload);
        } else {
            DB::table('managed_form_signoffs')->insert($payload + [
                'managed_form_id' => $id, 'user_id' => $uid, 'created_at' => now(),
            ]);
        }

        return response()->json(['ok' => true, 'message' => 'Draft saved.']);
    }

    /**
     * GET /managed-forms/mine - the caller's own drafts and submitted forms.
     * Feeds the Drafts / Submitted tabs in My Forms.
     */
    public function mine(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agencyId = $this->agencyId($request);
        if (! $agencyId) {
            return response()->json(['drafts' => [], 'submitted' => []]);
        }

        $rows = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->where('s.user_id', $uid)->where('f.agency_id', $agencyId)
            ->orderByDesc('s.updated_at')
            ->get(['s.id', 's.managed_form_id', 's.signed_at', 's.updated_at', 's.field_values',
                   's.filled_file_url', 'f.title', 'f.description', 'f.file_url', 'f.fillable', 'f.reusable']);

        $shape = function ($r) {
            $vals = $r->field_values ? (json_decode($r->field_values, true) ?: []) : [];
            return [
                'signoff_id'   => $r->id,
                'id'           => $r->managed_form_id,
                'title'        => $r->title,
                'description'  => $r->description,
                'file_url'     => $r->filled_file_url ?: $r->file_url,
                'original_url' => $r->file_url,
                'fillable'     => (bool) $r->fillable,
                'reusable'     => (bool) $r->reusable,
                'answers'      => count($vals),
                'signed_at'    => $r->signed_at,
                'updated_at'   => $r->updated_at,
            ];
        };

        return response()->json([
            'drafts'    => $rows->whereNull('signed_at')->map($shape)->values(),
            'submitted' => $rows->whereNotNull('signed_at')->map($shape)->values(),
        ]);
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
        if (! $this->mayUseForm($form, $uid)) {
            return response()->json(['message' => 'This form is not assigned to you.'], 403);
        }
        $data = $request->validate([
            'signature'    => ['required', 'string'],   // base64 PNG
            'name'         => ['nullable', 'string', 'max:190'],
            // Fill-and-sign only. field_values keeps the answers queryable without
            // parsing a PDF; filled_file is the completed PDF (the client writes the
            // values into the original's own AcroForm fields, embeds the signature
            // and flattens), which is the artefact a parent or regulator wants.
            'field_values' => ['nullable', 'array'],
            'filled_file'  => ['nullable', 'string'],   // base64 PDF, no data: prefix
        ]);
        $u = DB::table('users')->where('id', $uid)->first();
        // validate() omits an absent nullable key entirely, and no client sends
        // `name` — so $data['name'] threw "Undefined array key" and every signature
        // submission 500'd, the plain read-and-sign flow included.
        $name = ($data['name'] ?? null) ?: trim((string) (($u->first_name ?? '') . ' ' . ($u->last_name ?? '')));

        // Store the completed PDF next to the original.
        $filledUrl = null;
        if (!empty($data['filled_file'])) {
            $bin = base64_decode(preg_replace('#^data:application/pdf;base64,#', '', $data['filled_file']), true);
            // 20MB ceiling and a real %PDF header — never write whatever was posted.
            if ($bin !== false && strlen($bin) > 0 && strlen($bin) <= 20971520 && str_starts_with($bin, '%PDF')) {
                $fp = 'managed-forms/filled/' . $id . '/' . $uid . '-' . time() . '.pdf';
                Storage::disk('public')->put($fp, $bin);
                $filledUrl = '/storage/' . $fp;
            }
        }

        // Sign the OPEN draft if one exists, otherwise start a new record. A reusable
        // form therefore accumulates one row per submission instead of overwriting.
        $open = DB::table('managed_form_signoffs')->where('managed_form_id', $id)
            ->where('user_id', $uid)->whereNull('signed_at')->first();
        $row = [
                'signer_name' => $name ?: null,
                'signature'   => mb_substr($data['signature'], 0, 400000),
                'field_values' => !empty($data['field_values']) ? json_encode($data['field_values']) : null,
                'filled_file_url' => $filledUrl,
                'signed_at'   => now(),
                'ip_address'  => substr((string) $request->ip(), 0, 45),
                'updated_at'  => now(),
        ];
        if ($open) {
            DB::table('managed_form_signoffs')->where('id', $open->id)->update($row);
        } else {
            DB::table('managed_form_signoffs')->insert($row + [
                'managed_form_id' => $id, 'user_id' => $uid, 'created_at' => now(),
            ]);
        }

        return response()->json(['ok' => true, 'message' => 'Signed. Thank you!']);
    }
}
