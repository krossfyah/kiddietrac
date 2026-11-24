<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a CalendarFeed scope into VEVENTs (see CalendarFeed for the rules). Every reader here takes
 * the ids the scope already proved belong to the person; none of them widens to an agency.
 *
 * Times: timed events carry TZID=<the agency's zone> with a generated VTIMEZONE, so a Toronto
 * event stays 9:00 on both sides of a DST change; closures, absences and holidays are all-day DATE
 * values (no zone, no midnight drift). UIDs are "<kind>-<row id>@kiddietrac.com": the same row is
 * always the same event, so an edit replaces it in the family's calendar instead of adding a copy.
 * DTSTAMP is the row's own last change, so an unchanged feed is byte-identical and polls get 304.
 */
final class CalendarFeedBuilder
{
    private Carbon $now;
    private Carbon $from;   // first day (date, UTC midnight)
    private Carbon $to;     // last day
    private array $scope;
    private string $kind;
    private array $events = [];
    private array $centreInfo = [];
    private array $agencyTz = [];
    private array $scrubbers = [];
    private array $closureDays = [];   // centreId => [Y-m-d => true]

    public function __construct(Carbon $now, array $scope, string $kind)
    {
        $this->now = $now->copy()->utc();
        $this->from = $this->now->copy()->subDays(CalendarFeed::BACK_DAYS)->startOfDay();
        $this->to = $this->now->copy()->addMonthsNoOverflow(CalendarFeed::AHEAD_MONTHS)->startOfDay();
        $this->scope = $scope;
        $this->kind = $kind;
        if (! empty($scope['centres'])) {
            foreach (DB::table('centres')->whereIn('id', $scope['centres'])->get(['id', 'agency_id', 'name', 'supervisor_first_name', 'supervisor_last_name', 'open_time', 'close_time']) as $c) {
                $this->centreInfo[(int) $c->id] = $c;
            }
        }
    }

    public function count(): int
    {
        return count($this->events);
    }

    /* ── helpers ─────────────────────────────────────────────────────────────── */

    private function tz(int $agencyId): string
    {
        if (! isset($this->agencyTz[$agencyId])) {
            $tz = (string) (DB::table('agencies')->where('id', $agencyId)->value('timezone') ?: 'America/Toronto');
            try { new \DateTimeZone($tz); } catch (\Throwable $e) { $tz = 'America/Toronto'; }
            $this->agencyTz[$agencyId] = $tz;
        }

        return $this->agencyTz[$agencyId];
    }

    private function centreAgency(?int $centreId): ?int
    {
        return $centreId ? ($this->scope['centre_agency'][$centreId] ?? null) : null;
    }

    /** "Bruni Meeser" (a home child care named after its provider) → "Bruni". */
    public static function centreLabelOf(?object $c): string
    {
        if (! $c) {
            return 'Your centre';
        }
        $name = trim((string) $c->name);
        $last = trim((string) ($c->supervisor_last_name ?? ''));
        if ($last !== '' && mb_stripos($name, $last) !== false) {
            $name = trim((string) preg_replace('/\s+/u', ' ', str_ireplace($last, '', $name)), " -·,'");
            if ($name === '') {
                $name = trim((string) ($c->supervisor_first_name ?? ''));
            }
        }

        return $name !== '' ? $name : 'Your centre';
    }

    private function label(?int $centreId): string
    {
        $c = $centreId ? ($this->centreInfo[$centreId] ?? null) : null;
        $label = self::centreLabelOf($c);

        return $c ? $this->scrub($label, (int) $c->agency_id) : $label;
    }

    private function multiCentre(): bool
    {
        return count($this->scope['centres'] ?? []) > 1;
    }

