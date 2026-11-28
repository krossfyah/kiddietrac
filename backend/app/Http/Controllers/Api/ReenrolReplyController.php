<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AttendanceReply;
use App\Support\ReenrolReplies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PUBLIC: answer a re-enrolment from the email's button (2026-11-28). See App\Support\ReenrolReplies.
 * Signed out by design: the link IS the credential (random 40 characters, stored hashed, one campaign + one family + one
 * guardian, until the end of the deadline day). GET only reads (email scanners open links); only the page's POST acts.
 * The page shows the children's FIRST names only.
 */
final class ReenrolReplyController extends Controller
{
    /** GET /api/v1/public/reenrolment-reply/{token} */
    public function show(string $token): JsonResponse
    {
        [$link, $c, $state] = ReenrolReplies::find($token);
        $brand = $link ? AttendanceReply::brand((int) $link->agency_id) : null;
        if ($state !== 'ok') {
            return response()->json(['state' => $state, 'message' => ReenrolReplies::stateMessage($state), 'brand' => $brand, 'locale' => self::locale($link)]);
        }

        return response()->json(['state' => 'ok', 'locale' => self::locale($link), 'brand' => $brand] + self::describe($link, $c));
    }

    /** POST /api/v1/public/reenrolment-reply/{token}  {answers: [{child_id, response: yes|no, note?}], website: ''} */
    public function submit(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:20'],
            'answers.*.child_id' => ['required', 'integer'],
            'answers.*.response' => ['required', 'string', 'in:yes,no'],
            'answers.*.note' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'string', 'max:0'],   // honeypot: bots fill every field
        ]);
        [$link, $c, $state] = ReenrolReplies::find($token);
        if ($state !== 'ok') {
            return response()->json(['message' => ReenrolReplies::stateMessage($state)], 422);
        }
        [$ok, $message] = ReenrolReplies::answer($c, (int) $link->family_id, $data['answers'], (int) $link->guardian_user_id, 'email');
        if (! $ok) {
            // A refused answer is worth a line too (a forwarded or late link). Saved answers are audited in ReenrolReplies::answer.
            try {
                \App\Support\Audit::write(['user_id' => (int) $link->guardian_user_id, 'agency_id' => (int) $link->agency_id, 'action' => 'reenrolment.refused',
                    'entity_type' => 'reenrollment_campaign', 'entity_id' => (int) $c->id,
                    'payload' => json_encode(['summary' => 'Email link re-enrolment answer not saved: ' . $message, 'ip' => $request->ip()], JSON_UNESCAPED_UNICODE),
                    'created_at' => now()]);
            } catch (\Throwable $e) {
            }

            return response()->json(['message' => $message] + self::describe($link, $c), 422);
        }
        DB::table('reenrolment_reply_links')->where('id', $link->id)->update(['last_used_at' => now()]);

        return response()->json(['ok' => true, 'message' => $message] + self::describe($link, $c));
    }

    private static function describe(object $link, object $c): array
    {
        $first = (string) (strtok(trim((string) DB::table('users')->where('id', $link->guardian_user_id)->value('first_name')), ' ') ?: '');

        return [
            'guardian_first' => $first,
            'campaign' => ['term' => $c->target_term, 'name' => $c->campaign_name, 'deadline' => substr((string) $c->deadline, 0, 10),
                'deadline_label' => \App\Support\BillingRules::day((string) $c->deadline)],
            'children' => array_map(fn ($k) => ['child_id' => $k['child_id'], 'first_name' => $k['first_name'], 'response' => $k['response'], 'note' => $k['notes']],
                ReenrolReplies::children((int) $c->id, (int) $link->family_id)),
        ];
    }

    private static function locale(?object $link): string
    {
        $l = ($link && ! empty($link->guardian_user_id)) ? (string) DB::table('users')->where('id', $link->guardian_user_id)->value('locale') : '';

        return in_array($l, ['en', 'fr', 'es', 'hi'], true) ? $l : 'en';
    }
}
