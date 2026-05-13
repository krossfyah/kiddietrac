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
        $user = $request->user();

        $agencyId = DB::table("role_assignments")
            ->where("user_id", $user->id)
            ->where("role", "agency_admin")
            ->where("active", true)
            ->value("agency_id");

        if (!$agencyId) {
            return response()->json(["message" => "No agency access"], 403);
        }

        $agency = DB::table("agencies")->where("id", $agencyId)->first();
        $centres = DB::table("centres")->where("agency_id", $agencyId)->whereNull("deleted_at")->get();

        $centreStats = [];
        $totalEnrolled = 0;
        $totalPresentNow = 0;
        $totalReceivables = 0;
        $totalStaffOnFloor = 0;
        $today = Carbon::today()->toDateString();

        foreach ($centres as $c) {
            // Enrolled count
            $enrolled = DB::table("children")
                ->join("enrollments", "enrollments.child_id", "=", "children.id")
                ->join("rooms", "rooms.id", "=", "enrollments.room_id")
                ->where("rooms.centre_id", $c->id)
                ->where("children.enrollment_status", "enrolled")
                ->whereNull("enrollments.end_date")
                ->whereNull("children.deleted_at")
                ->distinct()
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

            // Staff on floor — simple query, time_entries has centre_id directly
            $staffOnFloor = DB::table("time_entries")
                ->where("centre_id", $c->id)
                ->whereNull("clocked_out_at")
                ->count();

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

            $centreStats[] = [
                "id" => $c->id,
                "name" => $c->name,
                "city" => $c->city,
                "enrolled" => $enrolled,
                "present_now" => $presentNow,
                "staff_on_floor" => $staffOnFloor,
                "receivables" => (float) $receivables,
                "room_count" => $roomCount,
                "rooms_in_breach" => $breach ? 1 : 0,
                "license_capacity" => $c->license_capacity,
                "capacity_pct" => $c->license_capacity ? round(($enrolled / max(1, $c->license_capacity)) * 100) : 0,
            ];

            $totalEnrolled += $enrolled;
            $totalPresentNow += $presentNow;
            $totalReceivables += $receivables;
            $totalStaffOnFloor += $staffOnFloor;
        }

        // Recent audit activity — filtered to actions from users in this agency
        $userIdsInAgency = DB::table("role_assignments")
            ->where(function ($q) use ($agencyId) {
                $q->where("agency_id", $agencyId)
                  ->orWhereIn("centre_id", function ($qq) use ($agencyId) {
                      $qq->select("id")->from("centres")->where("agency_id", $agencyId);
                  });
            })
            ->pluck("user_id")
            ->unique()
            ->all();

        $recentActivity = collect();
        if (!empty($userIdsInAgency)) {
            $recentActivity = DB::table("audit_logs")
                ->leftJoin("users", "users.id", "=", "audit_logs.user_id")
                ->whereIn("audit_logs.user_id", $userIdsInAgency)
                ->orderByDesc("audit_logs.created_at")
                ->limit(15)
                ->select("audit_logs.*", "users.first_name", "users.last_name")
                ->get();
        }

        return response()->json([
            "agency" => [
                "id" => $agency->id ?? null,
                "name" => $agency->name ?? "Agency",
                "centre_count" => $centres->count(),
            ],
            "totals" => [
                "enrolled" => $totalEnrolled,
                "present_now" => $totalPresentNow,
                "staff_on_floor" => $totalStaffOnFloor,
                "receivables" => (float) $totalReceivables,
            ],
            "centres" => $centreStats,
            "recent_activity" => $recentActivity->map(fn ($a) => [
                "id" => $a->id,
                "action" => $a->action,
                "actor" => trim(($a->first_name ?? "") . " " . ($a->last_name ?? "")) ?: "System",
                "centre_name" => null,
                "details" => $a->entity_type ? ($a->entity_type . (isset($a->entity_id) ? " #" . $a->entity_id : "")) : null,
                "created_at" => $a->created_at,
                "display_time" => Carbon::parse($a->created_at)->diffForHumans(),
            ])->all(),
        ]);
    }
}
