<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Live;
use App\Support\Notify;
use App\Support\TempPickups;
use App\Support\TestAgency;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Temporary pickup people (2026-11-24): App\Support\TempPickups has the rules.
 *
 * Parent   GET /parent/temp-pickups · POST /parent/temp-pickups (multipart, photo) · POST …/{id}/cancel · POST …/{id}/resend · GET …/{id}/photo
 * Staff    GET /provider/temp-pickups · GET /provider/temp-pickups/child/{child} · POST /provider/temp-pickups/verify {child_id, code, pass_id?}
 *          POST /provider/temp-pickups/alert {child_id, pass_id?} · POST /provider/temp-pickups/{id}/cancel · GET /provider/temp-pickups/{id}/photo
 * Holder   POST /pickup-pass/view {token} · POST /pickup-pass/sign-out {token, centre_code}   (no account: the link is the pass)
 */
class TempPickupController extends Controller
{
    /* ── parents ──────────────────────────────────────────────────────────── */

    /** The families I am a real guardian of (not a pick-up-only contact), with their children at open centres. */
    private function myFamilies(int $uid): array
    {
        $rows = DB::table('guardians as g')
            ->join('families as fa', 'fa.id', '=', 'g.family_id')
            ->join('centres as ce', 'ce.id', '=', 'fa.centre_id')
            ->join('children as c', 'c.family_id', '=', 'fa.id')
            ->tap(fn ($q) => Live::centres(Live::families($q, 'fa'), 'ce'))
            ->where('g.user_id', $uid)->where(fn ($q) => $q->whereNull('g.billing_hidden')->orWhere('g.billing_hidden', 0))
            ->whereNull('c.deleted_at')->where(fn ($q) => Live::enrolledChildren($q, 'c'))
            ->orderBy('c.first_name')
            ->get(['fa.id as family_id', 'ce.id as centre_id', 'ce.agency_id', 'c.id as child_id', 'c.first_name', 'c.preferred_name']);
        $out = [];
        foreach ($rows as $r) {
            $f = &$out[(int) $r->family_id];
            $f ??= ['family_id' => (int) $r->family_id, 'centre_id' => (int) $r->centre_id, 'agency_id' => (int) $r->agency_id, 'children' => []];
            $f['children'][(int) $r->child_id] = ['id' => (int) $r->child_id, 'name' => trim((string) ($r->preferred_name ?: $r->first_name))];
            unset($f);
        }
        foreach ($out as &$f) $f['children'] = array_values($f['children']);

        return $out;
    }

    private function present(object $p, bool $withCode = false): array
    {
        $names = TempPickups::childNames(TempPickups::childIds($p));
        $used = TempPickups::usedChildIds($p);
        $out = ['id' => (int) $p->id, 'full_name' => $p->full_name, 'relationship' => $p->relationship,
            'phone' => $p->phone ? preg_replace('/^\+1(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', $p->phone) : null,
            'children' => array_values(array_map(fn ($id, $n) => ['id' => $id, 'name' => $n, 'picked_up' => in_array($id, $used, true)], array_keys($names), $names)),
            'mode' => $p->mode, 'one_time' => (bool) $p->one_time, 'starts_on' => (string) $p->starts_on, 'ends_on' => (string) $p->ends_on,
            'window' => TempPickups::window($p), 'state' => TempPickups::state($p), 'uses' => (int) $p->uses, 'note' => $p->note,
            'has_photo' => (bool) $p->photo_path, 'added_by' => (string) (strtok(trim((string) DB::table('users')->where('id', $p->created_by_user_id)->value('first_name')), ' ') ?: ''),
            'last_used' => $p->last_used_at ? Carbon::parse($p->last_used_at)->timezone(AgencyTime::tz((int) $p->agency_id))->format('M j, g:i A') : null];
        if ($withCode) {
            try { $out['code'] = Crypt::decryptString((string) $p->code_enc); } catch (\Throwable $e) { $out['code'] = null; }
        }

        return $out;
    }

