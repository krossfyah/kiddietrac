<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * THE PUBLIC WEBSITE DEMO (2026-10-31). Anthony chose "Live phone, demo accounts": kiddietrac.com shows the real app in
 * a phone frame, and "Try as Parent / Educator / Director" signs a visitor into a fictional Brightwood demo account
 * (agency with settings.demo = true AND settings.demo_public = true; every user @brightwood-demo.kiddietrac.invalid).
 *
 * Anyone on the internet can drive these accounts, so a public demo agency must never reach the outside world. This
 * class is the one answer to "is this the public demo?", asked by every gate:
 *   - mail: SuppressAgencyMail cancels anything sent from/to it or during a demo session (before any bypass);
 *   - SMS / calls: Suppression::isUser, SmsGateway::deliver, Telnyx::sendSms/dial;
 *   - push: Suppression::isUser (FcmService + WebPushService already ask it);
 *   - payments: PaymentProviders::configured is false for it;
 *   - AI that costs credit: AiConfig::offFor;
 *   - requests: App\Http\Middleware\PublicDemoGuard (risky routes refused, edits/deletes of shared records refused,
 *     and every row a visitor inserts recorded so kt:reset-web-demo can remove exactly those each night).
 */
final class PublicDemo
{
    public const TOKEN = 'web-demo';
    /** The designated accounts behind "Try as ..." (Brightwood: parent of two at centre 84, its educator, its director). */
    public const USERS = ['parent' => 1214, 'educator' => 1199, 'director' => 1198];

    /**
     * THE PUBLISHED LOGINS (2026-10-31, second round). The website's demo phone boots the real app to its real sign-in
     * page, and these are printed beside it for anyone to type. They are not secrets: the protection is everything
     * else in this class - every session of a demo-only person is fenced in, whatever token it holds - and
     * kt:reset-web-demo puts these passwords back every night. Changing one here changes the site's card too
     * (demo-phone.html and the portal's kt-demo-frame.js list the same three) and needs a reset run.
     */
    /* NEW LOGINS (2026-10-30, Anthony: "use realistic email addresses (fake) and password"). The site no longer prints
       them - sales hands them out (Files repository > Demo logins) - and the old Demo-*-2026 passwords, which were on the
       public site for a day, are retired. The addresses are on brightwood.kiddietrac.com: a domain we own with no mail
       server, so nothing could ever be delivered to them even if every suppression failed. */
    public const DOMAIN = 'brightwood.kiddietrac.com';
    public const ACCOUNTS = [
        'parent'   => ['id' => 1214, 'email' => 'megan.gonzalez@brightwood.kiddietrac.com', 'password' => 'Pebbles#Megan365',
            'photo' => 'https://i.pravatar.cc/240?u=parent.gonzalez%40brightwood-demo.kiddietrac.invalid'],
        'educator' => ['id' => 1199, 'email' => 'maya.thompson@brightwood.kiddietrac.com', 'password' => 'Crayons#Maya917',
            'photo' => 'https://i.pravatar.cc/240?u=educator%40brightwood-demo.kiddietrac.invalid'],
        'director' => ['id' => 1198, 'email' => 'elena.brooks@brightwood.kiddietrac.com', 'password' => 'Sunrise#Elena482',
            'photo' => 'https://i.pravatar.cc/240?u=director%40brightwood-demo.kiddietrac.invalid'],
        /* The agency admin (2026-10-30, Anthony: the "Demo experience" page shows every role). A new fictional person,
           not the private Harper Lane admin; fenced like the others (PublicDemoGuard: risky routes, no edits of shared rows). */
        'admin'    => ['id' => 1287, 'email' => 'olivia.bennett@brightwood.kiddietrac.com', 'password' => 'Harbor#Olivia846',
            'photo' => 'https://i.pravatar.cc/240?u=olivia.bennett%40brightwood.kiddietrac.com'],
    ];
    /* THE WHITE-LABEL DEMO (2026-10-30, Anthony: "with the emulator do not make it white label use the kiddietrac branding
       and add another login specifically for white label with a full label experience"). Brightwood (agency 25) is now
       KiddieTrac-branded (powered_by_visible = 1); Starlight Kids Academy (kt:seed-starlight-demo, its own logo, icon and
       colours, powered_by_visible = 0) is the partner's own app on the demo phone. Same three people, own addresses on
       starlight.kiddietrac.com (ours, no mail server). */
    /* The white-label logins carry NO 'kiddietrac' (2026-10-30, Anthony: a full white label): .example is reserved (RFC 2606) and can never receive mail. */
    public const DOMAINS = [self::DOMAIN, 'starlight.kiddietrac.com', 'starlightkids.example'];
    public const ACCOUNTS_WL = [
        'parent'   => ['id' => 1259, 'email' => 'megan.gonzalez@starlightkids.example', 'password' => 'Comet#Megan713',
            'photo' => 'https://i.pravatar.cc/240?u=megan.gonzalez%40starlightkids.example'],
        'educator' => ['id' => 1244, 'email' => 'maya.thompson@starlightkids.example', 'password' => 'Stardust#Maya264',
            'photo' => 'https://i.pravatar.cc/240?u=maya.thompson%40starlightkids.example'],
        'director' => ['id' => 1243, 'email' => 'elena.brooks@starlightkids.example', 'password' => 'Twinkle#Elena528',
            'photo' => 'https://i.pravatar.cc/240?u=elena.brooks%40starlightkids.example'],
        'admin'    => ['id' => 1288, 'email' => 'olivia.bennett@starlightkids.example', 'password' => 'Galaxy#Olivia391',
            'photo' => 'https://i.pravatar.cc/240?u=olivia.bennett%40starlightkids.example'],
    ];

