<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Provincial fee subsidies (2026-09-29).
 *
 * Anthony: "rename CWELLCC subsidies to Subsidies and add CWELLCC as a subtab and add
 * provincial subsidy as another subtab", and chose "Manage + reduce invoices".
 *
 * The `subsidies` table has existed since the first schema -- type provincial, a case
 * number, a monthly amount, a date range -- and invoice generation already takes an
 * active subsidy's monthly_amount off that child's tuition (InvoiceController, where it
 * reads ONE row per child with ->first()). Nothing could create a row. This is that
 * missing half, for type = 'provincial'.
 *
 * Two rules follow from how invoices read the table:
 *  - ONE subsidy per child at a time. Invoices take the first active row valid on the
 *    issue date, so an overlapping second row would be silently ignored (or would hide
 *    the first). Overlaps are refused with the dates of the one in the way.
 *  - A mistaken entry is REMOVED (active = 0), never deleted, and ending one sets its
 *    last day, so an invoice can always be traced back to the record that reduced it.
 *
 * Scope: the active agency, by the family's centre (as the CWELCC report). This is money,
 * so a centre director sees and edits only their own centres; agency admins and platform
 * admins see the whole agency.
 */
class SubsidyController extends Controller
{
    private const TYPE = 'provincial';

    /** GET /compliance/subsidies?month=YYYY-MM[&all=1] */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        [$start, $end, $month] = $this->monthRange($request);

        $q = $this->baseQuery($agencyId, $centres)
            ->where('s.type', self::TYPE)
            ->where('s.active', 1)
            ->when($request->filled('family_id'), fn ($w) => $w->where('f.id', (int) $request->query('family_id')));
        if (! $request->boolean('all')) {
            $q->where('s.valid_from', '<=', $end->toDateString())
              ->where(function ($w) use ($start) {
                  $w->whereNull('s.valid_to')->orWhere('s.valid_to', '>=', $start->toDateString());
              });
        }
        $rows = $q->orderBy('ch.last_name')->orderBy('ch.first_name')->orderByDesc('s.valid_from')
            ->get();
        $docs = $this->docsFor($rows->pluck('id')->all());
        $rows = $rows->map(fn ($r) => $this->shape($r, $agencyId, $start, $end, $docs));

        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->when($centres !== null, fn ($w) => $w->whereIn('c.id', $centres))
            ->whereNull('ch.deleted_at')->whereNull('f.deleted_at')
            ->whereExists(function ($e) {
                $e->select(DB::raw(1))->from('enrollments as en')
                  ->whereColumn('en.child_id', 'ch.id')->whereNull('en.end_date');
            })
            ->orderBy('ch.last_name')->orderBy('ch.first_name')
            ->get(['ch.id', 'ch.first_name', 'ch.last_name', 'f.family_name', 'c.name as centre_name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => trim($c->first_name . ' ' . $c->last_name),
                'family_name' => $c->family_name, 'centre_name' => $c->centre_name]);

