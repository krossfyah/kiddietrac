<?php

namespace App\Support;

use App\Services\FcmService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TEMPORARY PICKUP PEOPLE (2026-11-24, Anthony: "is there a way for a parent to add a temp pick up person that
 * generates a temp account to pick up their child for a period of time or one time use?").
 *
 * His choices:
 *  - BOTH kinds, the parent chooses:
 *      pass   - the person is texted a pickup code + a link to their pass (QR). Staff check the code at sign-out.
 *      signin - a temporary pickup sign-in: the same personal link, which also lets them sign the child OUT
 *               themselves with One-Tap (the centre's code at the door). Nothing else: no photos, reports,
 *               messages or billing, because it is not a portal account at all, just this one pass.
 *  - Active at once; the centre director and the child's room educators are told; staff can cancel it.
 *  - One pickup (one day, done after that sign-out) or a date range of up to 30 days.
 *  - At the door: the code must match; staff see the photo the parent added; the parent is told the moment the
 *    child is signed out by that person; and "if someone tries to sign out child and its not the right person
 *    everyone should be alerted immediately (high alert)": a wrong, expired, used or cancelled code, or staff
 *    pressing "Not the right person", rings the child's guardians, room educators, directors and admins like a
 *    🚨 emergency (EmergencyMessage::pushExtra), plus a bell row and a text to the guardians.
 *
 * Codes and links are stored hashed (the code also encrypted, so the holder's own pass page can show it). The
 * photo is private (local disk, served only to the family and the centre's staff). Test agencies send nothing.
 * A staff sign-out takes the pass through CheckEventController::checkOut: verify() leaves a 3-minute ticket for
 * that child and staff member, which the check-out consumes (so every sign-out screen works unchanged).
 */
final class TempPickups
{
    public const MAX_DAYS = 30;
    public const LINE_AGENCY = 2;   // texts go from the KiddieTrac toll-free line
    public const PASS_URL = 'https://app.kiddietrac.com/pickup-pass.html';

    public static function codeHash(int $id, string $code): string
    {
        return hash_hmac('sha256', $id . '|' . $code, (string) config('app.key'));
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', 'ktpass|' . $token);
    }

    public static function childIds(object $p): array
    {
        return array_values(array_map('intval', json_decode((string) $p->child_ids, true) ?: []));
    }

    /** Which children on this pass were already picked up with it (today, for a one-time pass: ever). */
    public static function usedChildIds(object $p): array
    {
        return DB::table('temp_pickup_uses')->where('temp_pickup_id', $p->id)->pluck('child_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
    }

    /** 'active' | 'upcoming' | 'expired' | 'used' | 'cancelled' | 'locked', as of now in the agency's day. */
    public static function state(object $p): string
    {
        if (in_array($p->status, ['cancelled', 'used', 'locked'], true)) return $p->status;
        $today = AgencyTime::today((int) $p->agency_id);
        if ((string) $p->starts_on > $today) return 'upcoming';
        if ((string) $p->ends_on < $today) return 'expired';

        return 'active';
    }

    /** Can this pass take this child out right now? [ok, reason] */
    public static function usable(object $p, int $childId): array
    {
        $st = self::state($p);
        if ($st !== 'active') return [false, $st];
        if (! in_array($childId, self::childIds($p), true)) return [false, 'not_this_child'];
        if ($p->one_time && in_array($childId, self::usedChildIds($p), true)) return [false, 'used'];

        return [true, 'ok'];
    }

    public static function childNames(array $ids): array
    {
        if (! $ids) return [];

        return DB::table('children')->whereIn('id', $ids)->orderBy('first_name')->get(['id', 'first_name', 'preferred_name'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => trim((string) ($c->preferred_name ?: $c->first_name))])->all();
    }

    public static function window(object $p): string
    {
        $a = Carbon::parse($p->starts_on);
        $b = Carbon::parse($p->ends_on);
        if ($p->one_time || $a->equalTo($b)) return ($p->one_time ? 'One pickup on ' : '') . $a->format('D, M j');

        return $a->format('D, M j') . ' to ' . $b->format('D, M j');
    }

    /* ── creating one ─────────────────────────────────────────────────────── */

    /** Insert the pass; returns [row, code, token]. */
    public static function create(array $d): array
    {
        $token = Str::random(40);
        $id = DB::table('temp_pickups')->insertGetId([
            'agency_id' => $d['agency_id'], 'centre_id' => $d['centre_id'], 'family_id' => $d['family_id'],
            'child_ids' => json_encode(array_values(array_map('intval', $d['child_ids']))), 'created_by_user_id' => $d['user_id'],
            'full_name' => $d['full_name'], 'relationship' => $d['relationship'] ?: null, 'phone' => $d['phone'],
            'photo_path' => $d['photo_path'], 'mode' => $d['mode'], 'one_time' => $d['one_time'] ? 1 : 0,
            'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'], 'note' => $d['note'] ?: null,
            'code_hash' => '', 'code_enc' => '', 'token_hash' => self::tokenHash($token), 'status' => 'active',
            'uses' => 0, 'fails' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $code = self::newCode($id, $d['child_ids']);

        return [DB::table('temp_pickups')->where('id', $id)->first(), $code, $token];
    }

    /** A fresh 6-digit code, never the same as another live pass for these children. */
    public static function newCode(int $id, array $childIds): string
    {
        $live = DB::table('temp_pickups')->where('id', '<>', $id)->where('status', 'active')
            ->where('ends_on', '>=', now()->subDay()->toDateString())->get(['id', 'code_hash', 'child_ids'])
            ->filter(fn ($p) => array_intersect(array_map('intval', json_decode((string) $p->child_ids, true) ?: []), $childIds));
        for ($i = 0; $i < 30; $i++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (preg_match('/^(\d)\1{5}$/', $code) || str_contains('0123456789', $code)) continue;
            if (! $live->contains(fn ($p) => hash_equals((string) $p->code_hash, self::codeHash((int) $p->id, $code)))) break;
        }
        DB::table('temp_pickups')->where('id', $id)->update(['code_hash' => self::codeHash($id, $code), 'code_enc' => Crypt::encryptString($code), 'updated_at' => now()]);

        return $code;
    }

    /** A new link (the old one stops working), e.g. for "Text it again". */
    public static function newToken(int $id): string
    {
        $token = Str::random(40);
        DB::table('temp_pickups')->where('id', $id)->update(['token_hash' => self::tokenHash($token), 'updated_at' => now()]);

        return $token;
    }

    /** Text the pickup person their code and pass link. 'sent' | 'held' | 'failed' | reason. */
    public static function textHolder(object $p, string $code, string $token): string
    {
        $agencyId = (int) $p->agency_id;
        if (TestAgency::blocks($agencyId)) { TestAgency::note('text', ['agency' => $agencyId, 'what' => 'temp pickup pass']); return 'held (test agency)'; }
        if (Suppression::isAgency($agencyId)) return 'held (notifications off)';
        $to = Telnyx::e164((string) $p->phone);
        if (! preg_match('/^\+1[2-9]\d{2}[2-9]\d{6}$/', $to)) return 'not a mobile number';
        $kw = DB::table('sms_messages')->where('to_phone', $to)->whereIn('category', ['inbound_stop', 'inbound_start'])->orderByDesc('id')->value('category');
        if ($kw === 'inbound_stop') return 'opted out';
        $parent = (string) (strtok(trim((string) DB::table('users')->where('id', $p->created_by_user_id)->value('first_name')), ' ') ?: 'A parent');
        $kids = implode(' and ', self::childNames(self::childIds($p)));
        $body = 'KiddieTrac: ' . $parent . ' added you to pick up ' . $kids . ', ' . self::window($p) . '. Code ' . $code . '. '
            . ($p->mode === 'signin' ? 'Sign out with One-Tap or show staff: ' : 'Show staff at pickup: ')
            . self::PASS_URL . '#t=' . $token . ' Bring photo ID.' . self::signOff($p);
        $body = Brand::rename($body, $agencyId);
        $r = SmsGateway::deliver(self::LINE_AGENCY, $to, $body);
        DB::table('sms_messages')->insert(['agency_id' => self::LINE_AGENCY, 'direction' => 'out', 'to_user_id' => null, 'to_phone' => $to,
            'body' => str_replace([$code, $token], ['••••••', '…'], $body), 'category' => 'temp_pickup',
            'provider' => $r['provider'] ?? null, 'provider_ref' => $r['ref'] ?? null, 'status' => ! empty($r['ok']) ? 'sent' : 'failed',
            'error' => $r['error'] ?? null, 'sent_at' => now(), 'created_at' => now()]);

        return ! empty($r['ok']) ? 'sent' : 'failed';
    }

    /** The children's room educators and their centre's directors (not the whole agency's admins). */
    public static function staffFor(object $p, bool $withAdmins = false): array
    {
        $agencyId = (int) $p->agency_id;
        $cfg = array_merge(SafeArrival::settings($agencyId), ['notify_directors' => true, 'notify_admins' => $withAdmins]);
        $ids = [];
        foreach (self::childIds($p) as $cid) {
            $rooms = DB::table('enrollments')->where('child_id', $cid)->whereNull('end_date')->pluck('room_id')->filter()->all();
            try { if ($t = CareSchedule::roomToday($cid, AgencyTime::tz($agencyId))) $rooms[] = $t; } catch (\Throwable $e) {}
            $rooms = array_values(array_unique(array_map('intval', $rooms))) ?: [null];
            foreach ($rooms as $rid) {
                $centre = $rid ? (int) (DB::table('rooms')->where('id', $rid)->value('centre_id') ?: $p->centre_id) : (int) $p->centre_id;
                if ((int) DB::table('centres')->where('id', $centre)->value('agency_id') !== $agencyId) continue;   // never across a tenant
                $ids = array_merge($ids, SafeArrival::recipients($agencyId, $centre, $rid, $cfg, true, true, $withAdmins));
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public static function guardianIds(int $familyId): array
    {
        return DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $familyId)
            ->whereNull('u.deleted_at')->pluck('u.id')->map(fn ($x) => (int) $x)->unique()->values()->all();
    }

    /** "Maya added Jane (neighbour) to pick up Ava on Fri, Nov 28": bell + push to the room and the directors. */
    public static function tellStaffCreated(object $p): int
    {
        $agencyId = (int) $p->agency_id;
        if (TestAgency::blocks($agencyId)) return 0;
        $kids = implode(' and ', self::childNames(self::childIds($p)));
        $parent = (string) (strtok(trim((string) DB::table('users')->where('id', $p->created_by_user_id)->value('first_name')), ' ') ?: 'A parent');
        $rows = [];
        foreach (self::staffFor($p) as $uid) {
            $rows[] = ['user_id' => $uid, 'agency_id' => $agencyId, 'type' => 'checkin', 'title' => '🎫 Temporary pickup: ' . $kids,
                'body' => $parent . ' added ' . $p->full_name . ($p->relationship ? ' (' . $p->relationship . ')' : '') . ' to pick up ' . $kids . ': ' . self::window($p)
                    . '. They have a pickup code to show you, and their photo is on the sign-out screen.',
                'data' => json_encode(['link' => '#safe-arrival', 'temp_pickup' => (int) $p->id]), 'created_at' => now()];
        }
        if ($rows) Notify::write($rows);

        return count($rows);
    }

    /* ── at the door ──────────────────────────────────────────────────────── */

    /** The passes that could collect this child today (active, not yet used for this child). */
    public static function todayFor(int $childId): array
    {
        $agencyId = Notify::agencyOfChild($childId);
        if (! $agencyId) return [];
        $today = AgencyTime::today($agencyId);
        $rows = DB::table('temp_pickups')->where('agency_id', $agencyId)->where('status', 'active')
            ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->whereRaw('JSON_CONTAINS(child_ids, ?)', [(string) $childId])->get();

        return $rows->filter(fn ($p) => self::usable($p, $childId)[0])->values()->all();
    }

    /**
     * Staff typed a code for this child. Any pass of this family's with that code is identified; a miss, or a
     * pass that is not valid now, raises the high alert. ['ok' => bool, 'pass' => row|null, 'message' => text].
     */
    public static function verify(int $staffId, int $childId, string $code, ?int $passId = null): array
    {
        $agencyId = (int) Notify::agencyOfChild($childId);
        $first = self::childNames([$childId])[$childId] ?? 'this child';
        $all = DB::table('temp_pickups')->where('agency_id', $agencyId)->whereRaw('JSON_CONTAINS(child_ids, ?)', [(string) $childId])
            ->where('created_at', '>=', now()->subDays(120))->orderByDesc('id')->get();
        $hit = null;
        foreach ($all as $p) if (preg_match('/^\d{6}$/', $code) && hash_equals((string) $p->code_hash, self::codeHash((int) $p->id, $code))) { $hit = $p; break; }
        if ($hit) {
            [$ok, $why] = self::usable($hit, $childId);
            if ($ok) {
                Cache::put('kt-tp-ok:' . $childId . ':' . $staffId, (int) $hit->id, now()->addMinutes(3));
                DB::table('temp_pickups')->where('id', $hit->id)->update(['fails' => 0, 'updated_at' => now()]);
                self::audit($agencyId, $staffId, 'temp_pickup.verified', $childId, 'Pickup code matched ' . $hit->full_name . '\'s pass for ' . $first);

                return ['ok' => true, 'pass' => $hit, 'message' => 'Code matches. ' . $hit->full_name . ' can take ' . $first . '. Check them out now.'];
            }
            $what = ['expired' => 'has expired', 'upcoming' => 'isn\'t valid until ' . Carbon::parse($hit->starts_on)->format('D, M j'),
                'used' => 'was already used', 'cancelled' => 'was cancelled', 'locked' => 'was locked after wrong codes'][$why] ?? 'isn\'t valid now';
            $n = self::alert($childId, $staffId, $hit->full_name . '\'s pickup pass ' . $what, $hit);

            return ['ok' => false, 'pass' => $hit, 'alerted' => $n, 'message' => 'Don\'t release ' . $first . '. That pass ' . $what . '. Everyone has been alerted.'];
        }
        // No pass has this code. Count it against the pass staff were checking, if any; three misses lock it.
        $target = $passId ? $all->firstWhere('id', $passId) : null;
        if ($target && $target->status === 'active') {
            $fails = (int) $target->fails + 1;
            DB::table('temp_pickups')->where('id', $target->id)->update(['fails' => $fails, 'status' => $fails >= 3 ? 'locked' : 'active', 'updated_at' => now()]);
        }
        $n = self::alert($childId, $staffId, 'a pickup code that didn\'t match' . ($target ? ' (' . $target->full_name . '\'s pass)' : ''), $target);

        return ['ok' => false, 'pass' => null, 'alerted' => $n, 'message' => 'Don\'t release ' . $first . '. That code doesn\'t match. Everyone has been alerted.'];
    }

    /**
     * One-Tap "Scan a pickup pass" (2026-11-24): a code with NO child picked yet. Matched against the passes of the
     * children this staff member looks after ($scope = Visibility::liveChildIds), since code_hash is per pass id.
     * ok => the pass and the children it can still take today. A pass that isn't valid now => refused + the high
     * alert for its children. No match => a friendly refusal, audited (no child to alert); 5 misses in 10 minutes
     * pause lookups for this staff member (the route is throttled too).
     */
    public static function lookup(int $staffId, int $agencyId, array $scope, string $code): array
    {
        $code = preg_replace('/\D/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) return ['ok' => false, 'kind' => 'format', 'message' => 'A pickup code is 6 digits.'];
        $missKey = 'kt-tp-lookmiss:' . $staffId;
        if ((int) Cache::get($missKey, 0) >= 5) {
            return ['ok' => false, 'kind' => 'paused', 'message' => 'Too many codes that didn\'t match. Wait 10 minutes, or check the pass with the director.'];
        }
        $scope = array_map('intval', $scope);
        $hit = null;
        if ($scope) {
            $rows = DB::table('temp_pickups')->where('agency_id', $agencyId)->where('created_at', '>=', now()->subDays(120))->orderByDesc('id')->get();
            foreach ($rows as $p) {
                if (! array_intersect(self::childIds($p), $scope)) continue;
                if (hash_equals((string) $p->code_hash, self::codeHash((int) $p->id, $code))) { $hit = $p; break; }
            }
        }
        if (! $hit) {
            Cache::put($missKey, (int) Cache::get($missKey, 0) + 1, now()->addMinutes(10));
            self::audit($agencyId, $staffId, 'temp_pickup.lookup_miss', null, 'A pickup code scanned or typed at One-Tap matched no pass for this staff member\'s children');

            return ['ok' => false, 'kind' => 'none', 'message' => 'Not a valid pass for your centre. Don\'t release a child: check with the family or the director.'];
        }
        $kids = array_values(array_intersect(self::childIds($hit), $scope));
        $usable = array_values(array_filter($kids, fn ($c) => self::usable($hit, $c)[0]));
        $names = implode(' and ', self::childNames($kids));
        if (! $usable) {
            $st = self::state($hit);
            $what = ['expired' => 'has expired', 'upcoming' => 'isn\'t valid until ' . Carbon::parse($hit->starts_on)->format('D, M j'),
                'cancelled' => 'was cancelled', 'locked' => 'was locked after wrong codes'][$st] ?? 'was already used';
            $n = 0;
            foreach ($kids as $cid) $n += self::alert($cid, $staffId, $hit->full_name . '\'s pickup pass ' . $what . ' (scanned at One-Tap)', $hit);

            return ['ok' => false, 'kind' => 'invalid', 'pass' => $hit, 'alerted' => $n,
                'message' => 'Don\'t release ' . $names . '. That pass ' . $what . '. Everyone has been alerted.'];
        }
        Cache::forget($missKey);
        self::audit($agencyId, $staffId, 'temp_pickup.looked_up', $usable[0], 'Pickup code at One-Tap matched ' . $hit->full_name . '\'s pass for ' . $names);

        return ['ok' => true, 'pass' => $hit, 'children' => $usable];
    }

    /** The check-out that follows a verify() by the same staff member within 3 minutes. */
    public static function takeVerified(int $childId, int $staffId): ?object
    {
        $id = Cache::pull('kt-tp-ok:' . $childId . ':' . $staffId);
        if (! $id) return null;
        $p = DB::table('temp_pickups')->where('id', $id)->first();

        return $p && self::usable($p, $childId)[0] ? $p : null;
    }

    /** A sign-out happened with this pass: record it, close a finished one-time pass, tell the parents. */
    public static function recordUse(object $p, int $childId, int $eventId, ?int $byUserId, string $method): void
    {
        DB::table('temp_pickup_uses')->insert(['temp_pickup_id' => $p->id, 'child_id' => $childId, 'check_event_id' => $eventId,
            'by_user_id' => $byUserId, 'method' => $method, 'created_at' => now()]);
        $upd = ['uses' => DB::raw('uses + 1'), 'last_used_at' => now(), 'updated_at' => now()];
        if ($p->one_time && ! array_diff(self::childIds($p), self::usedChildIds($p))) $upd['status'] = 'used';
        DB::table('temp_pickups')->where('id', $p->id)->update($upd);
        $first = self::childNames([$childId])[$childId] ?? 'Your child';
        $tz = AgencyTime::tz((int) $p->agency_id);
        $at = now($tz)->format('g:i A');
        self::audit((int) $p->agency_id, $byUserId, 'temp_pickup.used', $childId, $first . ' signed out at ' . $at . ' by ' . $p->full_name
            . ' (temporary pickup ' . ($method === 'self_signout' ? 'sign-in, One-Tap' : 'pass, code checked by staff') . ')');
        if (TestAgency::blocks((int) $p->agency_id)) return;
        $title = '🎫 ' . $first . ' was picked up';
        $body = $first . ' was signed out at ' . $at . ' by ' . $p->full_name . ' (your temporary pickup person).';
        $rows = array_map(fn ($uid) => ['user_id' => $uid, 'agency_id' => (int) $p->agency_id, 'type' => 'checkin', 'title' => $title, 'body' => $body,
            'data' => json_encode(['link' => '#family-contacts', 'child_id' => $childId]), 'created_at' => now()], self::guardianIds((int) $p->family_id));
        if ($rows) Notify::write($rows);
        try {
            $ph = DB::table('users')->where('id', $p->created_by_user_id)->value('phone');
            if ($ph && ! Suppression::isAgency((int) $p->agency_id)) self::textTo($ph, 'KiddieTrac: ' . $body . self::signOff($p), (int) $p->agency_id, 'temp_pickup_used');
        } catch (\Throwable $e) {
        }
    }

    /**
     * Every temporary-pickup text ends with who it is from: " – Sunny Meadows (iLearn Childcare)" (Anthony, 2026-11-24:
     * "Centre + agency"). A plain hyphen, not a dash: an en dash makes the whole text Unicode, 70 characters a part instead of 160. One name when they are the same; Brand::rename still runs over the whole text afterwards.
     */
    public static function signOff(object $p): string
    {
        // A home named after its provider is "Amna's home child care", never her full name (FamilyFacingName, 2026-11-27).
        $centre = trim(FamilyFacingName::place((int) ($p->centre_id ?? 0) ?: null, ''));
        $agency = FamilyFacingName::agency((int) $p->agency_id);
        if ($centre === '' && $agency === '') return '';
        if ($centre === '' || strcasecmp($centre, $agency) === 0) return ' - ' . ($centre ?: $agency);

        return ' - ' . $centre . ($agency !== '' ? ' (' . $agency . ')' : '');
    }

    /** A pass was cancelled: the room and director hear it (bell), and the family too when staff cancelled it. */
    public static function tellCancelled(object $p, int $byUserId, string $who): int
    {
        if (TestAgency::blocks((int) $p->agency_id)) return 0;
        $kids = implode(' and ', self::childNames(self::childIds($p)));
        $by = (string) (strtok(trim((string) DB::table('users')->where('id', $byUserId)->value('first_name')), ' ') ?: '');
        $title = '🎫 Temporary pickup cancelled';
        $body = $p->full_name . ' can no longer pick up ' . $kids . ' (' . self::window($p) . ')' . ($by ? ', cancelled by ' . $by : '') . '. Their code no longer works.';
        $staff = self::staffFor($p);
        $users = $who === 'staff' ? array_values(array_unique(array_merge($staff, self::guardianIds((int) $p->family_id)))) : $staff;
        $users = array_values(array_diff($users, [$byUserId]));
        $rows = array_map(fn ($uid) => ['user_id' => $uid, 'agency_id' => (int) $p->agency_id, 'type' => 'checkin', 'title' => $title, 'body' => $body,
            'data' => json_encode(['link' => in_array($uid, $staff, true) ? '#safe-arrival' : '#family-contacts', 'temp_pickup' => (int) $p->id]), 'created_at' => now()], $users);
        if ($rows) Notify::write($rows);

        return count($rows);
    }

    /** The holder's photo as a data: URI, for the printed emergency card (the file is never web-reachable). */
    public static function photoDataUri(object $p): ?string
    {
        try {
            if (! $p->photo_path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($p->photo_path)) return null;

            return 'data:image/jpeg;base64,' . base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($p->photo_path));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function textTo(string $phone, string $body, int $agencyId, string $category): string
    {
        $to = Telnyx::e164($phone);
        if (! preg_match('/^\+1[2-9]\d{2}[2-9]\d{6}$/', $to)) return 'no number';
        $kw = DB::table('sms_messages')->where('to_phone', $to)->whereIn('category', ['inbound_stop', 'inbound_start'])->orderByDesc('id')->value('category');
        if ($kw === 'inbound_stop') return 'opted out';
        $body = Brand::rename($body, $agencyId);
        $r = SmsGateway::deliver(self::LINE_AGENCY, $to, $body);
        DB::table('sms_messages')->insert(['agency_id' => self::LINE_AGENCY, 'direction' => 'out', 'to_user_id' => null, 'to_phone' => $to, 'body' => $body,
            'category' => $category, 'provider' => $r['provider'] ?? null, 'provider_ref' => $r['ref'] ?? null,
            'status' => ! empty($r['ok']) ? 'sent' : 'failed', 'error' => $r['error'] ?? null, 'sent_at' => now(), 'created_at' => now()]);

        return ! empty($r['ok']) ? 'sent' : 'failed';
    }

    /**
     * HIGH ALERT: someone tried to take this child and it isn't the right person (or the right pass). Rings like a
     * 🚨 emergency for the child's guardians, the room's educators, the centre's directors and the agency's admins,
     * with a bell row each and a text to the guardians. Once per child per 5 minutes (each attempt is still audited).
     * Returns how many people were alerted (0 for a test agency: nothing is sent there).
     */
    public static function alert(int $childId, ?int $byUserId, string $what, ?object $p = null, ?string $where = null): int
    {
        $agencyId = (int) Notify::agencyOfChild($childId);
        $first = self::childNames([$childId])[$childId] ?? 'a child';
        $familyId = (int) DB::table('children')->where('id', $childId)->value('family_id');
        $by = $byUserId ? (string) (strtok(trim((string) DB::table('users')->where('id', $byUserId)->value('first_name')), ' ') ?: '') : '';
        self::audit($agencyId, $byUserId, 'temp_pickup.alert', $childId, 'PICKUP ALERT for ' . $first . ': ' . $what . ($where ? ' (' . $where . ')' : ''));
        if (TestAgency::blocks($agencyId)) return 0;
        if (! Cache::add('kt-tp-alert:' . $childId, 1, now()->addMinutes(5))) return 0;
        $pp = $p ?: (object) ['agency_id' => $agencyId, 'centre_id' => (int) DB::table('families')->where('id', $familyId)->value('centre_id'), 'child_ids' => json_encode([$childId])];
        $staff = self::staffFor($pp, true);
        $guardians = self::guardianIds($familyId);
        $title = '🚨 PICKUP ALERT · ' . $first;
        $staffBody = 'Someone tried to sign ' . $first . ' out with ' . $what . ($by ? ', reported by ' . $by : '') . '. ' . $first . ' has NOT been released. Check the door now.';
        $famBody = 'Someone tried to pick up ' . $first . ' with ' . $what . '. ' . $first . ' has NOT been released and the centre has been alerted. Call the centre now.';
        $fcm = app(FcmService::class);
        $n = 0;
        foreach (array_values(array_unique(array_merge($staff, $guardians))) as $uid) {
            $fam = in_array($uid, $guardians, true) && ! in_array($uid, $staff, true);
            $body = $fam ? $famBody : $staffBody;
            $link = $fam ? '#dashboard' : '#safe-arrival';
            try {
                Notify::write(['user_id' => $uid, 'agency_id' => $agencyId, 'type' => 'safe_arrival', 'title' => $title, 'body' => $body, 'push' => false,
                    'data' => json_encode(['link' => $link, 'child_id' => $childId, 'pickup_alert' => 1]), 'created_at' => now()]);
                $fcm->sendToUser($uid, $title, $body, $link, true, true, true, EmergencyMessage::pushExtra());
                $n++;
            } catch (\Throwable $e) {
                Log::warning('Pickup alert push failed', ['child' => $childId, 'user' => $uid, 'error' => $e->getMessage()]);
            }
        }
        if (! Suppression::isAgency($agencyId)) {
            foreach (DB::table('users')->whereIn('id', $guardians ?: [0])->whereNotNull('phone')->pluck('phone') as $ph) {
                try { self::textTo((string) $ph, 'KiddieTrac PICKUP ALERT: ' . $famBody . self::signOff($pp), $agencyId, 'temp_pickup_alert'); } catch (\Throwable $e) {}
            }
        }

        return $n;
    }

    /* ── the holder's own pass page ───────────────────────────────────────── */

    public static function byToken(string $token): ?object
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) return null;

        return DB::table('temp_pickups')->where('token_hash', self::tokenHash($token))->first();
    }

    public static function audit(?int $agencyId, ?int $userId, string $action, ?int $childId, string $summary): void
    {
        try {
            Audit::write(['user_id' => $userId, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => $childId ? 'child' : 'temp_pickup',
                'entity_id' => $childId, 'payload' => json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }
}
