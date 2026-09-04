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
    protected $signature = 'kiddietrac:campaigns-mail {--dry-run : Print to console, do not send} {--id= : Send a specific campaign id only}';
    protected $description = 'Deliver the email channel for marketing campaigns';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('id');

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

            $recipients = $this->resolveAudience($c);
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

            $brand = $agency->brand_primary_color ?: '#1F6080';
            $subjectFallback = '[' . $agency->name . '] ' . $c->title;
            $subject = $c->subject ?: $subjectFallback;
            $bodyHtml = EmailTemplate::wrap((int) $agency->id, $c->body_html, [
                'eyebrow'   => 'A MESSAGE FROM',
                'title'     => $agency->name,
                'subtitle'  => $c->title,
                'preheader' => mb_strimwidth(strip_tags($c->body_html), 0, 140, '…'),
                'footer_note' => 'You\'re receiving this because you are connected to ' . htmlspecialchars($agency->name) . '. To stop receiving marketing emails, contact us at ' . ($agency->brand_support_email ?: $agency->contact_email ?: 'support@kiddietrac.com') . '.',
            ]);

            if ($dry) {
                $this->line("--- Campaign #{$c->id}: {$c->title} -> " . count($recipients) . ' recipients ---');
                $this->line('Subject: ' . $subject);
                $this->line('First recipient: ' . ($recipients[0]->email ?? '?'));
                continue;
            }

            $mailer = AgencyMailer::forAgency((int) $agency->id);
            $delivered = 0; $failed = 0;
            foreach ($recipients as $r) {
                try {
                    $mailer->mailer()->html($bodyHtml, function ($m) use ($subject, $r) {
                        $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                        $m->to($r->email, $name ?: $r->email)->subject($subject);
                    });
                    $delivered++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn('  send failed to ' . $r->email . ': ' . $e->getMessage());
                }
            }

            DB::table('marketing_campaigns')->where('id', $c->id)->update([
                'email_sent_at' => $now,
                'email_delivery_count' => $delivered,
                'email_failure_count' => $failed,
                'status' => 'sent',
                'sent_at' => $c->sent_at ?: $now,
                'recipient_count' => $c->recipient_count ?: count($recipients),
                'delivery_count' => ($c->delivery_count ?? 0) + $delivered,
                'updated_at' => $now,
            ]);

            \App\Support\Audit::write([
                'user_id' => null,
                // The campaign carries its own agency; a console row gets no stamp
                // from anywhere else.
                'agency_id' => (int) ($c->agency_id ?? 0) ?: null,
                'action' => 'campaign.email_sent',
                'entity_type' => 'marketing_campaign',
                'entity_id' => (int) $c->id,
                'payload' => json_encode(['delivered' => $delivered, 'failed' => $failed, 'audience' => $c->audience]),
                'created_at' => $now,
            ]);

            // v22p48: insert a notifications row per recipient so the
            // in-portal inbox shows the campaign even if the email lands
            // in spam or the user prefers to read inside the portal.
            $notifRows = [];
            $bodyPreview = mb_strimwidth(strip_tags($c->body_html ?? ''), 0, 200, '…');
            foreach ($recipients as $r) {
                $notifRows[] = [
                    'user_id' => (int) $r->id,
                    'type' => 'marketing',
                    'title' => $c->title,
                    'body' => $bodyPreview,
                    'data' => json_encode([
                        'url' => '/dashboard.html#announcements',
                        'campaign_id' => (int) $c->id,
                    ]),
                    'created_at' => $now,
                ];
            }
            if (!empty($notifRows)) {
                // Chunk to keep INSERT statements bounded
                foreach (array_chunk($notifRows, 500) as $chunk) {
                    DB::table('notifications')->insert($chunk);
                }
            }

            $this->info("Campaign #{$c->id}: emailed $delivered / " . count($recipients) . " (failed: $failed)");
        }
        return self::SUCCESS;
    }

    /**
     * Mirror of MarketingController::resolveAudience but joins users to pluck
     * actual email addresses for delivery.
     */
    private function resolveAudience(object $c): array
    {
        $centreScope = $c->centre_id ? [(int) $c->centre_id]
            : DB::table('centres')->where('agency_id', $c->agency_id)->whereNull('deleted_at')->pluck('id')->all();

        if ($c->audience === 'staff') {
            return DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', true)
                ->whereIn('ra.role', ['agency_admin', 'centre_director', 'educator'])
                ->where(function ($q) use ($c, $centreScope) {
                    $q->where('ra.agency_id', $c->agency_id)->orWhereIn('ra.centre_id', $centreScope);
                })
                ->whereNull('u.deleted_at')->whereNotNull('u.email')
                ->distinct()->select('u.id', 'u.email', 'u.first_name', 'u.last_name')->get()->all();
        }

        $base = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereIn('f.centre_id', $centreScope)
            ->whereNull('u.deleted_at')
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
