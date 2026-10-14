<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\PickupLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * LOGINS FOR A FAMILY'S PEOPLE, FROM BOTH SIDES (2026-10-13, Anthony: "add ability to request
 * account for emergency guardian under parent view and also to setup under admin/director next
 * to the emergency and guardian names").
 *
 * Parent side: a guardian sees their family's guardians and emergency contacts and can ASK for
 * a pick-up login for a contact. They never create one — a login is a key to the children
 * (check-in/out QR, daily reports, photos), so the centre approves it. The request lands in
 * the bell and inbox of the agency's admins and the family's centre director.
 *
 * Staff side (centre director / agency admin, centre-scoped): next to each contact on the
 * child's record — set up a login (approving a pending request), remove it, or decline the
 * request; next to each guardian who has never signed in — send the login invite.
 *
 * The login itself is App\Support\PickupLogin: a guardian link with billing hidden, enforced
 * server-side by PickupOnlyNoBilling.
 */
final class PickupLoginController extends Controller
{
    use ResolvesCentreContext;

    private const NEVER_CLAIMED = ['invited', 'not_invited'];

    // ── Parent ──────────────────────────────────────────────────────────────

    /** The caller's families: guardians and emergency contacts, with login state. */
    public function parentContacts(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $links = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->where('g.user_id', $uid)->whereNull('f.deleted_at')
            ->get(['f.id', 'f.family_name', 'g.billing_hidden']);

        $out = [];
        foreach ($links as $l) {
            $fid = (int) $l->id;
            $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $fid)->whereNull('u.deleted_at')
                ->orderByDesc('g.is_primary')->orderBy('g.id')
                ->get(['u.id', 'u.first_name', 'u.last_name', 'u.status', 'u.phone', 'g.relationship', 'g.is_primary', 'g.billing_hidden', 'g.can_pickup'])
                ->map(fn ($g) => [
                    'id' => (int) $g->id,
                    'name' => trim($g->first_name . ' ' . $g->last_name),
                    'relationship' => $g->relationship,
                    'is_primary' => (bool) $g->is_primary,
                    'pickup_only' => (bool) $g->billing_hidden,
                    'can_pickup' => (bool) $g->can_pickup,
                    'phone' => $g->phone,
                    'has_login' => ! in_array((string) $g->status, self::NEVER_CLAIMED, true),
                    'is_me' => (int) $g->id === $uid,
                ])->values();
            $pending = DB::table('pickup_login_requests')->where('family_id', $fid)->where('status', 'pending')
                ->get(['id', 'emergency_contact_id', 'email', 'created_at'])->keyBy('emergency_contact_id');
            $contacts = DB::table('emergency_contacts')->where('family_id', $fid)
                ->orderByDesc('can_pickup')->orderBy('id')
                ->get(['id', 'name', 'relationship', 'phone', 'alt_phone', 'alt_phone_type', 'alt_phone_ext', 'email', 'can_pickup', 'user_id', 'notes'])
                ->map(function ($e) use ($pending) {
                    $p = $pending->get($e->id);
                    return [
                        'id' => (int) $e->id, 'name' => $e->name, 'relationship' => $e->relationship,
                        'phone' => $e->phone, 'email' => $e->email,
                        'alt_phone' => $e->alt_phone, 'alt_phone_type' => $e->alt_phone_type, 'alt_phone_ext' => $e->alt_phone_ext,
                        'notes' => $e->notes,
                        'can_pickup' => (bool) $e->can_pickup, 'has_login' => (bool) $e->user_id,
                        'request' => $p ? ['id' => (int) $p->id, 'email' => $p->email, 'created_at' => $p->created_at] : null,
                    ];
                })->values();
            $out[] = [
                'family_id' => $fid, 'family_name' => $l->family_name,
                // Someone who is only a pick-up login cannot hand out logins in turn.
                'can_request' => ! (int) $l->billing_hidden,
                'guardians' => $guardians, 'contacts' => $contacts,
            ];
        }

