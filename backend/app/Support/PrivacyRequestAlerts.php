<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Privacy requests coming due (2026-11-10, App\Http\Controllers\Api\PrivacyRequestController). PIPEDA s. 8(3) gives
 * 30 days to answer and s. 8(5) makes a missed deadline a refusal, so each agency's admins get ONE bell row a day
 * (it also rings their phone - Notify::write) while any open request is due within 7 days or overdue.
 * Sent from privacy:breach-reminders, at the hour the agency set for breach reminders, to the same admins
 * (PrivacyBreachAlerts::eligible, admins only, minus anyone muted there). Summary only: reference and due date,
 * never the requester's name or what they asked.
 */
final class PrivacyRequestAlerts
{
    public const SOON_DAYS = 7;

    /** @return array<int, array{to:int, title:string, count:int}> */
    public static function digest(int $agencyId, bool $dry = false): array
    {
        $today = Carbon::parse(AgencyTime::today($agencyId));
        $due = DB::table('privacy_requests')->where('agency_id', $agencyId)->whereIn('status', ['open', 'in_progress'])
            ->get(['ref', 'due_on', 'extended_to'])
            ->map(function ($r) use ($today) {
                $by = Carbon::parse((string) ($r->extended_to ?: $r->due_on));
                $left = (int) $today->diffInDays($by, false);

                return ['ref' => $r->ref, 'left' => $left, 'by' => $by->format('M j')];
            })
            ->filter(fn ($r) => $r['left'] <= self::SOON_DAYS)->sortBy('left')->values();
        if ($due->isEmpty()) return [];

        $overdue = $due->where('left', '<', 0)->count();
        $title = $overdue
            ? $overdue . ' privacy request' . ($overdue === 1 ? ' is' : 's are') . ' overdue'
            : $due->count() . ' privacy request' . ($due->count() === 1 ? '' : 's') . ' due this week';
        $body = mb_substr($due->map(fn ($r) => $r['ref'] . ': ' . ($r['left'] < 0 ? abs($r['left']) . ' days overdue' : 'answer by ' . $r['by']))->implode(' · '), 0, 480);

        $muted = PrivacyBreachAlerts::settings($agencyId)['muted'] ?? [];
        $sent = [];
        foreach (PrivacyBreachAlerts::eligible($agencyId) as $p) {
            if (empty($p['admin']) || in_array((int) $p['user_id'], array_map('intval', $muted), true)) continue;
            if (! $dry) {
                Notify::write(['user_id' => (int) $p['user_id'], 'type' => 'compliance', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => '#privacy-requests', 'kind' => 'privacy_request_digest']), 'created_at' => now()]);
            }
            $sent[] = ['to' => (int) $p['user_id'], 'title' => $title, 'count' => $due->count()];
        }

        return $sent;
    }
}
