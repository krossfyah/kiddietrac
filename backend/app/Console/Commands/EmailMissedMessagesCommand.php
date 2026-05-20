<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p40 — Email recipients about chat messages they haven't read.
 *
 * Runs every 15 minutes via routes/console.php. Picks up messages that
 * are:
 *   - older than the per-agency delay (default 30 minutes)
 *   - never email_notified_at-stamped
 *   - never read_at-stamped
 *
 * For each such message, resolves the recipient set (everyone with
 * access to the conversation except the sender), groups by
 * (recipient, conversation) so a single user gets ONE email summarising
 * all unread messages in a thread, and sends through AgencyMailer
 * (per-agency SMTP when configured).
 *
 * Email is HTML-branded via EmailTemplate so each agency's logo +
 * brand colour applies; white-label tenants get clean tenant-branded
 * mail with no 'Powered by Kiddietrac' line.
 *
 * Every included message is stamped email_notified_at on success so
 * a follow-up run doesn't re-email the same message even if it's
 * still unread.
 */
final class EmailMissedMessagesCommand extends Command
{
    protected $signature = 'kiddietrac:chat-emails {--dry-run : Print to console, do not send} {--delay= : Override delay minutes (default 30)}';
    protected $description = 'Email recipients about chat messages that have been unread for too long';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $defaultDelay = (int) ($this->option('delay') ?: 30);
        $now = Carbon::now();