        return response()->json(['families' => $out]);
    }

    /** Ask the centre for a pick-up login for one of the family's emergency contacts. */
    public function parentRequest(Request $request, int $ec): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:190']);
        $uid = (int) $request->user()->id;
        $contact = DB::table('emergency_contacts')->where('id', $ec)->first();
        abort_unless($contact, 404);
        $link = DB::table('guardians')->where('family_id', $contact->family_id)->where('user_id', $uid)->first();
        abort_unless($link, 404);
        abort_if((int) $link->billing_hidden, 403, 'Only a parent or guardian of the family can ask for this.');
        if ($contact->user_id) {
            return response()->json(['message' => $contact->name . ' already has a pick-up login.'], 409);
        }
        $agencyId = $this->familyAgency((int) $contact->family_id);
        abort_unless($agencyId, 404);

        $email = mb_strtolower(trim($data['email']));
        $existing = DB::table('pickup_login_requests')->where('emergency_contact_id', $ec)->where('status', 'pending')->first();
        if ($existing) {
            DB::table('pickup_login_requests')->where('id', $existing->id)->update(['email' => $email, 'requested_by' => $uid, 'updated_at' => now()]);
            $reqId = (int) $existing->id;
        } else {
            $reqId = (int) DB::table('pickup_login_requests')->insertGetId([
                'family_id' => $contact->family_id, 'emergency_contact_id' => $ec, 'requested_by' => $uid,
                'email' => $email, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $who = trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? '')) ?: 'A parent';
        $this->audit($uid, $agencyId, 'family.pickup_login_requested', (int) $contact->family_id, [
            'emergency_contact_id' => $ec, 'request_id' => $reqId, 'email' => $email,
            'summary' => $who . ' asked for a pick-up login for ' . $contact->name . ' (' . $email . ').',
        ]);
        if (! $existing) $this->tellStaff($agencyId, (int) $contact->family_id, $who, $contact, $email);

        return response()->json(['ok' => true, 'id' => $reqId], $existing ? 200 : 201);
    }

    /*
     * PARENTS KEEP THEIR OWN PICK-UP LIST (2026-10-13, Anthony: "pick up authorizations for
     * parents view should show the guardians allowed to pick up child and can add or
     * remove/edit and it sends a notification to admin/educator/director when changes are
     * made and logs these appropriately").
     *
     * The list is the family's emergency_contacts — the SAME rows staff see on the child's
     * record, the educator's record, the emergency card and the sign-out check. (The old
     * pickup_authorizations table behind the parent "Pickup people" screen had no rows at
     * all and nothing else read it as the list.) Every change tells the room's educators, the
     * centre's directors and the agency's admins, tells the family's other parents, and is
     * written to the audit log with the before and after.
     *
     * Parents may change their emergency contacts, not the guardians: who is a guardian of
     * the child (and custody) is the centre's record.
     */
    private function contactRules(bool $create): array
    {
        return ($create ? ['family_id' => ['required', 'integer']] : []) + [
            'name' => ['required', 'string', 'max:120'],
            'relationship' => ['nullable', 'string', 'max:60'],
            'phone' => ['required', 'string', 'max:40'],
            'alt_phone' => ['nullable', 'string', 'max:40'],
            'alt_phone_type' => ['nullable', 'in:mobile,home,work,other'],
            'alt_phone_ext' => ['nullable', 'string', 'max:10'],
            'email' => ['nullable', 'email:rfc', 'max:180'],
            'can_pickup' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function contactCols(array $d): array
    {
        $alt = trim((string) ($d['alt_phone'] ?? ''));
        $type = $alt !== '' ? ($d['alt_phone_type'] ?? 'mobile') : null;

        return [
            'name' => trim($d['name']), 'relationship' => trim((string) ($d['relationship'] ?? '')) ?: null,
            'phone' => trim($d['phone']), 'alt_phone' => $alt ?: null, 'alt_phone_type' => $type,
            'alt_phone_ext' => $type === 'work' ? (trim((string) ($d['alt_phone_ext'] ?? '')) ?: null) : null,
            'email' => trim((string) ($d['email'] ?? '')) ?: null,
            'can_pickup' => (bool) $d['can_pickup'],
            'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
        ];
    }

    /** The caller must be a parent/guardian of this family — not a pick-up-only login. */
    private function parentFamily(Request $request, int $familyId): int
    {
        $link = DB::table('guardians')->where('family_id', $familyId)->where('user_id', $request->user()->id)->first();
        abort_unless($link, 404);
        abort_if((int) $link->billing_hidden, 403, 'Only a parent or guardian of the family can change its contacts.');
        $agencyId = $this->familyAgency($familyId);
        abort_unless($agencyId, 404);

        return $agencyId;
    }

    /** POST /parent/family-contacts */
    public function parentAdd(Request $request): JsonResponse
    {
        $d = $request->validate($this->contactRules(true));
        $fid = (int) $d['family_id'];
        $agencyId = $this->parentFamily($request, $fid);
        if (DB::table('emergency_contacts')->where('family_id', $fid)->count() >= 20) {
            return response()->json(['message' => 'A family can have up to 20 contacts. Remove one first.'], 422);
        }
        $cols = $this->contactCols($d);
        $id = (int) DB::table('emergency_contacts')->insertGetId(['family_id' => $fid, 'created_at' => now(), 'updated_at' => now()] + $cols);
        $this->contactChanged($request, $agencyId, $fid, 'added', null, (object) ($cols + ['id' => $id]));

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** PATCH /parent/family-contacts/{ec} */
    public function parentUpdate(Request $request, int $ec): JsonResponse
    {
        $before = DB::table('emergency_contacts')->where('id', $ec)->first();
        abort_unless($before, 404);
        $agencyId = $this->parentFamily($request, (int) $before->family_id);
        $d = $request->validate($this->contactRules(false));
        $cols = $this->contactCols($d);
        DB::table('emergency_contacts')->where('id', $ec)->update($cols + ['updated_at' => now()]);
        $after = DB::table('emergency_contacts')->where('id', $ec)->first();
        // No longer allowed to pick up: a pick-up login stops working with it.
        if ($before->user_id && ! $cols['can_pickup']) {
            PickupLogin::revoke($agencyId, (int) $before->family_id, $ec, (int) $request->user()->id);
        }
        $this->contactChanged($request, $agencyId, (int) $before->family_id, 'updated', $before, $after);

        return response()->json(['ok' => true]);
    }

    /** DELETE /parent/family-contacts/{ec} */
    public function parentRemove(Request $request, int $ec): JsonResponse
    {
        $before = DB::table('emergency_contacts')->where('id', $ec)->first();
        abort_unless($before, 404);
        $agencyId = $this->parentFamily($request, (int) $before->family_id);
        if ($before->user_id) {
            PickupLogin::revoke($agencyId, (int) $before->family_id, $ec, (int) $request->user()->id);
        }
        DB::table('pickup_login_requests')->where('emergency_contact_id', $ec)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'updated_at' => now()]);
        DB::table('emergency_contacts')->where('id', $ec)->delete();
        $this->contactChanged($request, $agencyId, (int) $before->family_id, 'removed', $before, null);

        return response()->json(['ok' => true]);
    }

    /** Audit (before/after) + tell the staff and the family's other parents. */
    private function contactChanged(Request $request, int $agencyId, int $familyId, string $verb, ?object $before, ?object $after): void
    {
        $actor = $request->user();
        $who = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: 'A parent';
        $name = (string) (($after ?? $before)->name ?? 'a contact');
        $family = (string) DB::table('families')->where('id', $familyId)->value('family_name');
        $children = DB::table('children')->where('family_id', $familyId)->whereNull('deleted_at')
            ->get(['id', 'first_name', 'preferred_name']);
        $kids = $children->map(fn ($c) => $c->preferred_name ?: $c->first_name)->filter()->implode(' & ') ?: 'the children';

        $changes = [];
        if ($verb === 'updated' && $before && $after) {
            $labels = ['name' => 'name', 'relationship' => 'relationship', 'phone' => 'phone', 'alt_phone' => 'second phone',
                'email' => 'email', 'can_pickup' => 'allowed to pick up', 'notes' => 'notes'];
            foreach ($labels as $col => $label) {
                $a = (string) ($before->{$col} ?? ''); $b = (string) ($after->{$col} ?? '');
                if ($col === 'can_pickup') { $a = $a ? 'yes' : 'no'; $b = $b ? 'yes' : 'no'; }
                if ($a !== $b) $changes[] = $label . ': ' . ($a === '' ? '—' : $a) . ' → ' . ($b === '' ? '—' : $b);
            }
            if (! $changes) return;                    // nothing actually changed: nobody is told
        }
        $pick = (bool) (($after ?? $before)->can_pickup ?? false);
        $line = match ($verb) {
            'added' => $who . ' added ' . $name . ' as ' . ($pick ? 'an authorised pick-up' : 'an emergency contact') . ' for ' . $kids . '.',
            'removed' => $who . ' removed ' . $name . ' from ' . $kids . '\'s pick-ups and emergency contacts.',
            default => $who . ' changed ' . $name . ' (' . implode('; ', $changes) . ').',
        };
        $this->audit((int) $actor->id, $agencyId, 'family.emergency_contact_' . $verb, $familyId, [
            'emergency_contact_id' => (int) (($after ?? $before)->id ?? 0), 'by' => 'parent',
            'before' => $before, 'after' => $after, 'changes' => $changes, 'summary' => $line,
        ]);

        try {
            $centreId = (int) DB::table('families')->where('id', $familyId)->value('centre_id');
            $roomIds = DB::table('enrollments')->whereIn('child_id', $children->pluck('id'))
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()))
                ->pluck('room_id')->unique()->all();
            $educators = $roomIds ? DB::table('educator_rooms as er')->join('users as u', 'u.id', '=', 'er.user_id')
                ->whereIn('er.room_id', $roomIds)->whereNull('u.deleted_at')->where('u.status', 'active')->pluck('u.id')->all() : [];
            $leads = DB::table('role_assignments')->where('active', 1)
                ->where(function ($q) use ($agencyId, $centreId) {
                    $q->where(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $agencyId))
                      ->orWhere(fn ($x) => $x->where('role', 'centre_director')->where('centre_id', $centreId));
                })->pluck('user_id')->all();
            $staff = array_values(array_diff(array_unique(array_map('intval', array_merge($educators, $leads))), [(int) $actor->id]));
            $parents = DB::table('guardians')->where('family_id', $familyId)->where('billing_hidden', 0)
                ->where('user_id', '!=', $actor->id)->pluck('user_id')->map(fn ($x) => (int) $x)->all();

            $title = '🚗 Pick-up list ' . ($verb === 'added' ? 'addition' : ($verb === 'removed' ? 'removal' : 'change')) . ($family !== '' ? ' · ' . $family : '');
            $childId = (int) ($children->first()->id ?? 0);
            $rows = [];
            foreach ($staff as $uid) {
                $rows[] = ['user_id' => $uid, 'type' => 'pickup_auth', 'title' => $title, 'body' => mb_substr($line, 0, 500),
                    'data' => json_encode(['link' => $childId ? '#child-detail?id=' . $childId : '#admin-families', 'family_id' => $familyId]), 'created_at' => now()];
            }
            foreach ($parents as $uid) {
                $rows[] = ['user_id' => $uid, 'type' => 'pickup_auth', 'title' => $title, 'body' => mb_substr($line, 0, 500),
                    'data' => json_encode(['link' => '#family-contacts', 'family_id' => $familyId]), 'created_at' => now()];
            }
            if ($rows) Notify::write($rows);
            foreach (array_merge($staff, $parents) as $uid) {
                try { app(\App\Services\FcmService::class)->sendToUser($uid, $title, $line, in_array($uid, $parents, true) ? '#family-contacts' : '#child-detail?id=' . $childId); } catch (\Throwable $e) {}
            }
            // Directors and admins also by email: a change to who may collect a child is worth a record in the inbox.
            $to = DB::table('users')->whereIn('id', array_intersect($leads, $staff))->whereNull('deleted_at')->pluck('email')
                ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
            if ($to) {
                $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($line) . '</p>'
                    . '<p style="margin:0;font-size:13px;color:#475569;">Changed by the parent in KiddieTrac. The child\'s record shows the current list; the audit log keeps the before and after.</p>';
                $html = \App\Services\EmailTemplate::wrap($agencyId, $body, ['eyebrow' => 'PICK-UPS', 'title' => $title, 'preheader' => $line]);
                \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $title, $agencyId) {
                    $m->to($to[0])->subject($title);
                    if (count($to) > 1) $m->bcc(array_slice($to, 1));
                    try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId); } catch (\Throwable $e) {}
                });
            }
        } catch (\Throwable $e) {
            Log::warning('pick-up list change notify failed', ['family_id' => $familyId, 'error' => $e->getMessage()]);
        }
    }

    /** Withdraw a request the caller's family made that is still waiting. */
    public function parentCancel(Request $request, int $id): JsonResponse
    {
        $r = DB::table('pickup_login_requests')->where('id', $id)->where('status', 'pending')->first();
        abort_unless($r, 404);
        $link = DB::table('guardians')->where('family_id', $r->family_id)->where('user_id', $request->user()->id)->where('billing_hidden', 0)->exists();
        abort_unless($link, 404);
        DB::table('pickup_login_requests')->where('id', $id)->update(['status' => 'cancelled', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ── Staff ───────────────────────────────────────────────────────────────

    /** Give a contact a pick-up login (also approves a waiting request). */
    public function staffGrant(Request $request, int $ec): JsonResponse
    {
        $data = $request->validate(['email' => 'nullable|email:rfc|max:190']);
        [$contact, $agencyId] = $this->staffContact($request, $ec);
        if ($contact->user_id) return response()->json(['message' => $contact->name . ' already has a pick-up login.'], 409);

        $pending = DB::table('pickup_login_requests')->where('emergency_contact_id', $ec)->where('status', 'pending')->first();
        $email = trim((string) ($data['email'] ?? '')) ?: ($pending->email ?? '') ?: (string) ($contact->email ?? '');
        if ($email === '') return response()->json(['message' => 'An email address is needed to set up a login.'], 422);

        $by = (int) $request->user()->id;
        $r = PickupLogin::grant($agencyId, (int) $contact->family_id, $ec, $email, (string) $contact->name, $contact->relationship, $by);
        if (! $r) return response()->json(['message' => 'That email address does not look right.'], 422);

        if ($pending) {
            DB::table('pickup_login_requests')->where('id', $pending->id)->update(['status' => 'approved', 'decided_by' => $by, 'decided_at' => now(), 'updated_at' => now()]);
            $this->tellRequester($pending, 'Pick-up login set up', $contact->name . ' now has a KiddieTrac pick-up login and has been emailed how to sign in.');
        }

        $family = (string) DB::table('families')->where('id', $contact->family_id)->value('family_name');
        $emailed = $r['temp']
            ? $this->accountEmail($email, (string) $contact->name, 'Your KiddieTrac pick-up login',
                "You've been added to KiddieTrac by your childcare provider as an authorised pick-up person"
                . ($family !== '' ? " for the {$family} family" : '') . ".\n\n"
                . "Sign in at https://app.kiddietrac.com to see the children you can pick up and your check-in/out QR code:\n\n"
                . "Email: {$email}\n"
                . "Temporary password: {$r['temp']}\n\n"
                . "We recommend changing your password after you sign in, via 'Forgot password'.", $agencyId)
            : $this->accountEmail($email, (string) $contact->name, 'You can now pick up with KiddieTrac',
                "Your childcare provider has added you as an authorised pick-up person"
                . ($family !== '' ? " for the {$family} family" : '') . ".\n\n"
                . "Sign in at https://app.kiddietrac.com with your existing KiddieTrac account to see the children and your check-in/out QR code.", $agencyId);

        return response()->json(['ok' => true, 'user_id' => $r['user_id'], 'reused' => $r['reused'], 'emailed' => $emailed]);
    }

    /** Take a contact's pick-up login away. */
    public function staffRevoke(Request $request, int $ec): JsonResponse
    {
        [$contact, $agencyId] = $this->staffContact($request, $ec);
        if (! $contact->user_id) return response()->json(['ok' => true]);
        PickupLogin::revoke($agencyId, (int) $contact->family_id, $ec, (int) $request->user()->id);

        return response()->json(['ok' => true]);
    }

    /** Turn down a parent's request. */
    public function staffDecline(Request $request, int $id): JsonResponse
    {
        $r = DB::table('pickup_login_requests')->where('id', $id)->where('status', 'pending')->first();
        abort_unless($r, 404);
        [$contact, $agencyId] = $this->staffContact($request, (int) $r->emergency_contact_id);
        $by = (int) $request->user()->id;
        DB::table('pickup_login_requests')->where('id', $id)->update(['status' => 'declined', 'decided_by' => $by, 'decided_at' => now(), 'updated_at' => now()]);
        $this->audit($by, $agencyId, 'family.pickup_login_declined', (int) $r->family_id, [
            'request_id' => $id, 'emergency_contact_id' => (int) $r->emergency_contact_id,
            'summary' => 'Declined the request for a pick-up login for ' . $contact->name . '.',
        ]);
        $this->tellRequester($r, 'Pick-up login request', 'The centre did not set up a pick-up login for ' . $contact->name . '. Please speak to the centre if you have questions.');

        return response()->json(['ok' => true]);
    }

    /**
     * Send a guardian who has never signed in their login (a fresh temporary password).
     * Someone already signing in is left alone — their working password is never replaced
     * (the lesson in AdminController::resendWelcome); they can use "Forgot password".
     */
    public function staffInviteGuardian(Request $request, int $user): JsonResponse
    {
        $data = $request->validate(['family_id' => 'required|integer']);
        $fid = (int) $data['family_id'];
        $agencyId = $this->staffFamily($request, $fid);
        $g = DB::table('guardians')->where('family_id', $fid)->where('user_id', $user)->exists();
        abort_unless($g, 404);
        $u = DB::table('users')->where('id', $user)->whereNull('deleted_at')->first();
        abort_unless($u, 404);
        if (! in_array((string) $u->status, self::NEVER_CLAIMED, true)) {
            return response()->json(['message' => trim($u->first_name . ' ' . $u->last_name) . ' already signs in. If they are locked out, they can use "Forgot password" on the sign-in page.'], 409);
        }
        if (! $u->email || ! filter_var($u->email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'There is no email address on file for this guardian. Add one in Families → Edit first.'], 422);
        }

        $temp = Str::random(12);
        DB::table('users')->where('id', $user)->update(['password' => Hash::make($temp), 'status' => 'invited', 'updated_at' => now()]);
        $name = trim($u->first_name . ' ' . $u->last_name);
        $emailed = $this->accountEmail($u->email, $name, 'Welcome to KiddieTrac',
            "Welcome to KiddieTrac!\n\nYour account is ready at https://app.kiddietrac.com\n\n"
            . "Email: {$u->email}\nTemporary password: {$temp}\n\n"
            . "Sign in with it, then change it under your profile.", $agencyId);
        $this->audit((int) $request->user()->id, $agencyId, 'family.guardian_login_invited', $fid, [
            'user_id' => $user, 'summary' => 'Sent ' . $name . ' (' . $u->email . ') their KiddieTrac login.',
        ]);

        return response()->json(['ok' => true, 'emailed' => $emailed]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function familyAgency(int $familyId): ?int
    {
        $a = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)->whereNull('f.deleted_at')->value('c.agency_id');

        return $a ? (int) $a : null;
    }

    /** The family must be in the ACTIVE agency and in the caller's centres. */
    private function staffFamily(Request $request, int $familyId): int
    {
        $agencyId = $this->familyAgency($familyId);
        abort_unless($agencyId && $agencyId === (int) $this->resolveAgencyId($request), 404);
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403, 'This family is not at your centre.');
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $centre = DB::table('families')->where('id', $familyId)->value('centre_id');
        abort_unless(\App\Support\CentreScope::canWrite($scope, $centre), 403, 'This family is not at your centre.');

        return $agencyId;
    }

    private function staffContact(Request $request, int $ec): array
    {
        $contact = DB::table('emergency_contacts')->where('id', $ec)->first();
        abort_unless($contact, 404);

        return [$contact, $this->staffFamily($request, (int) $contact->family_id)];
    }

    private function accountEmail(string $to, string $name, string $subject, string $body, int $agencyId): bool
    {
        try {
            $m = new \App\Mail\AccountNotice(
                recipientName: $name ?: 'there', subjectLine: $subject, bodyText: $body,
                ctaLabel: 'Sign in to KiddieTrac', ctaUrl: config('app.url', 'https://app.kiddietrac.com'),
                agencyId: $agencyId,
            );
            $m->onQueue('mail');
            Mail::to($to, $name ?: null)->queue($m);

            return true;
        } catch (\Throwable $e) {
            Log::warning('pickup login email failed', ['to' => $to, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Bell + email to the agency's admins and the family's centre director(s). */
    private function tellStaff(int $agencyId, int $familyId, string $who, object $contact, string $email): void
    {
        try {
            $centreId = (int) DB::table('families')->where('id', $familyId)->value('centre_id');
            $family = (string) DB::table('families')->where('id', $familyId)->value('family_name');
            $staff = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', 1)->whereNull('u.deleted_at')
                ->where(function ($q) use ($agencyId, $centreId) {
                    $q->where(fn ($x) => $x->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId))
                      ->orWhere(fn ($x) => $x->where('ra.role', 'centre_director')->where('ra.centre_id', $centreId));
                })->distinct()->get(['u.id', 'u.email']);
            if ($staff->isEmpty()) return;

            $childId = DB::table('children')->where('family_id', $familyId)->whereNull('deleted_at')->orderBy('id')->value('id');
            $link = $childId ? '#child-detail?id=' . $childId : '#admin-families';
            $title = 'Pick-up login requested';
            $line = $who . ' asked for a pick-up login for ' . $contact->name
                . ($contact->relationship ? ' (' . $contact->relationship . ')' : '') . ($family !== '' ? ', ' . $family . ' family' : '') . '.';
            Notify::write($staff->map(fn ($s) => [
                'user_id' => (int) $s->id, 'type' => 'family.pickup_login_requested', 'title' => $title,
                'body' => $line . ' Approve or decline it on the child\'s record.',
                'data' => json_encode(['link' => $link, 'family_id' => $familyId, 'emergency_contact_id' => (int) $contact->id]),
                'created_at' => now(),
            ])->values()->all());

            $to = $staff->pluck('email')->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
            if (! $to) return;
            $body = '<p style="margin:0 0 14px;"><strong>' . e($who) . '</strong> asked for a KiddieTrac pick-up login for '
                . '<strong>' . e((string) $contact->name) . '</strong>'
                . ($contact->relationship ? ' (' . e((string) $contact->relationship) . ')' : '')
                . ($family !== '' ? ', an emergency contact of the ' . e($family) . ' family' : '') . '.</p>'
                . '<p style="margin:0 0 14px;">Email to send the login to: <strong>' . e($email) . '</strong></p>'
                . '<p style="margin:0 0 14px;">A pick-up login lets them check the children in and out with their own QR code and see the parent view. They never see billing.</p>'
                . '<p style="margin:0;">Open the child\'s record in KiddieTrac → <em>Family record</em> to approve or decline it.</p>';
            $html = \App\Services\EmailTemplate::wrap($agencyId, $body, ['eyebrow' => 'Families', 'title' => $title, 'preheader' => $line]);
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $title, $agencyId) {
                $m->to($to[0])->subject($title);
                if (count($to) > 1) $m->bcc(array_slice($to, 1));
                try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId); } catch (\Throwable $e) {}
            });
        } catch (\Throwable $e) {
            Log::warning('pickup login request notify failed', ['family_id' => $familyId, 'error' => $e->getMessage()]);
        }
    }

    private function tellRequester(object $req, string $title, string $body): void
    {
        Notify::write([
            'user_id' => (int) $req->requested_by, 'type' => 'family.pickup_login_decided',
            'title' => $title, 'body' => $body,
            'data' => json_encode(['link' => '#family-contacts']), 'created_at' => now(),
        ]);
    }

    private function audit(int $by, int $agencyId, string $action, int $familyId, array $payload): void
    {
        try {
            Audit::write([
                'user_id' => $by, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => 'family', 'entity_id' => $familyId,
                'payload' => json_encode($payload), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
