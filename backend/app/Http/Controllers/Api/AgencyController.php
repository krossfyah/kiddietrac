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

        foreach ($centres as $c) {
            // Enrolled count — count children directly by enrollment_status at the
            // centre (via their family). The old room-join missed children who are
            // enrolled but not yet assigned to a room (e.g. agency-imported kids
            // whose centres have no rooms), so agencies like iLearn showed 0.
            $enrolled = DB::table("children")
                ->join("families", "families.id", "=", "children.family_id")
                ->where("families.centre_id", $c->id)
                ->where("children.enrollment_status", "enrolled")
                ->whereNull("children.deleted_at")
                ->count("children.id");

            // Present right now
            $presentNow = DB::table("check_events as ci")
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from("check_events as co")
                        ->whereColumn("co.child_id", "ci.child_id")
                        ->where("co.event_type", "check_out")
                        ->where("co.occurred_at", ">", DB::raw("ci.occurred_at"))
                        ->whereDate("co.occurred_at", DB::raw("CURRENT_DATE"));
                })
                ->join("rooms", "rooms.id", "=", "ci.room_id")
                ->where("rooms.centre_id", $c->id)
                ->where("ci.event_type", "check_in")
                ->whereDate("ci.occurred_at", $today)
                ->distinct("ci.child_id")
                ->count("ci.child_id");

            // Staff on floor — DISTINCT users with an open shift (a staffer with
            // two stray open entries must count once, not twice).
            $staffOnFloor = DB::table("time_entries")
                ->where("centre_id", $c->id)
                ->whereNull("clocked_out_at")
                ->distinct("user_id")
                ->count("user_id");

            // Receivables
            $receivables = DB::table("invoices")
                ->where("centre_id", $c->id)
                ->whereIn("status", ["sent", "partial", "overdue"])
                ->sum("balance_due");

            // Room count (no deleted_at on rooms; use active flag)
            $roomCount = DB::table("rooms")
                ->where("centre_id", $c->id)
                ->where("active", true)
                ->count();

            // Simplified breach: total kids vs total staff on floor at the centre
            $breach = ($presentNow > 0 && $staffOnFloor === 0);

            // Today's attendance roster for the card: every enrolled child with
            // their avatar + status + check-in / check-out times.
            $enrolledKids = DB::table("children")
                ->join("families", "families.id", "=", "children.family_id")
                ->where("families.centre_id", $c->id)
                ->where("children.enrollment_status", "enrolled")
                ->whereNull("children.deleted_at")
                ->select("children.id", "children.first_name", "children.last_name", "children.photo_url")
                ->orderBy("children.first_name")
                ->get();
            $todaysEvents = DB::table("check_events as ci")
                ->join("rooms", "rooms.id", "=", "ci.room_id")
                ->where("rooms.centre_id", $c->id)
                ->whereIn("ci.event_type", ["check_in", "check_out"])
                ->whereDate("ci.occurred_at", $today)
                ->select("ci.child_id", "ci.event_type", "ci.occurred_at")
                ->get();
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
                    "check_in_at"  => $ci ? Carbon::parse($ci)->format("g:i A") : null,
                    "check_out_at" => $co ? Carbon::parse($co)->format("g:i A") : null,
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
            $staffPresent = array_values(array_unique(
                DB::table("time_entries as t")
                    ->join("users as u", "u.id", "=", "t.user_id")
                    ->where("t.centre_id", $c->id)->whereNull("t.clocked_out_at")
                    ->selectRaw("TRIM(CONCAT(u.first_name,' ',COALESCE(u.last_name,''))) as nm")
                    ->distinct()->orderBy("nm")->pluck("nm")->all()
            ));

            $centreStats[] = [
                "id" => $c->id,
                "name" => $c->name,
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

        $loginQ = DB::table("audit_logs as a")->leftJoin("users as u", "u.id", "=", "a.user_id")
            ->whereIn("a.action", ["login", "logout"])->orderByDesc("a.created_at")->limit(25)
            ->select("a.action", "a.created_at", "u.first_name", "u.last_name");
        if (! $allMode) $loginQ->whereIn("a.user_id", $userIdsInAgency ?: [0]);
        foreach ($loginQ->get() as $r) {
            $nm = trim(($r->first_name ?? "") . " " . ($r->last_name ?? "")) ?: "A user";
            $out = $r->action === "logout";
            $events->push(["icon" => $out ? "🚪" : "🔑", "text" => $nm . ($out ? " signed out" : " signed in"), "when" => $r->created_at]);
        }

        if (! empty($centreIds)) {
            foreach (DB::table("check_events as ce")->join("rooms as r", "r.id", "=", "ce.room_id")
                ->join("children as ch", "ch.id", "=", "ce.child_id")
                ->whereIn("r.centre_id", $centreIds)->orderByDesc("ce.occurred_at")->limit(40)
                ->select("ce.event_type", "ce.occurred_at",
                    DB::raw("TRIM(CONCAT(ch.first_name,' ',COALESCE(ch.last_name,''))) as nm"))->get() as $r) {
                $in = $r->event_type === "check_in";
                $events->push(["icon" => $in ? "🟢" : "⚪", "text" => $r->nm . ($in ? " checked in" : " checked out"), "when" => $r->occurred_at]);
            }
            foreach (DB::table("time_entries as t")->join("users as u", "u.id", "=", "t.user_id")
                ->whereIn("t.centre_id", $centreIds)->orderByDesc("t.clocked_in_at")->limit(25)
                ->select("t.clocked_in_at", "t.clocked_out_at",
                    DB::raw("TRIM(CONCAT(u.first_name,' ',COALESCE(u.last_name,''))) as nm"))->get() as $r) {
                $events->push(["icon" => "🟩", "text" => $r->nm . " clocked in", "when" => $r->clocked_in_at]);
                if ($r->clocked_out_at) $events->push(["icon" => "🟥", "text" => $r->nm . " clocked out", "when" => $r->clocked_out_at]);
            }
            foreach (DB::table("incidents as inc")->join("children as ch", "ch.id", "=", "inc.child_id")
                ->join("families as f", "f.id", "=", "ch.family_id")->whereIn("f.centre_id", $centreIds)
                ->orderByDesc("inc.occurred_at")->limit(15)
                ->select("inc.occurred_at", "inc.incident_type",
                    DB::raw("TRIM(CONCAT(ch.first_name,' ',COALESCE(ch.last_name,''))) as nm"))->get() as $r) {
                $events->push(["icon" => "🩹", "text" => "Incident (" . str_replace("_", " ", (string) $r->incident_type) . ") — " . $r->nm, "when" => $r->occurred_at]);
            }
        }

        $recentActivity = $events->filter(fn ($e) => ! empty($e["when"]))
            ->sortByDesc("when")->take(60)->values()
            ->map(fn ($e) => ["icon" => $e["icon"], "text" => $e["text"], "created_at" => $e["when"], "action" => $e["text"]]);

        return response()->json([
            "agency" => [
                "id" => $agency->id ?? null,
                "name" => $allMode ? "All agencies" : ($agency->name ?? "Agency"),
                "centre_count" => $centres->count(),
                "logo_url" => $agency ? ($agency->logo_url ?: ($agency->brand_logo_url ?? null)) : null,
                "brand_color" => $agency->brand_primary_color ?? null,
            ],
            "totals" => [
                "enrolled" => $totalEnrolled,
                "present_now" => $totalPresentNow,
                "staff_on_floor" => $totalStaffOnFloor,
                "receivables" => (float) $totalReceivables,
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
}
