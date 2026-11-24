<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\SupportTicketController;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SUPPORT TICKETS PAST THEIR SLA GET SOMEONE'S ATTENTION (2026-11-01, Anthony: "when SLA's are breached with
 * support tickets are emails not sent out to resolve?" - they were not: the targets only coloured the Tickets
 * dashboard).
 *
 * Every 15 minutes: an open ticket (not awaiting_user, which is waiting on the person who raised it) that has
 * missed its first-response target (no reply yet from anyone but the raiser) or its resolve target, using the
 * same SupportTicketController::SLA and the same first-reply rule as the dashboard. Each breach kind alerts once,
 * then once a day while it stays breached (support_ticket_sla_alerts).
 *
 * Who: KiddieTrac superadmins always; for an agency's ticket, that agency's admins too. Never the raiser.
 * How: one email per person per run listing their tickets, one push, and a bell row per ticket.
 * --dry-run lists what would go out and writes and sends nothing.
 */
class TicketSlaAlerts extends Command
{
    protected $signature = 'kt:ticket-sla {--dry-run : List the alerts without sending or recording anything}';

    protected $description = 'Alert superadmins (and the agency\'s admins) about support tickets past their SLA';

    private const REMIND_HOURS = 24;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $now = now();
        $tickets = DB::table('support_tickets')->where('status', 'open')
            ->get(['id', 'agency_id', 'raised_by_user_id', 'priority', 'subject', 'created_at']);
        if ($tickets->isEmpty()) {
            $this->info('No open tickets.');
            return self::SUCCESS;
        }

        $ids = $tickets->pluck('id')->all();
        $firstReply = [];
        foreach (DB::table('support_ticket_messages as m')->join('support_tickets as t', 't.id', '=', 'm.ticket_id')
            ->whereIn('m.ticket_id', $ids)->whereNotNull('m.user_id')
            ->whereRaw('(t.raised_by_user_id IS NULL OR m.user_id <> t.raised_by_user_id)')
            ->groupBy('m.ticket_id')->selectRaw('m.ticket_id, MIN(m.created_at) as first_at')->get() as $r) {
            $firstReply[(int) $r->ticket_id] = $r->first_at;
        }
        $sent = DB::table('support_ticket_sla_alerts')->whereIn('ticket_id', $ids)->get()
            ->keyBy(fn ($a) => $a->ticket_id . ':' . $a->kind);

        $due = [];   // [ticket, kind, overdue hours, due at]
        foreach ($tickets as $t) {
            $tgt = SupportTicketController::SLA[strtolower((string) $t->priority)] ?? SupportTicketController::SLA['normal'];
            $created = Carbon::parse((string) $t->created_at);
            $resDue = $created->copy()->addHours($tgt['resolve_h']);
            $respDue = $created->copy()->addHours($tgt['respond_h']);
            // The resolve breach is the bigger one and supersedes a missed first response.
            if ($now->gt($resDue)) {
                $kind = 'resolve'; $at = $resDue;
            } elseif (empty($firstReply[(int) $t->id]) && $now->gt($respDue)) {
                $kind = 'respond'; $at = $respDue;
            } else {
                continue;
            }
            $prev = $sent[$t->id . ':' . $kind] ?? null;
            if ($prev && $prev->last_sent_at && Carbon::parse((string) $prev->last_sent_at)->gt($now->copy()->subHours(self::REMIND_HOURS))) {
                continue;
            }
            $due[] = ['t' => $t, 'kind' => $kind, 'at' => $at, 'tgt' => $tgt, 'again' => (bool) $prev];
        }
        if (! $due) {
            $this->info('Nothing past its SLA needs an alert now.');
            return self::SUCCESS;
        }

        // Recipients: platform admins only (2026-11-24, TicketAlerts) - an agency's admins are no longer alerted.
        $supers = \App\Support\TicketAlerts::adminIds();
        $byUser = [];
        foreach ($due as $d) {
            $who = $supers;
            foreach (array_unique($who) as $uid) {
                if ($uid && $uid !== (int) $d['t']->raised_by_user_id) {
                    $byUser[$uid][] = $d;
                }
            }
        }
        $users = DB::table('users')->whereIn('id', array_keys($byUser))->whereNull('deleted_at')->get(['id', 'first_name', 'email'])->keyBy('id');
        $agencies = DB::table('agencies')->whereIn('id', collect($due)->pluck('t.agency_id')->filter()->unique()->all())->pluck('name', 'id');

        $label = function (array $d) use ($now, $agencies) {
            $late = max(1, (int) round($d['at']->diffInMinutes($now) / 60));
            $what = $d['kind'] === 'resolve' ? 'not resolved' : 'no reply yet';
            return [
                'line' => '#' . $d['t']->id . ' ' . $d['t']->subject,
                'meta' => ($d['t']->agency_id ? ($agencies[$d['t']->agency_id] ?? 'Agency') : 'KiddieTrac') . ' · '
                    . ucfirst(strtolower((string) ($d['t']->priority ?: 'normal'))) . ' · ' . $what . ', '
                    . $late . 'h past the ' . ($d['kind'] === 'resolve' ? $d['tgt']['resolve_h'] . 'h resolve' : $d['tgt']['respond_h'] . 'h first-response') . ' target'
                    . ($d['again'] ? ' (still open)' : ''),
            ];
        };

