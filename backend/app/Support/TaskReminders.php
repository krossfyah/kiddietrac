<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TASK REMINDERS (2026-10-27, Anthony: "when you assign a task make sure you can also set an
 * option to set reminders, send via email, text, app, voice").
 *
 * A task can carry one reminder: a moment (stored UTC, entered and shown in the AGENCY's time
 * zone) and the ways to deliver it - app (bell + push), email, text, voice call. The
 * kiddietrac:task-reminders job (every 5 minutes) sends each one once, to the person the task
 * is assigned to, and records per channel what happened. A task marked done before its moment
 * is never reminded.
 *
 * Every channel goes through the portal's one sender for it, so nothing new can reach a person
 * the rest of the portal would not: texts through SmsController::sendOne (consent, STOP,
 * suppression), calls through VoiceController::callOne (agency voice switch, the person's own
 * "don't call me", do-not-ring, consent), email skipped for accounts that cannot sign in.
 * "sent" in the result means the channel took it; a skipped or failed channel says so.
 */
final class TaskReminders
{
    public const CHANNELS = ['app', 'email', 'sms', 'voice'];
    public const LABELS = ['app' => 'App', 'email' => 'Email', 'sms' => 'Text', 'voice' => 'Voice call'];

    /** "2026-10-28T08:00" in the agency's time zone -> UTC, or null. */
    public static function toUtc(?string $local, int $agencyId): ?Carbon
    {
        $local = trim((string) $local);
        if ($local === '') return null;
        try {
            return Carbon::parse(str_replace('T', ' ', $local), AgencyTime::tz($agencyId))->utc();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** UTC -> "2026-10-28T08:00" in the agency's time zone (for the form). */
    public static function toLocal($utc, int $agencyId): ?string
    {
        if (! $utc) return null;
        return Carbon::parse((string) $utc, 'UTC')->tz(AgencyTime::tz($agencyId))->format('Y-m-d\TH:i');
    }

    /** Known channels only, in a fixed order. */
    public static function channels($in): array
    {
        $in = is_array($in) ? $in : (json_decode((string) $in, true) ?: []);
        return array_values(array_intersect(self::CHANNELS, array_map('strval', $in)));
    }

    /** Reminders whose moment has come and that have not been sent. */
    public static function due(): \Illuminate\Support\Collection
    {
        return DB::table('tasks')
            ->whereNull('deleted_at')->whereNull('reminded_at')
            ->whereNotNull('remind_at')->where('remind_at', '<=', now('UTC'))
            ->where('status', '!=', 'done')
            ->orderBy('remind_at')->limit(200)->get();
    }

    /** Send one task's reminder. Returns [channel => 'sent' | 'skipped: why' | 'failed: why']. */
    /** $kind: 'reminder' (the scheduled one) or 'assigned' (sent the moment it is assigned, on $channels). */
    public static function send(object $t, bool $dry = false, string $kind = 'reminder', ?array $channels = null): array
    {
        $agencyId = (int) $t->agency_id;
        $u = DB::table('users')->where('id', $t->assigned_to)->first(['id', 'first_name', 'email', 'phone', 'status', 'deleted_at']);
        $by = $t->assigned_by ? DB::table('users')->where('id', $t->assigned_by)->value('first_name') : null;   // first names only
        $isNew = $kind === 'assigned';
        $channels = self::channels($channels ?? ($isNew ? [] : $t->remind_channels));
        $out = [];
        // The portal's own list of accounts that cannot sign in (Audience::OFF_STATUSES). Recorded as handled,
        // so a reminder for somebody who has left is not retried every five minutes.
        if (! $u || $u->deleted_at || in_array((string) ($u->status ?? ''), Audience::OFF_STATUSES, true)) {
            foreach ($channels as $c) $out[$c] = 'skipped: the person cannot sign in';
            if (! $dry && ! $isNew) DB::table('tasks')->where('id', $t->id)->update(['reminded_at' => now(), 'remind_result' => json_encode($out), 'updated_at' => now()]);
            return $out;
        }
        $tz = AgencyTime::tz($agencyId);
        $due = $t->due_date ? Carbon::parse((string) $t->due_date, $tz)->format('l, F j') : null;
        $title = (string) $t->title;
        $line = ($isNew ? 'New task: ' : 'Reminder: ') . $title . ($due ? ' (due ' . $due . ')' : '');
        $first = trim((string) $u->first_name) ?: 'there';

        foreach ($channels as $c) {
            if ($dry) { $out[$c] = 'dry run'; continue; }
            try {
                if ($c === 'app') {
                    Notify::write(['agency_id' => $agencyId, 'user_id' => (int) $u->id, 'type' => $isNew ? 'task_assigned' : 'task_reminder', 'title' => $isNew ? '📋 New task' . ($by ? ' from ' . $by : '') : '⏰ Task reminder',
                        'body' => mb_substr($title . ($due ? ' · due ' . $due : ''), 0, 1000), 'data' => json_encode(['link' => '#my-tasks']), 'created_at' => now()]);
                    try { app(\App\Services\FcmService::class)->sendToUser((int) $u->id, $isNew ? 'New task' . ($by ? ' from ' . $by : '') : 'Task reminder', mb_substr($title, 0, 140), '#my-tasks'); } catch (\Throwable $e) {}
                    $out[$c] = 'sent';
                } elseif ($c === 'email') {
                    $em = mb_strtolower(trim((string) $u->email));
                    if (! filter_var($em, FILTER_VALIDATE_EMAIL)) { $out[$c] = 'skipped: no email address'; continue; }
                    $body = '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">Hi ' . e($first) . ', ' . ($isNew ? 'you have a new task' : 'this is a reminder about a task')
                        . ($by ? ' from ' . e($by) : '') . '.</p>'
                        . EmailTemplate::calloutBox('<strong>' . e($title) . '</strong>' . ($due ? '<br>Due ' . e($due) : '')
                            . ($t->description ? '<br><br>' . nl2br(e(mb_substr((string) $t->description, 0, 1500))) : ''), 'info')
                        . '<p style="margin:14px 0 0;font-size:14px;color:#334155;line-height:1.6">Open <b>My tasks</b> in KiddieTrac to mark it in progress or done.</p>'
                        . '<div style="margin:16px 0 4px">' . EmailTemplate::button('Open my tasks', 'https://app.kiddietrac.com/dashboard.html#my-tasks') . '</div>';
                    $html = EmailTemplate::wrap($agencyId, $body, ['eyebrow' => $isNew ? 'NEW TASK' : 'TASK REMINDER', 'title' => $title, 'subtitle' => $due ? 'Due ' . $due : '', 'preheader' => $line]);
                    AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to($em)->subject($line));
                    $out[$c] = 'sent';
                } elseif ($c === 'sms') {
                    if (! trim((string) $u->phone)) { $out[$c] = 'skipped: no phone number'; continue; }
                    $ok = app(\App\Http\Controllers\Api\SmsController::class)->sendOne($agencyId, (int) $u->id, (string) $u->phone,
                        mb_substr($line . ($by ? ' — from ' . $by : '') . '. Open My tasks in KiddieTrac.', 0, 300), 'task');
                    $out[$c] = $ok ? 'sent' : 'skipped: not sent (consent, opt-out or texting is off — see the text log)';
                } elseif ($c === 'voice') {
                    if (! trim((string) $u->phone)) { $out[$c] = 'skipped: no phone number'; continue; }
                    $script = 'Hello ' . $first . '. ' . ($isNew ? 'You have a new task in KiddieTrac' . ($by ? ' from ' . $by : '') . '. ' : 'This is a KiddieTrac task reminder. ') . $title . '.'
                        . ($due ? ' It is due ' . $due . '.' : '') . ' Please open My tasks in the KiddieTrac app. Goodbye.';
                    $ok = app(\App\Http\Controllers\Api\VoiceController::class)->callOne($agencyId, (int) $u->id, (string) $u->phone, $script, 'task',
                        $t->assigned_by ? (int) $t->assigned_by : null);
                    $out[$c] = $ok ? 'sent' : 'skipped: not called (calls off, opt-out or no consent — see the call log)';
                }
            } catch (\Throwable $e) {
                Log::warning('task reminder failed', ['task' => $t->id, 'channel' => $c, 'error' => $e->getMessage()]);
                $out[$c] = 'failed: ' . mb_substr($e->getMessage(), 0, 160);
            }
        }
        if (! $dry && ! $isNew) {
            DB::table('tasks')->where('id', $t->id)->update(['reminded_at' => now(), 'remind_result' => json_encode($out), 'updated_at' => now()]);
        }
        return $out;
    }
}