    /** GET /parent/temp-pickups */
    public function parentIndex(Request $request): JsonResponse
    {
        $fams = $this->myFamilies((int) $request->user()->id);
        $rows = $fams ? DB::table('temp_pickups')->whereIn('family_id', array_keys($fams))
            ->where(fn ($q) => $q->where('ends_on', '>=', now()->subDays(14)->toDateString())->orWhere('status', 'active'))
            ->orderByDesc('id')->limit(40)->get() : collect();

        return response()->json(['families' => array_values($fams), 'max_days' => TempPickups::MAX_DAYS,
            'today' => $fams ? AgencyTime::today(reset($fams)['agency_id']) : now()->toDateString(),
            'passes' => $rows->map(fn ($p) => $this->present($p, true))->values()]);
    }

    /** POST /parent/temp-pickups (multipart) */
    public function parentStore(Request $request): JsonResponse
    {
        $d = $request->validate([
            'child_ids' => ['required', 'array', 'min:1', 'max:8'], 'child_ids.*' => ['integer'],
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'relationship' => ['nullable', 'string', 'max:60'],
            'phone' => ['required', 'string', 'max:40'],
            'mode' => ['required', 'in:pass,signin'],
            'one_time' => ['required', 'boolean'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:300'],
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:12288'],
        ], ['photo.required' => 'Add a photo of the person, so staff can see who to expect.']);
        $uid = (int) $request->user()->id;
        $fam = null;
        foreach ($this->myFamilies($uid) as $f) {
            $ids = array_column($f['children'], 'id');
            if (! array_diff(array_map('intval', $d['child_ids']), $ids)) { $fam = $f; break; }
        }
        if (! $fam) return response()->json(['message' => 'Pick your own children (from one family).'], 403);
        $e164 = \App\Support\Telnyx::e164((string) $d['phone']);
        if (! preg_match('/^\+1[2-9]\d{2}[2-9]\d{6}$/', $e164)) return response()->json(['message' => 'Enter their mobile number (10 digits), so we can text them the pickup code.'], 422);
        $today = AgencyTime::today($fam['agency_id']);
        $start = $d['starts_on'];
        $end = $d['one_time'] ? $start : ($d['ends_on'] ?: $start);
        if ($start < $today) return response()->json(['message' => 'The first day can\'t be in the past.'], 422);
        if ($end < $start) return response()->json(['message' => 'The last day must be on or after the first day.'], 422);
        if (Carbon::parse($start)->diffInDays(Carbon::parse($end)) >= TempPickups::MAX_DAYS) {
            return response()->json(['message' => 'A temporary pickup can last up to ' . TempPickups::MAX_DAYS . ' days. For longer, ask the centre to add them as an emergency contact.'], 422);
        }
        $photo = $this->keepPhoto($request->file('photo'));
        if (! $photo) return response()->json(['message' => 'That photo could not be read. Try a JPG or PNG.'], 422);
        [$p, $code, $token] = TempPickups::create(['agency_id' => $fam['agency_id'], 'centre_id' => $fam['centre_id'], 'family_id' => $fam['family_id'],
            'child_ids' => array_map('intval', $d['child_ids']), 'user_id' => $uid, 'full_name' => trim($d['full_name']),
            'relationship' => trim((string) ($d['relationship'] ?? '')), 'phone' => $e164, 'photo_path' => $photo, 'mode' => $d['mode'],
            'one_time' => (bool) $d['one_time'], 'starts_on' => $start, 'ends_on' => $end, 'note' => trim((string) ($d['note'] ?? ''))]);
        $texted = TempPickups::textHolder($p, $code, $token);
        $told = TempPickups::tellStaffCreated($p);
        $kids = implode(' and ', TempPickups::childNames(TempPickups::childIds($p)));
        TempPickups::audit($fam['agency_id'], $uid, 'temp_pickup.created', TempPickups::childIds($p)[0] ?? null,
            'Added ' . $p->full_name . ' as a temporary pickup (' . ($p->mode === 'signin' ? 'pickup sign-in' : 'pass') . ') for ' . $kids . ': ' . TempPickups::window($p)
            . '; text ' . $texted . ', ' . $told . ' staff told');

        return response()->json(['ok' => true, 'texted' => $texted, 'staff_told' => $told, 'pass' => $this->present($p, true),
            'link' => TestAgency::blocks($fam['agency_id']) ? TempPickups::PASS_URL . '#t=' . $token : null]);
    }

    /** Re-encode to a small JPEG (drops location and other photo metadata); private disk. */
    private function keepPhoto($file): ?string
    {
        try {
            $raw = file_get_contents($file->getRealPath());
            $img = @imagecreatefromstring($raw);
            if (! $img) return null;
            if (function_exists('exif_read_data') && str_contains((string) $file->getMimeType(), 'jpeg')) {
                $ex = @exif_read_data($file->getRealPath());
                $o = is_array($ex) ? (int) ($ex['Orientation'] ?? 1) : 1;
                if ($o === 3) $img = imagerotate($img, 180, 0); elseif ($o === 6) $img = imagerotate($img, -90, 0); elseif ($o === 8) $img = imagerotate($img, 90, 0);
            }
            $w = imagesx($img); $h = imagesy($img); $s = min(1, 720 / max($w, $h));
            $out = imagecreatetruecolor(max(1, (int) ($w * $s)), max(1, (int) ($h * $s)));
            imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
            ob_start(); imagejpeg($out, null, 84); $jpg = ob_get_clean();
            $path = 'temp-pickups/' . date('Y/m') . '/' . Str::random(28) . '.jpg';
            Storage::disk('local')->put($path, $jpg);

            return $path;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parentPass(Request $request, int $id): object
    {
        $p = DB::table('temp_pickups')->where('id', $id)->first();
        abort_unless($p && isset($this->myFamilies((int) $request->user()->id)[(int) $p->family_id]), 404);

        return $p;
    }

    /** POST /parent/temp-pickups/{id}/cancel */
    public function parentCancel(Request $request, int $id): JsonResponse
    {
        $p = $this->parentPass($request, $id);

        return $this->cancel($request, $p, 'parent');
    }

    /** POST /parent/temp-pickups/{id}/resend - a new code and link (the old ones stop working), texted again. */
    public function parentResend(Request $request, int $id): JsonResponse
    {
        $p = $this->parentPass($request, $id);
        if (! in_array(TempPickups::state($p), ['active', 'upcoming'], true)) return response()->json(['message' => 'This pickup is no longer active.'], 422);
        $code = TempPickups::newCode((int) $p->id, TempPickups::childIds($p));
        $token = TempPickups::newToken((int) $p->id);
        $p = DB::table('temp_pickups')->where('id', $id)->first();
        $texted = TempPickups::textHolder($p, $code, $token);
        TempPickups::audit((int) $p->agency_id, (int) $request->user()->id, 'temp_pickup.resent', TempPickups::childIds($p)[0] ?? null,
            'Sent ' . $p->full_name . ' a new pickup code and link (the old ones no longer work); text ' . $texted);

        return response()->json(['ok' => true, 'texted' => $texted, 'pass' => $this->present($p, true)]);
    }

    /** GET /parent/temp-pickups/{id}/photo */
    public function parentPhoto(Request $request, int $id)
    {
        return $this->photo($this->parentPass($request, $id));
    }

    private function photo(object $p)
    {
        abort_unless($p->photo_path && Storage::disk('local')->exists($p->photo_path), 404);

        return response(Storage::disk('local')->get($p->photo_path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }

    private function cancel(Request $request, object $p, string $who): JsonResponse
    {
        if (in_array($p->status, ['cancelled', 'used'], true)) return response()->json(['ok' => true]);
        DB::table('temp_pickups')->where('id', $p->id)->update(['status' => 'cancelled', 'cancelled_by_id' => $request->user()->id, 'cancelled_at' => now(), 'updated_at' => now()]);
        TempPickups::audit((int) $p->agency_id, (int) $request->user()->id, 'temp_pickup.cancelled', TempPickups::childIds($p)[0] ?? null,
            'Cancelled ' . $p->full_name . '\'s temporary pickup (' . TempPickups::window($p) . ') as ' . $who);
        try { TempPickups::tellCancelled($p, (int) $request->user()->id, $who); } catch (\Throwable $e) {}

        return response()->json(['ok' => true]);
    }

    /* ── staff ────────────────────────────────────────────────────────────── */

    private function staffAgency(Request $request, ?int $childId = null): int
    {
        if ($childId) return (int) Notify::agencyOfChild($childId);
        $a = (int) $request->header('X-Active-Agency-Id');
        if (! $a) $a = (int) DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)->where('role', '!=', 'guardian')->value('agency_id');
        abort_unless($a, 400, 'Select an agency first.');

        return $a;
    }

    /** A child this staff member looks after (their rooms; directors and admins more) - the roster's own rule. */
    private function staffChild(Request $request, int $childId): int
    {
        $agencyId = $this->staffAgency($request, $childId);
        abort_unless($agencyId && in_array($childId, Visibility::liveChildIds($agencyId, $request->user()), true), 404);

        return $agencyId;
    }

    private function staffPass(Request $request, int $id): object
    {
        $p = DB::table('temp_pickups')->where('id', $id)->first();
        abort_unless($p, 404);
        $mine = Visibility::liveChildIds((int) $p->agency_id, $request->user());
        abort_unless(array_intersect(TempPickups::childIds($p), $mine), 404);

        return $p;
    }

    /** GET /provider/temp-pickups - active and upcoming in my rooms/centres. */
    public function staffIndex(Request $request): JsonResponse
    {
        $agencyId = $this->staffAgency($request);
        $mine = Visibility::liveChildIds($agencyId, $request->user());
        $today = AgencyTime::today($agencyId);
        $rows = DB::table('temp_pickups')->where('agency_id', $agencyId)->where('ends_on', '>=', $today)
            ->where(fn ($q) => $q->where('status', 'active')->orWhere('updated_at', '>=', now()->subDay()))->orderBy('starts_on')->limit(80)->get()
            ->filter(fn ($p) => array_intersect(TempPickups::childIds($p), $mine))
            ->map(function ($p) use ($mine) {
                $x = $this->present($p);
                $x['children'] = array_values(array_filter($x['children'], fn ($c) => in_array($c['id'], $mine, true)));
                return $x;
            })->values();

        return response()->json(['passes' => $rows]);
    }

    /** GET /provider/temp-pickups/child/{child} - who may collect this child today with a pass. */
    public function staffForChild(Request $request, int $child): JsonResponse
    {
        $this->staffChild($request, $child);

        return response()->json(['passes' => array_map(fn ($p) => $this->present($p), TempPickups::todayFor($child))]);
    }

    /** GET /provider/temp-pickups/child/{child}/all - the child record's list: recent passes in every state (same guard as Cancel). */
    public function staffChildAll(Request $request, int $child): JsonResponse
    {
        $agencyId = $this->staffChild($request, $child);
        $since = Carbon::parse(AgencyTime::today($agencyId))->subDays(60)->toDateString();
        $rows = DB::table('temp_pickups')->where('agency_id', $agencyId)->whereRaw('JSON_CONTAINS(child_ids, ?)', [(string) $child])
            ->where(fn ($q) => $q->where('ends_on', '>=', $since)->orWhere('status', 'active'))->orderByDesc('starts_on')->orderByDesc('id')->limit(20)->get();

        return response()->json(['passes' => $rows->map(function ($p) use ($child) {
            $x = $this->present($p);
            $x['children'] = array_values(array_filter($x['children'], fn ($c) => $c['id'] === $child));
            return $x;
        })->values()]);
    }

    /** POST /provider/temp-pickups/verify {child_id, code, pass_id?} */
    public function staffVerify(Request $request): JsonResponse
    {
        $d = $request->validate(['child_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:12'], 'pass_id' => ['nullable', 'integer']]);
        $this->staffChild($request, (int) $d['child_id']);
        $r = TempPickups::verify((int) $request->user()->id, (int) $d['child_id'], preg_replace('/\D/', '', $d['code']), $d['pass_id'] ?? null);

        return response()->json(['ok' => $r['ok'], 'message' => $r['message'], 'alerted' => $r['alerted'] ?? 0,
            'pass' => $r['ok'] && $r['pass'] ? $this->present($r['pass']) : null], $r['ok'] ? 200 : 422);
    }

    /** POST /provider/temp-pickups/lookup {code} - One-Tap "Scan a pickup pass": which pass, which children, no child picked first. */
    public function staffLookup(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $agencyId = $this->staffAgency($request);
        $scope = Visibility::liveChildIds($agencyId, $request->user());
        $r = TempPickups::lookup((int) $request->user()->id, $agencyId, $scope, (string) $d['code']);
        if (! $r['ok']) {
            return response()->json(['ok' => false, 'kind' => $r['kind'], 'message' => $r['message'], 'alerted' => $r['alerted'] ?? 0], $r['kind'] === 'paused' ? 429 : 422);
        }
        $p = $r['pass'];
        $tz = AgencyTime::tz($agencyId);
        $names = TempPickups::childNames($r['children']);
        $kids = [];
        foreach ($r['children'] as $cid) {
            [$from, $to] = AgencyTime::dayRangeForCentre((int) $p->centre_id);
            $last = DB::table('check_events')->where('child_id', $cid)->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->where('occurred_at', '<=', now())
                ->orderByDesc('occurred_at')->orderByDesc('id')->first(['event_type', 'room_id', 'occurred_at']);
            $kids[] = ['id' => $cid, 'name' => $names[$cid] ?? 'Child', 'room_id' => $last ? (int) $last->room_id : null,
                'at_centre' => $last && $last->event_type === 'check_in',
                'out_at' => $last && $last->event_type === 'check_out' ? Carbon::parse($last->occurred_at)->timezone($tz)->format('g:i A') : null];
        }
        $x = $this->present($p);
        $x['children'] = $kids;

        return response()->json(['ok' => true, 'pass' => $x]);
    }

    /** POST /provider/temp-pickups/alert {child_id, pass_id?} - "This isn't the right person". */
    public function staffAlert(Request $request): JsonResponse
    {
        $d = $request->validate(['child_id' => ['required', 'integer'], 'pass_id' => ['nullable', 'integer']]);
        $this->staffChild($request, (int) $d['child_id']);
        $p = ! empty($d['pass_id']) ? DB::table('temp_pickups')->where('id', $d['pass_id'])->whereRaw('JSON_CONTAINS(child_ids, ?)', [(string) $d['child_id']])->first() : null;
        $n = TempPickups::alert((int) $d['child_id'], (int) $request->user()->id, $p ? 'a pass in ' . $p->full_name . '\'s name, but staff say it isn\'t that person' : 'staff say the person collecting isn\'t the right person', $p);
        if ($p && $p->status === 'active') DB::table('temp_pickups')->where('id', $p->id)->update(['status' => 'locked', 'updated_at' => now()]);

        return response()->json(['ok' => true, 'alerted' => $n]);
    }

    /** POST /provider/temp-pickups/{id}/cancel */
    public function staffCancel(Request $request, int $id): JsonResponse
    {
        return $this->cancel($request, $this->staffPass($request, $id), 'staff');
    }

    /** GET /provider/temp-pickups/{id}/photo */
    public function staffPhoto(Request $request, int $id)
    {
        return $this->photo($this->staffPass($request, $id));
    }

    /* ── the pickup person (no account: their link is the pass) ───────────── */

    /** POST /pickup-pass/view {token} */
    public function holderView(Request $request): JsonResponse
    {
        $p = TempPickups::byToken((string) $request->input('token', ''));
        if (! $p) return response()->json(['message' => 'This pickup pass link isn\'t valid. Ask the parent to send it again.'], 404);
        $st = TempPickups::state($p);
        $centre = DB::table('centres')->where('id', $p->centre_id)->first(['name', 'address_line1', 'city', 'phone']);
        $agency = DB::table('agencies')->where('id', $p->agency_id)->first(['name', 'contact_phone']);
        $x = $this->present($p, $st === 'active' || $st === 'upcoming');
        unset($x['phone'], $x['note']);

        return response()->json(['pass' => $x, 'centre' => ['name' => $centre->name ?? null, 'address' => trim(($centre->address_line1 ?? '') . ', ' . ($centre->city ?? ''), ', '),
            'phone' => $centre->phone ?? ($agency->contact_phone ?? null)], 'agency' => $agency->name ?? null]);
    }

    /** POST /pickup-pass/sign-out {token, centre_code} - a pickup sign-in signs the children OUT with One-Tap. */
    public function holderSignOut(Request $request): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'max:60'], 'centre_code' => ['required', 'string', 'max:120']]);
        $p = TempPickups::byToken($d['token']);
        if (! $p) return response()->json(['message' => 'This pickup pass link isn\'t valid.'], 404);
        $centreId = $this->centreFromCode(trim($d['centre_code']));
        if (! $centreId) return response()->json(['message' => 'That isn\'t today\'s centre code. Scan the QR code at the door, or type the 6-letter code under it.'], 422);
        if ((int) DB::table('centres')->where('id', $centreId)->value('agency_id') !== (int) $p->agency_id) {
            return response()->json(['message' => 'That code is for a different centre.'], 422);
        }
        if ($p->mode !== 'signin') return response()->json(['message' => 'Show this pass to a staff member: they will sign the child out with you.'], 422);
        $done = [];
        $refused = [];
        foreach (TempPickups::childIds($p) as $cid) {
            [$ok, $why] = TempPickups::usable($p, $cid);
            $first = TempPickups::childNames([$cid])[$cid] ?? 'the child';
            if (! $ok) { $refused[$cid] = $why; continue; }
            [$from, $to] = AgencyTime::dayRangeForCentre($centreId);
            $last = DB::table('check_events')->where('child_id', $cid)->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
                ->where('occurred_at', '<=', now())->orderByDesc('occurred_at')->first();
            if (! $last || $last->event_type !== 'check_in') continue;   // not here: nothing to sign out
            $ev = DB::table('check_events')->insertGetId(['child_id' => $cid, 'room_id' => $last->room_id, 'event_type' => 'check_out', 'occurred_at' => now(),
                'by_user_id' => $p->created_by_user_id, 'recorded_by_id' => $p->created_by_user_id,
                'notes' => 'Temporary pickup: ' . $p->full_name . ' (One-Tap)', 'created_at' => now()]);
            TempPickups::recordUse($p, $cid, $ev, null, 'self_signout');
            $this->tellRoom($p, $cid, (int) $last->room_id);
            $done[] = $first;
            $p = DB::table('temp_pickups')->where('id', $p->id)->first();
        }
        if (! $done && $refused) {
            $why = reset($refused);
            $what = ['expired' => 'an expired pickup pass', 'upcoming' => 'a pickup pass that isn\'t valid yet', 'used' => 'a pickup pass that was already used',
                'cancelled' => 'a cancelled pickup pass', 'locked' => 'a locked pickup pass'][$why] ?? 'a pickup pass that isn\'t valid now';
            foreach (array_keys($refused) as $cid) TempPickups::alert($cid, null, $what . ' in ' . $p->full_name . '\'s name (One-Tap at the door)', $p);

            return response()->json(['message' => 'This pass can\'t be used right now, so the child can\'t be signed out. The centre and family have been told. Please speak to a staff member.'], 422);
        }
        if (! $done) return response()->json(['message' => 'There\'s no one on this pass signed in at the centre right now.'], 422);