    /** Every set of website logins: 'kiddietrac' (Brightwood, KiddieTrac's own brand) and 'white_label' (Starlight). */
    public static function sets(): array
    {
        return ['kiddietrac' => self::ACCOUNTS, 'white_label' => self::ACCOUNTS_WL];
    }

    /** parent / educator / director / admin for a website login, null for anyone else. */
    public static function roleOf(int $userId): ?string
    {
        foreach (self::sets() as $set) foreach ($set as $role => $a) if ((int) $a['id'] === $userId) return $role;
        return null;
    }

    /** Which set a website login belongs to ('kiddietrac' | 'white_label'), null for anyone else. */
    public static function setOf(int $userId): ?string
    {
        foreach (self::sets() as $name => $set) foreach ($set as $a) if ((int) $a['id'] === $userId) return $name;
        return null;
    }

    /** The account id of a role in the same set as this person (the parent a staff "Try it" calls). */
    public static function idFor(int $userId, string $role): ?int
    {
        $set = self::sets()[self::setOf($userId) ?? 'kiddietrac'] ?? self::ACCOUNTS;
        return isset($set[$role]) ? (int) $set[$role]['id'] : null;
    }

    /** The public demo agency this demo person belongs to (there are two since 2026-10-30). */
    public static function agencyOf(int $userId): int
    {
        $demo = self::agencyIds();
        $id = (int) (DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->whereIn('agency_id', $demo ?: [0])->value('agency_id')
            ?: DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('g.user_id', $userId)->whereIn('c.agency_id', $demo ?: [0])->value('c.agency_id'));
        return $id ?: (int) ($demo[0] ?? 0);
    }

    /**
     * THE PARENT PHONE FOLLOWS THE CHILD (2026-10-31, Anthony: "if I log any child off it should show up on the parent
     * demo"). The demo parent (user id) of a family in a public demo agency: its primary guardian with an account, who
     * must be a demo-only person. Null for anything else - never a real person.
     */
    public static function parentOfFamily(int $familyId, int $agencyId): ?int
    {
        if (! self::isAgency($agencyId)) return null;
        $fam = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')->where('f.id', $familyId)->first(['f.id', 'c.agency_id']);
        if (! $fam || (int) $fam->agency_id !== $agencyId) return null;
        foreach (DB::table('guardians')->where('family_id', $familyId)->whereNotNull('user_id')->orderByDesc('is_primary')->orderBy('id')->pluck('user_id') as $uid) {
            if (self::isUser((int) $uid)) return (int) $uid;
        }
        return null;
    }

