<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\Audience;
use App\Support\DrillCadence;
use App\Support\Live;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * drills:cadence-remind — ONTARIO (O. Reg. 137/15 s.68 / s.68.2, 2026-10-13). Daily.
 *
 * Tells a centre's directors when its missing-child drill is due within 14 days or
 * overdue, and when its monthly fire drill is overdue. Agency admins hear about anything
 * OVERDUE, and about everything at a centre with no director. One bell + one email per
 * person per run, listing every centre that changed — never one per centre.
 *
 * ONCE PER STATE CHANGE. drill_cadence_reminders keeps the state (and due date) each
 * centre was last told about; the same state on the same due date is not repeated. A
 * centre that gets back on track is recorded as 'ok' silently, so the next slip is news.
 *
 * On by default for CA-ON agencies; an admin can switch it off on the Drill log
 * (agencies.settings.drill_cadence_reminders.enabled = false). Other jurisdictions: nothing.
 */
final class DrillCadenceRemindCommand extends Command
{
    protected $signature = 'drills:cadence-remind {--agency=} {--dry : list what would be sent, change nothing} {--date= : pretend today is this agency date (testing)}';
    protected $description = 'Ontario: remind directors/admins when a missing-child drill is due or overdue, or a monthly fire drill is overdue (once per change)';

    public function handle(): int
    {
        $q = DB::table('agencies')->whereNull('deleted_at');
        if ($this->option('agency')) $q->where('id', (int) $this->option('agency'));
        $dry = (bool) $this->option('dry');

        foreach ($q->get(['id', 'name', 'settings']) as $a) {
            $agencyId = (int) $a->id;
            if (! DrillCadence::applies($agencyId)) continue;
            $s = $a->settings ? (json_decode((string) $a->settings, true) ?: []) : [];
            if (($s['drill_cadence_reminders']['enabled'] ?? true) === false) continue;
            $now = $this->option('date') ? Carbon::parse((string) $this->option('date') . ' 08:00', \App\Support\AgencyTime::tz($agencyId)) : null;

            $perUser = [];     // user id => [lines]
            $toRecord = [];    // rows to upsert after sending
            foreach (Live::centreIds($agencyId) as $cid) {
                $status = DrillCadence::status($agencyId, (int) $cid, $now);
                $centre = (string) DB::table('centres')->where('id', $cid)->value('name');
                $news = [];
                foreach ($status as $type => $st) {
                    $alert = $type === 'missing_child' ? in_array($st['state'], ['due_soon', 'overdue'], true) : $st['state'] === 'overdue';
                    $key = $alert ? $st['state'] : 'ok';
                    $prev = DB::table('drill_cadence_reminders')->where('centre_id', $cid)->where('type', $type)->first();
                    if ($prev && $prev->state === $key && ($key === 'ok' || (string) $prev->due_on === (string) $st['due_on'])) continue;
                    $toRecord[] = ['centre_id' => (int) $cid, 'type' => $type, 'state' => $key, 'due_on' => $st['due_on'], 'alert' => $alert];
                    if ($alert) $news[] = ['type' => $type, 'st' => $st];
                }
                if (! $news) continue;
                $overdue = (bool) array_filter($news, fn ($n) => $n['st']['state'] === 'overdue');
                $directors = $this->people($agencyId, ['centre_director'], (int) $cid);
                $admins = ($overdue || ! $directors) ? $this->people($agencyId, ['agency_admin'], null) : [];
                foreach (array_unique(array_merge($directors, $admins)) as $uid) {
                    foreach ($news as $n) $perUser[$uid][] = ['centre' => $centre, 'centre_id' => (int) $cid] + $n;
                }
            }

            foreach ($perUser as $uid => $lines) {
                $u = DB::table('users')->where('id', $uid)->first(['id', 'email', 'first_name']);
                if ($dry) { $this->line("agency {$agencyId} → user {$uid}: " . implode('; ', array_map(fn ($l) => $l['centre'] . ' ' . $this->text($l), $lines))); continue; }
                $first = $lines[0];
                Notify::write(['user_id' => (int) $uid, 'type' => 'drill_due',
                    'title' => count($lines) === 1 ? DrillCadence::label($first['type']) . ' drill ' . ($first['st']['state'] === 'overdue' ? 'overdue' : 'due soon') . ' — ' . $first['centre'] : count($lines) . ' drills due or overdue',
                    'body' => implode(' · ', array_map(fn ($l) => $l['centre'] . ': ' . $this->text($l), $lines)),
                    'data' => json_encode(['link' => '#drills'])]);
                if (! $u || ! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL) || \App\Support\ChatAudience::isServiceEmail((string) $u->email)) continue;
                $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
                $li = '';
                foreach ($lines as $l) {
                    $col = $l['st']['state'] === 'overdue' ? '#B91C1C' : '#B45309';
                    $li .= '<li style="margin:6px 0"><b>' . $e($l['centre']) . '</b> — <span style="color:' . $col . ';font-weight:700">' . $e($this->text($l)) . '</span></li>';
                }
                $html = EmailTemplate::wrap($agencyId, '<p style="margin:0 0 10px;font-size:15px;color:#334155">Drill schedule under the Ontario regulation (O. Reg. 137/15): missing-child drills at least every 3 months, fire drills every month.</p>'
                    . '<ul style="margin:0;padding-left:18px;font-size:14.5px;color:#0F172A">' . $li . '</ul>'
                    . '<p style="margin:14px 0 0;font-size:14px;color:#334155">Record each drill in KiddieTrac → Drill log as soon as it is done. You will hear again only if this changes.</p>'
                    . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">An agency admin can switch these reminders off on the Drill log.</p>',
                    ['eyebrow' => 'SAFETY', 'title' => 'Drills due', 'subtitle' => count($lines) === 1 ? $first['centre'] : count($lines) . ' items']);
                $subject = count($lines) === 1 ? DrillCadence::label($first['type']) . ' drill ' . ($first['st']['state'] === 'overdue' ? 'overdue' : 'due soon') . ' at ' . $first['centre'] : 'Drills due or overdue at your centres';
                try { AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to((string) $u->email)->subject($subject)); }
                catch (\Throwable $ex) { $this->error('mail failed: ' . $ex->getMessage()); }
            }

