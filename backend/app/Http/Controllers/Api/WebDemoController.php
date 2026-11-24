<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PublicDemo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * POST /marketing-site/demo-session {role: parent|educator|director} - the website's "Try it live" phone (2026-10-31).
 * Signs a visitor into a fictional Brightwood account for 2 hours (Sanctum token "web-demo"); the page opens
 * https://app.kiddietrac.com/index.html#kt_sso=TOKEN in its phone frame. No passwords anywhere. Only the three
 * designated accounts of a PUBLIC demo agency can ever be handed out (App\Support\PublicDemo), and every outbound
 * channel is shut for them. Throttled per minute (route) and per day (here, per address and overall).
 */
class WebDemoController extends Controller
{
    private const PER_IP_DAY = 40;
    private const ALL_DAY = 3000;

    private function cors(JsonResponse $r): JsonResponse
    {
        return $r->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * THE DEMO FEELS ALIVE (2026-10-31). POST /demo/pulse - called by the portal's kt-demo-frame.js every ~15 s for
     * the first minutes of a demo sign-in inside the website's phone. Each call writes at most ONE scripted, fictional
     * in-app notification (a row in `notifications` for this demo person, nothing else) once its moment has come, so
     * the bell, the sound and the phone's banner all behave as they would for real. Inserted directly - no push, no
     * mail, no text - and recorded by PublicDemoGuard so the nightly reset removes it. Demo-only people only.
     */
    public function pulse(Request $request): JsonResponse
    {
        $u = $request->user();
        if (! $u || ! PublicDemo::isUser((int) $u->id)) return response()->json(['message' => 'Not found.'], 404);
        $tok = method_exists($u, 'currentAccessToken') ? $u->currentAccessToken() : null;
        $tid = (int) ($tok->id ?? 0);
        if (! $tid || empty($tok->created_at)) return response()->json(['created' => 0, 'done' => true]);
        $role = PublicDemo::roleOf((int) $u->id);
        $script = $role ? $this->script((string) $role, (int) $u->id) : [];
        $key = 'webdemo:pulse:' . $tid;
        $done = (int) Cache::get($key, 0);
        $age = now()->getTimestamp() - \Illuminate\Support\Carbon::parse($tok->created_at)->getTimestamp();
        $dayKey = 'webdemo:pulse:' . now()->format('Ymd');
        $made = 0;
        if ($done < count($script) && $age >= $script[$done]['at'] && (int) Cache::get($dayKey, 0) < self::PULSE_DAY) {
            $n = $script[$done];
            \Illuminate\Support\Facades\DB::table('notifications')->insert([
                'user_id' => (int) $u->id, 'type' => $n['type'], 'title' => $n['title'], 'body' => $n['body'],
                'data' => json_encode(['link' => $n['link'], 'kt_category' => $n['cat'], 'demo' => true]),
                'created_at' => now(),
            ]);
            $done++; $made = 1;
            Cache::put($dayKey, (int) Cache::get($dayKey, 0) + 1, now()->addDay());
        }
        Cache::put($key, $done, now()->addHours(PublicDemo::SESSION_HOURS + 1));
        return response()->json(['created' => $made, 'done' => $done >= count($script),
            'next_in' => $done < count($script) ? max(0, $script[$done]['at'] - $age) : null]);
    }

    /**
     * THE PARENT'S PHONE BESIDE THE STAFF PHONE (2026-10-31, Anthony: "in the customer experience on the marketing site,
     * when logged in as educator can you have a phone next to it as a parent to show what the parent sees with
     * notifications etc"). POST /demo/companion - asked by kt-demo-frame.js inside the website's demo phone once a demo
     * EDUCATOR / DIRECTOR / ADMIN is signed in. Hands back a sign-in for that same demo agency's parent (Megan), so the
     * second phone boots straight into the parent app and shows what the educator's actions do to a family.
     * Fail closed: the caller must be a public-demo staff person on a demo sign-in (a token with an expiry), the parent
     * must be the same set's parent (same brand, same agency) and a public-demo person too. The parent's token lives no
     * longer than the caller's, one per caller session (a new ask replaces the old one), and every issue is audited.
     * The parent session is fenced exactly like every demo session (PublicDemoGuard, suppression, nightly reset).
     */
    public function companion(Request $request): JsonResponse
    {
        $u = $request->user();
        $no = fn () => response()->json(['message' => 'Not available.'], 403);
        if (! $u || ! PublicDemo::isUser((int) $u->id)) return $no();
        $role = PublicDemo::roleOf((int) $u->id);
        if (! in_array($role, ['educator', 'director', 'admin'], true)) return $no();
        $tok = method_exists($u, 'currentAccessToken') ? $u->currentAccessToken() : null;
        if (! $tok || empty($tok->id) || empty($tok->expires_at)) return $no();
        $exp = \Illuminate\Support\Carbon::parse($tok->expires_at);
        if ($exp->isPast()) return $no();
        $agency = PublicDemo::agencyOf((int) $u->id);
        if (! PublicDemo::isAgency($agency)) return $no();
        /* The parent phone follows the child (2026-10-31): the family of the child / family / invoice the staff member just
           acted on, in this same demo agency only; nothing named = the published demo parent (Megan). */
        $famId = 0;
        if ($request->filled('child_id')) $famId = (int) \Illuminate\Support\Facades\DB::table('children')->where('id', (int) $request->input('child_id'))->value('family_id');
        elseif ($request->filled('family_id')) $famId = (int) $request->input('family_id');
        elseif ($request->filled('invoice_id')) $famId = (int) \Illuminate\Support\Facades\DB::table('invoices')->where('id', (int) $request->input('invoice_id'))->value('family_id');
        $named = $request->filled('child_id') || $request->filled('family_id') || $request->filled('invoice_id');
        $parentId = $named ? ($famId ? PublicDemo::parentOfFamily($famId, $agency) : null) : PublicDemo::idFor((int) $u->id, 'parent');
        if (! $parentId || ! PublicDemo::isUser($parentId) || PublicDemo::agencyOf($parentId) !== $agency) return $no();
        if (! $named && PublicDemo::setOf($parentId) !== PublicDemo::setOf((int) $u->id)) return $no();
        if (! $famId) $famId = (int) \Illuminate\Support\Facades\DB::table('guardians')->where('user_id', $parentId)->value('family_id');
        $parent = User::find($parentId);
        if (! $parent) return $no();
        $dayKey = 'webdemo:companion:' . now()->format('Ymd');
        if ((int) Cache::get($dayKey, 0) >= self::ALL_DAY) {
            return response()->json(['message' => 'The live demo is very busy right now. Please try again later.'], 429);
        }
        PublicDemo::prepareUser($parentId);
        // One companion per staff session: a second ask (a relaunch, a reload) replaces the first.
        $mapKey = 'webdemo:companion:tok:' . (int) $tok->id;
        $old = (int) Cache::get($mapKey, 0);
        if ($old) \Illuminate\Support\Facades\DB::table('personal_access_tokens')->where('id', $old)->where('tokenable_id', $parentId)->delete();
        $until = $exp->copy()->min(now()->addHours(PublicDemo::SESSION_HOURS));
        $new = $parent->createToken(PublicDemo::TOKEN . '-companion', ['*'], $until);
        Cache::put($mapKey, (int) $new->accessToken->id, $until);
        Cache::put($dayKey, (int) Cache::get($dayKey, 0) + 1, now()->addDay());
        \App\Support\Audit::write([
            'user_id' => (int) $u->id, 'agency_id' => $agency, 'action' => 'demo.companion_issued',
            'entity_type' => 'user', 'entity_id' => $parentId,
            'payload' => json_encode(['summary' => 'Website demo: opened the parent\'s phone beside the ' . $role . '\'s phone',
                'role' => $role, 'parent_id' => $parentId, 'expires_at' => $until->toIso8601String()]),
        ]);
        return response()->json([
            'token' => $new->plainTextToken,
            'brand' => PublicDemo::setOf((int) $u->id) === 'white_label' ? 'wl' : 'kt',
            'name' => $parent->first_name,
            'family_id' => $famId,
            'kids' => \Illuminate\Support\Facades\DB::table('children')->where('family_id', $famId)->whereNull('deleted_at')->orderBy('id')
                ->get(['id', 'first_name', 'preferred_name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->preferred_name ?: $c->first_name])->values(),
            'roster' => PublicDemo::roster($agency),
            'expires_at' => $until->toIso8601String(),
        ]);
    }

    private const PULSE_DAY = 6000;

    /** What happens to each demo person in their first minutes (seconds after signing in). Fictional, Brightwood. */
    private function script(string $role, int $userId = 0): array
    {
        $kids = \Illuminate\Support\Facades\DB::table('guardians as g')->join('children as c', 'c.family_id', '=', 'g.family_id')
            ->where('g.user_id', PublicDemo::idFor($userId, 'parent') ?? PublicDemo::USERS['parent'])->orderBy('c.id')->pluck('c.first_name')->all();
        // "at Brightwood" / "at Starlight": the person's own centre brand (2026-10-30, white-label demo).
        $brand = strtok((string) \Illuminate\Support\Facades\DB::table('agencies')->where('id', PublicDemo::agencyOf($userId))->value('name'), ' ') ?: 'Brightwood';
        $a = $kids[1] ?? ($kids[0] ?? 'Evelyn');
        $b = $kids[0] ?? 'James';
        return [
            'parent' => [
                ['at' => 20, 'type' => 'checkin', 'cat' => 'checkin', 'link' => '#dashboard', 'title' => "{$a} was signed in", 'body' => "Maya signed {$a} in at {$brand} just now. Have a great day!"],
                ['at' => 65, 'type' => 'photo', 'cat' => 'photos', 'link' => '#dashboard', 'title' => "New photo of {$b}", 'body' => "Maya shared a photo from Sprouts: leaf painting in the garden."],
                ['at' => 130, 'type' => 'care', 'cat' => 'photos', 'link' => '#dashboard', 'title' => "{$a}'s daily report is ready", 'body' => 'Lunch: ate most of it · Nap: 1 h 20 min · Mood: happy'],
                ['at' => 230, 'type' => 'announcement', 'cat' => 'announcement', 'link' => '#dashboard', 'title' => 'Pumpkin patch day on Friday', 'body' => 'Please send rain boots and a labelled water bottle.'],
            ],
            'educator' => [
                ['at' => 20, 'type' => 'chat', 'cat' => 'chat', 'link' => '#dashboard', 'title' => 'Message from Megan Gonzalez', 'body' => "Hi Maya! {$a} didn't sleep well last night, she may need an early nap."],
                ['at' => 70, 'type' => 'checkin', 'cat' => 'checkin', 'link' => '#dashboard', 'title' => "{$b} Gonzalez was signed in", 'body' => 'Signed in by Megan Gonzalez at the front door (One-Tap).'],
                ['at' => 140, 'type' => 'headcount_due', 'cat' => 'staff', 'link' => '#dashboard', 'title' => 'Headcount due in Sprouts', 'body' => "It's time for this room's headcount."],
                ['at' => 240, 'type' => 'announcement', 'cat' => 'announcement', 'link' => '#dashboard', 'title' => 'Staff meeting moved to 3:15 PM', 'body' => 'Elena: same room, coffee is on me.'],
            ],
            'director' => [
                ['at' => 20, 'type' => 'incident', 'cat' => 'safety', 'link' => '#dashboard', 'title' => 'Incident report to review', 'body' => 'Maya filed a minor incident in Sprouts: a scraped knee on the playground.'],
                ['at' => 75, 'type' => 'time_off', 'cat' => 'staff', 'link' => '#dashboard', 'title' => 'Time-off request from Maya', 'body' => 'Friday: a personal day. Approve or decline in Team.'],
                ['at' => 150, 'type' => 'chat', 'cat' => 'chat', 'link' => '#dashboard', 'title' => 'Message from Megan Gonzalez', 'body' => "Could we book a tour for my sister's family next week?"],
                ['at' => 250, 'type' => 'invoice', 'cat' => 'billing', 'link' => '#dashboard', 'title' => 'Two invoices are past due', 'body' => 'Send a friendly reminder from Billing (switched off in the demo).'],
            ],
        ][$role] ?? [];
    }

    public function start(Request $request): JsonResponse
    {
        $role = strtolower(trim((string) $request->input('role', '')));
        if (! isset(PublicDemo::USERS[$role])) {
            return $this->cors(response()->json(['message' => 'Choose Parent, Educator or Director.'], 422));
        }
        $day = now()->format('Ymd');
        $ipKey = 'webdemo:' . $day . ':' . sha1((string) $request->ip());
        $allKey = 'webdemo:' . $day . ':all';
        if ((int) Cache::get($ipKey, 0) >= self::PER_IP_DAY || (int) Cache::get($allKey, 0) >= self::ALL_DAY) {
            return $this->cors(response()->json(['message' => 'The live demo is very busy right now — please try again later, or book a guided demo.'], 429));
        }
        $user = User::find(PublicDemo::USERS[$role]);
        // Never anyone real: the account must belong only to a public demo agency.
        if (! $user || ! PublicDemo::isUser((int) $user->id)) {
            return $this->cors(response()->json(['message' => 'The live demo is not available right now.'], 503));
        }
        PublicDemo::prepareUser((int) $user->id);
        $token = $user->createToken(PublicDemo::TOKEN, ['*'], now()->addHours(2))->plainTextToken;
        Cache::put($ipKey, (int) Cache::get($ipKey, 0) + 1, now()->addDay());
        Cache::put($allKey, (int) Cache::get($allKey, 0) + 1, now()->addDay());
        return $this->cors(response()->json([
            'url' => 'https://app.kiddietrac.com/index.html#kt_sso=' . rawurlencode($token),
            'role' => $role,
            'name' => $user->first_name,
            'expires_in' => 7200,
        ]));
    }
}
