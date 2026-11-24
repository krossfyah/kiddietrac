<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The birthday sing-along (2026-10-21). Anthony: "in the birthday email which the educator
 * gets as the reminder make a birthday video as a cartoon with the happy birthday full song
 * ... this video can be played in class for the kid to hear".
 *
 *   GET /birthday-video/{child}?expires=&n=&signature=   (signed, expiring; no sign-in)
 *   GET /birthday-video/demo                              (sample: Amara, 4, Sunny Meadows)
 *
 * The cartoon itself is the same for everyone and never shows a name (Anthony, 2026-10-21:
 * "the video should not have the name of the person itself and keep generic"). The player page
 * asks this only for the greeting ABOVE the video, and that is ALL it gets: the child's first (or preferred) name, the age they are turning, and the
 * centre and agency names. No surname, no date of birth, no other children. The age comes
 * from the signed link itself (`n`), so nothing here reads a date of birth at all; the
 * signature stops anyone changing it or the child id.
 */
final class BirthdayVideoController extends Controller
{
    public function show(Request $request, int $child): JsonResponse
    {
        $row = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->leftJoin('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('ch.id', $child)
            ->whereNull('ch.deleted_at')
            ->first(['ch.first_name', 'ch.preferred_name', 'c.name as centre', 'c.agency_id', 'c.supervisor_first_name', 'c.supervisor_last_name']);

        if (! $row) {
            return $this->out(['message' => 'This birthday video is no longer available.'], 410);
        }

        $agency = $row->agency_id ? (string) (DB::table('agencies')->where('id', $row->agency_id)->value('name') ?? '') : '';

        /* A home daycare is named after its provider ("Amna Ahsan"), so "from everyone at
           Amna Ahsan" reads wrong. When the location's name IS the supervisor's name, the
           player says "from your teacher Amna and all your friends" instead. */
        $sup = trim(($row->supervisor_first_name ?? '') . ' ' . ($row->supervisor_last_name ?? ''));
        $home = $sup !== '' && strcasecmp($sup, trim((string) $row->centre)) === 0;

        return $this->out([
            'name' => mb_substr(trim((string) ($row->preferred_name ?: $row->first_name)), 0, 24),
            'setting' => $home ? 'home' : 'centre',
            'teacher' => $home ? mb_substr(trim((string) $row->supervisor_first_name), 0, 30) : '',
            'turning' => max(0, min(12, (int) $request->query('n', 0))),
            'centre' => mb_substr((string) ($row->centre ?? ''), 0, 60),
            'agency' => mb_substr($agency, 0, 60),
        ]);
    }

    /**
     * GET /birthday-video/staff/{user}?expires=&signature= — the greeting for a staff member's
     * birthday: first (or preferred) name and their agency's name. Nothing else.
     */
    public function staff(int $user): JsonResponse
    {
        $cols = ['first_name'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'preferred_name')) {
            $cols[] = 'preferred_name';
        }
        $u = DB::table('users')->where('id', $user)->whereNull('deleted_at')->first($cols);
        if (! $u) {
            return $this->out(['message' => 'This birthday video is no longer available.'], 410);
        }
        $agencyId = DB::table('role_assignments')->where('user_id', $user)->where('active', 1)
            ->where('role', '!=', 'guardian')->whereNotNull('agency_id')->value('agency_id');
        $agency = $agencyId ? (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?? '') : '';

        return $this->out([
            'name' => mb_substr(trim((string) (($u->preferred_name ?? null) ?: $u->first_name)), 0, 24),
            'staff' => true,
            'agency' => mb_substr($agency, 0, 60),
        ]);
    }

    /** The sample shown from test emails and the preview: no real child. */
    public function demo(): JsonResponse
    {
        return $this->out(['name' => 'Amara', 'turning' => 4, 'centre' => 'Sunny Meadows', 'agency' => 'Sunny Meadows Childcare', 'demo' => true]);
    }

    private function out(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