            if ($dry) { foreach ($toRecord as $r) $this->line("  record centre {$r['centre_id']} {$r['type']} → {$r['state']} ({$r['due_on']})"); continue; }
            foreach ($toRecord as $r) {
                DB::table('drill_cadence_reminders')->updateOrInsert(['centre_id' => $r['centre_id'], 'type' => $r['type']],
                    ['agency_id' => $agencyId, 'state' => $r['state'], 'due_on' => $r['due_on'], 'reminded_at' => $r['alert'] ? now() : null, 'updated_at' => now(), 'created_at' => now()]);
            }
        }

        return self::SUCCESS;
    }

    /** Active, reachable people with these roles — at this centre, or agency-wide when $centreId is null. */
    private function people(int $agencyId, array $roles, ?int $centreId): array
    {
        $q = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->whereIn('ra.role', $roles)->where('ra.active', true)->whereNull('u.deleted_at');
        $centreId ? $q->where('ra.centre_id', $centreId) : $q->where('ra.agency_id', $agencyId);
        Audience::excludeService(Audience::excludeOff($q));

        return $q->pluck('u.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function text(array $l): string
    {
        $st = $l['st'];
        $fmt = fn ($d) => $d ? Carbon::createFromFormat('Y-m-d', $d)->format('M j, Y') : null;
        $label = DrillCadence::label($l['type']) . ' drill';
        $last = $st['last_date'] ? 'last ' . $fmt($st['last_date']) : 'none on record';
        if ($st['state'] === 'overdue') {
            return $l['type'] === 'fire' && ! empty($st['missed_month'])
                ? $label . ' overdue — none in ' . $st['missed_month'] . ' (' . $last . ')'
                : $label . ' overdue — was due by ' . $fmt($st['due_on']) . ' (' . $last . ')';
        }

        return $label . ' due by ' . $fmt($st['due_on']) . ' (' . $last . ')';
    }
}
