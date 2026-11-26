<?php

namespace App\Support;

use App\Http\Controllers\Api\AbsenceController;
use App\Models\User;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * PARENTS CALL IN: ABSENT OR LATE BY PHONE (2026-11-24).
 *
 * Anthony: "setup the dialer to have parents call in to the kiddietrac number to log a late, absent etc for their
 * child - how can we safely and securely tie this back to their childs account?" His choices: recognised parents
 * first; a texted code, or a phone PIN for landlines; and when a caller can't prove who they are, "leave a voice
 * mail that gets sent to the agency admin/director to quickly approve the absent/late via email/phone push".
 *
 * Why caller ID alone is never enough: it can be faked, and a fake "absent" would stop Safe Arrival checking on a
 * child. So the number only decides whether to OFFER the parent menu. Nothing about a child (not even a first name)
 * is said until the caller proves they hold the phone on file (a 4-digit code texted to it during the call) or know
 * that parent's Phone PIN (set in the app: Running late → Call in instead). Three wrong tries and the caller can only
 * leave a message, which a director/admin approves or declines (phone_reports).
 *
 * A verified report goes through exactly the app's path: AbsenceController::store (centre + family told) or
 * SafeArrival::logLate (Safe Arrival waits, educators told). Test agencies never text or alert anyone.
 */
final class ParentLine
{
    public const LINE_AGENCY = 2;   // the Telnyx account that owns the toll-free number
    public const ACTIONS = ['1' => 'absent_today', '2' => 'late_arrival', '3' => 'late_pickup', '4' => 'absent_tomorrow'];
    public const LABEL = ['absent_today' => 'Absent today', 'late_arrival' => 'Dropping off late today',
        'late_pickup' => 'Picking up late today', 'absent_tomorrow' => 'Absent tomorrow'];
    public const REASONS = ['1' => 'sick', '2' => 'appointment', '3' => 'holiday', '4' => 'family', '5' => 'other'];

    public static function enabled(): bool
    {
        return (string) PlatformSettings::get('voice.parent_line', '1') === '1';
    }

    /** Ten digits of a North American number, or ''. */
    public static function digits(?string $n): string
    {
        $d = preg_replace('/\D/', '', (string) $n);
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);

