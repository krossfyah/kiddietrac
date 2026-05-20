<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p59 — Family directory. Opt-in by guardian; visible to other
 * families at the same centre.
 */
final class DirectoryController extends Controller
{
    public function listDirectory(Request $request): JsonResponse
    {
        $u = $request->user();
        $myFamilyId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        if (!$myFamilyId) abort(403, 'Not a family member');
        $myFamily = DB::table('families')->where('id', $myFamilyId)->first();

        // Other opt-in families at same centre
        $rows = DB::table('directory_optins as d')
            ->join('families as f', 'f.id', '=', 'd.family_id')
            ->where('f.centre_id', $myFamily->centre_id)
            ->where('f.id', '!=', $myFamilyId)
            ->whereNull('f.deleted_at')
            ->select('f.id', 'f.family_name',
                'd.share_email', 'd.share_phone', 'd.share_address', 'd.share_children_names',
                DB::raw("CASE WHEN d.share_email THEN f.primary_email ELSE NULL END as primary_email"),
                DB::raw("CASE WHEN d.share_phone THEN f.primary_phone ELSE NULL END as primary_phone"),
                DB::raw("CASE WHEN d.share_address THEN f.address_line1 ELSE NULL END as address_line1"),
                DB::raw("CASE WHEN d.share_address THEN f.city ELSE NULL END as city"))
            ->orderBy('f.family_name')->get();
        // Attach child first names if shared
        $rows->transform(function ($r) {
            if ($r->share_children_names) {
                $r->child_names = DB::table('children')->where('family_id', $r->id)
                    ->whereNull('deleted_at')->pluck('first_name')->all();
            } else {
                $r->child_names = [];
            }
            return $r;
        });
        return response()->json(['data' => $rows]);
    }

    public function myOptin(Request $request): JsonResponse
    {
        $u = $request->user();
        $familyId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        if (!$familyId) abort(403);
        $row = DB::table('directory_optins')->where('family_id', $familyId)->first();
        return response()->json(['data' => $row]);
    }

    public function setOptin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'share_email' => 'required|boolean',
            'share_phone' => 'required|boolean',
            'share_address' => 'required|boolean',
            'share_children_names' => 'required|boolean',
        ]);
        $u = $request->user();
        $familyId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        if (!$familyId) abort(403);

        $anyShared = $data['share_email'] || $data['share_phone'] || $data['share_address'] || $data['share_children_names'];
        if (!$anyShared) {
            DB::table('directory_optins')->where('family_id', $familyId)->delete();
            return response()->json(['status' => 'opted_out']);
        }
        DB::table('directory_optins')->updateOrInsert(
            ['family_id' => $familyId],
            $data + ['opt_in_at' => now(), 'updated_at' => now()]
        );
        return response()->json(['status' => 'updated']);
    }
}
