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
            $centres = DB::table("centres")->whereNull("deleted_at")->get();
        } else {
            $agencyId = $this->activeAgencyId($request);
            if (!$agencyId) {
                return response()->json(["message" => "No agency access"], 403);
            }
            $agency = DB::table("agencies")->where("id", $agencyId)->first();
            $centres = DB::table("centres")->where("agency_id", $agencyId)->whereNull("deleted_at")->get();
        }

        $centreStats = [];
        $totalEnrolled = 0;
        $totalPresentNow = 0;
        $totalReceivables = 0;
        $totalStaffOnFloor = 0;
        $today = Carbon::today()->toDateString();

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
            ->whereDate("ci.occurred_at", $today)
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
            $inAt = [];
            $outAt = [];
            foreach ($todaysEvents as $e) {
                if ($e->event_type === "check_in") {
                    if (! isset($inAt[$e->child_id]) || $e->occurred_at < $inAt[$e->child_id]) $inAt[$e->child_id] = $e->occurred_at;
                } else {
                    if (! isset($outAt[$e->child_id]) || $e->occurred_at > $outAt[$e->child_id]) $outAt[$e->child_id] = $e->occurred_at;
                }
            }
            $roster = [];
            $checkedInCount = 0;
            foreach ($enrolledKids as $k) {
                $ci = $inAt[$k->id] ?? null;
                $co = $outAt[$k->id] ?? null;
                $status = ! $ci ? "absent" : ($co ? "out" : "in");
                if ($status !== "absent") $checkedInCount++;
                $roster[] = [
                    "id"           => $k->id,
                    "name"         => trim($k->first_name . " " . ($k->last_name ?? "")),
                    "photo_url"    => $k->photo_url,
                    "status"       => $status,
                    "check_in_at"  => $ci ? Carbon::parse($ci)->timezone($ctz)->format("g:i A") : null,
                    "check_out_at" => $co ? Carbon::parse($co)->timezone($ctz)->format("g:i A") : null,
                ];
            }
            // Present-first, then absent; each group alphabetical (already ordered).
            usort($roster, function ($a, $b) {
                $rank = ["in" => 0, "out" => 1, "absent" => 2];
                return [$rank[$a["status"]], $a["name"]] <=> [$rank[$b["status"]], $b["name"]];
            });

            // Names behind the Present + Staff counters (for hover on the card).
            $presentChildren = [];
            foreach ($roster as $rr) {
                if ($rr["status"] === "in") $presentChildren[] = $rr["name"];
            }
            $staffPresent = array_values(array_unique(array_values($staffByCentre[$c->id] ?? [])));
            sort($staffPresent);

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
                "roster" => $roster,
                "checked_in_count" => $checkedInCount,
                "not_checked_in_count" => count($roster) - $checkedInCount,
                "staff_on_floor" => $staffOnFloor,
                "receivables" => (float) $receivables,
                "room_count" => $roomCount,
                "rooms_in_breach" => $breach ? 1 : 0,
                "license_capacity" => $c->license_capacity,
                "capacity_pct" => $c->license_capacity ? round(($enrolled / max(1, $c->license_capacity)) * 100) : 0,
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
        $userIdsInAgency = DB::table("role_assignments")
            ->where(function ($q) use ($agencyId) {
                $q->where("agency_id", $agencyId)
                  ->orWhereIn("centre_id", function ($qq) use ($agencyId) {
                      $qq->select("id")->from("centres")->where("agency_id", $agencyId);
                  });
            })->pluck("user_id")->unique()->all();
        $centreIds = $centres->pluck("id")->all();

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
            ->whereIn("a.action", ["login", "logout"])->orderByDesc("a.created_at")->limit(25)
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
        if (! $allMode) { $staffQ->where(function ($q) use ($agencyId, $__cids) { $q->where("agency_id", $agencyId)->orWhereIn("centre_id", $__cids ?: [0]); }); }
        $totalStaff = $staffQ->distinct()->count("user_id");
        $overdueInvoices = DB::table("invoices")->whereIn("centre_id", $__cids ?: [0])->where("status", "overdue")->count();
        if (\Illuminate\Support\Facades\Schema::hasTable('external_invoices')) {
            $overdueInvoices += (int) DB::table('external_invoices as ei')->join('families as f', 'f.id', '=', 'ei.family_id')
                ->whereIn('f.centre_id', $__cids ?: [0])->where('ei.status', 'overdue')->count();
        }

        // Agency owner = the earliest agency_admin, reachable directly (agency_id)
        // or through one of the agency's centres. Shown in the Edit-agency window.
        $owner = null;
        if (! $allMode) {
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
            "totals" => [
                "enrolled" => $totalEnrolled,
                "present_now" => $totalPresentNow,
                "staff_on_floor" => $totalStaffOnFloor,
                "receivables" => (float) $totalReceivables,
                "families" => $totalFamilies,
                "staff_total" => $totalStaff,
                "overdue_invoices" => $overdueInvoices,
            ],
            "centres" => $centreStats,
            "archived_centres" => DB::table("centres")
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

        return $own ? (int) $own : null;
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

        $events = collect();
        $push = fn ($type, $icon, $text, $when, $link = null, $avatar = null) => $events->push([
            "type" => $type, "icon" => $icon, "text" => $text, "when" => (string) $when, "link" => $link, "avatar" => $avatar,
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
                ->where(function ($q) use ($agencyIds, $centreIds) { $q->whereIn("agency_id", $agencyIds ?: [0])->orWhereIn("centre_id", $centreIds ?: [0]); })
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
            ->whereIn("al.action", ["login", "logout"])->orderByDesc("al.created_at")->limit(40)
            ->select("al.user_id", "al.action", "u.first_name", "u.last_name", "u.photo_url", "al.created_at");
        if (! $unscoped) $liQ->whereIn("al.user_id", $agencyUserIds ?: [0]);
        foreach ($liQ->get() as $r) {
            $nm = trim(($r->first_name ?? "") . " " . ($r->last_name ?? "")) ?: "A user";
            $out = $r->action === "logout";
            $push($out ? "logout" : "login", $out ? "\u{1F6AA}" : "\u{1F511}",
                $nm . ($out ? " signed out" : " signed in"), $r->created_at, "#audit-logs", $r->photo_url);
        }

        // Inspection reports (hcc_inspection_forms — agency_id direct).
        $insQ = DB::table("hcc_inspection_forms")->whereNull("deleted_at")->orderByDesc("created_at")->limit(15)
            ->select("form_type", "provider_name", "status", "created_at");
        if (! $unscoped) $insQ->whereIn("agency_id", $agencyIds ?: [0]);
        foreach ($insQ->get() as $r) {
            $ft = ucwords(str_replace("_", " ", (string) $r->form_type));
            $st = $r->status === "submitted" ? "submitted" : ($r->status ?: "updated");
            $push("inspection", "\u{1F4CB}", "Inspection report (" . ($ft ?: "form") . ")" . ($r->provider_name ? " — " . $r->provider_name : "") . " " . $st, $r->created_at, "#hcc-forms");
        }

        // Home-visit reports.
        $hvQ = DB::table("home_visit_reports")->whereNull("deleted_at")->orderByDesc("created_at")->limit(12)
            ->select("family_name", "child_name", "visit_type", "created_at");
        if (! $unscoped) $hvQ->whereIn("agency_id", $agencyIds ?: [0]);
        foreach ($hvQ->get() as $r) $push("home_visit", "\u{1F3E1}", "Home-visit report — " . ($r->family_name ?: $r->child_name ?: "family"), $r->created_at, "#home-visits");

        // Incidents (scoped via room → centre).
        $incQ = DB::table("incidents as inc")->leftJoin("rooms as rm", "rm.id", "=", "inc.room_id")->leftJoin("centres as c", "c.id", "=", "rm.centre_id")
            ->orderByDesc("inc.created_at")->limit(12)
            ->select("inc.incident_type", "inc.created_at");
        if (! $unscoped) $incQ->whereIn("c.agency_id", $agencyIds ?: [0]);
        foreach ($incQ->get() as $r) $push("incident", "\u{1FA79}", "Incident logged (" . str_replace("_", " ", (string) $r->incident_type) . ")", $r->created_at, "#incidents");

        // New staff / role assignments.
        $stQ = DB::table("role_assignments as ra")->join("users as u", "u.id", "=", "ra.user_id")
            ->where("ra.active", true)->whereNotNull("ra.created_at")
            ->whereIn("ra.role", ["educator", "centre_director", "agency_admin", "home_visitor", "auditor"])
            ->orderByDesc("ra.created_at")->limit(12)
            ->select("u.first_name", "u.last_name", "u.photo_url", "ra.role", "ra.created_at");
        if (! $unscoped) $stQ->whereIn("ra.agency_id", $agencyIds ?: [0]);
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

        // Everyone attached to the agency: staff (role_assignments agency_id OR a
        // centre in the agency) + guardians (families in the agency's centres).
        $staffIds = DB::table("role_assignments")->where("active", true)
            ->where(function ($q) use ($agencyId, $centreIds) { $q->where("agency_id", $agencyId)->orWhereIn("centre_id", $centreIds ?: [0]); })
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
            "scope"     => "agency",
        ]);
    }
}
