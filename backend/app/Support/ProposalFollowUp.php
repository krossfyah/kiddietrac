<?php

namespace App\Support;

use App\Http\Controllers\Api\SalesController;
use App\Models\SalesActivity;
use App\Models\SalesQuote;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * AUTOMATIC PROPOSAL FOLLOW-UP (2026-10-23, Anthony: "add feature to follow up with proposal
 * based on x timeframe as well (system will follow-up) and then mark the lead as followed-up").
 *
 * Chosen when the proposal is emailed (or later from its menu): follow up after N days, up to
 * M times. Each due follow-up is an open "⏰ Follow-up" task on the lead (so it shows in Sales →
 * Follow-ups and the calendar); kt:proposal-followups (hourly) sends it on a weekday between
 * 9 a.m. and 5 p.m. Eastern: a short note from the person who prepared the proposal, the
 * private review-and-sign link and the PDF again. The task is then marked done ("followed up
 * automatically"), the email is logged on the lead, and the next one is scheduled.
 * It stops by itself once the proposal is signed or declined, or the lead is won, lost or gone.
 */
final class ProposalFollowUp
{
    private const TZ = 'America/Toronto';

    /** The next weekday, N days on, at 10 a.m. Eastern. */
    public static function nextAt(int $days, ?Carbon $from = null): Carbon
    {
        $t = ($from ?: Carbon::now(self::TZ))->copy()->tz(self::TZ)->addDays(max(1, $days))->setTime(10, 0);
        while ($t->isWeekend()) $t->addDay();
        return $t->utc();
    }

    /** Turn on / change / stop (days = 0) the follow-ups for one proposal. */
    public static function schedule(SalesQuote $q, int $days, int $max, ?int $userId = null): void
    {
        self::closeTask($q, 'Automatic follow-up ' . ($days > 0 ? 'rescheduled' : 'stopped'));
        if ($days <= 0) {
            DB::table('sales_quotes')->where('id', $q->id)->update(['followup_days' => null, 'followup_max' => null, 'followup_next_at' => null, 'followup_task_id' => null]);
            return;
        }
        $max = max(1, min(5, $max));
        $sent = (int) ($q->followups_sent ?? 0);
        if ($sent >= $max) $max = $sent + 1;
        $next = self::nextAt($days);
        $taskId = self::openTask($q, $next, $sent + 1, $max, $userId);
        DB::table('sales_quotes')->where('id', $q->id)->update(['followup_days' => $days, 'followup_max' => $max, 'followup_next_at' => $next, 'followup_task_id' => $taskId]);
    }

    private static function openTask(SalesQuote $q, Carbon $at, int $n, int $max, ?int $userId): ?int
    {
        try {
            $a = SalesActivity::create(['lead_id' => $q->lead_id, 'user_id' => $userId, 'type' => 'followup', 'done' => false,
                'due_date' => $at->copy()->tz(self::TZ)->toDateString(),
                'body' => 'Automatic follow-up on proposal ' . ($q->number ?: '') . ' (' . $n . ' of ' . $max . '): KiddieTrac emails it on '
                    . $at->copy()->tz(self::TZ)->format('D M j') . ' unless it has been signed by then.']);
            DB::table('sales_leads')->where('id', $q->lead_id)->update(['follow_up_date' => $at->copy()->tz(self::TZ)->toDateString()]);
            return (int) $a->id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function closeTask(SalesQuote $q, string $why): void
    {
        if (empty($q->followup_task_id)) return;
        $t = DB::table('sales_activities')->where('id', $q->followup_task_id)->where('done', 0)->first(['id', 'body']);
        if ($t) DB::table('sales_activities')->where('id', $t->id)->update(['done' => 1, 'done_at' => now(), 'body' => $t->body . "\n" . $why . '.', 'updated_at' => now()]);
    }

    /** Hourly: send what is due (weekdays 9-5 ET), stop what no longer needs it. */
    public static function run(bool $dry = false): array
    {
        $out = ['sent' => 0, 'stopped' => 0, 'waiting' => 0];
        $now = Carbon::now(self::TZ);
        $business = ! $now->isWeekend() && $now->hour >= 9 && $now->hour < 17;
        foreach (SalesQuote::with('lead')->whereNotNull('followup_next_at')->get() as $q) {
            $lead = $q->lead;
            $why = null;
            if ($q->client_signed_at) $why = 'stopped: the proposal was signed';
            elseif ($q->status === 'declined' || $q->declined_at) $why = 'stopped: the proposal was declined';
            elseif (! $lead || $lead->deleted_at || $lead->status !== 'open') $why = 'stopped: the lead is closed';
            elseif (! $lead->email) $why = 'stopped: the lead has no email address';
            if ($why) {
                if (! $dry) { self::closeTask($q, 'Automatic follow-up ' . $why); DB::table('sales_quotes')->where('id', $q->id)->update(['followup_next_at' => null, 'followup_task_id' => null]); }
                $out['stopped']++;
                continue;
            }
            if (Carbon::parse($q->followup_next_at)->isFuture() || ! $business) { $out['waiting']++; continue; }
            if ($dry) { $out['sent']++; continue; }
            if (self::send($q)) $out['sent']++;
        }
        return $out;
    }

    /** Send one follow-up now and move to the next (or finish). */
    public static function send(SalesQuote $q): bool
    {
        $lead = $q->lead;
        $n = (int) $q->followups_sent + 1;
        try {
            $pb = SalesController::preparedByOf($q);
            $signUrl = ($q->sign_token && ! $q->client_signed_at) ? SalesController::signUrl($q) : null;
            $bytes = app(SalesController::class)->pdfBytesOf($q);
            $html = self::html($q, $lead, $pb, $signUrl, $n);
            Mail::html($html, function ($m) use ($lead, $q, $pb, $bytes) {
                MailScope::platform($m);
                $m->to($lead->email, $lead->name ?: null)->subject('Following up: your KiddieTrac proposal ' . ($q->number ?: ''));
                if (! empty($pb['email']) && filter_var($pb['email'], FILTER_VALIDATE_EMAIL)) $m->replyTo($pb['email'], $pb['name'] ?: 'KiddieTrac Sales');
                $m->attachData($bytes, ($q->number ?: 'proposal') . '.pdf', ['mime' => 'application/pdf']);
            });
        } catch (\Throwable $e) {
            Log::warning('Proposal follow-up not sent', ['quote' => $q->id, 'error' => $e->getMessage()]);
            return false;
        }
        // The task is done, the email is on the lead, and the next one is booked.
        self::closeTask($q, 'Followed up automatically on ' . Carbon::now(self::TZ)->format('D M j, g:i A') . ' ET');
        SalesActivity::create(['lead_id' => $lead->id, 'user_id' => null, 'type' => 'email', 'done' => true, 'due_date' => now()->toDateString(),
            'body' => 'Followed up on proposal ' . ($q->number ?: '') . ' automatically (' . $n . ' of ' . (int) $q->followup_max . ') — emailed ' . $lead->email . ' with the sign link and the PDF.']);
        $more = $n < (int) $q->followup_max;
        $next = $more ? self::nextAt((int) $q->followup_days) : null;
        $q->followups_sent = $n;
        $taskId = $more ? self::openTask($q, $next, $n + 1, (int) $q->followup_max, null) : null;
        DB::table('sales_quotes')->where('id', $q->id)->update(['followups_sent' => $n, 'last_followup_at' => now(), 'followup_next_at' => $next, 'followup_task_id' => $taskId]);
        DB::table('sales_leads')->where('id', $lead->id)->update(['last_activity_at' => now()] + ($more ? [] : ['follow_up_date' => null]));
        return true;
    }

    public static function html(SalesQuote $q, $lead, array $pb, ?string $signUrl, int $n): string
    {
        $navy = '#1E2A5E'; $teal = '#1F6080'; $muted = '#5B6B7C';
        $first = trim((string) strtok((string) ($lead->name ?: ''), ' ')) ?: 'there';
        $org = $lead->company ?: 'your centre';
        $rep = $pb['name'] ?: 'The KiddieTrac Team';
        $sentOn = $q->sent_at ? Carbon::parse($q->sent_at)->tz(self::TZ)->format('F j') : null;
        $repLine = implode(' · ', array_filter([$pb['title'] ? e($pb['title']) : null,
            $pb['email'] ? '<a href="mailto:' . e($pb['email']) . '" style="color:' . $teal . ';text-decoration:none">' . e($pb['email']) . '</a>' : null,
            $pb['phone'] ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $pb['phone'])) . '" style="color:' . $teal . ';text-decoration:none">' . e($pb['phone']) . '</a>' : null])) ?: 'KiddieTrac';
        $lead1 = $n === 1
            ? 'I wanted to follow up on the proposal I sent' . ($sentOn ? ' on ' . e($sentOn) : '') . ' for ' . e($org) . ', and see whether you had any questions.'
            : 'Just checking in once more on the proposal for ' . e($org) . '. I know things get busy, so I am happy to make this easy.';
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#EEF3F6">'
            . '<div style="display:none;max-height:0;overflow:hidden">A quick follow-up on your KiddieTrac proposal.</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF3F6"><tr><td align="center" style="padding:24px 10px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#0D1B2A">'
            . '<tr><td bgcolor="#081C41" style="background:#081C41;padding:22px 24px;text-align:center"><img src="https://api.kiddietrac.com/email/wordmark-tagline-navy.png" width="230" alt="KiddieTrac - Smart Childcare Management Platform" style="display:block;margin:0 auto;border:0;width:230px;max-width:75%;height:auto"></td></tr>'
            . '<tr><td bgcolor="#1FA2A6" style="font-size:0;line-height:0;background:#1FA2A6"><img src="https://api.kiddietrac.com/email/brand-gradient.png" width="600" height="6" alt="" style="display:block;border:0;width:100%;height:6px"></td></tr>'
            . '<tr><td style="padding:28px 40px 6px">'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65">Hi ' . e($first) . ',</p>'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65">' . $lead1 . '</p>'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65">If it would help, I can walk your team through KiddieTrac on a short call, or answer anything about the setup, pricing or how we would get your families and staff started. Just reply to this email.</p>'
            . ($signUrl ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px auto 6px"><tr><td align="center" bgcolor="#1F6080" style="border-radius:10px;background:#1F6080">'
                . '<a href="' . e($signUrl) . '" style="display:inline-block;padding:14px 26px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px">&#9997;&#65039; Review &amp; sign online</a></td></tr></table>'
                . '<div style="text-align:center;font-size:12px;color:' . $muted . ';margin:0 0 6px">The proposal is attached again for convenience. Your link is private to you.</div>' : '<p style="font-size:14px;color:' . $muted . '">The proposal is attached again for convenience.</p>')
            . '<p style="margin:18px 0 26px;font-size:15px;line-height:1.6">Warm regards,<br><strong style="color:' . $navy . '">' . e($rep) . '</strong><br><span style="color:' . $muted . ';font-size:13.5px">' . $repLine . '</span></p>'
            . '</td></tr>'
            . '<tr><td style="padding:0 40px 24px;font-size:11.5px;line-height:1.5;color:#7A8B9A">This proposal is private and confidential and intended only for ' . e($org) . '.</td></tr>'
            . '<tr><td style="background:' . $navy . ';padding:16px 32px;text-align:center;font-size:12px;line-height:1.6;color:#C9D3E6"><strong style="color:#ffffff">KiddieTrac</strong> · Smart Childcare Management Platform<br>'
            . '<a href="https://www.kiddietrac.com" style="color:#9FE0F0;text-decoration:none">kiddietrac.com</a></td></tr>'
            . '</table>' . ProposalTracking::pixel((int) $q->id, 'followup') . '</td></tr></table></body></html>';
    }
}
