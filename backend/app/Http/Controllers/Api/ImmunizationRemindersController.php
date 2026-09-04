<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-agency immunization-record reminders: a standing nudge to the people who can
 * actually chase a parent, listing the children whose record is missing or out of
 * date. Stored in agencies.settings under "immunization_reminders" and consumed by
 * the immunization:reminders command.
 *
 * Defaults OFF. A recurring email that starts sending itself the moment the code
 * ships is how an agency ends up mailing its whole team about something nobody asked
 * for — every agency opts in deliberately, the same way billing reminders work.
 */
final class ImmunizationRemindersController extends Controller
{
    public const DEFAULTS = [
        'enabled'              => false,
        'frequency'            => 'weekly',   // weekly | monthly
        'day_of_week'          => 1,          // 1 = Monday … 7 = Sunday (weekly)
        'day_of_month'         => 1,          // 1–28 (monthly; 28 so every month has one)
        'send_time'            => '09:00',    // agency-local HH:MM
        'notify_agency_admins' => true,
        'notify_directors'     => true,
        'notify_educators'     => false,      // usually the office chases this, not the room
        'include_missing'      => true,       // children with no record on file at all
        'stale_after_months'   => 12,         // a record older than this needs updating; 0 = never
        'custom_message'       => '',
    ];

