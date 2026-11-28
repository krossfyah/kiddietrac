<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * 📻 WALKIE-TALKIE (2026-11-27). Anthony: "add walkie-talkie capabilities to quickly reach out to educators or
 * admins/directors - can this be wired up for free?", "we need to have it where the app at all times is listening for
 * live push to talk". Live, half-duplex push-to-talk between an agency's OWN staff. Parents are never in it.
 *
 * FREE, NO RELAY. The speaker's page records 16 kHz mono G.711 mu-law (8-bit, 16 KB/s) and posts it to us in
 * ~400 ms pieces (POST walkie/talk/{id}/chunk). Each piece is (1) sealed into walkie_chunks with KT_DATA_KEY, the
 * same at-rest encryption as chat, and (2) written AES-256-GCM-encrypted under a fresh per-transmission key to
 * parent-portal/wt/<random 32 hex>/<seq>.bin, which nginx hands out as a plain static file. Listeners get the key
 * once from an authenticated call (walkie/inbox, or walkie/device/wake for the phone listener) and then read the
 * pieces as static files - so twenty phones hearing one speaker cost nginx file reads, not twenty PHP workers. The
 * web root never holds playable audio: ciphertext only, keys only in the database (sealed), the folder deleted a
 * couple of minutes after the talk ends (kt:walkie-sweep). This server cannot hold WebSockets (Cloudways nginx is
 * root-managed, no supervisor), which is why it is pieces + static files rather than a socket.
 *
 * WAKING A LISTENER. Each person has a "doorbell": parent-portal/wt/b/<HMAC of their id>.txt, a number that changes
 * when something is said to them. An open portal tab reads it once a second (static, ~0 CPU). Phones are woken by a
 * high-priority DATA-ONLY FCM message (WalkiePush) to the app's native listener (Android 3.13+), which plays it out
 * loud with the screen off. Other phones get a normal "Walkie-talkie from Amna" notification to tap.
 *
 * WHO (one rule, reach()/channels()): always the ACTIVE agency's own staff (HrLeave::staffIds - no super admins,
 * integration or reseller accounts, no deactivated accounts; HR leavers dropped). Admins reach the whole agency;
 * directors and educators the staff of their own centres (Visibility) plus their leaders (CentreScope::managers).
 *
 * THE ONE EXCEPTION - A SUPER ADMIN AS A GUEST (2026-11-27, Anthony: "Give me full access"). A platform admin is not
 * agency staff (AgencyOutsiders) and is left out of every other agency notice. For the walkie ONLY, a platform admin
 * joins the agency he has SWITCHED INTO (X-Active-Agency-Id): opening the walkie there (any walkie call, ctx())
 * records walkie_prefs.guest_agency_id, and from then on he is one of that agency's walkie people - he reaches the
 * whole agency like an agency admin, appears in its people list ("Super admin"), is in its All staff / Emergency /
 * leaders channels, and its staff can call him. ONE agency at a time: switching agency moves him; he is never in
 * the walkie of an agency he is not active in. guests() is the only door; integration and reseller accounts never.
 *
 * Content is never in logs, emails, pushes or the audit log: those say who talked to whom, when and for how long.
 */
final class Walkie
{
    public const STAFF = ['agency_admin', 'centre_director', 'educator', 'home_visitor'];
    public const SAMPLE_RATE = 16000;
    public const MAX_SECS = 60;          // one press is cut off after a minute
    public const FLOOR_SECS = 6;          // a speaker whose pieces stop arriving loses the floor after this
    public const LIVE_KEEP_SECS = 150;    // live (ciphertext) files are deleted this long after the talk ends

    /** agencies.settings.walkie, with Anthony's defaults (2026-11-27): on, 24/7 listening, 30 days, emergency on. */
    public static function settings(int $aid): array
    {
        $raw = DB::table('agencies')->where('id', $aid)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $w = is_array($s['walkie'] ?? null) ? $s['walkie'] : [];

        return [
            'enabled' => array_key_exists('enabled', $w) ? (bool) $w['enabled'] : true,
            'listen' => in_array($w['listen'] ?? '', ['always', 'on_shift'], true) ? $w['listen'] : 'always',
            'retention_days' => max(1, min(365, (int) ($w['retention_days'] ?? 30))),
            'emergency' => array_key_exists('emergency', $w) ? (bool) $w['emergency'] : true,
            'emergency_name' => mb_substr(trim((string) ($w['emergency_name'] ?? '')), 0, 40) ?: 'Emergency',
            'emergency_talkers' => in_array($w['emergency_talkers'] ?? '', ['admins', 'leaders'], true) ? $w['emergency_talkers'] : 'leaders',
            'educators_agency' => (bool) ($w['educators_agency'] ?? false),   // educators may also reach staff at other centres
        ];
    }

