<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

/**
 * v22p51 — Twilio SMS.
 * Two flows:
 *   1. broadcast: director picks an audience (centre / agency / family / role)
 *      and the system sends one SMS per recipient phone number.
 *   2. emergency: pre-rendered template (closure, evacuation, illness etc.)
 *      sent immediately to a target list.
 *
 * Each send goes through sendOne() so we get a sms_messages row for audit
 * and a single point of opt-in respect.
 *
 * Env: TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM. Inert without them.
 */
final class SmsController extends Controller
{
    public function broadcast(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);
        $data = $request->validate([
            'audience' => 'required|string|in:centre,room,agency,family,role',
            'centre_id' => 'nullable|integer',
            'room_id'  => 'nullable|integer',
            'family_id' => 'nullable|integer',
            'role'     => 'nullable|string',
            'body'     => 'required|string|max:300',
            'category' => 'nullable|string|max:40',
        ]);
        // A narrowing audience without the thing to narrow BY used to fall through to
        // "everyone in the agency". On a paid channel that turns a message for one room
        // into a message for every family the agency has. Refuse it instead.
        $needs = ['centre' => 'centre_id', 'room' => 'room_id', 'family' => 'family_id'];
        if (isset($needs[$data['audience']]) && empty($data[$needs[$data['audience']]])) {
            return response()->json([
                'message' => 'Choose which one to send to before sending.',
                'errors' => [$needs[$data['audience']] => ['Required for this audience.']],
            ], 422);
        }

        // The centre or room must belong to THIS agency — the ids arrive from the client.
        if (! empty($data['centre_id'])) {
            abort_unless(DB::table('centres')->where('id', $data['centre_id'])
                ->where('agency_id', $agencyId)->exists(), 403, 'Unknown centre.');
        }
        if (! empty($data['room_id'])) {
            abort_unless(DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')
                ->where('r.id', $data['room_id'])->where('c.agency_id', $agencyId)->exists(), 403, 'Unknown room.');
        }

        $recipients = $this->resolveRecipients($agencyId, $data);
        $sent = 0; $skipped = 0;
        foreach ($recipients as $r) {
            $ok = $this->sendOne($agencyId, (int) $r->id, (string) ($r->phone ?? ''), $data['body'], $data['category'] ?? 'broadcast');
            if ($ok) $sent++; else $skipped++;
        }
        return response()->json(['sent' => $sent, 'skipped' => $skipped, 'total' => $recipients->count()]);
    }

    public function listMessages(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('sms_messages')
            ->where('agency_id', $agencyId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
        return response()->json(['data' => $rows]);
    }

    private function resolveRecipients(int $agencyId, array $data)
    {
        $q = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.agency_id', $agencyId)
            ->where('ra.active', true)
            ->where('u.sms_opt_in', 1)
            ->whereNotNull('u.phone')
            ->where('u.phone', '!=', '')
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.phone')
            ->distinct();

        if ($data['audience'] === 'role' && !empty($data['role'])) {
            $q->where('ra.role', $data['role']);
        }
        if ($data['audience'] === 'centre' && !empty($data['centre_id'])) {
            $q->where('ra.centre_id', $data['centre_id']);
        }
        // `guardians` is the family-to-user link. This joined `family_users`, which does
        // not exist on this database, so every family broadcast was a 500.
        if ($data['audience'] === 'family' && !empty($data['family_id'])) {
            $q->join('guardians as g', 'g.user_id', '=', 'u.id')
              ->where('g.family_id', $data['family_id']);
        }

        // A room means the people in it: the educators assigned to it, and the guardians
        // of the children enrolled in it. Anything less is not who you meant.
        if ($data['audience'] === 'room' && !empty($data['room_id'])) {
            $roomId = (int) $data['room_id'];

            $educators = DB::table('educator_rooms')->where('room_id', $roomId)->pluck('user_id');
            $guardians = DB::table('enrollments as e')
                ->join('children as ch', 'ch.id', '=', 'e.child_id')
                ->join('guardians as g', 'g.family_id', '=', 'ch.family_id')
                ->where('e.room_id', $roomId)
                ->whereNull('ch.deleted_at')
                ->where(function ($w) {
                    $w->whereNull('e.end_date')->orWhere('e.end_date', '>=', now()->toDateString());
                })
                ->pluck('g.user_id');

            $q->whereIn('u.id', $educators->merge($guardians)->unique()->values()->all() ?: [0]);
        }

        return $q->get();
    }

    public function sendOne(int $agencyId, int $userId, string $phone, string $body, string $category): bool
    {
        // Do-not-contact: never text a parent at a live agency while we are testing.
        if (\App\Support\Suppression::isUser($userId)) {
            \App\Support\Suppression::note('sms', $userId, $category);
            return false;
        }

        // Consent. The docblock above has always described this method as "a single point
        // of opt-in respect" and nothing here ever checked: resolveRecipients filtered on
        // a users.sms_opt_in column that did not exist, and the check-in path came
        // straight here with only its per-event preference. One text to someone who never
        // agreed is a complaint to the carrier and the number gets taken away, so the gate
        // belongs at the choke point where a new category inherits it by default.
        //
        // Logged as a skipped row rather than dropped silently, so "why did that not
        // send?" has an answer.
        if (! DB::table('users')->where('id', $userId)->value('sms_opt_in')) {
            DB::table('sms_messages')->insert([
                'agency_id' => $agencyId,
                'to_user_id' => $userId,
                'to_phone' => $phone,
                'body' => $body,
                'category' => $category,
                'status' => 'skipped',
                'error' => 'no sms consent',
                'created_at' => now(),
            ]);

            return false;
        }

        $rowId = DB::table('sms_messages')->insertGetId([
            'agency_id'  => $agencyId,
            'to_user_id' => $userId,
            'to_phone'   => $phone,
            'body'       => $body,
            'category'   => $category,
            'status'     => 'queued',
            'created_at' => now(),
        ]);
        if (!env('TWILIO_SID') || !$phone) {
            DB::table('sms_messages')->where('id', $rowId)->update([
                'status' => 'skipped', 'error' => !env('TWILIO_SID') ? 'twilio not configured' : 'no phone',
            ]);
            return false;
        }
        try {
            $client = new Client(env('TWILIO_SID'), env('TWILIO_TOKEN'));
            $msg = $client->messages->create($phone, ['from' => env('TWILIO_FROM'), 'body' => $body]);
            DB::table('sms_messages')->where('id', $rowId)->update([
                'twilio_sid' => $msg->sid, 'status' => 'sent', 'sent_at' => now(),
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Twilio send failed', ['user' => $userId, 'msg' => $e->getMessage()]);
            DB::table('sms_messages')->where('id', $rowId)->update([
                'status' => 'failed', 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    private function assertAgencyAccess(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