        return strlen($d) === 10 ? $d : '';
    }

    public static function masked(?string $n): string
    {
        $d = self::digits($n);

        return $d === '' ? '' : '(' . substr($d, 0, 3) . ') •••-' . substr($d, 6);
    }

    /** Active parent accounts whose phone on file is this number (their own, or their family's main number). */
    public static function match(string $from): array
    {
        $d = self::digits($from);
        if ($d === '' || $d === '8554009996') return [];
        $eq = fn (string $col) => "RIGHT(REGEXP_REPLACE(COALESCE({$col}, ''), '[^0-9]', ''), 10) = ?";
        $ids = DB::table('users as u')->whereNull('u.deleted_at')->where('u.status', 'active')
            ->where(fn ($q) => $q->whereRaw($eq('u.phone'), [$d])->orWhereRaw($eq('u.phone2'), [$d]))
            ->whereExists(fn ($q) => $q->from('guardians as g')->whereColumn('g.user_id', 'u.id'))
            ->pluck('u.id')->all();
        $fam = DB::table('families')->whereRaw($eq('primary_phone'), [$d])->pluck('id')->all();
        if ($fam) {
            $ids = array_merge($ids, DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->whereIn('g.family_id', $fam)->whereNull('u.deleted_at')->where('u.status', 'active')->pluck('u.id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** These parents' children at an open centre (the same list as their Running late screen), max 8 for the keypad. */
    public static function children(array $userIds): array
    {
        if (! $userIds) return [];
        $rows = DB::table('guardians as g')
            ->join('children as c', 'c.family_id', '=', 'g.family_id')
            ->join('families as fa', 'fa.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'fa.centre_id')
            ->tap(fn ($q) => Live::centres(Live::families($q, 'fa'), 'ce'))
            ->whereIn('g.user_id', $userIds)->whereNull('c.deleted_at')
            ->orderBy('c.first_name')->orderBy('c.id')
            ->get(['c.id', 'c.first_name', 'c.preferred_name', 'ce.id as centre_id', 'ce.agency_id', 'g.user_id']);
        $out = [];
        foreach ($rows as $r) {
            /* The line is part of the SMS & voice add-on (2026-11-26): a child at an agency without it is not offered,
               so the caller hears the same "no children found" as an unknown number. */
            if (! \App\Support\Features::on((int) $r->agency_id, 'sms')) continue;
            $out[(int) $r->id] ??= ['child_id' => (int) $r->id, 'first_name' => trim((string) ($r->preferred_name ?: $r->first_name)),
                'centre_id' => (int) $r->centre_id, 'agency_id' => (int) $r->agency_id, 'guardian_user_id' => (int) $r->user_id];
        }

        return array_slice(array_values($out), 0, 8);
    }

    /* ── the call's own state (server side, keyed by Telnyx's CallSid: nothing the caller sends can set it) ── */

    public static function state(string $sid): ?array
    {
        return $sid === '' ? null : Cache::get('kt-pline:' . hash('sha256', $sid));
    }

    public static function save(string $sid, array $s): void
    {
        if ($sid !== '') Cache::put('kt-pline:' . hash('sha256', $sid), $s, now()->addMinutes(40));
    }

    private static function codeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /** Text a one-call code to the number calling. 'sent' | 'test' | 'opted_out' | 'limit' | 'not_mobile' | 'failed'. */
    public static function sendCode(string $sid, string $from, array $agencyIds): string
    {
        try {
            if ($agencyIds && ! array_filter($agencyIds, fn ($a) => ! TestAgency::blocks((int) $a))) return 'test';
            $to = Telnyx::e164($from);
            if (! preg_match('/^\+1[2-9]\d{2}[2-9]\d{6}$/', $to)) return 'not_mobile';
            $kw = DB::table('sms_messages')->where('to_phone', $to)->whereIn('category', ['inbound_stop', 'inbound_start'])->orderByDesc('id')->value('category');
            if ($kw === 'inbound_stop') return 'opted_out';
            if (DB::table('sms_messages')->where('to_phone', $to)->where('category', 'phone_code')->where('created_at', '>=', now()->subHour())->count() >= 3) return 'limit';
            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $body = 'KiddieTrac: your phone code is ' . $code . '. It only works on the call you are on now. If you did not just call us, you can ignore this text.';
            $r = SmsGateway::deliver(self::LINE_AGENCY, $to, $body);
            DB::table('sms_messages')->insert([
                'agency_id' => self::LINE_AGENCY, 'direction' => 'out', 'to_user_id' => null, 'to_phone' => $to,
                'body' => str_replace($code, '••••', $body),   // the code itself is never stored
                'category' => 'phone_code', 'provider' => $r['provider'] ?? null, 'provider_ref' => $r['ref'] ?? null,
                'status' => ! empty($r['ok']) ? 'sent' : 'failed', 'error' => $r['error'] ?? null, 'sent_at' => now(), 'created_at' => now(),
            ]);
            if (empty($r['ok'])) return 'failed';
            Cache::put('kt-pline-code:' . hash('sha256', $sid), self::codeHash($code), now()->addMinutes(10));

            return 'sent';
        } catch (\Throwable $e) {
            Log::warning('Parent line code text failed', ['error' => $e->getMessage()]);
            return 'failed';
        }
    }

    public static function codeMatches(string $sid, string $digits): bool
    {
        $h = Cache::get('kt-pline-code:' . hash('sha256', $sid));
        if (! $h || ! hash_equals((string) $h, self::codeHash($digits))) return false;
        Cache::forget('kt-pline-code:' . hash('sha256', $sid));   // one use

        return true;
    }

    public static function anyPin(array $userIds): bool
    {
        return $userIds && DB::table('users')->whereIn('id', $userIds)->whereNotNull('phone_pin_hash')->exists();
    }

    /** The parent (of those matched) whose Phone PIN this is. Five misses in a day locks a PIN until tomorrow. */
    public static function pinUser(array $userIds, string $pin): ?int
    {
        if (! $userIds) return null;
        $users = DB::table('users')->whereIn('id', $userIds)->whereNotNull('phone_pin_hash')->get(['id', 'phone_pin_hash']);
        foreach ($users as $u) {
            if (Cache::get('kt-ppin-lock:' . $u->id)) continue;
            if (Hash::check($pin, (string) $u->phone_pin_hash)) {
                Cache::forget('kt-ppin-fail:' . $u->id);
                return (int) $u->id;
            }
        }
        foreach ($users as $u) {
            $n = (int) Cache::get('kt-ppin-fail:' . $u->id, 0) + 1;
            Cache::put('kt-ppin-fail:' . $u->id, $n, now()->endOfDay());
            if ($n >= 5 && Cache::add('kt-ppin-lock:' . $u->id, 1, now()->endOfDay())) {
                self::audit(null, (int) $u->id, 'phone_line.pin_locked', null, 'Phone PIN locked for the rest of the day after 5 wrong tries on a call');
            }
        }

        return null;
    }

    /** Why a PIN is refused, or null. */
    public static function weakPin(string $pin, ?string $phone): ?string
    {
        if (! preg_match('/^\d{4}$/', $pin)) return 'Use exactly 4 digits.';
        if (count(array_unique(str_split($pin))) === 1) return 'Pick a PIN that isn\'t one digit repeated.';
        if (str_contains('0123456789', $pin) || str_contains('9876543210', $pin)) return 'Pick a PIN that isn\'t a run of digits.';
        if (in_array($pin, ['1212', '1122', '2580', '0852', '1004', '2000', '6969', '1010'], true)) return 'That PIN is too easy to guess.';
        $d = self::digits($phone);
        if ($d !== '' && substr($d, -4) === $pin) return 'Don\'t use the last 4 digits of your phone number.';
        if (preg_match('/^(19|20)\d\d$/', $pin)) return 'Don\'t use a year.';

        return null;
    }

    /** "HH:MM" for a late notice from a keypad answer (1/2/3 = presets, or 4 digits like 1030), or null. */
    public static function lateTime(array $kid, string $action, string $digits): ?string
    {
        $tz = AgencyTime::tz($kid['agency_id']);
        $now = Carbon::now($tz);
        $round = fn (Carbon $t) => $t->copy()->addMinutes((5 - $t->minute % 5) % 5)->second(0);
        if (strlen($digits) === 1) {
            if ($action === 'late_arrival') {
                $m = ['1' => 30, '2' => 60, '3' => 120][$digits] ?? null;

                return $m ? $round($now->copy()->addMinutes($m))->format('H:i') : null;
            }
            $m = ['1' => 15, '2' => 30, '3' => 60][$digits] ?? null;
            if (! $m) return null;
            $base = $now;
            try {
                $dep = SafeDeparture::today($kid['agency_id'])[$kid['child_id']] ?? null;
                if ($dep && ! empty($dep['expected'])) $base = Carbon::parse($dep['expected'], $tz);
            } catch (\Throwable $e) {
            }

            return $round($base->max($now)->copy()->addMinutes($m))->format('H:i');
        }
        if (! preg_match('/^(\d{1,2})(\d{2})$/', $digits, $mm)) return null;
        [$h, $i] = [(int) $mm[1], (int) $mm[2]];
        if ($h >= 1 && $h <= 6) $h += 12;   // "0130" during the day is 1:30 PM
        if ($h > 23 || $i > 59) return null;

        return sprintf('%02d:%02d', $h, $i);
    }

    public static function spoken(string $hm): string
    {
        return Carbon::createFromFormat('H:i', $hm)->format('g:i A');
    }

    /**
     * Record it, the same way the app does. [ok, what to say]. $via: code | pin | message (approved by staff).
     * $forDate: the day the caller meant (a message approved later still applies to the day it was left).
     */
    public static function apply(array $kid, string $action, ?string $reason, ?string $by, int $guardianId, string $via, ?string $forDate = null, ?int $staffId = null): array
    {
        $first = $kid['first_name'];
        $tz = AgencyTime::tz($kid['agency_id']);
        $how = $via === 'message' ? 'by phone message, confirmed by the centre' : 'by phone (' . ($via === 'pin' ? 'phone PIN' : 'texted code') . ')';
        try {
            if ($action === 'absent_today' || $action === 'absent_tomorrow') {
                $date = $forDate ?: Carbon::now($tz)->toDateString();
                if ($action === 'absent_tomorrow' && ! $forDate) $date = Carbon::now($tz)->addDay()->toDateString();
                $user = User::find($guardianId);
                if (! $user) return [false, 'We could not find your account.'];
                $req = Request::create('/api/v1/parent/absences', 'POST', ['child_id' => $kid['child_id'],
                    'reason' => in_array($reason, self::REASONS, true) ? $reason : 'other', 'note' => 'Reported ' . $how . '.', 'date' => $date]);
                $req->setUserResolver(fn () => $user);
                $req->headers->set('X-Active-Agency-Id', (string) $kid['agency_id']);
                $res = app(AbsenceController::class)->store($req);
                if ($res->getStatusCode() !== 200) return [false, 'Sorry, we could not record that. Please contact your centre.'];
                self::audit($kid['agency_id'], $staffId ?: $guardianId, 'phone_line.absence', $kid['child_id'],
                    "{$first} reported absent " . ($action === 'absent_tomorrow' ? 'tomorrow' : 'today') . " ({$date}) {$how}");

                return [true, 'Thank you. ' . $first . ' is marked absent ' . ($action === 'absent_tomorrow' ? 'tomorrow' : 'today') . ', and the centre has been told.'];
            }
            $kind = $action === 'late_pickup' ? 'departure' : 'arrival';
            if (! $by) return [false, 'Sorry, we did not get a time.'];
            [$err] = SafeArrival::logLate($kid['agency_id'], $kid['child_id'], $kind, $by, 'Told us ' . $how, $guardianId, 'parent');
            if ($err) {
                return [false, $err === 'That child is not on today\'s list.' ? $first . ' is not expected at the centre today, so there is nothing to change.' : 'Sorry, we could not record that. ' . $err];
            }
            self::audit($kid['agency_id'], $staffId ?: $guardianId, 'phone_line.late', $kid['child_id'],
                "{$first}: " . ($kind === 'departure' ? 'late pickup by ' : 'late drop-off by ') . self::spoken($by) . " {$how}");

            return [true, 'Thank you. The centre knows ' . ($kind === 'departure' ? 'you will pick ' . $first . ' up by ' : $first . ' will be there by ') . self::spoken($by) . '.'];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return [false, 'Sorry, we could not record that. Please contact your centre.'];
        } catch (\Throwable $e) {
            Log::warning('Parent line apply failed', ['child' => $kid['child_id'], 'error' => $e->getMessage()]);
            return [false, 'Sorry, something went wrong. Please contact your centre.'];
        }
    }

    /* ── a message the caller could not verify: directors/admins approve it ── */

    /** Who decides on a message about these children: directors of their centres and the agency's admins. */
    public static function approvers(int $agencyId, array $kids): array
    {
        $cfg = array_merge(SafeArrival::settings($agencyId), ['notify_directors' => true, 'notify_admins' => true]);
        $by = [];
        foreach ($kids as $k) {
            foreach (SafeArrival::recipients($agencyId, (int) $k['centre_id'], null, $cfg, false, true) as $uid) $by[(int) $uid][] = $k['first_name'];
        }

        return $by;   // user id => the children of this message they look after
    }

    /** Bell + push, and email, to each approver. Nothing for a test agency or an agency with notifications off. */
    public static function notifyApprovers(int $reportId): int
    {
        $r = DB::table('phone_reports')->where('id', $reportId)->first();
        if (! $r) return 0;
        $agencyId = (int) $r->agency_id;
        if (TestAgency::blocks($agencyId) || Suppression::isAgency($agencyId)) return 0;
        $kids = json_decode((string) $r->candidates, true) ?: [];
        $what = self::LABEL[$r->action] ?? $r->action;
        if ($r->arrive_by) $what .= ' (by ' . self::spoken(substr((string) $r->arrive_by, 0, 5)) . ')';
        $link = '#safe-arrival?phone=' . $reportId;
        $n = 0;
        foreach (self::approvers($agencyId, $kids) as $uid => $names) {
            $names = array_values(array_unique($names));
            $who = implode(' or ', $names);
            $title = '📞 Phone message to approve';
            $body = 'A parent called about ' . $who . ': ' . $what . '. Their code check didn\'t pass, so listen and approve or decline.';
            try {
                Notify::write(['user_id' => $uid, 'agency_id' => $agencyId, 'type' => 'safe_arrival', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => $link, 'phone_report' => $reportId]), 'created_at' => now()]);
                $n++;
            } catch (\Throwable $e) {
                Log::warning('Phone message bell failed', ['report' => $reportId, 'user' => $uid, 'error' => $e->getMessage()]);
            }
            try {
                $u = DB::table('users')->where('id', $uid)->first(['email', 'first_name']);
                if ($u && filter_var((string) $u->email, FILTER_VALIDATE_EMAIL) && SafeArrival::staffWants((int) $uid, 'email')) {
                    $m = self::approvalMail($agencyId, (string) $u->first_name, $who, $what, $r, 'https://app.kiddietrac.com/dashboard.html' . $link);
                    AgencyMailer::forAgency($agencyId)->html($m['html'], function ($msg) use ($u, $m) {
                        $msg->to((string) $u->email, (string) (strtok(trim((string) $u->first_name), ' ') ?: '') ?: null)
                            ->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($m['subject']);
                    });
                }
            } catch (\Throwable $e) {
                Log::warning('Phone message email failed', ['report' => $reportId, 'user' => $uid, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    /** The approver's email (pure: used for the preview too). */
    public static function approvalMail(int $agencyId, string $first, string $who, string $what, object $r, string $url): array
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $tz = AgencyTime::tz($agencyId);
        $at = Carbon::parse($r->created_at)->timezone($tz)->format('g:i A');
        $first = (string) (strtok(trim($first), ' ') ?: '');
        $body = '<p class="kt-pl-p" style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#1F2937;">' . ($first !== '' ? 'Hi ' . $e($first) . ',' : 'Hi,') . '</p>'
            . '<p class="kt-pl-p" style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#1F2937;">A parent called the KiddieTrac line at ' . $e($at)
            . ' about <strong>' . $e($who) . '</strong> and asked for: <strong>' . $e($what) . '</strong>.</p>'
            . '<p class="kt-pl-p" style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#1F2937;">The number matches a parent on file (' . $e(self::masked($r->from_number))
            . '), but the caller couldn\'t confirm the texted code or their phone PIN, so nothing has been changed yet. Listen to their message, then approve or decline it.</p>'
            . '<p style="margin:0 0 18px;">' . EmailTemplate::button('Listen and decide', $url) . '</p>'
            . '<p class="kt-pl-s" style="margin:0;font-size:13px;line-height:1.5;color:#64748B;">If you approve, it is recorded exactly as if the parent had used the app, and the parent gets a text. If you decline, they get a text asking them to contact the centre.</p>';
        $html = EmailTemplate::wrap($agencyId, $body, ['eyebrow_icon' => '📞', 'eyebrow' => 'SAFE ARRIVAL', 'title' => 'A phone message needs you',
            'subtitle' => $who . ' · ' . $what, 'preheader' => 'A parent called about ' . $who . ': ' . $what . '. Listen and approve or decline.']);
        $dark = '.kt-pl-p{color:#E5E7EB!important}.kt-pl-s{color:#94A3B8!important}';
        $style = '<style>@media (prefers-color-scheme:dark){' . $dark . '}[data-ogsc] ' . str_replace('}.', '}[data-ogsc] .', $dark) . '</style>';
        $html = stripos($html, '</head>') !== false ? preg_replace('~</head>~i', $style . '</head>', $html, 1) : $style . $html;

        return ['subject' => 'Phone message: ' . $who . ' · ' . $what, 'html' => $html];
    }

    /** Text the caller how it ended (never for a test agency, never to a number that texted STOP). */
    public static function textOutcome(object $r, string $body): string
    {
        if (TestAgency::blocks((int) $r->agency_id) || Suppression::isAgency((int) $r->agency_id)) return 'held';
        $to = Telnyx::e164((string) $r->from_number);
        $kw = DB::table('sms_messages')->where('to_phone', $to)->whereIn('category', ['inbound_stop', 'inbound_start'])->orderByDesc('id')->value('category');
        if ($kw === 'inbound_stop') return 'opted out';
        $res = SmsGateway::deliver(self::LINE_AGENCY, $to, $body);
        DB::table('sms_messages')->insert(['agency_id' => self::LINE_AGENCY, 'direction' => 'out', 'to_user_id' => null, 'to_phone' => $to, 'body' => $body,
            'category' => 'phone_report', 'provider' => $res['provider'] ?? null, 'provider_ref' => $res['ref'] ?? null,
            'status' => ! empty($res['ok']) ? 'sent' : 'failed', 'error' => $res['error'] ?? null, 'sent_at' => now(), 'created_at' => now()]);

        return ! empty($res['ok']) ? 'sent' : 'failed';
    }

    public static function audit(?int $agencyId, ?int $userId, string $action, ?int $childId, string $summary): void
    {
        try {
            Audit::write(['user_id' => $userId, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => $childId ? 'child' : 'phone_line',
                'entity_id' => $childId, 'payload' => json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }
}