        return response()->json(['ok' => true, 'signed_out' => $done, 'time' => now(AgencyTime::tz((int) $p->agency_id))->format('g:i A'),
            'message' => implode(' and ', $done) . ' ' . (count($done) === 1 ? 'is' : 'are') . ' signed out. Please let a staff member see you leave.']);
    }

    /** The centre a One-Tap code at the door belongs to (today's QR or 6-letter code), or null. */
    private function centreFromCode(string $raw): ?int
    {
        if (preg_match('/^[A-Za-z0-9]{6}$/', $raw)) {
            $sc = DB::table('checkin_short_codes')->where('code', strtoupper($raw))->first();
            return $sc && (! $sc->expires_at || strtotime((string) $sc->expires_at) >= time()) ? (int) $sc->centre_id : null;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 4 || $parts[0] !== 'KTCHK' || $parts[2] !== now()->format('Ymd')) return null;
        $sig = substr(hash_hmac('sha256', (int) $parts[1] . '.' . $parts[2], (string) config('app.key')), 0, 16);

        return hash_equals($sig, $parts[3]) ? (int) $parts[1] : null;
    }

    /** The room hears a temporary pickup person signed a child out themselves (they can check at the door). */
    private function tellRoom(object $p, int $childId, int $roomId): void
    {
        if (TestAgency::blocks((int) $p->agency_id)) return;
        $first = TempPickups::childNames([$childId])[$childId] ?? 'A child';
        $one = (object) ['agency_id' => $p->agency_id, 'centre_id' => $p->centre_id, 'child_ids' => json_encode([$childId])];
        $rows = array_map(fn ($uid) => ['user_id' => $uid, 'agency_id' => (int) $p->agency_id, 'type' => 'checkin', 'title' => '🎫 ' . $first . ' signed out (temporary pickup)',
            'body' => $p->full_name . ' signed ' . $first . ' out with One-Tap at ' . now(AgencyTime::tz((int) $p->agency_id))->format('g:i A') . '. Their photo is on the pass: please check it\'s them at the door.',
            'data' => json_encode(['link' => '#safe-arrival', 'child_id' => $childId, 'temp_pickup' => (int) $p->id]), 'created_at' => now()], TempPickups::staffFor($one));
        if ($rows) Notify::write($rows);
    }
}
