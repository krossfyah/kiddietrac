<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AGENCY CONTACTS ON THE KIDDIETRAC SUBSCRIBER LIST (2026-10-27, Anthony: "all of the agencies contacts
 * should be automatically added a subscriber to kiddietrac once boarded - backfill").
 *
 * An onboarded agency's business contacts - its contact email and its active agency admins and centre
 * directors - are added to site_subscribers (source 'agency'), the same list
 * leads join (LeadWelcome). kiddietrac:agency-subscribers runs hourly, so an agency is on the list
 * within the hour of being set up and a new admin is picked up the same way; it was also run once as
 * the backfill.
 *  - Never re-added after unsubscribing: an existing row is left exactly as it is.
 *  - No welcome email: they are customers, not leads.
 *  - Test and demo agencies, and test / example / placeholder addresses, are left out.
 */
final class AgencySubscribers
{
    /* 2026-10-27 later: EVERY active user of an onboarded agency (parents, educators, staff, admins), not only
       the admins - Anthony: "subscribers should be all users (parents, educators, staff) ... once boarded - backfill". */
    private const SKIP_EMAIL = '/(@example\.|\.invalid$|\.test$|\.example$|@appreview\.kiddietrac\.com$|@demo\.|testagency|@kiddietrac\.com$)/i';

    /** [email => ['name' => .., 'agency' => ..]] for one agency, or for all of them. */
    public static function contacts(?int $agencyId = null): array
    {
        $out = [];
        $agencies = DB::table('agencies')->whereNull('deleted_at')
            ->when($agencyId, fn ($q) => $q->where('id', $agencyId))
            ->get(['id', 'name', 'contact_email', 'billing_status']);
        foreach ($agencies as $a) {
            if (preg_match('/\b(test|demo|sandbox)\b/i', (string) $a->name) || in_array((string) $a->billing_status, ['closed', 'cancelled', 'canceled'], true)) {
                continue;
            }
            $add = function ($email, $name) use (&$out, $a) {
                $e = mb_strtolower(trim((string) $email));
                if (! filter_var($e, FILTER_VALIDATE_EMAIL) || preg_match(self::SKIP_EMAIL, $e) || isset($out[$e])) return;
                $out[$e] = ['name' => trim((string) $name) ?: null, 'agency' => (string) $a->name];
            };
            $add($a->contact_email, '');
            $people = DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.agency_id', $a->id)->where('ra.active', 1)
                ->whereNull('u.deleted_at')->whereNotIn('u.status', Audience::OFF_STATUSES)
                ->distinct()->get(['u.email', 'u.first_name', 'u.last_name']);
            foreach ($people as $p) $add($p->email, trim($p->first_name . ' ' . $p->last_name));
        }
        return $out;
    }

    /** Add the ones not on the list yet. Returns ['added' => [..], 'already' => n, 'unsubscribed' => n]. */
    public static function sync(?int $agencyId = null, bool $dry = false): array
    {
        $res = ['added' => [], 'already' => 0, 'unsubscribed' => 0];
        if (! Schema::hasTable('site_subscribers')) return $res;
        $now = now();
        foreach (self::contacts($agencyId) as $email => $c) {
            $row = DB::table('site_subscribers')->where('email', $email)->first(['id', 'unsubscribed_at']);
            if ($row) { $row->unsubscribed_at ? $res['unsubscribed']++ : $res['already']++; continue; }
            $res['added'][] = $email;
            if ($dry) continue;
            DB::table('site_subscribers')->insert([
                'email' => $email, 'name' => $c['name'], 'agency_name' => $c['agency'] ?: null, 'source' => 'agency',
                'subscribed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return $res;
    }
}
