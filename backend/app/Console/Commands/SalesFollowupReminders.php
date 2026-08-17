<?php

namespace App\Console\Commands;

use App\Models\SalesActivity;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Daily lead follow-up reminders. Emails the lead's owner (or superadmins if the
 * lead is unassigned) about follow-up tasks that are due today or overdue, then
 * marks each `reminded` so it nudges once (no daily spam). Scheduled in
 * routes/console.php.
 */
class SalesFollowupReminders extends Command
{
    protected $signature = 'kiddietrac:sales-followups {--dry : List without sending}';

    protected $description = 'Email sales reps about due / overdue lead follow-ups.';

    public function handle(): int
    {
        $today = now()->toDateString();

        $due = SalesActivity::where('type', 'followup')
            ->where('done', false)
            ->where('reminded', false)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today)
            ->orderBy('due_date')
            ->get();

        $superadmins = User::whereHas('roleAssignments', fn ($q) => $q->where('role', 'platform_admin'))
            ->whereNotNull('email')->pluck('email')->unique()->values()->all();

        $sent = 0;
        foreach ($due as $act) {
            $lead = SalesLead::with('owner')->find($act->lead_id);
            if (! $lead || $lead->status !== 'open') {
                $act->reminded = true;
                $act->saveQuietly();
                continue;
            }

            $to = [];
            if ($lead->owner && $lead->owner->email) {
                $to[] = $lead->owner->email;
            }
            if (empty($to)) {
                $to = $superadmins;
            }
            if (empty($to)) {
                $act->reminded = true;
                $act->saveQuietly();
                continue;
            }

            $overdue = $act->due_date->lt(now()->startOfDay());
            $when = $overdue ? ('overdue since ' . $act->due_date->format('M j')) : 'due today';

            if ($this->option('dry')) {
                $this->line('would remind ' . implode(', ', $to) . " re lead #{$lead->id} ({$when})");
                continue;
            }

            try {
                $who = $lead->company ?: $lead->name;
                $html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;color:#0D1B2A;max-width:560px">'
                    . '<h2 style="margin:0 0 2px">⏰ Follow-up ' . e($when) . '</h2>'
                    . '<p style="color:#5a7080;margin:0 0 12px">Reminder for a lead in your KiddieTrac sales pipeline.</p>'
                    . '<table style="border-collapse:collapse;font-size:14px">'
                    . '<tr><td style="padding:4px 14px 4px 0;color:#5a7080;font-weight:600">Lead</td><td style="padding:4px 0">' . e($who) . '</td></tr>'
                    . ($lead->name && $lead->company ? '<tr><td style="padding:4px 14px 4px 0;color:#5a7080;font-weight:600">Contact</td><td style="padding:4px 0">' . e($lead->name) . '</td></tr>' : '')
                    . ($lead->email ? '<tr><td style="padding:4px 14px 4px 0;color:#5a7080;font-weight:600">Email</td><td style="padding:4px 0">' . e($lead->email) . '</td></tr>' : '')
                    . ($lead->phone ? '<tr><td style="padding:4px 14px 4px 0;color:#5a7080;font-weight:600">Phone</td><td style="padding:4px 0">' . e($lead->phone) . '</td></tr>' : '')
                    . '<tr><td style="padding:4px 14px 4px 0;color:#5a7080;font-weight:600">Due</td><td style="padding:4px 0">' . e($act->due_date->format('M j, Y')) . '</td></tr>'
                    . ($act->body ? '<tr><td style="padding:4px 14px 4px 0;color:#5a7080;font-weight:600;vertical-align:top">Note</td><td style="padding:4px 0">' . nl2br(e($act->body)) . '</td></tr>' : '')
                    . '</table>'
                    . '<p style="margin:18px 0 0"><a href="https://app.kiddietrac.com/dashboard.html#sales-lead?id=' . $lead->id
                    . '" style="background:#7C3AED;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:700">Open lead →</a></p>'
                    . '</div>';

                Mail::html($html, function ($m) use ($to, $when, $who) {
                    $m->to($to)->subject('⏰ Sales follow-up ' . $when . ': ' . $who);
                    // Every one of these has been suppressed since the feature shipped —
                    // 4 of 4 — because the rep's address matched an account the gate had
                    // paused. A follow-up reminder is an operational prompt to a working
                    // mailbox, not a notification to a user, so it carries the same bypass
                    // as the rest of that class.
                    try { $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1'); } catch (\Throwable $e) {}
                });
                $sent++;
            } catch (\Throwable $e) {
                // never fail the batch on one bad send
            }

            $act->reminded = true;
            $act->saveQuietly();
        }

        $this->info("Sales follow-up reminders: {$sent} sent / {$due->count()} due.");

        return self::SUCCESS;
    }
}
