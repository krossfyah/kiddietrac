<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Training emails (2026-10-02): "you've been assigned training", "due soon", "overdue".
 *
 * One email per person per send, listing every module it is about. Sent through the
 * agency's mailer, so the agency's own mail switches apply as they do to everything
 * else. The recipient is always the assignee's own address — never a default.
 */
final class TrainingMail
{
    public const LINK = 'https://app.kiddietrac.com/dashboard.html#training';

    /**
     * @param string $kind  assigned | due_soon | overdue
     * @param array  $items [{title, due_on}]
     */
    public static function send(int $agencyId, int $userId, string $kind, array $items, ?string $note = null, ?string $byName = null): bool
    {
        $u = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first(['first_name', 'last_name', 'email']);
        if (! $u || ! $u->email || ! $items) {
            return false;
        }
        $tz = AgencyTime::tz($agencyId);
        $n = count($items);
        $fmtDue = fn ($d) => $d ? Carbon::parse($d, $tz)->format('l, F j') : null;

        $rows = '';
        foreach ($items as $it) {
            $due = $fmtDue($it['due_on'] ?? null);
            $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:14.5px;color:#0F172A;font-weight:600;">🎓 ' . e($it['title']) . '</td>'
                . '<td style="padding:8px 0 8px 12px;border-bottom:1px solid #F1F5F9;font-size:13px;color:' . ($kind === 'overdue' ? '#B91C1C' : '#475569') . ';white-space:nowrap;text-align:right;">'
                . ($due ? ($kind === 'overdue' ? 'was due ' : 'due ') . e($due) : '') . '</td></tr>';
        }

        [$title, $lead, $subject] = match ($kind) {
            'due_soon' => ['Training due soon', ($n === 1 ? 'A training video assigned to you is' : "{$n} training videos assigned to you are") . ' due in the next couple of days.', 'Reminder: training due soon'],
            'overdue' => ['Training overdue', ($n === 1 ? 'A training video assigned to you is' : "{$n} training videos assigned to you are") . ' now past the due date.', 'Training overdue — please complete it'],
            default => ['Training assigned to you', ($byName ? e($byName) . ' has' : 'Your centre has') . ' assigned you ' . ($n === 1 ? 'a training video' : "{$n} training videos") . ' to watch in KiddieTrac.', $n === 1 ? 'Training assigned: ' . $items[0]['title'] : "Training assigned: {$n} videos"],
        };

        $html = EmailTemplate::wrap($agencyId,
            '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Hi ' . e(trim((string) $u->first_name) ?: 'there') . ', ' . $lead . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">' . $rows . '</table>'
            . ($note ? '<p style="margin:0 0 16px;padding:10px 14px;background:#F8FAFC;border-left:3px solid #0E7C90;font-size:14px;color:#334155;line-height:1.5;">' . nl2br(e($note)) . '</p>' : '')
            . '<p style="margin:0 0 18px;"><a href="' . self::LINK . '" style="display:inline-block;background:#0E7C90;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:12px 22px;border-radius:10px;">Start training</a></p>'
            . '<p style="margin:0;font-size:13px;color:#64748B;line-height:1.6;">Open KiddieTrac, go to <strong>Help &amp; guides → Training</strong>. Each video is a few minutes long; '
            . 'your progress is saved, and it counts as done once you have watched it through.</p>',
            ['eyebrow' => 'TRAINING', 'title' => $title, 'subtitle' => 'Help & guides', 'preheader' => strip_tags($lead)]);

        $to = (string) $u->email;
        $name = trim($u->first_name . ' ' . $u->last_name);
        try {
            dispatch(function () use ($agencyId, $to, $name, $html, $subject) {
                AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $name, $subject, $agencyId) {
                    $m->to($to, $name ?: null)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($subject);
                    $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                });
            })->onQueue('mail');

            return true;
        } catch (\Throwable $e) {
            Log::warning('Training email failed', ['user' => $userId, 'kind' => $kind, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
