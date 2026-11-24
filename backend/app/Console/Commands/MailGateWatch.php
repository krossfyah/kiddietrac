<?php

namespace App\Console\Commands;

use App\Listeners\SuppressAgencyMail;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Form mail must never be withheld from a parent who has not claimed their account (2026-10-25).
 * Daily: any email in the last 26 hours that the gate suppressed and whose subject is form mail is
 * reported to the platform admins (bell) and listed here. With the subject guard in place this should
 * always be zero; a non-zero count means a new sender or gate needs fixing.
 */
class MailGateWatch extends Command
{
    protected $signature = 'kt:mail-gate-watch {--hours=26} {--fix : resend each withheld form email (from its logged body) to the parent}';
    protected $description = 'Report form emails the suppression gate withheld from parents';

    public function handle(): int
    {
        $since = now()->subHours(max(1, (int) $this->option('hours')));
        $rows = DB::table('email_logs')->where('status', 'suppressed')->where('created_at', '>=', $since)->get(['id', 'agency_id', 'to_email', 'subject', 'error']);
        $hits = $rows->filter(fn ($r) => preg_match(SuppressAgencyMail::FORM_MAIL_RX, preg_replace('/^\[SUPPRESSED\]\s*/', '', (string) $r->subject))
            // Only what the not-onboarded gate withheld: an agency switch, a kill-switch or a deactivated account is deliberate.
            && str_contains((string) $r->error, 'not accepted their invite'))->values();
        $resent = 0;
        foreach ($hits as $h) {
            $this->warn("  #{$h->id} {$h->to_email}: {$h->subject} ({$h->error})");
            if (! $this->option('fix')) continue;
            /* RESEND (2026-10-25): from the logged body, once per withheld row, with the form-mail header so the gate
               lets it through. Skipped when the same subject already reached that address afterwards. */
            $subj = preg_replace('/^\[SUPPRESSED\]\s*/', '', (string) $h->subject);
            if (DB::table('email_logs')->where('to_email', $h->to_email)->where('subject', $subj)->where('status', 'sent')->where('id', '>', $h->id)->exists()) continue;
            if (DB::table('email_logs')->where('id', $h->id)->value('error') === 'resent by kt:mail-gate-watch') continue;
            $row = DB::table('email_logs')->where('id', $h->id)->first(['body_html', 'agency_id']);
            if (! $row || strlen((string) $row->body_html) < 200) continue;
            try {
                \Illuminate\Support\Facades\Mail::html((string) $row->body_html, function ($m) use ($h, $subj, $row) {
                    $m->to($h->to_email)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($subj);
                    if ($row->agency_id) $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $row->agency_id);
                    $m->getHeaders()->addTextHeader('X-KT-Form-Package', '1');
                });
                DB::table('email_logs')->where('id', $h->id)->update(['error' => 'resent by kt:mail-gate-watch']);
                $resent++;
            } catch (\Throwable $e) { report($e); }
        }
        if ($hits->count()) {
            $admins = DB::table('role_assignments')->where('role', 'platform_admin')->where('active', 1)->pluck('user_id')->unique();
            Notify::write($admins->map(fn ($u) => ['user_id' => (int) $u, 'type' => 'mail_gate', 'title' => '⚠️ ' . $hits->count() . ' form email(s) were withheld from parents',
                'body' => $hits->take(3)->map(fn ($h) => $h->to_email . ': ' . preg_replace('/^\[SUPPRESSED\]\s*/', '', (string) $h->subject))->implode(' · '), 'data' => json_encode(['link' => '#email-logs']), 'created_at' => now()])->values()->all());
        }
        $this->info($hits->count() . ' withheld form email(s) in the last ' . (int) $this->option('hours') . 'h' . ($this->option('fix') ? ", {$resent} resent" : ''));
        return self::SUCCESS;
    }
}