    /**
     * Free text authored by staff (titles, places) goes through here: a staff member's
     * "First Last" becomes "First", and any OTHER family's child's "First Last" becomes
     * "a child". This feed's own children keep their names.
     */
    private function scrub(string $text, int $agencyId): string
    {
        if ($text === '') {
            return $text;
        }
        if (! isset($this->scrubbers[$agencyId])) {
            $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
            $staff = DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.role', '!=', 'guardian')
                ->where(fn ($q) => $q->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centreIds ?: [0]))
                ->distinct()->get(['u.first_name', 'u.last_name']);
            $mine = array_map(fn ($c) => (int) $c->id, $this->scope['children'] ?? []);
            $kids = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
                ->whereIn('f.centre_id', $centreIds ?: [0])->whereNotIn('c.id', $mine ?: [0])
                ->get(['c.first_name', 'c.last_name', 'c.preferred_name']);
            $map = [];
            foreach ($staff as $s) {
                $f = trim((string) $s->first_name); $l = trim((string) $s->last_name);
                if ($f !== '' && $l !== '') {
                    $map['/\b' . preg_quote($f, '/') . '\s+' . preg_quote($l, '/') . '\b/iu'] = $f;
                }
            }
            foreach ($kids as $k) {
                $l = trim((string) $k->last_name);
                foreach (array_unique(array_filter([trim((string) $k->first_name), trim((string) $k->preferred_name)])) as $f) {
                    if ($l !== '') {
                        $map['/\b' . preg_quote($f, '/') . '\s+' . preg_quote($l, '/') . '\b/iu'] = 'a child';
                    }
                }
            }
            $this->scrubbers[$agencyId] = $map;
        }
        $map = $this->scrubbers[$agencyId];
        if (! $map) {
            return $text;
        }
        $out = @preg_replace(array_keys($map), array_values($map), $text);