        return response()->json([
            'data' => $rows,
            'month' => $month,
            'totals' => ['children' => $rows->pluck('child_id')->unique()->count(),
                'monthly' => round((float) $rows->where('in_month', true)->sum('month_amount'), 2)],
            'children' => $children,
        ]);
    }

    /** GET /compliance/subsidies/csv?month=YYYY-MM */
    public function csv(Request $request): StreamedResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        [$start, $end, $month] = $this->monthRange($request);
        $rows = $this->baseQuery($agencyId, $centres)
            ->where('s.type', self::TYPE)->where('s.active', 1)
            ->where('s.valid_from', '<=', $end->toDateString())
            ->where(function ($w) use ($start) {
                $w->whereNull('s.valid_to')->orWhere('s.valid_to', '>=', $start->toDateString());
            })
            ->orderBy('ch.last_name')->get();

        $monthStart = $start->toDateString();

        return response()->streamDownload(function () use ($rows, $monthStart) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Child', 'Family', 'Centre', 'Case number', 'Basis', 'Daily amount', 'Monthly amount',
                'Scheduled days this month', 'Amount this month', 'From', 'To', 'Approved', 'Notes']);
            foreach ($rows as $r) {
                $c = \App\Support\SubsidyAmount::forSubsidyMonth($r, $monthStart);
                $daily = ($r->amount_basis ?? 'monthly') === 'daily';
                fputcsv($out, [trim($r->first_name . ' ' . $r->last_name), $r->family_name, $r->centre_name,
                    $r->case_number, $daily ? 'Daily' : 'Monthly',
                    $daily ? number_format((float) $r->daily_amount, 2, '.', '') : '',
                    $daily ? '' : number_format((float) $r->monthly_amount, 2, '.', ''),
                    $c['days'] ?? '', number_format($c['amount'], 2, '.', ''),
                    $r->valid_from, $r->valid_to, $r->approved_at, $r->notes]);
            }
            fclose($out);
        }, 'Provincial-subsidies-' . $month . '.csv', ['Content-Type' => 'text/csv']);
    }

    /** POST /compliance/subsidies */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        $data = $this->validated($request, true);
        $child = $this->childInScope((int) $data['child_id'], $agencyId, $centres);
        $fam = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('ch.id', $child->id)->first(['f.id', 'f.centre_id']);

        if ($clash = $this->overlap((int) $child->id, $data['valid_from'], $data['valid_to'] ?? null, null)) {
            return $this->overlapError($clash);
        }

        $id = DB::table('subsidies')->insertGetId([
            'child_id' => $child->id,
            'type' => self::TYPE,
            'case_number' => $data['case_number'] ?? null,
            'monthly_amount' => $data['monthly_amount'] ?? 0,
            'amount_basis' => $data['amount_basis'] ?? 'monthly',
            'daily_amount' => $data['daily_amount'] ?? null,
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
            'approved_at' => $data['approved_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'active' => 1,
            // Fixed at creation: the family and centre the subsidy was granted under.
            // Reports and the family record read these, so the history stays with the
            // family even if the child later moves family or is archived.
            'family_id' => $fam->id ?? null,
            'centre_id' => $fam->centre_id ?? null,
            'created_by_id' => (int) $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, $agencyId, 'subsidy.created', $child, null, $this->row($id));

        return response()->json(['data' => $this->shape($this->fetch($id), $agencyId, null, null, $this->docsFor([$id]))], 201);
    }

    /** PATCH /compliance/subsidies/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        [$before, $child] = $this->ownedRow($id, $agencyId, $centres);
        $data = $this->validated($request, false);

        $from = $data['valid_from'] ?? $before->valid_from;
        $to = array_key_exists('valid_to', $data) ? $data['valid_to'] : $before->valid_to;
        if ($to !== null && $to < $from) {
            return response()->json(['message' => 'The last day cannot be before the first day.',
                'errors' => ['valid_to' => ['Before the start date.']]], 422);
        }
        if ($clash = $this->overlap((int) $child->id, $from, $to, $id)) {
            return $this->overlapError($clash);
        }

        $upd = array_intersect_key($data, array_flip(['case_number', 'monthly_amount', 'amount_basis', 'daily_amount', 'valid_from', 'valid_to', 'approved_at', 'notes']));
        if ($upd) {
            DB::table('subsidies')->where('id', $id)->update($upd + ['updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'subsidy.updated', $child, (array) $before, $this->row($id));

        return response()->json(['data' => $this->shape($this->fetch($id), $agencyId, null, null, $this->docsFor([$id]))]);
    }

    /** POST /compliance/subsidies/{id}/end {valid_to} -- the subsidy's last day. */
    public function end(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        [$before, $child] = $this->ownedRow($id, $agencyId, $centres);
        $data = $request->validate(['valid_to' => ['required', 'date']]);
        $to = Carbon::parse($data['valid_to'])->toDateString();
        if ($to < $before->valid_from) {
            return response()->json(['message' => 'The last day cannot be before the subsidy started (' . $before->valid_from . ').',
                'errors' => ['valid_to' => ['Before the start date.']]], 422);
        }
        DB::table('subsidies')->where('id', $id)->update(['valid_to' => $to, 'ended_by_id' => (int) $request->user()->id, 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'subsidy.ended', $child, (array) $before, $this->row($id));

        return response()->json(['data' => $this->shape($this->fetch($id), $agencyId, null, null, $this->docsFor([$id]))]);
    }

    /** DELETE /compliance/subsidies/{id} -- entered by mistake. Kept, marked inactive. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        [$before, $child] = $this->ownedRow($id, $agencyId, $centres);
        DB::table('subsidies')->where('id', $id)->update(['active' => 0, 'removed_at' => now(), 'removed_by_id' => (int) $request->user()->id]);
        $this->audit($request, $agencyId, 'subsidy.removed', $child, (array) $before, null);

        return response()->json(['removed' => true]);
    }

    /**
     * GET /compliance/families/{id}/subsidies -- the family record's Subsidies section:
     * every CWELCC enrolment period and every provincial subsidy granted under this
     * family, including ended ones and ones removed as mistakes (marked), newest first.
     */
    public function familyHistory(Request $request, int $familyId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        $this->familyInScope($familyId, $agencyId, $centres);

        $cwelcc = \App\Support\CwelccHistory::periods($familyId)->map(fn ($p) => [
            'id' => (int) $p->id,
            'from' => $p->enrolled_from,
            'to' => $p->enrolled_to,
            'rate' => $p->subsidy_rate !== null ? (float) $p->subsidy_rate : null,
            'source' => $p->source,
            'started_by' => $p->started_by ?: null,
            'ended_by' => $p->ended_by ?: null,
        ]);
        $prov = $this->baseQuery($agencyId, $centres)
            ->where('s.type', self::TYPE)
            ->where('f.id', $familyId)
            ->orderByDesc('s.valid_from')->get();
        $pdocs = $this->docsFor($prov->pluck('id')->all());
        $prov = $prov->map(fn ($r) => $this->shape($r, $agencyId, null, null, $pdocs));

        return response()->json(['cwelcc' => $cwelcc, 'provincial' => $prov,
            'can_edit' => true]);
    }

    /**
     * POST /compliance/families/{id}/cwelcc {action: enrol|rate|end, date, rate}
     * Records a change as a new dated period; never rewrites a closed one.
     */
    public function familyCwelcc(Request $request, int $familyId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        $fam = $this->familyInScope($familyId, $agencyId, $centres);
        $data = $request->validate([
            'action' => ['required', 'in:enrol,rate,end'],
            'date' => ['required', 'date'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $day = Carbon::parse($data['date'])->toDateString();
        $rate = isset($data['rate']) ? (float) $data['rate'] : null;
        $by = (int) $request->user()->id;
        $before = \App\Support\CwelccHistory::open($familyId);
        $err = match ($data['action']) {
            'enrol' => \App\Support\CwelccHistory::enrol($familyId, $day, $rate, $by),
            'rate' => \App\Support\CwelccHistory::changeRate($familyId, $day, $rate, $by),
            'end' => \App\Support\CwelccHistory::end($familyId, $day, $by),
        };
        if ($err) {
            return response()->json(['message' => $err], 422);
        }
        try {
            \App\Support\Audit::write([
                'user_id' => $by, 'agency_id' => $agencyId,
                'action' => 'cwelcc.' . ($data['action'] === 'rate' ? 'rate_changed' : ($data['action'] === 'end' ? 'ended' : 'enrolled')),
                'entity_type' => 'family', 'entity_id' => $familyId,
                'payload' => json_encode([
                    'summary' => ['enrol' => 'Enrolled ', 'rate' => 'Changed the CWELCC rate for ', 'end' => 'Ended CWELCC for '][$data['action']]
                        . $fam->family_name . ($data['action'] === 'enrol' ? ' in CWELCC' : '') . ' from ' . $day
                        . ($rate !== null ? ' at ' . $rate . '%' : ''),
                    'family' => $fam->family_name, 'date' => $day, 'rate' => $rate,
                    'before' => $before, 'after' => \App\Support\CwelccHistory::open($familyId),
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return $this->familyHistory($request, $familyId);
    }

    /**
     * POST /compliance/subsidies/{id}/documents  (multipart: files[] or file)
     * The province's or government's paperwork: approval letter, renewal, notice.
     * Private disk, never public; one row per file.
     */
    public function uploadDocuments(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        [$row] = $this->docScope($id, $agencyId, $centres);
        $request->validate([
            'files' => ['sometimes', 'array', 'max:10'],
            'files.*' => ['file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,heic,heif,webp,doc,docx'],
            'file' => ['sometimes', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,heic,heif,webp,doc,docx'],
        ]);
        $files = $request->file('files') ?: array_filter([$request->file('file')]);
        if (! $files) {
            return response()->json(['message' => 'Choose a file to upload.'], 422);
        }
        $saved = [];
        foreach ($files as $f) {
            $ext = strtolower($f->getClientOriginalExtension() ?: $f->extension() ?: 'bin');
            $path = $f->storeAs('subsidy-docs/' . $agencyId . '/' . $row->id, \Illuminate\Support\Str::uuid() . '.' . $ext, 'local');
            if (! $path) {
                return response()->json(['message' => 'That file could not be saved. Try again.'], 500);
            }
            $saved[] = DB::table('subsidy_documents')->insertGetId([
                'subsidy_id' => $row->id,
                'family_id' => $row->family_id,
                'path' => $path,
                'original_name' => mb_substr($f->getClientOriginalName() ?: ('document.' . $ext), 0, 255),
                'mime' => $f->getMimeType(),
                'size' => (int) $f->getSize(),
                'uploaded_by_id' => (int) $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->docAudit($request, $agencyId, 'subsidy.document_added', $row, DB::table('subsidy_documents')->whereIn('id', $saved)->pluck('original_name')->all());

        return response()->json(['documents' => $this->docsFor([$row->id])[$row->id] ?? []], 201);
    }

    /** GET /compliance/subsidies/{id}/documents/{doc} -- streams the file to signed-in staff. */
    public function downloadDocument(Request $request, int $id, int $doc)
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        [$row] = $this->docScope($id, $agencyId, $centres);
        $d = DB::table('subsidy_documents')->where('id', $doc)->where('subsidy_id', $row->id)->whereNull('removed_at')->first();
        abort_unless($d && \Illuminate\Support\Facades\Storage::disk('local')->exists($d->path), 404, 'That document was not found.');

        return \Illuminate\Support\Facades\Storage::disk('local')->download($d->path, $d->original_name,
            ['Content-Type' => $d->mime ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store']);
    }

    /** DELETE /compliance/subsidies/{id}/documents/{doc} -- hidden, the file is kept. */
    public function removeDocument(Request $request, int $id, int $doc): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = $this->centreScope($request, $agencyId);
        [$row] = $this->docScope($id, $agencyId, $centres);
        $d = DB::table('subsidy_documents')->where('id', $doc)->where('subsidy_id', $row->id)->whereNull('removed_at')->first();
        abort_unless($d, 404, 'That document was not found.');
        DB::table('subsidy_documents')->where('id', $d->id)->update(['removed_at' => now(), 'removed_by_id' => (int) $request->user()->id, 'updated_at' => now()]);
        $this->docAudit($request, $agencyId, 'subsidy.document_removed', $row, [$d->original_name]);

        return response()->json(['documents' => $this->docsFor([$row->id])[$row->id] ?? []]);
    }

    // -- helpers -----------------------------------------------------------------

    /** subsidy id => [{id, name, size, uploaded_at, uploaded_by}] for live documents. */
    private function docsFor(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        $rows = DB::table('subsidy_documents as d')->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by_id')
            ->whereIn('d.subsidy_id', $ids)->whereNull('d.removed_at')->orderBy('d.id')
            ->get(['d.id', 'd.subsidy_id', 'd.original_name', 'd.size', 'd.mime', 'd.created_at',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as uploaded_by")]);
        foreach ($rows as $d) {
            $out[(int) $d->subsidy_id][] = ['id' => (int) $d->id, 'name' => $d->original_name, 'size' => (int) $d->size,
                'mime' => $d->mime, 'uploaded_at' => $d->created_at, 'uploaded_by' => $d->uploaded_by ?: null];
        }

        return $out;
    }

    /**
     * [row] for a provincial subsidy whose FAMILY the caller may see -- ended and archived
     * ones included, because their paperwork is exactly what an audit asks for.
     */
    private function docScope(int $id, int $agencyId, ?array $centres): array
    {
        $row = DB::table('subsidies as s')->join('children as ch', 'ch.id', '=', 's.child_id')
            ->where('s.id', $id)->where('s.type', self::TYPE)
            ->first(['s.*', DB::raw('COALESCE(s.family_id, ch.family_id) as family_id'), 'ch.first_name', 'ch.last_name']);
        abort_unless($row, 404, 'That subsidy was not found.');
        $this->familyInScope((int) $row->family_id, $agencyId, $centres);

        return [$row];
    }

    private function docAudit(Request $request, int $agencyId, string $action, object $row, array $names): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => (int) $request->user()->id, 'agency_id' => $agencyId,
                'action' => $action, 'entity_type' => 'child', 'entity_id' => (int) $row->child_id,
                'payload' => json_encode([
                    'summary' => ($action === 'subsidy.document_added' ? 'Added ' : 'Removed ') . implode(', ', $names)
                        . ' on the provincial subsidy for ' . trim($row->first_name . ' ' . $row->last_name)
                        . ($row->case_number ? ' (case ' . $row->case_number . ')' : ''),
                    'subsidy_id' => (int) $row->id, 'files' => $names,
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private function familyInScope(int $familyId, int $agencyId, ?array $centres): object
    {
        $fam = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)->where('c.agency_id', $agencyId)
            ->when($centres !== null, fn ($w) => $w->whereIn('c.id', $centres))
            ->first(['f.id', 'f.family_name', 'f.centre_id']);
        abort_unless($fam, 404, 'That family was not found.');

        return $fam;
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'child_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'case_number' => ['nullable', 'string', 'max:120'],
            // Daily or monthly (2026-09-29). A daily amount comes off as that amount x
            // the child's scheduled care days in the month (App\Support\SubsidyAmount).
            'amount_basis' => ['sometimes', 'in:monthly,daily'],
            'monthly_amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999'],
            'daily_amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999'],
            'valid_from' => [$req, 'date'],
            'valid_to' => ['nullable', 'date'],
            'approved_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        foreach (['valid_from', 'valid_to', 'approved_at'] as $k) {
            if (! empty($data[$k])) {
                $data[$k] = Carbon::parse($data[$k])->toDateString();
            }
        }
        if (! empty($data['valid_from']) && ! empty($data['valid_to']) && $data['valid_to'] < $data['valid_from']) {
            abort(response()->json(['message' => 'The last day cannot be before the first day.',
                'errors' => ['valid_to' => ['Before the start date.']]], 422));
        }
        if (isset($data['case_number'])) {
            $data['case_number'] = trim($data['case_number']) ?: null;
        }
        // The basis decides which amount is required; the other is cleared so a row
        // never carries a stale figure that a later reader could mistake for the real one.
        if ($creating || array_key_exists('amount_basis', $data) || array_key_exists('monthly_amount', $data) || array_key_exists('daily_amount', $data)) {
            $basis = $data['amount_basis'] ?? ($creating ? 'monthly' : null);
            if ($basis === 'daily' || ($basis === null && isset($data['daily_amount']))) {
                if (empty($data['daily_amount'])) {
                    abort(response()->json(['message' => 'Enter the daily amount.', 'errors' => ['daily_amount' => ['Required for a daily subsidy.']]], 422));
                }
                $data['amount_basis'] = 'daily';
                $data['monthly_amount'] = 0;
            } elseif ($basis === 'monthly' || isset($data['monthly_amount'])) {
                if (empty($data['monthly_amount'])) {
                    abort(response()->json(['message' => 'Enter the monthly amount.', 'errors' => ['monthly_amount' => ['Required for a monthly subsidy.']]], 422));
                }
                $data['amount_basis'] = 'monthly';
                $data['daily_amount'] = null;
            }
        }

        return $data;
    }

    /** Another ACTIVE subsidy of any type for this child whose dates overlap. */
    private function overlap(int $childId, string $from, ?string $to, ?int $exceptId): ?object
    {
        return DB::table('subsidies')
            ->where('child_id', $childId)->where('active', 1)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where(function ($q) use ($to) {
                if ($to !== null) {
                    $q->where('valid_from', '<=', $to);
                }
            })
            ->where(function ($q) use ($from) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from);
            })
            ->first();
    }

    private function overlapError(object $clash): JsonResponse
    {
        $range = $clash->valid_from . ' to ' . ($clash->valid_to ?: 'no end date');

        return response()->json([
            'message' => 'This child already has a ' . ($clash->type === self::TYPE ? 'provincial' : $clash->type)
                . ' subsidy from ' . $range . '. End that one first, or pick dates that do not overlap -- '
                . 'invoices apply one subsidy per child.',
            'errors' => ['valid_from' => ['Overlaps another subsidy.']],
        ], 422);
    }

    private function baseQuery(int $agencyId, ?array $centres)
    {
        // The family and centre the subsidy was granted under (s.family_id / s.centre_id),
        // falling back to the child's current ones for rows from before they existed.
        // Archived children are NOT filtered out: this is history.
        return DB::table('subsidies as s')
            ->join('children as ch', 'ch.id', '=', 's.child_id')
            ->join('families as f', 'f.id', '=', DB::raw('COALESCE(s.family_id, ch.family_id)'))
            ->join('centres as c', 'c.id', '=', DB::raw('COALESCE(s.centre_id, f.centre_id)'))
            ->where('c.agency_id', $agencyId)
            ->when($centres !== null, fn ($w) => $w->whereIn('c.id', $centres))
            ->select('s.*', 'ch.first_name', 'ch.last_name', 'ch.deleted_at as child_archived', 'f.id as family_id', 'f.family_name',
                'c.id as centre_id', 'c.name as centre_name',
                DB::raw('(select en.monthly_fee from enrollments en where en.child_id = ch.id and en.end_date is null order by en.start_date desc limit 1) as monthly_fee'));
    }

    private function fetch(int $id): object
    {
        return DB::table('subsidies as s')
            ->join('children as ch', 'ch.id', '=', 's.child_id')
            ->join('families as f', 'f.id', '=', DB::raw('COALESCE(s.family_id, ch.family_id)'))
            ->join('centres as c', 'c.id', '=', DB::raw('COALESCE(s.centre_id, f.centre_id)'))
            ->where('s.id', $id)
            ->select('s.*', 'ch.first_name', 'ch.last_name', 'ch.deleted_at as child_archived', 'f.id as family_id', 'f.family_name',
                'c.id as centre_id', 'c.name as centre_name',
                DB::raw('(select en.monthly_fee from enrollments en where en.child_id = ch.id and en.end_date is null order by en.start_date desc limit 1) as monthly_fee'))
            ->first();
    }

    private function shape(object $r, int $agencyId, ?Carbon $start = null, ?Carbon $end = null, array $docs = []): array
    {
        // What it takes off the month shown (or this month), from the ONE calculation the
        // invoice run uses -- a daily subsidy's figure depends on the scheduled days.
        $monthStart = ($start ?: Carbon::parse(\App\Support\AgencyTime::today($agencyId)))->copy()->startOfMonth()->toDateString();
        $calc = \App\Support\SubsidyAmount::forSubsidyMonth($r, $monthStart);
        // The agency's date, not UTC's: from 8pm Toronto the UTC date is already tomorrow.
        $today = \App\Support\AgencyTime::today($agencyId);
        $inMonth = $start === null || ($r->valid_from <= $end->toDateString()
            && ($r->valid_to === null || $r->valid_to >= $start->toDateString()));
        $status = $r->valid_from > $today ? 'upcoming' : (($r->valid_to && $r->valid_to < $today) ? 'ended' : 'active');

        return [
            'id' => (int) $r->id,
            'child_id' => (int) $r->child_id,
            'child_name' => trim($r->first_name . ' ' . $r->last_name) . (! empty($r->child_archived) ? ' (archived)' : ''),
            'family_id' => isset($r->family_id) ? (int) $r->family_id : null,
            'family_name' => $r->family_name,
            'centre_name' => $r->centre_name,
            'case_number' => $r->case_number,
            'monthly_amount' => round((float) $r->monthly_amount, 2),
            'amount_basis' => ($r->amount_basis ?? 'monthly') === 'daily' ? 'daily' : 'monthly',
            'daily_amount' => isset($r->daily_amount) && $r->daily_amount !== null ? round((float) $r->daily_amount, 2) : null,
            'month_amount' => $calc['amount'],
            'month_days' => $calc['days'],
            'documents' => $docs[(int) $r->id] ?? [],
            'monthly_fee' => $r->monthly_fee !== null ? round((float) $r->monthly_fee, 2) : null,
            'valid_from' => $r->valid_from,
            'valid_to' => $r->valid_to,
            'approved_at' => $r->approved_at,
            'notes' => $r->notes,
            'status' => (isset($r->active) && ! $r->active) ? 'removed' : $status,
            'in_month' => $inMonth,
        ];
    }

    private function row(int $id): array
    {
        return (array) DB::table('subsidies')->where('id', $id)->first();
    }

    /** [row, child] for a provincial subsidy the caller may manage, or 404. */
    private function ownedRow(int $id, int $agencyId, ?array $centres): array
    {
        $row = DB::table('subsidies')->where('id', $id)->where('type', self::TYPE)->where('active', 1)->first();
        abort_unless($row, 404, 'That subsidy was not found.');

        return [$row, $this->childInScope((int) $row->child_id, $agencyId, $centres)];
    }

    private function childInScope(int $childId, int $agencyId, ?array $centres): object
    {
        $child = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('ch.id', $childId)->whereNull('ch.deleted_at')
            ->where('c.agency_id', $agencyId)
            ->when($centres !== null, fn ($w) => $w->whereIn('c.id', $centres))
            ->first(['ch.id', 'ch.first_name', 'ch.last_name', 'f.family_name', 'c.id as centre_id']);
        // 404, not 403: a child outside your scope is one you cannot see.
        abort_unless($child, 404, 'That child was not found.');

        return $child;
    }

    /** null = the whole agency; otherwise the centres a centre director holds. */
    private function centreScope(Request $request, int $agencyId): ?array
    {
        $uid = $request->user()->id;
        $wide = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where(function ($q) use ($agencyId) {
                $q->where('role', 'platform_admin')
                  ->orWhere(fn ($w) => $w->where('role', 'agency_admin')->where('agency_id', $agencyId));
            })->exists();
        if ($wide) {
            return null;
        }
        $ids = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where('role', 'centre_director')->where('agency_id', $agencyId)
            ->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
        abort_if(! $ids, 403, 'Subsidies are managed by centre directors and agency admins.');

        return $ids;
    }

    private function monthRange(Request $request): array
    {
        $month = (string) $request->query('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        $start = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();

        return [$start, $start->copy()->endOfMonth(), $month];
    }

    private function audit(Request $request, int $agencyId, string $action, object $child, ?array $before, ?array $after): void
    {
        $name = trim($child->first_name . ' ' . $child->last_name);
        $r = $after ?: $before ?: [];
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'agency_id' => $agencyId,
                'action' => $action,
                'entity_type' => 'child',
                'entity_id' => $child->id,
                'payload' => json_encode([
                    'summary' => ucfirst(substr($action, 8)) . ' provincial subsidy for ' . $name
                        . ' (' . ($r['case_number'] ?? 'no case number') . ', '
                        . ((($r['amount_basis'] ?? 'monthly') === 'daily')
                            ? '$' . number_format((float) ($r['daily_amount'] ?? 0), 2) . '/day of scheduled care, '
                            : '$' . number_format((float) ($r['monthly_amount'] ?? 0), 2) . '/month, ') . ($r['valid_from'] ?? '?') . ' to ' . (($r['valid_to'] ?? null) ?: 'open') . ')',
                    'child' => $name,
                    'family' => $child->family_name ?? null,
                    'before' => $before,
                    'after' => $after,
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never be the reason a save fails.
        }
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($activeId) { $q->where('role', 'platform_admin')->orWhere('agency_id', $activeId); })->exists()) {
            return $activeId;
        }
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) {
            abort(400, 'Select an agency first.');
        }
        $first = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->value('agency_id');
        abort_unless($first, 400);

        return (int) $first;
    }
}
