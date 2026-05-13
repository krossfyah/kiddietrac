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

    public function index(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) return response()->json(['templates' => []]);

        $rows = EDocumentTemplate::query()
            ->where('centre_id', $centreId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $uploaders = DB::table('users')
            ->whereIn('id', $rows->pluck('uploaded_by_id')->unique())
            ->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id');

        // For each template, count signatures and total families at centre.
        $totalFamilies = DB::table('families')
            ->where('centre_id', $centreId)
            ->whereNull('deleted_at')
            ->count();

        $sigCounts = DB::table('edocument_signatures')
            ->select('template_id', DB::raw('COUNT(DISTINCT family_id) as families_signed'))
            ->whereIn('template_id', $rows->pluck('id'))
            ->groupBy('template_id')
            ->pluck('families_signed', 'template_id');

        $out = $rows->map(function ($r) use ($uploaders, $sigCounts, $totalFamilies) {
            $arr = $r->toArray();
            $arr['uploaded_by_name'] = $uploaders[$r->uploaded_by_id] ?? null;
            $arr['families_signed']  = (int) ($sigCounts[$r->id] ?? 0);
            $arr['families_total']   = $totalFamilies;
            return $arr;
        });

        return response()->json(['templates' => $out]);
    }

    public function store(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
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
        $centreId = $this->resolveCentreId($request->user());
        $row = EDocumentTemplate::where('centre_id', $centreId)->find($id);
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
        $centreId = $this->resolveCentreId($request->user());
        $row = EDocumentTemplate::where('centre_id', $centreId)->find($id);
        if (! $row) return response()->json(['message' => 'Not found'], 404);
        $row->update(['status' => 'archived']);
        return response()->json(['template' => $row->fresh()]);
    }

    public function signatures(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $template = EDocumentTemplate::where('centre_id', $centreId)->find($id);
        if (! $template) return response()->json(['message' => 'Not found'], 404);

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

        $out = $sigs->map(function ($s) use ($users, $families) {
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
        $centreId = $this->resolveCentreId($request->user());
        $row = EDocumentTemplate::where('centre_id', $centreId)->find($id);
        if (! $row) return response()->json(['message' => 'Not found'], 404);
        return $this->streamPdf($row);
    }

    // ----- Parent side -----

    public function parentIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $familyIds = DB::table('guardians')
            ->where('user_id', $user->id)
            ->pluck('family_id')->unique();
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
        $family = DB::table('families')->where('id', $data['family_id'])->first();
        if (! $family || $family->centre_id !== $template->centre_id) {
            return response()->json(['message' => 'Document not for your family'], 403);
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

        $sig = EDocumentSignature::create([
            'template_id'       => $id,
            'centre_id'         => $template->centre_id,
            'family_id'         => $data['family_id'],
            'signed_by_user_id' => $user->id,
            'child_id'          => $data['child_id'] ?? null,
            'signature_data'    => $data['signature_data'],
            'typed_name'        => $data['typed_name'] ?? null,
            'signed_at'         => now(),
            'ip_address'        => $request->ip(),
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
            ->exists();
        if (! $allowed) return response()->json(['message' => 'Forbidden'], 403);

        return $this->streamPdf($template);
    }

    private function streamPdf(EDocumentTemplate $row)
    {
        if (! Storage::disk('local')->exists($row->storage_path)) {
            return response()->json(['message' => 'File missing on storage'], 410);
        }
        return Storage::disk('local')->download(
            $row->storage_path,
            $row->original_filename ?: 'document.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }
}