    public static function parentOfChild(int $childId, int $agencyId): ?int
    {
        $fam = (int) DB::table('children')->where('id', $childId)->value('family_id');
        return $fam ? self::parentOfFamily($fam, $agencyId) : null;
    }

    /** Every child of a public demo agency with its demo parent (the parent phone's "Parent of" picker). */
    public static function roster(int $agencyId): array
    {
        if (! self::isAgency($agencyId)) return [];
        $out = [];
        $rows = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)->whereNull('ch.deleted_at')->orderBy('ch.first_name')->get(['ch.id', 'ch.first_name', 'ch.preferred_name', 'ch.last_name', 'ch.family_id']);
        $parents = [];
        foreach ($rows as $r) {
            $fid = (int) $r->family_id;
            if (! array_key_exists($fid, $parents)) $parents[$fid] = self::parentOfFamily($fid, $agencyId);
            if (! $parents[$fid]) continue;
            $out[] = ['child_id' => (int) $r->id, 'child' => trim(($r->preferred_name ?: $r->first_name) . ' ' . mb_substr((string) $r->last_name, 0, 1) . '.'),
                'family_id' => $fid, 'parent_id' => $parents[$fid]];
        }
        $names = DB::table('users')->whereIn('id', array_values(array_filter(array_unique(array_column($out, 'parent_id')))) ?: [0])->pluck('first_name', 'id');
        foreach ($out as &$o) { $o['parent'] = (string) ($names[$o['parent_id']] ?? ''); unset($o['parent_id']); }
        return $out;
    }

    /**
     * Every demo parent hears about sign-in / sign-out on every channel - in the app, by email and by text - so the demo
     * shows the whole picture (2026-10-31). Run by kt:reset-web-demo each night (visitors may switch channels off).
     */
    public static function demoPrefs(bool $dry = false): int
    {
        $n = 0;
        foreach (self::agencyIds() as $ag) {
            $uids = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('c.agency_id', $ag)->whereNotNull('g.user_id')->distinct()->pluck('g.user_id');
            foreach ($uids as $uid) {
                if (! self::isUser((int) $uid)) continue;
                $n++;
                if ($dry) continue;
                DB::table('notification_prefs')->updateOrInsert(
                    ['user_id' => (int) $uid, 'event_key' => \App\Services\CheckEventNotifier::EVENT_KEY],
                    ['email' => 1, 'sms' => 1, 'push' => 1, 'updated_at' => now()]
                );
            }
        }
        return $n;
    }

    /** A demo sign-in lasts this long (a real one: 30 days). */
    public const SESSION_HOURS = 2;
    /** A fictional demo shift ends this many hours after it starts (see endShifts). */
    public const SHIFT_HOURS = 10;

    private static ?array $agencies = null;
    private static array $users = [];

    /** Ids of public demo agencies: a demo agency explicitly opened to the public. */
    public static function agencyIds(): array
    {
        if (self::$agencies === null) {
            self::$agencies = [];
            try {
                foreach (DB::table('agencies')->get(['id', 'settings']) as $a) {
                    $s = json_decode((string) $a->settings, true) ?: [];
                    if (($s['demo'] ?? false) === true && ($s['demo_public'] ?? false) === true) self::$agencies[] = (int) $a->id;
                }
            } catch (\Throwable $e) {}
        }
        return self::$agencies;
    }

    public static function isAgency(?int $agencyId): bool
    {
        return $agencyId !== null && $agencyId > 0 && in_array((int) $agencyId, self::agencyIds(), true);
    }

    /**
     * A person who belongs ONLY to public demo agencies (staff role or guardian there, nothing anywhere else, and not a
     * platform admin). Someone who also works at or has a child in a real agency is a real person.
     */
    public static function isUser(?int $userId): bool
    {
        if (! $userId || ! self::agencyIds()) return false;
        if (array_key_exists($userId, self::$users)) return self::$users[$userId];
        $demo = self::agencyIds();
        $ag = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->get(['role', 'agency_id']);
        if ($ag->contains(fn ($r) => $r->role === 'platform_admin')) return self::$users[$userId] = false;
        $ids = $ag->pluck('agency_id')->filter()->map(fn ($i) => (int) $i)
            ->merge(DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('g.user_id', $userId)->pluck('c.agency_id')->map(fn ($i) => (int) $i))
            ->unique()->values();
        return self::$users[$userId] = $ids->isNotEmpty() && $ids->every(fn ($i) => in_array($i, $demo, true));
    }

    /** Is the current web request a public demo session (a "web-demo" token, or any demo-only person)? */
    public static function isSession(): bool
    {
        try {
            if (app()->runningInConsole() || ! app()->bound('request')) return false;
            $req = request();
            if ($req->attributes->has('kt_public_demo')) return (bool) $req->attributes->get('kt_public_demo');
            $u = $req->user('sanctum');
            $yes = false;
            if ($u) {
                $tok = method_exists($u, 'currentAccessToken') ? $u->currentAccessToken() : null;
                $yes = ($tok && ($tok->name ?? '') === self::TOKEN) || self::isUser((int) $u->id);
            }
            $req->attributes->set('kt_public_demo', $yes);
            return $yes;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** An address that belongs to a demo-only person, or one that can never exist. */
    public static function isDemoEmail(string $email): bool
    {
        $e = mb_strtolower(trim($email));
        if ($e === '' || str_ends_with($e, '.invalid')) return true;
        foreach (self::DOMAINS as $dom) if (str_ends_with($e, '@' . $dom)) return true;
        $uid = DB::table('users')->whereRaw('LOWER(email) = ?', [$e])->value('id');
        return $uid ? self::isUser((int) $uid) : false;
    }

    /**
     * A sign-in identifier that must never trip the failed-attempt hold: a demo-only person's address (or a .invalid
     * one, which no real account has). Those addresses are printed on the website, so a hold would let one visitor
     * typing nonsense lock the demo for everyone; the per-IP limiter on /auth/login still applies.
     */
    public static function isDemoLogin(string $login): bool
    {
        return trim($login) !== '' && strpos($login, '@') !== false && self::isDemoEmail($login);
    }

    /**
     * Nightly: put every published account back exactly as the website prints it - its password and address, no
     * forced change, no MFA, Terms on file - and sign every session out. Returns one line per account for the report.
     */
    public static function restoreAccounts(bool $dry = false): array
    {
        $out = [];
        foreach (self::sets() as $setName => $set) foreach ($set as $role => $a) {
            $role = ($setName === 'kiddietrac' ? '' : 'wl ') . $role;
            $u = DB::table('users')->where('id', $a['id'])->first(['id', 'email', 'password', 'photo_url']);
            if (! $u || ! self::isUser((int) $u->id)) { $out[] = "{$role}: account {$a['id']} missing or not demo-only, left alone"; continue; }
            $pw = false;
            try { $pw = \Illuminate\Support\Facades\Hash::check($a['password'], (string) $u->password); } catch (\Throwable $e) {}
            $sameEmail = mb_strtolower((string) $u->email) === $a['email'];
            $tokens = DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->where('tokenable_id', $u->id)->count();
            // A visitor may set a profile photo (2026-10-30): the account's own photo comes back each night.
            $samePhoto = ! isset($a['photo']) || (string) $u->photo_url === $a['photo'];
            $out[] = sprintf('%-8s password %s · address %s · photo %s · signed-in sessions %d', $role, $pw ? 'ok' : 'RESTORED', $sameEmail ? 'ok' : 'RESTORED', $samePhoto ? 'ok' : 'RESTORED', $tokens);
            if ($dry) continue;
            $upd = [];
            if (! $pw) $upd['password'] = \Illuminate\Support\Facades\Hash::make($a['password']);
            if (! $sameEmail) $upd['email'] = $a['email'];
            if (! $samePhoto) $upd['photo_url'] = $a['photo'];
            if ($upd) DB::table('users')->where('id', $u->id)->update($upd + ['updated_at' => now()]);
            DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->where('tokenable_id', $u->id)->delete();
            self::prepareUser((int) $u->id);
        }
        return $out;
    }

    /**
     * FICTIONAL SHIFTS END (2026-10-31). demo:seed-daily clocks the demo staff in at 7:30 and nothing clocked them out
     * until the next run, so by evening the director's screen said "Checked in 15h - please check out" and the 6 pm
     * reminder told her she had never clocked out. Only demo-only people of a PUBLIC demo agency (all fictional): a
     * punch still open SHIFT_HOURS after it began is closed at begin + SHIFT_HOURS, as a real day would end.
     */
    public static function endShifts(bool $dry = false): int
    {
        if (! self::agencyIds()) return 0;
        $staff = DB::table('role_assignments')->whereIn('agency_id', self::agencyIds())->where('active', 1)
            ->where('role', '!=', 'guardian')->distinct()->pluck('user_id')
            ->filter(fn ($id) => self::isUser((int) $id))->values()->all();
        if (! $staff) return 0;
        $q = DB::table('time_punches')->whereIn('user_id', $staff)->whereNull('punched_out_at')
            ->where('punched_in_at', '<=', now()->subHours(self::SHIFT_HOURS));
        if ($dry) return $q->count();
        return $q->update(['punched_out_at' => DB::raw('DATE_ADD(punched_in_at, INTERVAL ' . (int) self::SHIFT_HOURS . ' HOUR)')]);
    }

    /** Make a designated account ready for a visitor: terms on file, no forced password change, no gates. */
    public static function prepareUser(int $userId): void
    {
        $u = DB::table('users')->where('id', $userId)->first();
        if (! $u) return;
        $agencyId = (int) (DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->value('agency_id')
            ?: DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')->where('g.user_id', $userId)->value('c.agency_id'));
        $signed = DB::table('signed_documents')->where('signer_user_id', $userId)->whereIn('document_type', ['privacy_nda', 'privacy_nda_declined'])->orderByDesc('id')->first();
        if (! $signed || $signed->document_type !== 'privacy_nda' || ($signed->agreement_version ?? null) !== \App\Http\Controllers\Api\AgreementController::AGREEMENT_VERSION) {
            DB::table('signed_documents')->insert([
                'agency_id' => $agencyId ?: null, 'document_type' => 'privacy_nda',
                'agreement_version' => \App\Http\Controllers\Api\AgreementController::AGREEMENT_VERSION,
                'source_table' => 'users', 'source_id' => $userId, 'signer_user_id' => $userId,
                'signer_name' => trim($u->first_name . ' ' . $u->last_name), 'signature_data' => '',
                'document_hash' => 'public-demo-account', 'signed_at' => now(), 'ip_address' => null,
                'user_agent' => 'KiddieTrac public demo (fictional account)', 'created_at' => now(),
            ]);
        }
        $upd = [];
        if ((int) $u->must_change_password) $upd['must_change_password'] = 0;
        if (! $u->password_changed_at || now()->diffInDays(\Illuminate\Support\Carbon::parse($u->password_changed_at), true) > 300) $upd['password_changed_at'] = now();
        if (! $u->onboarded_at) $upd['onboarded_at'] = now();
        if ((int) $u->two_factor_enabled) $upd['two_factor_enabled'] = 0;
        if ($agencyId && (int) ($u->last_agency_id ?? 0) !== $agencyId) $upd['last_agency_id'] = $agencyId;
        if ($upd) DB::table('users')->where('id', $userId)->update($upd + ['updated_at' => now()]);
    }
}
