<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Calls INTO the toll-free line (2026-10-23): what the dialer shows as Missed calls and
 * Voicemail, and the count on the 📞 badge.
 *
 * WHO SEES WHAT: platform admins see every call and voicemail on the line; anyone else
 * only the departments whose call-menu alerts list them (Platform → Phone & voicemail).
 * The general mailbox (no department) is platform admins only. Nobody else sees any.
 */
class InboundCalls
{
    /** Outcomes rank: a later callback never turns an answered call back into a missed one. */
    private const RANK = ['ringing' => 0, 'missed' => 1, 'answered' => 2, 'voicemail' => 3];

    /** A ringing call that never heard back is treated as missed after this long. */
    public const STALE_MINUTES = 3;

    public static function note(string $callSid, ?string $from, ?string $department, string $outcome, ?int $voicemailId = null): void
    {
        if ($callSid === '' || ! isset(self::RANK[$outcome])) return;
        try {
            $have = DB::table('inbound_calls')->where('call_sid', $callSid)->first();
            if (! $have) {
                DB::table('inbound_calls')->insertOrIgnore(['call_sid' => substr($callSid, 0, 100), 'from_number' => $from ? substr($from, 0, 40) : null,
                    'department' => $department ? substr($department, 0, 80) : null, 'outcome' => $outcome, 'voicemail_id' => $voicemailId,
                    'created_at' => now(), 'updated_at' => now()]);
                return;
            }
            $upd = ['updated_at' => now()];
            if (self::RANK[$outcome] >= (self::RANK[$have->outcome] ?? 0)) $upd['outcome'] = $outcome;
            if ($voicemailId) $upd['voicemail_id'] = $voicemailId;
            if (! $have->from_number && $from) $upd['from_number'] = substr($from, 0, 40);
            if (! $have->department && $department) $upd['department'] = substr($department, 0, 80);
            DB::table('inbound_calls')->where('id', $have->id)->update($upd);
        } catch (\Throwable $e) {
            Log::warning('Inbound call note failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The departments this person may see: ['all' => true] for a platform admin, otherwise
     * ['all' => false, 'names' => [...]] (possibly empty = nothing).
     */
    public static function scope(int $userId): array
    {
        $admin = DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($admin) return ['all' => true, 'names' => []];
        $names = [];
        foreach (PhoneMenu::config()['departments'] as $d) {
            if (in_array($userId, $d['alert_user_ids'] ?? [], true)) $names[] = $d['name'];
        }
        return ['all' => false, 'names' => $names];
    }

    public static function allowed(array $scope): bool
    {
        return $scope['all'] || $scope['names'];
    }

    /** Limit a query on a table with a `department` column to the scope (fails closed). */
    public static function limit($q, array $scope, string $col)
    {
        if ($scope['all']) return $q;
        return $scope['names'] ? $q->whereIn($col, $scope['names']) : $q->whereRaw('1 = 0');
    }

    /** Missed = nobody answered (with or without a voicemail), including stale "ringing". */
    public static function missedQuery(array $scope)
    {
        $q = DB::table('inbound_calls')->where('created_at', '>=', now()->subDays(60))
            ->where(function ($w) {
                $w->whereIn('outcome', ['missed', 'voicemail'])
                  ->orWhere(fn ($r) => $r->where('outcome', 'ringing')->where('created_at', '<', now()->subMinutes(self::STALE_MINUTES)));
            });
        return self::limit($q, $scope, 'department');
    }

    public static function voicemailQuery(array $scope)
    {
        $q = DB::table('voicemails')->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(90));
        return self::limit($q, $scope, 'department');
    }

    /* The badge: voicemails nobody has heard and missed calls (without a voicemail) that
       arrived since this person last OPENED the phone (2026-10-23, Anthony: "clear the
       indicator once I open it"). Opening clears the number; an unplayed voicemail keeps its
       "new" dot in the list until someone plays it. */
    public static function counts(int $userId): array
    {
        $scope = self::scope($userId);
        if (! self::allowed($scope)) return ['allowed' => false, 'voicemail' => 0, 'missed' => 0, 'total' => 0];
        $row = DB::table('dialer_seen')->where('user_id', $userId)->first();
        $seen = $row->missed_seen_at ?? null;
        $vmSeen = $row->vm_seen_at ?? null;
        $vmq = self::voicemailQuery($scope)->whereNull('listened_at');
        if ($vmSeen) $vmq->where('created_at', '>', $vmSeen);
        $vm = $vmq->count();
        $since = $seen ?: now()->subDays(7);
        $missed = self::missedQuery($scope)->whereNull('voicemail_id')->where('created_at', '>', $since)->count();
        return ['allowed' => true, 'voicemail' => $vm, 'missed' => $missed, 'total' => $vm + $missed, 'missed_seen_at' => $seen, 'latest' => self::latest($scope)];
    }

    /** The newest voicemail and missed call in scope - what the "new voicemail" popup shows. */
    public static function latest(array $scope): array
    {
        $out = ['voicemail' => null, 'missed' => null];
        try {
            $v = self::voicemailQuery($scope)->orderByDesc('id')->first(['id', 'from_number', 'department', 'duration', 'listened_at', 'created_at']);
            if ($v) $out['voicemail'] = ['id' => (int) $v->id, 'from' => (string) $v->from_number, 'from_pretty' => $v->from_number ? Dialer::pretty((string) $v->from_number) : 'Unknown caller',
                'department' => $v->department, 'duration' => (int) $v->duration, 'heard' => (bool) $v->listened_at, 'created_at' => $v->created_at];
            $m = self::missedQuery($scope)->whereNull('voicemail_id')->orderByDesc('id')->first(['id', 'from_number', 'department', 'created_at']);
            if ($m) $out['missed'] = ['id' => (int) $m->id, 'from' => (string) $m->from_number, 'from_pretty' => $m->from_number ? Dialer::pretty((string) $m->from_number) : 'Unknown caller',
                'department' => $m->department, 'created_at' => $m->created_at];
        } catch (\Throwable $e) {
        }
        return $out;
    }

    public static function markSeen(int $userId): void
    {
        DB::table('dialer_seen')->updateOrInsert(['user_id' => $userId], ['missed_seen_at' => now(), 'vm_seen_at' => now()]);
    }
}