        foreach ($byUser as $uid => $list) {
            $u = $users[$uid] ?? null;
            if (! $u) {
                continue;
            }
            $n = count($list);
            $title = $n === 1 ? '⏰ Ticket #' . $list[0]['t']->id . ' is past its SLA' : "⏰ {$n} support tickets are past their SLA";
            $this->line(($dry ? '[dry-run] ' : '') . "user {$uid} <{$u->email}>: " . implode(', ', array_map(fn ($d) => '#' . $d['t']->id . ' ' . $d['kind'], $list)));
            if ($dry) {
                continue;
            }

            foreach ($list as $d) {
                $l = $label($d);
                \App\Support\Notify::write([
                    'user_id' => $uid, 'type' => 'support_ticket',
                    'title' => '⏰ Past SLA: ' . mb_substr($l['line'], 0, 120),
                    'body' => $l['meta'],
                    'data' => json_encode(['link' => '#tickets', 'ticket_id' => (int) $d['t']->id, 'severity' => 'warning']),
                ]);
            }
            try {
                app(\App\Services\FcmService::class)->sendToUser($uid, $title, $n === 1 ? $label($list[0])['meta'] : 'Open Support → Tickets to pick them up.', '#tickets');
            } catch (\Throwable $e) {
                Log::warning('ticket SLA push to ' . $uid . ' failed: ' . $e->getMessage());
            }
            if (! $u->email) {
                continue;
            }
            $rows = '';
            foreach ($list as $d) {
                $l = $label($d);
                $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #E2E8F0;"><div style="font-weight:700;font-size:14.5px;">' . e($l['line']) . '</div>'
                    . '<div style="font-size:13px;color:#B45309;margin-top:2px;">' . e($l['meta']) . '</div></td></tr>';
            }
            $inner = '<p style="margin:0 0 12px;font-size:14px;">Hi ' . e((string) ($u->first_name ?: 'there')) . ', '
                . ($n === 1 ? 'this support ticket has' : 'these support tickets have') . ' missed ' . ($n === 1 ? 'its' : 'their') . ' SLA target and still need someone.</p>'
                . '<table role="presentation" style="border-collapse:collapse;width:100%;margin:0 0 16px;">' . $rows . '</table>'
                . '<p style="margin:0 0 16px;"><a href="https://app.kiddietrac.com/dashboard.html#tickets" style="display:inline-block;background:#0D6E80;color:#ffffff;text-decoration:none;font-weight:700;padding:10px 18px;border-radius:9px;">Open Tickets</a></p>'
                . '<p class="kt-muted" style="color:#94a3b8;font-size:12px;margin:0;">Targets: urgent 1h reply / 8h resolve, high 4h / 24h, normal 8h / 72h, low 24h / 168h. '
                . 'You hear about each ticket once when it breaches, then once a day while it stays open.</p>';
            $html = \App\Services\EmailTemplate::wrap(null, $inner, [
                'force_brand' => 'kt', 'eyebrow' => 'SUPPORT SLA', 'title' => $n === 1 ? 'A ticket is past its SLA' : "{$n} tickets are past their SLA",
                'subtitle' => 'Support tickets', 'preheader' => $n === 1 ? $label($list[0])['line'] : "{$n} support tickets need attention",
            ]);
            $subject = '[Support SLA] ' . ($n === 1 ? '#' . $list[0]['t']->id . ' ' . mb_substr((string) $list[0]['t']->subject, 0, 90) : "{$n} tickets are past their SLA");
            try {
                Mail::html($html, function ($m) use ($u, $subject) {
                    \App\Support\MailScope::platform($m);
                    $m->to($u->email)->subject($subject);
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                });
            } catch (\Throwable $e) {
                Log::warning('ticket SLA mail to ' . $uid . ' failed: ' . $e->getMessage());
            }
        }

        if (! $dry) {
            foreach ($due as $d) {
                $key = ['ticket_id' => (int) $d['t']->id, 'kind' => $d['kind']];
                $row = DB::table('support_ticket_sla_alerts')->where($key)->first();
                if ($row) {
                    DB::table('support_ticket_sla_alerts')->where('id', $row->id)->update(['times' => $row->times + 1, 'last_sent_at' => $now, 'updated_at' => $now]);
                } else {
                    DB::table('support_ticket_sla_alerts')->insert($key + ['times' => 1, 'first_sent_at' => $now, 'last_sent_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        $this->info(($dry ? '[dry-run] ' : '') . count($due) . ' breach alert(s) for ' . count($byUser) . ' people.');

        return self::SUCCESS;
    }
}
