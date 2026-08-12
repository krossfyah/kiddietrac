<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Concerns\ReportAlerts;
use App\Services\EmailTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Home-visit reports. A home visitor is attached to an agency and can file a
 * report against ANY centre in that agency (chosen from a dropdown). Agency
 * admins / directors / platform admins can review the agency's reports.
 */
class HomeVisitReportController extends Controller
{
    use ReportAlerts;

    /** The agency this user operates in (home visitors + admins are agency-scoped). */
    private function agencyId(Request $request): ?int
    {
        $active = (int) $request->header('X-Active-Agency-Id');
        if ($active && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($active) { $q->where('role', 'platform_admin')->orWhere('agency_id', $active); })->exists()) {
            return $active;
        }
        return (int) (DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)->whereNotNull('agency_id')->value('agency_id')) ?: null;
    }

    private function isReviewer(Request $request): bool
    {
        return DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])->exists();
    }

    /** GET /home-visits/centres — every centre in the visitor's agency (dropdown). */
    public function centres(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        if (! $agencyId) {
            return response()->json(['centres' => []]);
        }
        $centres = DB::table('centres')->where('agency_id', $agencyId)
            ->orderBy('name')->get(['id', 'name', 'city', 'province']);
        return response()->json(['centres' => $centres]);
    }

    /** GET /home-visits — the caller's own reports; reviewers see the agency's. */
    public function index(Request $request): JsonResponse
    {
        // Platform-admin "All agencies" mode (agency switcher sends X-Active-Agency-Id: all)
        // → span every agency instead of scoping to one. Mirrors AdminController::listUsers.
        $isPlatform = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        $allMode = $isPlatform && strtolower(trim((string) $request->header('X-Active-Agency-Id'))) === 'all';
        $agencyId = $allMode ? null : $this->agencyId($request);

        $q = DB::table('home_visit_reports as r')
            ->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.home_visitor_id')
            ->whereNull('r.deleted_at')
            ->when(! $allMode && $agencyId, fn ($x) => $x->where('r.agency_id', $agencyId))
            ->when(! $this->isReviewer($request), fn ($x) => $x->where('r.home_visitor_id', $request->user()->id))
            ->orderByDesc('r.visit_date')->orderByDesc('r.id')
            ->limit(500)
            ->get(['r.*', 'c.name as centre_name',
                DB::raw("NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),'') as home_visitor_name")]);

        return response()->json([
            'reports'     => $q->map(fn ($r) => $this->format($r))->all(),
            'is_reviewer' => $this->isReviewer($request),
            'all_mode'    => $allMode,
        ]);
    }

    /** POST /home-visits — file a report. */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($agencyId, 422, 'No agency for this account.');

        $data = $request->validate([
            'centre_id'        => 'nullable|integer',
            'family_id'        => 'nullable|integer',
            'child_id'         => 'nullable|integer',
            'family_name'      => 'nullable|string|max:191',
            'child_name'       => 'nullable|string|max:191',
            'visit_date'       => 'required|date',
            'visit_type'       => 'nullable|string|max:40',
            'location'         => 'nullable|string|max:40',
            'duration_minutes' => 'nullable|integer|min:0|max:1440',
            'present'          => 'nullable|string|max:191',
            'summary'          => 'nullable|string',
            'strengths'        => 'nullable|string',
            'concerns'         => 'nullable|string',
            'next_steps'       => 'nullable|string',
            'follow_up_date'   => 'nullable|date',
            'status'           => 'nullable|in:draft,submitted',
        ]);

        // The chosen centre must belong to the visitor's agency.
        if (! empty($data['centre_id'])) {
            $ok = DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->exists();
            abort_unless($ok, 422, 'That centre is not in your agency.');
        }

        $id = DB::table('home_visit_reports')->insertGetId(array_merge($data, [
            'agency_id'       => $agencyId,
            'home_visitor_id' => $request->user()->id,
            'visit_type'      => $data['visit_type'] ?? 'routine',
            'status'          => $data['status'] ?? 'submitted',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]));

        $row = DB::table('home_visit_reports as r')->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('r.id', $id)->first(['r.*', 'c.name as centre_name']);

        if (($data['status'] ?? 'submitted') === 'submitted') {
            try { $this->notifyReviewers($agencyId, $id, $data, $request->user()); } catch (\Throwable $e) {}
        }

        return response()->json(['ok' => true, 'report' => $this->format($row)], 201);
    }

    /** Email the agency's configured alert roles when a home-visit report is submitted. */
    private function notifyReviewers(int $agencyId, int $id, array $data, $actor): void
    {
        $recipients = $this->alertRecipients($agencyId);
        if (! $recipients) return;

        $hvName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: 'A home visitor';
        $when = Carbon::parse($data['visit_date'])->format('M j, Y');
        $centre = ! empty($data['centre_id']) ? DB::table('centres')->where('id', $data['centre_id'])->value('name') : null;
        $family = $data['family_name'] ?? ($data['child_name'] ?? 'a family');

        $line = fn ($k, $v) => $v ? '<tr><td style="padding:6px 12px 6px 0;color:#64748b;font-size:13px;white-space:nowrap;">' . $k . '</td><td style="padding:6px 0;font-weight:700;color:#0f172a;font-size:13px;">' . htmlspecialchars((string) $v) . '</td></tr>' : '';
        $rows = $line('Family', $data['family_name'] ?? null) . $line('Child', $data['child_name'] ?? null)
            . $line('Visit type', ucfirst((string) ($data['visit_type'] ?? 'routine'))) . $line('Centre', $centre)
            . $line('Date of visit', $when) . $line('Home visitor', $hvName)
            . $line('Follow-up', ! empty($data['follow_up_date']) ? Carbon::parse($data['follow_up_date'])->format('M j, Y') : null);

        $body = '<p style="margin:0 0 12px;font-size:14.5px;color:#243244;">A home-visit report has just been submitted and is ready for your review.</p>'
            . '<table style="border-collapse:collapse;margin:8px 0 4px;">' . $rows . '</table>'
            . (! empty($data['summary']) ? '<p style="margin:12px 0 0;font-size:13px;color:#334155;"><strong>Summary:</strong> ' . htmlspecialchars((string) $data['summary']) . '</p>' : '')
            . '<p style="margin:14px 0 0;font-size:12.5px;color:#64748b;">Open it any time under <strong>Home Visits</strong> in your portal.</p>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'   => 'HOME VISIT',
            'title'     => 'New home-visit report submitted',
            'subtitle'  => $family . ' · ' . $when,
            'preheader' => $hvName . ' submitted a home-visit report for ' . $family . '.',
        ]);
        $subject = 'New home-visit report: ' . $family . ' (' . $when . ')';
        $this->sendReportAlert($agencyId, $recipients, $subject, $html);
    }

    /** GET /home-visits/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $row = $this->fetchScoped($request, $id);
        return response()->json([
            'report'      => $this->format($row),
            'is_reviewer' => $this->isReviewer($request),
        ]);
    }

    /** Fetch a report with centre + home-visitor name, enforcing the same-agency
     *  + author-or-reviewer scope. Used by show() and pdf(). */
    private function fetchScoped(Request $request, int $id)
    {
        $row = DB::table('home_visit_reports as r')
            ->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.home_visitor_id')
            ->whereNull('r.deleted_at')
            ->where('r.id', $id)
            ->first(['r.*', 'c.name as centre_name',
                DB::raw("NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),'') as home_visitor_name")]);
        abort_unless($row, 404);
        // Platform admins (incl. "All agencies") may view any agency's report;
        // everyone else is scoped to their active agency + must be author/reviewer.
        $isPlatform = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if (! $isPlatform) {
            abort_unless((int) $row->agency_id === (int) $this->agencyId($request), 403);
        }
        abort_unless($this->isReviewer($request) || (int) $row->home_visitor_id === (int) $request->user()->id, 403);
        return $row;
    }

    /** GET /home-visits/{id}/pdf — a branded, formatted compliance PDF of the report. */
    public function pdf(Request $request, int $id)
    {
        $row = $this->fetchScoped($request, $id);
        $pdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($this->pdfHtml($row));
        $pdf->setPaper('letter');
        $pdf->render();
        // Stamp "Page N of M" on every page.
        try {
            $c = $pdf->getCanvas();
            $f = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            $c->page_text($c->get_width() - 120, $c->get_height() - 32, 'Page {PAGE_NUM} of {PAGE_COUNT}', $f, 8, [0.5, 0.55, 0.6]);
        } catch (\Throwable $e) {}
        $fn = 'home-visit-' . $row->visit_date . '-' . $id . '.pdf';
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $fn . '"',
        ]);
    }

    /** Build the branded compliance-report HTML for the PDF. */
    private function pdfHtml($r): string
    {
        $agency = DB::table('agencies')->where('id', $r->agency_id)->first();
        $agencyName = $agency->name ?? 'Home Child Care Agency';
        $accent = (isset($agency->brand_primary_color) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $agency->brand_primary_color))
            ? $agency->brand_primary_color : '#1F6080';
        $contactBits = array_filter([$agency->contact_email ?? null, $agency->contact_phone ?? null]);
        $logo = $this->embedLogo($agency->brand_logo_url ?? $agency->logo_url ?? null);

        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $para = fn ($v) => $v !== null && trim((string) $v) !== '' ? nl2br($e($v)) : '<span style="color:#94a3b8;">—</span>';
        $typeLabels = ['initial' => 'Initial visit', 'routine' => 'Routine visit', 'follow_up' => 'Follow-up', 'assessment' => 'Assessment', 'other' => 'Other'];
        $fmtDate = function ($d) { try { return $d ? \Illuminate\Support\Carbon::parse($d)->format('F j, Y') : '—'; } catch (\Throwable $e) { return (string) $d; } };
        $statusLabel = ucfirst((string) $r->status);
        $edited = 0;
        $hist = property_exists($r, 'history') ? (json_decode($r->history ?? '[]', true) ?: []) : [];
        if (is_array($hist)) $edited = count($hist);

        // Detail rows (two-column grid)
        $detail = function ($k, $v) use ($e) {
            return '<tr><td style="padding:5px 12px 5px 0;color:#64748b;font-size:10.5px;white-space:nowrap;vertical-align:top;">' . $e($k) . '</td>'
                . '<td style="padding:5px 0;font-size:11px;color:#0f172a;font-weight:600;vertical-align:top;">' . $e($v ?: '—') . '</td></tr>';
        };
        $details = $detail('Date of visit', $fmtDate($r->visit_date))
            . $detail('Visit type', $typeLabels[$r->visit_type] ?? ($r->visit_type ?: '—'))
            . $detail('Home visitor', $r->home_visitor_name)
            . $detail('Centre', $r->centre_name)
            . $detail('Family / child', trim(($r->family_name ?: '') . ($r->child_name ? ' — ' . $r->child_name : '')) ?: '—')
            . $detail('Who was present', $r->present)
            . $detail('Location', $r->location)
            . $detail('Duration', $r->duration_minutes !== null ? ($r->duration_minutes . ' min') : '—')
            . $detail('Follow-up date', $r->follow_up_date ? $fmtDate($r->follow_up_date) : '—')
            . $detail('Status', $statusLabel . ($edited ? " · edited {$edited}×" : ''));

        // Narrative section helper
        $section = function ($title, $body) {
            return '<div style="margin-top:14px;"><div style="font-size:11.5px;font-weight:800;color:#0f172a;border-bottom:1px solid #e5e7eb;padding-bottom:4px;margin-bottom:6px;">' . $title . '</div>'
                . '<div style="font-size:11px;color:#243244;line-height:1.6;">' . $body . '</div></div>';
        };

        // Audit trail
        $auditHtml = '';
        if ($edited) {
            $rows = '';
            foreach (array_reverse($hist) as $h) {
                $when = isset($h['at']) ? (\Illuminate\Support\Carbon::parse($h['at'])->format('M j, Y g:i A')) : '';
                $by = $e($h['by'] ?? 'Someone');
                $note = ! empty($h['note']) ? ' — “' . $e($h['note']) . '”' : '';
                $chg = '';
                foreach (($h['changes'] ?? []) as $c) {
                    $chg .= '<div style="font-size:10px;color:#475569;margin-left:10px;">• ' . $e($c['field'] ?? '') . ': '
                        . '<span style="color:#b91c1c;text-decoration:line-through;">' . $e($c['from'] ?? '—') . '</span> → '
                        . '<span style="color:#047857;font-weight:700;">' . $e($c['to'] ?? '—') . '</span></div>';
                }
                $rows .= '<div style="padding:6px 0;border-top:1px solid #eef2f6;"><div style="font-size:10.5px;color:#334155;"><strong>' . $by . '</strong> · ' . $e($when) . $note . '</div>' . $chg . '</div>';
            }
            $auditHtml = $section('Edit history (audit trail)', $rows);
        }

        $logoHtml = $logo ? '<img src="' . $logo . '" style="height:46px;max-width:150px;object-fit:contain;">' : '<div style="font-size:18px;font-weight:800;color:#fff;">' . $e($agencyName) . '</div>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,Arial,sans-serif;color:#1f2937;margin:0;font-size:11px;}'
            . '@page{margin:120px 40px 70px 40px;}'
            . '#hdr{position:fixed;top:-96px;left:0;right:0;height:78px;background:' . $accent . ';border-radius:0 0 4px 4px;}'
            . '#ftr{position:fixed;bottom:-52px;left:0;right:0;border-top:1px solid #e5e7eb;padding-top:6px;font-size:8.5px;color:#94a3b8;}'
            . '</style></head><body>'
            // running header band
            . '<div id="hdr"><table style="width:100%;border-collapse:collapse;height:78px;"><tr>'
            . '<td style="padding:0 18px;vertical-align:middle;">' . $logoHtml . '</td>'
            . '<td align="right" style="padding:0 18px;vertical-align:middle;color:#fff;">'
            . '<div style="font-size:15px;font-weight:800;">' . $e($agencyName) . '</div>'
            . '<div style="font-size:9.5px;opacity:.9;">' . $e(implode('  ·  ', $contactBits)) . '</div>'
            . '</td></tr></table></div>'
            // running footer
            . '<div id="ftr"><table style="width:100%;"><tr>'
            . '<td style="font-size:8.5px;color:#94a3b8;">CONFIDENTIAL — ' . $e($agencyName) . ' home-visit compliance record. Generated ' . now(self::TZ_SAFE())->format('M j, Y g:i A') . '.</td>'
            . '</tr></table></div>'
            // title
            . '<div style="border-left:5px solid ' . $accent . ';padding:2px 0 2px 12px;margin-bottom:6px;">'
            . '<div style="font-size:19px;font-weight:800;color:#0f172a;">Home Visit Report</div>'
            . '<div style="font-size:11px;color:#64748b;">' . $e($typeLabels[$r->visit_type] ?? $r->visit_type) . ' · ' . $fmtDate($r->visit_date) . '</div></div>'
            // details grid
            . '<table style="width:100%;border-collapse:collapse;margin-top:8px;">' . $details . '</table>'
            // narratives
            . $section('Visit summary', $para($r->summary))
            . $section('Provider strengths', $para($r->strengths))
            . $section('Concerns / observations', $para($r->concerns))
            . $section('Next steps', $para($r->next_steps))
            . $auditHtml
            // signatures
            . '<div style="margin-top:26px;"><table style="width:100%;"><tr>'
            . '<td style="width:50%;padding-right:16px;"><div style="border-top:1px solid #334155;padding-top:4px;font-size:9.5px;color:#64748b;">Home Visitor signature &amp; date</div></td>'
            . '<td style="width:50%;padding-left:16px;"><div style="border-top:1px solid #334155;padding-top:4px;font-size:9.5px;color:#64748b;">Reviewer (Director / Admin) signature &amp; date</div></td>'
            . '</tr></table></div>'
            . '</body></html>';
    }

    private static function TZ_SAFE(): string { return 'America/Toronto'; }

    /** Fetch a remote logo and return a data: URI (guarded); null if unavailable. */
    private function embedLogo(?string $url): ?string
    {
        if (! $url) return null;
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 4], 'https' => ['timeout' => 4]]);
            $bytes = @file_get_contents($url, false, $ctx);
            if ($bytes === false || strlen($bytes) < 40 || strlen($bytes) > 800000) return null;
            $mime = 'image/png';
            if (str_starts_with($bytes, "\xFF\xD8")) $mime = 'image/jpeg';
            elseif (str_contains(substr($bytes, 0, 200), '<svg')) return null; // dompdf can't inline SVG reliably
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function format($r): array
    {
        return [
            'id'               => (int) $r->id,
            'centre_id'        => $r->centre_id ? (int) $r->centre_id : null,
            'centre_name'      => $r->centre_name ?? null,
            'home_visitor_name' => $r->home_visitor_name ?? null,
            'family_name'      => $r->family_name,
            'child_name'       => $r->child_name,
            'visit_date'       => $r->visit_date,
            'visit_type'       => $r->visit_type,
            'location'         => $r->location,
            'duration_minutes' => $r->duration_minutes !== null ? (int) $r->duration_minutes : null,
            'present'          => $r->present,
            'summary'          => $r->summary,
            'strengths'        => $r->strengths,
            'concerns'         => $r->concerns,
            'next_steps'       => $r->next_steps,
            'follow_up_date'   => $r->follow_up_date,
            'status'           => $r->status,
            'created_at'       => $r->created_at,
            'updated_at'       => $r->updated_at ?? null,
            'history'          => (function () use ($r) {
                $h = property_exists($r, 'history') ? json_decode($r->history ?? '[]', true) : [];
                return is_array($h) ? $h : [];
            })(),
        ];
    }

    /**
     * PATCH /home-visits/{report} — edit a report with an audit trail.
     * Every save appends a history entry (who, when, which fields changed, and an
     * optional note), so changes are traceable. EDITING IS RESTRICTED TO REVIEWERS
     * (agency admin / centre director / platform admin) — the home visitor files
     * the report but only a reviewer may amend it afterward.
     */
    public function update(Request $request, int $report): JsonResponse
    {
        $row = DB::table('home_visit_reports')->where('id', $report)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        $u = $request->user();
        $isReviewer = $this->isReviewer($request);
        $isAuthor = (int) $row->home_visitor_id === (int) $u->id;
        // Reviewers (agency admin / director) may amend any report (audited).
        // The home visitor who filed it may edit their OWN report only while it is
        // still a DRAFT; once submitted, only a reviewer can amend it.
        abort_unless($isReviewer || ($isAuthor && $row->status === 'draft'), 403,
            'You can only edit your own draft — submitted reports are edited by an admin or director.');
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if (! $isPlatform) {
            abort_unless((int) $row->agency_id === (int) $this->agencyId($request), 403);
        }

        $data = $request->validate([
            'centre_id'        => 'nullable|integer',
            'family_name'      => 'nullable|string|max:191',
            'child_name'       => 'nullable|string|max:191',
            'visit_date'       => 'nullable|date',
            'visit_type'       => 'nullable|string|max:40',
            'location'         => 'nullable|string|max:40',
            'duration_minutes' => 'nullable|integer|min:0|max:1440',
            'present'          => 'nullable|string|max:191',
            'summary'          => 'nullable|string',
            'strengths'        => 'nullable|string',
            'concerns'         => 'nullable|string',
            'next_steps'       => 'nullable|string',
            'follow_up_date'   => 'nullable|date',
            'status'           => 'nullable|in:draft,submitted',
            'note'             => 'nullable|string|max:2000',
        ]);

        $labels = [
            'centre_id' => 'Centre', 'family_name' => 'Family name', 'child_name' => 'Child name',
            'visit_date' => 'Visit date', 'visit_type' => 'Visit type', 'location' => 'Location',
            'duration_minutes' => 'Duration', 'present' => 'Who was present', 'summary' => 'Summary',
            'strengths' => 'Strengths', 'concerns' => 'Concerns', 'next_steps' => 'Next steps',
            'follow_up_date' => 'Follow-up date', 'status' => 'Status',
        ];
        $update = [];
        $changes = [];
        foreach (array_keys($labels) as $f) {
            if (!array_key_exists($f, $data)) continue;
            $new = $data[$f];
            $old = $row->$f ?? null;
            if ((string) $new === (string) $old) continue;
            $update[$f] = $new;
            $changes[] = ['field' => $labels[$f], 'from' => (string) ($old ?? ''), 'to' => (string) ($new ?? '')];
        }
        if (!empty($update['centre_id'])) {
            abort_unless(DB::table('centres')->where('id', $update['centre_id'])->where('agency_id', $row->agency_id)->exists(), 422, 'That centre is not in your agency.');
        }

        $note = trim((string) ($data['note'] ?? ''));
        if (empty($changes) && $note === '') {
            return response()->json(['ok' => true, 'report' => $this->format($this->reload($report)), 'unchanged' => true]);
        }

        // Audit trail ONLY for post-submission amendments (a reviewer changing an
        // already-submitted report). Editing a DRAFT — including the author
        // finishing their own draft, or submitting it — is not an "amendment" and
        // is saved silently without a history entry.
        if ($row->status === 'submitted') {
            $byName = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->email ?? 'Someone');
            $hist = json_decode($row->history ?? '[]', true);
            if (!is_array($hist)) $hist = [];
            $hist[] = [
                'at' => now()->toIso8601String(),
                'by_id' => (int) $u->id,
                'by' => $byName,
                'changes' => $changes,
                'note' => $note !== '' ? $note : null,
            ];
            $update['history'] = json_encode($hist);
        }
        $update['updated_at'] = now();
        DB::table('home_visit_reports')->where('id', $report)->update($update);

        return response()->json(['ok' => true, 'report' => $this->format($this->reload($report))]);
    }

    private function reload(int $id)
    {
        return DB::table('home_visit_reports as r')->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('r.id', $id)->first(['r.*', 'c.name as centre_name']);
    }
}
