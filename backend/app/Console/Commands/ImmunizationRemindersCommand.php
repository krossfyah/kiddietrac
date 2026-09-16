<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Api\ImmunizationRemindersController as Cfg;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reminds the people who can chase a parent which children still owe an
 * immunization record.
 *
 * Runs hourly and decides for itself whether this is the hour — each agency picks a
 * weekday (or day of the month) and a local send time, so the schedule lives in the
 * agency's settings rather than in cron. Off for every agency until switched on in
 * Settings → Email.
 */
class ImmunizationRemindersCommand extends Command
{
    protected $signature = 'immunization:reminders
                            {--agency= : only this agency}
                            {--force : ignore the schedule and send now}
                            {--test= : send the agency\'s reminder to this address instead}
                            {--dry : report what would be sent, send nothing}';

    protected $description = 'Email admins/directors/educators about immunization records needing an update';

    public function handle(): int
    {
        $agencies = DB::table('agencies')
            ->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))
            ->get(['id', 'name', 'timezone']);

        $sent = 0;
        foreach ($agencies as $agency) {
            $cfg = Cfg::read((int) $agency->id);

            if (! $cfg['enabled'] && ! $this->option('force')) {
                continue;
            }
            if (! $this->option('force') && ! $this->isDue($cfg, (string) ($agency->timezone ?: 'America/Toronto'))) {
                continue;
            }

            $outstanding = Cfg::outstanding((int) $agency->id, $cfg);
            if ($outstanding['count'] === 0) {
                /* Nothing outstanding means nothing to say. A reminder that arrives
                   every week to report zero is the fastest way to teach a team to
                   filter the sender. */
                $this->line("  {$agency->name}: nothing outstanding");
                continue;
            }

            $to = $this->option('test')
                ? [(string) $this->option('test')]
                : $this->recipients((int) $agency->id, $cfg);

            if (! $to) {
                $this->warn("  {$agency->name}: {$outstanding['count']} outstanding but nobody to tell");
                continue;
            }

            $this->line("  {$agency->name}: {$outstanding['count']} outstanding -> " . count($to) . ' recipient(s)');
            if ($this->option('dry')) {
                foreach ($outstanding['children'] as $c) {
                    $this->line('      ' . $c['name'] . ' (' . $c['reason'] . ')');
                }
                continue;
            }

            if ($this->send((int) $agency->id, (string) $agency->name, $to, $outstanding, $cfg)) {
                $sent++;
            }
        }

        $this->info("immunization:reminders — {$sent} agency reminder(s) sent");

        return 0;
    }

    /** Is this the hour this agency asked for, in the agency's own timezone? */
    private function isDue(array $cfg, string $tz): bool
    {
        try {
            $now = Carbon::now($tz);
        } catch (Throwable $e) {
            $now = Carbon::now('America/Toronto');
        }

        [$h, $m] = array_pad(explode(':', (string) ($cfg['send_time'] ?? '09:00')), 2, '0');
        if ((int) $now->format('G') !== (int) $h) {
            return false;
        }
        unset($m); // the hourly schedule only resolves to the hour

        if (($cfg['frequency'] ?? 'weekly') === 'monthly') {
            return (int) $now->day === (int) ($cfg['day_of_month'] ?? 1);
        }

        // Carbon: Monday = 1 … Sunday = 7, matching the setting.
        return (int) $now->isoWeekday() === (int) ($cfg['day_of_week'] ?? 1);
    }

    /** @return list<string> */
    private function recipients(int $agencyId, array $cfg): array
    {
        $roles = [];
        if ($cfg['notify_agency_admins'] ?? true) {
            $roles[] = 'agency_admin';
        }
        if ($cfg['notify_directors'] ?? true) {
            $roles[] = 'centre_director';
        }
        if ($cfg['notify_educators'] ?? false) {
            $roles[] = 'educator';
        }
        if (! $roles) {
            return [];
        }

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)
            ->whereNull('deleted_at')->pluck('id');

        return DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)
            ->whereIn('ra.role', $roles)
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('ra.agency_id', $agencyId);
                if ($centreIds->isNotEmpty()) {
                    $q->orWhereIn('ra.centre_id', $centreIds);
                }
            })
            ->whereNull('u.deleted_at')
            ->whereNotNull('u.email')
            ->distinct()->pluck('u.email')->filter()->unique()->values()->all();
    }

    private function send(int $agencyId, string $agencyName, array $to, array $outstanding, array $cfg): bool
    {
        $rows = '';
        foreach ($outstanding['children'] as $c) {
            $rows .= '<tr>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #E6EAF2;font-weight:600;">' . e($c['name']) . '</td>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #E6EAF2;color:#475569;">'
                . e(trim(($c['centre_name'] ?? '') . ($c['room_name'] ? ' · ' . $c['room_name'] : ''))) . '</td>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #E6EAF2;color:#B45309;">' . e($c['reason']) . '</td>'
                . '</tr>';
        }

        $n = $outstanding['count'];
        $body = '<p style="margin:0 0 14px;">'
            . '<strong>' . $n . '</strong> ' . ($n === 1 ? 'child needs' : 'children need')
            . ' an immunization record from a parent.</p>';

        if (trim((string) ($cfg['custom_message'] ?? '')) !== '') {
            $body .= '<p style="margin:0 0 14px;color:#334155;">' . e((string) $cfg['custom_message']) . '</p>';
        }

        $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="border-collapse:collapse;font-size:14px;margin:0 0 16px;">'
            . '<tr>'
            . '<th align="left" style="padding:9px 12px;border-bottom:2px solid #CBD5E1;font-size:12px;text-transform:uppercase;color:#64748B;">Child</th>'
            . '<th align="left" style="padding:9px 12px;border-bottom:2px solid #CBD5E1;font-size:12px;text-transform:uppercase;color:#64748B;">Where</th>'
            . '<th align="left" style="padding:9px 12px;border-bottom:2px solid #CBD5E1;font-size:12px;text-transform:uppercase;color:#64748B;">Why</th>'
            . '</tr>' . $rows . '</table>'
            . '<p style="margin:0 0 14px;">Parents can upload a record themselves from the app — '
            . 'it files straight onto the child\'s Documents tab.</p>'
            . '<p style="margin:0;font-size:12.5px;color:#64748B;">'
            . 'You are receiving this because immunization reminders are switched on for '
            . e($agencyName) . '. Change or stop them in Settings → Email.</p>';

        $subject = $n . ' immunization ' . ($n === 1 ? 'record' : 'records') . ' need updating';
        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'Immunization records',
            'title' => $subject,
            'preheader' => $subject,
        ]);

        try {
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $subject, $agencyId) {
                $m->to($to[0])->subject($subject);
                if (count($to) > 1) {
                    // One send, the rest blind: a staff list is not something to
                    // hand round in a To line.
                    $m->bcc(array_slice($to, 1));
                }
                // Say which agency this is, rather than leaving the mail layer to
                // infer a tenant from the recipient's address.
                try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId); }
                catch (Throwable $e) {}
            });

            return true;
        } catch (Throwable $e) {
            Log::warning('Immunization reminder failed', ['agency' => $agencyId, 'e' => $e->getMessage()]);
            $this->warn('    send failed: ' . $e->getMessage());

            return false;
        }
    }
}
