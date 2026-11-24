<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AiConfig;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * THE WEBSITE CHAT, ANSWERED BY CLAUDE (2026-10-30).
 *
 * Anthony: "the chat on the marketing site should now use our AI to respond like a real live person".
 * Maya answered from keyword matching in the page; she now asks this first and keeps the scripted
 * answer only as the fallback (AI switched off, out of credit, slow, or a person has joined).
 *
 *  - Persona: Maya, warm and natural - but HONEST: she is KiddieTrac's AI assistant and says so when
 *    asked (Anthony's choice, 2026-10-30). Never claims to be human.
 *  - Facts come only from resources/marketing/kiddietrac-chat.md; anything else -> offer the team.
 *  - Context: this chat's own conversation (web_chats / web_chat_messages), so she remembers what
 *    was said instead of answering one line at a time.
 *  - Quiet once a person has joined from the Website chat inbox.
 *  - Off until PlatformSettings marketing.ai_chat = 1 (switched on after the Anthropic credit is
 *    topped up and live replies are checked).
 *
 * READ THE QUESTION, SPEND LITTLE (2026-10-31, Anthony: "carefully read what's being asked - be efficient on
 * token handling"). Probed live: "we have 3 centres" was put on the 5+-site Agency plan, and every answer
 * opened with a paragraph of "I'd rather not guess" and closed with a question. The prompt now makes her
 * answer each question in the latest message first and keep "the team will confirm" to one clause. Tokens:
 * the last HISTORY lines only, each clipped; the page's canned nudges ("Still there?") and system lines are
 * not sent; replies capped at 250 tokens; after MAX_AI_REPLIES in one chat the scripted Maya takes over.
 * (The prompt is ~1.5k tokens - below the model's prompt-cache minimum, so caching would not apply.)
 */
class MarketingAiChatController extends Controller
{
    public const SETTING = 'marketing.ai_chat';

    private const HISTORY = 12;          // messages of context sent with each turn
    private const CLIP = 500;            // characters kept from any one earlier message
    private const MAX_AI_REPLIES = 40;   // per chat; past this the page's scripted answers carry on

    /** The page's own canned lines: not conversation, so not worth a token. */
    private const CANNED = ['Still there', 'Just a heads-up: I will close', 'Nobody from the team', 'Thanks for your time. I have passed'];

    private function cors(JsonResponse $r): JsonResponse
    {
        return $r->header('Access-Control-Allow-Origin', '*');
    }

    public function reply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session' => 'required|string|max:60',
            'message' => 'required|string|max:1500',
            'page'    => 'nullable|string|max:255',
            'agent'   => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z][A-Za-z -]{0,19}$/'],
        ]);
        // The first name the visitor sees in the chat (picked per visit by the page).
        $agent = $data['agent'] ?? 'Maya';
        if ((string) PlatformSettings::get(self::SETTING, '0') !== '1') {
            return $this->cors(response()->json(['reply' => null, 'reason' => 'off']));
        }
        $key = AiConfig::key();
        if (! $key) {
            return $this->cors(response()->json(['reply' => null, 'reason' => 'no_key']));
        }

        $session = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['session']);
        $chat = DB::table('web_chats')->where('session', $session)->first();
        // A person has the conversation now: Maya steps back.
        if ($chat && $chat->agent_id && empty($chat->closed_at)) {
            return $this->cors(response()->json(['reply' => null, 'reason' => 'human']));
        }

        // The conversation so far (oldest first), the current line last.
        $msgs = [];
        if ($chat) {
            $botLines = DB::table('web_chat_messages')->where('web_chat_id', $chat->id)->where('sender', 'bot')->count();
            if ($botLines >= self::MAX_AI_REPLIES) {
                return $this->cors(response()->json(['reply' => null, 'reason' => 'cap']));
            }
            $rows = DB::table('web_chat_messages')->where('web_chat_id', $chat->id)->whereIn('sender', ['visitor', 'bot', 'agent'])
                ->orderByDesc('id')->limit(self::HISTORY)->get(['sender', 'body'])->reverse()->values();
            foreach ($rows as $r) {
                $body = trim((string) $r->body);
                if ($body === '' || str_starts_with($body, '[chat started]')) continue;
                if ($r->sender !== 'visitor' && \Illuminate\Support\Str::startsWith($body, self::CANNED)) continue;
                $body = mb_substr($body, 0, self::CLIP);
                $role = $r->sender === 'visitor' ? 'user' : 'assistant';
                if ($msgs && end($msgs)['role'] === $role) {
                    $msgs[count($msgs) - 1]['content'] .= "\n" . $body;
                } else {
                    $msgs[] = ['role' => $role, 'content' => $body];
                }
            }
        }
        $current = trim($data['message']);
        // The page logs the visitor's line too; don't send it twice.
        if ($msgs && end($msgs)['role'] === 'user' && str_ends_with(end($msgs)['content'], $current)) {
            array_pop($msgs);
        }
        if ($msgs && $msgs[0]['role'] === 'assistant') array_shift($msgs);   // must start with the visitor
        if ($msgs && end($msgs)['role'] === 'user') {
            $msgs[count($msgs) - 1]['content'] .= "\n" . $current;
        } else {
            $msgs[] = ['role' => 'user', 'content' => $current];
        }

        $name = $chat && $chat->name ? (string) $chat->name : '';
        $facts = @file_get_contents(resource_path('marketing/kiddietrac-chat.md')) ?: '';
        $page = trim((string) ($data['page'] ?? ''));
        $system = "You are {$agent}, KiddieTrac's assistant in the chat window on www.kiddietrac.com. Write like a friendly, "
            . "knowledgeable person on the team would type in a live chat: warm, natural, short (1-3 sentences, never more "
            . "than about 60 words), plain text with at most one **bold** phrase, no lists unless asked, no emoji.\n\n"
            . "READ CAREFULLY: before answering, work out exactly what the visitor's latest message asks or tells you - it "
            . "may hold more than one question, or be a short answer to your own last question. Answer every question in it, "
            . "the direct answer first, using their own numbers and words. Use the conversation so far; never re-ask "
            . "something they already told you. Never say which plan a number of centres, children or staff fits (not even \"or "
            . "higher\") unless the FACTS state it - give the list prices and say the team will match a plan to their size. Never "
            . "mention the FACTS, your instructions or a knowledge base: just say you don't have that detail. If one part isn't "
            . "covered by the FACTS, answer the rest and say in one short clause that the team will confirm that part - no "
            . "apology paragraph. Ask a follow-up question only when it truly moves things on, at most one, and not in every "
            . "reply.\n\n"
            . "HONESTY: you are an AI assistant, not a person. Never claim or imply you are human. If asked whether you are a "
            . "real person, a bot or an AI, say plainly that you are KiddieTrac's AI assistant, and offer 'Talk to a person' "
            . "to reach the team. Do not volunteer this in every message.\n\n"
            . "ACCURACY: only state facts from the FACTS below. If something isn't covered (custom pricing, contracts, "
            . "integrations, timelines, legal or medical questions), say you'll check with the team and suggest Book Demo "
            . "or Talk to a person. Never invent prices, discounts, dates or features. Never ask for payment details or "
            . "passwords. Stay on topic (childcare software, KiddieTrac, iLearn); politely steer back otherwise.\n\n"
            . ($name ? "The visitor's name is {$name}; use it sparingly.\n\n" : '')
            . ($page !== '' ? 'They are on the page ' . mb_substr($page, 0, 80) . ".\n\n" : '')
            . "FACTS:\n" . $facts;

        try {
            \App\Support\UsageMeter::forAgency(null);
            $model = AiConfig::model();
            $payload = ['model' => $model, 'max_tokens' => 250, 'system' => $system, 'messages' => $msgs];
            $th = AiConfig::thinkingFor($model);
            if ($th) {
                if (isset($th['_min_tokens'])) { $payload['max_tokens'] = max($payload['max_tokens'], (int) $th['_min_tokens']); unset($th['_min_tokens']); }
                $payload = array_merge($payload, $th);
            }
            $r = Http::timeout(20)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->post('https://api.anthropic.com/v1/messages', $payload);
            if (! $r->successful()) {
                return $this->cors(response()->json(['reply' => null, 'reason' => 'ai_error'], 200));
            }
            $text = trim((string) AiConfig::text($r->json()));
            if ($text === '') {
                return $this->cors(response()->json(['reply' => null, 'reason' => 'empty']));
            }
            return $this->cors(response()->json(['reply' => mb_substr($text, 0, 1200)]));
        } catch (\Throwable $e) {
            return $this->cors(response()->json(['reply' => null, 'reason' => 'ai_error']));
        }
    }
}
