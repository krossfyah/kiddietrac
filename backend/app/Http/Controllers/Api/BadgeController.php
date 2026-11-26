<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Badges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Every badge in one answer, "seen" on opening the inbox, and the iPhone icon sync (App\Support\Badges). */
final class BadgeController extends Controller
{
    /** GET /badges */
    public function index(Request $r): JsonResponse
    {
        return response()->json(Badges::forRequest($r))->header('Cache-Control', 'no-store');
    }

    /** POST /notifications/seen - the person opened their inbox: every counter for alerts resets; rows stay unread. */
    public function seen(Request $r): JsonResponse
    {
        $uid = (int) $r->user()->id;
        Badges::markSeen($uid);
        // Staff submissions in the Inbox / bell (StaffSubmissionsFeed): seen here is seen on every device.
        try { \App\Support\StaffSubmissionsFeed::markSeen($r); } catch (\Throwable $e) { report($e); }
        $b = Badges::forRequest($r);
        $b['ios'] = Badges::syncIos($uid, $b['total']);
        return response()->json($b)->header('Cache-Control', 'no-store');
    }

    /** POST /badges/sync - the counts went down here (a chat read, a row tapped): put the true number on the iPhones. */
    public function sync(Request $r): JsonResponse
    {
        $b = Badges::forRequest($r);
        $b['ios'] = Badges::syncIos((int) $r->user()->id, $b['total']);
        return response()->json($b)->header('Cache-Control', 'no-store');
    }
}
