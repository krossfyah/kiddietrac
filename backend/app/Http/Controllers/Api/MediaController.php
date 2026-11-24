<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MediaController extends Controller
{
    /* The ONE definition of who may see a child. This file used to carry a private
       copy that knew only "guardian" and "staff of the family's home centre", so it
       refused an educator a child who is in her room today under a shared-care
       schedule — the whole photo upload failed because one of five tagged children
       lived at another centre. */
    use ResolvesCentreContext;

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,heic,webp', 'max:8192'],
            'child_ids' => ['required'],
            'caption' => ['nullable', 'string', 'max:500'],
            'taken_at' => ['nullable', 'date'],
            'room_id' => ['nullable', 'integer'],
        ]);

        $childIds = $request->input('child_ids');
        if (is_string($childIds)) {
            $childIds = array_filter(array_map('intval', explode(',', $childIds)));
        }
        $childIds = array_unique(array_filter(array_map('intval', (array) $childIds)));
        if (empty($childIds)) {
            return response()->json(['message' => 'At least one child_id required'], 422);
        }
        // SECURITY (v22p94): the uploader must have access to EVERY tagged child.
        foreach ($childIds as $cid) {
            abort_unless($this->canAccessChildId($request->user(), (int) $cid), 403);
            // Staff only (2026-11-05 leak sweep): canAccessChildId() also lets the child's parent through.
            abort_unless($this->ktStaffForChild($request->user(), (int) $cid), 403);
        }
        // Photo permissions (2026-11-06, App\Support\MediaConsent): never a photo of a child whose family said no.
        if ($why = \App\Support\MediaConsent::untaggable(array_map('intval', $childIds))) {
            return response()->json(['message' => $why, 'reason' => 'no_photo_consent'], 422);
        }

        $roomId = $request->input('room_id');
        /* A picked room must be one of a tagged child's rooms, at a centre you work at (2026-11-05 leak
           sweep): room_id was written unchecked, so a photo could land on another centre's room feed. */
        if ($roomId) {
            $fits = false;
            foreach ($childIds as $cid) {
                if ($this->ktRoomFitsChild($request->user(), (int) $roomId, (int) $cid)) { $fits = true; break; }
            }
            if (! $fits) {
                return response()->json(['message' => 'That room is not one of these children\'s rooms at a centre you work at.'], 422);
            }
        }
        if (!$roomId) {
            // The room they are in today — a photo belongs to the day it was taken.
            $roomId = DB::table('enrollments')
                ->where('child_id', $childIds[0])
                ->whereNull('end_date')
                ->tap(fn ($q) => \App\Support\CareSchedule::constrain($q, 'enrollments'))
                ->value('room_id');
        }

        $file = $request->file('photo');
        $year = now()->format('Y');
        $month = now()->format('m');
        $uuid = Str::uuid()->toString();
        $extension = strtolower($file->getClientOriginalExtension());
        $relativePath = "media/{$year}/{$month}/{$uuid}.{$extension}";

        Storage::disk('public')->putFileAs("media/{$year}/{$month}", $file, "{$uuid}.{$extension}");

        $takenAt = $request->input('taken_at') ?: now();

        $mediaId = DB::table('media')->insertGetId([
            'room_id' => $roomId,
            'media_type' => 'photo',
            'storage_path' => $relativePath,
            'size_bytes' => $file->getSize(),
            'caption' => $request->input('caption'),
            'taken_at' => $takenAt,
            'taken_by_id' => $request->user()->id,
            'created_at' => now(),
        ]);

        foreach ($childIds as $childId) {
            DB::table('media_child_tags')->updateOrInsert(
                ['media_id' => $mediaId, 'child_id' => $childId],
                ['auto_tagged' => false]
            );
        }

        return response()->json([
            'message' => 'Photo uploaded',
            'media_id' => $mediaId,
            'tagged_children' => count($childIds),
            'url' => '/storage/' . $relativePath,
        ], 201);
    }

    public function forChild(Request $request, int $childId): JsonResponse
    {
        if (!$this->canAccessChildId($request->user(), $childId)) {
            abort(403);
        }
        $limit = min(50, (int) $request->input('limit', 30));

        // Read the table educators actually upload into.
        //
        // This used to query `media` + `media_child_tags` — which is EMPTY. Every
        // photo an educator shares goes to `photos` (PhotoFeedController::upload,
        // tagging children in a child_ids JSON column), so the parent gallery has
        // been showing parents nothing at all while their child's photos existed
        // the whole time. Videos (media_type = 'video') come through here too.
        $rows = DB::table('photos')
            ->whereJsonContains('child_ids', (int) $childId)
            ->whereNull('hidden_at')   // no photo consent: shown to nobody (App\Support\MediaConsent)
            ->orderByDesc('taken_at')
            ->limit($limit * 3)
            ->get(['id', 'url', 'thumbnail_url', 'media_type', 'caption', 'taken_at', 'child_ids', 'hidden_at']);
        /* PHOTO CONSENT (2026-11-06): a parent sees only the photos their family may see - none of a child at "do not
           photograph", and no group photo with another family's child who is not cleared for group photos. */
        $familyId = (int) DB::table('children')->where('id', $childId)->value('family_id');
        if (DB::table('guardians')->where('family_id', $familyId)->where('user_id', $request->user()->id)->exists()) {
            $rows = \App\Support\MediaConsent::filter($rows, \App\Support\MediaConsent::familyChildren([$familyId]));
        }
        $rows = $rows->take($limit)->values();
        // Stored in UTC; shown in the centre's own time (agency-timezone rule, 2026-10-30).
        $tz = \App\Support\AgencyTime::tzForCentre((int) DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('ch.id', $childId)->value('f.centre_id'));

        return response()->json([
            'photos' => $rows->map(fn ($m) => [
                'id' => $m->id,
                'url' => $m->url,
                'thumbnail_url' => $m->thumbnail_url,
                'media_type' => $m->media_type ?: 'image',
                'caption' => $m->caption,
                'taken_at' => $m->taken_at,
                'date_display' => Carbon::parse($m->taken_at, 'UTC')->setTimezone($tz)->format('M j, g:i A'),
            ])->all(),
        ]);
    }

    public function observationsForChild(Request $request, int $childId): JsonResponse
    {
        if (!$this->canAccessChildId($request->user(), $childId)) {
            abort(403);
        }
        $observations = DB::table('observations')
            ->where('child_id', $childId)
            // Honour the educator's choice. "Share with family right away" is a real
            // decision on the save screen; returning unshared observations here would
            // publish a note that was deliberately kept internal.
            ->where('shared_with_family', 1)
            ->orderByDesc('observed_at')
            ->limit(20)
            ->get();

        return response()->json([
            'observations' => $observations->map(fn ($o) => [
                'id' => $o->id,
                'domain' => $o->domain,
                'title' => $o->title,
                'body' => $o->body,
                'observed_at' => $o->observed_at,
                'date_display' => Carbon::parse($o->observed_at)->format('M j'),
            ])->all(),
        ]);
    }

    public function createObservation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'domain' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'media_id' => ['nullable', 'integer'],
            'observed_at' => ['nullable', 'date'],
        ]);
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403); // v22p94
        /* Staff only, and a linked photo must be one of this child's (2026-11-05 leak sweep): a parent
           with any staff role could record observations on their own child, and media_id was stored
           unchecked, attaching any photo id (another family's) to the observation the family is shown. */
        abort_unless($this->ktStaffForChild($request->user(), (int) $data['child_id']), 403);
        if (! empty($data['media_id']) && ! DB::table('media_child_tags')->where('media_id', (int) $data['media_id'])
                ->where('child_id', (int) $data['child_id'])->exists()) {
            return response()->json(['message' => 'That photo is not tagged with this child.'], 422);
        }

        $id = DB::table('observations')->insertGetId([
            'child_id' => $data['child_id'],
            'framework' => 'ELECT',
            'domain' => $data['domain'],
            'title' => $data['title'],
            'body' => $data['body'],
            'media_ids' => isset($data['media_id']) ? json_encode([$data['media_id']]) : null,
            'observed_at' => ! empty($data['observed_at']) ? \Illuminate\Support\Carbon::parse($data['observed_at']) : now(),   // was ?: on a missing key → 500 (2026-10-31)
            'recorded_by_id' => $request->user()->id,
            'shared_with_family' => true,
            'created_at' => now(),
        ]);

        try {
            \App\Support\ChildMomentNotify::after([[
                'child_id' => (int) $data['child_id'], 'kind' => 'observations', 'emoji' => '🌱',
                'label' => 'New learning observation', 'text' => (string) $data['title'], 'link' => '#today',
            ]]);
        } catch (\Throwable $e) {}

        return response()->json(['id' => $id, 'message' => 'Observation recorded'], 201);
    }

    // ---------------------------------------------------------------- Authenticated media proxy
    // Photos live on external CDNs / storage that do NOT send CORS headers, so the
    // browser can DISPLAY them in an <img> but the app's fetch() cannot read the bytes
    // to trigger a Save (this is why parent photo "download" did nothing while payslips
    // — which the API itself serves — worked). This streams a media URL back THROUGH the
    // API (same-origin to the app, with the standard CORS + Content-Disposition:
    // attachment), so the blob download works everywhere, exactly like the payslip PDF.
    public function download(Request $request)
    {
        $url  = (string) $request->query('url', '');
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $request->query('name', 'photo'));
        if ($name === '' || trim($name, '-') === '') {
            $name = 'photo';
        }

        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return response()->json(['message' => 'Invalid URL.'], 422);
        }
        // SSRF guard: resolve the host and refuse private / reserved ranges.
        $ip = @gethostbyname($parts['host']);
        if (! filter_var($ip, FILTER_VALIDATE_IP)
            || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return response()->json(['message' => 'Host not allowed.'], 422);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // this host has no IPv6 route
            CURLOPT_USERAGENT      => 'KiddieTrac/1.0 (+media-proxy)',
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 400) {
            return response()->json(['message' => 'Could not fetch the file.'], 502);
        }
        if ($ctype && stripos($ctype, 'image/') !== 0) {
            return response()->json(['message' => 'Not an image.'], 415);
        }
        if (! preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $name)) {
            $ext = stripos($ctype, 'png') !== false ? 'png'
                : (stripos($ctype, 'webp') !== false ? 'webp'
                : (stripos($ctype, 'gif') !== false ? 'gif' : 'jpg'));
            $name .= '.' . $ext;
        }

        return response($body, 200, [
            'Content-Type'        => $ctype ?: 'image/jpeg',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'private, max-age=0',
        ]);
    }

    /* A ROOM FOR THIS CHILD, AT A CENTRE YOU WORK AT (2026-11-05 leak sweep). room_id came from the
       request and was written unchecked, so an entry could be filed into another centre's or agency's
       room and show on that room's lists. The room must be at the child's centre or one the child is
       placed in, and the caller must be staff at the room's centre (authorizeCentreAccess: a staff role
       there, its agency's admin, a room there, or a platform admin switched into its agency). */
    private function ktRoomFitsChild($user, int $roomId, int $childId): bool
    {
        $roomCentre = (int) DB::table('rooms')->where('id', $roomId)->value('centre_id');
        if ($roomCentre <= 0 || ! $this->authorizeCentreAccess($user, $roomCentre)) return false;
        $child = DB::table('children')->where('id', $childId)->first(['family_id', 'primary_room_id']);
        if (! $child) return false;
        if ((int) DB::table('families')->where('id', $child->family_id)->value('centre_id') === $roomCentre) return true;
        if ((int) ($child->primary_room_id ?? 0) === $roomId) return true;
        return DB::table('enrollments')->where('child_id', $childId)->whereNull('end_date')->where('room_id', $roomId)->exists();
    }

    /* STAFF, NOT ONLY A PARENT (2026-11-05 leak sweep). canAccessChildId() also says yes to the child's
       own guardian, and these are staff writes: a parent who holds a staff role anywhere passed the route
       gate and could write them for their own child. Call AFTER canAccessChildId() has passed: someone who
       is not the child's guardian passed it as staff; a guardian passes here only if they are also staff
       at the child's centre (or the centre of one of its open placements). */
    private function ktStaffForChild($user, int $childId): bool
    {
        $child = DB::table('children')->where('id', $childId)->first(['family_id']);
        if (! $child) return false;
        $isGuardian = DB::table('guardians')->where('user_id', $user->id)->where('family_id', $child->family_id)->exists();
        if (! $isGuardian) return true;
        $centres = DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->where('e.child_id', $childId)->whereNull('e.end_date')->pluck('r.centre_id')->all();
        $centres[] = DB::table('families')->where('id', $child->family_id)->value('centre_id');
        foreach (array_unique(array_filter(array_map('intval', $centres))) as $cid) {
            if ($this->authorizeCentreAccess($user, $cid)) return true;
        }
        return false;
    }
}
