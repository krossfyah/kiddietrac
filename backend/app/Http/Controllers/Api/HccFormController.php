<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Concerns\ReportAlerts;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\HccFormPdf;
use App\Support\HccFormRenderer;
use App\Support\HccFormSchemas;
use App\Support\KtDompdf as Dompdf;   // translating Dompdf (2026-10-22)
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Home-visitor inspection forms (2026-07-21).
 *
 * A home visitor selects one of two standardised forms — the Monthly Monitoring
 * & Inspection Report or the quarterly Standard Home Visitor Checklist — fills
 * it in, and submits. On submit the completed form is emailed (as a PDF) to the
 * agency's directors/admins and stored so those reviewers can see every
 * submission in a table (form type, provider, date/time, who filed it) and
 * download the PDF.
 */
class HccFormController extends Controller
{
    use ReportAlerts;

    /** These two forms are specific to iLearn (agency 2) and the Test Agency (agency 6). */
    private const ALLOWED_AGENCIES = [2, 6];

    private function agencyId(Request $request): ?int
    {
        /* The agency you are working in (2026-11-05 leak sweep): a platform admin the one they named; anyone else
           the membership-checked active agency. The old fallback took the first agency_id row held anywhere, so a
           person with roles in two agencies could read and file forms in the one they were not working in. */
        $active = (int) $request->header('X-Active-Agency-Id');
        if ($active && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where('role', 'platform_admin')->exists()) {
            return $active;
        }
        return \App\Support\AgencyRole::activeAgency($request);
    }