    public static function saveSettings(int $aid, array $in): array
    {
        $cur = self::settings($aid);
        $new = [
            'enabled' => array_key_exists('enabled', $in) ? (bool) $in['enabled'] : $cur['enabled'],
            'listen' => in_array($in['listen'] ?? '', ['always', 'on_shift'], true) ? $in['listen'] : $cur['listen'],
            'retention_days' => isset($in['retention_days']) ? max(1, min(365, (int) $in['retention_days'])) : $cur['retention_days'],
            'emergency' => array_key_exists('emergency', $in) ? (bool) $in['emergency'] : $cur['emergency'],
            'emergency_name' => isset($in['emergency_name']) ? (mb_substr(trim((string) $in['emergency_name']), 0, 40) ?: 'Emergency') : $cur['emergency_name'],
            'emergency_talkers' => in_array($in['emergency_talkers'] ?? '', ['admins', 'leaders'], true) ? $in['emergency_talkers'] : $cur['emergency_talkers'],
            'educators_agency' => array_key_exists('educators_agency', $in) ? (bool) $in['educators_agency'] : $cur['educators_agency'],
        ];
        DB::transaction(function () use ($aid, $new) {
            $raw = DB::table('agencies')->where('id', $aid)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['walkie'] = $new;
            DB::table('agencies')->where('id', $aid)->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return $new;
    }

    /**
     * The agency's own staff, leavers dropped - plus, agency-wide only, a super admin who is a guest here (guests()).
     * A centre-scoped list leaves the guest out, the same as an agency admin (an agency-level role, no centre).
     * @return int[]
     */
    public static function staff(int $aid, ?array $centres = null): array
    {
        $ids = HrLeave::staffIds($aid, $centres);
        $gone = rescue(fn () => HrLeaving::leaverIds($aid), [], false);
        $ids = array_values(array_diff($ids, $gone));
        if ($centres === null) $ids = array_values(array_unique(array_merge($ids, self::guests($aid))));

        return $ids;
    }

    /**
     * SUPER ADMIN GUESTS (2026-11-27, see the class note): platform admins whose ACTIVE agency for the walkie is this
     * one. The walkie-only exception to AgencyOutsiders - nothing else reads this. @return int[]
     */
    public static function guests(int $aid): array
    {
        if ($aid <= 0) return [];

        return rescue(fn () => DB::table('walkie_prefs as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.guest_agency_id', $aid)->whereNull('u.deleted_at')->whereNotIn('u.status', ['deactivated'])
            ->whereExists(fn ($x) => $x->from('role_assignments as ra')->whereColumn('ra.user_id', 'p.user_id')
                ->where('ra.role', 'platform_admin')->where('ra.active', 1))
            ->pluck('p.user_id')->map(fn ($v) => (int) $v)->all(), [], false);
    }

    public static function isGuest(int $aid, int $uid): bool
    {
        return $uid > 0 && in_array($uid, self::guests($aid), true);
    }

    /** An active platform admin (role row active)? Integration and reseller accounts are not. */
    public static function isPlatformAdmin(int $uid): bool
    {
        return $uid > 0 && DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', 1)->exists();
    }

    /**
     * A platform admin using the walkie in this agency: make this his one guest agency (moves him out of the last
     * one). Returns false for anyone else, who must be real staff.
     */
    public static function enterAsGuest(int $aid, int $uid): bool
    {
        if ($aid <= 0 || ! self::isPlatformAdmin($uid)) return false;
        $cur = DB::table('walkie_prefs')->where('user_id', $uid)->value('guest_agency_id');
        if ((int) $cur !== $aid) {
            DB::table('walkie_prefs')->updateOrInsert(['user_id' => $uid], ['guest_agency_id' => $aid, 'guest_at' => now(), 'updated_at' => now()]);
        }

        return true;
    }

    /** Is this person one of this agency's staff (the only people who may use it here)? */
    public static function isStaff(int $aid, int $uid): bool
    {
        return in_array($uid, self::staff($aid), true);
    }

    /**
     * Who is this person to the agency: 'admin' (whole agency), 'director' or 'staff', and the centres they work at.
     *
     * @param  string[] $roles roles in the active agency (AgencyRole::names)
     */
    public static function who(int $aid, int $uid, array $roles): array
    {
        $open = Live::centreIds($aid);
        // A super admin guest (guests()) reaches the agency the way an agency admin does.
        if (in_array('agency_admin', $roles, true) || (in_array('platform_admin', $roles, true) && self::isGuest($aid, $uid))) {
            return ['kind' => 'admin', 'centres' => $open];
        }
        $user = DB::table('users')->where('id', $uid)->first();
        $mine = array_values(array_intersect(array_map('intval', Visibility::centreIds($aid, $user)), $open));

        return ['kind' => in_array('centre_director', $roles, true) ? 'director' : 'staff', 'centres' => $mine];
    }

    /** Everyone this person may call one-to-one. @return int[] */
    public static function reach(int $aid, int $uid, array $who): array
    {
        if ($who['kind'] === 'admin' || ($who['kind'] === 'staff' && self::settings($aid)['educators_agency'])) {
            $ids = self::staff($aid);
        } else {
            $all = self::staff($aid);
            $ids = array_merge(
                self::staff($aid, $who['centres'] ?: [0]),
                array_values(array_intersect(self::leaders($aid, $who['centres']), $all))
            );
        }

        return array_values(array_diff(array_unique(array_map('intval', $ids)), [$uid]));
    }

    /**
     * Agency admins + the directors of these centres (CentreScope::managers: never an outsider or a leaver), plus a
     * super admin guest here (guests(): he stands with the agency admins). @return int[]
     */
    public static function leaders(int $aid, array $centres): array
    {
        $ids = CentreScope::managers($aid, $centres ?: null)->pluck('u.id')->map(fn ($v) => (int) $v)->all();

        return array_values(array_unique(array_merge($ids, self::guests($aid))));
    }

    /**
     * The group channels this person can use, keyed by channel key:
     *   room:<id>     the educators of a room          centre:<id>   a centre's staff
     *   leaders:<id>  a centre's directors + admins    agency        everyone (admins talk, everyone listens)
     *   emergency     everyone, always plays (admin setting: who may talk)
     * A one-to-one channel is dm:<low id>-<high id> (dmKey).
     */
    public static function channels(int $aid, int $uid, array $who): array
    {
        $set = self::settings($aid);
        $centres = $who['centres'];
        $names = DB::table('centres')->whereIn('id', $centres ?: [0])->pluck('name', 'id');
        $out = [];
        $isAdmin = $who['kind'] === 'admin';
        $isLeader = $isAdmin || $who['kind'] === 'director';
        if ($set['emergency']) {
            $out['emergency'] = ['key' => 'emergency', 'kind' => 'emergency', 'label' => $set['emergency_name'], 'icon' => '🚨',
                'can_talk' => $set['emergency_talkers'] === 'admins' ? $isAdmin : $isLeader, 'emergency' => true];
        }
        $out['agency'] = ['key' => 'agency', 'kind' => 'agency', 'label' => 'All staff', 'icon' => '🏢', 'can_talk' => $isAdmin, 'emergency' => false];
        foreach ($centres as $c) {
            $n = (string) ($names[$c] ?? 'Centre');
            $out['centre:' . $c] = ['key' => 'centre:' . $c, 'kind' => 'centre', 'label' => $n . ' staff', 'icon' => '🏫', 'can_talk' => true, 'emergency' => false, 'centre_id' => (int) $c];
            $out['leaders:' . $c] = ['key' => 'leaders:' . $c, 'kind' => 'leaders', 'label' => $n . ' leaders', 'icon' => '🧭', 'can_talk' => true, 'emergency' => false, 'centre_id' => (int) $c];
        }
        $rooms = DB::table('rooms')->whereIn('centre_id', $centres ?: [0])->where(fn ($q) => $q->where('active', 1)->orWhereNull('active'))
            ->orderBy('name')->get(['id', 'name', 'centre_id']);
        foreach ($rooms as $r) {
            $out['room:' . $r->id] = ['key' => 'room:' . $r->id, 'kind' => 'room', 'label' => $r->name . ' educators', 'icon' => '🧸', 'can_talk' => true,
                'emergency' => false, 'centre_id' => (int) $r->centre_id, 'centre' => (string) ($names[$r->centre_id] ?? '')];
        }

        return $out;
    }

    public static function dmKey(int $a, int $b): string
    {
        return 'dm:' . min($a, $b) . '-' . max($a, $b);
    }

    /** The other person of a dm channel, seen from $uid; null when $uid is not in it. */
    public static function dmOther(string $key, int $uid): ?int
    {
        if (! preg_match('/^dm:(\d+)-(\d+)$/', $key, $m)) return null;
        $a = (int) $m[1]; $b = (int) $m[2];
        if ($uid === $a) return $b;
        if ($uid === $b) return $a;

        return null;
    }

    /** Who hears this channel (the speaker excluded). Fails closed: an unknown key is nobody. @return int[] */
    public static function members(int $aid, string $key, int $speaker): array
    {
        $all = self::staff($aid);
        $ids = [];
        if ($key === 'agency' || $key === 'emergency') {
            $ids = $all;
        } elseif (preg_match('/^centre:(\d+)$/', $key, $m)) {
            $ids = self::staff($aid, [(int) $m[1]]);
        } elseif (preg_match('/^leaders:(\d+)$/', $key, $m)) {
            $ids = array_values(array_intersect(self::leaders($aid, [(int) $m[1]]), $all));
        } elseif (preg_match('/^room:(\d+)$/', $key, $m)) {
            $centre = (int) DB::table('rooms')->where('id', (int) $m[1])->value('centre_id');
            $ids = array_values(array_intersect(
                DB::table('educator_rooms')->where('room_id', (int) $m[1])->pluck('user_id')->map(fn ($v) => (int) $v)->all(),
                self::staff($aid, [$centre ?: 0])
            ));
        } elseif (($o = self::dmOther($key, $speaker)) !== null) {
            $ids = in_array($o, $all, true) ? [$o] : [];
        }

        return array_values(array_diff(array_unique(array_map('intval', $ids)), [$speaker]));
    }

    /** Do not disturb right now? */
    public static function dnd(int $uid): bool
    {
        $p = DB::table('walkie_prefs')->where('user_id', $uid)->first();
        if (! $p || ! $p->dnd) return false;

        return $p->dnd_until === null || strtotime((string) $p->dnd_until) > time();
    }

    /** Clocked in now (an open punch from the last 16 hours)? @return int[] of $ids that are */
    public static function onShift(array $ids): array
    {
        if (! $ids) return [];

        return DB::table('time_punches')->whereIn('user_id', $ids)->whereNull('punched_out_at')
            ->where('punched_in_at', '>=', now()->subHours(16))->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    // ---- live files ------------------------------------------------------------------------------------------------

    public static function liveRoot(): string
    {
        return dirname(base_path()) . '/parent-portal/wt';
    }

    /** The person's doorbell file name (an HMAC of their id: unguessable, no table). */
    public static function bellName(int $uid): string
    {
        $k = Sensitive::enabled() ? Sensitive::key() : (string) config('app.key');

        return substr(hash_hmac('sha256', 'walkie-bell:' . $uid, $k), 0, 32);
    }

    public static function bellPath(int $uid): string
    {
        return '/wt/b/' . self::bellName($uid) . '.txt';
    }

    /** Ring these people's doorbells (an open portal tab notices within a second). */
    public static function ring(array $uids): void
    {
        $dir = self::liveRoot() . '/b';
        if (! is_dir($dir)) @mkdir($dir, 0775, true);
        $v = (string) (int) (microtime(true) * 1000);
        foreach (array_unique(array_map('intval', $uids)) as $u) {
            if ($u <= 0) continue;
            $f = $dir . '/' . self::bellName($u) . '.txt';
            @file_put_contents($f . '.tmp', $v);
            @rename($f . '.tmp', $f);
        }
    }

    /** One encrypted live piece: 12-byte IV, ciphertext, 16-byte GCM tag. */
    public static function writeLive(string $dir, string $keyB64, int $seq, string $raw): void
    {
        $path = self::liveRoot() . '/' . $dir;
        if (! is_dir($path)) @mkdir($path, 0775, true);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($raw, 'aes-256-gcm', base64_decode($keyB64), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) return;
        @file_put_contents($path . '/' . $seq . '.bin.tmp', $iv . $ct . $tag);
        @rename($path . '/' . $seq . '.bin.tmp', $path . '/' . $seq . '.bin');
    }

    /** The talk is over: end.txt holds how many pieces there are. */
    public static function endLive(string $dir, int $chunks): void
    {
        $path = self::liveRoot() . '/' . $dir;
        if (! is_dir($path)) @mkdir($path, 0775, true);
        @file_put_contents($path . '/end.txt', (string) $chunks);
    }

    public static function dropLive(?string $dir): void
    {
        if (! $dir || ! preg_match('/^[a-f0-9]{32}$/', $dir)) return;
        $path = self::liveRoot() . '/' . $dir;
        foreach (glob($path . '/*') ?: [] as $f) @unlink($f);
        @rmdir($path);
    }

    /** First name for pushes and "is talking" (staff first names only). */
    public static function firstName(int $uid): string
    {
        $u = DB::table('users')->where('id', $uid)->first(['first_name', 'preferred_name']);

        return trim((string) (($u->preferred_name ?? '') ?: ($u->first_name ?? ''))) ?: 'A colleague';
    }

    public static function fullName(int $uid): string
    {
        $u = DB::table('users')->where('id', $uid)->first(['first_name', 'preferred_name', 'last_name']);

        return trim((string) ((($u->preferred_name ?? '') ?: ($u->first_name ?? '')) . ' ' . ($u->last_name ?? '')));
    }
}
