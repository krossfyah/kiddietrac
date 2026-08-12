<?php

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Weekly nudge to parents who keep having their child checked in/out MANUALLY
 * (a staff member doing it on their behalf) instead of self-scanning the QR
 * barcode. Fires only when the manual count in the trailing 7 days EXCEEDS a
 * threshold (default 2). Friendly, personalised (parent + children by name),
 * white-labelled per agency, and CC'd to the agency's admin.
 *
 * "Manual" = a check_events row whose actor (by_user_id) is agency STAFF rather
 * than a guardian of that child — i.e. the parent did not self-scan.
 *
 *   php artisan kiddietrac:manual-checkin-reminders
 *   php artisan kiddietrac:manual-checkin-reminders --test=me@x.com
 */
class ManualCheckinReminderCommand extends Command
{
    protected $signature = 'kiddietrac:manual-checkin-reminders {--test= : send one white-labelled sample to this address and exit} {--force : ignore the weekly cadence gate}';

    protected $description = 'Nudge parents who keep using manual (staff) check-in instead of the QR barcode.';

    public function handle(): int
    {
        $test = (string) $this->option('test');
        if ($test !== '') {
            $agencyId = (int) (DB::table('agencies')->where('name', 'like', '%iLearn%')->value('id')
                ?: DB::table('agencies')->orderBy('id')->value('id'));
            $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'Your agency');
            $this->send($test, 'Anthony', ['Mattea', 'Aria'], 4, $agencyName, $agencyId, null, true);
            $this->info("Sample manual-check-in reminder sent to {$test} using agency #{$agencyId} ({$agencyName}) branding.");

            return self::SUCCESS;
        }

        $weekAgo = Carbon::now()->subDays(7);
        $sent = 0;

