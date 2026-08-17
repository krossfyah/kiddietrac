<?php

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\BirthdayGreetings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Birthday emails for children and staff.
 *
 * The existing birthdays:celebrate command writes in-app bell notifications on the day
 * itself and sends no mail at all. This is the mail half, and it runs AHEAD of the day so
 * a room has time to plan something rather than finding out on the morning.
 *
 * Off unless an agency turns it on. Birthday mail is warm when it is wanted and intrusive
 * when it is not, and it reaches parents about their own children — that is not a default
 * anyone should inherit silently. Every part of it is separately switchable, because an
 * agency that wants its educators reminded does not necessarily want mail going to families.
 */
class BirthdayEmailCommand extends Command
{
    protected $signature = 'kiddietrac:birthday-emails
        {--test= : Send one sample of each message type to this address and stop}
        {--dry-run : List who would be emailed without sending}
        {--agency= : Restrict to one agency id}';

    protected $description = 'Email birthday wishes for children and staff whose birthday is approaching';

    /** Defaults for an agency that has never configured this. Off, and quiet. */
    private const DEFAULTS = [
        'enabled' => false,
        'days_ahead' => 1,
        'children_notify_guardians' => true,
        'children_notify_educators' => true,
        'staff_notify_person' => true,
        'staff_notify_leads' => false,
    ];

