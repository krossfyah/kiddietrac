<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EACH APP SIGNS IN ITS OWN PEOPLE (2026-10-30, Anthony: "make the emulator only use its credentials assigned to its brand
 * (kiddie or starlight and if you took the starlight and used with kiddietrac it wouldn't work) ... for live production as
 * well based on the white labelled approach").
 *
 * A BRAND is the app a person signs in through:
 *   'kt'     KiddieTrac's own app / site (no tenant) - and any agency that is NOT white-labelled;
 *   'r:<id>' a white-label reseller's app: every agency under a reseller with hide_kiddietrac;
 *   'a:<id>' an agency's own white-label app (powered_by_visible = 0, no reseller).
 * The sign-in page says which app it is with the X-KT-Brand header (kt-brand-header.js: the tenant slug from ?tenant= /
 * kt_tenant, else "kiddietrac"); social sign-in carries it through the OAuth state. No header = KiddieTrac (fail closed).
 *
 * A person may sign in through a brand only if one of their agencies (active staff roles, or a guardian's families) is that
 * brand. Not checked: platform admins and sales reps, reseller staff (cross-tenant by design), and people with no agency
 * yet (nothing to lock). People in both kinds may use either app; the agency they land in follows the app.
 *
 * Refusals are the SAME "Invalid credentials." as a wrong password, so the wrong app never learns the account exists.
 *
 * Switch: PlatformSettings auth.brand_lock = off | log | enforce (default log: decide nothing, record what WOULD be
 * refused). agencies.settings.brand_lock = 'enforce' turns it on for one agency (the website demo agencies 25 and 26):
 * enforced when the person belongs to such an agency or signs in through its brand. Every decision is a row in
 * auth_brand_events, which is what the impact report reads.
 */
final class BrandLock
{
    public const SETTING = 'auth.brand_lock';
    private static array $agencyKey = [];

    /** The brand a sign-in request came through. */
    public static function contextKey(Request $r, ?string $slug = null): string
    {
        $slug = strtolower(trim((string) ($slug ?? $r->header('X-KT-Brand', ''))));
        if ($slug === '' || $slug === 'kiddietrac' || ! preg_match('/^[a-z0-9-]{1,80}$/', $slug)) return 'kt';
        $a = DB::table('agencies')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($a) return self::keyForAgency((int) $a);
        $res = DB::table('resellers')->where('slug', $slug)->where('status', 'active')->first(['id', 'hide_kiddietrac']);
        if ($res && (int) $res->hide_kiddietrac === 1) {
            $ags = DB::table('agencies')->where('reseller_id', (int) $res->id)->whereNull('deleted_at')->pluck('id')->map(fn ($i) => (int) $i)->all();
            if ($ags && ! array_diff($ags, self::ktAgencies())) return 'kt';
            return 'r:' . (int) $res->id;
        }
        return 'kt';
    }

    /** PlatformSettings auth.brand_lock_kt_agencies: agencies signed into through the KiddieTrac app whatever they look like. */
    public static function ktAgencies(): array
    {
        $l = PlatformSettings::get(self::SETTING . '_kt_agencies');
        $l = is_array($l) ? $l : (json_decode((string) $l, true) ?: []);
        return array_map('intval', $l);
    }

    /** The brand an agency's people use. */
    public static function keyForAgency(int $agencyId): string
    {
        if (isset(self::$agencyKey[$agencyId])) return self::$agencyKey[$agencyId];
        if (in_array($agencyId, self::ktAgencies(), true)) return self::$agencyKey[$agencyId] = 'kt';
        $b = Brand::forAgency($agencyId);
        $k = empty($b['white_label']) ? 'kt' : (($b['source'] ?? '') === 'reseller' && ! empty($b['reseller_id']) ? 'r:' . (int) $b['reseller_id'] : 'a:' . $agencyId);
        return self::$agencyKey[$agencyId] = $k;
    }

    /** The agencies a person belongs to: active non-platform roles, and guardians' families. */
    public static function agencyIds(int $userId): array
    {
        $ids = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->whereNotNull('agency_id')
            ->whereNotIn('role', ['platform_admin', 'sales_rep'])->pluck('agency_id')
            ->merge(DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('g.user_id', $userId)->whereNull('c.deleted_at')->pluck('c.agency_id'))
            ->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
        return DB::table('agencies')->whereIn('id', $ids ?: [0])->whereNull('deleted_at')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /* App-store review accounts sign into the KiddieTrac app although their agency (Test Agency) is white-labelled:
       Apple's App Review (Jordan, 1057) and Google Play's pre-launch robots (Jessica, 88). PlatformSettings
       auth.brand_lock_exempt_users = [user ids] keeps them working whatever the switch says. */
    public static function exempt(int $userId): bool
    {
        $list = PlatformSettings::get(self::SETTING . '_exempt_users');
        $list = is_array($list) ? $list : (json_decode((string) $list, true) ?: []);
        if (in_array($userId, array_map('intval', $list), true)) return true;
        return DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->where(fn ($q) => $q->whereIn('role', ['platform_admin', 'sales_rep'])->orWhereNotNull('reseller_id'))->exists();
    }

    /** Rows of agencies (->id) narrowed to the app this request came through. Never empties a list. */
    public static function filterAgencies(Request $r, int $userId, $rows)
    {
        try {
            if ((string) PlatformSettings::get(self::SETTING) !== 'enforce' || self::exempt($userId)) return $rows;
            $ctx = self::contextKey($r);
            $f = collect($rows)->filter(fn ($a) => self::keyForAgency((int) $a->id) === $ctx)->values();
            return $f->count() ? $f : $rows;
        } catch (\Throwable $e) {
            return $rows;
        }
    }

    private static function agencyEnforces(int $agencyId): bool
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        return ($s['brand_lock'] ?? null) === 'enforce';
    }

    private static function contextAgencyIds(string $ctx): array
    {
        if (str_starts_with($ctx, 'a:')) return [(int) substr($ctx, 2)];
        if (str_starts_with($ctx, 'r:')) return DB::table('agencies')->where('reseller_id', (int) substr($ctx, 2))->whereNull('deleted_at')->pluck('id')->map(fn ($i) => (int) $i)->all();
        return [];
    }

    /**
     * Decide one sign-in. Returns true when it may go ahead (allowed, or refused only in log mode).
     * $path: login | passkey | social | sso | set-password.
     */
    public static function allows(Request $r, int $userId, string $path, ?string $slug = null): bool
    {
        try {
            $global = (string) (PlatformSettings::get(self::SETTING) ?: 'log');
            if (! in_array($global, ['off', 'log', 'enforce'], true)) $global = 'log';
            $ctx = self::contextKey($r, $slug);
            $exempt = self::exempt($userId);
            $agencies = $exempt ? [] : self::agencyIds($userId);
            $keys = array_values(array_unique(array_map(fn ($a) => self::keyForAgency($a), $agencies)));
            $ok = $exempt || ! $keys || in_array($ctx, $keys, true);

            $mode = $global;
            if (! $ok && $mode !== 'enforce') {
                foreach (array_merge($agencies, self::contextAgencyIds($ctx)) as $a) {
                    if (self::agencyEnforces($a)) { $mode = 'enforce'; break; }
                }
            }
            $decision = $ok ? ($exempt ? 'exempt' : ($keys ? 'allow' : 'no_agency')) : ($mode === 'enforce' ? 'block' : ($mode === 'log' ? 'would_block' : 'off'));
            if ($global !== 'off' || $decision === 'block') {
                DB::table('auth_brand_events')->insert([
                    'user_id' => $userId, 'path' => $path, 'context' => $ctx, 'user_brands' => implode(',', $keys),
                    'decision' => $decision, 'ip' => mb_substr((string) $r->ip(), 0, 45),
                    'user_agent' => mb_substr((string) $r->userAgent(), 0, 255), 'created_at' => now(),
                ]);
            }
            if ($decision === 'block') {
                $__u = DB::table('users')->where('id', $userId)->first(['first_name', 'last_name', 'email']);
                $__name = $__u ? (trim(($__u->first_name ?? '') . ' ' . ($__u->last_name ?? '')) ?: (string) $__u->email) : 'An account';
                $__app = fn ($k) => $k === 'kiddietrac' ? 'KiddieTrac' : ucwords(str_replace(['a:', 'r:', '-', '_'], ['agency #', 'reseller #', ' ', ' '], (string) $k));
                Audit::write(['user_id' => $userId, 'agency_id' => $agencies[0] ?? null, 'action' => 'login_brand_blocked',
                    'entity_type' => 'user', 'entity_id' => $userId,
                    'payload' => json_encode([
                        'summary' => $__name . ' (' . ($__u->email ?? '?') . ') was refused sign-in in the ' . $__app($ctx) . ' app: the account belongs to the '
                            . implode(' / ', array_map($__app, $keys)) . ' app. Method: ' . str_replace('-', ' ', $path) . '. They saw the normal wrong-password message.',
                        'context' => $ctx, 'their_app' => implode(', ', $keys), 'path' => $path, 'email' => $__u->email ?? null,
                    ]),
                    'ip_address' => mb_substr((string) $r->ip(), 0, 45), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 500)]);
                return false;
            }
            // Allowed, in both kinds: land in an agency of the app they came through.
            if ($ok && ! $exempt && count($keys) > 1) {
                $here = array_values(array_filter($agencies, fn ($a) => self::keyForAgency($a) === $ctx));
                $last = (int) DB::table('users')->where('id', $userId)->value('last_agency_id');
                if ($here && ! in_array($last, $here, true)) DB::table('users')->where('id', $userId)->update(['last_agency_id' => $here[0]]);
            }
            return true;
        } catch (\Throwable $e) {
            // The lock must never take sign-in down; the error is logged and the sign-in goes ahead (this is a brand
            // separation, not the password check).
            Log::warning('brand lock check failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return true;
        }
    }
}
