<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Api\ReportsController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails due report schedules (PDF and/or CSV). Runs hourly; a schedule fires
 * once per day at/after its send-time hour on the matching day.
 */
class SendScheduledReports extends Command
{
    protected $signature = 'kiddietrac:send-scheduled-reports {--force : send every active schedule now, ignoring day/time}';
    protected $description = 'Email due scheduled reports to their recipients';

    public function handle(): int
    {
        $now = now();
        $today = $now->toDateString();
        $dow = (int) $now->dayOfWeek;   // 0=Sun..6=Sat
        $dom = (int) $now->day;
        $hour = (int) $now->hour;
        $force = (bool) $this->option('force');

        $rows = DB::table('report_schedules')
            ->where('active', 1)
            ->when(! $force, fn ($q) => $q->where(function ($qq) use ($today) {
                $qq->whereNull('last_sent_on')->orWhere('last_sent_on', '<', $today);
            }))
            ->get();

        $sent = 0;
        foreach ($rows as $r) {
            if (! $force) {
                if ($r->frequency === 'weekly' && (int) ($r->day_of_week ?? 1) !== $dow) {
                    continue;
                }
                if ($r->frequency === 'monthly' && (int) ($r->day_of_month ?: 1) !== min($dom, 28)) {
                    continue;
                }
                $sendHour = (int) substr((string) $r->send_time, 0, 2);
                if ($hour < $sendHour) {
                    continue;   // not time yet today
                }
            }

            if (self::sendOne($r)) {
                DB::table('report_schedules')->where('id', $r->id)->update(['last_sent_on' => $today, 'updated_at' => now()]);
                $sent++;
            }
        }

        $this->info("Scheduled reports sent: {$sent}");
        // Name the schedules that cannot deliver, so a run that sends nothing explains
        // itself rather than just reporting zero.
        foreach ($rows as $r) {
            $to = $r->recipient_email ?: DB::table('users')->where('id', $r->recipient_user_id)->value('email');
            if ($to && ($why = self::undeliverableReason($to))) {
                $this->warn("  schedule #{$r->id} ({$r->report_type}) -> {$to}: {$why}."
                    . ' The report is still delivered, but this usually means the schedule'
                    . ' should be pointed at somebody still here.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Why this address cannot be delivered to, or null if it can.
     *
     * Only speaks to accounts we hold: an address belonging to nobody in the system is a
     * plain external recipient and perfectly deliverable. It is an address that maps to a
     * DEPARTED account that gets blocked downstream, and that is the case worth naming.
     */
    public static function undeliverableReason(string $email): ?string
    {
        $u = DB::table('users')->where('email', $email)->first(['id', 'status', 'deleted_at', 'first_name', 'last_name']);
        if (! $u) {
            return null;
        }
        $who = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $email;

        if (! empty($u->deleted_at)) {
            return "the recipient address belongs to {$who}, whose account was removed on "
                . substr((string) $u->deleted_at, 0, 10) . ' — notifications to it are paused';
        }
        if (in_array((string) $u->status, ['deactivated', 'suspended'], true)) {
            return "the recipient address belongs to {$who}, whose account is {$u->status}"
                . ' — notifications to it are paused';
        }

        return null;
    }

    /**
     * Point the log row for this send at the right agency.
     *
     * email_logs attributes by INFERRING the agency from the recipient — their first active
     * role assignment — which is a guess, and wrong for anyone who belongs to more than one.
     * iLearn's weekly report was filed under the demo agency because its recipient held a
     * role at both, so it never appeared in the log of the agency that sent it.
     *
     * Narrow on purpose: this recipient, this subject, the last few minutes, and only when
     * the row disagrees. It corrects a row this method caused and nothing else.
     */
    private static function fileUnderAgency(string $to, string $subject, int $agencyId): void
    {
        if (! $agencyId) {
            return;
        }
        try {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('email_logs', 'agency_id')) {
                return;
            }
            DB::table('email_logs')
                ->where('to_email', $to)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->where('subject', 'like', '%' . mb_substr($subject, 0, 40) . '%')
                ->where(function ($q) use ($agencyId) {
                    $q->whereNull('agency_id')->orWhere('agency_id', '!=', $agencyId);
                })
                ->update(['agency_id' => $agencyId]);
        } catch (\Throwable $e) {
            // A misfiled log row is not worth failing a delivered report over.
        }
    }

    /**
     * Was the message we just handed to the mailer actually dropped?
     *
     * The listener records every suppression in email_logs with status 'suppressed', so the
     * log is the only honest answer available to a sender. Matched on recipient and subject
     * within the last few minutes; missing table or no row means assume it went, since a
     * false alarm here would stop a working schedule.
     */
    public static function wasSuppressed(string $to, string $subject): bool
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('email_logs')) {
                return false;
            }

            return DB::table('email_logs')
                ->where('to_email', $to)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->where('status', 'suppressed')
                ->where('subject', 'like', '%' . mb_substr($subject, 0, 40) . '%')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Date range [from, to] (Y-m-d or null) for a schedule's range_kind. */
    public static function range(string $kind): array
    {
        $today = now();
        switch ($kind) {
            case 'last_7d':    return [$today->copy()->subDays(7)->toDateString(), $today->toDateString()];
            case 'last_30d':   return [$today->copy()->subDays(30)->toDateString(), $today->toDateString()];
            case 'this_month': return [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()];
            case 'last_month': return [$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()];
            case 'all':
            default:           return [null, null];
        }
    }

    /** Build + email a single schedule. Returns true if a mail was dispatched. */
    public static function sendOne($r, bool $manual = false): bool
    {
        $to = $r->recipient_email;
        if (! $to && $r->recipient_user_id) {
            $to = DB::table('users')->where('id', $r->recipient_user_id)->value('email');
        }
        if (! $to) {
            return false;
        }

        // A note, not a refusal. An address that also happens to be a departed user's
        // login is still a perfectly good destination for a report — see the bypass header
        // on the send below — but it is worth saying out loud, because it usually means the
        // schedule is pointed at somebody who has left and wants re-pointing.
        if ($blocked = self::undeliverableReason($to)) {
            Log::notice('Scheduled report recipient is also a departed account — sending anyway: ' . $blocked, [
                'schedule_id' => $r->id ?? null,
                'recipient' => $to,
            ]);
        }

        [$from, $toDate] = self::range((string) $r->range_kind);

        $rep = app(ReportsController::class)->buildScheduledReport(
            (int) $r->agency_id,
            (string) $r->report_type,
            $r->centre_id ? (int) $r->centre_id : null,
            $from,
            $toDate,
            'Scheduled report'
        );
        if (! ($rep['ok'] ?? false)) {
            return false;
        }

        $atts = [];
        if (in_array($r->format, ['pdf', 'both'], true) && $rep['pdf'] !== null) {
            $atts[] = ['data' => $rep['pdf'], 'name' => $rep['filename_base'] . '.pdf', 'mime' => 'application/pdf'];
        }
        if (in_array($r->format, ['csv', 'both'], true) && $rep['csv'] !== null) {
            $atts[] = ['data' => $rep['csv'], 'name' => $rep['filename_base'] . '.csv', 'mime' => 'text/csv'];
        }
        if (! $atts) {
            return false;
        }

        $agencyName = DB::table('agencies')->where('id', $r->agency_id)->value('name') ?: 'KiddieTrac';
        $subject = $rep['title'] . ' — ' . $agencyName;
        $rangeTxt = $from ? ($from . ' to ' . $toDate) : 'all records';

        // Branded like everything else the platform sends. This went out as bare <p> tags
        // on a white page — no header, no agency logo, no footer — which for an agency on
        // the white-label plan meant their own scheduled report did not look like theirs.
        // EmailTemplate::wrap is what every other outbound email already passes through.
        $inner = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">Hello,</p>'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">Your scheduled <strong>'
            . e($rep['title']) . '</strong> report is attached.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px">'
            . '<tr><td style="background:#F1F5F9;border-radius:10px;padding:14px 16px;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">Period</div>'
            . '<div style="font-size:15px;font-weight:700;color:#0F172A;margin:2px 0 10px;">' . e($rangeTxt) . '</div>'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">Rows</div>'
            . '<div style="font-size:15px;font-weight:700;color:#0F172A;margin-top:2px;">' . (int) $rep['count'] . '</div>'
            . '</td></tr></table>'
            . '<p style="margin:0;font-size:13px;line-height:1.6;color:#64748B;">You are receiving this because a report '
            . 'schedule was set up in KiddieTrac.' . ($manual ? ' (Sent manually.)' : '') . '</p>';

        $body = \App\Services\EmailTemplate::wrap((int) $r->agency_id, $inner, [
            'eyebrow' => 'SCHEDULED REPORT',
            'title' => $rep['title'],
            'subtitle' => $agencyName . ' · ' . $rangeTxt,
            'preheader' => $rep['title'] . ' — ' . $rangeTxt . ', ' . (int) $rep['count'] . ' rows',
        ]);

        try {
            Mail::html($body, function ($m) use ($to, $subject, $atts) {
                $m->to($to)->subject($subject);
                // A scheduled report goes to a DESTINATION somebody configured — a shared
                // mailbox, a team address, an accountant — not to a person in their
                // capacity as a user. The suspended/deactivated gate matches on the
                // address, so without this an ops mailbox that happens to double as a
                // departed user's login silently switches the schedule off. The same
                // reasoning, and the same header, as the other 29 senders that address a
                // configured recipient.
                try { $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1'); } catch (\Throwable $e) {}
                // File it under the agency the REPORT is for, not the one guessed from the
                // recipient. These were landing in the demo agency's log because the
                // recipient held a role there too, so they were invisible in the log of the
                // agency that sent them.
                try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $r->agency_id); } catch (\Throwable $e) {}
                foreach ($atts as $a) {
                    $m->attachData($a['data'], $a['name'], ['mime' => $a['mime']]);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Scheduled report send failed: ' . $e->getMessage(), ['schedule_id' => $r->id ?? null]);

            return false;
        }

        // Did it actually leave? Suppression happens downstream in a listener and throws
        // nothing, so a dropped send is indistinguishable from a delivered one from here —
        // which is exactly how three weeks of reports went missing while this returned true
        // and stamped last_sent_on. Reading the log back covers every reason a message can
        // be dropped, including ones added later.
        // File the log row under the agency the REPORT is for.
        //
        // The X-KT-Agency-Id header above is the tidy way and the log listener reads it,
        // but the row still comes out attributed to the recipient's first role assignment —
        // something between the send and the listener is not carrying it. Rather than leave
        // the report invisible in its own agency's log while that is chased, the sender
        // corrects the row it just caused: it knows the agency for certain, and it is
        // already reading this row back to check for suppression.
        self::fileUnderAgency($to, $subject, (int) $r->agency_id);

        if (self::wasSuppressed($to, $subject)) {
            Log::warning('Scheduled report was SUPPRESSED after sending — not marking as sent.', [
                'schedule_id' => $r->id ?? null, 'recipient' => $to, 'subject' => $subject,
            ]);

            return false;
        }

        return true;
    }
}
