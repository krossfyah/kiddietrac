<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Live chat between marketing-site visitors and the KiddieTrac team.
 *
 * The page (www.kiddietrac.com) talks to the PUBLIC half: it records each line
 * (through MarketingSiteController::logChat, which already did the JSONL log) and
 * polls for what the team has said. The portal talks to the STAFF half under
 * /sales/web-chats, behind the sales_rep / platform_admin routes.
 *
 * Maya, the scripted assistant, still lives in the page. The server only tells the
 * page whether anyone is online and whether a person has taken the chat; the page
 * decides when Maya speaks, and she always says she is the virtual assistant.
 *
 * Every timestamp here is written and compared from PHP's clock. MySQL's NOW() runs
 * on a different zone on this host (see the audit two-clocks note), so no query in
 * this file uses it.
 */
class WebChatController extends Controller
{
    /** An agent counts as online when available and their portal checked in this recently. */
    private const ONLINE_SECONDS = 120;
    /** Typing stamps older than this are stale - the other side stopped. */
    private const TYPING_SECONDS = 6;
    /** The visitor's page polls every few seconds; silence longer than this means they left. */
    private const PRESENT_SECONDS = 45;

    // ─────────────────────────────── shared ───────────────────────────────

    public static function cleanSession(?string $s): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $s), 0, 64);
    }

    private static function now(): string
    {
        return now()->toDateTimeString();
    }

    private static function iso(?string $s): ?string
    {
        if (! $s) {
            return null;
        }
        try {
            return Carbon::parse($s, config('app.timezone'))->utc()->toIso8601ZuluString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function recent(?string $s, int $seconds): bool
    {
        if (! $s) {
            return false;
        }
        try {
            return Carbon::parse($s, config('app.timezone'))->gt(now()->subSeconds($seconds));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Agents taking chats right now, as user ids. */
    private static function onlineAgentIds(): array
    {
        return DB::table('web_chat_agents')->where('available', true)
            ->where('last_seen_at', '>', now()->subSeconds(self::ONLINE_SECONDS)->toDateTimeString())
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
    }

    /** The name a visitor sees: first name only, the way a person introduces themselves. */
    private static function firstName(?User $u): string
    {
        $n = trim((string) ($u->first_name ?? ''));
        return $n !== '' ? $n : 'A team member';
    }

    private static function addMessage(int $chatId, string $sender, string $body, ?int $userId = null, ?string $author = null): int
    {
        return (int) DB::table('web_chat_messages')->insertGetId([
            'web_chat_id' => $chatId,
            'sender'      => $sender,
            'user_id'     => $userId,
            'author'      => $author,
            'body'        => $body,
            'created_at'  => self::now(),
        ]);
    }

    private static function messageOut(object $m): array
    {
        return [
            'id'     => (int) $m->id,
            'sender' => $m->sender,
            'author' => $m->author,
            'body'   => $m->body,
            'at'     => self::iso($m->created_at),
        ];
    }

    // ─────────────────────────────── public (the website) ───────────────────────────────

    /**
     * Called from logChat for every line the page records. Creates the conversation on
     * its first line and alerts the team when a visitor first speaks.
     *
     * Returns the stored message id, or null if the table is not there yet - logChat
     * must keep working on the JSONL log regardless.
     */
    public static function record(Request $request, string $session, string $name, string $email, string $sender, string $message, ?string $page = null): ?int
    {
        try {
            if (strlen($session) < 8) {
                return null;
            }
            $now = self::now();
            $chat = DB::table('web_chats')->where('session', $session)->first();
            if (! $chat) {
                $id = DB::table('web_chats')->insertGetId([
                    'session'    => $session,
                    'name'       => $name ?: null,
                    'email'      => $email ?: null,
                    'status'     => 'open',
                    'ip'         => substr((string) $request->ip(), 0, 45),
                    'page'       => $page ? mb_substr($page, 0, 255) : null,
                    'visitor_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $chat = DB::table('web_chats')->where('id', $id)->first();
            }

            $upd = ['updated_at' => $now];
            if ($name !== '' && $name !== $chat->name) {
                $upd['name'] = $name;
            }
            if ($email !== '' && $email !== $chat->email) {
                $upd['email'] = $email;
            }
            $author = null;
            if ($sender === 'visitor') {
                $upd['last_visitor_at'] = $now;
                $upd['visitor_seen_at'] = $now;
                $upd['visitor_typing_at'] = null;
                // A visitor writing into a closed chat has come back: reopen it rather
                // than start a second thread the team has to stitch together.
                if ($chat->status !== 'open') {
                    $upd['status'] = 'open';
                    $upd['closed_at'] = null;
                }
            } else {
                $author = 'Maya';
            }
            DB::table('web_chats')->where('id', $chat->id)->update($upd);
            $mid = self::addMessage((int) $chat->id, $sender, $message, null, $author);

            // The pre-chat line ("[chat started] name <email>") is not somebody waiting
            // for an answer yet; the first real question is.
            $isQuestion = $sender === 'visitor' && strpos($message, '[chat started]') !== 0;
            if ($isQuestion && ! $chat->alerted_at && ! $chat->agent_id) {
                DB::table('web_chats')->where('id', $chat->id)->whereNull('alerted_at')->update(['alerted_at' => $now]);
                self::alertTeam((int) $chat->id, false);
            }

            return $mid;
        } catch (\Throwable $e) {
            Log::warning('web chat record failed: '.$e->getMessage());
            return null;
        }
    }

    /**
     * GET /marketing-site/chat/poll?session=&after=&typing=1&full=1
     *
     * What the page needs every few seconds: new lines from the team, whether a
     * person has the chat, whether they are typing, and whether anyone is online at
     * all. A GET with no custom headers, so it never costs a CORS preflight.
     */
    public function poll(Request $request): JsonResponse
    {
        $session = self::cleanSession($request->query('session'));
        $after = max(0, (int) $request->query('after', 0));
        $online = count(self::onlineAgentIds()) > 0;
        $out = ['ok' => true, 'online' => $online, 'agent' => null, 'typing' => false, 'status' => null, 'messages' => []];

        // A short id is one of the old, guessable ones. It gets the online flag and
        // nothing that was said in anybody's conversation.
        if (strlen($session) < 16) {
            return $this->cors(response()->json($out));
        }
        $chat = DB::table('web_chats')->where('session', $session)->first();
        if (! $chat) {
            return $this->cors(response()->json($out));
        }

        $upd = ['visitor_seen_at' => self::now()];
        if ($request->query('typing') === '1') {
            $upd['visitor_typing_at'] = self::now();
        }
        DB::table('web_chats')->where('id', $chat->id)->update($upd);

        // full=1 is the page coming back after a reload: it needs the whole thread to
        // redraw, not just what the team said since.
        $q = DB::table('web_chat_messages')->where('web_chat_id', $chat->id)->orderBy('id');
        if ($request->query('full') === '1') {
            $q->limit(300);
        } else {
            $q->where('id', '>', $after)->whereIn('sender', ['agent', 'system'])->limit(100);
        }
        $out['messages'] = $q->get()->map(fn ($m) => self::messageOut($m))->all();
        $out['status'] = $chat->status;
        if ($chat->agent_id && $chat->status === 'open') {
            $out['agent'] = ['name' => $chat->agent_name ?: 'KiddieTrac team'];
        }
        $out['typing'] = $chat->status === 'open' && self::recent($chat->staff_typing_at, self::TYPING_SECONDS);
        if ($out['typing']) {
            $out['typing_name'] = $chat->staff_typing_name ?: ($chat->agent_name ?: null);
        }

        return $this->cors(response()->json($out));
    }

    /**
     * POST /marketing-site/chat/human {session}
     *
     * The visitor pressed "Talk to a person". Flag the chat so it rises to the top of
     * the inbox, and alert the team again - this is the moment somebody asked.
     */
    public function wantHuman(Request $request): JsonResponse
    {
        $session = self::cleanSession($request->input('session'));
        $chat = strlen($session) >= 16 ? DB::table('web_chats')->where('session', $session)->first() : null;
        if (! $chat) {
            return $this->cors(response()->json(['ok' => false, 'online' => count(self::onlineAgentIds()) > 0]));
        }
        $online = count(self::onlineAgentIds()) > 0;
        if (! $chat->wants_human) {
            // No system line: the page tells the visitor itself, and the inbox shows the
            // request as a "Wants a person" chip. A system line would reach both.
            DB::table('web_chats')->where('id', $chat->id)->update(['wants_human' => true, 'updated_at' => self::now()]);
            self::alertTeam((int) $chat->id, true);
        }

        return $this->cors(response()->json(['ok' => true, 'online' => $online]));
    }

    /** Marks the chat closed when the visitor ends it with the ✕ (not when they merely leave). */
    public static function visitorEnded(string $session): void
    {
        try {
            $chat = DB::table('web_chats')->where('session', $session)->first();
            if (! $chat || $chat->status !== 'open') {
                return;
            }
            DB::table('web_chats')->where('id', $chat->id)->update([
                'status' => 'closed', 'closed_at' => self::now(), 'updated_at' => self::now(),
                'visitor_typing_at' => null,
            ]);
            self::addMessage((int) $chat->id, 'system', 'The visitor ended the chat.');
        } catch (\Throwable $e) {
            Log::warning('web chat end failed: '.$e->getMessage());
        }
    }

    /** The stored conversation, in the shape endChat's transcript email renders. */
    public static function transcriptFor(string $session): array
    {
        try {
            $chat = DB::table('web_chats')->where('session', $session)->first();
            if (! $chat) {
                return [];
            }
            return DB::table('web_chat_messages')->where('web_chat_id', $chat->id)->orderBy('id')->limit(400)->get()
                ->filter(fn ($m) => strpos((string) $m->body, '[chat started]') !== 0)
                ->map(function ($m) {
                    return [
                        'sender'  => $m->sender,
                        'author'  => $m->author,
                        'message' => (string) $m->body,
                        'at'      => $m->created_at ? Carbon::parse($m->created_at, config('app.timezone'))->setTimezone('America/Toronto')->format('g:i A') : null,
                    ];
                })->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function cors(JsonResponse $r): JsonResponse
    {
        return $r->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'no-store');
    }

    // ─────────────────────────────── alerts ───────────────────────────────

    /**
     * Tell the team somebody is waiting: a phone push, and an email when nobody is
     * online to see it in the portal.
     *
     * Deferred until after the response - FCM is a network call per recipient, and
     * the visitor's message must not wait on it (see the check-in/push notes). Not
     * queued: the worker runs once a minute, and a minute is too long for a chat.
     */
    private static function alertTeam(int $chatId, bool $askedForPerson): void
    {
        $run = function () use ($chatId, $askedForPerson) {
            try {
                $chat = DB::table('web_chats')->where('id', $chatId)->first();
                if (! $chat) {
                    return;
                }
                $last = DB::table('web_chat_messages')->where('web_chat_id', $chatId)->where('sender', 'visitor')
                    ->where('body', 'not like', '[chat started]%')->orderByDesc('id')->value('body');
                $who = $chat->name ?: ($chat->email ?: 'A visitor');
                $title = ($askedForPerson ? '🙋 ' : '💬 ').$who.($askedForPerson ? ' wants to talk to a person' : ' is chatting on the website');
                $body = $last ? mb_substr((string) $last, 0, 140) : 'Open the website chat to reply.';

                $online = self::onlineAgentIds();
                // Online agents first; nobody online means everybody who can answer.
                $ids = $online ?: User::whereHas('roleAssignments', fn ($q) => $q->whereIn('role', ['platform_admin', 'sales_rep']))
                    ->where(function ($q) { $q->whereNull('status')->orWhere('status', 'active'); })
                    ->pluck('id')->map(fn ($v) => (int) $v)->all();
                $fcm = app(\App\Services\FcmService::class);
                foreach (array_unique($ids) as $uid) {
                    try {
                        $fcm->sendToUser($uid, $title, $body, '#sales-webchat?id='.$chatId, true);
                    } catch (\Throwable $e) {
                    }
                }

                if (! $online) {
                    self::emailWaiting($chat, (string) $last, $askedForPerson);
                }
            } catch (\Throwable $e) {
                Log::warning('web chat alert failed: '.$e->getMessage());
            }
        };

        if (app()->runningInConsole()) {
            $run();
        } else {
            dispatch($run)->afterResponse();
        }
    }

    /**
     * Nobody online: an email to the address configured for chat transcripts, so a
     * waiting visitor reaches a person's inbox. Only the configured address - if it is
     * not set, the push above is all there is, and that is logged.
     */
    private static function emailWaiting(object $chat, string $last, bool $askedForPerson): void
    {
        $cfg = [];
        try {
            if (\Illuminate\Support\Facades\Storage::disk('local')->exists('marketing-site.json')) {
                $cfg = json_decode((string) \Illuminate\Support\Facades\Storage::disk('local')->get('marketing-site.json'), true) ?: [];
            }
        } catch (\Throwable $e) {
        }
        $to = trim((string) ($cfg['chat_transcript_to'] ?? ''));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Log::info('web chat: nobody online and no chat_transcript_to configured; push only for chat '.$chat->id);
            return;
        }
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $who = $chat->name ?: 'A visitor';
        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;color:#0f172a">'
            .'<h2 style="margin:0 0 4px;font-size:19px">'.($askedForPerson ? '🙋 Somebody asked for a person' : '💬 Somebody is chatting on the website').'</h2>'
            .'<p style="margin:0 0 16px;color:#64748b;font-size:13.5px">Nobody is marked available for website chat, so Maya (the virtual assistant) is answering for now. Reply from the portal and you take the conversation over.</p>'
            .'<p style="margin:0 0 6px;font-size:14px"><b>'.$e($who).'</b>'.($chat->email ? ' &lt;'.$e($chat->email).'&gt;' : '').'</p>'
            .($last !== '' ? '<div style="background:#ecfeff;border:1px solid #a5f3fc;border-radius:9px;padding:10px 12px;font-size:14px;line-height:1.55;white-space:pre-wrap">'.$e($last).'</div>' : '')
            .'<p style="margin:18px 0 0"><a href="https://app.kiddietrac.com/dashboard.html#sales-webchat?id='.(int) $chat->id.'" style="background:#1F6080;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:700">Open the chat →</a></p>'
            .'</div>';
        try {
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($to, $who, $chat) {
                $m->to($to)->subject('Website chat waiting — '.$who);
                if ($chat->email && filter_var($chat->email, FILTER_VALIDATE_EMAIL)) {
                    $m->replyTo($chat->email, $chat->name ?: $chat->email);
                }
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
        } catch (\Throwable $ex) {
            Log::warning('web chat waiting mail failed: '.$ex->getMessage());
        }
    }

    // ─────────────────────────────── staff (the portal) ───────────────────────────────

    /**
     * POST /sales/web-chats/presence {available?}
     *
     * The portal's heartbeat for anyone who can take chats. Keeps them "online",
     * optionally flips their availability, and answers with what the badge and the
     * new-chat alert need - one request, not three.
     */
    public function presence(Request $request): JsonResponse
    {
        $uid = (int) auth()->id();
        $row = DB::table('web_chat_agents')->where('user_id', $uid)->first();
        $available = $row ? (bool) $row->available : true;
        if ($request->has('available')) {
            $available = $request->boolean('available');
        }
        DB::table('web_chat_agents')->updateOrInsert(['user_id' => $uid], [
            'available' => $available, 'last_seen_at' => self::now(), 'updated_at' => self::now(),
        ]);

        // Waiting = open, the visitor spoke last, and nobody else has it.
        $open = DB::table('web_chats')->where('status', 'open')
            ->where(function ($q) use ($uid) { $q->whereNull('agent_id')->orWhere('agent_id', $uid); })
            ->whereNotNull('last_visitor_at')
            ->where('updated_at', '>', now()->subDay()->toDateTimeString())
            ->orderByDesc('last_visitor_at')->limit(50)
            ->get(['id', 'name', 'email', 'last_visitor_at', 'last_staff_at', 'staff_read_id', 'wants_human', 'agent_id', 'visitor_seen_at']);
        $waiting = [];
        foreach ($open as $c) {
            $lastVisitorMsg = DB::table('web_chat_messages')->where('web_chat_id', $c->id)->where('sender', 'visitor')->max('id');
            if ($lastVisitorMsg && $lastVisitorMsg > (int) $c->staff_read_id) {
                $waiting[] = [
                    'id' => (int) $c->id, 'name' => $c->name ?: ($c->email ?: 'Visitor'),
                    'latest' => (int) $lastVisitorMsg, 'wants_human' => (bool) $c->wants_human,
                    'preview' => mb_substr((string) DB::table('web_chat_messages')->where('id', $lastVisitorMsg)->value('body'), 0, 120),
                    'present' => self::recent($c->visitor_seen_at, self::PRESENT_SECONDS),
                ];
            }
        }

        return response()->json([
            'available' => $available,
            'online'    => count(self::onlineAgentIds()),
            'unread'    => count($waiting),
            'waiting'   => $waiting,
        ]);
    }

    /** GET /sales/web-chats?status=open|closed|all */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'open');
        $q = DB::table('web_chats');
        if ($status === 'open' || $status === 'closed') {
            $q->where('status', $status);
        }
        // Somebody who opened the widget and never said a word is not a conversation.
        $q->whereNotNull('last_visitor_at');
        $rows = $q->orderByDesc('updated_at')->limit(200)->get();
        $ids = $rows->pluck('id')->all();

        $lastMsg = [];
        $lastVisitor = [];
        if ($ids) {
            $maxIds = DB::table('web_chat_messages')->whereIn('web_chat_id', $ids)
                ->where('body', 'not like', '[chat started]%')
                ->select('web_chat_id', DB::raw('max(id) as mid'))->groupBy('web_chat_id')->pluck('mid', 'web_chat_id');
            $msgs = DB::table('web_chat_messages')->whereIn('id', $maxIds->values()->all())->get()->keyBy('web_chat_id');
            foreach ($msgs as $cid => $m) {
                $lastMsg[$cid] = $m;
            }
            $lastVisitor = DB::table('web_chat_messages')->whereIn('web_chat_id', $ids)->where('sender', 'visitor')
                ->select('web_chat_id', DB::raw('max(id) as mid'))->groupBy('web_chat_id')->pluck('mid', 'web_chat_id')->all();
        }
        $me = (int) auth()->id();

        return response()->json([
            'chats' => $rows->map(function ($c) use ($lastMsg, $lastVisitor, $me) {
                $m = $lastMsg[$c->id] ?? null;
                return [
                    'id'          => (int) $c->id,
                    'name'        => $c->name,
                    'email'       => $c->email,
                    'status'      => $c->status,
                    'agent_id'    => $c->agent_id ? (int) $c->agent_id : null,
                    'agent_name'  => $c->agent_name,
                    'mine'        => (int) $c->agent_id === $me,
                    'wants_human' => (bool) $c->wants_human,
                    'unread'      => (int) ($lastVisitor[$c->id] ?? 0) > (int) $c->staff_read_id,
                    'present'     => $c->status === 'open' && self::recent($c->visitor_seen_at, self::PRESENT_SECONDS),
                    'typing'      => $c->status === 'open' && self::recent($c->visitor_typing_at, self::TYPING_SECONDS),
                    'preview'     => $m ? mb_substr((string) $m->body, 0, 140) : '',
                    'preview_by'  => $m ? $m->sender : null,
                    'at'          => self::iso($c->updated_at),
                    'started'     => self::iso($c->created_at),
                    'lead_id'     => $c->lead_id ? (int) $c->lead_id : null,
                ];
            })->values(),
        ]);
    }

    /** GET /sales/web-chats/{id}?after= - the thread; reading it marks it read. */
    public function show(Request $request, int $id): JsonResponse
    {
        $c = DB::table('web_chats')->where('id', $id)->first();
        abort_unless($c, 404);
        $after = max(0, (int) $request->query('after', 0));
        $msgs = DB::table('web_chat_messages')->where('web_chat_id', $id)->where('id', '>', $after)
            ->orderBy('id')->limit(400)->get();
        $maxId = (int) DB::table('web_chat_messages')->where('web_chat_id', $id)->max('id');
        if ($maxId > (int) $c->staff_read_id) {
            DB::table('web_chats')->where('id', $id)->update(['staff_read_id' => $maxId]);
        }

        $lead = null;
        if ($c->lead_id) {
            $lead = DB::table('sales_leads')->where('id', $c->lead_id)->whereNull('deleted_at')->first(['id', 'name', 'stage']);
        } elseif ($c->email) {
            $lead = DB::table('sales_leads')->where('email', $c->email)->whereNull('deleted_at')->orderByDesc('id')->first(['id', 'name', 'stage']);
        }

        return response()->json([
            'chat' => [
                'id'          => (int) $c->id,
                'name'        => $c->name,
                'email'       => $c->email,
                'status'      => $c->status,
                'agent_id'    => $c->agent_id ? (int) $c->agent_id : null,
                'agent_name'  => $c->agent_name,
                'mine'        => (int) $c->agent_id === (int) auth()->id(),
                'wants_human' => (bool) $c->wants_human,
                'present'     => $c->status === 'open' && self::recent($c->visitor_seen_at, self::PRESENT_SECONDS),
                'typing'      => $c->status === 'open' && self::recent($c->visitor_typing_at, self::TYPING_SECONDS),
                'page'        => $c->page,
                'started'     => self::iso($c->created_at),
                'lead'        => $lead ? ['id' => (int) $lead->id, 'name' => $lead->name, 'stage' => $lead->stage] : null,
            ],
            'messages' => $msgs->map(fn ($m) => self::messageOut($m))->values(),
        ]);
    }

    /** Take the chat: Maya stops, and the visitor is told a person joined. */
    private function claim(object $c): object
    {
        $u = auth()->user();
        if ((int) $c->agent_id === (int) $u->id) {
            return $c;
        }
        $name = self::firstName($u);
        $prev = $c->agent_name;
        DB::table('web_chats')->where('id', $c->id)->update([
            'agent_id' => $u->id, 'agent_name' => $name, 'agent_joined_at' => self::now(),
            'status' => 'open', 'closed_at' => null, 'updated_at' => self::now(),
        ]);
        self::addMessage((int) $c->id, 'system', $prev && $prev !== $name
            ? $name.' from the KiddieTrac team has taken over from '.$prev.'.'
            : $name.' from the KiddieTrac team joined the chat.');

        return DB::table('web_chats')->where('id', $c->id)->first();
    }

    /** POST /sales/web-chats/{id}/join */
    public function join(int $id): JsonResponse
    {
        $c = DB::table('web_chats')->where('id', $id)->first();
        abort_unless($c, 404);
        $this->claim($c);

        return response()->json(['ok' => true]);
    }

    /** POST /sales/web-chats/{id}/reply {body} - replying takes the chat if nobody has it. */
    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:4000']);
        $c = DB::table('web_chats')->where('id', $id)->first();
        abort_unless($c, 404);
        $c = $this->claim($c);
        $u = auth()->user();
        $mid = self::addMessage($id, 'agent', trim($data['body']), (int) $u->id, self::firstName($u));
        DB::table('web_chats')->where('id', $id)->update([
            'last_staff_at' => self::now(), 'staff_typing_at' => null, 'updated_at' => self::now(),
            'staff_read_id' => $mid,
        ]);

        return response()->json(['ok' => true, 'message' => self::messageOut(DB::table('web_chat_messages')->where('id', $mid)->first())], 201);
    }

    /** POST /sales/web-chats/{id}/typing */
    public function typing(int $id): JsonResponse
    {
        $u = auth()->user();
        DB::table('web_chats')->where('id', $id)->where('status', 'open')->update([
            'staff_typing_at' => self::now(), 'staff_typing_name' => self::firstName($u),
        ]);

        return response()->json(['ok' => true]);
    }

    /** POST /sales/web-chats/{id}/release - hand the visitor back to Maya. */
    public function release(int $id): JsonResponse
    {
        $c = DB::table('web_chats')->where('id', $id)->first();
        abort_unless($c, 404);
        if ($c->agent_id) {
            DB::table('web_chats')->where('id', $id)->update([
                'agent_id' => null, 'agent_name' => null, 'staff_typing_at' => null, 'updated_at' => self::now(),
            ]);
            self::addMessage($id, 'system', ($c->agent_name ?: 'The team member').' has left the chat. Maya, the virtual assistant, can help from here — and the team has your details.');
        }

        return response()->json(['ok' => true]);
    }

    /** POST /sales/web-chats/{id}/close */
    public function close(int $id): JsonResponse
    {
        $c = DB::table('web_chats')->where('id', $id)->first();
        abort_unless($c, 404);
        if ($c->status === 'open') {
            DB::table('web_chats')->where('id', $id)->update([
                'status' => 'closed', 'closed_at' => self::now(), 'staff_typing_at' => null, 'updated_at' => self::now(),
            ]);
            self::addMessage($id, 'system', 'The KiddieTrac team closed this chat. Thanks for stopping by!');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /sales/web-chats/{id}/lead
     *
     * Into the pipeline. An open lead with the same email is linked rather than
     * duplicated - endChat may already have created one when the visitor left.
     */
    public function lead(int $id): JsonResponse
    {
        $c = DB::table('web_chats')->where('id', $id)->first();
        abort_unless($c, 404);
        $lead = null;
        if ($c->lead_id) {
            $lead = \App\Models\SalesLead::find($c->lead_id);
        }
        if (! $lead && $c->email) {
            $lead = \App\Models\SalesLead::where('email', $c->email)->orderByDesc('id')->first();
        }
        $created = false;
        if (! $lead) {
            $lead = \App\Models\SalesLead::create([
                'name'             => $c->name ?: ($c->email ?: 'Website visitor'),
                'email'            => $c->email ?: null,
                'source'           => 'website-chat',
                'stage'            => 'new',
                'status'           => 'open',
                'owner_id'         => auth()->id(),
                'last_activity_at' => now(),
                'notes'            => 'Started from a website chat.',
            ]);
            $created = true;
        }
        $lines = collect(self::transcriptFor((string) $c->session))->map(function ($m) use ($c) {
            $who = $m['sender'] === 'visitor' ? ($c->name ?: 'Visitor')
                : ($m['sender'] === 'bot' ? 'Maya (assistant)' : ($m['sender'] === 'agent' ? ($m['author'] ?: 'Team') : '—'));
            return $who.': '.$m['message'];
        })->implode("\n");
        \App\Models\SalesActivity::create([
            'lead_id' => $lead->id, 'user_id' => auth()->id(), 'type' => 'note',
            'body' => "Website chat #{$c->id}:\n\n".mb_substr($lines, 0, 9000), 'done' => true,
        ]);
        DB::table('web_chats')->where('id', $id)->update(['lead_id' => $lead->id]);

        return response()->json(['ok' => true, 'lead_id' => (int) $lead->id, 'created' => $created]);
    }
}
