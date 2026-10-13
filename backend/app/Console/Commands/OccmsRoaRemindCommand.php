<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\AgencyTime;
use App\Support\ChatAudience;
use App\Support\Live;
use App\Support\Notify;
use App\Support\OccmsRoa;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * occms:roa-remind — the monthly Record of Attendance reminders (2026-10-13). Ontario agencies
 * only, on unless an admin switches it off (OCCMS reporting → Settings). At 8:00 agency time:
 *   · on the 1st: last month's ROA is ready to review, to each centre's directors and the
 *     agency admins — only centres that had a child with a fee subsidy that month;
 *   · two days before the due day (the 6th when it is due on the 8th): the centres whose ROA
 *     is still not signed off, "due in OCCMS by the 8th".
 * Bell + email, one per person listing their centres. Each (month, kind) goes out once per agency.
 */
final class OccmsRoaRemindCommand extends Command
{
    protected $signature = 'occms:roa-remind {--agency=} {--dry} {--force : ignore the 8:00 window, the day and the once-only guard} {--kind= : ready|due (with --force)}';
    protected $description = 'Monthly OCCMS Record of Attendance reminders: ready to review on the 1st, not signed off two days before the due day';

    public function handle(): int
    {
        $q = DB::table('agencies')->whereNull('deleted_at');
        if ($this->option('agency')) $q->where('id', (int) $this->option('agency'));
        $loose = (bool) $this->option('force');
        $dry = (bool) $this->option('dry');
        foreach ($q->get(['id', 'name', 'settings']) as $a) {
            $agencyId = (int) $a->id;
            if (! OccmsRoa::applies($agencyId)) continue;
            $set = OccmsRoa::settings($agencyId);
            if (! $set['reminders']) continue;
            $now = Carbon::now(AgencyTime::tz($agencyId));
            $dueDay = (int) $set['due_day'];
            $nudgeDay = $dueDay - 2;
            $kind = null;
            if ($loose && in_array($this->option('kind'), ['ready', 'due'], true)) $kind = $this->option('kind');
            elseif ($loose || $now->hour === 8) {
                if ($now->day === 1) $kind = 'ready';
                elseif ($nudgeDay > 1 && $now->day === $nudgeDay) $kind = 'due';
            }
            if (! $kind) continue;
            $month = $now->copy()->startOfMonth()->subMonthNoOverflow()->format('Y-m');
            $key = $month . ':' . $kind;
            $s = $a->settings ? (json_decode((string) $a->settings, true) ?: []) : [];
            if (! $loose && ! empty($s['occms_roa_sent'][$key])) continue;
            if (! $dry) {
                // Claim it first, so an overlapping run or a crash part-way never sends twice.
                $claimed = DB::transaction(function () use ($agencyId, $key, $loose) {
                    $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
                    $cur = $raw ? (json_decode((string) $raw, true) ?: []) : [];
                    if (! $loose && ! empty($cur['occms_roa_sent'][$key])) return false;
                    $sent = is_array($cur['occms_roa_sent'] ?? null) ? $cur['occms_roa_sent'] : [];
                    $sent[$key] = now()->toIso8601String();
                    $cur['occms_roa_sent'] = array_slice($sent, -12, null, true);   // the last few months is plenty
                    DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($cur)]);

                    return true;
                });
                if (! $claimed) continue;
            }
            $n = $this->runAgency($agencyId, (string) $a->name, $month, $kind, $set, $dry);
            $this->line("agency {$agencyId}: {$kind} {$month} — {$n} person(s)" . ($dry ? ' [dry]' : ''));
        }

        return self::SUCCESS;
    }

    /** @return int people told */
    public function runAgency(int $agencyId, string $agencyName, string $month, string $kind, array $set, bool $dry): int
    {
        $ms = $month . '-01';
        $me = Carbon::parse($ms)->endOfMonth()->toDateString();
        $open = Live::centreIds($agencyId);
        // Centres with a fee-subsidized child that month (the subsidy's own centre, else the family's).
        $centreIds = DB::table('subsidies as s')->join('children as ch', 'ch.id', '=', 's.child_id')
            ->join('families as f', 'f.id', '=', DB::raw('COALESCE(s.family_id, ch.family_id)'))
            ->whereIn(DB::raw('COALESCE(s.centre_id, f.centre_id)'), $open ?: [0])
            ->whereIn('s.type', OccmsRoa::SUBSIDY_TYPES)->where('s.active', 1)
            ->where('s.valid_from', '<=', $me)->where(fn ($w) => $w->whereNull('s.valid_to')->orWhere('s.valid_to', '>=', $ms))
            ->selectRaw('DISTINCT COALESCE(s.centre_id, f.centre_id) as cid')->pluck('cid')->map(fn ($v) => (int) $v)->all();
        if ($kind === 'due') {
            $done = DB::table('roa_months')->whereIn('centre_id', $centreIds ?: [0])->where('month', $month)
                ->whereIn('status', ['signed_off', 'submitted'])->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
            $centreIds = array_values(array_diff($centreIds, $done));
        }
        if (! $centreIds) return 0;
        $names = DB::table('centres')->whereIn('id', $centreIds)->orderBy('name')->pluck('name', 'id')->all();
        $status = DB::table('roa_months')->whereIn('centre_id', $centreIds)->where('month', $month)->pluck('status', 'centre_id')->all();

        $people = [];
        foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.role', 'centre_director')->where('ra.active', true)
            ->whereIn('ra.centre_id', $centreIds)->whereNull('u.deleted_at')->where('u.status', 'active')->get(['u.id', 'u.email', 'u.first_name', 'ra.centre_id']) as $d) {
            $people[(int) $d->id]['email'] = $d->email;
            $people[(int) $d->id]['first'] = $d->first_name;
            $people[(int) $d->id]['centres'][(int) $d->centre_id] = true;
        }
        foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.role', 'agency_admin')->where('ra.active', true)
            ->where('ra.agency_id', $agencyId)->whereNull('u.deleted_at')->where('u.status', 'active')->get(['u.id', 'u.email', 'u.first_name']) as $d) {
            $people[(int) $d->id] = ['email' => $d->email, 'first' => $d->first_name, 'centres' => array_fill_keys($centreIds, true)];
        }

        $label = Carbon::parse($ms)->format('F Y');
        $due = Carbon::parse(OccmsRoa::dueOn($month, (int) $set['due_day']))->format('M j');
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $stLabel = ['draft' => 'draft', 'reviewed' => 'reviewed, waiting for sign-off', 'signed_off' => 'signed off', 'submitted' => 'submitted'];
        $n = 0;
        foreach ($people as $uid => $p) {
            $mine = array_values(array_filter(array_keys($p['centres']), fn ($c) => isset($names[$c])));
            if (! $mine) continue;
            $n++;
            if ($dry) { $this->line("  would tell user {$uid}: " . implode(', ', array_map(fn ($c) => $names[$c], $mine))); continue; }
            $title = $kind === 'ready'
                ? 'Record of Attendance for ' . $label . ' is ready to review'
                : 'Record of Attendance for ' . $label . ' is not signed off — due in OCCMS by ' . $due;
            $list = implode(' · ', array_map(fn ($c) => $names[$c] . ($kind === 'due' ? ' (' . ($stLabel[$status[$c] ?? 'draft'] ?? 'draft') . ')' : ''), $mine));
            Notify::write(['user_id' => (int) $uid, 'type' => 'occms_roa_' . $kind, 'title' => mb_substr($title, 0, 200), 'body' => $list,
                'data' => json_encode(['link' => '#subsidy-roa?month=' . $month])]);
            if (! filter_var((string) $p['email'], FILTER_VALIDATE_EMAIL) || ChatAudience::isServiceEmail((string) $p['email'])) continue;
            $li = '';
            foreach ($mine as $c) $li .= '<li style="margin:4px 0">' . $e($names[$c]) . ($kind === 'due' ? ' <span style="color:#B45309">— ' . $e($stLabel[$status[$c] ?? 'draft'] ?? 'draft') . '</span>' : '') . '</li>';
            $body = '<p style="margin:0 0 10px;font-size:15px;color:#334155">Hi ' . $e($p['first'] ?: 'there') . ', '
                . ($kind === 'ready'
                    ? 'the fee subsidy Record of Attendance for <b>' . $e($label) . '</b> is ready to review for:'
                    : 'the fee subsidy Record of Attendance for <b>' . $e($label) . '</b> is <b>not signed off yet</b> for:')
                . '</p><ul style="margin:0;padding-left:18px;font-size:14.5px;color:#0F172A">' . $li . '</ul>'
                . '<p style="margin:14px 0 0;font-size:14px;color:#334155">It is due in OCCMS by <b>' . $e($due) . '</b>. Open KiddieTrac → <b>Compliance → OCCMS reporting</b>: check each child\'s days, '
                . 'mark it reviewed, have a second person sign it off, then enter it in OCCMS and record the date.</p>'
                . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">A monthly reminder from ' . $e($agencyName) . '. An agency admin can switch it off in OCCMS reporting → Settings.</p>';
            try {
                AgencyMailer::forAgency($agencyId)->html(EmailTemplate::wrap($agencyId, $body, ['eyebrow' => 'OCCMS', 'title' => $kind === 'ready' ? 'Record of Attendance ready' : 'Record of Attendance due', 'subtitle' => $agencyName]),
                    fn ($m) => $m->to((string) $p['email'])->subject($title));
            } catch (\Throwable $ex) {
                $this->error('mail failed: ' . $ex->getMessage());
            }
        }

        return $n;
    }
}
