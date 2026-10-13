<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\AgencyTime;
use App\Support\ChatAudience;
use App\Support\Live;
use App\Support\PolicyLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * policies:remind — the gentle weekly nudge (2026-10-13). ON unless an agency switches it off
 * (Policies screen). Mondays at 8:00 agency time, at most once per ISO week:
 *   · each staff member with policies still to sign gets ONE email listing them;
 *   · each centre's directors get ONE summary: who still has to sign, and policies whose
 *     review is due within 30 days or overdue (the program statement's annual review —
 *     O. Reg. 137/15 s.46 — is one of these); agency admins get the same for the agency.
 * Nothing outstanding → nothing sent. Only agencies that have published a policy.
 */
final class PolicyRemindCommand extends Command
{
    protected $signature = 'policies:remind {--agency=} {--dry} {--force : ignore the Monday 8:00 window and the once-a-week guard}';
    protected $description = 'Weekly reminders: staff policies still to sign, and director/admin summaries with reviews due';

    public function handle(): int
    {
        $q = DB::table('agencies')->whereNull('deleted_at')
            ->whereIn('id', DB::table('policies')->whereNotNull('current_version_id')->where('archived', false)->distinct()->pluck('agency_id'));
        if ($this->option('agency')) $q->where('id', (int) $this->option('agency'));
        $loose = $this->option('dry') || $this->option('force');
        foreach ($q->get(['id', 'name', 'settings']) as $a) {
            $agencyId = (int) $a->id;
            $s = $a->settings ? (json_decode((string) $a->settings, true) ?: []) : [];
            if (array_key_exists('enabled', (array) ($s['policy_reminders'] ?? [])) && ! $s['policy_reminders']['enabled']) continue;
            $now = Carbon::now(AgencyTime::tz($agencyId) ?: 'America/Toronto');
            $week = $now->format('o-\WW');
            if (! $loose && (! $now->isMonday() || $now->hour !== 8)) continue;
            if (! $loose && (($s['policy_reminders_week'] ?? null) === $week)) continue;
            if (! $this->option('dry')) {
                // Claim the week first, so an overlapping run or a crash part-way never sends twice.
                DB::transaction(function () use ($agencyId, $week) {
                    $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
                    $cur = $raw ? (json_decode((string) $raw, true) ?: []) : [];
                    $cur['policy_reminders_week'] = $week;
                    DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($cur)]);
                });
            }
            [$staffMails, $mgrMails] = $this->runAgency($agencyId, (string) $a->name, $now);
            $this->line("agency {$agencyId}: {$staffMails} staff reminder(s), {$mgrMails} summary(ies)" . ($this->option('dry') ? ' [dry]' : ''));
        }

        return self::SUCCESS;
    }

    /** @return array{0:int,1:int} */
    public function runAgency(int $agencyId, string $agencyName, Carbon $now): array
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $dry = (bool) $this->option('dry');
        $staff = PolicyLibrary::staff($agencyId);
        $outByUser = [];
        foreach ($staff as $uid => $st) {
            $o = PolicyLibrary::outstandingFor($agencyId, $uid);
            if ($o) $outByUser[$uid] = $o;
        }

        // 1. staff
        $nStaff = 0;
        foreach ($outByUser as $uid => $list) {
            $st = $staff[$uid];
            if (($st['status'] ?? '') !== 'active' || ! filter_var($st['email'], FILTER_VALIDATE_EMAIL)) continue;
            $nStaff++;
            if ($dry) continue;
            $li = '';
            foreach ($list as $r) $li .= '<li style="margin:4px 0">' . $e($r->title) . ((int) $r->version > 1 ? ' <span style="color:#64748B">(updated — version ' . (int) $r->version . ')</span>' : '') . '</li>';
            $html = EmailTemplate::wrap($agencyId,
                '<p style="margin:0 0 10px;font-size:15px;color:#334155">Hi ' . $e(explode(' ', $st['name'])[0] ?: 'there') . ', ' . (count($list) === 1 ? 'one policy is' : count($list) . ' policies are') . ' waiting for you to read and sign:</p>'
                . '<ul style="margin:0;padding-left:18px;font-size:14.5px;color:#0F172A">' . $li . '</ul>'
                . '<p style="margin:14px 0 0;font-size:14px;color:#334155">Open KiddieTrac → <b>Policies to review</b>. It takes a minute or two each.</p>'
                . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">A weekly reminder from ' . $e($agencyName) . '. It stops once everything is signed.</p>',
                ['eyebrow' => 'POLICIES', 'title' => 'Policies to review', 'subtitle' => $agencyName]);
            $this->send($agencyId, $st['email'], 'Policies to review (' . count($list) . ')', $html);
        }

        // 2. reviews due within 30 days (or overdue)
        $today = $now->toDateString();
        $soon = $now->copy()->addDays(30)->toDateString();
        $due = DB::table('policies')->where('agency_id', $agencyId)->where('archived', false)->whereNotNull('next_review_due')->where('next_review_due', '<=', $soon)
            ->orderBy('next_review_due')->get(['id', 'title', 'centre_id', 'next_review_due', 'key']);
        $missing = DB::table('policies')->where('agency_id', $agencyId)->where('archived', false)->where('required', true)->whereNull('current_version_id')->count();

        // 3. managers: each director for their centres; admins for the agency
        $mgrs = [];
        $dirs = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.role', 'centre_director')->where('ra.active', true)
            ->whereIn('ra.centre_id', Live::centreIds($agencyId) ?: [0])->whereNull('u.deleted_at')->where('u.status', 'active')->get(['u.id', 'u.email', 'u.first_name', 'ra.centre_id']);
        foreach ($dirs as $d) { $mgrs[(int) $d->id]['email'] = $d->email; $mgrs[(int) $d->id]['first'] = $d->first_name; $mgrs[(int) $d->id]['centres'][] = (int) $d->centre_id; }
        $admins = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.role', 'agency_admin')->where('ra.active', true)
            ->where('ra.agency_id', $agencyId)->whereNull('u.deleted_at')->where('u.status', 'active')->get(['u.id', 'u.email', 'u.first_name']);
        foreach ($admins as $d) { $mgrs[(int) $d->id] = ['email' => $d->email, 'first' => $d->first_name, 'centres' => null]; }

        $nMgr = 0;
        foreach ($mgrs as $m) {
            if (! filter_var($m['email'], FILTER_VALIDATE_EMAIL) || ChatAudience::isServiceEmail($m['email'])) continue;
            $mine = $m['centres'];
            $people = [];
            foreach ($outByUser as $uid => $list) {
                if ($mine !== null && ! array_intersect($staff[$uid]['centres'], $mine)) continue;
                $people[] = [$staff[$uid]['name'], count($list)];
            }
            $reviews = array_values(array_filter($due->all(), fn ($p) => $mine === null || $p->centre_id === null || in_array((int) $p->centre_id, $mine, true)));
            if (! $people && ! $reviews && ! ($mine === null && $missing)) continue;
            $nMgr++;
            if ($dry) continue;
            $body = '<p style="margin:0 0 10px;font-size:15px;color:#334155">Hi ' . $e($m['first'] ?: 'there') . ', here is this week\'s policy summary' . ($mine === null ? ' for ' . $e($agencyName) : ' for your centre') . '.</p>';
            if ($reviews) {
                $li = '';
                foreach ($reviews as $p) {
                    $over = $p->next_review_due < $today;
                    $li .= '<li style="margin:4px 0">' . $e($p->title) . ': ' . ($over ? '<b style="color:#B91C1C">review overdue since ' . $e(Carbon::parse($p->next_review_due)->format('M j, Y')) . '</b>'
                        : 'review due ' . $e(Carbon::parse($p->next_review_due)->format('M j, Y'))) . ($p->key === 'program_statement' ? ' <span style="color:#64748B">(annual review, s.46)</span>' : '') . '</li>';
                }
                $body .= '<p style="margin:12px 0 4px;font-size:14px;font-weight:700;color:#0F172A">Reviews due</p><ul style="margin:0;padding-left:18px;font-size:14px;color:#0F172A">' . $li . '</ul>';
            }
            if ($people) {
                usort($people, fn ($x, $y) => $y[1] <=> $x[1]);
                $li = '';
                foreach (array_slice($people, 0, 40) as $pp) $li .= '<li style="margin:3px 0">' . $e($pp[0]) . ' — ' . $pp[1] . ' to sign</li>';
                if (count($people) > 40) $li .= '<li style="margin:3px 0;color:#64748B">and ' . (count($people) - 40) . ' more</li>';
                $body .= '<p style="margin:12px 0 4px;font-size:14px;font-weight:700;color:#0F172A">Staff with policies still to sign</p><ul style="margin:0;padding-left:18px;font-size:14px;color:#0F172A">' . $li . '</ul>';
            }
            if ($mine === null && $missing) {
                $body .= '<p style="margin:12px 0 0;font-size:14px;color:#334155">' . $missing . ' required ' . ($missing === 1 ? 'policy has' : 'policies have') . ' no text yet. Upload or write them in Policies.</p>';
            }
            $body .= '<p style="margin:14px 0 0;font-size:14px;color:#334155">See the training matrix in KiddieTrac → <b>Policies</b>.</p>'
                . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">Weekly, on Mondays. An admin can switch these off on the Policies screen.</p>';
            $this->send($agencyId, $m['email'], 'Weekly policy summary', EmailTemplate::wrap($agencyId, $body, ['eyebrow' => 'POLICIES', 'title' => 'Weekly policy summary', 'subtitle' => $agencyName]));
        }

        return [$nStaff, $nMgr];
    }

    private function send(int $agencyId, string $to, string $subject, string $html): void
    {
        try {
            AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $ex) {
            $this->error('mail failed: ' . $ex->getMessage());
        }
    }
}
