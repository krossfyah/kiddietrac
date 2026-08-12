<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
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
    use ResolvesCentreContext;

    private const ROLES = ['guardian', 'educator', 'home_visitor', 'centre_director'];

    /**
     * The agency this request operates on.
     *
     * This used to return X-Active-Agency-Id verbatim, with no check that the caller
     * has anything to do with that agency. A stale header therefore filed uploads
     * into someone else's tenant: on 2026-08-12 an agency_admin of agency 2 uploaded
     * two forms while her browser still carried "6", so both landed in Test Agency,
     * invisible to her AND to her educators, with no error to explain it.
     *
     * resolveAgencyId() (ResolvesCentreContext, from the tenant-isolation audit)
     * already does this correctly: the header is honoured only for a platform_admin
     * or a user who actually holds a role in that agency, otherwise it falls back to
     * the caller's own agency. Everything here now goes through it.
     */
    private function agencyId(Request $request): int
    {
        return (int) ($this->resolveAgencyId($request) ?: 0);
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
        // The actual ids too, so the Edit dialog can pre-select the people already
        // named — editing was previously unable to show, let alone change, them.
        $namedIds = DB::table('managed_form_recipients')
            ->whereIn('managed_form_id', $forms->pluck('id')->all())
            ->get(['managed_form_id', 'user_id'])
            ->groupBy('managed_form_id')
            ->map(fn ($g) => $g->pluck('user_id')->map(fn ($v) => (int) $v)->values()->all());

        $out = $forms->map(function ($f) use ($counts, $uploaders, $named, $namedIds) {
            $f->audiences = $f->audiences ? (json_decode($f->audiences, true) ?: []) : [];
            $f->signoff_count = (int) ($counts[$f->id] ?? 0);
            $u = $uploaders[$f->created_by_id] ?? null;
            $f->uploaded_by = $u ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) : null;
            $f->named_count = (int) ($named[$f->id] ?? 0);
            $f->recipient_ids = $namedIds[$f->id] ?? [];
            $f->notify_email = $f->notify_email ?? null;
            $f->fillable = (bool) ($f->fillable ?? false);
            $f->reusable = (bool) ($f->reusable ?? false);
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
            // Required: a library of bare titles ('test 8') tells an admin nothing about
            // what a form is for, and the Completed tab now shows this column. NOT enforced
            // in update(): the activate/deactivate button PATCHes {active} alone.
            'description' => ['required', 'string', 'max:2000'],
            'file'        => ['required', 'file', 'mimes:pdf', 'max:15360'], // 15 MB
            'audiences'   => ['nullable'],   // optional when specific people are named
            'recipient_ids' => ['nullable'],
            // Opt-in PER FORM, chosen by the admin at upload. Most uploads are
            // read-and-sign notices where typing into the page makes no sense.
            'fillable'    => ['nullable'],
            'reusable'    => ['nullable'],
            // Optional: where a completed copy should be sent. Blank = nobody.
            'notify_email' => ['nullable', 'email', 'max:190'],
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
            'notify_email'  => $data['notify_email'] ?? null,
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
        // Everything chosen at upload has to be changeable afterwards. These two were
        // upload-only, so getting a toggle wrong meant deleting the form and
        // re-uploading the PDF just to flip a boolean.
        if ($request->has('fillable')) {
            $patch['fillable'] = filter_var($request->input('fillable'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if ($request->has('reusable')) {
            $patch['reusable'] = filter_var($request->input('reusable'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if ($request->has('notify_email')) {
            $em = trim((string) $request->input('notify_email'));
            // Clearing the field switches the notification off again.
            $patch['notify_email'] = ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) ? $em : null;
        }
        // Named recipients, likewise editable. Sent as the COMPLETE list (the picker
        // shows the current selection), so this replaces rather than appends —
        // otherwise removing somebody would be impossible.
        $newRecipients = null;
        if ($request->has('recipient_ids')) {
            $ids = $request->input('recipient_ids');
            if (is_string($ids)) $ids = json_decode($ids, true);
            $newRecipients = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        }

        // Check BEFORE writing: an edit must not leave the form with nobody to sign
        // it. Validating afterwards would mean rejecting a change already applied.
        $audAfter = array_key_exists('audiences', $patch)
            ? (json_decode($patch['audiences'], true) ?: [])
            : ($form->audiences ? (json_decode($form->audiences, true) ?: []) : []);
        $namedAfter = $newRecipients !== null
            ? count($newRecipients)
            : DB::table('managed_form_recipients')->where('managed_form_id', $id)->count();
        if (empty($audAfter) && $namedAfter === 0) {
            return response()->json([
                'message' => 'That would leave the form with nobody to sign it — choose an audience or pick specific people.',
                'errors' => ['audiences' => ['Choose at least one audience or pick specific people.']],
            ], 422);
        }

        DB::table('managed_forms')->where('id', $id)->update($patch);

        if ($newRecipients !== null) {
            $existing = DB::table('managed_form_recipients')->where('managed_form_id', $id)
                ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            $remove = array_diff($existing, $newRecipients);
            $add    = array_diff($newRecipients, $existing);
            if ($remove) {
                DB::table('managed_form_recipients')->where('managed_form_id', $id)
                    ->whereIn('user_id', $remove)->delete();
            }
            foreach ($add as $rid) {
                DB::table('managed_form_recipients')->insertOrIgnore([
                    'managed_form_id' => $id, 'user_id' => $rid, 'created_at' => now(),
                ]);
            }
        }

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
                'f.title as form_title', 'f.description as form_description',
                'f.file_url', 's.filled_file_url',
                'f.notify_email as form_notify_email', 's.notified_at', 's.notified_to',
                'u.first_name', 'u.last_name', 'u.email',
            ])
            ->limit(500)
            ->get();
        return response()->json(['signoffs' => $rows]);
    }

    /**
     * POST /admin/managed-forms/signoffs/{id}/email — send this completed copy to the
     * address configured on its form.
     *
     * Setting an address only affects submissions signed AFTER it was set, so forms
     * already signed had no way to reach it; this also re-sends one that shows as
     * "Not sent". Scoped to the caller's agency like every other admin action here.
     */
    public function emailSignoff(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);
        $row = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $id)->where('f.agency_id', $agencyId)
            ->select(['s.id', 's.user_id', 's.signer_name', 's.filled_file_url', 's.signed_at',
                      'f.id as form_id', 'f.agency_id', 'f.title', 'f.description', 'f.notify_email',
                      'u.email as signer_email'])
            ->first();
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $row->signed_at) {
            return response()->json(['message' => 'That form has not been signed yet.'], 422);
        }
        if (! $row->notify_email) {
            return response()->json(['message' => 'No address is set on this form. Add one with Edit first.'], 422);
        }

        // emailCompletedForm() reads the form's own fields, so hand it a form-shaped object.
        $form = (object) [
            'id' => $row->form_id, 'agency_id' => $row->agency_id, 'title' => $row->title,
            'description' => $row->description, 'notify_email' => $row->notify_email,
        ];
        $this->emailCompletedForm($form, $row->signer_name, $row->filled_file_url,
            (string) ($row->signer_email ?? ''), (int) $row->user_id, (int) $row->id);

        return response()->json(['ok' => true, 'message' => 'Copy emailed to ' . $row->notify_email . '.']);
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
            $signoffId = (int) $open->id;
        } else {
            $signoffId = (int) DB::table('managed_form_signoffs')->insertGetId($row + [
                'managed_form_id' => $id, 'user_id' => $uid, 'created_at' => now(),
            ]);
        }

        // If the form names an address, send the completed copy there. Best-effort:
        // the signature is already saved, so a mail problem must not fail the submit.
        try {
            $this->emailCompletedForm($form, $name, $filledUrl, (string) ($u->email ?? ''), $uid, $signoffId);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true, 'message' => 'Signed. Thank you!']);
    }

    /**
     * Email the completed form to the address configured on it, with the filled PDF
     * attached. Requested so a signed form can land in a compliance inbox or with a
     * director instead of only living in the Completed tab.
     *
     * The recipient is chosen by an admin and is often outside the agency (a licensing
     * contact, a shared mailbox), so this carries X-KT-Bypass-Suppression: it is
     * operational mail the admin explicitly asked for, like a support ticket, not a
     * broadcast that the per-agency comms switch should silence.
     */
    /**
     * The signer's place, and what this agency calls it.
     *
     * Agencies are set up differently: settings.centre_term is 'centre', 'room' or
     * 'provider', and a home-provider agency's centre record IS the provider. The
     * lookup is therefore the same; only the label changes. Falls back to the room's
     * own centre for staff attached through educator_rooms rather than directly.
     *
     * @return array{0: ?string, 1: string} [name, word]
     */
    private function signerPlace(int $userId, int $agencyId): array
    {
        $word = 'centre';
        try {
            $settings = DB::table('agencies')->where('id', $agencyId)->value('settings');
            $arr = $settings ? (json_decode($settings, true) ?: []) : [];
            $t = $arr['centre_term'] ?? 'centre';
            if (in_array($t, ['centre', 'room', 'provider'], true)) $word = $t;
        } catch (\Throwable $e) {
        }
        if (! $userId) return [null, $word];

        try {
            $centreId = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
                ->whereNotNull('centre_id')->value('centre_id');
            if (! $centreId && \Illuminate\Support\Facades\Schema::hasTable('educator_rooms')) {
                // Attached to a room rather than to a centre directly.
                $centreId = DB::table('educator_rooms as er')->join('rooms as r', 'r.id', '=', 'er.room_id')
                    ->where('er.user_id', $userId)->value('r.centre_id');
            }
            if (! $centreId) return [null, $word];
            $name = DB::table('centres')->where('id', $centreId)->value('name');
            return [$name ?: null, $word];
        } catch (\Throwable $e) {
            return [null, $word];
        }
    }

    private function emailCompletedForm(object $form, ?string $signerName, ?string $filledUrl, string $signerEmail, int $signerId = 0, int $signoffId = 0): void
    {
        $to = trim((string) ($form->notify_email ?? ''));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) return;

        $who = $signerName ?: 'Someone';
        $when = \App\Support\AgencyTime::fmt(now(), \App\Support\AgencyTime::tz((int) $form->agency_id));

        // Name the place in the subject. Which KIND of place that is depends on the
        // agency: settings.centre_term is 'centre', 'room' or 'provider', and the
        // centre record IS that thing - a home-provider agency's centre is the
        // provider. Same lookup either way; only the word changes.
        [$placeName, $placeWord] = $this->signerPlace($signerId, (int) $form->agency_id);

        $subject = 'Completed form: ' . $form->title . ($placeName ? " \u{2014} " . $placeName : '');

        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
              . e($who) . ' has completed and signed <strong>' . e($form->title) . '</strong>.</p>'
              . \App\Services\EmailTemplate::calloutBox(
                    '<strong>Form:</strong> ' . e($form->title)
                    . ($form->description ? '<br><strong>About:</strong> ' . e($form->description) : '')
                    . '<br><strong>Signed by:</strong> ' . e($who) . ($signerEmail ? ' (' . e($signerEmail) . ')' : '')
                    . ($placeName ? '<br><strong>' . e(ucfirst($placeWord)) . ':</strong> ' . e($placeName) : '')
                    . '<br><strong>Signed at:</strong> ' . e($when),
                    'info'
                )
              . '<p style="margin:14px 0 0;font-size:13.5px;color:#64748B;line-height:1.6;">'
              . ($filledUrl
                    ? 'The completed PDF is attached, with the signature embedded.'
                    : 'This form was signed as a read-and-sign notice, so there is no filled PDF to attach.')
              . '</p>';

        $html = \App\Services\EmailTemplate::wrap((int) $form->agency_id, $body, [
            'eyebrow'   => 'FORM COMPLETED',
            'title'     => $form->title,
            'subtitle'  => 'Signed by ' . $who,
            'preheader' => $who . ' completed ' . $form->title . '.',
        ]);

        $absPdf = null;
        if ($filledUrl) {
            $candidate = Storage::disk('public')->path(preg_replace('#^/storage/#', '', $filledUrl));
            if (is_file($candidate)) $absPdf = $candidate;
        }
        $attachName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $form->title) . '.pdf';

        dispatch(function () use ($to, $subject, $html, $absPdf, $attachName, $signoffId) {
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($to, $subject, $absPdf, $attachName) {
                $m->to($to)
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject);
                if ($absPdf) $m->attach($absPdf, ['as' => $attachName, 'mime' => 'application/pdf']);
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
            // Stamped only once the send has actually returned, so the Completed tab
            // reports what happened rather than what was queued.
            if ($signoffId) {
                try {
                    DB::table('managed_form_signoffs')->where('id', $signoffId)
                        ->update(['notified_at' => now(), 'notified_to' => $to]);
                } catch (\Throwable $e) {}
            }
        })->onQueue('mail');
    }
}
