<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AiBundle;
use App\Support\UsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI writing help - part of the AI Bundle (2026-10-28).
 *
 * Anthony: "utilizing AI to generate daily summaries, emails and assisting with observation entries ... do not impact
 * how things are done today but add this as an enhancement". So this only ever SUGGESTS text: the screens put a
 * "✨ AI" menu beside the box (kt-ai-assist.js), the reply replaces nothing until the person chooses to use it, and
 * nothing is sent or saved from here.
 *
 * POST /ai/assist/write {kind, action, text, language?}
 *   kind:   announcement | message | email | incident
 *   action: draft (turn rough notes into a finished piece) | improve | shorter | friendlier | formal | translate
 * Gated by ai_tools (EnforcePlanFeatures: 'ai/assist'); staff roles only (route group).
 */
class AiAssistController extends Controller
{
    private const KINDS = [
        'announcement' => 'an announcement from a childcare centre to all of its families',
        'message' => 'a message from a childcare educator or director to a parent',
        'email' => 'an email from a childcare centre',
        'incident' => 'the description in a childcare incident report that the child\'s family will read',
    ];
    private const ACTIONS = [
        'draft' => 'Turn the notes below into a finished, ready-to-send piece. Use only the facts in the notes.',
        'improve' => 'Improve the clarity, spelling, grammar and flow of the text below. Keep its meaning, facts and length about the same.',
        'shorter' => 'Make the text below noticeably shorter and easier to read, keeping every important fact.',
        'friendlier' => 'Make the text below warmer and friendlier, keeping it professional and keeping every fact.',
        'formal' => 'Make the text below more formal and professional, keeping every fact.',
        'translate' => 'Translate the text below into %s. Keep names, times, dates and numbers exactly as they are.',
    ];
    private const LANGUAGES = ['French' => 'French', 'Spanish' => 'Spanish', 'Hindi' => 'Hindi', 'English' => 'English',
        'Punjabi' => 'Punjabi', 'Urdu' => 'Urdu', 'Arabic' => 'Arabic', 'Tagalog' => 'Tagalog', 'Portuguese' => 'Portuguese', 'Mandarin Chinese' => 'Mandarin Chinese'];

    public function write(Request $request): JsonResponse
    {
        $d = $request->validate([
            'kind' => ['required', 'in:' . implode(',', array_keys(self::KINDS))],
            'action' => ['required', 'in:' . implode(',', array_keys(self::ACTIONS))],
            'text' => ['required', 'string', 'min:3', 'max:6000'],
            'language' => ['nullable', 'string', 'max:40'],
        ]);
        $agencyId = $this->resolveAgencyId($request);
        // The route map gates ai/assist; this is the same answer for the AGENCY the request is about.
        abort_unless(AiBundle::on($agencyId), 403, 'AI writing help is part of the AI Bundle.');
        $lang = $d['action'] === 'translate' ? (self::LANGUAGES[$d['language'] ?? ''] ?? null) : null;
        if ($d['action'] === 'translate' && ! $lang) {
            return response()->json(['message' => 'Choose a language to translate into.'], 422);
        }

        $key = config('services.anthropic.key') ?: config('services.anthropic.api_key');
        if (! $key) {
            return response()->json(['message' => 'AI writing help is not available right now.'], 503);
        }

        $rules = 'You help staff at a licensed childcare centre write. You are writing ' . self::KINDS[$d['kind']] . '. '
            . 'Never invent facts, names, times, amounts, medical details or promises that are not in the text you are given. '
            . 'Never add placeholders like [Name]. Use plain, kind, everyday language a busy parent understands. '
            . 'Keep the writer\'s own words for people, places and things (if they wrote "sandbox", it stays "sandbox"). '
            . 'Write in the same language as the text unless asked to translate. '
            . 'Reply with ONLY the finished text - no preamble, no quotation marks, no notes about what you changed.';
        if ($d['kind'] === 'incident') {
            $rules .= ' For an incident report: describe only what happened, in the order it happened, neutrally and factually; '
                . 'no blame, no guessing at causes, no medical diagnosis, no minimising or dramatising; '
                . 'keep what was done in response (first aid, who was told) if it is in the text.';
        }
        $task = sprintf(self::ACTIONS[$d['action']], $lang ?? '');

        UsageMeter::forAgency($agencyId);   // the call is metered to this agency (ai_usage)
        try {
            if ($d['action'] === 'draft') \App\Support\AiFresh::as('draft.' . $d['kind'], $agencyId);   // a new piece: fresh writing (AiFresh); edits of the user's own text are left alone
            $r = Http::timeout(40)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.model') ?: 'claude-haiku-4-5-20251001',
                    'max_tokens' => 1200,
                    'system' => $rules,
                    'messages' => [['role' => 'user', 'content' => $task . "\n\n---\n" . $d['text']]],
                ]);
        } catch (\Throwable $e) {
            Log::warning('AI assist: no answer', ['agency' => $agencyId, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'The AI did not answer just now. Please try again in a moment.'], 503);
        }
        if (! $r->successful()) {
            Log::warning('AI assist failed', ['agency' => $agencyId, 'status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);

            return response()->json(['message' => 'AI writing help is not available right now. Please try again later.'], 503);
        }
        $text = trim((string) (\App\Support\AiConfig::text($r->json()) ?? ''));
        if ($text === '') {
            return response()->json(['message' => 'The AI did not suggest anything. Try adding a little more detail.'], 422);
        }

        return response()->json(['text' => $text, 'kind' => $d['kind'], 'action' => $d['action']]);
    }

    /** Same rule as ConferenceController::resolveAgencyId (v22p94/v22p98): the header only when the user holds that agency. */
    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) {
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
