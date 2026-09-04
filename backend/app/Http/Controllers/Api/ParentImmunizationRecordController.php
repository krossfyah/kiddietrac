<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A parent submitting their child's immunization record.
 *
 * The structured immunizations table (one row per vaccine, entered by staff) is a
 * different thing and stays where it is. What parents have is a document — a photo
 * of the yellow card, a clinic printout, a PDF from the health unit — and until now
 * there was no way for them to hand it over inside the portal at all. It arrived by
 * email or on paper at drop-off, which is precisely how a record ends up on nobody's
 * file.
 *
 * The upload lands in `documents` under the CHILD's scope, so it appears on the
 * child's Documents tab in the director/admin portal with no further work — it is
 * the child's record, not a parallel store. Category 'immunization' is forced here
 * rather than accepted from the request, so this endpoint cannot be used as a
 * general-purpose writer into a child's file.
 *
 * There is deliberately no parent DELETE. A submitted health record is evidence the
 * centre relies on for compliance, and letting the submitter withdraw it silently
 * after the fact is not something a parent should be able to do alone. Staff can
 * remove one through the existing child-document endpoint, which is audited.
 */
class ParentImmunizationRecordController extends Controller
{
    use AuthorizesTenantAccess;

    private const CATEGORY = 'immunization';

    /** GET /parent/children/{child}/immunization-records */
    public function index(Request $request, int $childId): JsonResponse
    {
        $this->assertChild((int) $request->user()->id, $childId);

        return response()->json(['records' => self::recordsFor([$childId])]);
    }

