<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p38 — Send the email channel for marketing campaigns.
 *
 * Picks up marketing_campaigns rows where:
 *   - channel is 'email' or 'both'
 *   - status is 'sent' (the in-portal write already happened in sendNow)
 *     AND email_sent_at is NULL  (not yet emailed)
 *   - OR status is 'scheduled' AND scheduled_for has passed
 *
 * Resolves audience -> email addresses, wraps the body in the branded
 * email shell via EmailTemplate (so each tenant's logo + colours apply),
 * sends through AgencyMailer (using their per-agency SMTP when set).
 *
 * Runs every 5 minutes via routes/console.php scheduler. With cron firing
 * `php artisan schedule:run` every minute, scheduled campaigns get delivered
 * within ~5 minutes of their scheduled_for time.
 */
final class SendCampaignEmailsCommand extends Command
{
    protected $signature = 'kiddietrac:campaigns-mail {--dry-run : Print to console, do not send} {--id= : Send a specific campaign id only} {--resend-to= : With --id: email an already-sent campaign again, ONLY to these comma-separated addresses it never reached}';
    protected $description = 'Deliver the email channel for marketing campaigns';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('id');
        if ((string) $this->option('resend-to') !== '') {
            return $this->resend((string) $this->option('resend-to'), $dry);
        }

        // Find campaigns that need to be emailed
        $now = Carbon::now();
        $q = DB::table('marketing_campaigns')
            ->whereIn('channel', ['email', 'both'])
            ->whereNull('deleted_at')
            ->whereNull('email_sent_at')
            ->where(function ($x) use ($now) {
                $x->where('status', 'sent')                              // sent via the in-portal path; needs email follow-up
                  ->orWhere(function ($y) use ($now) {
                      $y->where('status', 'scheduled')                   // pre-scheduled, no in-portal send yet
                        ->whereNotNull('scheduled_for')
                        ->where('scheduled_for', '<=', $now);
                  });
            });
        if ($only) $q->where('id', (int) $only);
        $campaigns = $q->get();

        $this->info("Found {$campaigns->count()} campaign(s) ready to email (dry=" . ($dry ? 'yes' : 'no') . ')');

        foreach ($campaigns as $c) {
            $agency = DB::table('agencies')->where('id', $c->agency_id)->first();
            if (!$agency) { $this->warn("Campaign #{$c->id}: agency {$c->agency_id} missing, skipping"); continue; }

            $everyone = $this->resolveAudience($c);
            // A stable order, so a paced send resumes at the right person (email_progress).
            usort($everyone, function ($x, $y) { return ((int) $x->id) <=> ((int) $y->id); });
            /* Who is EMAILED (2026-11-27): one email per address, and nobody without one. Two accounts on one address
               got two copies, and a parent with no email on file was counted as a failed send. Everyone in the
               audience still gets the in-app notice below. */
            $recipients = self::mailable($everyone);
            if (empty($recipients)) {
                $this->line("Campaign #{$c->id}: no recipients matched audience '{$c->audience}', marking emailed-with-zero");
                if (!$dry) {
                    DB::table('marketing_campaigns')->where('id', $c->id)->update([
                        'email_sent_at' => $now,
                        'email_delivery_count' => 0,
                        'updated_at' => $now,
                    ]);
                }
                continue;
            }

            // One builder for the send and the portal's View (MarketingController::show), so the two cannot differ.
            ['subject' => $subject, 'html' => $bodyHtml] = self::build($c, $agency);

            if ($dry) {
                $this->line("--- Campaign #{$c->id}: {$c->title} -> " . count($recipients) . ' recipients ---');
                $this->line('Subject: ' . $subject);
                $this->line('First recipient: ' . ($recipients[0]->email ?? '?'));
                continue;
            }

            /* SEND FROM (white-label, 2026-10-20). A campaign may name one of the agency's
               sender accounts; CampaignSender::forCampaign() honours it only for a white-label
               agency with the account still connected, else the agency default is used.
               A Gmail account is PACED: PER_RUN recipients a run, GAP_SECONDS apart, resuming
               from email_progress on the next run, so a personal mailbox never bursts. */
            $sender = \App\Support\CampaignSender::forCampaign($c, $agency);
            $paced = $sender && \App\Support\RsvpSender::isGmail($sender);
            $mailer = $sender ? null : AgencyMailer::forAgency((int) $agency->id);
            $fromUsed = $sender ? \App\Support\CampaignSender::address($sender) : $mailer->fromAddress();
            $start = (int) ($c->email_progress ?? 0);
            $batch = $paced ? array_slice($recipients, $start, \App\Support\CampaignSender::PER_RUN) : array_slice($recipients, $start);
            $delivered = 0; $failed = 0; $i = 0;
            foreach ($batch as $r) {
                if ($paced && $i++ > 0) { sleep(\App\Support\CampaignSender::GAP_SECONDS); }
                $out = $this->sendOne($c, $agency, $r, $sender, $mailer, $bodyHtml, $subject);
                if ($out === 'failed') { $failed++; } elseif ($out === 'sent') { $delivered++; }
            }
            $done = $start + count($batch) >= count($recipients);
            $totDelivered = (int) ($c->email_delivery_count ?? 0) + $delivered;
            $totFailed = (int) ($c->email_failure_count ?? 0) + $failed;

            DB::table('marketing_campaigns')->where('id', $c->id)->update([
                'email_sent_at' => $done ? $now : null,
                'email_progress' => $start + count($batch),
                'email_from' => $fromUsed ?: null,
                'email_delivery_count' => $totDelivered,
                'email_failure_count' => $totFailed,
                'status' => 'sent',
                'sent_at' => $c->sent_at ?: $now,
                'recipient_count' => $c->recipient_count ?: count($recipients),
                'delivery_count' => ($c->delivery_count ?? 0) + $delivered,
                'updated_at' => $now,
            ]);
            if (! $done) {
                $this->info("Campaign #{$c->id}: paced via {$fromUsed}, " . ($start + count($batch)) . ' / ' . count($recipients) . ' so far');
                continue;
            }
            $delivered = $totDelivered; $failed = $totFailed;

            \App\Support\Audit::write([
                'user_id' => null,
                // The campaign carries its own agency; a console row gets no stamp
                // from anywhere else.
                'agency_id' => (int) ($c->agency_id ?? 0) ?: null,
                'action' => 'campaign.email_sent',
                'entity_type' => 'marketing_campaign',
                'entity_id' => (int) $c->id,
                'payload' => json_encode(['delivered' => $delivered, 'failed' => $failed, 'audience' => $c->audience, 'from' => $fromUsed]),
                'created_at' => $now,
            ]);

            // v22p48: insert a notifications row per recipient so the
            // in-portal inbox shows the campaign even if the email lands
            // in spam or the user prefers to read inside the portal.
            $notifRows = [];
            self::writeBell($c, $everyone, $now);

            $this->info("Campaign #{$c->id}: emailed $delivered / " . count($recipients) . " (failed: $failed)");
        }
        return self::SUCCESS;
    }

    /** In-app notice per person (v22p48), so the inbox shows the campaign even if the email lands in spam. */
    private static function writeBell(object $c, array $people, $now): void
    {
        $notifRows = [];
        $bodyPreview = \App\Support\CampaignMerge::excerpt(strip_tags($c->body_html ?? ''), 200);
        foreach ($people as $r) {
            $notifRows[] = [
                'user_id' => (int) $r->id,
                'type' => 'marketing',
                'title' => \App\Support\CampaignMerge::text($c->title, $r->first_name ?? null),
                'body' => \App\Support\CampaignMerge::text($bodyPreview, $r->first_name ?? null),
                'data' => json_encode([
                    'url' => '/dashboard.html#announcements',
                    'campaign_id' => (int) $c->id,
                ]),
                'created_at' => $now,
            ];
        }
        // Chunk to keep INSERT statements bounded
        foreach (array_chunk($notifRows, 500) as $chunk) {
            \App\Support\Notify::write($chunk);
        }
    }

    /** One email per address (first account by id wins), and only people with an address. Order is kept. */
    private static function mailable(array $people): array
    {
        $seen = [];
        $out = [];
        foreach ($people as $r) {
            $e = mb_strtolower(trim((string) ($r->email ?? '')));
            if ($e === '' || isset($seen[$e])) continue;
            $seen[$e] = true;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * ONE RECIPIENT'S EMAIL. Returns what really happened, read back from the email log through CampaignSends::record
     * ('sent', 'suppressed', 'failed' or 'unknown'). "Sent" means our mail service accepted it - a message the mail
     * rules held back used to be counted as delivered because handing it to the mailer did not throw.
     */
    private function sendOne(object $c, object $agency, object $r, $sender, $mailer, string $bodyHtml, string $subject): string
    {
        /* PER-PERSON RECORD (2026-11-27, App\Support\CampaignSends): the message is kept so its email_logs row
           (open pixel token) can be tied to this campaign and person - Recipients & stats reads it. */
        $cap = null; $sendErr = null; $oneSubject = null; $startedAt = now();
        // Family campaigns reach parents who have not accepted their invite yet (MailScope::campaign); staff ones do not.
        $family = (string) $c->audience !== 'staff';
        try {
            /* {first_name} (2026-11-27): the frame was built once above; only the token is filled in here,
               per recipient, with their own first name (escaped), so batching and pacing are unchanged. */
            $oneHtml = \App\Support\CampaignMerge::html($bodyHtml, $r->first_name ?? null);
            $oneSubject = \App\Support\CampaignMerge::text($subject, $r->first_name ?? null);
            $build = function ($m) use ($oneSubject, $r, &$cap, $family) {
                $cap = $m;
                $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                $m->to($r->email, $name ?: $r->email)->subject($oneSubject);
                if ($family) {
                    \App\Support\MailScope::campaign($m);
                }
            };
            if ($sender) {
                \App\Support\RsvpSender::html($sender, (int) $agency->id, $oneHtml, $build);
            } else {
                $mailer->html($oneHtml, $build);
            }
        } catch (\Throwable $e) {
            $sendErr = $e;
            $this->warn('  send failed to ' . $r->email . ': ' . $e->getMessage());
        }
        $out = \App\Support\CampaignSends::record($c, $r, (string) ($oneSubject ?? $subject), $cap, $sendErr, $startedAt);

        // No per-person record available (table missing): fall back to whether the mailer threw.
        return $out ?? ($sendErr ? 'failed' : 'sent');
    }

    /**
     * RESEND TO THE PEOPLE A CAMPAIGN NEVER REACHED (2026-11-27). `--id=N --resend-to=a@x,b@y`.
     *
     * For an already-emailed campaign only, and only to the addresses named - never the whole audience again. Fails
     * closed: every address must be in the campaign's audience today (a typo or an outsider stops the whole run), and
     * anyone the campaign already reached (a 'sent' record or a 'sent' email log for it) is skipped. At most 80
     * addresses. Same builder, same sender, same per-person record and open tracking as the first send; the in-app
     * notice is written only for people who do not have one for this campaign yet. --dry-run lists, sends nothing.
     */
    private function resend(string $list, bool $dry): int
    {
        $id = (int) $this->option('id');
        $c = $id ? DB::table('marketing_campaigns')->where('id', $id)->whereNull('deleted_at')
            ->whereIn('channel', ['email', 'both'])->whereNotNull('email_sent_at')->first() : null;
        if (! $c) {
            $this->error('--resend-to needs --id of a campaign that has already been emailed.');
            return self::FAILURE;
        }
        $agency = DB::table('agencies')->where('id', $c->agency_id)->first();
        if (! $agency) { $this->error('Agency missing.'); return self::FAILURE; }

        $want = [];
        foreach (preg_split('/[\s,;]+/', $list) as $e) {
            $e = mb_strtolower(trim($e));
            if ($e === '') continue;
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) { $this->error("Not an email address: $e"); return self::FAILURE; }
            $want[$e] = true;
        }
        $want = array_keys($want);
        if (! $want) { $this->error('No addresses given.'); return self::FAILURE; }
        if (count($want) > 80) { $this->error('More than 80 addresses: refused.'); return self::FAILURE; }

        $everyone = $this->resolveAudience($c);
        usort($everyone, fn ($x, $y) => ((int) $x->id) <=> ((int) $y->id));
        $byAddr = [];
        foreach (self::mailable($everyone) as $r) $byAddr[mb_strtolower(trim((string) $r->email))] = $r;
        $outside = array_values(array_diff($want, array_keys($byAddr)));
        if ($outside) {
            $this->error('Not in this campaign\'s audience, nothing sent: ' . implode(', ', $outside));
            return self::FAILURE;
        }

        ['subject' => $subject, 'html' => $bodyHtml] = self::build($c, $agency);
        $already = \App\Support\CampaignSends::ready()
            ? DB::table('marketing_campaign_sends')->where('campaign_id', $c->id)->where('outcome', 'sent')->pluck('email')
                ->map(fn ($e) => mb_strtolower(trim((string) $e)))->all() : [];
        $todo = [];
        foreach ($want as $e) {
            $r = $byAddr[$e];
            $logged = DB::table('email_logs')->whereRaw('LOWER(TRIM(to_email)) = ?', [$e])
                ->where('created_at', '>=', Carbon::parse($c->sent_at)->subMinutes(10))
                ->whereIn('status', ['sent', 'bounced'])
                ->where('subject', \App\Support\CampaignMerge::text($subject, $r->first_name ?? null))->exists();
            if (in_array($e, $already, true) || $logged) {
                $this->line("  skip $e: already received this campaign");
                continue;
            }
            $todo[] = $r;
        }
        $this->info("Campaign #{$c->id}: " . count($todo) . ' to send (dry=' . ($dry ? 'yes' : 'no') . ')');
        if ($dry || ! $todo) {
            foreach ($todo as $r) $this->line('  would send to ' . $r->email . ' (user ' . $r->id . ')');
            return self::SUCCESS;
        }

        $sender = \App\Support\CampaignSender::forCampaign($c, $agency);
        $paced = $sender && \App\Support\RsvpSender::isGmail($sender);
        $mailer = $sender ? null : AgencyMailer::forAgency((int) $agency->id);
        $tally = ['sent' => 0, 'suppressed' => 0, 'failed' => 0, 'unknown' => 0];
        $reached = [];
        foreach ($todo as $i => $r) {
            if ($i > 0) sleep($paced ? \App\Support\CampaignSender::GAP_SECONDS : 1);
            $out = $this->sendOne($c, $agency, $r, $sender, $mailer, $bodyHtml, $subject);
            $tally[$out] = ($tally[$out] ?? 0) + 1;
            $this->line("  {$r->email}: $out");
            if ($out === 'sent') $reached[] = $r;
        }

        // In-app notice only for people who have none for this campaign (the first run wrote one for everyone it listed).
        $hasBell = DB::table('notifications')->where('type', 'marketing')
            ->whereRaw("JSON_EXTRACT(data, '$.campaign_id') = ?", [(int) $c->id])->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $needBell = array_values(array_filter($reached, fn ($r) => ! in_array((int) $r->id, $hasBell, true)));
        if ($needBell) self::writeBell($c, $needBell, now());

        /* The counts, from what really happened: every person this campaign reached by email, from the per-person
           records (they cover the first run and this one). Failures add on. */
        $sentTotal = \App\Support\CampaignSends::ready()
            ? DB::table('marketing_campaign_sends')->where('campaign_id', $c->id)->where('outcome', 'sent')->count()
            : (int) $c->email_delivery_count + $tally['sent'];
        $upd = [
            'email_delivery_count' => $sentTotal,
            'email_failure_count' => (int) ($c->email_failure_count ?? 0) + $tally['failed'],
            'updated_at' => now(),
        ];
        if ($c->channel === 'email') $upd['delivery_count'] = $sentTotal;   // 'both': delivery_count is the News count
        DB::table('marketing_campaigns')->where('id', $c->id)->update($upd);

        \App\Support\Audit::write([
            'user_id' => null,
            'agency_id' => (int) ($c->agency_id ?? 0) ?: null,
            'action' => 'campaign.email_resent',
            'entity_type' => 'marketing_campaign',
            'entity_id' => (int) $c->id,
            'payload' => json_encode(['asked' => count($want), 'tried' => count($todo)] + $tally),
            'created_at' => now(),
        ]);
        $this->info("Campaign #{$c->id} resend: " . json_encode($tally) . "; campaign now counts $sentTotal emailed");

        return self::SUCCESS;
    }

    /**
     * THE CAMPAIGN EMAIL, BUILT ONCE (2026-11-27). Pure: no sends, no writes. {first_name} is still in the result;
     * the send fills it per recipient (CampaignMerge::html / ::text) and the portal's View fills it with a sample
     * name. 'email' => 'announcement' names the catalogue entry, so an agency's own wording applies the same way
     * whoever calls this.
     *
     * @return array{subject:string, html:string, preheader:string}
     */
    public static function build(object $c, object $agency): array
    {
        // Families see the agency's name without Inc / Ltd (FamilyFacingName, 2026-11-27).
        $name = \App\Support\FamilyFacingName::agency((int) $agency->id) ?: (string) $agency->name;
        $subjectFallback = '[' . $name . '] ' . $c->title;
        $subject = $c->subject ?: $subjectFallback;
        $preheader = \App\Support\CampaignMerge::excerpt(strip_tags((string) $c->body_html), 140);
        $html = EmailTemplate::wrap((int) $agency->id, (string) $c->body_html, [
            'email'     => 'announcement',
            'eyebrow'   => 'A MESSAGE FROM',
            'title'     => $name,
            'subtitle'  => $c->title,
            'preheader' => $preheader,
            'footer_note' => 'You\'re receiving this because you are connected to ' . htmlspecialchars($name) . '. To stop receiving marketing emails, contact us at ' . ($agency->brand_support_email ?: $agency->contact_email ?: 'support@kiddietrac.com') . '.',
        ]);

        return ['subject' => $subject, 'html' => $html, 'preheader' => $preheader];
    }

    /**
     * Mirror of MarketingController::resolveAudience but joins users to pluck
     * actual email addresses for delivery.
     */
    private function resolveAudience(object $c): array
    {
        $centreScope = $c->centre_id ? [(int) $c->centre_id]
            : \App\Support\Live::centreIds((int) $c->agency_id);

        if ($c->audience === 'staff') {
            return DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', true)
                ->whereIn('ra.role', ['agency_admin', 'centre_director', 'educator'])
                ->where(function ($q) use ($c, $centreScope) {
                    $q->where('ra.agency_id', $c->agency_id)->orWhereIn('ra.centre_id', $centreScope);
                })
                ->whereNull('u.deleted_at')->whereNotNull('u.email')
                ->tap(fn ($q) => \App\Support\AgencyOutsiders::exclude($q, 'u.id'))
                ->distinct()->select('u.id', 'u.email', 'u.first_name', 'u.last_name')->get()->all();
        }

        /* FAMILIES STILL WITH US, PEOPLE STILL ABLE TO SIGN IN (2026-11-27). This read every guardian row at the
           agency's centres, so 22 parents of families that left long ago (de-enrolled, departure applied) were in
           "Get the KiddieTrac app" and only the mail gate's closed-account check stopped them. Live::families is the
           one rule for "has not left"; a suspended or deactivated account is not a recipient either. An account
           still waiting on its invite IS one: family campaigns reach them (MailScope::campaign). */
        $base = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereIn('f.centre_id', $centreScope)
            ->tap(fn ($q) => \App\Support\Live::families($q, 'f'))
            ->whereNull('u.deleted_at')
            ->where(fn ($w) => $w->whereNull('u.status')->orWhereNotIn('u.status', ['suspended', 'deactivated']))
            ->whereNotNull('u.email');
        if ($c->audience === 'active_families') {
            $base->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('children as ch')
                    ->whereColumn('ch.family_id', 'f.id')
                    ->where('ch.enrollment_status', 'enrolled')
                    ->whereNull('ch.deleted_at');
            });
        } elseif ($c->audience === 'waitlist') {
            $base->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('children as ch')
                    ->whereColumn('ch.family_id', 'f.id')
                    ->where('ch.enrollment_status', 'waitlist');
            });
        } elseif ($c->audience === 'prospects') {
            $base->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('children as ch')
                    ->whereColumn('ch.family_id', 'f.id')
                    ->where('ch.enrollment_status', 'enrolled');
            });
        }
        return $base->distinct()
            ->select('u.id', 'u.email', 'u.first_name', 'u.last_name')
            ->get()->all();
    }
}
