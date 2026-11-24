<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BookingMail;
use App\Support\SalesCalendar;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales → Calendar (2026-10-23, Anthony: "a sales calendar under sales to show all of the
 * bookings and whereabouts of sales staff including allowing sales staff to show their
 * availability and book/block time off"). One view of: website bookings, pipeline
 * meetings and follow-ups, each person's meetings / blocked time / time off / whereabouts,
 * and their weekly hours. A sales rep edits their own entries; a platform admin anyone's.
 * Shown in Eastern Time, like the bookings.
 */
class SalesCalendarController extends Controller
{
    private const KINDS = ['meeting', 'block', 'time_off', 'where'];
    private const WHERE = ['office' => 'In the office', 'remote' => 'Working remotely', 'road' => 'On the road', 'client' => 'At a client', 'out' => 'Out of office'];

    private function isAdmin(int $uid): bool
    {
        return DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists();
    }

    private function mayEdit(Request $r, int $ownerId): bool
    {
        $uid = (int) $r->user()->id;
        return $uid === $ownerId || $this->isAdmin($uid);
    }

    /** GET /sales/calendar?from=YYYY-MM-DD&to=YYYY-MM-DD (Eastern days, inclusive) */
    public function index(Request $request): JsonResponse
    {
        $tz = BookingMail::TZ;
        $from = Carbon::parse((string) $request->query('from', now($tz)->startOfWeek(Carbon::SUNDAY)->toDateString()), $tz)->startOfDay();
        $to = Carbon::parse((string) $request->query('to', $from->copy()->addDays(6)->toDateString()), $tz)->endOfDay();
        if ($to->diffInDays($from, true) > 62) $to = $from->copy()->addDays(62)->endOfDay();
        $fu = $from->copy()->utc(); $tu = $to->copy()->utc();
        $staff = SalesCalendar::staff();
        $ids = array_column($staff, 'id');

        $events = DB::table('sales_calendar_events')->whereIn('user_id', $ids ?: [0])
            ->where('starts_at', '<', $tu)->where('ends_at', '>', $fu)->orderBy('starts_at')->get()
            ->map(fn ($e) => [
                'id' => (int) $e->id, 'user_id' => (int) $e->user_id, 'kind' => $e->kind, 'title' => $e->title,
                'start' => Carbon::parse($e->starts_at, 'UTC')->toIso8601String(), 'end' => Carbon::parse($e->ends_at, 'UTC')->toIso8601String(),
                'all_day' => (bool) $e->all_day, 'where' => $e->where, 'where_label' => self::WHERE[$e->where] ?? null, 'location' => $e->location, 'notes' => $e->notes,
                'lead_id' => $e->lead_id, 'editable' => $this->mayEdit($request, (int) $e->user_id),
            ])->values();

        $bookings = DB::table('marketing_bookings')->whereIn('status', ['requested', 'booked'])
            ->where('starts_at', '<', $tu)->where('ends_at', '>', $fu)->orderBy('starts_at')->get()
            ->map(fn ($b) => [
                'id' => (int) $b->id, 'status' => $b->status, 'user_id' => $b->host_user_id ? (int) $b->host_user_id : null,
                'title' => BookingMail::typeName($b) . ' · ' . $b->name . ($b->agency ? ' (' . $b->agency . ')' : ''),
                'start' => Carbon::parse($b->starts_at, 'UTC')->toIso8601String(), 'end' => Carbon::parse($b->ends_at, 'UTC')->toIso8601String(),
                'via' => $b->meet_via, 'teams_url' => $b->teams_url,
            ])->values();

        // Pipeline meetings and follow-ups that are due in the range (all-day items).
        $acts = DB::table('sales_activities as a')->join('sales_leads as l', 'l.id', '=', 'a.lead_id')
            ->whereIn('a.type', ['meeting', 'followup', 'call'])->where('a.done', false)->whereNull('l.deleted_at')
            ->whereBetween('a.due_date', [$from->toDateString(), $to->toDateString()])
            ->get(['a.id', 'a.type', 'a.body', 'a.due_date', 'a.lead_id', 'a.user_id', 'l.company', 'l.name', 'l.owner_id'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'type' => $a->type, 'date' => $a->due_date, 'lead_id' => (int) $a->lead_id,
                'user_id' => (int) ($a->owner_id ?: $a->user_id) ?: null, 'title' => ($a->company ?: $a->name) . ' — ' . mb_substr((string) $a->body, 0, 90)])->values();
        $fups = DB::table('sales_leads')->whereNull('deleted_at')->where('status', 'open')->whereBetween('follow_up_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'company', 'name', 'follow_up_date', 'owner_id'])
            ->map(fn ($l) => ['id' => 'f' . $l->id, 'type' => 'followup', 'date' => $l->follow_up_date, 'lead_id' => (int) $l->id, 'user_id' => $l->owner_id ? (int) $l->owner_id : null,
                'title' => 'Follow up: ' . ($l->company ?: $l->name)])->values();

        return response()->json([
            'tz' => $tz, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'me' => (int) $request->user()->id, 'admin' => $this->isAdmin((int) $request->user()->id),
            'staff' => $staff, 'events' => $events, 'bookings' => $bookings, 'activities' => $acts->concat($fups)->values(), 'where' => self::WHERE,
        ]);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'user_id' => ['nullable', 'integer'], 'kind' => ['required', 'in:' . implode(',', self::KINDS)], 'title' => ['nullable', 'string', 'max:200'],
            'date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'end_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'all_day' => ['nullable', 'boolean'], 'where' => ['nullable', 'in:' . implode(',', array_keys(self::WHERE))],
            'location' => ['nullable', 'string', 'max:200'], 'notes' => ['nullable', 'string', 'max:2000'], 'lead_id' => ['nullable', 'integer'],
        ]);
        $tz = BookingMail::TZ;
        $allDay = ! empty($d['all_day']) || $d['kind'] === 'where' || ($d['kind'] === 'time_off' && empty($d['start_time']));
        $endDate = $d['end_date'] ?? $d['date'];
        if ($endDate < $d['date']) abort(422, 'The end date is before the start date.');
        if ($allDay) {
            $s = Carbon::parse($d['date'], $tz)->startOfDay(); $e = Carbon::parse($endDate, $tz)->addDay()->startOfDay();
        } else {
            abort_if(empty($d['start_time']) || empty($d['end_time']), 422, 'Give a start and end time, or make it all day.');
            $s = Carbon::createFromFormat('Y-m-d H:i', $d['date'] . ' ' . $d['start_time'], $tz);
            $e = Carbon::createFromFormat('Y-m-d H:i', $endDate . ' ' . $d['end_time'], $tz);
            abort_if($e->lte($s), 422, 'The end is before the start.');
        }
        return ['kind' => $d['kind'], 'title' => $d['title'] ?? null, 'starts_at' => $s->copy()->utc(), 'ends_at' => $e->copy()->utc(), 'all_day' => $allDay,
            'where' => $d['kind'] === 'where' ? ($d['where'] ?? 'office') : null, 'location' => $d['location'] ?? null, 'notes' => $d['notes'] ?? null,
            'lead_id' => $d['lead_id'] ?? null, 'user_id' => (int) ($d['user_id'] ?? 0)];
    }

    private function audit(Request $r, string $action, string $summary, ?int $id = null): void
    {
        \App\Support\Audit::write(['user_id' => (int) $r->user()->id, 'agency_id' => null, 'action' => $action, 'entity_type' => 'sales_calendar', 'entity_id' => $id,
            'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
    }

    private function describe(array $row): string
    {
        $tz = BookingMail::TZ;
        $who = DB::table('users')->where('id', $row['user_id'])->value('first_name');
        $label = ['meeting' => 'Meeting', 'block' => 'Blocked time', 'time_off' => 'Time off', 'where' => self::WHERE[$row['where']] ?? 'Whereabouts'][$row['kind']];
        $when = $row['all_day'] ? Carbon::parse($row['starts_at'], 'UTC')->tz($tz)->format('M j') . ($row['ends_at']->diffInDays($row['starts_at'], true) > 1 ? '–' . Carbon::parse($row['ends_at'], 'UTC')->tz($tz)->subDay()->format('M j') : '')
            : Carbon::parse($row['starts_at'], 'UTC')->tz($tz)->format('M j g:i A') . '–' . Carbon::parse($row['ends_at'], 'UTC')->tz($tz)->format('g:i A');
        return $label . ' for ' . $who . ' · ' . $when . ($row['title'] ? ' · ' . $row['title'] : '');
    }

    /** POST /sales/calendar/events */
    public function store(Request $request): JsonResponse
    {
        $row = $this->validated($request);
        $row['user_id'] = $row['user_id'] ?: (int) $request->user()->id;
        abort_unless(in_array($row['user_id'], array_column(SalesCalendar::staff(), 'id'), true), 422, 'That person is not on the sales team.');
        abort_unless($this->mayEdit($request, $row['user_id']), 403, 'You can only add to your own calendar.');
        $id = DB::table('sales_calendar_events')->insertGetId($row + ['created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'sales_calendar.added', $this->describe($row), $id);
        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** PATCH /sales/calendar/events/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $e = DB::table('sales_calendar_events')->where('id', $id)->first();
        abort_unless($e, 404);
        abort_unless($this->mayEdit($request, (int) $e->user_id), 403, 'You can only change your own calendar.');
        $row = $this->validated($request);
        $row['user_id'] = $row['user_id'] ?: (int) $e->user_id;
        abort_unless($this->mayEdit($request, $row['user_id']), 403, 'You can only move things to your own calendar.');
        DB::table('sales_calendar_events')->where('id', $id)->update($row + ['updated_at' => now()]);
        $this->audit($request, 'sales_calendar.changed', $this->describe($row), $id);
        return response()->json(['ok' => true]);
    }

    /** DELETE /sales/calendar/events/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $e = DB::table('sales_calendar_events')->where('id', $id)->first();
        abort_unless($e, 404);
        abort_unless($this->mayEdit($request, (int) $e->user_id), 403, 'You can only change your own calendar.');
        DB::table('sales_calendar_events')->where('id', $id)->delete();
        $this->audit($request, 'sales_calendar.removed', 'Removed: ' . ($e->title ?: $e->kind) . ' (' . Carbon::parse($e->starts_at, 'UTC')->tz(BookingMail::TZ)->format('M j') . ')', $id);
        return response()->json(['ok' => true]);
    }

    /** PUT /sales/calendar/availability {user_id?, hours: [{weekday, start, end}, …]} — replaces that person's week. */
    public function saveAvailability(Request $request): JsonResponse
    {
        $d = $request->validate(['user_id' => ['nullable', 'integer'], 'hours' => ['present', 'array', 'max:40'],
            'hours.*.weekday' => ['required', 'integer', 'min:0', 'max:6'], 'hours.*.start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'hours.*.end' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']]);
        $uid = (int) ($d['user_id'] ?? $request->user()->id);
        abort_unless(in_array($uid, array_column(SalesCalendar::staff(), 'id'), true), 422, 'That person is not on the sales team.');
        abort_unless($this->mayEdit($request, $uid), 403, 'You can only set your own hours.');
        foreach ($d['hours'] as $h) abort_if($h['end'] <= $h['start'], 422, 'Each span must end after it starts.');
        DB::transaction(function () use ($uid, $d) {
            DB::table('sales_availability')->where('user_id', $uid)->delete();
            foreach ($d['hours'] as $h) DB::table('sales_availability')->insert(['user_id' => $uid, 'weekday' => $h['weekday'], 'start_time' => $h['start'], 'end_time' => $h['end'], 'created_at' => now(), 'updated_at' => now()]);
        });
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $this->audit($request, 'sales_calendar.hours', 'Working hours for ' . DB::table('users')->where('id', $uid)->value('first_name') . ': '
            . (collect($d['hours'])->map(fn ($h) => $days[$h['weekday']] . ' ' . $h['start'] . '–' . $h['end'])->implode(', ') ?: 'none'));
        return response()->json(['ok' => true, 'staff' => SalesCalendar::staff()]);
    }
}
