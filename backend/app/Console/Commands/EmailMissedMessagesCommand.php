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
 * Runs every 5 minutes via routes/console.php. Picks up messages that
 * are:
 *   - older than the per-agency delay, agencies.settings.chat_email_delay_minutes,
 *     default 5 minutes (see delayForAgency)
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
    protected $signature = 'kiddietrac:chat-emails {--dry-run : Print to console, do not send} {--delay= : Override delay minutes for EVERY agency}';

    /** Used when an agency has not chosen its own. */
    public const DEFAULT_DELAY_MINUTES = 5;

    /** Sane bounds: under a minute is a stampede, over a day is not a notification. */
    private const MIN_DELAY = 1;
    private const MAX_DELAY = 1440;

    /**
     * How long a message may sit unread at this agency before it is emailed.
     *
     * Per agency, in `agencies.settings.chat_email_delay_minutes`, because the right
     * answer differs: a home-visiting service may want half an hour, a busy centre wants
     * five minutes. The docblock above this class claimed a per-agency delay for months
     * while the code used a flat 30 for everybody — this is that promise actually kept.
     * (Anthony, 2026-09-09)
     */
    public static function delayForAgency($agency): int
    {
        $raw = null;
        try {
            $s = json_decode((string) ($agency->settings ?? '{}'), true) ?: [];
            $raw = $s['chat_email_delay_minutes'] ?? null;
        } catch (\Throwable $e) {
        }
        if ($raw === null || $raw === '') return self::DEFAULT_DELAY_MINUTES;

        $n = (int) $raw;
        if ($n < self::MIN_DELAY) return self::MIN_DELAY;
        if ($n > self::MAX_DELAY) return self::MAX_DELAY;

        return $n;
    }
    protected $description = 'Email recipients about chat messages that have been unread for too long';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $override = $this->option('delay') !== null && $this->option('delay') !== ''
            ? (int) $this->option('delay') : null;
        $now = Carbon::now();

        /* Delays vary per agency now, and the SQL below can only take one cutoff — so it
           casts the widest net (the shortest delay in use) and each message is then held
           against its own agency's setting further down. Fetching with the longest delay
           instead would silently never email the agencies that chose a short one. */
        $delays = [];
        foreach (DB::table('agencies')->get(['id', 'settings']) as $ag) {
            $delays[(int) $ag->id] = $override ?? self::delayForAgency($ag);
        }
        $defaultDelay = $override ?? (empty($delays) ? self::DEFAULT_DELAY_MINUTES : min($delays));

        // Pick up candidates that are stale enough and not yet emailed.
        // We over-fetch a bit (200 max per run) so even busy installs don't
        // pile up; if more than 200 are queued, the next tick gets them.
        $candidates = DB::table('messages')
            ->whereNull('email_notified_at')
            ->whereNull('read_at')
            ->where('created_at', '<=', $now->copy()->subMinutes($defaultDelay))
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        if ($candidates->isEmpty()) {
            // Family threads are quiet — but team chat is a separate table and may not
            // be, so it still gets its sweep before we finish.
            $teamOnly = $this->sweepTeamChat($now, $defaultDelay, $dry);
            $this->info('No unread family messages awaiting email notification.'
                .($teamOnly ? '  Team chat: '.$teamOnly.' email(s).' : ''));

            return self::SUCCESS;
        }

        $this->info("Found {$candidates->count()} candidate message(s) " . ($dry ? '(dry-run, no send)' : 'to email'));

        // Group by conversation so we resolve recipients once per thread
        $byConv = $candidates->groupBy('conversation_id');

        /* ONE email per person per run — not one per conversation.
           Keyed by email address rather than user id: Safia has two active accounts on
           info@ilearnhcc.com, and a per-user key still delivered that inbox two of
           everything. */
        $byRecipient = []; // key: lower(email) => [recipient, conversation, messages[], senders[], convIds{}]

        foreach ($byConv as $convId => $msgs) {
            $conv = DB::table('conversations')->where('id', $convId)->first();
            if (!$conv) continue;

            /* Now hold each message against ITS OWN agency's delay. The query above used
               the shortest delay in use, so a thread at an agency that chose a longer one
               is still waiting and must not be emailed yet. */
            $convAgency = $this->agencyForConversation($conv);
            $convDelay = $override ?? ($convAgency ? ($delays[(int) $convAgency->id] ?? self::DEFAULT_DELAY_MINUTES) : self::DEFAULT_DELAY_MINUTES);
            $convCutoff = $now->copy()->subMinutes($convDelay);
            $msgs = $msgs->filter(fn ($m) => strtotime((string) $m->created_at) <= $convCutoff->getTimestamp());
            if ($msgs->isEmpty()) continue;

            $recipients = $this->resolveRecipients($conv);
            if (empty($recipients)) continue;

            $senderIds = $msgs->pluck('sender_id')->unique()->all();
            $senders = DB::table('users')->whereIn('id', $senderIds)->get()->keyBy('id');

            foreach ($recipients as $r) {
                // Skip senders so they don't email themselves about their own messages
                $unreadFromOthers = $msgs->reject(function ($m) use ($r) { return (int) $m->sender_id === (int) $r->id; });
                if ($unreadFromOthers->isEmpty()) continue;
                // An integration mailbox is not a person waiting to be told about a chat.
                if ($this->isServiceAccount($r)) continue;

                $key = mb_strtolower(trim((string) $r->email));
                if ($key === '') continue;
                if (! isset($byRecipient[$key])) {
                    $byRecipient[$key] = [
                        'recipient' => $r,
                        // Whichever thread came first — used only for the email's header
                        // and link. The message cards below carry the real content.
                        'conversation' => $conv,
                        'messages' => [],
                        'senders' => [],
                        'convIds' => [],
                    ];
                }
                // Keyed by message id: two accounts on one inbox merge into a single
                // digest, and without this their overlapping messages appear twice in it.
                foreach ($unreadFromOthers as $m) { $byRecipient[$key]['messages'][$m->id] = $m; }
                foreach ($senders as $sid => $su) { $byRecipient[$key]['senders'][$sid] = $su; }
                $byRecipient[$key]['convIds'][$convId] = true;
            }
        }

        $sent = 0; $failed = 0; $stampedIds = [];
        foreach ($byRecipient as $job) {
            $r = $job['recipient'];
            $conv = $job['conversation'];
            // Oldest first, so the digest reads in the order things were said.
            $msgs = array_values($job['messages']);
            usort($msgs, fn ($a, $b) => strcmp((string) $a->created_at, (string) $b->created_at));
            // subjectFor()/renderEmail() index this by sender id, so it stays a collection.
            $senders = collect($job['senders']);

            $agency = $this->agencyForConversation($conv);
            $subject = $this->subjectFor($msgs, $senders, $agency);
            $body    = $this->renderEmail($r, $conv, $msgs, $senders, $agency);

            if ($dry) {
                $this->line("--- to {$r->email} | " . count($job['convIds']) . " thread(s) | "
                    . count($msgs) . " unread msg(s) ---");
                $this->line('subject: ' . $subject);
                continue;
            }

            try {
                $mailer = AgencyMailer::forAgency($agency ? (int) $agency->id : null);
                $mailer->html($body, function ($m) use ($subject, $r) {
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
            // DELIBERATELY UNSTAMPED: one summary row for a sweep across all agencies'
            // conversations. Splitting it per agency would mean re-counting the batch by
            // owner, which is a different change; guessing an owner would misfile it.
            \App\Support\Audit::write([
                'user_id' => null,
                'agency_id' => null,
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

        $teamSent = $this->sweepTeamChat($now, $defaultDelay, $dry);

        $this->info("Done. family sent=$sent failed=$failed stamped=" . count($stampedIds)
            . "  team chat sent=$teamSent");
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

    /**
     * Integration and no-reply mailboxes are not people.
     *
     * integration+ilearn@kiddietrac.com is an agency_admin, so resolveRecipients()
     * attached it to every conversation and it collected 33 emails per run.
     */
    private function isServiceAccount(object $r): bool
    {
        $e = mb_strtolower(trim((string) ($r->email ?? '')));

        return $e === ''
            || str_contains($e, 'integration+')
            || str_starts_with($e, 'noreply@')
            || str_starts_with($e, 'no-reply@');
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

    /**
     * The same treatment for team chat.
     *
     * `messages` (family threads) has been covered since this command was written;
     * `staff_messages` never was, so a staff-to-staff message left unread produced an
     * in-app bell and nothing more. Same rules: older than the delay, nobody has read it,
     * not already emailed about.
     *
     * "Unread" is per participant here rather than per message — a team thread records
     * last_read_at on the participant, not read_at on the row — so a message counts as
     * missed for anyone whose last_read_at is older than it.
     *
     * @return int how many people were emailed
     */
    private function sweepTeamChat(\Illuminate\Support\Carbon $now, int $delayMinutes, bool $dry): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('staff_messages', 'email_notified_at')) {
            return 0;   // migration not applied yet — do nothing rather than fail
        }

        $cutoff = $now->copy()->subMinutes($delayMinutes);

        $msgs = DB::table('staff_messages')
            ->whereNull('email_notified_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit(200)
            ->get(['id', 'thread_id', 'sender_id', 'body', 'created_at']);

        if ($msgs->isEmpty()) {
            return 0;
        }

        $sent = 0;
        $stamped = [];
        // email address => everything that person missed, across every thread.
        $byPerson = [];

        foreach ($msgs->groupBy('thread_id') as $threadId => $threadMsgs) {
            $thread = DB::table('staff_threads')->where('id', $threadId)->first();
            if (! $thread) {
                // Nothing to send, but stamp them so they are not reconsidered forever.
                foreach ($threadMsgs as $m) { $stamped[] = $m->id; }
                continue;
            }

            $senderIds = $threadMsgs->pluck('sender_id')->unique()->all();

            foreach (DB::table('staff_thread_participants as p')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.thread_id', $threadId)
                ->whereNotIn('p.user_id', $senderIds)      // not back to whoever wrote it
                ->whereNull('u.deleted_at')
                ->whereNotNull('u.email')
                ->get(['p.user_id', 'p.last_read_at', 'u.email', 'u.first_name']) as $p) {

                // Only the messages this person has actually missed.
                $missed = $threadMsgs->filter(fn ($m) => ! $p->last_read_at
                    || \Illuminate\Support\Carbon::parse($m->created_at)->gt(\Illuminate\Support\Carbon::parse($p->last_read_at)));
                if ($missed->isEmpty()) {
                    continue;
                }
                if (\App\Support\Suppression::isUser((int) $p->user_id)) {
                    continue;
                }

                $pKey = mb_strtolower(trim((string) $p->email));
                if ($pKey === '') { continue; }
                // Integration mailboxes are not people — same rule as the family path.
                if (str_contains($pKey, 'integration+')
                    || str_starts_with($pKey, 'noreply@') || str_starts_with($pKey, 'no-reply@')) {
                    continue;
                }

                /* Collected, not sent. This loop runs per THREAD, so sending here gave
                   somebody in 39 threads 39 emails from one run. Everything a person
                   missed is gathered across every thread and sent once, below.
                   Keyed by message id so two threads cannot list the same message twice. */
                if (! isset($byPerson[$pKey])) {
                    $byPerson[$pKey] = ['p' => $p, 'agency_id' => (int) $thread->agency_id,
                                        'missed' => [], 'threads' => []];
                }
                foreach ($missed as $mm) { $byPerson[$pKey]['missed'][$mm->id] = $mm; }
                $byPerson[$pKey]['threads'][$threadId] = true;
                /* Collected only. The send happens once per PERSON after every thread
                   has contributed — see the loop below. Sending here is what gave
                   somebody in 39 threads 39 emails from a single run. */
                continue;
            }

            foreach ($threadMsgs as $m) { $stamped[] = $m->id; }
        }

        /* One email each, now that every thread has contributed. Stamping below is
           unchanged and still covers every message considered this run, which is only
           safe because nobody is skipped above any more. */
        foreach ($byPerson as $job) {
            $p = $job['p'];
            $missed = collect(array_values($job['missed']))
                ->sortBy(fn ($m) => (string) $m->created_at)->values();
            $count = $missed->count();
            $threadCount = count($job['threads']);
            if ($count === 0) { continue; }

            $names = DB::table('users')->whereIn('id', $missed->pluck('sender_id')->unique()->all())
                ->selectRaw("id, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")
                ->pluck('n', 'id')->all();
            $firstName = $names[$missed->first()->sender_id] ?? 'A colleague';
            $uniqueSenders = $missed->pluck('sender_id')->unique()->count();

            $subject = $count === 1
                ? 'New message from '.$firstName
                : $count.' new messages'.($uniqueSenders > 1
                    ? ' from '.$uniqueSenders.' colleagues'
                    : ' from '.$firstName);

            if ($dry) {
                $this->line('  [dry] team chat -> '.$p->email.'  ('.$count.' message(s) across '
                    .$threadCount.' thread(s))');
                $sent++;
                continue;
            }

            try {
                $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
                // Each line names its sender — a digest spanning threads is unreadable
                // without it, unlike the old one-thread-per-email version.
                $lines = $missed->take(8)->map(fn ($m) =>
                    '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:9px;'
                    .'padding:10px 12px;margin-bottom:8px;font-size:14px;line-height:1.55;'
                    .'color:#1E293B;white-space:pre-wrap;">'
                    .'<div style="font-size:12px;font-weight:700;color:#1F6080;margin-bottom:4px;">'
                    .$e($names[$m->sender_id] ?? 'A colleague').'</div>'
                    .$e($m->body).'</div>')->implode('');

                /* "Team chat" means nothing to a parent. Broadcast now delivers to
                   parents through the same private threads, so the label follows who is
                   being written to rather than which table the message sits in. */
                $isParent = DB::table('guardians')->where('user_id', $p->user_id)->exists();
                $where = $isParent ? 'Messages' : 'Team chat';

                $htmlBody = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
                    .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
                    .'You have '.$count.' unread message'.($count === 1 ? '' : 's').' in '.$e($where)
                    .($threadCount > 1 ? ' across '.$threadCount.' conversations' : '')
                    .($count > 8 ? ' — the first eight are below' : '').'.</td></tr>'
                    .'<tr><td>'.$lines.'</td></tr>'
                    .'<tr><td style="padding:14px 0 0;font-size:14px;color:#64748B;">'
                    .'Open <strong>'.$e($where).'</strong> in KiddieTrac to reply.</td></tr></table>';

                $html = \App\Services\EmailTemplate::wrap((int) $job['agency_id'], $htmlBody, [
                    'eyebrow' => $isParent ? 'MESSAGES' : 'TEAM CHAT',
                    'title' => $subject,
                    'preheader' => $count.' unread message'.($count === 1 ? '' : 's').' in Team chat.',
                ]);

                $digestAgencyId = (int) ($job['agency_id'] ?? 0);
                \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($p, $subject, $digestAgencyId) {
                    \App\Support\MailScope::agency($m, $digestAgencyId ?: null);
                    try { $m->getHeaders()->addTextHeader('X-KT-Engagement', '1'); } catch (\Throwable $e) {}
                    $m->to($p->email)->subject($subject);
                });
                $sent++;
            } catch (\Throwable $ex) {
                Log::warning('Team-chat digest email failed', [
                    'to' => $p->email, 'error' => $ex->getMessage(),
                ]);
            }
        }

        if (! $dry && $stamped) {
            DB::table('staff_messages')->whereIn('id', $stamped)
                ->update(['email_notified_at' => $now]);
        }

        return $sent;
    }

}