        $agencies = DB::table('agencies')->get(['id', 'name', 'settings']);
        foreach ($agencies as $ag) {
            $s = json_decode($ag->settings ?? '{}', true) ?: [];
            $enabled = array_key_exists('manual_checkin_reminders_enabled', $s) ? (bool) $s['manual_checkin_reminders_enabled'] : true; // default ON
            if (! $enabled) {
                continue;
            }
            $threshold = isset($s['manual_checkin_threshold']) ? max(1, (int) $s['manual_checkin_threshold']) : 2; // "exceeds 2/week"

            // Staff users of THIS agency (their actions = "manual" check-ins).
            $staffIds = DB::table('role_assignments')
                ->where('active', 1)
                ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'home_visitor'])
                ->where(function ($q) use ($ag) {
                    $centreIds = DB::table('centres')->where('agency_id', $ag->id)->pluck('id')->all();
                    $q->where('agency_id', $ag->id);
                    if ($centreIds) $q->orWhereIn('centre_id', $centreIds);
                })
                ->pluck('user_id')->unique()->values()->all();
            if (empty($staffIds)) {
                continue;
            }

            // The agency admin to CC (first active agency_admin with an email).
            $adminEmail = DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.role', 'agency_admin')->where('ra.active', 1)->where('ra.agency_id', $ag->id)
                ->whereNotNull('u.email')->whereNull('u.deleted_at')
                ->value('u.email');

            // Per-family manual count over the trailing 7 days (staff-performed only).
            $rows = DB::table('check_events as ce')
                ->join('children as c', 'c.id', '=', 'ce.child_id')
                ->whereIn('ce.by_user_id', $staffIds)
                ->where('ce.occurred_at', '>=', $weekAgo)
                ->whereNull('c.deleted_at')
                ->groupBy('c.family_id')
                ->select('c.family_id', DB::raw('COUNT(*) as manual_n'))
                ->havingRaw('COUNT(*) > ?', [$threshold])
                ->get();

            foreach ($rows as $fam) {
                try {
                    // Primary guardian (name + email) + the family's children names.
                    $guardian = DB::table('guardians as g')
                        ->join('users as u', 'u.id', '=', 'g.user_id')
                        ->where('g.family_id', $fam->family_id)
                        ->whereNotNull('u.email')->whereNull('u.deleted_at')
                        ->orderByDesc('g.is_primary')
                        ->select('u.first_name', 'u.email')
                        ->first();
                    if (! $guardian) {
                        continue;
                    }
                    $kids = DB::table('children')->where('family_id', $fam->family_id)->whereNull('deleted_at')
                        ->orderBy('first_name')->pluck(DB::raw("COALESCE(preferred_name, first_name)"))->all();
                    if (empty($kids)) {
                        continue;
                    }
                    $this->send((string) $guardian->email, (string) ($guardian->first_name ?: 'there'),
                        $kids, (int) $fam->manual_n, (string) $ag->name, (int) $ag->id, $adminEmail, false);
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('Manual-checkin reminder failed', ['family' => $fam->family_id, 'error' => $e->getMessage()]);
                }
            }
        }

        $this->info("Manual-check-in reminders sent: {$sent}.");

        return self::SUCCESS;
    }

    /** @param string[] $kidNames */
    private function send(string $email, string $parentFirst, array $kidNames, int $manualCount, string $agencyName, int $agencyId, ?string $ccAdmin, bool $sync): void
    {
        $first = htmlspecialchars($parentFirst ?: 'there');
        $names = array_map('htmlspecialchars', $kidNames);
        $kidList = count($names) === 1 ? $names[0]
            : (count($names) === 2 ? $names[0] . ' and ' . $names[1]
            : implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names));
        $them = count($names) === 1 ? 'them' : 'them';
        $child = count($names) === 1 ? 'your child' : 'your children';

        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Hi ' . $first . ' 👋</p>'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">We noticed <strong>' . $kidList . '</strong> ' . (count($names) === 1 ? 'was' : 'were') . ' checked in or out <strong>manually ' . $manualCount . ' times</strong> this week, rather than with the <strong>QR barcode</strong> at drop-off and pick-up.</p>'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">No worries at all — but a quick tip! Scanning the QR yourself takes a couple of seconds and it:</p>'
            . '<ul style="margin:0 0 16px;padding-left:20px;font-size:14.5px;line-height:1.7;color:#334155;">'
            . '<li>records the <strong>exact</strong> time ' . $child . ' arrived and left,</li>'
            . '<li>logs <strong>who</strong> dropped off and collected ' . $them . ' — an important safety &amp; compliance record, and</li>'
            . '<li>saves our educators time so they can focus on ' . $them . '.</li>'
            . '</ul>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Just open the KiddieTrac app and tap <strong>Check-in QR</strong> at the bottom, then scan the code at the door. That\'s it! 🎉</p>'
            . '<p style="margin:16px 0 0;font-size:13px;color:#94A3B8;">Thanks for helping us keep ' . $child . '\'s records accurate. Questions? Just reply to this email.</p>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'   => 'A QUICK TIP',
            'title'     => $agencyName,
            'subtitle'  => 'The fast, secure QR check-in',
            'preheader' => 'A couple of seconds at the door keeps ' . strip_tags($child) . '\'s arrival records accurate.',
        ]);
        $subject = 'A quick tip on checking ' . strip_tags($kidList) . ' in & out';
        $name = $parentFirst;

        $sendClosure = function () use ($agencyId, $email, $name, $subject, $html, $ccAdmin) {
            AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($email, $name, $subject, $ccAdmin) {
                $m->to($email, $name ?: null)
                    ->from('noreply@kiddietrac.com', 'KiddieTrac')
                    ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                    ->subject($subject);
                if ($ccAdmin && $ccAdmin !== $email) { try { $m->cc($ccAdmin); } catch (\Throwable $e) {} }
                $m->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:support@kiddietrac.com>');
            });
        };
        if ($sync) { $sendClosure(); } else { dispatch($sendClosure)->onQueue('mail'); }

    }
}
