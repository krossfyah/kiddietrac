<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Email the intended recipients about chat messages that have gone unread for
 * N minutes (default 15). A parent's message escalates to the centre's team; a
 * staff message escalates to the family's guardians. Each message is emailed at
 * most once (tracked via messages.email_notified_at).
 */
final class MissedMessagesCommand extends Command
{
    protected $signature = 'kiddietrac:missed-messages {--minutes=15} {--dry : Print instead of sending}';
    protected $description = 'Email recipients about chat messages left unread for N minutes.';

    public function handle(): int
    {
        $mins = (int) $this->option('minutes');
        if ($mins < 1) $mins = 15;
        $cutoff = now()->subMinutes($mins);

        $msgs = DB::table('messages')
            ->whereNull('read_at')
            ->whereNull('email_notified_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit(300)
            ->get();

        if ($msgs->isEmpty()) {
            $this->info('No missed messages to escalate.');
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($msgs as $m) {
            $convo = DB::table('conversations')->where('id', $m->conversation_id)->first();
            if (!$convo) {
                DB::table('messages')->where('id', $m->id)->update(['email_notified_at' => now()]);
                continue;
            }

            $sender = DB::table('users')->where('id', $m->sender_id)->first();
            $senderName = $sender ? trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? '')) : 'Someone';
            if ($senderName === '') $senderName = 'Someone';

            // A guardian's message → notify the centre team; otherwise (staff) → notify the family.
            $senderIsGuardian = DB::table('guardians')->where('user_id', $m->sender_id)->where('family_id', $convo->family_id)->exists();
            if ($senderIsGuardian) {
                $recips = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                    ->where('ra.centre_id', $convo->centre_id)->where('ra.active', true)
                    ->whereIn('ra.role', ['educator', 'centre_director', 'agency_admin'])
                    ->where('u.id', '!=', $m->sender_id)->whereNotNull('u.email')
                    ->distinct()->get(['u.id', 'u.first_name', 'u.last_name', 'u.email']);
            } else {
                $recips = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                    ->where('g.family_id', $convo->family_id)->where('u.id', '!=', $m->sender_id)
                    ->whereNotNull('u.email')->distinct()->get(['u.id', 'u.first_name', 'u.last_name', 'u.email']);
            }

            $agencyId = DB::table('centres')->where('id', $convo->centre_id)->value('agency_id');
            $preview = $m->body ? e(mb_substr($m->body, 0, 400)) : '📎 An attachment';
            $subject = 'New KiddieTrac message from ' . $senderName;
            $html = $this->body($senderName, $preview);

            if ($this->option('dry')) {
                $this->line("msg #{$m->id} (convo {$convo->id}) → " . count($recips) . ' recipient(s): ' . $recips->pluck('email')->implode(', '));
            } else {
                $mailer = AgencyMailer::forAgency($agencyId ? (int) $agencyId : null);
                foreach ($recips as $r) {
                    try {
                        $mailer->mailer()->html($html, function ($mm) use ($subject, $r) {
                            $mm->to($r->email, trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')))->subject($subject);
                        });
                        $sent++;
                    } catch (\Throwable $e) {
                        $this->warn('send failed for ' . $r->email . ': ' . $e->getMessage());
                    }
                }
            }

            DB::table('messages')->where('id', $m->id)->update(['email_notified_at' => now()]);
        }

        $this->info('Escalated ' . count($msgs) . ' message(s); sent ' . $sent . ' email(s).');
        return self::SUCCESS;
    }

    private function body(string $senderName, string $preview): string
    {
        $portal = 'https://app.kiddietrac.com/dashboard.html#messages';
        return '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;color:#0D1B2A;">'
            . '<div style="background:#081C41;color:#fff;padding:20px 24px;border-radius:14px 14px 0 0;font-weight:800;font-size:18px;">KiddieTrac · New message</div>'
            . '<div style="border:1px solid #E5E7EB;border-top:none;border-radius:0 0 14px 14px;padding:22px 24px;">'
            . '<p style="margin:0 0 12px;font-size:15px;">You have an unread message from <b>' . e($senderName) . '</b>:</p>'
            . '<blockquote style="margin:0 0 18px;padding:12px 16px;background:#F1F5F9;border-left:4px solid #0E7C90;border-radius:8px;font-size:15px;line-height:1.5;">' . $preview . '</blockquote>'
            . '<a href="' . $portal . '" style="display:inline-block;background:#0E7C90;color:#fff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:700;font-size:15px;">Open KiddieTrac</a>'
            . '<p style="margin:18px 0 0;font-size:12px;color:#94A3B8;">You’re receiving this because a message went unread. Open the app to reply.</p>'
            . '</div></div>';
    }
}