    /**
     * The filed records for a set of children, newest first, each saying who put it
     * there and when.
     *
     * Parent-or-staff is derived rather than stored: the uploader is a parent exactly
     * when they are a guardian of that child. Storing a flag at upload time would have
     * meant two writers keeping one fact in step, and the answer is already in the
     * data — a guardian link is what "parent" means here.
     *
     * @param  list<int>  $childIds
     * @return list<array<string,mixed>>
     */
    public static function recordsFor(array $childIds): array
    {
        if (! $childIds) {
            return [];
        }

        $docs = DB::table('documents as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'd.scope_id')
            ->where('d.scope_type', 'child')
            ->whereIn('d.scope_id', $childIds)
            ->where('d.category', self::CATEGORY)
            ->orderByDesc('d.id')
            ->get([
                'd.id', 'd.scope_id', 'd.title', 'd.file_type', 'd.file_size',
                'd.created_at', 'd.uploaded_by_id',
                'u.first_name as up_first', 'u.last_name as up_last',
                'ch.first_name as ch_first', 'ch.last_name as ch_last', 'ch.preferred_name as ch_pref',
            ]);
        if ($docs->isEmpty()) {
            return [];
        }

        // Which uploaders are guardians of the child they uploaded for?
        $guardianPairs = DB::table('guardians as g')
            ->join('children as c', 'c.family_id', '=', 'g.family_id')
            ->whereIn('c.id', $childIds)
            ->whereIn('g.user_id', $docs->pluck('uploaded_by_id')->filter()->unique()->all() ?: [0])
            ->get(['g.user_id', 'c.id as child_id'])
            ->map(fn ($r) => $r->user_id . ':' . $r->child_id)
            ->flip();

        return $docs->map(fn ($d) => [
            'id' => (int) $d->id,
            'child_id' => (int) $d->scope_id,
            'child_name' => trim((($d->ch_pref ?: $d->ch_first) . ' ' . $d->ch_last)),
            'title' => $d->title,
            'file_type' => $d->file_type,
            'file_size' => (int) $d->file_size,
            'uploaded_at' => $d->created_at,
            'uploaded_by' => trim(($d->up_first ?? '') . ' ' . ($d->up_last ?? '')) ?: null,
            'uploaded_by_parent' => $d->uploaded_by_id
                && $guardianPairs->has($d->uploaded_by_id . ':' . $d->scope_id),
        ])->values()->all();
    }

    /** POST /parent/children/{child}/immunization-records */
    public function store(Request $request, int $childId): JsonResponse
    {
        $this->assertChild((int) $request->user()->id, $childId);

        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validate([
            // Photos are the common case — a parent holding the card up to their phone.
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic', 'max:10240'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) Str::uuid() . '.' . $ext;
        $file->storeAs('child-documents/' . $childId, $name, 'public');
        $publicPath = '/storage/child-documents/' . $childId . '/' . $name;

        $title = trim((string) ($data['title'] ?? '')) ?: 'Immunization record';
        $docId = DB::table('documents')->insertGetId([
            'scope_type' => 'child',
            'scope_id' => $childId,
            'category' => self::CATEGORY,
            'title' => mb_substr($title, 0, 200),
            'file_url' => $publicPath,
            'file_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'uploaded_by_id' => $request->user()->id,
            'created_at' => now(),
        ]);

        $childName = trim(($child->preferred_name ?: $child->first_name) . ' ' . $child->last_name);
        $this->alertTeam($childId, $childName, (int) $request->user()->id, $docId);

        // Audit granularly: WHICH child, WHICH document, and who sent it.
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'agency_id' => $this->agencyOfChild($childId),
                'action' => 'child.immunization_record_uploaded',
                'entity_type' => 'child',
                'entity_id' => $childId,
                'payload' => json_encode([
                    'child_name' => $childName,
                    'document_id' => $docId,
                    'title' => $title,
                    'uploaded_by' => 'parent',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Immunization upload audit failed', ['child' => $childId, 'e' => $e->getMessage()]);
        }

        return response()->json([
            'id' => $docId,
            'message' => 'Immunization record received — your child\'s educator and centre have been notified.',
        ]);
    }

    /**
     * Stream the file back through the API rather than handing out the raw /storage
     * path: the mobile WebView cannot always open a storage link, and this keeps the
     * access check on the request, so a guessed id from another family 403s.
     */
    public function download(Request $request, int $childId, int $docId)
    {
        $this->assertChild((int) $request->user()->id, $childId);

        $doc = DB::table('documents')->where('id', $docId)
            ->where('scope_type', 'child')->where('scope_id', $childId)
            ->where('category', self::CATEGORY)->first();
        if (! $doc) {
            abort(404);
        }

        $rel = ltrim(str_replace('/storage/', '', (string) $doc->file_url), '/');
        $disk = Storage::disk('public');
        if ($rel === '' || ! $disk->exists($rel)) {
            abort(404);
        }

        return response()->file($disk->path($rel));
    }

    /**
     * Tell the people who need to know: the educators and director(s) at the centre(s)
     * where this child has an open enrolment, plus the agency's admins.
     *
     * Scoped by CENTRE because that is how staff are actually assigned — role
     * assignments carry an agency and a centre, not a room — so a narrower room-level
     * alert is not something the data can express today. The parent who uploaded it is
     * excluded; they already know.
     */
    private function alertTeam(int $childId, string $childName, int $uploaderId, int $docId): void
    {
        try {
            $centreIds = DB::table('enrollments as e')
                ->join('rooms as r', 'r.id', '=', 'e.room_id')
                ->where('e.child_id', $childId)->whereNull('e.end_date')
                ->distinct()->pluck('r.centre_id')->filter()->all();

            $agencyIds = $centreIds
                ? DB::table('centres')->whereIn('id', $centreIds)->pluck('agency_id')->filter()->unique()->all()
                : [];

            $recipients = DB::table('role_assignments')
                ->where('active', 1)
                ->where(function ($q) use ($centreIds, $agencyIds) {
                    if ($centreIds) {
                        $q->orWhere(function ($x) use ($centreIds) {
                            $x->whereIn('role', ['educator', 'centre_director'])->whereIn('centre_id', $centreIds);
                        });
                    }
                    if ($agencyIds) {
                        $q->orWhere(function ($x) use ($agencyIds) {
                            $x->whereIn('role', ['agency_admin', 'centre_director'])->whereIn('agency_id', $agencyIds);
                        });
                    }
                })
                ->pluck('user_id')->unique()->reject(fn ($id) => (int) $id === $uploaderId)->values();

            if ($recipients->isEmpty()) {
                return;
            }

            $now = now();
            $rows = $recipients->map(fn ($uid) => [
                'user_id' => (int) $uid,
                'type' => 'immunization_record',
                'title' => '💉 Immunization record for ' . $childName,
                'body' => 'A parent uploaded an immunization record. It is on the child\'s Documents tab.',
                'data' => json_encode([
                    'child_id' => $childId,
                    'document_id' => $docId,
                    'hash' => 'child-detail?id=' . $childId . '&tab=documents',
                ]),
                'created_at' => $now,
            ])->all();

            DB::table('notifications')->insert($rows);
        } catch (\Throwable $e) {
            /* A record that was successfully filed must never fail because a bell
               could not be rung — the document is the thing that matters. */
            Log::warning('Immunization alert failed', ['child' => $childId, 'e' => $e->getMessage()]);
        }
    }

    private function agencyOfChild(int $childId): ?int
    {
        $aid = DB::table('enrollments as e')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('e.child_id', $childId)->whereNull('e.end_date')
            ->value('c.agency_id');
        if ($aid) {
            return (int) $aid;
        }
        $aid = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('ch.id', $childId)->value('c.agency_id');

        return $aid ? (int) $aid : null;
    }
}
