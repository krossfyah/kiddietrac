<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Reminds an educator when they log care for a child who is not signed in.
 *
 * Catch-up logging is allowed — the day does not always permit logging in the moment —
 * but a care log is part of a child's official record, so entries that do not line up
 * with attendance need to be visible. Admins and directors are BCC'd rather than To'd:
 * it is supervision, not a summons.
 *
 * At most ONE reminder per educator per day. A morning of catching up must not send six
 * emails; that is exactly how the missed-message flood happened.
 */
class AttendanceReminder
{
    public static function maybeSend(int $educatorId, array $childIds, $request = null, int $sinceMinutes = 10): void
    {
        $agencyId = self::agencyOf($educatorId);
        if (! $agencyId || ! self::enabled($agencyId)) {
            return;
        }
        if (Suppression::isUser($educatorId)) {
            return;
        }

        // Once a day. audit_logs is the ledger — no new table for a rate limit.
        $already = DB::table('audit_logs')->where('user_id', $educatorId)
            ->where('action', 'care.attendance_reminder')
            ->where('created_at', '>=', now()->startOfDay())->exists();
        if ($already) {
            return;
        }

        $educator = DB::table('users')->where('id', $educatorId)
            ->first(['first_name', 'last_name', 'email']);
        if (! $educator || ! filled($educator->email)) {
            return;
        }

        $tz = DB::table('agencies')->where('id', $agencyId)->value('timezone') ?: 'America/Toronto';
        $rows = DB::table('daily_care_logs as l')->join('children as c', 'c.id', '=', 'l.child_id')
            ->where('l.recorded_by_id', $educatorId)->whereIn('l.child_id', $childIds)
            ->where('l.created_at', '>=', now()->subMinutes($sinceMinutes))
            ->orderByDesc('l.id')->limit(8)
            ->get(['l.log_type', 'l.occurred_at', 'c.first_name', 'c.last_name']);
        if ($rows->isEmpty()) {
            return;
        }

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $items = '';
        foreach ($rows as $r) {
            $when = Carbon::parse($r->occurred_at, 'UTC')->setTimezone($tz)->format('D d M, g:i a');
            $items .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #EEF2F6;font-size:14px;color:#0F172A;">'
                .$e(trim($r->first_name.' '.$r->last_name)).'</td>'
                .'<td style="padding:8px 12px;border-bottom:1px solid #EEF2F6;font-size:14px;color:#475569;">'
                .$e(ucfirst($r->log_type)).'</td>'
                .'<td style="padding:8px 12px;border-bottom:1px solid #EEF2F6;font-size:14px;color:#475569;white-space:nowrap;">'
                .$e($when).'</td></tr>';
        }

        $first = $e($educator->first_name ?: 'there');
        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
          .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 14px;">Hi '.$first.',</td></tr>'
          .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 14px;">'
          .'You logged daily moments today for children who were not signed in at the time. '
          .'Care logs are part of each child&rsquo;s official record, so they need to line up with attendance &mdash; '
          .'a log against a child who is signed out can look like an error to a parent or an inspector.'
          .'</td></tr>'
          .'<tr><td style="padding:0 0 6px;font-size:12px;font-weight:800;color:#64748B;letter-spacing:.06em;">ENTRIES TO CHECK</td></tr>'
          .'<tr><td style="padding:0 0 16px;"><table cellpadding="0" cellspacing="0" border="0" width="100%" '
          .'style="border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;">'
          .'<tr><td style="padding:8px 12px;background:#F8FAFC;font-size:11px;font-weight:800;color:#64748B;">CHILD</td>'
          .'<td style="padding:8px 12px;background:#F8FAFC;font-size:11px;font-weight:800;color:#64748B;">ENTRY</td>'
          .'<td style="padding:8px 12px;background:#F8FAFC;font-size:11px;font-weight:800;color:#64748B;">TIME</td></tr>'
          .$items.'</table></td></tr>'
          .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 8px;">'
          .'<strong>What to do</strong><br>'
          .'&bull; Sign the child in before logging, so the record matches the day.<br>'
          .'&bull; Catching up after sign-out is fine &mdash; confirm the prompt and the entry is marked as a catch-up.<br>'
          .'&bull; If a child was here but never signed in, ask your director to correct the attendance record.'
          .'</td></tr></table>';

        $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'   => 'ATTENDANCE REMINDER',
            'title'     => 'Daily moments logged for children who were signed out',
            'preheader' => 'A few entries today were logged while the child was not signed in.',
        ]);

        // Admins and directors in BCC — supervision without singling anyone out.
        $bcc = Audience::excludeOff(
            DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', true)->where('ra.agency_id', $agencyId)
                ->whereIn('ra.role', ['agency_admin', 'centre_director'])
                ->whereNull('u.deleted_at')->whereNotNull('u.email')
        )->pluck('u.email')->unique()->values()
            // An integration inbox is not a person to supervise. integration+… holds an
            // agency_admin role, so it matches this query without this filter.
            ->reject(function ($a) {
                $a = mb_strtolower(trim((string) $a));

                return str_contains($a, 'integration+')
                    || str_starts_with($a, 'noreply@') || str_starts_with($a, 'no-reply@');
            })->values()->all();

        Mail::html($html, function ($m) use ($educator, $bcc) {
            $m->to($educator->email, trim($educator->first_name.' '.$educator->last_name) ?: null)
              ->subject('Reminder: daily moments logged for signed-out children');
            if ($bcc) {
                $m->bcc($bcc);
            }
        });

        DB::table('notifications')->insert([
            'user_id' => $educatorId,
            'type' => 'attendance',
            'title' => 'Children not signed in',
            'body' => 'You logged daily moments for children who were not signed in. Please sign children in before logging.',
            'data' => json_encode(['link' => '#care']),
            'created_at' => now(),
        ]);

        \App\Support\Audit::write([
            'user_id' => $educatorId, 'agency_id' => $agencyId,
            'action' => 'care.attendance_reminder', 'entity_type' => 'user', 'entity_id' => $educatorId,
            'payload' => json_encode(['entries' => $rows->count(), 'bcc' => count($bcc)]),
            'created_at' => now(),
        ]);
    }

    private static function enabled(int $agencyId): bool
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);

        return ($s['attendance_reminders'] ?? true) ? true : false;   // default ON
    }

    private static function agencyOf(int $userId): ?int
    {
        $direct = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->whereNotNull('agency_id')->value('agency_id');
        if ($direct) {
            return (int) $direct;
        }
        $viaCentre = DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $userId)->where('ra.active', true)->value('c.agency_id');

        return $viaCentre ? (int) $viaCentre : null;
    }
}
