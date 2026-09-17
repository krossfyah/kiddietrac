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
                            {--test-parent= : send ONE sample PARENT reminder to this address}
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

            /* The family-facing pass. Separate from the office digest below because the
               two answer different questions — "which children do we need to chase" and
               "your child's record needs updating" — and because an agency can want one
               without the other. */
            if (($cfg['notify_parents'] ?? false) || $this->option('test-parent')) {
                if ($this->option('force') || $this->option('test-parent')
                    || $this->isDue($cfg, (string) ($agency->timezone ?: 'America/Toronto'))) {
                    $sent += $this->parentPass((int) $agency->id, (string) $agency->name, $cfg);
                }
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

    /* ────────────────────────────────────────────────────────────────────────────
       THE PARENT REMINDER (2026-09-17)

       "for immunizations that are due create a parent email and bcc admin/director,
       app notification reminder that is configurable in the portal" — Anthony.

       ⚠ THE THING THAT MAKES THIS DANGEROUS, AND THE GUARD THAT ANSWERS IT.

       The schedule says a two-year-old owes about a dozen doses. `immunizations` holds
       what a centre has TRANSCRIBED, and across iLearn that is 3 children of 28. So a
       reminder that simply lists schedule gaps would tell 25 families their child is
       overdue for thirteen vaccines — a claim the centre cannot support, about their
       child's health, in writing. One send like that costs more trust than the feature
       could ever repay.

       So what gets said depends on what we actually know:

         • some doses recorded → name the ones overdue or coming up. This is a real,
           checkable statement.
         • nothing recorded, and no card on file → ask for the RECORD. "We do not have
           your child's immunization record" is true; "your child is overdue for 13
           vaccines" is not.
         • nothing recorded, but a card IS on file → say nothing to the family at all.
           The card is in the building and somebody has to type it up; chasing the
           parent for a document they already sent is the complaint that started all
           this ([[kiddietrac-immunization-record-vs-dose]]).

       One email per FAMILY, not per child: siblings share a household and two emails
       about the same subject is how a sender gets filtered.
       ──────────────────────────────────────────────────────────────────────────── */
    private function parentPass(int $agencyId, string $agencyName, array $cfg): int
    {
        $lead = (int) ($cfg['parent_lead_months'] ?? 2);
        $requiredOnly = (bool) ($cfg['parent_required_only'] ?? true);

        $rows = \App\Support\ImmunizationDue::forAgency($agencyId, $lead, $requiredOnly);
        if (! $rows) {
            $this->line("  {$agencyName}: no child is overdue or coming up");

            return 0;
        }

        // What we can actually say about each child — see the guard above.
        $childIds = array_column($rows, 'child_id');
        $hasDoses = DB::table('immunizations')->whereIn('child_id', $childIds)
            ->distinct()->pluck('child_id')->flip();
        $hasCard = DB::table('documents')->where('scope_type', 'child')
            ->whereIn('scope_id', $childIds)->where('category', 'immunization')
            ->distinct()->pluck('scope_id')->flip();

        $byFamily = [];
        foreach ($rows as $row) {
            $id = $row['child_id'];
            $recorded = $hasDoses->has($id);
            if (! $recorded && $hasCard->has($id)) {
                continue;                       // the office has it; not the parent's job
            }
            $row['ask'] = $recorded ? 'doses' : 'record';
            $byFamily[$row['family_id']][] = $row;
        }

        if (! $byFamily) {
            $this->line("  {$agencyName}: nothing to tell a family");

            return 0;
        }

        $testTo = (string) ($this->option('test-parent') ?? '');
        /* A SAMPLE GOES TO THE PERSON WHO ASKED FOR IT AND NOBODY ELSE. The real send
           blind-copies the office; a test that did the same would put a made-up
           reminder about a real child in front of real staff. */
        $staffBcc = (! $testTo && ($cfg['parent_bcc_staff'] ?? true))
            ? $this->recipients($agencyId, $cfg)
            : [];
        $sent = 0;

        foreach ($byFamily as $familyId => $children) {
            $guardians = $testTo
                ? [(object) ['email' => $testTo, 'name' => 'Anthony', 'user_id' => null]]
                : $this->guardiansOf((int) $familyId);

            if (! $guardians) {
                continue;
            }

            $subject = count($children) === 1
                ? 'Immunization record for ' . $children[0]['child_name']
                : 'Immunization records for your children';

            $body = $this->parentBody($children, $agencyName, $cfg);
            $html = EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => 'Immunization',
                'title' => $subject,
                'preheader' => 'An update is needed on your child\'s immunization record.',
            ]);

            if ($this->option('dry')) {
                $this->line('    [dry] ' . $subject . ' -> ' . implode(', ', array_column($guardians, 'email')));
                continue;
            }

            foreach ($guardians as $g) {
                try {
                    AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($g, $subject, $staffBcc, $agencyId) {
                        $m->to($g->email)->subject($subject);
                        if ($staffBcc) {
                            $m->bcc($staffBcc);
                        }
                        try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId); }
                        catch (Throwable $e) {}
                    });
                    $sent++;
                } catch (Throwable $e) {
                    Log::warning('Immunization parent reminder failed', ['to' => $g->email, 'e' => $e->getMessage()]);
                }

                /* The same thing in the app. A parent who lives in the APK should not
                   have to find it in their email, and the deep link lands on the child
                   where the record is uploaded. */
                if (($cfg['parent_push'] ?? true) && ! empty($g->user_id)) {
                    try {
                        \App\Support\Notify::write([
                            'user_id' => (int) $g->user_id,
                            'type' => 'immunization_reminder',
                            'title' => $subject,
                            'body' => $this->parentPushLine($children),
                            'data' => json_encode(['link' => '#my-children']),
                            'created_at' => now(),
                        ]);
                        app(\App\Services\FcmService::class)->sendToUser(
                            (int) $g->user_id, $subject, $this->parentPushLine($children), '#my-children'
                        );
                    } catch (Throwable $e) {
                        Log::warning('Immunization parent push failed', ['user' => $g->user_id, 'e' => $e->getMessage()]);
                    }
                }
            }

            if ($testTo) {
                break;                          // one sample is a sample
            }
        }

        $this->line("  {$agencyName}: {$sent} parent reminder(s)");

        return $sent;
    }

    /** One line for a notification tray, where there is no room for a table. */
    private function parentPushLine(array $children): string
    {
        $overdue = 0;
        $soon = 0;
        $missing = 0;
        foreach ($children as $c) {
            if ($c['ask'] === 'record') { $missing++; continue; }
            $overdue += count($c['overdue']);
            $soon += count($c['due_soon']);
        }
        $bits = [];
        if ($missing) { $bits[] = $missing . ' record' . ($missing === 1 ? '' : 's') . ' not on file'; }
        if ($overdue) { $bits[] = $overdue . ' overdue'; }
        if ($soon) { $bits[] = $soon . ' coming up'; }

        return ucfirst(implode(', ', $bits)) . '. Tap to upload or check the details.';
    }

    /** @return array<int,object{email:string,name:string,user_id:int}> */
    private function guardiansOf(int $familyId): array
    {
        return DB::table('guardians as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)
            ->whereNull('u.deleted_at')
            ->whereNotNull('u.email')
            /* Anyone who has not accepted their invite, or whose account is switched
               off, is not a person to write to — the mail layer would refuse them
               anyway, after the work was done. Same list the daily summary uses. */
            ->whereNotIn('u.status', ['invited', 'not_invited', 'deactivated', 'suspended'])
            ->get([
                'u.id as user_id', 'u.email',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),''),'there') as name"),
            ])->all();
    }

    /** The body of the family's email: one block per child, and what to do about it. */
    private function parentBody(array $children, string $agencyName, array $cfg): string
    {
        $out = '<p style="margin:0 0 14px;">Hello,</p>';

        if (trim((string) ($cfg['custom_message'] ?? '')) !== '') {
            $out .= '<p style="margin:0 0 14px;color:#334155;">' . e((string) $cfg['custom_message']) . '</p>';
        }

        foreach ($children as $c) {
            $out .= '<div style="border:1px solid #E5E7EB;border-radius:10px;padding:14px 16px;margin:0 0 14px;">'
                . '<div style="font-size:16px;font-weight:800;color:#0B2545;margin-bottom:6px;">' . e($c['child_name']) . '</div>';

            if ($c['ask'] === 'record') {
                /* No transcribed doses AND no card: the honest ask is the document. */
                $out .= '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#334155;">'
                    . 'We do not have an immunization record on file. Please send us a photo or a copy of '
                    . e($c['child_name']) . '\'s immunization card so we can keep their record up to date — '
                    . 'licensing requires one for every child in care.</p>';
            } else {
                if ($c['overdue']) {
                    $out .= '<div style="font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#B91C1C;margin:10px 0 4px;">Overdue</div>'
                        . $this->doseList($c['overdue']);
                }
                if ($c['due_soon']) {
                    $out .= '<div style="font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#B45309;margin:10px 0 4px;">Coming up</div>'
                        . $this->doseList($c['due_soon']);
                }
                $out .= '<p style="margin:10px 0 0;font-size:13.5px;line-height:1.6;color:#475569;">'
                    . 'If any of these have already been given, send us the updated record and we will '
                    . 'correct it — our list only shows what has been recorded here.</p>';
            }

            $out .= '</div>';
        }

        $out .= '<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">'
            . '<strong>How to send it:</strong> open the KiddieTrac app, go to your child and upload a photo '
            . 'of the card under Documents — or bring the original in and we will copy it.</p>'
            . '<p style="margin:0;font-size:12.5px;color:#64748B;">'
            . 'Sent by ' . e($agencyName) . ' because your child\'s immunization record needs an update. '
            . 'If you think this is wrong, reply to your centre and we will check it.</p>';

        return $out;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function doseList(array $items): string
    {
        $rows = '';
        foreach ($items as $i) {
            $due = $i['due_date'] ? Carbon::parse($i['due_date'])->format('j F Y') : '';
            $rows .= '<tr>'
                . '<td style="padding:6px 10px 6px 0;font-size:14px;color:#0F172A;font-weight:600;">'
                . e($i['vaccine']) . ' <span style="font-weight:400;color:#64748B;">' . e($i['dose_label']) . '</span></td>'
                . '<td style="padding:6px 0;font-size:13.5px;color:#475569;white-space:nowrap;">Due ' . e($due) . '</td>'
                . '</tr>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">'
            . $rows . '</table>';
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