    private function resolveAgencyId(Request $request): int
    {
        $u = $request->user();
        $header = (int) $request->header('X-Active-Agency-Id');

        // The header is a request, not a fact — honoured only for a platform_admin or
        // for someone who actually holds an active role in the agency named.
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', 1)->exists();
        if ($header && $isPlatform) {
            return $header;
        }
        if ($header && DB::table('role_assignments')->where('user_id', $u->id)
            ->where('active', 1)->where('agency_id', $header)->exists()) {
            return $header;
        }

        return (int) DB::table('role_assignments')
            ->where('user_id', $u->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->value('agency_id');
    }

    private function assertAccess(Request $request): void
    {
        $ok = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->exists();
        abort_unless($ok, 403, 'Admin access required');
    }

    public static function read(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $r = (isset($settings['immunization_reminders']) && is_array($settings['immunization_reminders']))
            ? $settings['immunization_reminders'] : [];

        return array_merge(self::DEFAULTS, $r);
    }

    /** GET /admin/immunization-reminders */
    public function show(Request $request): JsonResponse
    {
        $this->assertAccess($request);
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->select('id', 'name')->first();
        abort_unless($row, 404, 'Agency not found');

        return response()->json([
            'agency_id' => $row->id,
            'agency_name' => $row->name,
            'reminders' => self::read($agencyId),
            // What the reminder would say if it went out right now — so an admin can
            // see the size of the problem before deciding whether to turn it on.
            'preview' => self::outstanding($agencyId, self::read($agencyId)),
        ]);
    }

    /** POST /admin/immunization-reminders */
    public function update(Request $request): JsonResponse
    {
        $this->assertAccess($request);
        $agencyId = $this->resolveAgencyId($request);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'frequency' => ['nullable', 'in:weekly,monthly'],
            'day_of_week' => ['nullable', 'integer', 'min:1', 'max:7'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:28'],
            'send_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'notify_agency_admins' => ['nullable', 'boolean'],
            'notify_directors' => ['nullable', 'boolean'],
            'notify_educators' => ['nullable', 'boolean'],
            'include_missing' => ['nullable', 'boolean'],
            'stale_after_months' => ['nullable', 'integer', 'min:0', 'max:60'],
            'custom_message' => ['nullable', 'string', 'max:500'],
        ]);

        $current = self::read($agencyId);
        foreach (self::DEFAULTS as $k => $def) {
            if (is_bool($def)) {
                if ($request->has($k)) {
                    $current[$k] = $request->boolean($k);
                }
            } elseif (array_key_exists($k, $data) && $data[$k] !== null) {
                $current[$k] = is_int($def) ? (int) $data[$k] : $data[$k];
            }
        }

        /* Turning it on while every recipient box is unchecked would save happily and
           then send to nobody, which reads as a broken feature rather than a setting. */
        if ($current['enabled']
            && ! $current['notify_agency_admins'] && ! $current['notify_directors'] && ! $current['notify_educators']) {
            return response()->json([
                'message' => 'Choose at least one group to send these reminders to.',
                'errors' => ['notify_agency_admins' => ['Pick at least one recipient group.']],
            ], 422);
        }

        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        abort_unless($row, 404, 'Agency not found');
        $settings = $row->settings ? (json_decode($row->settings, true) ?: []) : [];
        $settings['immunization_reminders'] = $current;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);

        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id ?? null,
                'agency_id' => $agencyId,
                'action' => 'immunization_reminders_updated',
                'entity_type' => 'agency',
                'entity_id' => $agencyId,
                'payload' => json_encode(['settings' => $current]),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // never fail the save on audit
        }

        return response()->json(['status' => 'saved', 'reminders' => $current]);
    }

    /**
     * GET /admin/immunization-records
     *
     * Every immunization record filed for this agency's children, newest first, each
     * carrying who filed it and when — the subtab an admin opens to see what parents
     * have actually sent in. ?source=parent narrows it to parent uploads, which is the
     * question usually being asked; ?source=staff is the other half.
     */
    public function records(Request $request): JsonResponse
    {
        $this->assertAccess($request);
        $agencyId = $this->resolveAgencyId($request);

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)
            ->whereNull('deleted_at')->pluck('id');
        if ($centreIds->isEmpty()) {
            return response()->json(['records' => [], 'outstanding' => 0]);
        }

        /* Scoped to children with an open enrolment at one of THIS agency's centres.
           A child list is exactly the kind of thing that must be tenant-scoped at the
           query, not filtered afterwards. */
        $childIds = DB::table('children as ch')
            ->join('enrollments as e', 'e.child_id', '=', 'ch.id')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->whereIn('r.centre_id', $centreIds)
            ->whereNull('ch.deleted_at')
            ->distinct()->pluck('ch.id')->all();

        $records = ParentImmunizationRecordController::recordsFor($childIds);

        $source = strtolower((string) $request->query('source', ''));
        if ($source === 'parent') {
            $records = array_values(array_filter($records, fn ($r) => $r['uploaded_by_parent']));
        } elseif ($source === 'staff') {
            $records = array_values(array_filter($records, fn ($r) => ! $r['uploaded_by_parent']));
        }

        $cfg = self::read($agencyId);

        return response()->json([
            'records' => $records,
            'parent_uploads' => count(array_filter(
                ParentImmunizationRecordController::recordsFor($childIds),
                fn ($r) => $r['uploaded_by_parent']
            )),
            // How many children are still outstanding, so the subtab can show it
            // beside the list without a second call.
            'outstanding' => self::outstanding($agencyId, $cfg)['count'],
        ]);
    }

    /**
     * The children an agency should be chasing: enrolled, and with either no
     * immunization record on file or one older than the configured window.
     *
     * "On file" means a document filed against the child under the immunization
     * category — the same place the parent upload lands — OR a structured
     * immunization row entered by staff. Either counts; a centre that types the
     * vaccines in should not be nagged for a photo of the card as well.
     *
     * @return array{count:int, children:array<int,array<string,mixed>>}
     */
    public static function outstanding(int $agencyId, array $cfg): array
    {
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)
            ->whereNull('deleted_at')->pluck('id');
        if ($centreIds->isEmpty()) {
            return ['count' => 0, 'children' => []];
        }

        $children = DB::table('children as ch')
            ->join('enrollments as e', 'e.child_id', '=', 'ch.id')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->whereIn('c.id', $centreIds)
            ->whereNull('e.end_date')
            ->whereNull('ch.deleted_at')
            ->distinct()
            ->get([
                'ch.id', 'ch.first_name', 'ch.last_name', 'ch.preferred_name',
                'c.name as centre_name', 'r.name as room_name',
            ]);
        if ($children->isEmpty()) {
            return ['count' => 0, 'children' => []];
        }

        $ids = $children->pluck('id')->all();

        $latestDoc = DB::table('documents')
            ->where('scope_type', 'child')->whereIn('scope_id', $ids)
            ->where('category', 'immunization')
            ->select('scope_id', DB::raw('MAX(created_at) as latest'))
            ->groupBy('scope_id')->pluck('latest', 'scope_id');

        $latestRow = DB::table('immunizations')
            ->whereIn('child_id', $ids)
            ->select('child_id', DB::raw('MAX(COALESCE(administered_on, created_at)) as latest'))
            ->groupBy('child_id')->pluck('latest', 'child_id');

        $months = (int) ($cfg['stale_after_months'] ?? 12);
        $cutoff = $months > 0 ? now()->subMonths($months) : null;
        $includeMissing = (bool) ($cfg['include_missing'] ?? true);

        $out = [];
        foreach ($children as $ch) {
            $d = $latestDoc[$ch->id] ?? null;
            $r = $latestRow[$ch->id] ?? null;
            $latest = null;
            foreach ([$d, $r] as $candidate) {
                if (! $candidate) {
                    continue;
                }
                try {
                    $t = \Illuminate\Support\Carbon::parse($candidate);
                } catch (Throwable $e) {
                    continue;
                }
                if ($latest === null || $t->greaterThan($latest)) {
                    $latest = $t;
                }
            }

            if ($latest === null) {
                if ($includeMissing) {
                    $out[] = self::describe($ch, null, 'No record on file');
                }
                continue;
            }
            if ($cutoff && $latest->lessThan($cutoff)) {
                $out[] = self::describe($ch, $latest->toDateString(), 'Last updated ' . $latest->diffForHumans());
            }
        }

        return ['count' => count($out), 'children' => $out];
    }

    private static function describe(object $ch, ?string $latest, string $why): array
    {
        return [
            'id' => (int) $ch->id,
            'name' => trim((($ch->preferred_name ?: $ch->first_name) . ' ' . $ch->last_name)),
            'centre_name' => $ch->centre_name,
            'room_name' => $ch->room_name,
            'last_record_on' => $latest,
            'reason' => $why,
        ];
    }
}
