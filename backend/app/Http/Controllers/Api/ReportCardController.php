<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * v22p59 — Year-end / term report cards.
 * Narrative paragraphs per HDLH domain are drafted from a child's observations +
 * daily records. When the AI is configured it writes them; when it isn't (or a
 * call fails) a data-grounded template summarises the same logged records so the
 * feature never returns a blank card. Director reviews + edits + sends.
 */
final class ReportCardController extends Controller
{
    use ResolvesCentreContext;

    /** Cards awaiting the caller's (director/admin) approval, in their agency. */
    public function pending(Request $request): JsonResponse
    {
        abort_unless($this->isApprover($request->user()), 403);
        $agencyId = (int) $this->resolveAgencyId($request);
        $rows = DB::table('report_cards as r')
            ->join('children as ch', 'ch.id', '=', 'r.child_id')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.submitted_by_user_id')
            ->where('c.agency_id', $agencyId)
            ->where('r.status', 'submitted')
            ->orderBy('r.submitted_at')
            ->select('r.id', 'r.term', 'r.child_id', 'r.submitted_at',
                DB::raw("TRIM(CONCAT(ch.first_name, ' ', COALESCE(ch.last_name, ''))) as child"),
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as submitted_by"),
                'c.name as centre')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function listForChild(Request $request, int $childId): JsonResponse
    {
        // SECURITY (v22p94): only the child's guardians/centre staff.
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);
        $rows = DB::table('report_cards')->where('child_id', $childId)
            ->orderByDesc('created_at')->get();
        return response()->json(['data' => $rows]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'term' => 'required|string|max:40',
        ]);
        $this->assertStaff($request);
        // SECURITY (v22p94): scope to a child the caller can actually access.
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403);
        abort_unless(DB::table('children')->where('id', $data['child_id'])->exists(), 404);

        $out = $this->draftFor((int) $data['child_id'], (string) $data['term'], (int) $request->user()->id);
        abort_unless($out, 404);

        return response()->json($out);
    }

    /**
     * Draft a report card for one child, with no Request in sight.
     *
     * Split out of generate() so something OTHER than a person pressing the button can
     * ask for one — de-enrolling a family now drafts a leaving report for each child
     * (AdminController::destroyFamily). generate() keeps every bit of the request
     * handling and the authorisation; this does the work.
     *
     * $useAi is how the caller says "not now": on this host the Anthropic calls fail
     * rather than refuse (see the notes on the fallback), and four failing calls per
     * domain per child is a wait a de-enrolment cannot afford. Passing false goes
     * straight to the data-grounded templates, which is what those calls produce here
     * anyway.
     *
     * @return array{id:int,narratives:array,next_steps:string,source:string}|null
     */
    public function draftFor(int $childId, string $term, ?int $byUserId, bool $useAi = true): ?array
    {
        $child = DB::table('children')->where('id', $childId)->first();
        if (! $child) {
            return null;
        }
        $data = ['child_id' => $childId, 'term' => $term];

        // AI is OPTIONAL. If the key is absent (or a call fails) we still produce a
        // data-grounded draft from the child's logged records below — no more hard 503.
        $key = $useAi ? env('ANTHROPIC_API_KEY') : null;

        // Pull the child's logged records. IMPORTANT: observation `domain` values are the
        // real developmental domains (social_emotional, physical, cognitive,
        // language_literacy, creative_arts…), NOT the HDLH foundation names — so we MAP
        // them below. (Previously this filtered observations by the HDLH name directly and
        // matched almost nothing, starving both the AI prompt AND any fallback.)
        $observations = DB::table('observations')->where('child_id', $child->id)
            ->orderByDesc('observed_at')->limit(200)
            ->select('observed_at', 'domain', 'title', 'body')->get();
        // BOTH care tables. The roster quick-log writes daily_events and the care
        // screen writes daily_care_logs; counting only the first meant a report card
        // described a fraction of the child's term — on one child, 12 moments of 54.
        // The two vocabularies are mapped onto one set of buckets so a nap counts as
        // a nap whichever screen recorded it.
        $eventCounts = DB::table('daily_events')->where('child_id', $child->id)
            ->selectRaw('event_type, COUNT(*) as c')->groupBy('event_type')->pluck('c', 'event_type')->toArray();
        if (\Illuminate\Support\Facades\Schema::hasTable('daily_care_logs')) {
            $CARE_MAP = ['meal' => 'meal', 'snack' => 'snack', 'bottle' => 'meal', 'nap' => 'nap',
                'diaper' => 'diaper', 'bathroom' => 'diaper', 'mood' => 'mood', 'sunscreen' => 'activity',
                'outdoor' => 'activity'];
            $careCounts = DB::table('daily_care_logs')->where('child_id', $child->id)
                ->selectRaw('log_type, COUNT(*) as c')->groupBy('log_type')->pluck('c', 'log_type');
            foreach ($careCounts as $type => $n) {
                $bucket = $CARE_MAP[$type] ?? $type;
                $eventCounts[$bucket] = ($eventCounts[$bucket] ?? 0) + (int) $n;
            }
        }
        $eventCounts = collect($eventCounts);

        // HDLH foundation → the real observation domains that feed it.
        $map = [
            'Belonging'  => ['belonging', 'social_emotional', 'social-emotional'],
            'Well-being' => ['well-being', 'wellbeing', 'physical'],
            'Engagement' => ['engagement', 'cognitive'],
            'Expression' => ['expression', 'language_literacy', 'language-literacy', 'creative_arts', 'creative-arts'],
        ];

        $narratives = [];
        foreach (array_keys($map) as $d) {
            $keyName = strtolower(str_replace('-', '', $d));
            $obsForDomain = $observations
                ->filter(fn ($o) => in_array(strtolower((string) $o->domain), $map[$d], true))
                ->values();
            $text = '';
            if ($key) {
                $obsLines = $obsForDomain->take(20)
                    ->map(fn ($o) => '- ' . Carbon::parse($o->observed_at)->format('M j') . ': '
                        . ($o->title ? $o->title . ' — ' : '') . $o->body)->implode("\n");
                $prompt = "Write a warm, specific 4-5 sentence narrative for {$child->first_name}'s report card under the HDLH '{$d}' domain. "
                    . "Use {$child->first_name}'s name naturally. Avoid generic phrases. "
                    . "Mention 1-2 concrete moments from the observations + 1 growth area.\n\n"
                    . "Observations feeding this domain:\n" . ($obsLines ?: '(none logged)');
                try {
                    $res = Http::withHeaders([
                        'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json',
                    ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
                        'max_tokens' => 500,
                        'messages' => [['role' => 'user', 'content' => $prompt]],
                    ]);
                    $text = $res->ok() ? trim($res->json('content.0.text') ?? '') : '';
                } catch (\Throwable $e) {
                    $text = '';
                }
            }
            // Fallback: whenever the AI is unavailable OR returned nothing for this domain,
            // build a data-grounded draft from the child's own logged observations/events so
            // the feature always yields an editable starting narrative (status stays 'draft').
            if ($text === '') {
                $text = $this->templateNarrative($child->first_name, (string) $data['term'], $d, $obsForDomain, $eventCounts);
            }
            $narratives[$keyName] = $text;
        }

        $nextSteps = '';
        if ($key) {
            $nextPrompt = "Based on this child's profile, write 3 specific next-steps suggestions for {$child->first_name}'s continued growth. Return as a short numbered list.";
            try {
                $res = Http::withHeaders([
                    'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json',
                ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
                    'max_tokens' => 400,
                    'messages' => [['role' => 'user', 'content' => $nextPrompt]],
                ]);
                $nextSteps = $res->ok() ? trim($res->json('content.0.text') ?? '') : '';
            } catch (\Throwable $e) {
                $nextSteps = '';
            }
        }
        if ($nextSteps === '') {
            $nextSteps = $this->templateNextSteps($child->first_name, $observations, $map);
        }

        $exists = DB::table('report_cards')->where('child_id', $child->id)->where('term', $data['term'])->first();
        $payload = [
            'child_id' => $child->id,
            'term' => $data['term'],
            'generated_by_user_id' => $byUserId,
            'narrative_belonging' => $narratives['belonging'] ?? '',
            'narrative_wellbeing' => $narratives['wellbeing'] ?? '',
            'narrative_engagement' => $narratives['engagement'] ?? '',
            'narrative_expression' => $narratives['expression'] ?? '',
            'next_steps' => $nextSteps,
            'status' => 'draft',
            'updated_at' => now(),
        ];
        if ($exists) {
            DB::table('report_cards')->where('id', $exists->id)->update($payload);
            $id = $exists->id;
        } else {
            $payload['created_at'] = now();
            $id = DB::table('report_cards')->insertGetId($payload);
        }
        // `source` lets the UI note whether these are AI drafts or logged-record summaries.
        return [
            'id' => $id,
            'narratives' => $narratives,
            'next_steps' => $nextSteps,
            'source' => $key ? 'ai' : 'summary',
        ];
    }

    /**
     * Data-grounded fallback narrative (used when AI is unavailable OR returns nothing for a
     * domain). Summarises the child's REAL logged observations for the mapped HDLH domain so
     * directors get a specific, editable starting draft instead of a blank field.
     */
    private function templateNarrative(string $name, string $term, string $domain, $obs, $eventCounts): string
    {
        $focus = [
            'Belonging'  => 'building relationships and a sense of security',
            'Well-being' => 'self-regulation, physical skills and healthy routines',
            'Engagement' => 'exploring, problem-solving and curiosity',
            'Expression' => 'communication, language and creative expression',
        ][$domain] ?? 'growth and learning';

        $n = $obs->count();
        if ($n === 0) {
            $extra = '';
            if ($domain === 'Well-being') {
                $meals = (int) ($eventCounts['meal'] ?? 0) + (int) ($eventCounts['snack'] ?? 0);
                $naps = (int) ($eventCounts['nap_start'] ?? ($eventCounts['nap'] ?? 0));
                if ($meals || $naps) {
                    $extra = " Daily records show {$name} settling into routines, with {$meals} logged meals/snacks and {$naps} rest periods.";
                }
            }
            return "During {$term}, few formal '{$domain}' observations were recorded for {$name}." . $extra
                . " Educators will focus on capturing more moments of {$name}'s {$focus} next term. "
                . "(Draft generated from logged records — please review and personalise before sending.)";
        }

        $highlights = $obs->take(3)
            ->map(fn ($o) => trim(($o->title ? $o->title . ': ' : '') . (string) $o->body))
            ->filter(fn ($s) => $s !== '')->implode('; ');
        $first = $obs->first();
        $recent = $first ? trim(($first->title ? $first->title . ' — ' : '') . (string) $first->body) : '';

        $s = "Over {$term}, {$name} took part in {$n} recorded experience" . ($n === 1 ? '' : 's')
            . " connected to {$domain} ({$focus}). ";
        if ($highlights !== '') {
            $s .= "Highlights included: {$highlights}. ";
        }
        if ($recent !== '') {
            $s .= "Most recently, educators noted: \"{$recent}\". ";
        }
        $s .= "A continued focus for {$name} is growing confidence in {$focus}.";
        return $s;
    }

    /** Fallback next-steps: target the domain with the fewest logged observations. */
    private function templateNextSteps(string $name, $observations, array $map): string
    {
        $counts = [];
        foreach ($map as $d => $reals) {
            $counts[$d] = $observations->filter(fn ($o) => in_array(strtolower((string) $o->domain), $reals, true))->count();
        }
        asort($counts);
        $weakest = (string) array_key_first($counts);
        $foci = [
            'Belonging'  => 'connection and a sense of belonging',
            'Well-being' => 'self-help routines and active physical play',
            'Engagement' => 'sustained, hands-on exploration',
            'Expression' => 'language-rich conversation and creative activities',
        ];
        $step1 = $foci[$weakest] ?? 'continued all-round development';
        return "1. Continue offering experiences that strengthen {$name}'s {$step1}.\n"
            . "2. Share {$name}'s current interests with the family so learning continues at home.\n"
            . "3. Capture more observations across all four HDLH domains next term to build a fuller picture of {$name}'s growth.";
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = DB::table('report_cards')->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless($this->canAccessChildId($request->user(), (int) $row->child_id), 403); // v22p94
        $this->assertStaff($request);
        $data = $request->validate([
            'narrative_belonging' => 'nullable|string',
            'narrative_wellbeing' => 'nullable|string',
            'narrative_engagement' => 'nullable|string',
            'narrative_expression' => 'nullable|string',
            'next_steps' => 'nullable|string',
            'status' => 'nullable|in:draft,reviewed,submitted,sent',
        ]);
        DB::table('report_cards')->where('id', $id)->update($data + ['updated_at' => now()]);
        return response()->json(['status' => 'updated']);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $row = DB::table('report_cards')->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless($this->canAccessChildId($request->user(), (int) $row->child_id), 403); // v22p94
        // A report card must be approved by a director/admin before it reaches a
        // parent — an educator can only submit it for review (see submit()).
        abort_unless($this->isApprover($request->user()), 403, 'Report cards must be approved by a director or admin before sending.');
        $this->sendToGuardians($row);
        DB::table('report_cards')->where('id', $id)->update([
            'status' => 'sent', 'sent_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['status' => 'sent']);
    }

    /** Educator signs + submits the card for director/admin approval. */
    public function submit(Request $request, int $id): JsonResponse
    {
        $row = DB::table('report_cards')->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless($this->canAccessChildId($request->user(), (int) $row->child_id), 403);
        $this->assertStaff($request);
        $data = $request->validate(['educator_signature' => 'required|string']);
        DB::table('report_cards')->where('id', $id)->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by_user_id' => $request->user()->id,
            'educator_signature' => $data['educator_signature'],
            'review_note' => null,
            'updated_at' => now(),
        ]);
        $this->notifyApprovers($row, $request->user());
        return response()->json(['status' => 'submitted']);
    }

    /** Director/admin signs + approves → card is finalised and sent to the parent. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $row = DB::table('report_cards')->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless($this->canAccessChildId($request->user(), (int) $row->child_id), 403);
        abort_unless($this->isApprover($request->user()), 403, 'Only a director or admin can approve report cards.');
        $data = $request->validate(['admin_signature' => 'required|string']);
        DB::table('report_cards')->where('id', $id)->update([
            'status' => 'sent',
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
            'admin_signature' => $data['admin_signature'],
            'sent_at' => now(),
            'updated_at' => now(),
        ]);
        $this->sendToGuardians($row);
        // Let the educator know it was approved.
        if ($row->submitted_by_user_id) {
            $child = DB::table('children')->where('id', $row->child_id)->first();
            DB::table('notifications')->insert([
                'user_id' => $row->submitted_by_user_id, 'type' => 'report_card_approved',
                'title' => 'Report card approved & sent',
                'body' => ($child->first_name ?? 'The') . "'s {$row->term} report card was approved and sent to the family.",
                'data' => json_encode(['link' => '#report-cards', 'id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['status' => 'sent']);
    }

    /** Director/admin sends it back to the educator with a note (no approval). */
    public function reject(Request $request, int $id): JsonResponse
    {
        $row = DB::table('report_cards')->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless($this->isApprover($request->user()), 403, 'Only a director or admin can review report cards.');
        $data = $request->validate(['note' => 'nullable|string|max:1000']);
        $note = $data['note'] ?? 'Please revise and resubmit.';
        DB::table('report_cards')->where('id', $id)->update([
            'status' => 'draft', 'review_note' => $note, 'admin_signature' => null, 'updated_at' => now(),
        ]);
        if ($row->submitted_by_user_id) {
            $child = DB::table('children')->where('id', $row->child_id)->first();
            DB::table('notifications')->insert([
                'user_id' => $row->submitted_by_user_id, 'type' => 'report_card_changes',
                'title' => 'Report card needs changes',
                'body' => ($child->first_name ?? 'A') . "'s {$row->term} report card: {$note}",
                'data' => json_encode(['link' => '#report-cards', 'id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['status' => 'draft']);
    }

    /** True for director / agency-admin / platform-admin (the approvers). */
    private function isApprover($user): bool
    {
        return DB::table('role_assignments')->where('user_id', $user->id)
            ->whereIn('role', ['centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
    }

    /** Notify the child's agency directors + admins that a card awaits approval. */
    private function notifyApprovers($row, $submitter): void
    {
        $child = DB::table('children')->where('id', $row->child_id)->first();
        if (! $child) {
            return;
        }
        $agencyId = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $child->family_id)->value('c.agency_id');
        if (! $agencyId) {
            return;
        }
        $ids = DB::table('role_assignments')->where('agency_id', $agencyId)
            ->whereIn('role', ['centre_director', 'agency_admin'])->where('active', 1)
            ->pluck('user_id')->unique();
        $by = trim(($submitter->first_name ?? '') . ' ' . ($submitter->last_name ?? '')) ?: 'an educator';
        foreach ($ids as $uid) {
            DB::table('notifications')->insert([
                'user_id' => $uid, 'type' => 'report_card_review',
                'title' => 'Report card awaiting approval',
                'body' => "{$child->first_name}'s {$row->term} report card — submitted by {$by} for your review & signature.",
                'data' => json_encode(['link' => '#report-cards', 'id' => $row->id]),
                'created_at' => now(),
            ]);
            try {
                if (class_exists(\App\Services\FcmService::class)) {
                    \App\Services\FcmService::sendToUser((int) $uid, '📋 Report card to approve', "{$child->first_name}'s {$row->term} report card is awaiting your review.", '#report-cards');
                }
            } catch (\Throwable $e) { /* push is best-effort */ }
        }
    }

    /** Deliver a finalised card to the child's guardians (in-app notification). */
    private function sendToGuardians($row): void
    {
        $child = DB::table('children')->where('id', $row->child_id)->first();
        if (! $child) {
            return;
        }
        $gids = DB::table('guardians')->where('family_id', $child->family_id)->pluck('user_id');
        foreach ($gids as $gid) {
            if (! $gid) {
                continue;
            }
            DB::table('notifications')->insert([
                'user_id' => $gid, 'type' => 'report_card',
                'title' => "{$child->first_name}'s report card is ready",
                'body' => "Term: {$row->term}",
                'data' => json_encode(['link' => '#report-cards', 'id' => $row->id]),
                'created_at' => now(),
            ]);
            try {
                if (class_exists(\App\Services\FcmService::class)) {
                    \App\Services\FcmService::sendToUser((int) $gid, "📋 {$child->first_name}'s report card", "Their {$row->term} report card is ready to view.", '#report-cards');
                }
            } catch (\Throwable $e) { /* best-effort */ }
        }
    }

    public function pdf(Request $request, int $id): Response
    {
        $row = DB::table('report_cards')->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless($this->canAccessChildId($request->user(), (int) $row->child_id), 403); // v22p94
        $child = DB::table('children')->where('id', $row->child_id)->first();
        $family = DB::table('families')->where('id', $child->family_id)->first();
        $agency = DB::table('agencies')->where('id',
            DB::table('centres')->where('id', $family->centre_id)->value('agency_id'))->first();
        $nameOf = function ($uid) {
            if (! $uid) {
                return null;
            }
            $u = DB::table('users')->where('id', $uid)->first();
            return $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email) : null;
        };
        $html = view('pdf.report_card', [
            'card' => $row, 'child' => $child, 'family' => $family, 'agency' => $agency,
            'educatorName' => $nameOf($row->submitted_by_user_id ?? null),
            'adminName' => $nameOf($row->approved_by_user_id ?? null),
        ])->render();
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ReportCard-' . preg_replace('/[^A-Za-z0-9]/', '-', $child->first_name) . '-' . $row->term . '.pdf"',
        ]);
    }

    private function assertStaff(Request $request): void
    {
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($isStaff, 403);
    }
}
