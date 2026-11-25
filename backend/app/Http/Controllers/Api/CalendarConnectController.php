<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AgencyRole;
use App\Support\Audit;
use App\Support\ExternalCalendar;
use App\Support\GoogleMailbox;
use App\Support\MicrosoftMailbox;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings > Calendar > "Outlook / Google calendar" and the My day panel (2026-11-25). App\Support\ExternalCalendar.
 *
 *   GET    /me/calendar-connections            what is connected, and the mailboxes that could be
 *   POST   /me/calendar-connections/connect    {account_id, scope: personal|agency} -> the Microsoft / Google page
 *   POST   /me/calendar-connections/finish     {nonce} after the sign-in comes back (?kcal=)
 *   DELETE /me/calendar-connections/{id}       stop using it
 *   GET    /me/calendar-connections/events     ?from=&to= (Y-m-d, the agency's days) - the events, both calendars
 *
 * Personal: admins, directors, the KiddieTrac team (platform admin, sales). The agency's calendar: set by an agency
 * admin (or a platform admin in that agency); read by its admins and directors. Everything is the ACTIVE agency's;
 * no agency, no agency calendar.
 *
 * CALENDAR ONLY (2026-11-25): someone with no Email client (the sales team) connects with {provider} instead of an
 * account_id - a Microsoft / Google sign-in that grants the calendar and none of the mail, held on an email_accounts
 * row with shared_scope 'calendar' (never shown as a mailbox). Disconnecting removes that row and its sign-in.
 */
class CalendarConnectController extends Controller
{
    use ResolvesCentreContext;

    private const PERSONAL_ROLES = ['agency_admin', 'centre_director', 'platform_admin', 'sales_rep'];
    private const AGENCY_READ = ['agency_admin', 'centre_director', 'platform_admin'];
    private const AGENCY_SET = ['agency_admin', 'platform_admin'];
    /** Who has the Email client (their own mailboxes); everyone else in PERSONAL_ROLES connects calendar-only. */
    private const EMAIL_ROLES = ['agency_admin', 'centre_director', 'platform_admin'];

    private function roles(Request $r): array
    {
        return AgencyRole::names($r);
    }

    private function may(Request $r, array $roles): bool
    {
        return (bool) array_intersect($this->roles($r), $roles);
    }

    private function agencyId(Request $r): ?int
    {
        $a = (int) ($this->resolveAgencyId($r) ?: 0);
        return $a ?: null;
    }

    /** The mailboxes this person signed in to in Email (their own, not a shared sales one). */
    private function accounts(int $uid): array
    {
        return DB::table('email_accounts')->where('user_id', $uid)->whereNull('shared_scope')->whereNull('deleted_at')
            ->whereIn('provider', ['microsoft', 'google'])->orderByDesc('is_default')->orderBy('id')
            ->get(['id', 'email_address', 'provider', 'auth_type', 'status', 'auth_failed_at', 'oauth_token'])
            ->map(fn ($a) => [
                'id' => (int) $a->id, 'address' => $a->email_address, 'provider' => $a->provider,
                'signed_in' => in_array($a->auth_type, ['microsoft', 'google'], true) && empty($a->auth_failed_at),
                'calendar' => ExternalCalendar::granted($a),
            ])->values()->all();
    }

    public function index(Request $r): JsonResponse
    {
        if (! $this->may($r, self::PERSONAL_ROLES)) return response()->json(['available' => false]);
        $uid = (int) $r->user()->id;
        $aid = $this->agencyId($r);
        $readAgency = $aid && $this->may($r, self::AGENCY_READ);
        return response()->json([
            'available' => true,
            'configured' => ['microsoft' => MicrosoftMailbox::configured(), 'google' => GoogleMailbox::configured()],
            'personal' => ExternalCalendar::present(ExternalCalendar::personal($uid)),
            'agency' => $readAgency ? ExternalCalendar::present(ExternalCalendar::agency($aid)) : null,
            'agency_name' => $readAgency ? DB::table('agencies')->where('id', $aid)->value('name') : null,
            'can_set_agency' => $aid && $this->may($r, self::AGENCY_SET),
            'calendar_only' => ! $this->may($r, self::EMAIL_ROLES),
            'accounts' => $this->may($r, self::EMAIL_ROLES) ? $this->accounts($uid) : [],
        ]);
    }

    public function connect(Request $r): JsonResponse
    {
        $d = $r->validate(['account_id' => ['required_without:provider', 'nullable', 'integer'], 'provider' => ['required_without:account_id', 'nullable', 'in:microsoft,google'],
            'scope' => ['required', 'in:personal,agency']]);
        if (! $this->may($r, self::PERSONAL_ROLES)) return response()->json(['message' => 'Calendar connections are for admins, directors and the KiddieTrac team.'], 403);
        $uid = (int) $r->user()->id;
        if (empty($d['account_id'])) return $this->connectCalendarOnly($r, $uid, (string) $d['provider'], $d['scope']);
        if (! $this->may($r, self::EMAIL_ROLES)) return response()->json(['message' => 'Connect your calendar with Microsoft or Google instead.'], 403);
        $aid = null;
        if ($d['scope'] === 'agency') {
            $aid = $this->agencyId($r);
            if (! $aid || ! $this->may($r, self::AGENCY_SET)) return response()->json(['message' => 'Only an agency admin can connect the agency calendar.'], 403);
        }
        $a = DB::table('email_accounts')->where('id', $d['account_id'])->where('user_id', $uid)->whereNull('shared_scope')->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'That mailbox is not one of yours.'], 404);
        $cal = ['scope' => $d['scope'], 'agency_id' => $aid];
        if ($a->provider === 'microsoft') {
            if (! MicrosoftMailbox::configured()) return response()->json(['message' => 'Microsoft sign-in is not set up on this server. Please contact KiddieTrac support.'], 422);
            return response()->json(['url' => MicrosoftMailbox::authorizeUrl($a, $uid, 'calendar', $cal)]);
        }
        if ($a->provider === 'google') {
            if (! GoogleMailbox::configured()) return response()->json(['message' => 'Google sign-in is not set up on this server. Please contact KiddieTrac support.'], 422);
            return response()->json(['url' => GoogleMailbox::authorizeUrl($a, $uid, 'calendar', $cal)]);
        }
        return response()->json(['message' => 'Only Microsoft 365 / Outlook and Google mailboxes have a calendar KiddieTrac can connect.'], 422);
    }

    /** A calendar-only sign-in (no mail): the sales team, and anyone else without the Email client. Personal only. */
    private function connectCalendarOnly(Request $r, int $uid, string $provider, string $scope): JsonResponse
    {
        if ($scope !== 'personal') return response()->json(['message' => 'Only an agency admin can connect the agency calendar, from a mailbox in Email.'], 403);
        if ($provider === 'microsoft' && ! MicrosoftMailbox::configured()) return response()->json(['message' => 'Microsoft sign-in is not set up on this server. Please contact KiddieTrac support.'], 422);
        if ($provider === 'google' && ! GoogleMailbox::configured()) return response()->json(['message' => 'Google sign-in is not set up on this server. Please contact KiddieTrac support.'], 422);
        $a = DB::table('email_accounts')->where('user_id', $uid)->where('shared_scope', 'calendar')->where('provider', $provider)->whereNull('deleted_at')->orderByDesc('id')->first();
        if (! $a) {
            $id = DB::table('email_accounts')->insertGetId(['user_id' => $uid, 'agency_id' => null, 'shared_scope' => 'calendar', 'display_name' => 'Calendar',
                'email_address' => '', 'provider' => $provider, 'auth_type' => $provider, 'is_default' => 0, 'status' => 'pending',
                'signature_enabled' => 0, 'ooo_enabled' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $a = DB::table('email_accounts')->find($id);
        }
        $cal = ['scope' => 'personal', 'agency_id' => null];
        return response()->json(['url' => $provider === 'google' ? GoogleMailbox::authorizeUrl($a, $uid, 'calendar', $cal) : MicrosoftMailbox::authorizeUrl($a, $uid, 'calendar', $cal)]);
    }

    public function finish(Request $r): JsonResponse
    {
        $nonce = (string) $r->input('nonce', '');
        if (! preg_match('/^g?[a-f0-9]{40}$/', $nonce)) return response()->json(['message' => 'This sign-in link is not valid.'], 422);
        $uid = (int) $r->user()->id;
        $res = $nonce[0] === 'g' ? GoogleMailbox::finish($uid, $nonce) : MicrosoftMailbox::finish($uid, $nonce);
        [$ok, $msg, $accountId] = $res;
        $cal = $res[3] ?? null;
        if (! $ok) return response()->json(['message' => $msg], 422);
        $a = DB::table('email_accounts')->where('id', $accountId)->first();
        if (! $a || ! is_array($cal) || ! ExternalCalendar::granted($a)) {
            return response()->json(['message' => 'The mailbox was signed in again, but the calendar was not allowed, so nothing else changed.'], 422);
        }
        $scope = $cal['scope'] === 'agency' ? 'agency' : 'personal';
        $aid = $scope === 'agency' ? (int) ($cal['agency_id'] ?? 0) : null;
        // The agency is the one chosen when the button was pressed - and the person must still be its admin now.
        if ($scope === 'agency' && (! $aid || $aid !== $this->agencyId($r) || ! $this->may($r, self::AGENCY_SET))) {
            return response()->json(['message' => 'Switch to that agency as its admin, then connect its calendar again.'], 403);
        }
        $row = ['user_id' => $uid, 'email_account_id' => (int) $a->id, 'provider' => $a->provider, 'address' => $a->email_address,
            'status' => 'connected', 'status_detail' => null, 'updated_at' => now()];
        $old = $scope === 'agency' ? ExternalCalendar::agency($aid) : ExternalCalendar::personal($uid);
        if ($old) {
            if ((int) $old->email_account_id !== (int) $a->id) {
                DB::table('email_accounts')->where('id', $old->email_account_id)->where('shared_scope', 'calendar')->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'oauth_token' => null, 'status' => 'removed', 'updated_at' => now()]);
            }
            DB::table('calendar_links')->where('id', $old->id)->update($row);
            $id = (int) $old->id;
            ExternalCalendar::forget($id);
        } else {
            $id = (int) DB::table('calendar_links')->insertGetId($row + ['scope' => $scope, 'agency_id' => $aid, 'created_at' => now()]);
        }
        Audit::write(['user_id' => $uid, 'agency_id' => $aid ?: $this->agencyId($r), 'action' => 'calendar.connected', 'entity_type' => 'calendar_link', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Connected ' . ($scope === 'agency' ? 'the agency calendar' : 'their own calendar') . ' (' . $a->email_address . ', ' . ($a->provider === 'google' ? 'Google' : 'Microsoft') . ')']),
            'created_at' => now()]);
        return response()->json(['ok' => true, 'scope' => $scope, 'link' => ExternalCalendar::present(DB::table('calendar_links')->find($id))]);
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $uid = (int) $r->user()->id;
        $link = DB::table('calendar_links')->where('id', $id)->whereNull('deleted_at')->first();
        $mine = $link && $link->scope === 'personal' && (int) $link->user_id === $uid;
        $agencyOk = $link && $link->scope === 'agency' && (int) $link->agency_id === (int) $this->agencyId($r) && $this->may($r, self::AGENCY_SET);
        if (! $mine && ! $agencyOk) return response()->json(['message' => 'That calendar is not available.'], 404);
        DB::table('calendar_links')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        ExternalCalendar::forget($id);
        // A calendar-only sign-in exists for this link alone: it goes too (token and all), unless another link uses it.
        $acct = DB::table('email_accounts')->where('id', $link->email_account_id)->where('shared_scope', 'calendar')->whereNull('deleted_at')->first();
        if ($acct && ! DB::table('calendar_links')->where('email_account_id', $acct->id)->whereNull('deleted_at')->exists()) {
            DB::table('email_accounts')->where('id', $acct->id)->update(['deleted_at' => now(), 'oauth_token' => null, 'status' => 'removed', 'updated_at' => now()]);
            \Illuminate\Support\Facades\Cache::forget('kt_mbx_at:' . $acct->id);
            \Illuminate\Support\Facades\Cache::forget('kt_gmbx_at:' . $acct->id);
        }
        // The meetings already written stay on that calendar; KiddieTrac just stops moving them.
        Audit::write(['user_id' => $uid, 'agency_id' => $link->agency_id ?: $this->agencyId($r), 'action' => 'calendar.disconnected', 'entity_type' => 'calendar_link', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Disconnected ' . ($link->scope === 'agency' ? 'the agency calendar' : 'their own calendar') . ' (' . $link->address . ')']),
            'created_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function events(Request $r): JsonResponse
    {
        if (! $this->may($r, self::PERSONAL_ROLES)) return response()->json(['events' => [], 'calendars' => []]);
        $uid = (int) $r->user()->id;
        $aid = $this->agencyId($r);
        $tz = $aid ? \App\Support\AgencyTime::tz($aid) : \App\Support\BookingMail::TZ;
        try {
            $from = Carbon::parse((string) $r->query('from', now($tz)->toDateString()), $tz)->startOfDay();
            $to = Carbon::parse((string) $r->query('to', now($tz)->addDays(7)->toDateString()), $tz)->endOfDay();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Choose the dates again.'], 422);
        }
        if ($to->lte($from)) $to = $from->copy()->endOfDay();
        if ($from->diffInDays($to, true) > ExternalCalendar::MAX_DAYS) $to = $from->copy()->addDays(ExternalCalendar::MAX_DAYS)->endOfDay();
        $out = []; $cals = [];
        $links = [];
        if ($p = ExternalCalendar::personal($uid)) $links[] = [$p, false];
        if ($aid && $this->may($r, self::AGENCY_READ) && ($ag = ExternalCalendar::agency($aid)) && (! $p || (int) $ag->email_account_id !== (int) $p->email_account_id)) $links[] = [$ag, true];
        foreach ($links as [$link, $busyOnly]) {
            $res = ExternalCalendar::events($link, $from->copy()->utc(), $to->copy()->utc(), $busyOnly);
            $cals[] = ['scope' => $link->scope, 'provider' => $link->provider, 'address' => $link->address, 'ok' => $res['ok'], 'message' => $res['message']];
            foreach ($res['events'] as $e) $out[] = $e;
        }
        usort($out, fn ($a, $b) => strcmp((string) $a['start'], (string) $b['start']));
        return response()->json(['events' => $out, 'calendars' => $cals, 'timezone' => $tz]);
    }
}
