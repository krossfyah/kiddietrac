<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * PICK-UP LOGINS FOR EMERGENCY CONTACTS (2026-10-13, Anthony: "Emergency contacts should be
 * selected to add as a user for pick up as a guardian/parent and being able to access the QR
 * for check in/out and no financial information to be shown" — and, asked what they should
 * see: "Parent view minus billing").
 *
 * An emergency contact ticked for a login becomes a GUARDIAN of that family with:
 *   can_pickup = 1 · can_receive_billing = 0 · billing_share_pct = 0 · billing_hidden = 1
 * so they sign in like a parent (their own QR to check the children in and out, daily
 * reports, photos, messages) and see no money. "No billing" is enforced on the SERVER by
 * App\Http\Middleware\PickupOnlyNoBilling, not just hidden in the menu.
 *
 * Someone who is ALSO a real guardian somewhere keeps billing for that family: the block
 * applies only to people whose every guardian link is pick-up only (isPickupOnly).
 */
final class PickupLogin
{
    /**
     * Give the contact a login on this family. Returns ['user_id', 'temp' => password|null
     * (only for a brand-new account), 'reused' => bool], or null when the address is unusable.
     */
    public static function grant(int $agencyId, int $familyId, int $ecId, string $email, string $name, ?string $relationship, ?int $byUserId): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        // The same reuse rule as AdminController::createFamily: only an account already at
        // THIS agency, and never renamed.
        $existing = DB::table('users as u')
            ->whereRaw('LOWER(u.email) = ?', [$email])->whereNull('u.deleted_at')
            ->where(function ($q) use ($agencyId) {
                $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('role_assignments as ra')
                    ->whereColumn('ra.user_id', 'u.id')->where('ra.active', 1)->where('ra.agency_id', $agencyId))
                  ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('guardians as g')
                    ->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->whereColumn('g.user_id', 'u.id')->whereNull('f.deleted_at')->where('c.agency_id', $agencyId));
            })->first(['u.id']);

        $temp = null;
        if ($existing) {
            $uid = (int) $existing->id;
        } else {
            $parts = preg_split('/\s+/', trim($name), 2) ?: [];
            $temp = Str::random(12);
            $uid = (int) DB::table('users')->insertGetId([
                'email' => $email,
                'password' => Hash::make($temp),
                'first_name' => mb_substr($parts[0] ?? 'Pick-up', 0, 80),
                'last_name' => mb_substr($parts[1] ?? '', 0, 80),
                'locale' => 'en-CA', 'timezone' => 'America/Toronto',
                'status' => 'invited',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('role_assignments')->updateOrInsert(
            ['user_id' => $uid, 'role' => 'guardian', 'agency_id' => $agencyId, 'centre_id' => null],
            ['active' => true, 'created_at' => now()]
        );
        // A real guardian of this family already has more than a pick-up login: leave them be.
        $g = DB::table('guardians')->where('family_id', $familyId)->where('user_id', $uid)->first();
        if (! $g) {
            DB::table('guardians')->insert([
                'family_id' => $familyId, 'user_id' => $uid,
                'relationship' => mb_substr(mb_strtolower((string) ($relationship ?: 'other')), 0, 40),
                'is_primary' => 0, 'can_pickup' => 1, 'can_receive_billing' => 0,
                'billing_share_pct' => 0, 'billing_hidden' => 1, 'created_at' => now(),
            ]);
        }
        DB::table('emergency_contacts')->where('id', $ecId)->update(['user_id' => $uid, 'email' => $email, 'can_pickup' => 1, 'updated_at' => now()]);

        try {
            Audit::write([
                'user_id' => $byUserId, 'agency_id' => $agencyId,
                'action' => 'family.pickup_login_granted', 'entity_type' => 'family', 'entity_id' => $familyId,
                'payload' => json_encode(['emergency_contact_id' => $ecId, 'user_id' => $uid, 'email' => $email,
                    'summary' => 'Gave emergency contact ' . $name . ' (' . $email . ') a pick-up login: check-in/out and the parent view, no billing.']),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return ['user_id' => $uid, 'temp' => $temp, 'reused' => (bool) $existing];
    }

    /** Take the pick-up login away again (the account itself stays; it may be used elsewhere). */
    public static function revoke(int $agencyId, int $familyId, int $ecId, ?int $byUserId): void
    {
        $ec = DB::table('emergency_contacts')->where('id', $ecId)->first();
        if (! $ec || ! $ec->user_id) return;
        DB::table('guardians')->where('family_id', $familyId)->where('user_id', $ec->user_id)->where('billing_hidden', 1)->delete();
        if (! DB::table('guardians')->where('user_id', $ec->user_id)->exists()) {
            DB::table('role_assignments')->where('user_id', $ec->user_id)->where('agency_id', $agencyId)->where('role', 'guardian')->update(['active' => 0]);
        }
        DB::table('emergency_contacts')->where('id', $ecId)->update(['user_id' => null, 'updated_at' => now()]);
        try {
            Audit::write([
                'user_id' => $byUserId, 'agency_id' => $agencyId,
                'action' => 'family.pickup_login_removed', 'entity_type' => 'family', 'entity_id' => $familyId,
                'payload' => json_encode(['emergency_contact_id' => $ecId, 'user_id' => (int) $ec->user_id,
                    'summary' => 'Removed the pick-up login of emergency contact ' . $ec->name . '.']),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** Every guardian link this person has is pick-up only, and they hold no other role. */
    public static function isPickupOnly(int $userId): bool
    {
        static $memo = [];
        if (array_key_exists($userId, $memo)) return $memo[$userId];
        $links = DB::table('guardians')->where('user_id', $userId)->get(['billing_hidden']);
        if ($links->isEmpty() || $links->contains(fn ($l) => (int) ($l->billing_hidden ?? 0) === 0)) return $memo[$userId] = false;
        $other = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->where('role', '!=', 'guardian')->exists();

        return $memo[$userId] = ! $other;
    }
}
