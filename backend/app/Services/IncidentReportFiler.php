<?php

namespace App\Services;

use App\Models\Incident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Renders an incident report to PDF and files it on the child's record.
 *
 * The report existed only as a button — a director could build one on demand, but
 * nothing kept a copy, so there was no filed document a parent could open later
 * and nothing on the child's record showing a report had ever been produced.
 *
 * WHERE IT LANDS. One row in `documents`, scoped to the CHILD, in the existing
 * `child-documents` folder. Deliberately not two rows (child and family): two
 * copies of one file drift, and the family view can read its children's documents
 * without a second record to keep in step. `child-documents` is already in
 * ProtectedMedia::FOLDERS, so the file is denied to direct access and every URL
 * the API hands out is signed and expiring — a report of a child's injury must
 * never sit on a guessable public path.
 *
 * WHEN. Twice, and it replaces rather than accumulates:
 *   parent_notified  the family has been told, so there is a report to share
 *   closed           the final version, carrying the closing sign-off
 * A third copy on every status change would leave a family scrolling six near
 * identical PDFs trying to find the current one.
 */
class IncidentReportFiler
{
    public const CATEGORY = 'incident_report';

    /**
     * Render, store, and record the report. Returns the documents row id, or null
     * if anything went wrong — this is called from notify and close, and neither
     * of those should fail because a PDF could not be written.
     */
    public static function file(Incident $incident): ?int
    {
        try {
            $child = DB::table('children')->where('id', $incident->child_id)->first();
            if (! $child) {
                return null;
            }

            $centreId = DB::table('rooms')->where('id', $incident->room_id)->value('centre_id');
            $centre = $centreId ? DB::table('centres')->where('id', $centreId)->first() : null;
            $agency = ($centre->agency_id ?? null)
                ? DB::table('agencies')->where('id', $centre->agency_id)->first() : null;
            $recorder = DB::table('users')->where('id', $incident->recorded_by_id)->first();
            $notes = DB::table('incident_notes')->where('incident_id', $incident->id)
                ->orderBy('created_at')->get();

            $logo = $agency->brand_logo_url ?? ($agency->logo_url ?? null);
            if ($logo && ! preg_match('~^https?://~i', (string) $logo)) {
                $logo = 'https://app.kiddietrac.com/' . ltrim((string) $logo, '/');
            }

            $html = view('pdf.incident-report', [
                'incident' => $incident,
                'child'    => $child,
                'centre'   => $centre,
                'agency'   => $agency,
                'recorder' => $recorder,
                'notes'    => $notes,
                'logo'     => $logo,
                /* This copy goes into the family's documents, so the template
                   omits staff-internal notes and narrows the contact log to
                   contact with the family. The on-demand PDF a director builds
                   still renders the full record. */
                'audience' => 'parent',
            ])->render();

            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('letter', 'portrait');
            $dompdf->render();
            $bytes = $dompdf->output();

            $ref = 'INC-' . str_pad((string) $incident->id, 5, '0', STR_PAD_LEFT);
            $path = 'child-documents/' . $incident->child_id . '/incident-report-'
                . strtolower($ref) . '-' . now()->format('Ymd-His') . '.pdf';

            Storage::disk('public')->put($path, $bytes);
            $url = '/storage/' . $path;

            /* occurred_at is WALL CLOCK — the time the educator typed. Named in the
               title as stored, never converted, so the document a parent opens says
               the same time as the report inside it. */
            $when = $incident->occurred_at
                ? \Illuminate\Support\Carbon::parse($incident->occurred_at)->format('j M Y')
                : now()->format('j M Y');

            $title = 'Incident report — ' . $when . ' (' . $ref . ')';

            /* Replace, don't accumulate: the closing version supersedes the one
               written when the family was notified. The old file is removed so a
               superseded report cannot be opened from a stale signed link. */
            $existing = DB::table('documents')
                ->where('scope_type', 'child')->where('scope_id', $incident->child_id)
                ->where('category', self::CATEGORY)
                ->where('title', 'like', '%' . $ref . '%')
                ->get(['id', 'file_url']);

            foreach ($existing as $old) {
                $rel = ltrim(str_replace('/storage/', '', (string) $old->file_url), '/');
                try {
                    if ($rel && Storage::disk('public')->exists($rel)) {
                        Storage::disk('public')->delete($rel);
                    }
                } catch (\Throwable $e) { /* an orphan file is not worth failing over */ }
            }
            DB::table('documents')->whereIn('id', $existing->pluck('id'))->delete();

            $docId = DB::table('documents')->insertGetId([
                'scope_type'     => 'child',
                'scope_id'       => $incident->child_id,
                'category'       => self::CATEGORY,
                'title'          => $title,
                'file_url'       => $url,
                'file_type'      => 'application/pdf',
                'file_size'      => strlen($bytes),
                'uploaded_by_id' => $incident->recorded_by_id,
                'created_at'     => now(),
            ]);

            return $docId;
        } catch (\Throwable $e) {
            /* Never let filing the copy cost the thing that triggered it: telling a
               parent about their child's injury matters more than the PDF. */
            Log::warning('incident report filing failed', [
                'incident' => $incident->id,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }
}
