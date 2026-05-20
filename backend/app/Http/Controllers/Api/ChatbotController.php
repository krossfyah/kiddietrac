<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * v22p63 — AI parent chatbot.
 *  - POST /chatbot/ask         user asks a question
 *  - GET  /chatbot/history     last 20 messages in current session
 *
 * Context: help articles + centre/agency basic info.
 */
final class ChatbotController extends Controller
{
    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => 'required|string|min:3|max:1000',
            'session_id' => 'nullable|string|max:40',
        ]);
        $key = env('ANTHROPIC_API_KEY');
        abort_unless($key, 503, 'Chatbot not configured');

        $u = $request->user();
        $agencyId = (int) (DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->value('agency_id')
            ?: DB::table('guardians')->where('user_id', $u->id)
                ->join('families', 'families.id', '=', 'guardians.family_id')
                ->join('centres', 'centres.id', '=', 'families.centre_id')
                ->value('centres.agency_id'));
        $sessionId = ($data['session_id'] ?? null) ?: Str::uuid()->toString();

        // Pull help articles as context
        $helpDir = base_path('resources/help/director');
        $context = [];
        if (is_dir($helpDir)) {
            $files = glob($helpDir . '/*.md');
            foreach ($files as $f) {
                $body = file_get_contents($f);
                // strip front-matter
                $body = preg_replace('/^---[\s\S]*?---\n/', '', $body);
                $title = basename($f, '.md');
                $context[] = "### {$title}\n" . substr($body, 0, 1500);
            }
        }
        $contextStr = implode("\n\n", array_slice($context, 0, 12));

        // Pull last 5 turns for session continuity
        $history = DB::table('chatbot_messages')
            ->where('user_id', $u->id)
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->limit(10)
            ->get();
        $messages = [];
        foreach ($history as $h) {
            $messages[] = ['role' => $h->role === 'user' ? 'user' : 'assistant', 'content' => $h->body];
        }
        $messages[] = ['role' => 'user', 'content' => $data['question']];

        DB::table('chatbot_messages')->insert([
            'user_id' => $u->id, 'agency_id' => $agencyId, 'session_id' => $sessionId,
            'role' => 'user', 'body' => $data['question'], 'created_at' => now(),
        ]);

        $sys = "You are a helpful assistant for parents of children at a Canadian licensed childcare centre. "
            . "Answer ONLY using the policy context below. If the answer is not in the context, say so and suggest the parent contact the centre directly. "
            . "Keep answers under 150 words. Be warm and specific. Quote relevant policy when you can.\n\n"
            . "POLICY CONTEXT:\n" . $contextStr;

        try {
            $res = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
                'max_tokens' => 600,
                'system' => $sys,
                'messages' => $messages,
            ]);
            if (!$res->ok()) {
                $b = $res->json();
                $msg = $b['error']['message'] ?? $b['message'] ?? 'AI request failed';
                return response()->json(['error' => $msg, 'upstream_status' => $res->status()], 502);
            }
            $answer = trim($res->json('content.0.text') ?? '');
            DB::table('chatbot_messages')->insert([
                'user_id' => $u->id, 'agency_id' => $agencyId, 'session_id' => $sessionId,
                'role' => 'assistant', 'body' => $answer, 'created_at' => now(),
            ]);
            return response()->json([
                'answer' => $answer,
                'session_id' => $sessionId,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $sessionId = $request->query('session_id');
        $u = $request->user();
        $q = DB::table('chatbot_messages')->where('user_id', $u->id);
        if ($sessionId) $q->where('session_id', $sessionId);
        $rows = $q->orderByDesc('created_at')->limit(40)->get()->reverse()->values();
        return response()->json(['data' => $rows]);
    }
}
