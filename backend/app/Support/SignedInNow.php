<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which of these children are NOT signed in right now (2026-11-04).
 *
 * The same test CareController has always used for care logs: the child's latest
 * check_in has no check_out after it. Daily events (meals, snacks, naps, nappies,
 * sunscreen, bulk room logs) had no test at all, so an educator could log lunch for a
 * child who was never signed in and nothing said so. Both write paths answer with the
 * same 422 not_signed_in, and the portal shows one warning for it (KT.warnNotSignedIn).
 */
final class SignedInNow
{
    /**
     * @param  int[] $childIds
     * @return string[] display names of the children who are not signed in
     */
    public static function absentNames(array $childIds): array
    {
        $absent = [];
        foreach (array_values(array_unique(array_map('intval', $childIds))) as $childId) {
            if ($childId <= 0) continue;
            $lastIn = DB::table('check_events')->where('child_id', $childId)
                ->where('event_type', 'check_in')->orderByDesc('created_at')->value('created_at');
            $isHere = false;
            if ($lastIn) {
                $isHere = ! DB::table('check_events')->where('child_id', $childId)
                    ->where('event_type', 'check_out')->where('created_at', '>', $lastIn)->exists();
            }
            if (! $isHere) {
                $absent[] = DB::table('children')->where('id', $childId)
                    ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")
                    ->value('n') ?: ('child #'.$childId);
            }
        }
        return $absent;
    }

    /** The refusal both write paths send - the portal asks, then re-posts with confirm_absent=1. */
    public static function refusal(array $absent): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => 'not_signed_in',
            'absent' => $absent,
            'prompt' => (count($absent) === 1 ? $absent[0].' is' : implode(', ', $absent).' are')
                .' not signed in right now. Log this anyway as a catch-up entry?',
        ], 422);
    }
}