        // Pick up candidates that are stale enough and not yet emailed.
        // We over-fetch a bit (200 max per run) so even busy installs don't
        // pile up; if more than 200 are queued, the next 15-min tick gets them.
        $candidates = DB::table('messages')
            ->whereNull('email_notified_at')
            ->whereNull('read_at')
            ->where('created_at', '<=', $now->copy()->subMinutes($defaultDelay))
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No unread messages awaiting email notification.');
            return self::SUCCESS;
        }

        $this->info("Found {$candidates->count()} candidate message(s) " . ($dry ? '(dry-run, no send)' : 'to email'));

        // Group by conversation so we resolve recipients once per thread
        $byConv = $candidates->groupBy('conversation_id');

        // Per (recipient, conversation) we'll send ONE summary email
        $emailQueue = []; // key: $recipientId.':'.$convId  => [recipient, conv, messages[], senders[]]

        foreach ($byConv as $convId => $msgs) {
            $conv = DB::table('conversations')->where('id', $convId)->first();
            if (!$conv) continue;
            $recipients = $this->resolveRecipients($conv);
            if (empty($recipients)) continue;

            $senderIds = $msgs->pluck('sender_id')->unique()->all();
            $senders = DB::table('users')->whereIn('id', $senderIds)->get()->keyBy('id');

            foreach ($recipients as $r) {
                // Skip senders so they don't email themselves about their own messages
                $unreadFromOthers = $msgs->reject(function ($m) use ($r) { return (int) $m->sender_id === (int) $r->id; });
                if ($unreadFromOthers->isEmpty()) continue;

                $key = $r->id . ':' . $convId;
                $emailQueue[$key] = [
                    'recipient' => $r,
                    'conversation' => $conv,
                    'messages' => $unreadFromOthers->all(),
                    'senders' => $senders,
                ];
            }
        }

        $sent = 0; $failed = 0; $stampedIds = [];
        foreach ($emailQueue as $job) {
            $r = $job['recipient'];
            $conv = $job['conversation'];
            $msgs = $job['messages'];
            $senders = $job['senders'];

            $agency = $this->agencyForConversation($conv);
            $subject = $this->subjectFor($msgs, $senders, $agency);
            $body    = $this->renderEmail($r, $conv, $msgs, $senders, $agency);

            if ($dry) {
                $this->line("--- to {$r->email} | conv #{$conv->id} | " . count($msgs) . " unread msg(s) ---");
                $this->line('subject: ' . $subject);
                continue;
            }

            try {
                $mailer = AgencyMailer::forAgency($agency ? (int) $agency->id : null);
                $mailer->mailer()->html($body, function ($m) use ($subject, $r) {
                    $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                    $m->to($r->email, $name ?: $r->email)->subject($subject);
                });
                $sent++;
                foreach ($msgs as $msg) $stampedIds[] = $msg->id;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn('  send failed for ' . $r->email . ': ' . $e->getMessage());
            }
        }

        if (!$dry && !empty($stampedIds)) {
            $stampedIds = array_values(array_unique($stampedIds));
            DB::table('messages')->whereIn('id', $stampedIds)->update(['email_notified_at' => $now]);
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'chat.email_notified',
                'entity_type' => 'message',
                'entity_id' => null,
                'payload' => json_encode([
                    'sent' => $sent,
                    'failed' => $failed,
                    'message_ids' => count($stampedIds),
                ]),
                'created_at' => $now,
            ]);
        }

        $this->info("Done. sent=$sent failed=$failed stamped=" . count($stampedIds));
        return self::SUCCESS;
    }

    /**
     * Resolve every user who can see this conversation, with their email.
     * Mirrors ChatController::insertMessage but joins users + filters out
     * recipients without an email address.
     */
    private function resolveRecipients(object $conv): array
    {
        $centreId = (int) $conv->centre_id;
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');

        $guardianIds = DB::table('guardians')->where('family_id', $conv->family_id)->pluck('user_id')->all();
        $staffIds = DB::table('role_assignments')
            ->where('active', true)
            ->where(function ($q) use ($centreId, $agencyId) {
                $q->where('centre_id', $centreId)
                  ->orWhere(function ($w) use ($agencyId) {
                      $w->where('role', 'agency_admin')->where('agency_id', $agencyId);
                  });
            })
            ->pluck('user_id')->all();

        $userIds = array_values(array_unique(array_merge($guardianIds, $staffIds)));
        if (empty($userIds)) return [];

        return DB::table('users')
            ->whereIn('id', $userIds)
            ->whereNull('deleted_at')
            ->whereNotNull('email')
            ->get(['id', 'email', 'first_name', 'last_name'])
            ->all();
    }

    private function agencyForConversation(object $conv): ?object
    {
        $agencyId = (int) DB::table('centres')->where('id', $conv->centre_id)->value('agency_id');
        return $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
    }

    private function subjectFor(array $msgs, $senders, ?object $agency): string
    {
        $prefix = $agency ? '[' . $agency->name . '] ' : '';
        $count = count($msgs);
        if ($count === 1) {
            $sender = $senders[$msgs[0]->sender_id] ?? null;
            $name = $sender ? trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? '')) : 'Someone';
            return $prefix . 'New message from ' . $name;
        }
        $uniqSenders = array_unique(array_map(fn ($m) => (int) $m->sender_id, $msgs));
        return $prefix . $count . ' new messages' . (count($uniqSenders) > 1 ? ' from ' . count($uniqSenders) . ' people' : '');
    }

    private function renderEmail(object $recipient, object $conv, array $msgs, $senders, ?object $agency): string
    {
        $rcptFirst = $recipient->first_name ?: 'there';
        $items = '';
        foreach ($msgs as $m) {
            $sender = $senders[$m->sender_id] ?? null;
            $name = $sender ? trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? '')) : 'Someone';
            $when = Carbon::parse($m->created_at)->diffForHumans();
            $body = trim((string) $m->body);
            $attachmentsNote = '';
            if (!empty($m->attachments)) {
                $att = json_decode((string) $m->attachments, true);
                if (is_array($att) && !empty($att)) {
                    $attachmentsNote = '<div style="font-size:12px;color:#6B7280;margin-top:6px;">📎 ' . count($att) . ' attachment' . (count($att) === 1 ? '' : 's') . '</div>';
                }
            }
            $preview = $body !== '' ? htmlspecialchars(mb_strimwidth($body, 0, 320, '…'))
                                    : ($attachmentsNote ? '<em style="color:#6B7280;">(image)</em>' : '<em style="color:#6B7280;">(no text)</em>');
            $items .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="background:#F8FAFC;border-radius:12px;border-left:4px solid #1F6080;margin-bottom:10px;">'
                . '<tr><td style="padding:14px 16px;">'
                . '<div style="font-size:12px;font-weight:700;color:#1F6080;">' . htmlspecialchars($name)
                . ' <span style="color:#9CA3AF;font-weight:400;font-size:11px;margin-left:8px;">· ' . htmlspecialchars($when) . '</span></div>'
                . '<div style="font-size:14px;color:#111827;margin-top:6px;line-height:1.5;">' . $preview . '</div>'
                . $attachmentsNote
                . '</td></tr></table>';
        }

        $body  = '<p style="margin:0 0 14px;font-size:14px;color:#0F172A;">Hi ' . htmlspecialchars($rcptFirst) . ', you have ';
        $body .= count($msgs) === 1 ? '<strong>a new message</strong>' : '<strong>' . count($msgs) . ' new messages</strong>';
        $body .= ' in ' . htmlspecialchars($agency ? $agency->name : 'Kiddietrac') . '.</p>';
        $body .= $items;
        $body .= EmailTemplate::button('Open conversation', 'https://app.kiddietrac.com/dashboard.html#chat', $agency && $agency->brand_primary_color ? $agency->brand_primary_color : '#1F6080');

        return EmailTemplate::wrap($agency ? (int) $agency->id : null, $body, [
            'eyebrow'   => 'CHAT · UNREAD',
            'title'     => $agency ? $agency->name : 'Kiddietrac',
            'subtitle'  => count($msgs) === 1 ? '1 message waiting' : count($msgs) . ' messages waiting',
            'preheader' => 'You have ' . count($msgs) . ' unread message' . (count($msgs) === 1 ? '' : 's') . ' in Kiddietrac chat — tap to open.',
            'footer_note' => 'We waited about 30 minutes before sending this nudge. You will not get another email about these same messages — opening the chat or replying clears them.',
        ]);
    }
}
