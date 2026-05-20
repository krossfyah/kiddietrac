<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

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
 * AI generates narrative paragraphs per HDLH domain from a child's
 * observations + milestones. Director reviews + edits + sends.
 */
final class ReportCardController extends Controller
{
    public function listForChild(Request $request, int $childId): JsonResponse
    {
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
        $child = DB::table('children')->where('id', $data['child_id'])->first();
        abort_unless($child, 404);

        $key = env('ANTHROPIC_API_KEY');
        if (!$key) return response()->json(['error' => 'AI not configured'], 503);

        $observations = DB::table('observations')->where('child_id', $child->id)
            ->where('framework', 'HDLH')->orderByDesc('observed_at')->limit(100)
            ->select('observed_at', 'domain', 'body')->get();
        $milestones = DB::table('milestone_records')->where('child_id', $child->id)
            ->select('domain', 'milestone_label', 'status', 'observed_at')->get();

        $domains = ['Belonging', 'Well-being', 'Engagement', 'Expression'];
        $narratives = [];
        foreach ($domains as $d) {
            $obs = $observations->where('domain', $d)->take(20)
                ->map(fn ($o) => '- ' . Carbon::parse($o->observed_at)->format('M j') . ': ' . $o->body)->implode("\n");
            $ms = $milestones->where('domain', $d)
                ->map(fn ($m) => '- ' . $m->milestone_label . ' (' . $m->status . ')')->implode("\n");
            $prompt = "Write a warm, specific 4-5 sentence narrative for {$child->first_name}'s report card under the HDLH '{$d}' domain. "
                . "Use {$child->first_name}'s name naturally. Avoid generic phrases. "
                . "Mention 1-2 concrete moments from the observations + 1 growth area.\n\n"
                . "Observations in this domain:\n{$obs}\n\nMilestones in this domain:\n{$ms}";
            try {
                $res = Http::withHeaders([
                    'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json',
                ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
                    'max_tokens' => 500,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
                $narratives[strtolower(str_replace('-', '', $d))] = $res->ok() ? trim($res->json('content.0.text') ?? '') : '';
            } catch (\Throwable $e) {
                $narratives[strtolower(str_replace('-', '', $d))] = '';
            }
        }
        // Next steps from all domains
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
        } catch (\Throwable $e) { $nextSteps = ''; }

        $exists = DB::table('report_cards')->where('child_id', $child->id)->where('term', $data['term'])->first();
        $payload = [
            'child_id' => $child->id,
            'term' => $data['term'],
            'generated_by_user_id' => $request->user()->id,
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
        return response()->json(['id' => $id, 'narratives' => $narratives, 'next_steps' => $nextSteps]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = DB::table('report_cards')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertStaff($request);
        $data = $request->validate([
            'narrative_belonging' => 'nullable|string',
            'narrative_wellbeing' => 'nullable|string',
            'narrative_engagement' => 'nullable|string',
            'narrative_expression' => 'nullable|string',
            'next_steps' => 'nullable|string',
            'status' => 'nullable|in:draft,reviewed,sent',
        ]);
        DB::table('report_cards')->where('id', $id)->update($data + ['updated_at' => now()]);
        return response()->json(['status' => 'updated']);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $row = DB::table('report_cards')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertStaff($request);
        DB::table('report_cards')->where('id', $id)->update([
            'status' => 'sent', 'sent_at' => now(), 'updated_at' => now(),
        ]);
        // Notify guardians
        $child = DB::table('children')->where('id', $row->child_id)->first();
        $gids = DB::table('guardians')->where('family_id', $child->family_id)->pluck('user_id');
        foreach ($gids as $gid) {
            DB::table('notifications')->insert([
                'user_id' => $gid, 'type' => 'report_card',
                'title' => "{$child->first_name}'s report card is ready",
                'body' => "Term: {$row->term}",
                'data' => json_encode(['link' => '#report-cards', 'id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['status' => 'sent']);
    }

    public function pdf(Request $request, int $id): Response
    {
        $row = DB::table('report_cards')->where('id', $id)->first();
        abort_unless($row, 404);
        $child = DB::table('children')->where('id', $row->child_id)->first();
        $family = DB::table('families')->where('id', $child->family_id)->first();
        $agency = DB::table('agencies')->where('id',
            DB::table('centres')->where('id', $family->centre_id)->value('agency_id'))->first();
        $html = view('pdf.report_card', [
            'card' => $row, 'child' => $child, 'family' => $family, 'agency' => $agency,
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
