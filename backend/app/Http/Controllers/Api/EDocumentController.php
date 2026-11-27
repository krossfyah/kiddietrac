<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Models\EDocumentSignature;
use App\Models\EDocumentTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EDocumentController v22p2.2
 *
 *  Director endpoints:
 *    GET    /director/edocuments                      list templates at centre
 *    POST   /director/edocuments                      upload a PDF template (multipart)
 *    PATCH  /director/edocuments/{id}                 update meta
 *    POST   /director/edocuments/{id}/archive         soft-archive
 *    GET    /director/edocuments/{id}/signatures      who has signed
 *    GET    /director/edocuments/{id}/download        director-side download
 *
 *  Parent endpoints:
 *    GET    /parent/edocuments                        documents for my family
 *    POST   /parent/edocuments/{id}/sign              record my signature
 *    GET    /parent/edocuments/{id}/download          stream PDF (auth-gated)
 */
class EDocumentController extends Controller
{
    use ResolvesCentreContext;

    private const PRIVATE_DIR = 'private/edocuments';
    private const MAX_BYTES   = 10 * 1024 * 1024; // 10 MB

    // ----- Director side -----

    /* THE CENTRE A DIRECTOR MANAGES DOCUMENTS FOR (2026-11-05 leak sweep). resolveCentreId() returns the lowest
       centre where the person is director, admin OR EDUCATOR, so a director of centre Y who also teaches at
       centre X got X's documents, signatures and families' emails. An agency-wide caller (admin / platform)
       keeps resolveCentreId(); anyone else gets a centre where they are centre_director in this agency, or none. */
    /* EVERY CENTRE, NOT THE FIRST (2026-11-13 sweep, after "Could not save - Child not in your centre" on an
       immunization). directorCentreId() is ONE centre: for an agency admin, resolveCentreId()'s pick - the agency's
       first - so the admin's E-documents list and dashboard count showed centre 8 only at iLearn (seven centres) and
       every other centre's document answered "Not found"; a director of two centres saw only the lower one's. Lists
       and per-document actions now use all of them: agency-wide callers, every centre they may see in the active
       agency; anyone else, every centre where they are centre_director (inside CentreScope). Fails closed. A new
       document still goes to directorCentreId(), or to the centre the request names if it is one of these. */
    private function directorCentreIds(Request $request): array
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        if (! $agencyId) return [];
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $visible = $this->visibleCentreIds($request);
        if ($scope === null) return $visible;
        $dir = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $request->user()->id)->where('role', 'centre_director')->where('active', true)
            ->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
        return array_values(array_intersect($dir, $scope, $visible));
    }

    private function directorTemplate(Request $request, int $id): ?EDocumentTemplate
    {
        return EDocumentTemplate::whereIn('centre_id', $this->directorCentreIds($request) ?: [0])->find($id);
    }

    private function directorCentreId(Request $request): ?int
    {
        $want = (int) $request->input('centre_id');
        if ($want && in_array($want, $this->directorCentreIds($request), true)) return $want;

        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) return null;
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if ($scope === null) return $centreId;
        $dir = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $request->user()->id)->where('role', 'centre_director')->where('active', true)
            ->whereNotNull('centre_id')->orderBy('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
        $dir = array_values(array_intersect($dir, $scope));
        if (in_array($centreId, $dir, true)) return $centreId;
        return $dir[0] ?? null;
    }

    public function index(Request $request): JsonResponse
    {
        $centreIds = $this->directorCentreIds($request);
        if (! $centreIds) return response()->json(['templates' => []]);

        $rows = EDocumentTemplate::query()
            ->whereIn('centre_id', $centreIds)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $uploaders = DB::table('users')
            ->whereIn('id', $rows->pluck('uploaded_by_id')->unique())
            ->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id');

        // For each template, count signatures and total families at ITS centre.
        $totalFamilies = DB::table('families')
            ->whereIn('centre_id', $centreIds)
            ->whereNull('deleted_at')
            ->groupBy('centre_id')
            ->pluck(DB::raw('COUNT(*)'), 'centre_id');

        $sigCounts = DB::table('edocument_signatures')
            ->select('template_id', DB::raw('COUNT(DISTINCT family_id) as families_signed'))
            ->whereIn('template_id', $rows->pluck('id'))
            ->groupBy('template_id')
            ->pluck('families_signed', 'template_id');

        $out = $rows->map(function ($r) use ($uploaders, $sigCounts, $totalFamilies) {
            $arr = $r->toArray();
            $arr['uploaded_by_name'] = $uploaders[$r->uploaded_by_id] ?? null;
            $arr['families_signed']  = (int) ($sigCounts[$r->id] ?? 0);
            $arr['families_total']   = (int) ($totalFamilies[$r->centre_id] ?? 0);
            return $arr;
        });

        return response()->json(['templates' => $out]);
    }

    public function store(Request $request): JsonResponse
    {
        $centreId = $this->directorCentreId($request);
        if (! $centreId) return response()->json(['message' => 'No centre access'], 403);

        $request->validate([
            'name'        => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'audience'    => ['nullable', 'in:all_families,opt_in'],
            'required'    => ['nullable', 'boolean'],
            'pdf'         => ['required', 'file', 'mimes:pdf', 'max:' . (int) (self::MAX_BYTES / 1024)],
        ]);

        $file = $request->file('pdf');
        if ($file->getMimeType() !== 'application/pdf') {
            return response()->json(['message' => 'PDF files only'], 422);
        }

        $filename = Str::uuid()->toString() . '.pdf';
        $relPath  = self::PRIVATE_DIR . '/' . $centreId . '/' . $filename;
        $file->storeAs(self::PRIVATE_DIR . '/' . $centreId, $filename, 'local');

        $template = EDocumentTemplate::create([
            'centre_id'         => $centreId,
            'name'              => $request->input('name'),
            'description'       => $request->input('description'),
            'storage_path'      => $relPath,
            'original_filename' => $file->getClientOriginalName(),
            'size_bytes'        => $file->getSize(),
            'audience'          => $request->input('audience', 'all_families'),
            'required'          => (bool) $request->input('required', true),
            'status'            => 'active',
            'uploaded_by_id'    => $request->user()->id,
        ]);

        return response()->json(['template' => $template], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = $this->directorTemplate($request, $id);
        if (! $row) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'audience'    => ['sometimes', 'in:all_families,opt_in'],
            'required'    => ['sometimes', 'boolean'],
            'status'      => ['sometimes', 'in:active,archived'],
        ]);
        $row->update($data);
        return response()->json(['template' => $row->fresh()]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $row = $this->directorTemplate($request, $id);
        if (! $row) return response()->json(['message' => 'Not found'], 404);
        $row->update(['status' => 'archived']);
        return response()->json(['template' => $row->fresh()]);
    }

    public function signatures(Request $request, int $id): JsonResponse
    {
        $template = $this->directorTemplate($request, $id);
        if (! $template) return response()->json(['message' => 'Not found'], 404);
        $centreId = (int) $template->centre_id;

        $sigs = EDocumentSignature::query()
            ->where('template_id', $id)
            ->orderByDesc('signed_at')
            ->limit(500)
            ->get();

        $userIds   = $sigs->pluck('signed_by_user_id')->unique();
        $familyIds = $sigs->pluck('family_id')->unique();
        $users     = DB::table('users')->whereIn('id', $userIds)
                     ->select('id', 'first_name', 'last_name', 'email')->get()->keyBy('id');
        $families  = DB::table('families')->whereIn('id', $familyIds)
                     ->select('id', 'family_name')->get()->keyBy('id');

        // Also surface which families have NOT yet signed (gap analysis for director).
        $signedFamilyIds = $sigs->pluck('family_id')->unique()->toArray();
        $missing = DB::table('families')
            ->where('centre_id', $centreId)
            ->whereNull('deleted_at')
            ->whereNotIn('id', $signedFamilyIds ?: [0])
            ->select('id', 'family_name', 'primary_email')
            ->get();

        $tz = \App\Support\AgencyTime::tzForCentre($centreId);
        $out = $sigs->map(function ($s) use ($users, $families, $tz) {
            $u = $users[$s->signed_by_user_id] ?? null;
            $f = $families[$s->family_id] ?? null;
            return [
                'id'             => $s->id,
                'family_id'      => $s->family_id,
                'family_name'    => $f->family_name ?? '?',
                'signed_by_id'   => $s->signed_by_user_id,
                'signed_by_name' => $u ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) : '?',
                'signed_by_email'=> $u->email ?? null,
                'typed_name'     => $s->typed_name,
                'signed_at'      => $s->signed_at,
                'ip_address'     => $s->ip_address,
                // Signature stamp (2026-11-27): signed date + time, IP and approximate place, for this signature record.
                'stamp'          => \App\Support\SignatureStamp::lines($s->signed_at, $s->ip_address, $s->signer_location ?? null, null, true, $tz) ?: null,
            ];
        });

        return response()->json([
            'signatures' => $out,
            'missing_families' => $missing,
            'template' => $template,
        ]);
    }

    public function directorDownload(Request $request, int $id)
    {
        $row = $this->directorTemplate($request, $id);
        if (! $row) return response()->json(['message' => 'Not found'], 404);
        return $this->streamPdf($row);
    }

    // ----- Parent side -----

    public function parentIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        /* Live families only (2026-11-05 leak sweep): a closed family's documents still listed. */
        $familyIds = DB::table('guardians')
            ->join('families', 'families.id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $user->id)
            ->whereNull('families.deleted_at')
            ->pluck('guardians.family_id')->unique();
        if ($familyIds->isEmpty()) {
            return response()->json(['templates' => []]);
        }

        // Resolve centre(s) for these families
        $centreIds = DB::table('families')
            ->whereIn('id', $familyIds)
            ->pluck('centre_id')->unique();

        $templates = EDocumentTemplate::query()
            ->whereIn('centre_id', $centreIds)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        $signed = DB::table('edocument_signatures')
            ->whereIn('family_id', $familyIds)
            ->whereIn('template_id', $templates->pluck('id'))
            ->select('template_id', 'family_id', 'signed_at', 'signed_by_user_id')
            ->get()
            ->keyBy(function ($row) { return $row->template_id . ':' . $row->family_id; });

        $out = [];
        foreach ($templates as $t) {
            foreach ($familyIds as $fid) {
                $key = $t->id . ':' . $fid;
                $sig = $signed[$key] ?? null;
                $out[] = [
                    'id'           => $t->id,
                    'family_id'    => $fid,
                    'name'         => $t->name,
                    'description'  => $t->description,
                    'required'     => (bool) $t->required,
                    'audience'     => $t->audience,
                    'created_at'   => $t->created_at,
                    'signed'       => $sig !== null,
                    'signed_at'    => $sig->signed_at ?? null,
                    'signed_by_me' => $sig ? ($sig->signed_by_user_id === $user->id) : false,
                ];
            }
        }
        return response()->json(['templates' => $out]);
    }

    public function sign(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'family_id'      => ['required', 'integer'],
            'signature_data' => ['required', 'string', 'max:500000'], // <=500KB dataURL
            'typed_name'     => ['nullable', 'string', 'max:160'],
            'child_id'       => ['nullable', 'integer'],
        ]);

        // Confirm guardianship of this family
        $isGuardian = DB::table('guardians')
            ->where('user_id', $user->id)
            ->where('family_id', $data['family_id'])
            ->exists();
        if (! $isGuardian) {
            return response()->json(['message' => 'Not a guardian of this family'], 403);
        }

        $template = EDocumentTemplate::where('status', 'active')->whereNull('deleted_at')->find($id);
        if (! $template) return response()->json(['message' => 'Document not available'], 404);

        // Confirm template centre matches family centre
        /* A live family at the document's own centre, and a child of THAT family (2026-11-05 leak sweep): a closed
           family could still sign, and child_id was written unchecked, so a signature could name another family's
           child. Ids compared as integers. */
        $family = DB::table('families')->where('id', $data['family_id'])->whereNull('deleted_at')->first();
        if (! $family || (int) $family->centre_id !== (int) $template->centre_id) {
            return response()->json(['message' => 'Document not for your family'], 403);
        }
        if (! empty($data['child_id']) && ! DB::table('children')->where('id', (int) $data['child_id'])
                ->where('family_id', (int) $family->id)->whereNull('deleted_at')->exists()) {
            return response()->json(['message' => 'That child is not in this family'], 422);
        }

        // Require the signature data to look like an image dataURL
        if (! preg_match('#^data:image/(png|jpe?g);base64,#', $data['signature_data'])) {
            return response()->json(['message' => 'Signature must be a PNG or JPEG data URL'], 422);
        }

        // Idempotent: if (template, family) already signed, return existing
        $existing = EDocumentSignature::where('template_id', $id)
            ->where('family_id', $data['family_id'])->first();
        if ($existing) {
            return response()->json(['signature' => $existing, 'message' => 'Already signed'], 200);
        }

        // Signature stamp (2026-11-27): IP + approximate place, read on the server at signing time.
        $stamp = \App\Support\SignatureStamp::capture($request);
        $sig = EDocumentSignature::create([
            'template_id'       => $id,
            'centre_id'         => $template->centre_id,
            'family_id'         => $data['family_id'],
            'signed_by_user_id' => $user->id,
            'child_id'          => $data['child_id'] ?? null,
            'signature_data'    => $data['signature_data'],
            'typed_name'        => $data['typed_name'] ?? null,
            'signed_at'         => now(),
            'ip_address'        => $stamp['ip'] ?? $request->ip(),
            'signer_location'   => $stamp['location'],
            'user_agent'        => substr((string) $request->userAgent(), 0, 500),
        ]);

        return response()->json(['signature' => $sig], 201);
    }

    public function parentDownload(Request $request, int $id)
    {
        $user = $request->user();
        $template = EDocumentTemplate::whereNull('deleted_at')->find($id);
        if (! $template) return response()->json(['message' => 'Not found'], 404);

        // Confirm user is a guardian of a family at this template's centre.
        $allowed = DB::table('guardians')
            ->join('families', 'families.id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $user->id)
            ->where('families.centre_id', $template->centre_id)
            // A live family only (2026-11-05 leak sweep): a closed family's former parent could still open the document.
            ->whereNull('families.deleted_at')
            ->exists();
        if (! $allowed) return response()->json(['message' => 'Forbidden'], 403);

        return $this->streamPdf($template);
    }

    /** Short-lived signed URL a mobile WebView can open directly (blob: / <a download>
     *  does not work in the Android WebView). */
    public function parentLink(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $template = EDocumentTemplate::whereNull('deleted_at')->find($id);
        if (! $template) return response()->json(['message' => 'Not found'], 404);
        $allowed = DB::table('guardians')
            ->join('families', 'families.id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $user->id)
            ->where('families.centre_id', $template->centre_id)
            // A live family only (2026-11-05 leak sweep): a closed family's former parent could still open the document.
            ->whereNull('families.deleted_at')
            ->exists();
        if (! $allowed) return response()->json(['message' => 'Forbidden'], 403);
        return response()->json([
            'url' => URL::temporarySignedRoute('edoc.signed', now()->addMinutes(15), ['id' => $id, 'u' => $user->id]),
        ]);
    }

    /** Session-less signed download — serves the PDF INLINE so the browser/WebView
     *  renders it. Access re-checked from the signed `u` param. */
    public function signedStream(Request $request, int $id)
    {
        $uid = (int) $request->query('u');
        $template = EDocumentTemplate::whereNull('deleted_at')->find($id);
        if (! $template || ! $uid) abort(404);
        $allowed = DB::table('guardians')
            ->join('families', 'families.id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $uid)
            ->where('families.centre_id', $template->centre_id)
            // A live family only (2026-11-05 leak sweep): a closed family's former parent could still open the document.
            ->whereNull('families.deleted_at')
            ->exists();
        if (! $allowed) abort(403);
        if (! Storage::disk('local')->exists($template->storage_path)) abort(410);
        // FileResponse, not Storage::response(): that streams with fpassthru(), which this server's PHP disables (ticket #197, 2026-11-01).
        return \App\Support\FileResponse::download(
            $template->storage_path,
            $template->original_filename ?: 'document.pdf',
            ['Content-Type' => 'application/pdf'],
            'local',
            true
        );
    }

    private function streamPdf(EDocumentTemplate $row)
    {
        if (! Storage::disk('local')->exists($row->storage_path)) {
            return response()->json(['message' => 'File missing on storage'], 410);
        }
        return \App\Support\FileResponse::download(
            $row->storage_path,
            $row->original_filename ?: 'document.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }
}
