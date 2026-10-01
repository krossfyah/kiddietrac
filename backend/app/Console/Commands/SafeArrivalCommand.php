<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\CheckEventNotifier;
use App\Services\EmailTemplate;
use App\Services\FcmService;
use App\Support\SafeArrival;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Safe Arrival, every 5 minutes (2026-09-29). See App\Support\SafeArrival.
 *
 *   overdue   -> ask the parents once ("Has Maya arrived? ... tap Not attending today"),
 *                through their own channels (push/in-app, email, SMS as they chose).
 *   no answer -> after escalate_after_minutes, alert the room's educators and the centre's
 *                directors (in-app + push), once.
 *   arrived / absence reported -> the check closes itself.
 *
 * Only agencies that switched it on. A parent message also honours the agency's
 * notification switch and the test-agency kill switch (Suppression) -- staff alerts do
 * not, because a missing child is the centre's business whatever the parents' settings.
 *
 *   php artisan kiddietrac:safe-arrival [--agency=ID] [--dry-run]
 */
class SafeArrivalCommand extends Command
{
    protected $signature = 'kiddietrac:safe-arrival {--agency= : one agency only} {--dry-run : print, send nothing}';

    protected $description = 'Safe Arrival: ask parents about children who have not arrived, then alert staff';

    public function handle(CheckEventNotifier $notifier): int
    {
        $dry = (bool) $this->option('dry-run');
        $agencies = DB::table('agencies')->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))
            ->pluck('id');
        $now = Carbon::now();

        foreach ($agencies as $agencyId) {
            $agencyId = (int) $agencyId;
            $cfg = SafeArrival::settings($agencyId);
            if (! $cfg['enabled']) {
                continue;
            }
            $date = \App\Support\AgencyTime::today($agencyId);
            $tz = \App\Support\AgencyTime::tz($agencyId);
            $quietParents = \App\Support\Suppression::isAgency($agencyId);

            foreach (SafeArrival::today($agencyId, null, null, $date) as $row) {
                $c = $row['check'];

                // Close an open check the moment the child arrives or is reported absent.
                if ($c && ! $c->resolved_at && in_array($row['state'], ['arrived', 'absent'], true)) {
                    if (! $dry) {
                        DB::table('safe_arrival_checks')->where('id', $c->id)->update([
                            'status' => $row['state'], 'resolution' => $row['state'],
                            'arrived_at' => $row['arrived_at'], 'resolved_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    $this->line(($dry ? '[dry] ' : '') . "closed {$row['name']}: {$row['state']}");
                    continue;
                }
                if (! in_array($row['state'], ['overdue', 'escalated'], true)) {
                    continue;
                }

                // 1. Ask the parents, once.
                if (! $c) {
                    $sent = $quietParents ? 0 : $this->askParents($notifier, $agencyId, $row, $tz, $dry);
                    if (! $dry) {
                        DB::table('safe_arrival_checks')->insertOrIgnore([
                            'agency_id' => $agencyId, 'centre_id' => $row['centre_id'], 'room_id' => $row['room_id'],
                            'child_id' => $row['child_id'], 'check_date' => $date, 'expected_time' => $row['expected'] . ':00',
                            'due_at' => $row['due_at'], 'status' => 'notified',
                            'parents_notified_at' => now(), 'parents_notified' => $sent,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    $this->line(($dry ? '[dry] ' : '') . "asked parents of {$row['name']} ({$sent})" . ($quietParents ? ' [agency notifications off]' : ''));
                    continue;
                }

                // 2. No answer: alert the room and the centre, once.
                if (! $c->escalated_at && $c->parents_notified_at
                    && $now->greaterThanOrEqualTo(Carbon::parse($c->parents_notified_at)->addMinutes($cfg['escalate_after_minutes']))) {
                    [$n, $unreached] = $this->alertStaff($agencyId, $row, $tz, $dry, (int) $c->parents_notified);
                    if (! $dry) {
                        $upd = ['status' => 'escalated', 'escalated_at' => now(), 'staff_alerted' => $n, 'updated_at' => now()];
                        if (\Illuminate\Support\Facades\Schema::hasColumn('safe_arrival_checks', 'staff_unreached')) {
                            $upd['staff_unreached'] = $unreached ? mb_substr(implode(', ', $unreached), 0, 500) : null;
                        }
                        DB::table('safe_arrival_checks')->where('id', $c->id)->update($upd);
                    }
                    $this->line(($dry ? '[dry] ' : '') . "escalated {$row['name']} to {$n} staff"
                        . ($unreached ? ' (no phone alert reached: ' . implode(', ', $unreached) . ')' : ''));
                }
            }
        }

        return self::SUCCESS;
    }

    private function askParents(CheckEventNotifier $notifier, int $agencyId, array $row, string $tz, bool $dry): int
    {
        $name = $row['first_name'];
        $at = Carbon::parse($row['expected'], $tz)->format('g:i A');
        $title = "🛡️ Has {$name} arrived?";
        $body = "{$name} was expected at {$row['centre_name']} around {$at} and hasn't been signed in. "
            . "If they're not coming in today, please tell us in the app (Not attending today). "
            . "If they're on the way, no need to reply.";

        $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $row['family_id'])->whereNull('u.deleted_at')
            ->get(['u.id', 'u.email', 'u.phone', 'u.first_name', 'u.last_name']);
        $n = 0;
        foreach ($guardians as $g) {
            $prefs = $notifier->prefsFor((int) $g->id);
            if ($dry) { $n++; continue; }
            try {
                if ($prefs['push']) {
                    \App\Support\Notify::write([
                        'user_id' => $g->id, 'type' => 'safe_arrival', 'title' => $title, 'body' => $body,
                        'data' => json_encode(['link' => '#today', 'child_id' => $row['child_id']]), 'created_at' => now(),
                    ]);
                    app(FcmService::class)->sendToUser((int) $g->id, $title, $body, '#today');
                }
                if ($prefs['email'] && $g->email) {
                    $this->email($agencyId, (string) $g->email, trim($g->first_name . ' ' . $g->last_name), $title, $body);
                }
                if ($prefs['sms'] && $g->phone) {
                    // 'checkin_reminder' is the kind of text this is: the agency's and the
                    // person's own switches for it apply inside sendOne.
                    app(\App\Http\Controllers\Api\SmsController::class)
                        ->sendOne($agencyId, (int) $g->id, (string) $g->phone, $body, 'checkin_reminder');
                }
                $n++;
            } catch (\Throwable $e) {
                Log::warning('Safe Arrival parent message failed', ['child' => $row['child_id'], 'user' => $g->id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    /**
     * @return array{0:int,1:string[]} how many staff were alerted, and the names of those
     *         no phone notification reached
     */
    private function alertStaff(int $agencyId, array $row, string $tz, bool $dry, int $parentsAsked): array
    {
        // The people standing in that room, and whoever runs the centre.
        $ids = DB::table('educator_rooms as er')->join('users as u', 'u.id', '=', 'er.user_id')
            ->where('er.room_id', $row['room_id'])->whereNull('u.deleted_at')->where('u.status', 'active')
            ->pluck('u.id')->all();
        $ids = array_merge($ids, DB::table('role_assignments')->where('active', 1)
            ->whereIn('role', ['centre_director', 'agency_admin'])->where('centre_id', $row['centre_id'])
            ->pluck('user_id')->all());
        // Nobody attached to the room or centre: the agency's admins, so it never goes nowhere.
        if (! $ids) {
            $ids = DB::table('role_assignments')->where('active', 1)->where('role', 'agency_admin')
                ->where('agency_id', $agencyId)->pluck('user_id')->all();
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $at = Carbon::parse($row['expected'], $tz)->format('g:i A');
        $title = "⚠️ Safe Arrival: {$row['name']} not here";
        $body = "{$row['name']} ({$row['room_name']}) was expected around {$at}, hasn't been signed in, "
            . "and no absence has been reported. "
            . ($parentsAsked > 0 ? "The parents were asked and haven't answered. Please call them."
                                  : "No message could be sent to the parents. Please call them.");
        if ($dry) {
            return [count($ids), []];
        }
        /* A missing child cannot wait for someone to open the app. On 2026-10-01 the alert
           for Addison reached neither educator whose phone token had expired, and nothing
           said so. Anyone the push does not reach gets the same alert by email, and their
           names go on the check so the Safe Arrival screen shows who was not reached. */
        $unreached = [];
        foreach ($ids as $uid) {
            $res = [];
            try {
                \App\Support\Notify::write([
                    'user_id' => $uid, 'type' => 'safe_arrival', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => '#safe-arrival', 'child_id' => $row['child_id']]), 'created_at' => now(),
                ]);
                $res = app(FcmService::class)->sendToUser($uid, $title, $body, '#safe-arrival');
            } catch (\Throwable $e) {
                Log::warning('Safe Arrival staff alert failed', ['child' => $row['child_id'], 'user' => $uid, 'error' => $e->getMessage()]);
            }
            if ((int) ($res['sent'] ?? 0) > 0) {
                continue;
            }
            $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name', 'email']);
            if (! $u) {
                continue;
            }
            $unreached[] = trim($u->first_name . ' ' . $u->last_name);
            if ($u->email) {
                try {
                    $this->staffEmail($agencyId, (string) $u->email, trim($u->first_name . ' ' . $u->last_name), $title, $body);
                } catch (\Throwable $e) {
                    Log::warning('Safe Arrival staff email failed', ['child' => $row['child_id'], 'user' => $uid, 'error' => $e->getMessage()]);
                }
            }
        }

        return [count($ids), $unreached];
    }

    private function staffEmail(int $agencyId, string $to, string $toName, string $title, string $body): void
    {
        $html = EmailTemplate::wrap($agencyId,
            '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($body) . '</p>'
            . '<p style="margin:14px 0 0;font-size:13.5px;color:#475569;line-height:1.6;">You are getting this by email because '
            . 'the alert could not reach your phone. Open KiddieTrac and allow notifications so the next one does.</p>',
            ['eyebrow' => 'SAFE ARRIVAL', 'title' => $title, 'subtitle' => 'Attendance', 'preheader' => $body]);
        dispatch(function () use ($agencyId, $to, $toName, $html, $title) {
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $toName, $title) {
                $m->to($to, $toName ?: null)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($title);
            });
        })->onQueue('mail');
    }

    private function email(int $agencyId, string $to, string $toName, string $title, string $body): void
    {
        $html = EmailTemplate::wrap($agencyId,
            '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($body) . '</p>'
            . '<p style="margin:14px 0 0;font-size:13.5px;color:#475569;line-height:1.6;">Open KiddieTrac and tap '
            . '<strong>“Not attending today”</strong> to let the team know. That also stops these messages.</p>',
            ['eyebrow' => 'SAFE ARRIVAL', 'title' => $title, 'subtitle' => 'Attendance', 'preheader' => $body]);
        dispatch(function () use ($agencyId, $to, $toName, $html, $title) {
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $toName, $title) {
                $m->to($to, $toName ?: null)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($title);
            });
        })->onQueue('mail');
    }
}
