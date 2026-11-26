<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AgencyController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        // "All agencies" aggregate view: a platform_admin can send
        // X-Active-Agency-Id: all (from the agency switcher) to roll every agency
        // into one overview. Otherwise scope to the SELECTED agency — not just the
        // admin's own assignment (the "agency view leaks another agency" bug).
        $isPlatformAdmin = DB::table("role_assignments")
            ->where("user_id", $request->user()->id)
            ->where("role", "platform_admin")
            ->where("active", true)
            ->exists();
        $allMode = $isPlatformAdmin
            && strtolower(trim((string) $request->header("X-Active-Agency-Id"))) === "all";

        if ($allMode) {
            $agencyId = null;
            $agency = null;
            $centres = \App\Support\Live::centres(DB::table("centres"))->get();   // open only, not just undeleted (2026-10-09)
        } else {
            $agencyId = $this->activeAgencyId($request);
            if (!$agencyId) {
                return response()->json(["message" => "No agency access"], 403);
            }
            $agency = DB::table("agencies")->where("id", $agencyId)->first();
            $centres = \App\Support\Live::centres(DB::table("centres"))->where("agency_id", $agencyId)->get();
        }
        /* A CENTRE DIRECTOR GETS THIS SAME OVERVIEW FOR THEIR OWN CENTRES (2026-11-13, Anthony: "director view of the
           dashboard should show the rooms just like how i see them as super admin with all of the clock in/out and
           children", "things need to be consistent across roles"). App\Support\CentreScope: null = agency-wide
           (admins, exactly as before); a list = only those centres, and every agency-wide figure below (revenue,
           payroll, owner, archived centres, sign-ins of people elsewhere) is left out. Fails closed: no centres, none. */
        $scope = $allMode ? null : \App\Support\CentreScope::ids($request, (int) $agencyId);
        if ($scope !== null) {
            $centres = $centres->whereIn("id", $scope)->values();
        }

        $centreStats = [];
        $totalEnrolled = 0;
        $totalPresentNow = 0;
        $totalReceivables = 0;
        $totalStaffOnFloor = 0;
        /* The agency's day, not UTC's. app.timezone is UTC, so from early evening
           onward Carbon::today() already names TOMORROW — the overview's "here now"
           and today's check-in counts silently switched to an empty new day while the
           centre was still open. Same bug as the platform Logins-today card.
           Bounds rather than whereDate: occurred_at stores UTC, so a local calendar
           day is a RANGE in that column, never a date equality. (2026-08-25) */
        $displayTz = \App\Support\AgencyTime::tz($agencyId);
        $today = Carbon::now($displayTz)->toDateString();
        $dayStartUtc = Carbon::now($displayTz)->startOfDay()->utc();
        $dayEndUtc = Carbon::now($displayTz)->endOfDay()->utc();

        // -- BATCH pre-fetch for ALL in-scope centres (was ~8 queries PER centre =
        //    an N+1). Everything in the loop below now reads from these maps. --
        $__cids = $centres->pluck("id")->all();
        if (empty($__cids)) { $__cids = [0]; }
        $kidsByCentre = [];
        foreach (DB::table("children")->join("families", "families.id", "=", "children.family_id")
            ->whereIn("families.centre_id", $__cids)->where("children.enrollment_status", "enrolled")
            ->whereNull("children.deleted_at")
            ->select("families.centre_id as _cid", "children.id", "children.first_name", "children.last_name", "children.photo_url")
            ->orderBy("children.first_name")->get() as $__k) {
            $kidsByCentre[$__k->_cid][] = $__k;
        }
        $eventsByCentre = [];
        foreach (DB::table("check_events as ci")->join("rooms", "rooms.id", "=", "ci.room_id")
            ->whereIn("rooms.centre_id", $__cids)->whereIn("ci.event_type", ["check_in", "check_out"])
            ->whereBetween("ci.occurred_at", [$dayStartUtc, $dayEndUtc])
            ->select("rooms.centre_id as _cid", "ci.child_id", "ci.event_type", "ci.occurred_at")->get() as $__e) {
            $eventsByCentre[$__e->_cid][] = $__e;
        }
        // Staff on floor = anyone with an OPEN clock-in. There are TWO clock tables:
        // `time_entries` (older flow) AND `time_punches` (the provider/educator clock
        // used by the Provider Daily Overview). Reading only time_entries made a centre
        // with an educator punched in via time_punches show "0 staff" → a FALSE ratio
        // breach (1 child, 1 educator, but flagged understaffed). Merge both; keyed by
        // user_id so a staffer in both counts once.
        $staffByCentre = [];
        foreach (DB::table("time_entries as t")->join("users as u", "u.id", "=", "t.user_id")
            ->whereIn("t.centre_id", $__cids)->whereNull("t.clocked_out_at")
            ->selectRaw("t.centre_id as _cid, t.user_id, TRIM(CONCAT(u.first_name,' ',COALESCE(u.last_name,''))) as nm")->get() as $__s) {
            $staffByCentre[$__s->_cid][$__s->user_id] = $__s->nm;
        }
        foreach (DB::table("time_punches as tp")->join("users as u", "u.id", "=", "tp.user_id")
            ->whereIn("tp.centre_id", $__cids)->whereNull("tp.punched_out_at")
            ->where("tp.punched_in_at", ">=", Carbon::now()->subHours(20))
            ->selectRaw("tp.centre_id as _cid, tp.user_id, TRIM(CONCAT(u.first_name,' ',COALESCE(u.last_name,''))) as nm")->get() as $__s) {
            $staffByCentre[$__s->_cid][$__s->user_id] = $__s->nm;
        }
        // Provider photo fallback: a home-daycare provider-centre often has no logo of
        // its own, but the provider PERSON uploaded a profile photo on their user record.
        // Match that person by EMAIL (centre.email == user.email) — NOT "any educator
        // assigned to the centre", which grabbed the wrong face when a shared/demo
        // educator (e.g. Safia Ali) is assigned to many provider-centres.
        $provPhotoByCentre = [];
        foreach (DB::table('centres as c')->join('users as u', DB::raw('LOWER(u.email)'), '=', DB::raw('LOWER(c.email)'))
            ->whereIn('c.id', $__cids ?: [0])->whereNotNull('c.email')
            ->whereNotNull('u.photo_url')->whereNull('u.deleted_at')
            ->get(['c.id as cid', 'u.photo_url']) as $__pp) {
            if (empty($provPhotoByCentre[$__pp->cid])) $provPhotoByCentre[$__pp->cid] = $__pp->photo_url;
        }

        /* Provider clock in / out for the card, the way children have check in / out
           (Anthony, 2026-10-15). The provider PERSON is matched by email like the photo
           above. Shifts come from BOTH clock tables (see staffByCentre): anything
           punched in today, plus a shift still open from before midnight. */
        $provUserByCentre = [];
        foreach (DB::table('centres as c')->join('users as u', DB::raw('LOWER(u.email)'), '=', DB::raw('LOWER(c.email)'))
            ->whereIn('c.id', $__cids)->whereNotNull('c.email')->whereNull('u.deleted_at')
            ->orderBy('u.id')
            ->get(['c.id as cid', 'u.id as uid', 'u.first_name', 'u.last_name', 'u.photo_url']) as $__pu) {
            if (empty($provUserByCentre[$__pu->cid])) $provUserByCentre[$__pu->cid] = $__pu;
        }
        $shiftsByCentre = [];
        $__addShift = function ($cid, $uid, $nm, $photo, $in, $out) use (&$shiftsByCentre) {
            $s = $shiftsByCentre[$cid][$uid] ?? ["name" => $nm, "photo_url" => $photo, "in" => null, "out" => null, "open" => false, "n" => 0];
            $s["n"]++;   // how many clock-ins that day: more than one = a split day or a lunch
            if ($in && (! $s["in"] || $in < $s["in"])) $s["in"] = $in;
            if ($out === null) { $s["open"] = true; }
            elseif (! $s["out"] || $out > $s["out"]) { $s["out"] = $out; }
            $shiftsByCentre[$cid][$uid] = $s;
        };
        $__openFloor = Carbon::now()->subHours(20);
        foreach ([["time_punches", "punched_in_at", "punched_out_at"], ["time_entries", "clocked_in_at", "clocked_out_at"]] as [$__tbl, $__inC, $__outC]) {
            foreach (DB::table("$__tbl as s")->join("users as u", "u.id", "=", "s.user_id")
                ->whereIn("s.centre_id", $__cids)
                ->where(function ($q) use ($__inC, $__outC, $dayStartUtc, $dayEndUtc, $__openFloor) {
                    $q->whereBetween("s.$__inC", [$dayStartUtc, $dayEndUtc])
                      ->orWhere(function ($q2) use ($__inC, $__outC, $__openFloor) {
                          $q2->whereNull("s.$__outC")->where("s.$__inC", ">=", $__openFloor);
                      });
                })
                ->selectRaw("s.centre_id as _cid, s.user_id, s.$__inC as t_in, s.$__outC as t_out, u.photo_url, TRIM(CONCAT(u.first_name,' ',COALESCE(u.last_name,''))) as nm")
                ->get() as $__s) {
                $__addShift($__s->_cid, $__s->user_id, $__s->nm, $__s->photo_url, $__s->t_in, $__s->t_out);
            }
        }

        // Everyone assigned to each home, clocked in or not (see App\Support\CentreTeam).
        $teamByCentre = \App\Support\CentreTeam::forCentres($__cids, $today);

        $recByCentre = [];
        foreach (DB::table("invoices")->whereIn("centre_id", $__cids)
            ->whereIn("status", ["sent", "partial", "overdue"])->groupBy("centre_id")
            ->selectRaw("centre_id, SUM(balance_due) as bal")->get() as $__r) {
            $recByCentre[$__r->centre_id] = (float) $__r->bal;
        }
        // Fold in externally-synced (integration) invoices so agencies whose
        // billing lives in a connected system show correct receivables, not $0.
        if (\Illuminate\Support\Facades\Schema::hasTable('external_invoices')) {
            foreach (DB::table('external_invoices as ei')
                ->join('families as f', 'f.id', '=', 'ei.family_id')
                ->whereIn('f.centre_id', $__cids)
                ->whereNotIn('ei.status', ['paid', 'void'])
                ->groupBy('f.centre_id')
                ->selectRaw('f.centre_id as cid, SUM(ei.balance_due) as bal')->get() as $__r) {
                $recByCentre[$__r->cid] = ($recByCentre[$__r->cid] ?? 0) + (float) $__r->bal;
            }
        }
        $roomsByCentre = [];
        foreach (DB::table("rooms")->whereIn("centre_id", $__cids)->where("active", true)
            ->groupBy("centre_id")->selectRaw("centre_id, COUNT(*) as n")->get() as $__r) {
            $roomsByCentre[$__r->centre_id] = (int) $__r->n;
        }

        // Per-agency timezone for displaying check-in/out times (app tz is UTC; an
        // Ontario agency must read Eastern, not UTC). Map once; default Toronto.
        $agencyTz = DB::table('agencies')->pluck('timezone', 'id');

        /* WHO IS SCHEDULED TODAY (2026-11-01, Anthony: "for educators and children not
           scheduled to work - mark them as not scheduled in the your provider cards"). One
           rule, App\Support\ScheduledToday, shared with the Daily Overview. "Today" is each
           centre's own agency date (the all-agencies view can span zones). */
        $__dateByCentre = [];
        foreach ($centres as $__c) {
            $__dateByCentre[(int) $__c->id] = Carbon::now($agencyTz[$__c->agency_id] ?? 'America/Toronto')->toDateString();
        }
        $__openByCentre = [];
        $__kidSched = [];
        foreach (array_unique($__dateByCentre) as $__d) {
            $__ids = array_keys(array_filter($__dateByCentre, fn ($x) => $x === $__d));
            $__open = \App\Support\ScheduledToday::centresOpen($__ids, $__d);
            $__openByCentre += $__open;
            $__kids = [];
            foreach ($__ids as $__cid) {
                $__kids[$__cid] = array_map(fn ($k) => (int) $k->id, $kidsByCentre[$__cid] ?? []);
            }
            $__kidSched += \App\Support\ScheduledToday::children($__kids, $__d, $__open);
        }

        foreach ($centres as $c) {
            $ctz = $agencyTz[$c->agency_id] ?? 'America/Toronto';
            // Enrolled count — count children directly by enrollment_status at the
            // centre (via their family). The old room-join missed children who are
            // enrolled but not yet assigned to a room (e.g. agency-imported kids
            // whose centres have no rooms), so agencies like iLearn showed 0.
            $enrolled = count($kidsByCentre[$c->id] ?? []);

            // Present right now
            $__last = [];
            foreach (($eventsByCentre[$c->id] ?? []) as $__e) {
                if (! isset($__last[$__e->child_id]) || $__e->occurred_at >= $__last[$__e->child_id]["at"]) {
                    $__last[$__e->child_id] = ["at" => $__e->occurred_at, "type" => $__e->event_type];
                }
            }
            $presentNow = 0;
            foreach ($__last as $__l) { if ($__l["type"] === "check_in") { $presentNow++; } }

            // Staff on floor — DISTINCT users with an open shift (a staffer with
            // two stray open entries must count once, not twice).
            $staffOnFloor = count($staffByCentre[$c->id] ?? []);

            // Receivables
            $receivables = $recByCentre[$c->id] ?? 0.0;

            // Room count (no deleted_at on rooms; use active flag)
            $roomCount = $roomsByCentre[$c->id] ?? 0;

            // Simplified breach: total kids vs total staff on floor at the centre
            $breach = ($presentNow > 0 && $staffOnFloor === 0);

            // Today's attendance roster for the card: every enrolled child with
            // their avatar + status + check-in / check-out times.
            $enrolledKids = $kidsByCentre[$c->id] ?? [];
            $todaysEvents = $eventsByCentre[$c->id] ?? [];
            /* VISITS (2026-11-05). Status was "out" when the child had ANY check-out today,
               so a child signed out at 10 and back at 1 sat in the Out column, and off the
               present list, all afternoon. Status is the last event; the times are the
               first arrival and the final sign-out (none while back in). */
            $__byKid = [];
            foreach ($todaysEvents as $e) { $__byKid[$e->child_id][] = $e; }
            $inAt = []; $outAt = []; $visitsOf = [];
            foreach ($__byKid as $__kid => $__list) {
                usort($__list, fn ($a, $b) => strcmp((string) $a->occurred_at, (string) $b->occurred_at));
                $__v = \App\Support\ChildVisits::pair($__list);
                $__fi = \App\Support\ChildVisits::firstIn($__v);
                $__fo = \App\Support\ChildVisits::finalOut($__v);
                if ($__fi) $inAt[$__kid] = $__fi->occurred_at;
                if ($__fo) $outAt[$__kid] = $__fo->occurred_at;
                $visitsOf[$__kid] = $__v;
            }
            $roster = [];
            $checkedInCount = 0;
            $notInCount = 0;
            $notScheduledCount = 0;
            foreach ($enrolledKids as $k) {
                $ci = $inAt[$k->id] ?? null;
                $co = $outAt[$k->id] ?? null;
                $sched = $__kidSched[(int) $c->id][(int) $k->id] ?? true;
                // Not booked today and not here: "Not scheduled", never "Not in" / "Absent",
                // and not in the absent tally. A child who came anyway keeps their times.
                $status = ! $ci ? ($sched ? "absent" : "unscheduled") : ($co ? "out" : "in");
                if ($ci) $checkedInCount++;
                elseif ($sched) $notInCount++;
                else $notScheduledCount++;
                $roster[] = [
                    "id"           => $k->id,
                    "name"         => trim($k->first_name . " " . ($k->last_name ?? "")),
                    "photo_url"    => $k->photo_url,
                    "status"       => $status,
                    "scheduled"    => $sched,
                    "check_in_at"  => $ci ? Carbon::parse($ci)->timezone($ctz)->format("g:i A") : null,
                    "check_out_at" => $co ? Carbon::parse($co)->timezone($ctz)->format("g:i A") : null,
                    // More than one visit today: every one, for the card's tooltip / line.
                    "visits"       => count($visitsOf[$k->id] ?? []),
                    "visits_text"  => count($visitsOf[$k->id] ?? []) > 1 ? \App\Support\ChildVisits::text($visitsOf[$k->id], $ctz, 'now') : null,
                ];
            }
            // Present-first, then absent; each group alphabetical (already ordered).
            usort($roster, function ($a, $b) {
                $rank = ["in" => 0, "out" => 1, "absent" => 2, "unscheduled" => 3];
                return [$rank[$a["status"]], $a["name"]] <=> [$rank[$b["status"]], $b["name"]];
            });

            // Names behind the Present + Staff counters (for hover on the card).
            $presentChildren = [];
            foreach ($roster as $rr) {
                if ($rr["status"] === "in") $presentChildren[] = $rr["name"];
            }
            $staffPresent = array_values(array_unique(array_values($staffByCentre[$c->id] ?? [])));
            sort($staffPresent);

            /* END OF THE DAY (2026-10-15, Anthony: "show Absent at the end of the shift and
               during the shift if they are not in keep the not in and not clocked in
               statuses"). Children: past the centre's closing time (else the agency's
               automatic sign-off time for children, else 6 p.m.). The provider: past their
               rostered end today, else the same closing time. Wall clock, agency zone. */
            $__nowL = Carbon::now($ctz);
            $__close = $c->close_time ?? null;
            if (! $__close) {
                static $__autoAt = [];
                if (! array_key_exists($c->agency_id, $__autoAt)) {
                    try { $__autoAt[$c->agency_id] = \App\Http\Controllers\Api\AutoSignOffController::read((int) $c->agency_id)['children_at'] ?? null; }
                    catch (\Throwable $e) { $__autoAt[$c->agency_id] = null; }
                }
                $__close = $__autoAt[$c->agency_id] ?: '18:00:00';
            }
            $dayOver = $__nowL->format('H:i:s') >= substr((string) $__close . ':00', 0, 8);

            // Provider (and anyone else who clocked at this centre today): same shape
            // as a roster row. The provider leads, and shows "not in" when they have
            // not clocked in today, exactly like a child who has not arrived.
            $__fmt = fn ($t) => $t ? Carbon::parse($t, 'UTC')->timezone($ctz)->format("g:i A") : null;
            $staffShifts = [];
            $__prov = $provUserByCentre[$c->id] ?? null;
            $__sh = $shiftsByCentre[$c->id] ?? [];
            if ($__prov && ! isset($__sh[$__prov->uid])) {
                $__end = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')
                    ->where('r.centre_id', $c->id)->where('s.user_id', $__prov->uid)->whereIn('s.status', ['scheduled', 'active'])
                    ->whereRaw('DATE(s.starts_at) = ?', [$__nowL->toDateString()])->max('s.ends_at');
                /* The provider was always "scheduled", so on a Sunday or a closure day she
                   read "Not clocked in" / "Absent". Now the shared rule (ScheduledToday). */
                $staffShifts[] = ["name" => trim($__prov->first_name . ' ' . ($__prov->last_name ?? '')), "photo_url" => $__prov->photo_url,
                    "is_provider" => true, "status" => "absent",
                    "scheduled" => \App\Support\ScheduledToday::provider((int) $c->id, (int) $__prov->uid, $__dateByCentre[(int) $c->id], ! empty($__openByCentre[(int) $c->id]), (bool) $__end),
                    "clock_in_at" => null, "clock_out_at" => null,
                    "day_over" => $__end ? $__nowL->format('Y-m-d H:i:s') >= (string) $__end : $dayOver];
            }
            /* The rest of the home's team, clocked in or not (2026-10-21). A second educator
               used to appear only after clocking in, so Rachel Blane at Bruni's home was never
               on the card at all. Someone rostered today reads "Not clocked in" / "Absent" like
               the provider; someone with no shift today reads "Not scheduled", not absent. */
            foreach (($teamByCentre[$c->id] ?? []) as $__uid => $__m) {
                if (isset($__sh[$__uid]) || ($__prov && (int) $__prov->uid === (int) $__uid)) { continue; }
                $staffShifts[] = ["name" => $__m["name"], "photo_url" => $__m["photo_url"],
                    "is_provider" => false, "status" => "absent",
                    "scheduled" => $__m["is_provider"]
                        ? \App\Support\ScheduledToday::provider((int) $c->id, (int) $__uid, $__dateByCentre[(int) $c->id], ! empty($__openByCentre[(int) $c->id]), $__m["scheduled"])
                        : $__m["scheduled"],
                    "clock_in_at" => null, "clock_out_at" => null,
                    "day_over" => $__m["shift_end"] ? $__nowL->format('Y-m-d H:i:s') >= $__m["shift_end"] : $dayOver];
            }
            // On lunch / a break: their rows are closed but the day is not over (StaffBreaks, 2026-11-05).
            $__brk = \App\Support\StaffBreaks::currentFor(array_keys($__sh));
            foreach ($__sh as $__uid => $s) {
                $__b = (! $s["open"] && isset($__brk[(int) $__uid])) ? $__brk[(int) $__uid] : null;
                $staffShifts[] = ["name" => $s["name"], "photo_url" => $s["photo_url"],
                    "is_provider" => $__prov && (int) $__prov->uid === (int) $__uid,
                    "status" => $s["open"] ? "in" : ($__b ? "break" : "out"),
                    "break_kind" => $__b ? $__b->kind : null,
                    "break_since" => $__b ? $__fmt($__b->started_at) : null,
                    "shifts" => (int) ($s["n"] ?? 1),
                    "clock_in_at" => $__fmt($s["in"]), "clock_out_at" => $s["open"] ? null : $__fmt($s["out"])];
            }
            usort($staffShifts, fn ($a, $b) => [! $a["is_provider"], $a["name"]] <=> [! $b["is_provider"], $b["name"]]);

            // Provider person's full name (first + last), when the record carries it,
            // so the overview card can show "Cassandra Miller" rather than just the
            // centre/provider `name` field. Empty for records that were only given a
            // partial name — those still fall back to `name` on the card.
            $__provFull = trim(($c->supervisor_first_name ?? '') . ' ' . ($c->supervisor_last_name ?? ''));
            $centreStats[] = [
                "id" => $c->id,
                "name" => $c->name,
                "provider_name" => $__provFull !== '' ? $__provFull : null,
                "supervisor_first_name" => $c->supervisor_first_name ?? null,
                "supervisor_last_name" => $c->supervisor_last_name ?? null,
                "city" => $c->city,
                "enrolled" => $enrolled,
                "present_now" => $presentNow,
                "present_children" => $presentChildren,
                "staff_present" => $staffPresent,
                "team" => array_values(array_map(fn ($m) => $m["name"], $teamByCentre[$c->id] ?? [])),
                "roster" => $roster,
                "staff_shifts" => $staffShifts,
                "day_over" => $dayOver,
                "checked_in_count" => $checkedInCount,
                "not_checked_in_count" => $notInCount,            // scheduled today and not here
                "not_scheduled_count" => $notScheduledCount,      // not booked today and not here
                "staff_on_floor" => $staffOnFloor,
                "receivables" => (float) $receivables,
                "room_count" => $roomCount,
                "rooms_in_breach" => $breach ? 1 : 0,
                "license_capacity" => $c->license_capacity,
                /* TWO DIFFERENT QUESTIONS, deliberately kept apart.

                   capacity_pct  — how full the ROSTER is: enrolled ÷ licensed. An
                   enrolment-planning number that barely moves week to week. The admin
                   Centres list wants this one.

                   occupancy_pct — how full the ROOM is RIGHT NOW: children currently
                   checked in ÷ the maximum allowed at one time. This is the live
                   number the overview cards need.

                   The provider cards were drawing their donut from capacity_pct, so a
                   provider with nobody on site still read 83%. Same denominator, wrong
                   numerator — and because both are "a percentage of capacity" the card
                   looked plausible. (2026-08-25) */
                "capacity_pct" => $c->license_capacity ? round(($enrolled / max(1, $c->license_capacity)) * 100) : 0,
                "occupancy_pct" => $c->license_capacity ? round(($presentNow / max(1, $c->license_capacity)) * 100) : 0,
                // v22p3.4: per-centre branding for the agency dashboard cards
                "logo_url"     => $c->logo_url ?? null,
                "provider_photo_url" => $provPhotoByCentre[$c->id] ?? null,
                "brand_color"  => $c->brand_color ?? null,
                "accent_color" => $c->accent_color ?? null,
                "tagline"      => $c->tagline ?? null,
            ];

            $totalEnrolled += $enrolled;
            $totalPresentNow += $presentNow;
            $totalReceivables += $receivables;
            $totalStaffOnFloor += $staffOnFloor;
        }

        // Recent activity — a single time-sorted feed merging user sign-ins,
        // children checking in/out, staff clocking in/out, and incidents.
        $centreIds = $centres->pluck("id")->all();
        $userIdsInAgency = $scope !== null
            // A director: the people of their own centres - staff placed there, and those centres' parents.
            ? DB::table("role_assignments")->whereIn("centre_id", $centreIds ?: [0])->pluck("user_id")
                ->merge(DB::table("guardians as g")->join("families as f", "f.id", "=", "g.family_id")
                    ->whereIn("f.centre_id", $centreIds ?: [0])->whereNotNull("g.user_id")->pluck("g.user_id"))
                ->unique()->values()->all()
            : DB::table("role_assignments")
            ->where(function ($q) use ($agencyId) {
                $q->where("agency_id", $agencyId)
                  ->orWhereIn("centre_id", function ($qq) use ($agencyId) {
                      $qq->select("id")->from("centres")->where("agency_id", $agencyId);
                  });
            })->pluck("user_id")->unique()->all();

        $events = collect();

        // Role label per person so the feed reads "Safia Ali (Educator) signed in" and
        // children are marked "(Child)" — far easier to scan at a glance.
        $roleLabelMap = [];
        $__rank = ["agency_admin" => 0, "platform_admin" => 0, "centre_director" => 1, "home_visitor" => 2, "educator" => 3, "auditor" => 4, "sales_rep" => 5, "guardian" => 6];
        $__labelFor = ["agency_admin" => "Admin", "platform_admin" => "Admin", "centre_director" => "Director", "educator" => "Educator", "home_visitor" => "Home visitor", "auditor" => "Auditor", "sales_rep" => "Sales rep", "guardian" => "Parent"];
        foreach (DB::table("role_assignments")->whereIn("user_id", $userIdsInAgency ?: [0])->where("active", true)->get(["user_id", "role"]) as $__ra) {
            $cur = $roleLabelMap[$__ra->user_id] ?? null;
            if ($cur === null || ($__rank[$__ra->role] ?? 9) < ($__rank[$cur] ?? 9)) $roleLabelMap[$__ra->user_id] = $__ra->role;
        }
        foreach (DB::table("guardians")->whereIn("user_id", $userIdsInAgency ?: [0])->whereNotNull("user_id")->pluck("user_id") as $__gid) {
            if (empty($roleLabelMap[$__gid])) $roleLabelMap[$__gid] = "guardian";
        }
        $roleLbl = function ($uid) use ($roleLabelMap, $__labelFor) { $r = $roleLabelMap[$uid] ?? null; return $r ? ($__labelFor[$r] ?? ucfirst(str_replace("_", " ", $r))) : null; };

        $loginQ = DB::table("audit_logs as a")->leftJoin("users as u", "u.id", "=", "a.user_id")
            ->whereIn("a.action", ["login", "logout"])->orderByDesc("a.created_at")->orderByDesc("a.id")->limit(25)
            ->select("a.action", "a.user_id", "a.created_at", "u.first_name", "u.last_name", "u.photo_url");
        if (! $allMode) $loginQ->whereIn("a.user_id", $userIdsInAgency ?: [0]);
        foreach ($loginQ->get() as $r) {
            $nm = trim(($r->first_name ?? "") . " " . ($r->last_name ?? "")) ?: "A user";
            $lbl = $roleLbl($r->user_id);
            $who = $nm . ($lbl ? " (" . $lbl . ")" : "");
            $out = $r->action === "logout";
            $events->push(["icon" => $out ? "🚪" : "🔑", "text" => $who . ($out ? " signed out" : " signed in"), "when" => $r->created_at, "photo_url" => $r->photo_url ?? null, "name" => $nm]);
        }

        if (! empty($centreIds)) {
            foreach (DB::table("check_events as ce")->join("rooms as r", "r.id", "=", "ce.room_id")
                ->join("children as ch", "ch.id", "=", "ce.child_id")
                ->whereIn("r.centre_id", $centreIds)->orderByDesc("ce.occurred_at")->limit(40)
                ->select("ce.event_type", "ce.occurred_at", "ch.photo_url",
                    DB::raw("TRIM(CONCAT(ch.first_name,' ',COALESCE(ch.last_name,''))) as nm"))->get() as $r) {
                $in = $r->event_type === "check_in";
                $events->push(["icon" => $in ? "🟢" : "⚪", "text" => $r->nm . " (Child)" . ($in ? " checked in" : " checked out"), "when" => $r->occurred_at, "photo_url" => $r->photo_url ?? null, "name" => $r->nm]);
            }
            foreach (DB::table("time_entries as t")->join("users as u", "u.id", "=", "t.user_id")
                ->whereIn("t.centre_id", $centreIds)->orderByDesc("t.clocked_in_at")->limit(25)
                ->select("t.clocked_in_at", "t.clocked_out_at", "u.id as uid", "u.photo_url",
                    DB::raw("TRIM(CONCAT(u.first_name,' ',COALESCE(u.last_name,''))) as nm"))->get() as $r) {
                $lbl = $roleLbl($r->uid); $who = $r->nm . ($lbl ? " (" . $lbl . ")" : "");
                $events->push(["icon" => "🟩", "text" => $who . " clocked in", "when" => $r->clocked_in_at, "photo_url" => $r->photo_url ?? null, "name" => $r->nm]);
                if ($r->clocked_out_at) $events->push(["icon" => "🟥", "text" => $who . " clocked out", "when" => $r->clocked_out_at, "photo_url" => $r->photo_url ?? null, "name" => $r->nm]);
            }
            foreach (DB::table("incidents as inc")->join("children as ch", "ch.id", "=", "inc.child_id")
                ->join("families as f", "f.id", "=", "ch.family_id")->whereIn("f.centre_id", $centreIds)
                ->orderByDesc("inc.occurred_at")->limit(15)
                ->select("inc.occurred_at", "inc.incident_type", "ch.photo_url",
                    DB::raw("TRIM(CONCAT(ch.first_name,' ',COALESCE(ch.last_name,''))) as nm"))->get() as $r) {
                $events->push(["icon" => "🩹", "text" => "Incident (" . str_replace("_", " ", (string) $r->incident_type) . ") — " . $r->nm . " (Child)", "when" => $r->occurred_at, "photo_url" => $r->photo_url ?? null, "name" => $r->nm]);
            }
        }

        $recentActivity = $events->filter(fn ($e) => ! empty($e["when"]))
            ->sortByDesc("when")->take(60)->values()
            ->map(fn ($e) => ["icon" => $e["icon"], "text" => $e["text"], "created_at" => $e["when"], "action" => $e["text"], "photo_url" => $e["photo_url"] ?? null, "name" => $e["name"] ?? null]);

        // Extra headline metrics for the overview stat cards.
        $totalFamilies = DB::table("families")->whereIn("centre_id", $__cids ?: [0])->whereNull("deleted_at")->count();
        $staffQ = DB::table("role_assignments")->where("active", true)->whereIn("role", ["educator", "centre_director"]);
        if ($scope !== null) { $staffQ->whereIn("centre_id", $__cids ?: [0]); }
        elseif (! $allMode) { $staffQ->where(function ($q) use ($agencyId, $__cids) { $q->where("agency_id", $agencyId)->orWhereIn("centre_id", $__cids ?: [0]); }); }
        $totalStaff = $staffQ->distinct()->count("user_id");
        $overdueInvoices = DB::table("invoices")->whereIn("centre_id", $__cids ?: [0])->where("status", "overdue")->count();
        if (\Illuminate\Support\Facades\Schema::hasTable('external_invoices')) {
            $overdueInvoices += (int) DB::table('external_invoices as ei')->join('families as f', 'f.id', '=', 'ei.family_id')
                ->whereIn('f.centre_id', $__cids ?: [0])->where('ei.status', 'overdue')->count();
        }

        // Agency owner = the earliest agency_admin, reachable directly (agency_id)
        // or through one of the agency's centres. Shown in the Edit-agency window.
        $owner = null;
        if (! $allMode && $scope === null) {
            // Prefer an agency_admin (the true owner); fall back to the earliest
            // centre_director where an agency has no admin yet. EXCLUDE platform
            // admins (super admins) — a super admin who also holds an agency_admin
            // row must not be shown as the agency's owner (that was showing every
            // agency's owner as the super admin).
            $ownerRow = DB::table("role_assignments as ra")->join("users as u", "u.id", "=", "ra.user_id")
                ->whereIn("ra.role", ["agency_admin", "centre_director"])->where("ra.active", true)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))->from("role_assignments as pa")
                      ->whereColumn("pa.user_id", "u.id")->where("pa.role", "platform_admin")->where("pa.active", true);
                })
                // Skip the iLearn↔KiddieTrac sync service account (integration+…@) —
                // it's the earliest admin but isn't a real person / owner.
                ->where("u.email", "not like", "integration+%")
                ->where(function ($q) use ($agencyId) {
                    $q->where("ra.agency_id", $agencyId)
                      ->orWhereIn("ra.centre_id", function ($qq) use ($agencyId) {
                          $qq->select("id")->from("centres")->where("agency_id", $agencyId);
                      });
                })
                ->orderByRaw("CASE WHEN ra.role = 'agency_admin' THEN 0 ELSE 1 END")
                ->orderBy("ra.created_at")
                ->first(["u.first_name", "u.last_name", "u.email", "u.phone", "u.photo_url", "ra.role"]);

            // An explicit owner set by a super admin (Edit agency) wins over the
            // derived one — lets them correct it when the auto-pick is wrong.
            $explicit = [];
            try {
                $sj = is_string($agency->settings ?? null) ? (json_decode($agency->settings, true) ?: []) : [];
                $explicit = is_array($sj['owner'] ?? null) ? $sj['owner'] : [];
            } catch (\Throwable $e) {}

            // Resolve the NAMED owner's avatar/phone from their user record (by
            // email) so the card shows the right photo — the explicit name/email
            // and the derived admin can be different people (that's why the avatar
            // came back blank for an explicitly-set owner).
            $ownerUser = null;
            $lookupEmail = $explicit['email'] ?? ($ownerRow->email ?? null);
            if (! empty($lookupEmail)) {
                $ownerUser = DB::table("users")->whereRaw("LOWER(TRIM(email)) = ?", [mb_strtolower(trim($lookupEmail))])
                    ->whereNull("deleted_at")->first(["first_name", "last_name", "phone", "photo_url"]);
            }

            $derivedName = $ownerRow ? (trim(($ownerRow->first_name ?? "") . " " . ($ownerRow->last_name ?? "")) ?: null) : null;
            $owner = [
                "name" => ($explicit['name'] ?? null) ?: $derivedName,
                "email" => ($explicit['email'] ?? null) ?: ($ownerRow->email ?? null),
                "phone" => ($ownerUser->phone ?? null) ?: ($ownerRow->phone ?? null),
                "photo_url" => ($ownerUser->photo_url ?? null) ?: ($ownerRow->photo_url ?? null),
                "role" => $ownerRow->role ?? null,
                "explicit" => ! empty($explicit['name']) || ! empty($explicit['email']),
                "contact_email" => $agency->contact_email ?? null,
                "contact_phone" => $agency->contact_phone ?? null,
            ];
        }

        return response()->json([
            "agency" => [
                "id" => $agency->id ?? null,
                "name" => $allMode ? "All agencies" : ($agency->name ?? "Agency"),
                "centre_count" => $centres->count(),
                "logo_url" => $agency ? ($agency->logo_url ?: ($agency->brand_logo_url ?? null)) : null,
                "brand_color" => $agency->brand_primary_color ?? null,
                "owner" => $owner,
            ],
            "totals" => array_merge($this->overviewExtras($allMode ? null : ($agency->id ?? null), $centres, $scope), [
                "enrolled" => $totalEnrolled,
                "present_now" => $totalPresentNow,
                "staff_on_floor" => $totalStaffOnFloor,
                "receivables" => (float) $totalReceivables,
                "families" => $totalFamilies,
                "staff_total" => $totalStaff,
                "overdue_invoices" => $overdueInvoices,
            ]),
            /* The AGENCY's revenue — what families owe them — as distinct from the
               platform MRR widgets, which show what the agency pays KiddieTrac. */
            "revenue" => $scope !== null ? null : (function () use ($agency) {
                try {
                    $aid = $agency->id ?? null;
                    if (! $aid || ! \Illuminate\Support\Facades\Schema::hasTable("external_invoices")) {
                        return null;
                    }
                    /* NATIVE BILLING (2026-10-28). This read only external_invoices (the iLearn sync), so an agency that
                       invoices inside KiddieTrac — Brightwood, the California demo — showed $0 collected. With no synced
                       invoices at all, the agency's own invoices and payments answer instead. An agency that has synced
                       ones (iLearn) is unchanged. */
                    if (! DB::table("external_invoices")->where("agency_id", $aid)->exists()) {
                        $mStart = \App\Support\AgencyTime::boundaryUtc(null, 'month')->toDateTimeString();
                        $today = \App\Support\AgencyTime::todayDate()->toDateString();
                        $inv = DB::table("invoices as i")->join("centres as c", "c.id", "=", "i.centre_id")
                            ->where("c.agency_id", $aid)->whereNotIn("i.status", ["void", "draft", "refunded"]);
                        $od = (clone $inv)->where("i.balance_due", ">", 0)->whereNotNull("i.due_at")->where("i.due_at", "<", $today);
                        $paid = DB::table("payments as p")->join("families as f", "f.id", "=", "p.family_id")->join("centres as c", "c.id", "=", "f.centre_id")
                            ->where("c.agency_id", $aid)->where("p.status", "succeeded")->where("p.paid_at", ">=", $mStart);

                        return [
                            "collected_this_month" => round((float) $paid->sum("p.amount"), 2),
                            "outstanding"    => round((float) (clone $inv)->sum("i.balance_due"), 2),
                            "overdue"        => round((float) (clone $od)->sum("i.balance_due"), 2),
                            "overdue_count"  => (int) (clone $od)->count(),
                            "billed_total"   => round((float) (clone $inv)->sum("i.total"), 2),
                            "currency"       => DB::table("agencies")->where("id", $aid)->value("currency") ?: "CAD",
                        ];
                    }
                    $base = DB::table("external_invoices")->where("agency_id", $aid)
                        /* Voided invoices keep a non-zero balance_due, so counting them
                           overstates what is owed. They are cancelled, not outstanding. */
                        ->where("status", "!=", "void");
                    $monthStart = \App\Support\AgencyTime::boundaryUtc(null, 'month')->toDateTimeString();
                    $overdue = (clone $base)->where("balance_due", ">", 0)
                        ->whereNotNull("due_at")->where("due_at", "<", Carbon::now());

                    return [
                        "collected_this_month" => round((float) (clone $base)
                            ->where("external_updated_at", ">=", $monthStart)->sum("amount_paid"), 2),
                        "outstanding"    => round((float) (clone $base)->sum("balance_due"), 2),
                        "overdue"        => round((float) (clone $overdue)->sum("balance_due"), 2),
                        "overdue_count"  => (int) (clone $overdue)->count(),
                        "billed_total"   => round((float) (clone $base)->sum("total"), 2),
                        "currency"       => (clone $base)->value("currency") ?: "CAD",
                    ];
                } catch (\Throwable $e) {
                    // The dashboard must render even if billing cannot be read.
                    return null;
                }
            })(),
            "centres" => $centreStats,
            "scoped" => $scope !== null,
            "archived_centres" => $scope !== null ? [] : DB::table("centres")
                ->whereNotNull("deleted_at")
                ->when(! $allMode, fn ($q) => $q->where("agency_id", $agencyId))
                ->orderByDesc("deleted_at")
                ->get(["id", "name", "city"])
                ->map(fn ($c) => ["id" => $c->id, "name" => $c->name, "city" => $c->city])
                ->all(),
            // $recentActivity is already a formatted list of {icon,text,created_at,action}.
            "recent_activity" => $recentActivity->values()->all(),
        ]);
    }

    /**
     * Resolve which agency to scope to. Honors the X-Active-Agency-Id header
     * (mirrors AdminController::getAgencyId): a platform_admin may target ANY
     * agency the header names (defaulting to the first agency if absent); an
     * agency_admin may only target an agency they are assigned to, else their
     * own first assignment.
     */
    private function activeAgencyId(Request $request): ?int
    {
        $user = $request->user();
        $isPlatformAdmin = DB::table("role_assignments")
            ->where("user_id", $user->id)
            ->where("role", "platform_admin")
            ->where("active", true)
            ->exists();
        $activeId = (int) $request->header("X-Active-Agency-Id");

        if ($isPlatformAdmin) {
            // SECURITY (v22p97): a platform_admin must EXPLICITLY select an agency.
            // The old "default to the first agency" silently rendered iLearn's
            // dashboard to a super-admin who hadn't picked an agency yet — return
            // null (→ "select an agency") instead of leaking a real tenant.
            return ($activeId && DB::table("agencies")->where("id", $activeId)->whereNull("deleted_at")->exists())
                ? $activeId : null;
        }

        if ($activeId && DB::table("role_assignments")
                ->where("user_id", $user->id)
                ->where("role", "agency_admin")
                ->where("agency_id", $activeId)
                ->where("active", true)
                ->exists()) {
            return $activeId;
        }

        $own = DB::table("role_assignments")
            ->where("user_id", $user->id)
            ->where("role", "agency_admin")
            ->where("active", true)
            ->value("agency_id");
        if ($own) return (int) $own;

        /* A centre director (2026-11-13): the agency of a centre they direct - the active one when they direct a
           centre there. dashboard() then narrows the overview to their centres (CentreScope). */
        $dir = DB::table("role_assignments as ra")->join("centres as c", "c.id", "=", "ra.centre_id")
            ->where("ra.user_id", $user->id)->where("ra.role", "centre_director")->where("ra.active", true)
            ->whereNull("c.deleted_at")->pluck("c.agency_id")->map(fn ($v) => (int) $v)->unique()->values();
        if ($activeId && $dir->contains($activeId)) return $activeId;

        return $dir->first() ?: null;
    }

    /**
     * GET /api/v1/admin/activity-feed — a top-bar notification feed of notable
     * agency events (new families, inspection + home-visit reports, incidents,
     * new staff). Isolated per agency; a platform_admin sees EVERY agency.
     */
    public function activityFeed(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $isPlatformAdmin = DB::table("role_assignments")->where("user_id", $uid)->where("role", "platform_admin")->where("active", true)->exists();

        // A platform_admin scopes to whichever agency the switcher has selected.
        // Only the explicit "all" selection (X-Active-Agency-Id: all) — or not having
        // picked an agency yet — shows every agency's events. A super-admin viewing a
        // single agency (e.g. iLearn) must NOT see other agencies' notifications.
        // $unscoped, not $isPlatformAdmin, is what turns the per-agency filters off.
        $hdr        = strtolower(trim((string) $request->header("X-Active-Agency-Id")));
        $selectedId = $isPlatformAdmin && $hdr !== "all" && $hdr !== "" ? $this->activeAgencyId($request) : null;
        $unscoped   = $isPlatformAdmin && ($hdr === "all" || $hdr === "" || ! $selectedId);

        $agencyIds = [];
        $centreIds = [];
        if ($isPlatformAdmin && ! $unscoped) {
            // Super-admin has an agency selected → behave exactly like that agency's admin.
            $agencyIds = [(int) $selectedId];
            $centreIds = DB::table("centres")->whereIn("agency_id", $agencyIds)->pluck("id")->all();
        } elseif (! $isPlatformAdmin) {
            $agencyIds = DB::table("role_assignments")->where("user_id", $uid)->where("active", true)
                ->whereIn("role", ["agency_admin", "centre_director"])->whereNotNull("agency_id")
                ->pluck("agency_id")->unique()->values()->all();
            if (empty($agencyIds)) {
                $agencyIds = DB::table("role_assignments as ra")->join("centres as c", "c.id", "=", "ra.centre_id")
                    ->where("ra.user_id", $uid)->where("ra.active", true)->whereNotNull("ra.centre_id")
                    ->pluck("c.agency_id")->unique()->values()->all();
            }
            if (empty($agencyIds)) return response()->json(["events" => [], "scope" => "agency"]);
            $centreIds = DB::table("centres")->whereIn("agency_id", $agencyIds)->pluck("id")->all();
        }

        /* A DIRECTOR SEES THEIR OWN CENTRES (2026-11-26, memory kiddietrac-centre-director-scope). Every source below was
           scoped to the director's whole AGENCY - check-ins, clock-ins, families, sign-ins, inspections, home visits, new
           staff and time off from centres they do not run. $centreIds is now the centres they may see (all of an agency
           they administer), $wideAgencies the agencies they see whole; records keyed only by agency are kept to those, and
           a director's share goes by centre. Same answer as each record screen (Visibility::centreIds via
           StaffSubmissionsFeed::centreScope). Agency admins and platform admins: unchanged. */
        $wideAgencies = array_map("intval", $agencyIds);
        if (! $unscoped) {
            $wideAgencies = [];
            $scopedCentres = [];
            foreach ($agencyIds as $aid) {
                $s = \App\Support\StaffSubmissionsFeed::centreScope($request, (int) $aid);
                if ($s === null) {
                    $wideAgencies[] = (int) $aid;
                    $scopedCentres = array_merge($scopedCentres, DB::table("centres")->where("agency_id", (int) $aid)->pluck("id")->map(fn ($v) => (int) $v)->all());
                } else {
                    $scopedCentres = array_merge($scopedCentres, $s);
                }
            }
            $centreIds = array_values(array_unique($scopedCentres));
        }
        // Rows that carry an agency and maybe a centre: an agency seen whole, or a centre in scope.
        $agencyOrCentre = function ($q, string $agencyCol, string $centreCol) use ($wideAgencies, $centreIds) {
            return $q->where(fn ($w) => $w->whereIn($agencyCol, $wideAgencies ?: [0])->orWhereIn($centreCol, $centreIds ?: [0]));
        };

        $events = collect();
        $push = fn ($type, $icon, $text, $when, $link = null, $avatar = null, $device = null) => $events->push([
            "type" => $type, "icon" => $icon, "text" => $text, "when" => (string) $when, "link" => $link, "avatar" => $avatar,
            // Only sign-in/out events set this. Kept as its own field, not just glued into
            // the sentence, so the UI can render it as a muted second line.
            "device" => $device,
        ]);

        // New families (scoped via centre → agency).
        $famQ = DB::table("families as f")->join("centres as c", "c.id", "=", "f.centre_id")
            ->whereNull("f.deleted_at")->orderByDesc("f.created_at")->limit(15)
            ->select("f.family_name", "f.created_at");
        if (! $unscoped) $famQ->whereIn("f.centre_id", $centreIds ?: [0]);
        foreach ($famQ->get() as $r) $push("family", "\u{1F46A}", "New family created: " . ($r->family_name ?: "Unnamed family"), $r->created_at, "#admin-children");

        // Recent sign-ins (audit_logs 'login') for THIS agency's people — deduped
        // to each person's most recent login so the feed isn't flooded.
        $agencyUserIds = null;
        if (! $unscoped) {
            $staffIds = DB::table("role_assignments")->where("active", true)
                ->where(function ($q) use ($wideAgencies, $centreIds) { $q->whereIn("agency_id", $wideAgencies ?: [0])->orWhereIn("centre_id", $centreIds ?: [0]); })
                ->pluck("user_id");
            $guardIds = DB::table("guardians as g")->join("families as f", "f.id", "=", "g.family_id")
                ->whereIn("f.centre_id", $centreIds ?: [0])->pluck("g.user_id");
            $agencyUserIds = $staffIds->merge($guardIds)->unique()->filter()->values()->all();
        }
        // EVERY sign-in and sign-out as its own entry, newest first. The feed used to
        // collapse to each person's most recent login "so it isn't flooded", which is
        // precisely what made the history look missing: five sign-ins read as one line.
        // An activity feed should show the activity, so events are listed individually
        // and simply capped.
        $liQ = DB::table("audit_logs as al")->join("users as u", "u.id", "=", "al.user_id")
            ->whereIn("al.action", ["login", "logout"])->orderByDesc("al.created_at")->orderByDesc("al.id")->limit(40)
            // user_agent has been recorded on every sign-in all along and never read.
            ->select("al.user_id", "al.action", "u.first_name", "u.last_name", "u.photo_url", "al.created_at", "al.user_agent");
        if (! $unscoped) $liQ->whereIn("al.user_id", $agencyUserIds ?: [0]);
        foreach ($liQ->get() as $r) {
            $nm = trim(($r->first_name ?? "") . " " . ($r->last_name ?? "")) ?: "A user";
            $out = $r->action === "logout";
            /* Phone / tablet / desktop, and whether it was the app. Blank for anything the
               classifier cannot place, because a wrong guess here is worse than silence. */
            $dev = \App\Support\DeviceType::shortLabel($r->user_agent ?? null);
            $push($out ? "logout" : "login", $out ? "\u{1F6AA}" : "\u{1F511}",
                $nm . ($out ? " signed out" : " signed in") . ($dev !== "" ? " \u{00B7} " . $dev : ""),
                $r->created_at, "#audit-logs", $r->photo_url, $dev !== "" ? $dev : null);
        }

        // Inspection reports (hcc_inspection_forms — agency_id direct).
        $insQ = DB::table("hcc_inspection_forms")->whereNull("deleted_at")->orderByDesc("created_at")->limit(15)
            ->select("form_type", "provider_name", "status", "created_at");
        if (! $unscoped) $agencyOrCentre($insQ->whereIn("agency_id", $agencyIds ?: [0]), "agency_id", "centre_id");
        foreach ($insQ->get() as $r) {
            $ft = ucwords(str_replace("_", " ", (string) $r->form_type));
            $st = $r->status === "submitted" ? "submitted" : ($r->status ?: "updated");
            $push("inspection", "\u{1F4CB}", "Inspection report (" . ($ft ?: "form") . ")" . ($r->provider_name ? " — " . $r->provider_name : "") . " " . $st, $r->created_at, "#hcc-forms");
        }

        // Home-visit reports.
        $hvQ = DB::table("home_visit_reports")->whereNull("deleted_at")->orderByDesc("created_at")->limit(12)
            ->select("family_name", "child_name", "visit_type", "created_at");
        if (! $unscoped) $agencyOrCentre($hvQ->whereIn("agency_id", $agencyIds ?: [0]), "agency_id", "centre_id");
        foreach ($hvQ->get() as $r) $push("home_visit", "\u{1F3E1}", "Home-visit report — " . ($r->family_name ?: $r->child_name ?: "family"), $r->created_at, "#home-visits");

        /* Incidents: who logged it, for which child, at which centre, and the link opens that incident (2026-11-26, staff
           submissions sweep). A director sees their own centres only (CentreScope), open centres and children still
           with us (Live). StaffSubmissionsFeed::incidents is the reader. */
        try {
            foreach (\App\Support\StaffSubmissionsFeed::incidents($request, $uid, $unscoped, $agencyIds) as $ev) $events->push($ev);
        } catch (\Throwable $e) {
            report($e);
        }

        /* WHAT THE TEAM SUBMITTED (2026-11-26, Anthony: "notifications bell should also show if educators submitted forms,
           fire drills etc etc.."). Drills, hazards, medication doses, field trips, report cards sent for approval, shared
           notes, forms signed, policies acknowledged, certificates uploaded and HR tasks/courses/self reviews, each as one
           item in this list, by time. Feed only (no Notify row, no phone push). Scope, Live and the "Staff submissions"
           switch are in StaffSubmissionsFeed::events. */
        try {
            foreach (\App\Support\StaffSubmissionsFeed::events($request, $uid, $unscoped, $agencyIds) as $ev) $events->push($ev);
        } catch (\Throwable $e) {
            report($e);
        }

        // New staff / role assignments.
        $stQ = DB::table("role_assignments as ra")->join("users as u", "u.id", "=", "ra.user_id")
            ->where("ra.active", true)->whereNotNull("ra.created_at")
            ->whereIn("ra.role", ["educator", "centre_director", "agency_admin", "home_visitor", "auditor"])
            ->orderByDesc("ra.created_at")->limit(12)
            ->select("u.first_name", "u.last_name", "u.photo_url", "ra.role", "ra.created_at");
        if (! $unscoped) $agencyOrCentre($stQ->whereIn("ra.agency_id", $agencyIds ?: [0]), "ra.agency_id", "ra.centre_id");
        foreach ($stQ->get() as $r) {
            $nm = trim(($r->first_name ?? "") . " " . ($r->last_name ?? "")) ?: "A team member";
            $push("staff", "\u{1F464}", "New " . str_replace("_", " ", (string) $r->role) . ": " . $nm, $r->created_at, "#admin", $r->photo_url);
        }

        // Staff clock in/out (time_punches → user + centre). Each punch yields a
        // "clocked in" event at punched_in_at and, once closed, a "clocked out"
        // event at punched_out_at — so admins/directors (and a platform_admin, who
        // sees all) get educator clock activity right here in the bell.
        $tpQ = DB::table("time_punches as tp")
            ->join("users as u", "u.id", "=", "tp.user_id")
            ->leftJoin("centres as ce", "ce.id", "=", "tp.centre_id")
            ->orderByDesc("tp.punched_in_at")->limit(25)
            ->select("u.first_name", "u.last_name", "u.photo_url", "ce.name as centre_name", "tp.punched_in_at", "tp.punched_out_at");
        if (! $unscoped) $tpQ->whereIn("tp.centre_id", $centreIds ?: [0]);
        foreach ($tpQ->get() as $r) {
            $nm  = trim(($r->first_name ?? "") . " " . ($r->last_name ?? "")) ?: "A team member";
            $ctr = $r->centre_name ? (" — " . $r->centre_name) : "";
            if ($r->punched_in_at)  $push("clock", "\u{1F7E2}", $nm . " clocked in" . $ctr, $r->punched_in_at, "#dashboard", $r->photo_url);
            if ($r->punched_out_at) $push("clock", "\u{1F534}", $nm . " clocked out" . $ctr, $r->punched_out_at, "#dashboard", $r->photo_url);
        }

        // Child check-in / check-out -- attendance in the bell for the agency admins + directors, room -> centre scoped.
        $ceQ = DB::table("check_events as ce")
            ->join("children as ch", "ch.id", "=", "ce.child_id")
            ->join("rooms as rm", "rm.id", "=", "ce.room_id")
            ->leftJoin("users as bu", "bu.id", "=", "ce.by_user_id")
            ->leftJoin("guardians as g", function ($j) {
                $j->on("g.family_id", "=", "ch.family_id")->on("g.user_id", "=", "ce.by_user_id");
            })
            ->orderByDesc("ce.occurred_at")->limit(20)
            ->select("ch.first_name", "ch.preferred_name", "ch.photo_url", "ce.event_type", "ce.occurred_at", "rm.centre_id",
                     "bu.first_name as by_first", "bu.last_name as by_last", DB::raw("g.id as guardian_match"));
        if (! $unscoped) $ceQ->whereIn("rm.centre_id", $centreIds ?: [0]);
        foreach ($ceQ->get() as $r) {
            $nm = $r->preferred_name ?: $r->first_name;
            // Flag the manual workaround: a staff member (not the child's guardian)
            // signed the child in/out. Guardian self-scans stay unannotated.
            $byName = trim(($r->by_first ?? "") . " " . ($r->by_last ?? ""));
            $by = ($byName && ! $r->guardian_match) ? (" \u{00B7} by " . $byName . " (staff)") : "";
            if ($r->event_type === "check_in")  $push("checkin", "\u{2705}", $nm . " checked in" . $by, $r->occurred_at, "#dashboard", $r->photo_url);
            if ($r->event_type === "check_out") $push("checkin", "\u{1F44B}", $nm . " checked out" . $by, $r->occurred_at, "#dashboard", $r->photo_url);
        }

        /* TIME OFF IN THE BELL (2026-11-04, Anthony: "time off requests should show up in the notifications bell").
           The approvers got a Notify row, but an admin's bell opens THIS feed, and opening it marks their own alerts
           seen - so a request was cleared without ever being shown. Scoped by the request's own agency_id. */
        $toQ = DB::table("time_off_requests as tor")->join("users as u", "u.id", "=", "tor.user_id")
            ->orderByDesc("tor.updated_at")->limit(15)
            ->select("u.first_name", "u.last_name", "u.photo_url", "tor.request_type", "tor.start_at", "tor.end_at", "tor.status", "tor.created_at", "tor.decided_at");
        if (! $unscoped) {
            $toQ->whereIn("tor.agency_id", $agencyIds ?: [0])->where(function ($w) use ($wideAgencies, $centreIds) {
                $w->whereIn("tor.agency_id", $wideAgencies ?: [0])
                  ->orWhereIn("tor.centre_id", $centreIds ?: [0])
                  ->orWhere(fn ($n) => $n->whereNull("tor.centre_id")->whereExists(fn ($e) => $e->from("role_assignments as sra")
                      ->whereColumn("sra.user_id", "tor.user_id")->where("sra.active", 1)
                      ->whereIn("sra.role", \App\Support\Visibility::STAFF_ROLES)->whereIn("sra.centre_id", $centreIds ?: [0])->select(DB::raw(1))))
                  ->orWhere(fn ($n) => $n->whereNull("tor.centre_id")->whereExists(fn ($e) => $e->from("educator_rooms as er")->join("rooms as rm", "rm.id", "=", "er.room_id")
                      ->whereColumn("er.user_id", "tor.user_id")->whereIn("rm.centre_id", $centreIds ?: [0])->select(DB::raw(1))));
            });
        }
        foreach ($toQ->get() as $r) {
            $nm = trim(($r->first_name ?? "") . " " . ($r->last_name ?? "")) ?: "A team member";
            $kind = ucfirst(str_replace("_", " ", (string) ($r->request_type ?: "time off")));
            $from = $r->start_at ? date("M j", strtotime((string) $r->start_at)) : "";
            $to = $r->end_at && substr((string) $r->end_at, 0, 10) !== substr((string) $r->start_at, 0, 10) ? date("M j", strtotime((string) $r->end_at)) : "";
            $when = $from . ($to !== "" ? " \u{2013} " . $to : "");
            $push("time_off", "\u{1F334}", $nm . " requested time off (" . $kind . ($when !== "" ? " \u{00B7} " . $when : "") . ")"
                . ($r->status === "pending" ? " \u{00B7} waiting for a decision" : ($r->status === "cancelled" ? " \u{00B7} cancelled" : "")), $r->created_at, "#time-off", $r->photo_url);
            if ($r->decided_at && in_array($r->status, ["approved", "denied", "rejected"], true)) {
                $push("time_off", $r->status === "approved" ? "\u{2705}" : "\u{274C}", "Time off for " . $nm . ($when !== "" ? " (" . $when . ")" : "") . " " . ($r->status === "approved" ? "approved" : "declined"), $r->decided_at, "#time-off", $r->photo_url);
            }
        }

        /* Payments in (2026-11-05, App\Support\PaymentAlerts): the bell is how an admin hears about money. An agency
           admin sees every centre's; a director only their own centre's - money is not shared across centres. */
        $payCentres = $centreIds;
        if (! $isPlatformAdmin) {
            $adminAgencies = DB::table("role_assignments")->where("user_id", $uid)->where("active", true)
                ->whereIn("role", ["agency_admin", "admin"])->whereIn("agency_id", $agencyIds ?: [0])->pluck("agency_id")->all();
            $dirRows = DB::table("role_assignments")->where("user_id", $uid)->where("active", true)
                ->whereIn("role", ["centre_director", "director", "assistant_director"])->whereIn("agency_id", $agencyIds ?: [0])->get(["agency_id", "centre_id"]);
            $wholeAgencies = array_merge($adminAgencies, $dirRows->whereNull("centre_id")->pluck("agency_id")->all());
            $payCentres = array_values(array_unique(array_merge(
                DB::table("centres")->whereIn("agency_id", $wholeAgencies ?: [0])->pluck("id")->all(),
                array_intersect($dirRows->whereNotNull("centre_id")->pluck("centre_id")->map(fn ($v) => (int) $v)->all(), array_map("intval", $centreIds)))));
        }
        $payQ = DB::table("payments as p")->join("invoices as i", "i.id", "=", "p.invoice_id")->leftJoin("families as f", "f.id", "=", "p.family_id")
            ->where("p.status", "succeeded")->where("p.amount", ">", 0)
            ->orderByDesc(DB::raw("COALESCE(p.paid_at, p.created_at)"))->limit(15)
            ->select("p.amount", "p.notes", "p.method", "p.stripe_payment_id", "f.family_name", "i.invoice_number", DB::raw("COALESCE(p.paid_at, p.created_at) as at"));
        if (! $unscoped) $payQ->whereIn("i.centre_id", $payCentres ?: [0]);
        foreach ($payQ->get() as $r) {
            $how = stripos((string) $r->notes, "Apple Pay") !== false ? "Apple Pay" : (stripos((string) $r->notes, "Google Pay") !== false ? "Google Pay"
                : ($r->stripe_payment_id ? "card" : str_replace("_", " ", (string) ($r->method ?: "payment"))));
            $fam = trim((string) $r->family_name);
            $push("payment", "\u{1F4B3}", "\$" . number_format((float) $r->amount, 2) . " received from "
                . ($fam === "" ? "a family" : "the " . $fam . (stripos($fam, "family") === false ? " family" : ""))
                . " \u{00B7} invoice " . $r->invoice_number . " \u{00B7} " . $how, $r->at, "#admin-billing");
        }

        /* YOUR SAFETY ALERTS IN TIME ORDER, NOT PINNED ON TOP (2026-11-26, Anthony: "the notification bell at the top bar
           keeps showing the safe alerts as priority it should be treated the same as once new notifications are issue it
           should show based on the time"). The bell used to fetch /notifications/alerts separately and draw those rows in
           their own "Alerts for you" block above the feed, so a Safe Arrival alert from this morning sat above everything
           that happened since. With ?alerts=1 (the bell asks for it; older cached scripts do not, so they never get the rows
           twice) the person's own alerts go into this same list and the one sort below places them by when they happened.
           Scope is BellAlertsController::listFor's, unchanged: the caller's own rows, the agency on screen, fail closed.
           They carry alert=true, id and read, so the bell counts them once (they are Notify rows, already in /badges) and
           marks them read on click. */
        if ($request->boolean("alerts")) {
            try {
                foreach (\App\Http\Controllers\Api\BellAlertsController::listFor($request) as $a) {
                    if (empty($a["when"])) continue;
                    $events->push([
                        "type" => "alert", "alert" => true, "id" => $a["id"], "kind" => $a["type"], "icon" => null,
                        "text" => $a["title"], "body" => $a["body"], "when" => (string) $a["when"],
                        "link" => $a["link"] !== "" ? $a["link"] : "#notifications",
                        "read" => (bool) $a["read"], "avatar" => null, "device" => null,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);   // the agency feed still answers without them
            }
        }

        $feed = $events->filter(fn ($e) => ! empty($e["when"]))->sortByDesc("when")->take(40)->values()->all();
        return response()->json(["events" => $feed, "scope" => $unscoped ? "all" : "agency"]);
    }

    /**
     * GET /api/v1/admin/analytics/demographics — self-reported race/ethnicity +
     * auto-detected device-type breakdown for THIS agency's people (staff +
     * guardians). Both are captured at onboarding into users.profile_extras
     * (see AuthController::updateOnboarding). Agency-scoped: a platform_admin
     * must have an agency selected (X-Active-Agency-Id) — no cross-agency roll-up
     * here, so demographics never leak between tenants.
     */
    public function demographics(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $isPlatformAdmin = DB::table("role_assignments")->where("user_id", $uid)->where("role", "platform_admin")->where("active", true)->exists();

        // Resolve the agency to report on.
        if ($isPlatformAdmin) {
            $agencyId = $this->activeAgencyId($request);
            if (! $agencyId) return response()->json(["message" => "Select an agency first.", "total" => 0, "reported" => 0, "ethnicity" => [], "devices" => []], 200);
        } else {
            $agencyId = DB::table("role_assignments")->where("user_id", $uid)->where("active", true)
                ->whereIn("role", ["agency_admin", "centre_director"])->whereNotNull("agency_id")->value("agency_id");
            if (! $agencyId) {
                $agencyId = DB::table("role_assignments as ra")->join("centres as c", "c.id", "=", "ra.centre_id")
                    ->where("ra.user_id", $uid)->where("ra.active", true)->value("c.agency_id");
            }
            if (! $agencyId) return response()->json(["message" => "No agency.", "total" => 0, "reported" => 0, "ethnicity" => [], "devices" => []], 200);
        }
        $agencyId  = (int) $agencyId;
        $centreIds = DB::table("centres")->where("agency_id", $agencyId)->pluck("id")->all();
        /* A centre director sees the people of their own centres only (2026-11-13: the director now has the admin
           overview, which shows this card). CentreScope: null = agency-wide, as before; fails closed. */
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if ($scope !== null) {
            $centreIds = array_values(array_intersect(array_map("intval", $centreIds), $scope));
        }

        // Everyone attached to the agency: staff (role_assignments agency_id OR a
        // centre in the agency) + guardians (families in the agency's centres).
        $staffIds = DB::table("role_assignments")->where("active", true)
            ->where(function ($q) use ($agencyId, $centreIds, $scope) {
                if ($scope !== null) { $q->whereIn("centre_id", $centreIds ?: [0]); return; }
                $q->where("agency_id", $agencyId)->orWhereIn("centre_id", $centreIds ?: [0]);
            })
            ->pluck("user_id");
        $guardIds = DB::table("guardians as g")->join("families as f", "f.id", "=", "g.family_id")
            ->whereIn("f.centre_id", $centreIds ?: [0])->whereNotNull("g.user_id")->pluck("g.user_id");
        $userIds = $staffIds->merge($guardIds)->unique()->filter()->values()->all();

        $total = count($userIds);
        $ethCounts = [];
        $devCounts = [];
        $reported  = 0;
        if ($total) {
            $rows = DB::table("users")->whereIn("id", $userIds)->whereNull("deleted_at")->pluck("profile_extras");
            $total = $rows->count();
            foreach ($rows as $pe) {
                $x = is_array($pe) ? $pe : (json_decode((string) $pe, true) ?: []);
                $eth = trim((string) ($x["ethnicity"] ?? ""));
                $dev = trim((string) ($x["device_type"] ?? ""));
                if ($eth !== "") { $ethCounts[$eth] = ($ethCounts[$eth] ?? 0) + 1; $reported++; }
                if ($dev !== "") { $devCounts[$dev] = ($devCounts[$dev] ?? 0) + 1; }
            }
        }
        $shape = function (array $c) {
            arsort($c);
            return array_map(fn ($k, $v) => ["label" => $k, "count" => $v], array_keys($c), array_values($c));
        };
        return response()->json([
            "total"     => $total,          // people attached to the agency
            "reported"  => $reported,       // how many gave an ethnicity
            "ethnicity" => $shape($ethCounts),
            "devices"   => $shape($devCounts),
            // The app actually on their phones (registered for push), not what they told us.
            "apps"      => \App\Support\AppInstalls::counts($userIds),
            "scope"     => "agency",
        ]);
    }

    /**
     * Onboarding, payroll paid out, and the next payday.
     *
     * Kept apart from the main dashboard query because each answers a different
     * question and none of them belongs in the enrolment counts.
     *
     * Everything is scoped through the agency's own centres. In "all agencies" mode
     * there is no single pay schedule to speak of, so the payday is simply omitted
     * rather than picking one arbitrarily.
     */
    private function overviewExtras(?int $agencyId, $centres, ?array $scope = null): array
    {
        $centreIds = collect($centres)->pluck("id")->filter()->all();
        $out = [
            "parents_onboarded" => 0,
            "parents_pending" => 0,
            "payroll_paid_ytd" => 0.0,
            "payroll_paid_count" => 0,
            "next_payday" => null,
        ];

        if ($centreIds) {
            // A guardian who never finished onboarding has no working login, so
            // everything the portal sends them lands nowhere. onboarded_at is the
            // honest marker — users.status can read "active" on an account that was
            // created for somebody who has never once signed in.
            $familyIds = DB::table("families")->whereIn("centre_id", $centreIds)
                ->whereNull("deleted_at")->pluck("id");
            $guardianUserIds = DB::table("guardians")->whereIn("family_id", $familyIds)
                ->pluck("user_id")->unique()->filter()->all();

            if ($guardianUserIds) {
                $base = DB::table("users")->whereIn("id", $guardianUserIds)->whereNull("deleted_at");
                $out["parents_onboarded"] = (clone $base)->whereNotNull("onboarded_at")->count();
                $out["parents_pending"] = (clone $base)->whereNull("onboarded_at")->count();
            }
        }

        if ($agencyId) {
            // What actually left the account this year. `paid` is the only status
            // that means money moved — `issued` is a payslip that exists.
            $paid = DB::table("payroll_documents")->where("agency_id", $agencyId)
                ->where("status", "paid")
                ->whereYear(DB::raw("COALESCE(paid_at, issued_at, created_at)"), now()->year);
            /* A DIRECTOR'S OWN CENTRES (2026-11-13). The scoped dashboard passed no agency here, so a director's
               "Payroll paid out" read $0.00 and "Next payroll" Not set - while their Payroll screen shows the same
               money. Same reach as PayrollDocumentController::index: documents filed to their centres, or belonging
               to staff who work there. The pay schedule is the agency's, and it is their staff's payday too. */
            if ($scope !== null) {
                $staff = DB::table("role_assignments")->whereIn("centre_id", $scope ?: [0])->where("active", 1)
                    ->pluck("user_id")->map(fn ($v) => (int) $v)->unique()->all();
                $paid->where(function ($w) use ($scope, $staff) {
                    $w->whereIn("centre_id", $scope ?: [0])->orWhereIn("user_id", $staff ?: [0]);
                });
            }
            $out["payroll_paid_ytd"] = (float) (clone $paid)->sum("net");
            $out["payroll_paid_count"] = (clone $paid)->count();

            try {
                $out["next_payday"] = \App\Http\Controllers\Api\PayrollSettingsController::nextPayday($agencyId);
            } catch (\Throwable $e) {
                $out["next_payday"] = null;   // unconfigured is not an error
            }
        }

        return $out;
    }
}
