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
                'd.id', 'd.scope_id', 'd.title', 'd.notes', 'd.file_url',
                'd.file_type', 'd.file_size',
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

        /* WHICH DOSES THIS RECORD ACCOUNTED FOR.
           The link is proof_document_url: a dose recorded from a filed card carries that
           card's path, so the two halves of the same action stay joined without a new
           table. Everyone who can see the record sees what was read off it — that is what
           makes the educator's and the parent's view the same view as the director's. */
        $covered = DB::table('immunizations')
            ->whereIn('child_id', $childIds)
            ->whereIn('proof_document_url', $docs->pluck('file_url')->filter()->unique()->all() ?: [''])
            ->get(['proof_document_url', 'vaccine', 'dose_label', 'administered_on'])
            ->groupBy('proof_document_url');

        /* WHAT THE UPLOADER SAID IT SHOWS, which is a different question from what was
           read off it above. A card with a claim and no doses is one nobody has
           transcribed yet — the single most useful thing to know when looking at a pile
           of them. */
        $claims = DB::table('immunization_record_claims')
            ->whereIn('document_id', $docs->pluck('id')->all() ?: [0])
            ->orderBy('vaccine')
            ->get(['document_id', 'vaccine', 'dose_label', 'administered_on', 'confirmed_at'])
            ->groupBy('document_id');

        return $docs->map(fn ($d) => [
            'id' => (int) $d->id,
            'child_id' => (int) $d->scope_id,
            'child_name' => trim((($d->ch_pref ?: $d->ch_first) . ' ' . $d->ch_last)),
            'title' => $d->title,
            'notes' => $d->notes,
            /* A URL THE DEVICE'S OWN BROWSER CAN OPEN.
               Printing from inside the APK is impossible — neither web view implements
               window.print() — so the only way to put this on paper is to hand it to the
               real browser, and that browser has no session. ProtectedMedia::sign() is
               the portal's answer to exactly that: signature-carrying, bearer-free, dead
               when it expires. Same mechanism every <img src> in the portal already uses,
               and stableExpiry() keeps it cacheable. */
            'print_url' => \App\Support\ProtectedMedia::sign($d->file_url),
            /* Named `covers_claimed` and never `doses`: the two must not be mistaken for
               each other by any reader, here or on screen. */
            'covers_claimed' => collect($claims->get($d->id, []))->map(fn ($r) => [
                'vaccine' => $r->vaccine,
                'dose_label' => $r->dose_label,
                'administered_on' => $r->administered_on,
                'confirmed' => (bool) $r->confirmed_at,
            ])->values()->all(),
            'doses' => collect($covered->get($d->file_url, []))->map(fn ($r) => [
                'vaccine' => $r->vaccine,
                'dose_label' => $r->dose_label,
                'administered_on' => $r->administered_on,
            ])->values()->all(),
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
            'notes' => ['nullable', 'string', 'max:2000'],
            /* A JSON STRING, not an array, because this request is multipart/form-data —
               a file cannot travel in a JSON body, so the structured half has to be
               carried as a field. Shape: [{vaccine, dose_label, administered_on?}, …] */
            'doses' => ['nullable', 'string', 'max:20000'],

            /* WHAT THE UPLOADER SAYS THE CARD SHOWS. Same shape as `doses` and a
               completely different meaning: `doses` is the centre RECORDING a dose,
               this is anyone SAYING what they think is on the page. A parent may send
               it; it writes no dose, clears no flag, and counts towards no compliance
               figure until somebody at the centre confirms it. */
            'covers' => ['nullable', 'string', 'max:20000'],
        ]);

        /* READING A CARD IS A CLINICAL JUDGEMENT, AND IT IS THE CENTRE'S TO MAKE.

           A parent may hand the record over — that is the whole point of this endpoint —
           but deciding that the smudged line on it means "DTaP-IPV-Hib, 2nd dose" is the
           act that clears a compliance flag, and a family must not be able to clear their
           own. So the file is accepted from anyone who may reach the child, and the doses
           only from staff. Asked of an ACTIVE role assignment that reaches this child, and
           it fails closed — never of a role STRING on the user, which is how four
           guardian checks flipped at once once before. */
        $canRecord = $this->mayRecordDoses((int) $request->user()->id, $childId);

        $doses = [];
        foreach ((array) json_decode((string) ($data['doses'] ?? ''), true) as $d) {
            if (! is_array($d)) {
                continue;
            }
            $vaccine = trim((string) ($d['vaccine'] ?? ''));
            if ($vaccine === '') {
                continue;
            }
            $on = trim((string) ($d['administered_on'] ?? ''));
            $doses[] = [
                'vaccine' => mb_substr($vaccine, 0, 100),
                'dose_label' => mb_substr(trim((string) ($d['dose_label'] ?? '')), 0, 40) ?: null,
                // A blank date is honest when the card is unclear; it still records the dose.
                'administered_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) ? $on : null,
            ];
        }
        if ($doses && ! $canRecord) {
            return response()->json([
                'message' => 'Only the centre can record which doses a record covers.',
            ], 403);
        }

        /* THE CLAIM. Parsed exactly like a dose and stored somewhere else entirely, so
           there is no path by which ticking a box on a phone becomes a recorded
           immunisation. Capped so a crafted request cannot write an unbounded number of
           rows against one upload. */
        $covers = [];
        foreach ((array) json_decode((string) ($data['covers'] ?? ''), true) as $c) {
            if (! is_array($c) || count($covers) >= 60) {
                continue;
            }
            $vaccine = trim((string) ($c['vaccine'] ?? ''));
            if ($vaccine === '') {
                continue;
            }
            $on = trim((string) ($c['administered_on'] ?? ''));
            $covers[] = [
                'vaccine' => mb_substr($vaccine, 0, 100),
                'dose_label' => mb_substr(trim((string) ($c['dose_label'] ?? '')), 0, 40) ?: null,
                'administered_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) ? $on : null,
            ];
        }

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
            'notes' => ($n = trim((string) ($data['notes'] ?? ''))) !== '' ? $n : null,
            'file_url' => $publicPath,
            'file_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'uploaded_by_id' => $request->user()->id,
            'created_at' => now(),
        ]);

        /* THE TICKLIST, FILED AGAINST THE CARD. Written after the document so the claim
           can never point at nothing, and ignored quietly on failure: a parent who has
           just handed over their child's record must not be shown an error because the
           optional half of it did not save. The document is the thing that mattered. */
        if ($covers) {
            try {
                $agencyId = (int) DB::table('families as f')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('f.id', $child->family_id)->value('c.agency_id');
                $rows = [];
                foreach ($covers as $c) {
                    $rows[] = $c + [
                        'document_id' => $docId,
                        'child_id' => $childId,
                        'agency_id' => $agencyId ?: null,
                        'claimed_by_id' => (int) $request->user()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                /* insertOrIgnore, not insert: the unique key is (document, vaccine, dose)
                   and a double-submitted form must not fail the upload it belongs to. */
                DB::table('immunization_record_claims')->insertOrIgnore($rows);
            } catch (\Throwable $e) {
                Log::warning('immunization claim not saved', [
                    'document' => $docId, 'child' => $childId, 'err' => $e->getMessage(),
                ]);
            }
        }

        $childName = trim(($child->preferred_name ?: $child->first_name) . ' ' . $child->last_name);

        /* The doses, against the same document, in the same action.
           Filing the card and recording what it says used to be two jobs on two screens,
           which is why records sat on file for weeks with the child still showing overdue.
           An already-recorded dose is SKIPPED rather than duplicated — re-filing a clearer
           photo of the same card is a normal thing to do, and it must not double the
           history. */
        $already = DB::table('immunizations')->where('child_id', $childId)
            ->get(['vaccine', 'dose_label'])
            ->map(fn ($r) => mb_strtolower(trim($r->vaccine . '|' . $r->dose_label)))
            ->flip();

        $recorded = [];
        $skipped = [];
        foreach ($doses as $d) {
            $key = mb_strtolower(trim($d['vaccine'] . '|' . $d['dose_label']));
            $label = trim($d['vaccine'] . ' ' . ($d['dose_label'] ?? ''));
            if ($already->has($key)) {
                $skipped[] = $label;
                continue;
            }
            try {
                DB::table('immunizations')->insert([
                    'child_id' => $childId,
                    'vaccine' => $d['vaccine'],
                    'dose_label' => $d['dose_label'],
                    'administered_on' => $d['administered_on'],
                    // What this dose was read off. Joins the record to the row it produced.
                    'proof_document_url' => $publicPath,
                    'recorded_by_id' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $already->put($key, true);
                $recorded[] = $label;
            } catch (\Throwable $e) {
                Log::warning('Immunization dose insert failed', [
                    'child' => $childId, 'dose' => $label, 'e' => $e->getMessage(),
                ]);
            }
        }

        $note = trim((string) ($data['notes'] ?? ''));
        $byParent = ! $canRecord;
        $this->alertTeam($childId, $childName, (int) $request->user()->id, $docId, $recorded, $byParent);
        $this->emailOffice($childId, $childName, $request->user(), $docId, $title, $recorded, $skipped, $note);

        /* Audit granularly: WHICH child, WHICH document, and NAMING every dose — not a
           count. "3 doses recorded" cannot answer "was the 2nd DTaP entered from that
           card?", which is the only question anyone ever asks of this log. */
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
                    'uploaded_by' => $byParent ? 'parent' : 'staff',
                    'doses_recorded' => $recorded,
                    'doses_already_on_file' => $skipped,
                    'notes' => $note !== '' ? $note : null,
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Immunization upload audit failed', ['child' => $childId, 'e' => $e->getMessage()]);
        }

        return response()->json([
            'id' => $docId,
            'recorded' => $recorded,
            'skipped' => $skipped,
            'message' => $byParent
                ? 'Immunization record received — your child\'s educator and centre have been notified.'
                : ($recorded
                    ? 'Record filed and ' . count($recorded) . ' dose' . (count($recorded) === 1 ? '' : 's') . ' recorded.'
                    : 'Record filed.'),
        ]);
    }

    /**
     * May this person record what a card says, as opposed to merely handing it over?
     *
     * An ACTIVE staff role assignment that reaches the child — through one of the centres
     * where the child has an open enrolment, or through the agency above it. Platform
     * admins hold no tenant role by design and are allowed through explicitly rather than
     * by accident. Anything unrecognised is a no.
     */
    /**
     * FILL IN A RECORD THAT ARRIVED BARE.
     *
     * The common case this exists for: a parent photographs the card and sends it
     * without ticking anything, because reading a smudged line is not their job. The
     * document is then on file saying only that a card arrived, and the compliance
     * picture still shows nothing. Somebody at the centre has to read it.
     *
     * Until now that meant filing a SECOND copy of the same card through store() just
     * to attach doses to it, which is how a child ends up with three identical
     * photographs on their record. This writes the doses against the document that is
     * already there.
     *
     * Staff only, by the same test that guards doses on upload: deciding what a card
     * shows is a clinical judgement and it is the centre's to make. A guardian gets the
     * same 403 here as they would there.
     */
    public function details(Request $request, int $childId, int $docId): JsonResponse
    {
        $this->assertChild((int) $request->user()->id, $childId);

        if (! $this->mayRecordDoses((int) $request->user()->id, $childId)) {
            return response()->json([
                'message' => 'Only the centre can record which doses a record covers.',
            ], 403);
        }

        /* AN EXEMPTION FORM IS A RECORD TOO (2026-09-24).

           Anthony: "when the exempt form gets uploaded there should be the buttons to
           exempt with each of the doses that is required based on this."

           A filed document answers one of two questions - which doses were GIVEN, or
           which doses this child is EXEMPT from - and until now this endpoint could
           only hear the first. An exemption form had to be uploaded, then every dose
           it covered exempted one at a time from the schedule, with nothing tying the
           exemptions back to the form they came from.

           So `exempt` rides on the same payload. The ticks mean the same thing either
           way - "this document covers these doses" - and the document is written onto
           every row as its proof, which is what makes an exemption auditable: the
           reason says what was claimed, proof_document_url says what was produced. */
        $data = $request->validate([
            'doses' => ['required', 'array', 'min:1', 'max:60'],
            'doses.*.vaccine' => ['required', 'string', 'max:100'],
            'doses.*.dose_label' => ['nullable', 'string', 'max:40'],
            'doses.*.administered_on' => ['nullable', 'date_format:Y-m-d'],
            'exempt' => ['nullable', 'boolean'],
            'exemption_reason' => ['nullable', 'string', 'max:200'],
        ]);

        $asExemption = (bool) ($data['exempt'] ?? false);
        $reason = trim((string) ($data['exemption_reason'] ?? ''));

        /* A reason is what makes it a record. Without one an exemption is a missing
           dose wearing a better label, and it would silence the reminder with nothing
           on file to justify it. Same rule as the single-dose route. */
        if ($asExemption && $reason === '') {
            return response()->json([
                'message' => 'Give the reason for the exemption - it is what makes it a record.',
                'errors' => ['exemption_reason' => ['A reason is required.']],
            ], 422);
        }

        /* The document has to belong to THIS child and be an immunization record.
           Without this a valid doc id from another child would attach doses to the
           wrong file - the id is in the URL and the URL is user input. */
        $doc = DB::table('documents')
            ->where('id', $docId)
            ->where('scope_type', 'child')
            ->where('scope_id', $childId)
            ->where('category', self::CATEGORY)
            ->first(['id', 'file_url']);
        if (! $doc) {
            return response()->json(['message' => 'That record was not found for this child.'], 404);
        }

        /* Same de-duplication as the upload path: a dose already on file is reported
           back as skipped rather than written twice. */
        $already = DB::table('immunizations')->where('child_id', $childId)
            ->get(['vaccine', 'dose_label'])
            ->mapWithKeys(fn ($r) => [mb_strtolower(trim($r->vaccine . '|' . $r->dose_label)) => true]);

        $recorded = [];
        $skipped = [];
        foreach ($data['doses'] as $d) {
            $vaccine = trim((string) $d['vaccine']);
            if ($vaccine === '') {
                continue;
            }
            $doseLabel = trim((string) ($d['dose_label'] ?? '')) ?: null;
            $on = $d['administered_on'] ?? null;
            $key = mb_strtolower(trim($vaccine . '|' . $doseLabel));
            $label = trim($vaccine . ' ' . (string) $doseLabel);

            /* THE EXEMPTION BRANCH. Handed to the shared writer, which owns the one
               rule that matters here: a dose already carrying a DATE is left alone and
               reported back, because an exemption form arriving later does not unsay a
               dose somebody read off a card. The document travels with it as proof. */
            if ($asExemption) {
                $outcome = \App\Support\ImmunizationExemption::set(
                    $childId, $vaccine, $doseLabel, $reason,
                    (int) $request->user()->id, $doc->file_url
                );
                if ($outcome === \App\Support\ImmunizationExemption::SKIPPED_GIVEN) {
                    $skipped[] = $label . ' (already recorded as given)';
                } else {
                    $recorded[] = $label;
                }
                continue;
            }

            if ($already->has($key)) {
                $skipped[] = $label;
                continue;
            }
            try {
                $immId = DB::table('immunizations')->insertGetId([
                    'child_id' => $childId,
                    'vaccine' => $vaccine,
                    'dose_label' => $doseLabel,
                    'administered_on' => $on,
                    // Joins the dose to the card it was read off, exactly as store() does.
                    'proof_document_url' => $doc->file_url,
                    'recorded_by_id' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $already->put($key, true);
                $recorded[] = $label;

                /* CLOSE THE MATCHING CLAIM. If the uploader ticked this one, the tick was
                   a question and this is the answer - leaving it open would show the
                   record as still needing a look forever. */
                DB::table('immunization_record_claims')
                    ->where('document_id', $docId)
                    ->whereRaw('LOWER(vaccine) = ?', [mb_strtolower($vaccine)])
                    ->where(function ($q) use ($doseLabel) {
                        $doseLabel === null
                            ? $q->whereNull('dose_label')
                            : $q->whereRaw('LOWER(dose_label) = ?', [mb_strtolower($doseLabel)]);
                    })
                    ->update([
                        'confirmed_at' => now(),
                        'confirmed_by_id' => $request->user()->id,
                        'immunization_id' => $immId,
                        'updated_at' => now(),
                    ]);
            } catch (\Throwable $e) {
                Log::warning('Immunization detail insert failed', [
                    'child' => $childId, 'document' => $docId, 'dose' => $label, 'e' => $e->getMessage(),
                ]);
            }
        }

        /* Granular, naming every dose - a count cannot answer "was the 2nd DTaP entered
           from that card", which is the only question ever asked of this log. */
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'agency_id' => $this->agencyOfChild($childId),
                'action' => $asExemption
                    ? 'child.immunization_exemptions_recorded'
                    : 'child.immunization_details_recorded',
                'entity_type' => 'child',
                'entity_id' => $childId,
                'payload' => json_encode(array_filter([
                    'document_id' => $docId,
                    'doses_recorded' => $recorded,
                    'doses_already_on_file' => $skipped,
                    'exemption_reason' => $asExemption ? $reason : null,
                    /* Names every dose, not a count: "was the 2nd DTaP exempted from
                       that form" is the only question ever asked of this row. */
                    'summary' => $asExemption
                        ? (count($recorded) . ' dose(s) recorded EXEMPT from the filed form - '
                            . implode(', ', $recorded) . ' - reason: ' . $reason)
                        : null,
                ], fn ($v) => $v !== null && $v !== [])),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Immunization details audit failed', ['child' => $childId, 'e' => $e->getMessage()]);
        }

        return response()->json([
            'id' => $docId,
            'recorded' => $recorded,
            'skipped' => $skipped,
            'message' => $recorded
                ? (count($recorded) . ' dose' . (count($recorded) === 1 ? '' : 's') . ' recorded.')
                : 'Nothing new to record - those doses were already on file.',
        ]);
    }

    private function mayRecordDoses(int $userId, int $childId): bool
    {
        try {
            if (DB::table('role_assignments')->where('user_id', $userId)
                ->where('role', 'platform_admin')->where('active', 1)->exists()) {
                return true;
            }

            $centreIds = DB::table('enrollments as e')
                ->join('rooms as r', 'r.id', '=', 'e.room_id')
                ->where('e.child_id', $childId)->whereNull('e.end_date')
                ->distinct()->pluck('r.centre_id')->filter()->all();
            $agencyIds = $centreIds
                ? DB::table('centres')->whereIn('id', $centreIds)->pluck('agency_id')->filter()->unique()->all()
                : [];
            if (! $centreIds && ! $agencyIds) {
                return false;
            }

            return DB::table('role_assignments')
                ->where('user_id', $userId)->where('active', 1)
                ->where(function ($q) use ($centreIds, $agencyIds) {
                    if ($centreIds) {
                        $q->orWhere(function ($x) use ($centreIds) {
                            $x->whereIn('role', ['educator', 'centre_director', 'home_visitor'])
                                ->whereIn('centre_id', $centreIds);
                        });
                    }
                    if ($agencyIds) {
                        $q->orWhere(function ($x) use ($agencyIds) {
                            $x->whereIn('role', ['agency_admin', 'centre_director'])
                                ->whereIn('agency_id', $agencyIds);
                        });
                    }
                })
                ->exists();
        } catch (\Throwable $e) {
            // Fail CLOSED. An error here must never hand out the right to clear a
            // compliance flag.
            return false;
        }
    }

    /**
     * Tell the office, by email, that a record was filed for a named child.
     *
     * The in-app notification alertTeam() writes reaches whoever happens to open the
     * portal; an immunization record is a compliance artefact, and the person who has to
     * answer for it is usually not the person who filed it. Modelled on the attendance
     * correction notice — same recipients, same shape — so the office learns one format.
     */
    private function emailOffice(
        int $childId,
        string $childName,
        $actor,
        int $docId,
        string $title,
        array $recorded,
        array $skipped,
        string $note
    ): void {
        try {
            $agencyId = $this->agencyOfChild($childId);
            if (! $agencyId) {
                return;
            }
            $centreIds = DB::table('enrollments as e')
                ->join('rooms as r', 'r.id', '=', 'e.room_id')
                ->where('e.child_id', $childId)->whereNull('e.end_date')
                ->distinct()->pluck('r.centre_id')->filter();

            $actorId = (int) $actor->id;
            $to = DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', 1)
                ->where(function ($q) use ($agencyId, $centreIds) {
                    $q->where(function ($x) use ($agencyId) {
                        $x->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId);
                    });
                    if ($centreIds->isNotEmpty()) {
                        $q->orWhere(function ($x) use ($centreIds) {
                            $x->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $centreIds);
                        });
                    }
                })
                ->where('u.id', '!=', $actorId)
                ->whereNull('u.deleted_at')->whereNotNull('u.email')
                ->distinct()->pluck('u.email')->filter()->unique()->values()->all();
            if (! $to) {
                return;
            }

            $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: 'Someone';

            /* What is still outstanding AFTER this — the one number the office actually
               wants, and the difference between "handled" and "handled, keep chasing". */
            $outstanding = -1;   // -1 means "could not work it out", and says nothing
            try {
                $sched = DB::table('immunization_schedule')->where('agency_id', $agencyId)
                    ->where('active', 1)->count();
                $done = DB::table('immunizations')->where('child_id', $childId)->count();
                $outstanding = max(0, $sched - $done);
            } catch (\Throwable $e) {
                $outstanding = -1;
            }

            $list = fn (array $xs) => '<ul style="margin:0 0 14px;padding-left:18px;color:#0F172A;">'
                . implode('', array_map(fn ($x) => '<li style="margin:2px 0;">' . e($x) . '</li>', $xs))
                . '</ul>';

            $body = '<p style="margin:0 0 14px;"><strong>' . e($actorName) . '</strong> filed an '
                . 'immunization record for <strong>' . e($childName) . '</strong>.</p>'
                . ($recorded
                    ? '<p style="margin:0 0 6px;font-weight:700;color:#166534;">Recorded from it ('
                        . count($recorded) . '):</p>' . $list($recorded)
                    : '<p style="margin:0 0 14px;color:#92400E;">No doses were recorded from it yet — '
                        . 'the record is on file and still needs reading.</p>')
                . ($skipped
                    ? '<p style="margin:0 0 6px;font-weight:700;color:#475569;">Already on file, left alone:</p>'
                        . $list($skipped)
                    : '')
                . ($note !== ''
                    ? '<p style="margin:0 0 6px;font-weight:700;color:#475569;">Note:</p>'
                        . '<p style="margin:0 0 14px;color:#0F172A;white-space:pre-wrap;">' . e($note) . '</p>'
                    : '')
                . ($outstanding === 0
                    ? '<p style="margin:0 0 14px;color:#166534;font-weight:700;">'
                        . 'Nothing outstanding for this child — the immunization item is complete.</p>'
                    : ($outstanding > 0
                        ? '<p style="margin:0 0 14px;color:#475569;">' . $outstanding . ' dose'
                            . ($outstanding === 1 ? '' : 's') . ' on the schedule are still unrecorded.</p>'
                        : ''))
                . '<p style="margin:0 0 14px;font-size:13px;color:#475569;">Filed '
                . e(now()->setTimezone(\App\Support\AgencyTime::tz($agencyId))->format('j M Y \a\t g:i A'))
                . '. The record is on the child\'s Immunization tab.</p>'
                . '<p style="margin:0;font-size:12.5px;color:#64748B;">Immunization records are compliance '
                . 'evidence, so who filed one and what was read off it is recorded in the audit log.</p>';

            $subject = 'Immunization record filed — ' . $childName;

            $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => 'Immunization',
                'title' => $subject,
                'preheader' => $actorName . ' filed an immunization record for ' . $childName,
            ]);

            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $subject, $agencyId) {
                    $m->to($to[0])->subject($subject);
                    if (count($to) > 1) {
                        $m->bcc(array_slice($to, 1));
                    }
                    try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId); }
                    catch (\Throwable $e) {}
                });
        } catch (\Throwable $e) {
            Log::warning('Immunization filed notice failed', ['child' => $childId, 'e' => $e->getMessage()]);
        }
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
    private function alertTeam(
        int $childId,
        string $childName,
        int $uploaderId,
        int $docId,
        array $recorded = [],
        bool $byParent = true
    ): void {
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
                'body' => ($byParent ? 'A parent' : 'The centre') . ' filed an immunization record'
                    . ($recorded
                        ? ', and ' . count($recorded) . ' dose' . (count($recorded) === 1 ? '' : 's')
                            . ' were recorded from it: ' . implode(', ', $recorded) . '.'
                        : '. No doses have been recorded from it yet.')
                    . ' It is on the child\'s Immunization tab.',
                'data' => json_encode([
                    'child_id' => $childId,
                    'document_id' => $docId,
                    'doses_recorded' => $recorded,
                    'hash' => 'child-detail?id=' . $childId . '&tab=immunization',
                ]),
                'created_at' => $now,
            ])->all();

            \App\Support\Notify::write($rows);
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