    /* The centres a centre-scoped reviewer reviews (2026-11-05 leak sweep): null = agency-wide (admin /
       platform); otherwise the centres where the caller is centre_director in this agency. CentreScope alone
       also counted centres where they are only an educator, so a director of Y who teaches at X read X's forms. */
    private function directorScope(Request $request, int $agencyId): ?array
    {
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if ($scope === null) return null;
        $dir = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $request->user()->id)->where('role', 'centre_director')->where('active', true)
            ->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
        return array_values(array_intersect($scope, $dir));
    }

    /* Which centres the caller may file a form against (2026-11-05 leak sweep). A home visitor works across the
       agency by design (role stored with centre_id NULL), so null = any centre in the agency; a director only
       their own centres. */
    private function filingScope(Request $request, int $agencyId): ?array
    {
        if (in_array('home_visitor', \App\Support\AgencyRole::names($request), true)) return null;
        return $this->directorScope($request, $agencyId);
    }

    /** Is the inspection-forms feature available to this account's active agency? */
    private function enabled(Request $request): bool
    {
        return in_array((int) $this->agencyId($request), self::ALLOWED_AGENCIES, true);
    }

    private function isReviewer(Request $request): bool
    {
        return \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $request->user()->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])->exists();
    }

    /** GET /inspection-forms/schemas — the list of fillable forms (empty if not enabled). */
    /* WHICH REPORTS A REVIEWER MAY OPEN (2026-10-05). isReviewer() is true for a
       centre director too, and was read as "the whole agency": a Sunny Meadows director
       saw every centre's reports. Agency-wide reviewers keep that; a director reviews
       their own centres' (App\Support\CentreScope). */
    private function reviews(Request $request, int $agencyId, $centreId): bool
    {
        return $this->isReviewer($request)
            && \App\Support\CentreScope::canRead($this->directorScope($request, $agencyId), $centreId);
    }

    /** null = every report in the agency; otherwise the centres a director reviews. */
    private function reviewScope(Request $request, ?int $agencyId): ?array
    {
        // Director centres only, and fail closed with no agency (2026-11-05 leak sweep): this returned null
        // (= the whole agency) when no agency resolved.
        return $agencyId ? $this->directorScope($request, $agencyId) : [];
    }

    public function schemas(Request $request): JsonResponse
    {
        if (! $this->enabled($request)) {
            return response()->json(['enabled' => false, 'forms' => []]);
        }
        $out = [];
        foreach (HccFormSchemas::keys() as $k) {
            $out[] = ['key' => $k, 'label' => HccFormSchemas::label($k)];
        }
        return response()->json(['enabled' => true, 'forms' => $out]);
    }

    /** GET /inspection-forms/schema/{key} — the full, id-annotated schema for one form. */
    public function schema(string $key): JsonResponse
    {
        $s = HccFormSchemas::get($key);
        abort_unless($s, 404, 'Unknown form.');
        return response()->json(['schema' => $s]);
    }

    /** GET /inspection-forms/centres — centres in the visitor's agency (dropdown). */
    public function centres(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        if (! $agencyId) return response()->json(['centres' => []]);
        // The picker for a NEW form: open providers only (2026-10-09); past forms still list.
        $cq = \App\Support\Live::centres(DB::table('centres'))->where('agency_id', $agencyId);
        /* A director picks from their own centres only, the same ones store() accepts (2026-11-05 leak sweep). */
        $fs = $this->filingScope($request, (int) $agencyId);
        if ($fs !== null) $cq->whereIn('id', $fs ?: [0]);
        $centres = $cq->orderBy('name')->get(['id', 'name', 'city', 'province']);
        return response()->json(['centres' => $centres]);
    }

    /** GET /inspection-forms — reviewers see the agency's; a visitor sees their own. */
    public function index(Request $request): JsonResponse
    {
        if (! $this->enabled($request)) {
            return response()->json(['enabled' => false, 'forms' => []]);
        }
        $agencyId = $this->agencyId($request);
        $rows = DB::table('hcc_inspection_forms as f')
            ->leftJoin('centres as c', 'c.id', '=', 'f.centre_id')
            ->leftJoin('users as u', 'u.id', '=', 'f.home_visitor_id')
            ->when($agencyId, fn ($x) => $x->where('f.agency_id', $agencyId))
            ->when(! $this->isReviewer($request), fn ($x) => $x->where('f.home_visitor_id', $request->user()->id))
            // A centre director: their centres' forms, and any they filed themselves.
            ->when($this->isReviewer($request) && ($sc = $this->reviewScope($request, $agencyId)) !== null,
                fn ($x) => $x->where(fn ($w) => $w->whereIn('f.centre_id', $sc ?: [0])->orWhere('f.home_visitor_id', $request->user()->id)))
            ->whereNull('f.deleted_at')
            ->orderByDesc('f.visit_date')->orderByDesc('f.id')
            ->limit(500)
            ->get([
                'f.id', 'f.form_type', 'f.provider_name', 'f.visit_date', 'f.visit_time_in', 'f.visit_time_out',
                'f.quarter', 'f.status', 'f.history', 'f.created_at', 'f.updated_at', 'c.name as centre_name',
                'f.agency_id', 'f.centre_id', 'f.home_visitor_id',
                DB::raw("NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),'') as home_visitor_name"),
            ]);

        return response()->json(['enabled' => true, 'forms' => $rows->map(function ($r) use ($request) {
            $hist = json_decode($r->history ?? '[]', true);
            return [
                'id'             => (int) $r->id,
                'form_type'      => $r->form_type,
                'form_label'     => HccFormSchemas::label($r->form_type),
                'provider_name'  => $r->provider_name,
                'centre_name'    => $r->centre_name,
                'visit_date'     => $r->visit_date,
                'visit_time_in'  => $r->visit_time_in,
                'visit_time_out' => $r->visit_time_out,
                'quarter'        => $r->quarter,
                'home_visitor'   => $r->home_visitor_name ?: '—',
                'status'         => $r->status,
                'edited'         => is_array($hist) && count($hist) > 0,
                'edit_count'     => is_array($hist) ? count($hist) : 0,
                // Same rule as show() and update()'s fetch(): the list's Edit / correct follows it (2026-11-27).
                'editable'       => $this->reviews($request, (int) $r->agency_id, $r->centre_id) || (int) $r->home_visitor_id === (int) $request->user()->id,
                'created_at'     => $r->created_at,
                'updated_at'     => $r->updated_at,
            ];
        })->all()]);
    }

    /** POST /inspection-forms — save a draft or file a completed form. */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($agencyId, 422, 'No agency for this account.');
        abort_unless($this->enabled($request), 403, 'Inspection forms are not enabled for this agency.');

        $data = $request->validate([
            'form_type'      => 'required|in:monthly_monitoring,quarterly_checklist',
            'centre_id'      => 'nullable|integer',
            'provider_name'  => 'required|string|max:191',
            'visit_date'     => 'required|date',
            'visit_time_in'  => 'nullable|string|max:20',
            'visit_time_out' => 'nullable|string|max:20',
            'quarter'        => 'nullable|string|max:4',
            'status'         => 'nullable|in:draft,submitted',
            'answers'        => 'required|array',
        ]);
        $status = $data['status'] ?? 'submitted';

        if (! empty($data['centre_id'])) {
            $ok = DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->exists();
            abort_unless($ok, 422, 'That centre is not in your agency.');
        }
        /* A director files only against a centre they direct (2026-11-05 leak sweep): any centre in the agency
           was accepted, so a form (and its reviewer email) landed on another centre. */
        $fs = $this->filingScope($request, (int) $agencyId);
        if ($fs !== null) {
            abort_unless(! empty($data['centre_id']) && in_array((int) $data['centre_id'], $fs, true), 403, 'You can only file forms for a centre you direct.');
        }

        $u = $request->user();
        $hvName = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->email ?? 'Home visitor');

        $id = DB::table('hcc_inspection_forms')->insertGetId([
            'agency_id'       => $agencyId,
            'centre_id'       => $data['centre_id'] ?? null,
            'home_visitor_id' => $u->id,
            'form_type'       => $data['form_type'],
            'provider_name'   => $data['provider_name'],
            'visit_date'      => $data['visit_date'],
            'visit_time_in'   => $data['visit_time_in'] ?? null,
            'visit_time_out'  => $data['visit_time_out'] ?? null,
            'quarter'         => $data['quarter'] ?? null,
            'status'          => $status,
            'answers'         => json_encode($data['answers']),
            'history'         => json_encode([]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Only email reviewers when the form is actually submitted (not for drafts).
        if ($status === 'submitted') {
            try {
                $this->notifyReviewers($agencyId, $id, $data, $hvName);
            } catch (\Throwable $e) {
                Log::warning('HCC form reviewer email failed', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['ok' => true, 'id' => $id, 'status' => $status], 201);
    }

    /**
     * PATCH /inspection-forms/{id} — save a draft in progress, submit a draft, or
     * correct an already-submitted form. Edits to a SUBMITTED form append an audit
     * entry (who / when / the field-level old→new changes / a pre-edit snapshot).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless($this->enabled($request), 403);
        $row = $this->fetch($request, $id);
        $data = $request->validate([
            'centre_id'      => 'nullable|integer',
            'provider_name'  => 'nullable|string|max:191',
            'visit_date'     => 'nullable|date',
            'visit_time_in'  => 'nullable|string|max:20',
            'visit_time_out' => 'nullable|string|max:20',
            'quarter'        => 'nullable|string|max:4',
            'status'         => 'nullable|in:draft,submitted',
            'answers'        => 'required|array',
            'note'           => 'nullable|string|max:2000',
        ]);

        /* A new centre_id must be in this form's agency and one the caller may file for (2026-11-05 leak sweep):
           it was written unchecked, so a form could be moved to another agency's or another centre's centre. */
        if (array_key_exists('centre_id', $data) && (int) ($data['centre_id'] ?? 0) !== (int) ($row->centre_id ?? 0)) {
            if (! empty($data['centre_id'])) {
                abort_unless(DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $row->agency_id)->exists(), 422, 'That centre is not in your agency.');
            }
            $fs = $this->filingScope($request, (int) $row->agency_id);
            abort_unless($fs === null || (! empty($data['centre_id']) && in_array((int) $data['centre_id'], $fs, true)), 403, 'You can only file forms for a centre you direct.');
        }

        $wasSubmitted = $row->status === 'submitted';
        $newStatus = $data['status'] ?? $row->status;
        $oldAnswers = json_decode($row->answers ?? '{}', true) ?: [];
        $newAnswers = $data['answers'];

        $update = [
            'provider_name'  => $data['provider_name'] ?? $row->provider_name,
            'visit_date'     => $data['visit_date'] ?? $row->visit_date,
            'visit_time_in'  => $data['visit_time_in'] ?? $row->visit_time_in,
            'visit_time_out' => $data['visit_time_out'] ?? $row->visit_time_out,
            'quarter'        => $data['quarter'] ?? $row->quarter,
            'centre_id'      => array_key_exists('centre_id', $data) ? $data['centre_id'] : $row->centre_id,
            'status'         => $newStatus,
            'answers'        => json_encode($newAnswers),
            'updated_at'     => now(),
        ];

        // Audit trail only for edits AFTER first submission (draft saves are silent).
        if ($wasSubmitted) {
            $changes = $this->diffAnswers($row->form_type, $oldAnswers, $newAnswers);
            if ($changes || trim((string) ($data['note'] ?? '')) !== '') {
                $u = $request->user();
                $by = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->email ?? 'Someone');
                $hist = json_decode($row->history ?? '[]', true);
                if (! is_array($hist)) $hist = [];
                $hist[] = [
                    'at'           => now()->toIso8601String(),
                    'by_id'        => (int) $u->id,
                    'by'           => $by,
                    'note'         => trim((string) ($data['note'] ?? '')) ?: null,
                    'changes'      => $changes,
                    'prev_answers' => $oldAnswers, // snapshot for an old-vs-new view
                ];
                $update['history'] = json_encode($hist);
            }
        }

        DB::table('hcc_inspection_forms')->where('id', $id)->update($update);

        // If a draft was just submitted, notify reviewers now.
        if (! $wasSubmitted && $newStatus === 'submitted') {
            try {
                $fresh = DB::table('hcc_inspection_forms')->where('id', $id)->first();
                $by = trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? '')) ?: ($request->user()->email ?? 'Home visitor');
                $this->notifyReviewers((int) $row->agency_id, $id, (array) $update + ['form_type' => $row->form_type, 'provider_name' => $update['provider_name'], 'visit_date' => $update['visit_date'], 'visit_time_in' => $update['visit_time_in'], 'visit_time_out' => $update['visit_time_out'], 'quarter' => $update['quarter'], 'centre_id' => $update['centre_id']], $by);
            } catch (\Throwable $e) {
                Log::warning('HCC draft-submit email failed', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['ok' => true, 'id' => $id, 'status' => $newStatus]);
    }

    /** GET /inspection-forms/{id} — metadata + answers (for re-fill) + audit history. */
    public function show(Request $request, int $id): JsonResponse
    {
        $row = $this->fetch($request, $id);
        $answers = json_decode($row->answers ?? '{}', true) ?: [];
        $meta = $this->meta($row);
        $history = json_decode($row->history ?? '[]', true);
        if (! is_array($history)) $history = [];
        // Strip the heavy snapshots from the response — keep just the audit summary.
        $auditTrail = array_map(fn ($h) => [
            'at'      => $h['at'] ?? null,
            'by'      => $h['by'] ?? null,
            'note'    => $h['note'] ?? null,
            'changes' => $h['changes'] ?? [],
        ], $history);

        return response()->json([
            'form' => [
                'id'             => (int) $row->id,
                'form_type'      => $row->form_type,
                'form_label'     => HccFormSchemas::label($row->form_type),
                'provider_name'  => $row->provider_name,
                'centre_id'      => $row->centre_id ? (int) $row->centre_id : null,
                'visit_date'     => $row->visit_date,
                'visit_time_in'  => $row->visit_time_in,
                'visit_time_out' => $row->visit_time_out,
                'quarter'        => $row->quarter,
                'status'         => $row->status,
                'home_visitor'   => $meta['home_visitor'],
                'centre_name'    => $meta['centre_name'],
                'editable'       => $this->reviews($request, (int) $row->agency_id, $row->centre_id) || (int) $row->home_visitor_id === (int) $request->user()->id,
            ],
            'answers' => $answers,
            'history' => $auditTrail,
        ]);
    }

    /** Field-level old→new diff, labelled from the form schema, for the audit trail. */
    private function diffAnswers(string $formType, array $old, array $new): array
    {
        $labels = HccFormSchemas::labelMap($formType);
        $fmt = function ($v) {
            if (is_array($v)) return '(table)';
            $v = (string) $v;
            $low = strtolower($v);
            if ($low === 'yes') return 'Yes';
            if ($low === 'no') return 'No';
            if ($low === 'na') return 'N/A';
            if ($v === '1') return 'Checked';
            if ($v === '0' || $v === '') return '—';
            return $v;
        };
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $out = [];
        foreach ($keys as $k) {
            $o = $old[$k] ?? '';
            $n = $new[$k] ?? '';
            if (json_encode($o) === json_encode($n)) continue;
            if (! isset($labels[$k])) {
                // table blocks: labels keyed by block id (prefix before "__")
                $base = explode('__', $k)[0];
                $label = $labels[$base] ?? $k;
            } else {
                $label = $labels[$k];
            }
            $out[] = ['field' => $label, 'from' => $fmt($o), 'to' => $fmt($n)];
            if (count($out) >= 200) break;
        }
        return $out;
    }

    /** GET /inspection-forms/{id}/pdf — the completed form as a PDF. */
    public function pdf(Request $request, int $id)
    {
        $row = $this->fetch($request, $id);
        $pdf = $this->renderPdf($row);
        $fn = $row->form_type . '-' . $row->visit_date . '-' . $id . '.pdf';
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $fn . '"',
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function fetch(Request $request, int $id)
    {
        $agencyId = $this->agencyId($request);
        $row = DB::table('hcc_inspection_forms')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        abort_unless((int) $row->agency_id === (int) $agencyId, 403);
        abort_unless($this->reviews($request, (int) $row->agency_id, $row->centre_id) || (int) $row->home_visitor_id === (int) $request->user()->id, 403);
        return $row;
    }

    private function meta($row): array
    {
        $centre = $row->centre_id ? DB::table('centres')->where('id', $row->centre_id)->value('name') : null;
        $hv = DB::table('users')->where('id', $row->home_visitor_id)->first(['first_name', 'last_name', 'email']);
        $hvName = $hv ? (trim(($hv->first_name ?? '') . ' ' . ($hv->last_name ?? '')) ?: ($hv->email ?? '')) : '';
        return [
            'home_visitor' => $hvName,
            'centre_name'  => $centre,
            'submitted_at' => $row->created_at ? Carbon::parse($row->created_at)->format('M j, Y g:i A') : '',
        ];
    }

    private function renderPdf($row): string
    {
        $answers = json_decode($row->answers ?? '{}', true) ?: [];
        // Preferred: fill the ACTUAL original PDF (pixel-exact). Fall back to the
        // HTML renderer only if the template/map assets are missing.
        if (HccFormPdf::available($row->form_type)) {
            return HccFormPdf::render($row->form_type, $answers);
        }
        $schema = HccFormSchemas::get($row->form_type);
        return HccFormRenderer::toPdf($schema, $answers, $this->meta($row));
    }

    private function notifyReviewers(int $agencyId, int $id, array $data, string $hvName): void
    {
        $recipients = $this->alertRecipients($agencyId, ! empty($data['centre_id']) ? (int) $data['centre_id'] : null);
        if (! $recipients) return;

        $row = DB::table('hcc_inspection_forms')->where('id', $id)->first();
        $pdf = $this->renderPdf($row);

        $label = HccFormSchemas::label($data['form_type']);
        $centreName = ! empty($data['centre_id']) ? DB::table('centres')->where('id', $data['centre_id'])->value('name') : null;
        $when = Carbon::parse($data['visit_date'])->format('M j, Y');
        $timeBits = trim(($data['visit_time_in'] ?? '') . (! empty($data['visit_time_out']) ? ' – ' . $data['visit_time_out'] : ''));

        $rowsHtml = '';
        $line = function ($k, $v) { return $v ? '<tr><td style="padding:6px 12px 6px 0;color:#64748b;font-size:13px;white-space:nowrap;">' . $k . '</td><td style="padding:6px 0;font-weight:700;color:#0f172a;font-size:13px;">' . htmlspecialchars((string) $v) . '</td></tr>' : ''; };
        $rowsHtml .= $line('Form', $label);
        $rowsHtml .= $line('Provider visited', $data['provider_name']);
        $rowsHtml .= $line('Centre', $centreName);
        $rowsHtml .= $line('Date of visit', $when);
        $rowsHtml .= $line('Time', $timeBits);
        if (! empty($data['quarter'])) $rowsHtml .= $line('Quarter', 'Q' . $data['quarter']);
        $rowsHtml .= $line('Home visitor', $hvName);

        $body = '<p style="margin:0 0 12px;font-size:14.5px;color:#243244;">A home-visit inspection form has just been submitted and is ready for your review. The completed form is attached to this email as a PDF.</p>'
            . '<table style="border-collapse:collapse;margin:8px 0 4px;">' . $rowsHtml . '</table>'
            . '<p style="margin:14px 0 0;font-size:12.5px;color:#64748b;">You can also open it any time under <strong>Home Visitor Forms</strong> in your portal.</p>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'   => 'INSPECTION FORM',
            'title'     => 'New home-visit form submitted',
            'subtitle'  => $label . ' · ' . $when,
            'preheader' => $hvName . ' submitted a ' . $label . ' for ' . $data['provider_name'] . '.',
        ]);
        $subject = 'New inspection form: ' . $label . ' — ' . $data['provider_name'] . ' (' . $when . ')';
        $filename = $data['form_type'] . '-' . $data['visit_date'] . '.pdf';

        $this->sendReportAlert($agencyId, $recipients, $subject, $html, $pdf, $filename);
    }
}