    public function handle(): int
    {
        if ($test = $this->option('test')) {
            return $this->sendSample((string) $test);
        }

        $this->ensureLogTable();
        $agencies = DB::table('agencies')
            ->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))
            ->get(['id', 'name', 'settings']);

        $sent = 0;
        foreach ($agencies as $agency) {
            $cfg = $this->config($agency->settings);
            if (! $cfg['enabled']) {
                $this->line("  agency {$agency->id} ({$agency->name}): birthday emails off");
                continue;
            }
            $sent += $this->runForAgency($agency, $cfg);
        }

        $this->info(($this->option('dry-run') ? 'Would send ' : 'Sent ') . $sent . ' birthday email(s)');
        return self::SUCCESS;
    }

    /** Agency settings JSON -> config, with the defaults filled in. */
    private function config(?string $settingsJson): array
    {
        $s = json_decode((string) $settingsJson, true) ?: [];
        $b = is_array($s['birthdays'] ?? null) ? $s['birthdays'] : [];
        $out = self::DEFAULTS;
        foreach ($out as $k => $v) {
            if (array_key_exists($k, $b)) {
                $out[$k] = is_bool($v) ? (bool) $b[$k] : $b[$k];
            }
        }
        $out['days_ahead'] = max(0, min(14, (int) $out['days_ahead']));
        return $out;
    }

    private function runForAgency(object $agency, array $cfg): int
    {
        $tz = \App\Support\AgencyTime::tz((int) $agency->id) ?: 'America/Toronto';
        $target = Carbon::now($tz)->addDays((int) $cfg['days_ahead'])->startOfDay();
        $mmdd = $target->format('m-d');
        $when = BirthdayGreetings::whenPhrase((int) $cfg['days_ahead'], $target);
        $year = (int) $target->format('Y');
        $centreIds = DB::table('centres')->where('agency_id', $agency->id)->pluck('id');
        if ($centreIds->isEmpty()) return 0;

        $sent = 0;

        // ── children ────────────────────────────────────────────────────────
        if ($cfg['children_notify_guardians'] || $cfg['children_notify_educators']) {
            $children = DB::table('children as ch')
                ->join('families as f', 'f.id', '=', 'ch.family_id')
                ->whereIn('f.centre_id', $centreIds)
                ->where('ch.enrollment_status', 'enrolled')
                ->whereNull('ch.deleted_at')
                ->whereNotNull('ch.date_of_birth')
                ->whereRaw("DATE_FORMAT(ch.date_of_birth, '%m-%d') = ?", [$mmdd])
                ->get(['ch.id', 'ch.first_name', 'ch.last_name', 'ch.date_of_birth', 'ch.family_id', 'f.centre_id']);

            foreach ($children as $ch) {
                $turning = (int) Carbon::parse($ch->date_of_birth)->diffInYears($target);
                $seed = BirthdayGreetings::seed((int) $ch->id, $year);
                $name = trim($ch->first_name . ' ' . $ch->last_name);

                if ($cfg['children_notify_guardians']) {
                    $to = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                        ->where('g.family_id', $ch->family_id)->whereNull('u.deleted_at')
                        ->whereNotNull('u.email')->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);
                    foreach ($to as $r) {
                        $sent += $this->deliver($agency, $r, 'child_guardian', (int) $ch->id, $year,
                            '🎂 ' . $ch->first_name . ' turns ' . $turning . ' ' . $when,
                            BirthdayGreetings::forGuardian($seed, $ch->first_name, $turning, $when),
                            $name . "'s " . BirthdayGreetings::ordinal($turning) . ' birthday');
                    }
                }

                if ($cfg['children_notify_educators']) {
                    $staff = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                        ->where('ra.centre_id', $ch->centre_id)->where('ra.active', 1)
                        ->whereIn('ra.role', ['educator', 'centre_director'])
                        ->whereNull('u.deleted_at')->whereNotNull('u.email')
                        ->distinct()->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);
                    foreach ($staff as $r) {
                        $sent += $this->deliver($agency, $r, 'child_educator', (int) $ch->id, $year,
                            '🎂 ' . $ch->first_name . "'s birthday is " . $when,
                            BirthdayGreetings::forEducator($seed, $ch->first_name, $turning, $when),
                            $name . "'s " . BirthdayGreetings::ordinal($turning) . ' birthday');
                    }
                }
            }
        }

        // ── staff ───────────────────────────────────────────────────────────
        if ($cfg['staff_notify_person'] || $cfg['staff_notify_leads']) {
            $staff = DB::table('users as u')
                ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.active', 1)->where('ra.role', '!=', 'guardian')
                ->where(function ($q) use ($agency, $centreIds) {
                    $q->where('ra.agency_id', $agency->id)->orWhereIn('ra.centre_id', $centreIds);
                })
                ->whereNull('u.deleted_at')->whereNotNull('u.date_of_birth')->whereNotNull('u.email')
                ->whereRaw("DATE_FORMAT(u.date_of_birth, '%m-%d') = ?", [$mmdd])
                ->distinct()->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);

            foreach ($staff as $p) {
                $seed = BirthdayGreetings::seed((int) $p->id, $year);
                $name = trim($p->first_name . ' ' . $p->last_name);

                if ($cfg['staff_notify_person']) {
                    $sent += $this->deliver($agency, $p, 'staff_person', (int) $p->id, $year,
                        '🎉 Happy birthday, ' . $p->first_name . '!',
                        BirthdayGreetings::forPerson($seed, $p->first_name, $when),
                        'Happy birthday');
                }

                if ($cfg['staff_notify_leads']) {
                    $leads = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                        ->where('ra.active', 1)->whereIn('ra.role', ['agency_admin', 'centre_director'])
                        ->where(function ($q) use ($agency, $centreIds) {
                            $q->where('ra.agency_id', $agency->id)->orWhereIn('ra.centre_id', $centreIds);
                        })
                        ->where('u.id', '!=', $p->id)
                        ->whereNull('u.deleted_at')->whereNotNull('u.email')
                        ->distinct()->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);
                    foreach ($leads as $r) {
                        $sent += $this->deliver($agency, $r, 'staff_lead', (int) $p->id, $year,
                            $name . "'s birthday is " . $when,
                            BirthdayGreetings::forLead($seed, $name, $when), 'A birthday coming up');
                    }
                }
            }
        }

        return $sent;
    }

    /** One email, sent once. */
    private function deliver(object $agency, object $recipient, string $kind, int $subjectId, int $year,
                             string $subject, string $message, string $heading): int
    {
        if (DB::table('birthday_email_log')
            ->where(['recipient_user_id' => $recipient->id, 'kind' => $kind, 'subject_id' => $subjectId, 'birthday_year' => $year])
            ->exists()) {
            return 0;
        }

        if ($this->option('dry-run')) {
            $this->line("    would email {$recipient->email} — {$subject}");
            return 1;
        }

        $html = EmailTemplate::wrap((int) $agency->id, $this->bodyHtml($heading, $message), [
            'preheader' => $subject, 'eyebrow' => 'Birthday',
        ]);

        try {
            AgencyMailer::forAgency((int) $agency->id)->mailer()->html($html, function ($m) use ($recipient, $subject) {
                $m->to($recipient->email, trim(($recipient->first_name ?? '') . ' ' . ($recipient->last_name ?? '')))
                  ->subject($subject);
            });
        } catch (\Throwable $e) {
            $this->warn("    failed {$recipient->email}: " . $e->getMessage());
            return 0;
        }

        DB::table('birthday_email_log')->insert([
            'recipient_user_id' => $recipient->id, 'kind' => $kind, 'subject_id' => $subjectId,
            'birthday_year' => $year, 'created_at' => now(),
        ]);
        return 1;
    }

    private function bodyHtml(string $heading, string $message): string
    {
        return '<div style="text-align:center;font-size:44px;line-height:1;margin:0 0 14px;">🎂</div>'
            . '<div style="font-size:21px;font-weight:800;color:#0B2545;text-align:center;margin:0 0 14px;">'
            . e($heading) . '</div>'
            . '<div style="font-size:16px;line-height:1.65;color:#334155;text-align:center;margin:0 0 22px;">'
            . e($message) . '</div>'
            . EmailTemplate::button('Open KiddieTrac', 'https://app.kiddietrac.com');
    }

    /** One sample of each message type, so the wording can be judged before it goes out. */
    private function sendSample(string $to): int
    {
        $agencyId = (int) ($this->option('agency') ?: 6);
        $when = BirthdayGreetings::whenPhrase(1, Carbon::tomorrow());
        $samples = [
            ['To a parent', '🎂 Amara turns 4 tomorrow', BirthdayGreetings::forGuardian(3, 'Amara', 4, $when), "Amara's 4th birthday"],
            ['To an educator', "🎂 Amara's birthday is tomorrow", BirthdayGreetings::forEducator(2, 'Amara', 4, $when), "Amara's 4th birthday"],
            ['To the birthday person', '🎉 Happy birthday, Chearstine!', BirthdayGreetings::forPerson(1, 'Chearstine', $when), 'Happy birthday'],
            ['To a director', "Chearstine Fitzpatrick's birthday is tomorrow", BirthdayGreetings::forLead(4, 'Chearstine Fitzpatrick', $when), 'A birthday coming up'],
        ];

        foreach ($samples as [$label, $subject, $message, $heading]) {
            $html = EmailTemplate::wrap($agencyId,
                '<div style="background:#FEF3C7;color:#92400E;border-radius:8px;padding:8px 12px;font-size:12px;font-weight:700;text-align:center;margin:0 0 18px;">SAMPLE — ' . e($label) . '</div>'
                . $this->bodyHtml($heading, $message),
                ['preheader' => $subject, 'eyebrow' => 'Birthday']);

            AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($to, $subject, $label) {
                $m->to($to)->subject('[Sample: ' . $label . '] ' . $subject);
                // Operational sample — must reach the address regardless of agency suppression.
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
            $this->info("  sent sample: {$label}");
        }
        return self::SUCCESS;
    }

    /** Idempotency store. Created on first use rather than carried as a migration. */
    private function ensureLogTable(): void
    {
        if (Schema::hasTable('birthday_email_log')) return;
        Schema::create('birthday_email_log', function ($t) {
            $t->id();
            $t->unsignedBigInteger('recipient_user_id')->index();
            $t->string('kind', 32);
            $t->unsignedBigInteger('subject_id');
            $t->unsignedSmallInteger('birthday_year');
            $t->timestamp('created_at')->nullable();
            $t->unique(['recipient_user_id', 'kind', 'subject_id', 'birthday_year'], 'bday_once');
        });
    }
}