        return is_string($out) ? $out : $text;
    }

    private function clean(?string $s, int $max = 160): string
    {
        $s = html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    private function link(string $hash): string
    {
        return CalendarFeed::APP . '#' . $hash;
    }

    private function stamp($ts): string
    {
        try {
            return $ts ? Carbon::parse((string) $ts, 'UTC')->utc()->format('Ymd\THis\Z') : '20261103T000000Z';
        } catch (\Throwable $e) {
            return '20261103T000000Z';
        }
    }

    private function add(array $e): void
    {
        $this->events[$e['uid']] = $e;
    }

    private function allDay(string $uid, string $first, string $last, string $summary, string $desc, string $hash, $stamp, array $extra = []): void
    {
        $a = Carbon::parse($first)->startOfDay();
        $b = Carbon::parse($last)->startOfDay();
        if ($b->lt($a)) {
            $b = $a->copy();
        }
        $this->add($extra + ['uid' => $uid, 'all_day' => true, 'start' => $a->format('Ymd'), 'end' => $b->copy()->addDay()->format('Ymd'),
            'summary' => $summary, 'description' => $desc, 'url' => $this->link($hash), 'stamp' => $this->stamp($stamp), 'transp' => 'TRANSPARENT']);
    }

    private function timed(string $uid, Carbon $startLocal, Carbon $endLocal, string $tz, string $summary, string $desc, string $hash, $stamp, array $extra = []): void
    {
        if ($endLocal->lte($startLocal)) {
            $endLocal = $startLocal->copy()->addHour();
        }
        $this->add($extra + ['uid' => $uid, 'all_day' => false, 'tz' => $tz, 'start' => $startLocal->format('Ymd\THis'), 'end' => $endLocal->format('Ymd\THis'),
            'summary' => $summary, 'description' => $desc, 'url' => $this->link($hash), 'stamp' => $this->stamp($stamp), 'transp' => 'OPAQUE']);
    }

    private function fromDate(): string { return $this->from->toDateString(); }
    private function toDate(): string { return $this->to->toDateString(); }

    /* ── sources ─────────────────────────────────────────────────────────────── */

    /** Centre closures and holidays: all-day, one event per closure row (ranges stay one event). */
    public function closures(): void
    {
        $rows = DB::table('centre_closures')->whereIn('centre_id', $this->scope['centres'] ?: [0])
            ->where('closure_date', '<=', $this->toDate())
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('end_date')->where('closure_date', '>=', $this->fromDate()))
                ->orWhere('end_date', '>=', $this->fromDate()))
            ->orderBy('closure_date')->get();
        foreach ($rows as $r) {
            $cid = (int) $r->centre_id;
            $aid = $this->centreAgency($cid);
            if (! $aid) continue;
            $label = $this->label($cid);
            $reason = $this->clean($this->scrub(Closures::reason($r), $aid), 80);
            $last = $r->end_date && $r->end_date >= $r->closure_date ? $r->end_date : $r->closure_date;
            $multi = $last !== $r->closure_date;
            $this->allDay('closure-' . (int) $r->id . '@kiddietrac.com', (string) $r->closure_date, (string) $last,
                'Closed: ' . $reason . ($this->multiCentre() ? ' (' . $label . ')' : ''),
                $label . ' is closed ' . ($multi ? 'these days' : 'this day') . ' (' . $reason . ').',
                $this->kind === 'staff' ? 'dashboard' : 'announcements', $r->updated_at ?? $r->created_at, ['categories' => 'Closure']);
        }
    }

    /** Announcements that carry a date (due date / event time) for this person's scope. */
    public function announcements(array $audiences): void
    {
        $fromUtc = $this->from->format('Y-m-d H:i:s');
        $toUtc = $this->to->copy()->addDay()->format('Y-m-d H:i:s');
        $centres = $this->scope['centres'] ?: [0];
        $rooms = $this->scope['rooms'] ?: [0];
        $agencies = $this->scope['agencies'] ?: [0];
        $families = $this->kind === 'parent' ? ($this->scope['families'] ?: [0]) : [0];
        $rows = DB::table('announcements')->whereNotNull('sent_at')->whereNotNull('due_at')
            ->whereBetween('due_at', [$fromUtc, $toUtc])
            ->whereIn('audience', $audiences)
            ->whereNull('ref_type')
            ->where(function ($q) use ($centres, $rooms, $agencies, $families) {
                $q->where(fn ($w) => $w->where('scope_type', 'centre')->whereIn('scope_id', $centres))
                  ->orWhere(fn ($w) => $w->where('scope_type', 'room')->whereIn('scope_id', $rooms))
                  ->orWhere(fn ($w) => $w->where('scope_type', 'agency')->whereIn('scope_id', $agencies))
                  ->orWhere(fn ($w) => $w->where('scope_type', 'family')->whereIn('scope_id', $families));
            })
            ->orderBy('due_at')->get(['id', 'scope_type', 'scope_id', 'title', 'due_at', 'sent_at', 'created_at']);
        $roomCentre = DB::table('rooms')->whereIn('id', $rooms)->pluck('centre_id', 'id')->all();
        $famCentre = DB::table('families')->whereIn('id', $families)->pluck('centre_id', 'id')->all();
        foreach ($rows as $a) {
            $cid = match ($a->scope_type) {
                'centre' => (int) $a->scope_id,
                'room' => (int) ($roomCentre[$a->scope_id] ?? 0),
                'family' => (int) ($famCentre[$a->scope_id] ?? 0),
                default => 0,
            };
            $aid = $a->scope_type === 'agency' ? (int) $a->scope_id : $this->centreAgency($cid);
            if (! $aid || ! in_array($aid, $this->scope['agencies'], true)) continue;
            $tz = $this->tz($aid);
            $title = $this->clean($this->scrub((string) $a->title, $aid), 120);
            if ($title === '') continue;
            $from = $cid ? $this->label($cid) : (string) DB::table('agencies')->where('id', $aid)->value('name');
            $start = Carbon::parse((string) $a->due_at, 'UTC')->tz($tz);
            $desc = 'From ' . $from . '. The full announcement is in the app.';
            $hm = $start->format('H:i');
            if ($hm === '00:00' || $hm === '23:59') {
                $this->allDay('ann-' . (int) $a->id . '@kiddietrac.com', $start->toDateString(), $start->toDateString(), $title, $desc,
                    $this->kind === 'staff' ? 'announcements' : 'announcements', $a->sent_at ?? $a->created_at, ['categories' => 'Event']);
                $this->events['ann-' . (int) $a->id . '@kiddietrac.com']['transp'] = 'OPAQUE';
            } else {
                $this->timed('ann-' . (int) $a->id . '@kiddietrac.com', $start, $start->copy()->addHour(), $tz, $title, $desc,
                    'announcements', $a->sent_at ?? $a->created_at, ['categories' => 'Event']);
            }
        }
    }

    /**
     * Field trips and walks PLANNED in advance at these centres (a walk started on the spot is
     * live-tracked in Walk Watch, not a calendar event). For a parent, a trip with permission
     * slips counts only if one of THEIR children has a slip; a trip with none is centre-wide.
     */
    public function fieldTrips(?array $childIds): void
    {
        $q = DB::table('field_trips as t')->whereIn('t.centre_id', $this->scope['centres'] ?: [0])
            ->whereBetween('t.trip_date', [$this->fromDate(), $this->toDate()])
            ->whereNotIn('t.status', ['cancelled', 'canceled', 'deleted', 'draft'])
            ->where(fn ($w) => $w->whereNull('t.transport_method')->orWhere('t.transport_method', '!=', 'walking')->orWhereNotIn('t.status', ['active', 'completed']));
        if ($childIds !== null) {
            $q->where(function ($w) use ($childIds) {
                $w->whereNotExists(fn ($p) => $p->from('field_trip_permissions as p')->whereColumn('p.field_trip_id', 't.id'))
                  ->orWhereExists(fn ($p) => $p->from('field_trip_permissions as p')->whereColumn('p.field_trip_id', 't.id')->whereIn('p.child_id', $childIds ?: [0]));
            });
        }
        foreach ($q->orderBy('t.trip_date')->get(['t.id', 't.centre_id', 't.title', 't.destination', 't.trip_date', 't.depart_time', 't.return_time', 't.transport_method', 't.created_at', 't.updated_at']) as $t) {
            $cid = (int) $t->centre_id;
            $aid = $this->centreAgency($cid);
            if (! $aid) continue;
            $tz = $this->tz($aid);
            $title = $this->clean($this->scrub((string) $t->title, $aid), 100) ?: 'Outing';
            $walk = strtolower((string) $t->transport_method) === 'walking';
            $summary = preg_match('/\b(trip|walk|outing)\b/i', $title) ? $title : (($walk ? 'Walk: ' : 'Field trip: ') . $title);
            $where = $this->clean($this->scrub((string) $t->destination, $aid), 120);
            $desc = $this->label($cid) . ($where !== '' ? ' — going to ' . $where : '') . '.';
            $extra = ['categories' => 'Field trip', 'location' => $where];
            $date = substr((string) $t->trip_date, 0, 10);
            if ($t->depart_time) {
                $s = Carbon::parse($date . ' ' . $t->depart_time, $tz);
                $e = $t->return_time ? Carbon::parse($date . ' ' . $t->return_time, $tz) : $s->copy()->addHours(2);
                $desc .= ' Leaves ' . $s->format('g:i A') . ($t->return_time ? ', back about ' . $e->format('g:i A') : '') . '.';
                $this->timed('trip-' . (int) $t->id . '@kiddietrac.com', $s, $e, $tz, $summary, $desc, 'dashboard', $t->updated_at ?? $t->created_at, $extra);
            } else {
                $this->allDay('trip-' . (int) $t->id . '@kiddietrac.com', $date, $date, $summary, $desc, 'dashboard', $t->updated_at ?? $t->created_at, $extra + ['transp' => 'OPAQUE']);
            }
        }
    }

    /** Event RSVPs this person was invited to (by account, or by their email on an unclaimed invite). */
    public function rsvps(int $userId, string $email): void
    {
        $email = trim($email);
        $rows = DB::table('rsvp_invites as i')->join('rsvp_events as e', 'e.id', '=', 'i.event_id')
            ->whereNull('e.deleted_at')->whereNotNull('e.starts_at')
            ->whereIn('e.agency_id', $this->scope['agencies'] ?: [0])
            ->whereColumn('i.agency_id', 'e.agency_id')
            ->whereNull('i.opted_out_at')
            ->whereNotIn('e.status', ['cancelled', 'canceled', 'draft'])
            ->whereBetween('e.starts_at', [$this->from->format('Y-m-d H:i:s'), $this->to->copy()->addDay()->format('Y-m-d H:i:s')])
            ->where(function ($q) use ($userId, $email) {
                $q->where('i.user_id', $userId);
                if ($email !== '') {
                    $q->orWhere(fn ($w) => $w->whereNull('i.user_id')->where('i.email', $email));
                }
            })
            ->select('e.id', 'e.agency_id', 'e.title', 'e.location', 'e.starts_at', 'e.ends_at', 'e.updated_at', 'e.created_at')->distinct()->get();
        foreach ($rows as $e) {
            $aid = (int) $e->agency_id;
            $tz = $this->tz($aid);
            $s = Carbon::parse((string) $e->starts_at, 'UTC')->tz($tz);
            $en = $e->ends_at ? Carbon::parse((string) $e->ends_at, 'UTC')->tz($tz) : $s->copy()->addHours(2);
            $title = $this->clean($this->scrub((string) $e->title, $aid), 120) ?: 'Event';
            $this->timed('rsvp-' . (int) $e->id . '@kiddietrac.com', $s, $en, $tz, $title,
                'You were invited. Reply from your invitation email.', 'dashboard', $e->updated_at ?? $e->created_at,
                ['categories' => 'Event', 'location' => $this->clean($this->scrub((string) $e->location, $aid), 120)]);
        }
    }

    /** Conferences booked by this parent or for one of their children. */
    public function parentConferences(int $userId): void
    {
        $mine = [];
        foreach ($this->scope['children'] ?? [] as $c) {
            $mine[(int) $c->id] = $this->childName($c);
        }
        $rows = DB::table('conference_slots')->where('status', 'booked')
            ->whereIn('agency_id', $this->scope['agencies'] ?: [0])
            ->where(fn ($q) => $q->where('booked_by_user_id', $userId)->orWhereIn('booked_child_id', array_keys($mine) ?: [0]))
            ->whereBetween('slot_at', [$this->fromDate() . ' 00:00:00', $this->toDate() . ' 23:59:59'])
            ->get();
        foreach ($rows as $r) {
            $aid = (int) $r->agency_id;
            $tz = $this->tz($aid);
            $s = Carbon::parse((string) $r->slot_at, $tz);   // stored as the centre's wall-clock time
            $child = $mine[(int) $r->booked_child_id] ?? null;
            $teacher = $r->teacher_user_id ? trim((string) DB::table('users')->where('id', $r->teacher_user_id)->value('first_name')) : '';
            $this->timed('conf-' . (int) $r->id . '@kiddietrac.com', $s, $s->copy()->addMinutes(max(10, (int) $r->duration_minutes)), $tz,
                'Parent–teacher conference' . ($child ? ' · ' . $child : ''),
                ($teacher !== '' ? 'With ' . $teacher . '. ' : '') . 'Booked in KiddieTrac.', 'conferences', $r->updated_at ?? $r->created_at,
                ['categories' => 'Conference', 'location' => $this->clean($this->scrub((string) $r->location, $aid), 120)]);
        }
    }

    /** Conferences booked with this educator. No child or family is named in a staff feed. */
    public function staffConferences(int $userId): void
    {
        $rows = DB::table('conference_slots')->where('status', 'booked')->where('teacher_user_id', $userId)
            ->whereIn('agency_id', $this->scope['agencies'] ?: [0])
            ->whereBetween('slot_at', [$this->fromDate() . ' 00:00:00', $this->toDate() . ' 23:59:59'])->get();
        foreach ($rows as $r) {
            $aid = (int) $r->agency_id;
            $tz = $this->tz($aid);
            $s = Carbon::parse((string) $r->slot_at, $tz);
            $this->timed('conf-' . (int) $r->id . '@kiddietrac.com', $s, $s->copy()->addMinutes(max(10, (int) $r->duration_minutes)), $tz,
                'Parent–teacher conference', 'A family booked this time. Open KiddieTrac to see who.', 'conferences', $r->updated_at ?? $r->created_at,
                ['categories' => 'Conference', 'location' => $this->clean($this->scrub((string) $r->location, $aid), 120)]);
        }
    }

    private function childName(object $c): string
    {
        return trim((string) ($c->preferred_name ?: $c->first_name)) ?: 'Your child';
    }

    /** This parent's own children's absences. Reasons only, never the free-text note. */
    public function absences(): void
    {
        $kids = [];
        foreach ($this->scope['children'] ?? [] as $c) {
            $kids[(int) $c->id] = $this->childName($c);
        }
        if (! $kids) return;
        $why = ['sick' => 'sick', 'appointment' => 'appointment', 'holiday' => 'holiday', 'vacation' => 'vacation'];
        $rows = DB::table('child_absences')->whereIn('child_id', array_keys($kids))
            ->whereBetween('absent_on', [$this->fromDate(), $this->toDate()])->orderBy('absent_on')->get(['id', 'child_id', 'absent_on', 'reason', 'created_at']);
        foreach ($rows as $r) {
            $w = $why[strtolower((string) $r->reason)] ?? null;
            $name = $kids[(int) $r->child_id];
            $this->allDay('absence-' . (int) $r->id . '@kiddietrac.com', (string) $r->absent_on, (string) $r->absent_on,
                $name . ' away' . ($w ? ' (' . $w . ')' : ''), $name . ' is marked away in KiddieTrac.', 'today', $r->created_at, ['categories' => 'Absence']);
        }
    }

    /** Approved vacation holds on this parent's own family. */
    public function vacationHolds(): void
    {
        $byFam = [];
        foreach ($this->scope['children'] ?? [] as $c) {
            $byFam[(int) $c->family_id][] = $this->childName($c);
        }
        if (! $byFam) return;
        $rows = DB::table('vacation_holds')->whereIn('family_id', array_keys($byFam))->where('status', 'approved')
            ->where('start_date', '<=', $this->toDate())->where('end_date', '>=', $this->fromDate())->get();
        foreach ($rows as $r) {
            $names = implode(' & ', $byFam[(int) $r->family_id] ?? []);
            $this->allDay('hold-' . (int) $r->id . '@kiddietrac.com', (string) $r->start_date, (string) $r->end_date,
                'Vacation: ' . $names, 'Your approved vacation hold.', 'dashboard', $r->updated_at ?? $r->created_at, ['categories' => 'Absence']);
        }
    }

    /**
     * The child's regular week as two weekly repeating events, drop-off and pick-up, one pair per
     * centre (a child can be with two providers on different days). Times come from the child's
     * profile, else the centre's opening hours. Closed days, absences and vacation days are left
     * out with EXDATE, so a holiday Monday shows no drop-off.
     */
    public function schedule(): void
    {
        $code = ['mon' => 'MO', 'tue' => 'TU', 'wed' => 'WE', 'thu' => 'TH', 'fri' => 'FR', 'sat' => 'SA', 'sun' => 'SU'];
        foreach ($this->scope['children'] ?? [] as $c) {
            $byCentre = [];
            foreach (CareSchedule::week((int) $c->id) as $day => $cell) {
                if ($cell && ! empty($cell['centre_id']) && $this->centreAgency((int) $cell['centre_id'])) {
                    $byCentre[(int) $cell['centre_id']][] = $day;
                }
            }
            $skip = [];
            foreach (DB::table('child_absences')->where('child_id', $c->id)->where('absent_on', '>=', $this->fromDate())->pluck('absent_on') as $d) {
                $skip[substr((string) $d, 0, 10)] = true;
            }
            foreach (DB::table('vacation_holds')->where('family_id', $c->family_id)->where('status', 'approved')->where('end_date', '>=', $this->fromDate())->get(['start_date', 'end_date']) as $h) {
                for ($d = Carbon::parse($h->start_date), $n = 0; $d->lte(Carbon::parse($h->end_date)) && $n < 400; $d->addDay(), $n++) {
                    $skip[$d->toDateString()] = true;
                }
            }
            foreach ($byCentre as $cid => $days) {
                $aid = $this->centreAgency($cid);
                $tz = $this->tz($aid);
                $info = $this->centreInfo[$cid] ?? null;
                $drop = $c->expected_dropoff_time ?: ($info->open_time ?? null);
                $pick = $c->expected_pickup_time ?: ($info->close_time ?? null);
                $today = Carbon::now($tz)->startOfDay();
                $first = $today->copy();
                for ($i = 0; $i < 7 && ! in_array(strtolower($first->format('D')), $days, true); $i++) {
                    $first->addDay();
                }
                $closed = $this->closureDaysFor($cid, $today->toDateString());
                $name = $this->childName($c);
                $label = $this->label($cid);
                foreach ([['drop', 'Drop-off', $drop], ['pick', 'Pick-up', $pick]] as [$k, $word, $time]) {
                    if (! $time) continue;
                    $s = Carbon::parse($first->toDateString() . ' ' . $time, $tz);
                    $ex = [];
                    foreach (array_keys($closed + $skip) as $d) {
                        if ($d >= $first->toDateString() && in_array(strtolower(Carbon::parse($d)->format('D')), $days, true)) {
                            $ex[] = Carbon::parse($d . ' ' . $time, $tz)->format('Ymd\THis');
                        }
                    }
                    sort($ex);
                    $this->timed('sched-' . (int) $c->id . '-' . $cid . '-' . $k . '@kiddietrac.com', $s, $s->copy()->addMinutes(15), $tz,
                        $word . ': ' . $name, $name . '’s usual ' . strtolower($word) . ' at ' . $label . ' (from the child’s regular days). Closed days and absences are left out.',
                        'today', null, ['categories' => 'Schedule', 'location' => $label,
                            'rrule' => 'FREQ=WEEKLY;BYDAY=' . implode(',', array_map(fn ($d) => $code[$d], $days)) . ';UNTIL=' . $this->to->copy()->endOfDay()->format('Ymd\THis\Z'),
                            'exdates' => $ex]);
                }
            }
        }
    }

    private function closureDaysFor(int $centreId, string $from): array
    {
        if (! isset($this->closureDays[$centreId])) {
            $map = Closures::map([$centreId], $from, $this->toDate());
            $this->closureDays[$centreId] = array_fill_keys(array_keys($map[$centreId] ?? []), true);
        }

        return $this->closureDays[$centreId];
    }

    /** The educator's own shifts (stored as the centre's wall-clock time). */
    public function shifts(int $userId): void
    {
        $rows = DB::table('shifts as s')->leftJoin('rooms as r', 'r.id', '=', 's.room_id')
            ->where('s.user_id', $userId)->where('s.status', '!=', 'cancelled')
            ->whereBetween('s.starts_at', [$this->fromDate() . ' 00:00:00', $this->toDate() . ' 23:59:59'])
            ->orderBy('s.starts_at')->get(['s.id', 's.starts_at', 's.ends_at', 's.created_at', 'r.name as room_name', 'r.centre_id']);
        $fallback = $this->scope['agencies'][0] ?? null;
        foreach ($rows as $s) {
            $cid = $s->centre_id ? (int) $s->centre_id : null;
            $aid = $cid ? $this->centreAgency($cid) : $fallback;
            if (! $aid) continue;
            $tz = $this->tz($aid);
            $st = Carbon::parse((string) $s->starts_at, $tz);
            $en = $s->ends_at ? Carbon::parse((string) $s->ends_at, $tz) : $st->copy()->addHours(8);
            $room = $this->clean((string) $s->room_name, 60);
            $this->timed('shift-' . (int) $s->id . '@kiddietrac.com', $st, $en, $tz, 'Shift' . ($room !== '' ? ' · ' . $room : ''),
                'Your scheduled shift' . ($cid ? ' at ' . $this->label($cid) : '') . '.', 'dashboard', $s->created_at,
                ['categories' => 'Shift', 'location' => $cid ? $this->label($cid) : '']);
        }
    }

    /** The educator's own time off (approved, and pending marked as such). The reason stays private. */
    public function timeOff(int $userId): void
    {
        $rows = DB::table('time_off_requests')->where('user_id', $userId)->whereIn('status', ['approved', 'pending'])
            ->whereIn('agency_id', $this->scope['agencies'] ?: [0])
            ->where('start_at', '<=', $this->toDate())->where('end_at', '>=', $this->fromDate())->get();
        foreach ($rows as $t) {
            $what = ucfirst(str_replace('_', ' ', (string) ($t->request_type ?: 'time off')));
            $summary = $what . ($t->status === 'pending' ? ' (pending)' : '');
            $tz = $this->tz((int) $t->agency_id);
            $single = substr((string) $t->start_at, 0, 10) === substr((string) $t->end_at, 0, 10);
            if (! $t->all_day && $single && $t->start_time && $t->end_time) {
                $d = substr((string) $t->start_at, 0, 10);
                $this->timed('timeoff-' . (int) $t->id . '@kiddietrac.com', Carbon::parse($d . ' ' . $t->start_time, $tz), Carbon::parse($d . ' ' . $t->end_time, $tz), $tz,
                    $summary, 'Your ' . strtolower($what) . ($t->status === 'pending' ? ', waiting for approval' : '') . '.', 'dashboard', $t->updated_at ?? $t->created_at, ['categories' => 'Time off']);
            } else {
                $this->allDay('timeoff-' . (int) $t->id . '@kiddietrac.com', (string) $t->start_at, (string) $t->end_at, $summary,
                    'Your ' . strtolower($what) . ($t->status === 'pending' ? ', waiting for approval' : '') . '.', 'dashboard', $t->updated_at ?? $t->created_at, ['categories' => 'Time off', 'transp' => 'OPAQUE']);
            }
        }
    }

    /* ── iCalendar text ──────────────────────────────────────────────────────── */

    /** TEXT escaping, as Meetings::ics() does it (RFC 5545 3.3.11). */
    public static function esc(?string $s): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\r", "\n"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], (string) $s);
    }

    /** 75-octet line folding without splitting a UTF-8 character, as Meetings::ics() does it. */
    public static function fold(string $l): string
    {
        if (strlen($l) <= 75) {
            return $l;
        }
        $out = ''; $cur = ''; $max = 75;
        foreach (mb_str_split($l) as $ch) {
            if (strlen($cur) + strlen($ch) > $max) { $out .= $cur . "\r\n "; $cur = ''; $max = 74; }
            $cur .= $ch;
        }

        return $out . $cur;
    }

    /** A VTIMEZONE with every real transition across the feed's window (no RRULE guessing). */
    public static function vtimezone(string $tz, int $fromTs, int $toTs): array
    {
        $z = new \DateTimeZone($tz);
        $off = function (int $s): string {
            $sign = $s < 0 ? '-' : '+';
            $s = abs($s);

            return $sign . sprintf('%02d%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
        };
        $trans = $z->getTransitions($fromTs, $toTs) ?: [];
        $lines = ['BEGIN:VTIMEZONE', 'TZID:' . $tz];
        if (count($trans) <= 1) {
            $o = $trans[0]['offset'] ?? $z->getOffset(new \DateTime('@' . $fromTs));
            $abbr = $trans[0]['abbr'] ?? 'STD';
            array_push($lines, 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:' . $off($o), 'TZOFFSETTO:' . $off($o), 'TZNAME:' . $abbr, 'END:STANDARD');
        } else {
            // The first entry is the state AT $fromTs; give it its own component so a time
            // before the first change in the window is still covered.
            $prev = $trans[0]['offset'];
            array_push($lines, 'BEGIN:' . ($trans[0]['isdst'] ? 'DAYLIGHT' : 'STANDARD'),
                'DTSTART:' . gmdate('Ymd\THis', $trans[0]['ts'] + $prev), 'TZOFFSETFROM:' . $off($prev), 'TZOFFSETTO:' . $off($prev),
                'TZNAME:' . $trans[0]['abbr'], 'END:' . ($trans[0]['isdst'] ? 'DAYLIGHT' : 'STANDARD'));
            foreach (array_slice($trans, 1) as $t) {
                $k = $t['isdst'] ? 'DAYLIGHT' : 'STANDARD';
                array_push($lines, 'BEGIN:' . $k, 'DTSTART:' . gmdate('Ymd\THis', $t['ts'] + $prev), 'TZOFFSETFROM:' . $off($prev),
                    'TZOFFSETTO:' . $off($t['offset']), 'TZNAME:' . $t['abbr'], 'END:' . $k);
                $prev = $t['offset'];
            }
        }
        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    private function calName(): string
    {
        $agencies = $this->scope['agencies'] ?? [];
        $names = $agencies ? DB::table('agencies')->whereIn('id', $agencies)->orderBy('id')->pluck('name')->all() : [];
        $base = $names ? implode(' / ', $names) : 'My centre';
        if ($this->kind === 'staff') {
            return $base . ' · My work calendar';
        }
        $kids = array_unique(array_map(fn ($c) => $this->childName($c), $this->scope['children'] ?? []));

        return $base . ($kids ? ' · ' . implode(' & ', $kids) : '');
    }

    public function render(): string
    {
        $events = array_values($this->events);
        usort($events, fn ($a, $b) => [$a['start'], $a['uid']] <=> [$b['start'], $b['uid']]);
        $tzs = [];
        foreach ($events as $e) {
            if (! $e['all_day']) $tzs[$e['tz']] = true;
        }
        $primary = $this->scope['agencies'] ? $this->tz((int) $this->scope['agencies'][0]) : 'America/Toronto';
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//KiddieTrac//Calendar sync 1.0//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::esc($this->calName()),
            'X-WR-CALDESC:' . self::esc($this->kind === 'staff' ? 'Closures, events and your shifts. Updates by itself.' : 'Closures, events and your child’s days. Updates by itself.'),
            'X-WR-TIMEZONE:' . $primary,
            'REFRESH-INTERVAL;VALUE=DURATION:PT2H', 'X-PUBLISHED-TTL:PT2H'];
        $fromTs = $this->from->copy()->subDays(2)->getTimestamp();
        $toTs = $this->to->copy()->addDays(40)->getTimestamp();
        foreach (array_keys($tzs) as $tz) {
            $lines = array_merge($lines, self::vtimezone($tz, $fromTs, $toTs));
        }
        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $e['uid'];
            $lines[] = 'DTSTAMP:' . $e['stamp'];
            $lines[] = 'LAST-MODIFIED:' . $e['stamp'];
            if ($e['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:' . $e['start'];
                $lines[] = 'DTEND;VALUE=DATE:' . $e['end'];
            } else {
                $lines[] = 'DTSTART;TZID=' . $e['tz'] . ':' . $e['start'];
                $lines[] = 'DTEND;TZID=' . $e['tz'] . ':' . $e['end'];
                if (! empty($e['rrule'])) {
                    $lines[] = 'RRULE:' . $e['rrule'];
                    foreach (array_chunk($e['exdates'] ?? [], 20) as $chunk) {
                        $lines[] = 'EXDATE;TZID=' . $e['tz'] . ':' . implode(',', $chunk);
                    }
                }
            }
            $lines[] = 'SUMMARY:' . self::esc($e['summary']);
            $lines[] = 'DESCRIPTION:' . self::esc($e['description'] . "\n\nOpen in the app: " . $e['url']);
            if (! empty($e['location'])) $lines[] = 'LOCATION:' . self::esc($e['location']);
            $lines[] = 'URL:' . $e['url'];
            if (! empty($e['categories'])) $lines[] = 'CATEGORIES:' . self::esc($e['categories']);
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'TRANSP:' . $e['transp'];
            $lines[] = 'CLASS:PRIVATE';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }
}
