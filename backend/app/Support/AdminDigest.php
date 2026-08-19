<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * What a director or admin needs to act on, gathered in one pass.
 *
 * Every section is a THING SOMEBODY HAS TO DO, not a statistic. A digest that reports
 * "42 check-ins today" is read once and skipped forever; one that says "3 late pick-ups
 * are waiting on your decision" gets opened. Sections with nothing outstanding are
 * omitted entirely rather than printed as a row of zeroes — an empty section trains
 * people to skim past the full ones.
 *
 * Each section is wrapped: a digest is a scheduled email nobody is watching, so one
 * missing column must cost that section and not the whole message. Failures are logged
 * so a silently absent section can still be traced.
 */
final class AdminDigest
{
    /** @return array<string,mixed> */
    public static function gather(int $agencyId, string $from, string $to): array
    {
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        if (! $centreIds) {
            return [];
        }
        $ctx = ['agency' => $agencyId, 'centres' => $centreIds, 'from' => $from, 'to' => $to];

        $out = [];
        foreach ([
            'late' => 'latePickups', 'timeoff' => 'timeOff', 'tasks' => 'tasks',
            'tours' => 'tours', 'incidents' => 'incidents', 'immunisations' => 'immunisations',
            'tickets' => 'tickets', 'invoicing' => 'invoicing', 'welcome' => 'welcome',
            'week' => 'weekAhead', 'reportCards' => 'reportCards', 'forms' => 'forms',
            'openDays' => 'openDays', 'scorecard' => 'scorecard',
            'educators' => 'educators',
        ] as $key => $method) {
            try {
                $v = self::$method($ctx);
                if ($v) { $out[$key] = $v; }
            } catch (\Throwable $e) {
                Log::warning('AdminDigest section failed', ['section' => $key, 'error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    // ── Sections ────────────────────────────────────────────────────────────

    private static function latePickups(array $c): ?array
    {
        if (! Schema::hasTable('late_events')) return null;
        $pending = DB::table('late_events as l')
            ->join('children as ch', 'ch.id', '=', 'l.child_id')
            ->whereIn('l.centre_id', $c['centres'])->where('l.status', 'pending')
            ->orderByDesc('l.occurred_on')->limit(8)
            ->get(['l.kind', 'l.minutes', 'l.occurred_on', 'ch.first_name', 'ch.last_name']);
        if ($pending->isEmpty()) return null;

        return [
            'count' => DB::table('late_events')->whereIn('centre_id', $c['centres'])->where('status', 'pending')->count(),
            'rows' => $pending->map(fn ($r) => [
                'who' => trim($r->first_name . ' ' . $r->last_name),
                'what' => $r->kind === 'departure' ? 'Late pick-up' : 'Late arrival',
                'detail' => $r->minutes . ' min · ' . substr((string) $r->occurred_on, 0, 10),
            ])->all(),
        ];
    }

    private static function timeOff(array $c): ?array
    {
        if (! Schema::hasTable('time_off_requests')) return null;
        $rows = DB::table('time_off_requests as t')->join('users as u', 'u.id', '=', 't.user_id')
            ->where(fn ($q) => $q->where('t.agency_id', $c['agency'])->orWhereIn('t.centre_id', $c['centres']))
            ->where('t.status', 'pending')->orderBy('t.start_at')->limit(8)
            ->get(['t.request_type', 't.start_at', 't.end_at', 'u.first_name', 'u.last_name']);
        if ($rows->isEmpty()) return null;

        return ['count' => $rows->count(), 'rows' => $rows->map(fn ($r) => [
            'who' => trim($r->first_name . ' ' . $r->last_name),
            'what' => ucfirst(str_replace('_', ' ', (string) $r->request_type)),
            'detail' => substr((string) $r->start_at, 0, 10) . ' → ' . substr((string) $r->end_at, 0, 10),
        ])->all()];
    }

    private static function tasks(array $c): ?array
    {
        if (! Schema::hasTable('tasks')) return null;
        $q = DB::table('tasks as t')->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->where(fn ($x) => $x->where('t.agency_id', $c['agency'])->orWhereIn('t.centre_id', $c['centres']));
        if (Schema::hasColumn('tasks', 'status')) { $q->where('t.status', '!=', 'done'); }

        $rows = $q->orderBy('t.due_date')->limit(8)
            ->get(['t.title', 't.due_date', 'u.first_name', 'u.last_name']);
        if ($rows->isEmpty()) return null;

        $today = Carbon::parse($c['to'])->toDateString();
        return ['count' => $rows->count(), 'rows' => $rows->map(fn ($r) => [
            'who' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Unassigned',
            'what' => (string) $r->title,
            // An overdue task is the one that needs saying out loud.
            'detail' => $r->due_date
                ? (substr((string) $r->due_date, 0, 10) < $today ? '⚠ overdue — due ' : 'due ') . substr((string) $r->due_date, 0, 10)
                : 'no due date',
        ])->all()];
    }

    private static function tours(array $c): ?array
    {
        if (! Schema::hasTable('tour_bookings')) return null;
        $cols = Schema::getColumnListing('tour_bookings');
        $dateCol = in_array('scheduled_at', $cols) ? 'scheduled_at' : (in_array('tour_at', $cols) ? 'tour_at' : 'created_at');
        $nameCol = in_array('parent_name', $cols) ? 'parent_name' : (in_array('name', $cols) ? 'name' : null);

        $q = DB::table('tour_bookings');
        if (in_array('centre_id', $cols)) { $q->whereIn('centre_id', $c['centres']); }
        elseif (in_array('agency_id', $cols)) { $q->where('agency_id', $c['agency']); }
        $rows = $q->whereDate($dateCol, '>=', $c['from'])->orderBy($dateCol)->limit(6)->get();
        if ($rows->isEmpty()) return null;

        return ['count' => $rows->count(), 'rows' => $rows->map(fn ($r) => [
            'who' => $nameCol ? (string) ($r->{$nameCol} ?: 'A family') : 'A family',
            'what' => 'Tour booked',
            'detail' => substr((string) $r->{$dateCol}, 0, 16),
        ])->all()];
    }

    private static function incidents(array $c): ?array
    {
        if (! Schema::hasTable('incidents')) return null;
        $rows = DB::table('incidents as i')->join('children as ch', 'ch.id', '=', 'i.child_id')
            ->join('families as f', 'f.id', '=', 'ch.family_id')->whereIn('f.centre_id', $c['centres'])
            ->whereDate('i.occurred_at', '>=', $c['from'])->whereDate('i.occurred_at', '<=', $c['to'])
            ->orderByDesc('i.occurred_at')->limit(6)
            ->get(['i.incident_type', 'i.severity', 'i.occurred_at', 'ch.first_name', 'ch.last_name']);
        if ($rows->isEmpty()) return null;

        return ['count' => $rows->count(), 'rows' => $rows->map(fn ($r) => [
            'who' => trim($r->first_name . ' ' . $r->last_name),
            'what' => ucfirst((string) ($r->incident_type ?: 'Incident')),
            'detail' => ucfirst((string) ($r->severity ?: '')) . ' · ' . substr((string) $r->occurred_at, 0, 10),
        ])->all()];
    }

    /** Immunisations are reported by ABSENCE — the children with no record are the risk. */
    private static function immunisations(array $c): ?array
    {
        if (! Schema::hasTable('immunizations')) return null;
        $have = DB::table('immunizations')->distinct()->pluck('child_id')->all();
        $missing = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', $c['centres'])->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->when($have, fn ($q) => $q->whereNotIn('ch.id', $have))
            ->orderBy('ch.first_name')->limit(8)
            ->get(['ch.first_name', 'ch.last_name']);
        if ($missing->isEmpty()) return null;

        return [
            'count' => DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
                ->whereIn('f.centre_id', $c['centres'])->where('ch.enrollment_status', 'enrolled')
                ->whereNull('ch.deleted_at')->when($have, fn ($q) => $q->whereNotIn('ch.id', $have))->count(),
            'rows' => $missing->map(fn ($r) => [
                'who' => trim($r->first_name . ' ' . $r->last_name),
                'what' => 'No immunisation record',
                'detail' => 'nothing on file',
            ])->all(),
        ];
    }

    private static function tickets(array $c): ?array
    {
        if (! Schema::hasTable('support_tickets')) return null;
        $rows = DB::table('support_tickets')->where('agency_id', $c['agency'])
            ->whereNull('resolved_at')->orderByDesc('created_at')->limit(6)
            ->get(['subject', 'category', 'priority', 'created_at']);
        if ($rows->isEmpty()) return null;

        return ['count' => $rows->count(), 'rows' => $rows->map(fn ($r) => [
            'who' => ucfirst((string) ($r->category ?: 'Support')),
            'what' => (string) $r->subject,
            'detail' => ucfirst((string) ($r->priority ?: 'normal')) . ' · raised ' . substr((string) $r->created_at, 0, 10),
        ])->all()];
    }

    /** Who is due to be billed, grouped by how often their plan bills. */
    private static function invoicing(array $c): ?array
    {
        if (! Schema::hasTable('fee_plans')) return null;
        $plans = DB::table('fee_plans')->where('agency_id', $c['agency'])
            ->when(Schema::hasColumn('fee_plans', 'active'), fn ($q) => $q->where('active', 1))
            ->get(['name', 'amount', 'frequency']);
        if ($plans->isEmpty()) return null;

        $byFreq = [];
        foreach ($plans as $p) {
            $f = strtolower((string) ($p->frequency ?: 'monthly'));
            $byFreq[$f] = ($byFreq[$f] ?? 0) + 1;
        }
        // Families with no invoice raised in the window are the actionable half.
        $unbilled = 0;
        if (Schema::hasTable('invoices')) {
            $billed = DB::table('invoices')->whereIn('centre_id', $c['centres'])
                ->whereDate('created_at', '>=', $c['from'])->distinct()->pluck('family_id')->all();
            $unbilled = DB::table('families')->whereIn('centre_id', $c['centres'])
                ->whereNull('deleted_at')->whereNull('suspended_at')
                ->when($billed, fn ($q) => $q->whereNotIn('id', $billed))->count();
        }
        return ['plans' => $byFreq, 'unbilled_families' => $unbilled];
    }

    /** Families on file that nobody has invited yet — the welcome-package reminder. */
    private static function welcome(array $c): ?array
    {
        $rows = DB::table('families as f')
            ->leftJoin('guardians as g', 'g.family_id', '=', 'f.id')
            ->whereIn('f.centre_id', $c['centres'])->whereNull('f.deleted_at')->whereNull('f.suspended_at')
            ->whereNull('g.id')
            ->distinct()->orderBy('f.family_name')->limit(8)
            ->get(['f.family_name', 'f.primary_email']);
        if ($rows->isEmpty()) return null;

        return ['count' => $rows->count(), 'rows' => $rows->map(fn ($r) => [
            'who' => (string) ($r->family_name ?: 'Family'),
            'what' => 'No guardian account yet',
            'detail' => $r->primary_email ? 'invite ' . $r->primary_email : 'no email on file',
        ])->all()];
    }

    /** Closures, approved leave and birthdays landing in the next seven days. */
    private static function weekAhead(array $c): ?array
    {
        $start = Carbon::parse($c['to']);
        $end = $start->copy()->addDays(7);
        $rows = [];

        if (Schema::hasTable('centre_closures')) {
            foreach (DB::table('centre_closures')->whereIn('centre_id', $c['centres'])
                ->whereDate('closure_date', '>=', $start->toDateString())
                ->whereDate('closure_date', '<=', $end->toDateString())
                ->orderBy('closure_date')->limit(4)->get(['closure_date', 'reason']) as $r) {
                $rows[] = ['who' => 'Closed', 'what' => (string) ($r->reason ?: 'Closure'), 'detail' => substr((string) $r->closure_date, 0, 10)];
            }
        }
        if (Schema::hasTable('time_off_requests')) {
            foreach (DB::table('time_off_requests as t')->join('users as u', 'u.id', '=', 't.user_id')
                ->where(fn ($q) => $q->where('t.agency_id', $c['agency'])->orWhereIn('t.centre_id', $c['centres']))
                ->where('t.status', 'approved')
                ->whereDate('t.start_at', '<=', $end->toDateString())
                ->whereDate('t.end_at', '>=', $start->toDateString())
                ->limit(4)->get(['u.first_name', 'u.last_name', 't.start_at', 't.end_at']) as $r) {
                $rows[] = ['who' => trim($r->first_name . ' ' . $r->last_name), 'what' => 'Away',
                    'detail' => substr((string) $r->start_at, 0, 10) . ' → ' . substr((string) $r->end_at, 0, 10)];
            }
        }
        $mmdd = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) { $mmdd[] = $d->format('m-d'); }
        foreach (DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', $c['centres'])->whereNull('ch.deleted_at')->whereNotNull('ch.date_of_birth')
            ->whereRaw("DATE_FORMAT(ch.date_of_birth, '%m-%d') IN ('" . implode("','", $mmdd) . "')")
            ->limit(6)->get(['ch.first_name', 'ch.last_name', 'ch.date_of_birth']) as $r) {
            $rows[] = ['who' => trim($r->first_name . ' ' . $r->last_name), 'what' => '🎂 Birthday',
                'detail' => Carbon::parse($r->date_of_birth)->format('j M')];
        }
        return $rows ? ['count' => count($rows), 'rows' => array_slice($rows, 0, 10)] : null;
    }

    /** Children leaving soon whose report card has not been written. */
    private static function reportCards(array $c): ?array
    {
        if (! Schema::hasColumn('children', 'withdrawn_at')) return null;
        $done = Schema::hasTable('report_cards') ? DB::table('report_cards')->distinct()->pluck('child_id')->all() : [];
        $soon = Carbon::parse($c['to'])->addDays(30)->toDateString();

        $rows = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', $c['centres'])->whereNull('ch.deleted_at')
            ->whereNotNull('ch.withdrawn_at')->whereDate('ch.withdrawn_at', '<=', $soon)
            ->whereDate('ch.withdrawn_at', '>=', $c['from'])
            ->when($done, fn ($q) => $q->whereNotIn('ch.id', $done))
            ->orderBy('ch.withdrawn_at')->limit(6)
            ->get(['ch.first_name', 'ch.last_name', 'ch.withdrawn_at']);
        if ($rows->isEmpty()) return null;

        return ['count' => $rows->count(), 'rows' => $rows->map(fn ($r) => [
            'who' => trim($r->first_name . ' ' . $r->last_name),
            'what' => 'Report card not written',
            'detail' => 'leaving ' . substr((string) $r->withdrawn_at, 0, 10),
        ])->all()];
    }

    /** Completed forms in the window, and who completed them. */
    private static function forms(array $c): ?array
    {
        $people = [];
        $total = 0;
        if (Schema::hasTable('managed_form_signoffs')) {
            $cols = Schema::getColumnListing('managed_form_signoffs');
            $userCol = in_array('user_id', $cols) ? 'user_id' : (in_array('signed_by_id', $cols) ? 'signed_by_id' : null);
            $dateCol = in_array('signed_at', $cols) ? 'signed_at' : 'created_at';
            if ($userCol) {
                foreach (DB::table('managed_form_signoffs as s')->leftJoin('users as u', 'u.id', '=', 's.' . $userCol)
                    ->whereDate('s.' . $dateCol, '>=', $c['from'])->whereDate('s.' . $dateCol, '<=', $c['to'])
                    ->get(['u.first_name', 'u.last_name']) as $r) {
                    $n = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Unknown';
                    $people[$n] = ($people[$n] ?? 0) + 1;
                    $total++;
                }
            }
        }
        if (Schema::hasTable('custom_form_responses')) {
            $cols = Schema::getColumnListing('custom_form_responses');
            $dateCol = in_array('submitted_at', $cols) ? 'submitted_at' : 'created_at';
            $total += DB::table('custom_form_responses')
                ->whereDate($dateCol, '>=', $c['from'])->whereDate($dateCol, '<=', $c['to'])->count();
        }
        if (! $total) return null;
        arsort($people);
        return ['count' => $total, 'people' => array_slice($people, 0, 6, true)];
    }

    /** Educators scoring low, with something actionable rather than just a number. */
    /**
     * Days nobody closed: children still signed in, and staff never clocked out.
     *
     * The most perishable item in the digest. An hour after the fact somebody remembers
     * when the child left; a day later it is a guess, and by then the automatic sign-off
     * has stamped a time no one witnessed and the attendance record is fiction.
     */
    private static function openDays(array $c): ?array
    {
        $rows = [];

        // Children whose LAST event in the window was a check-in. The last event decides
        // it — a child who left and came back has both, and counting check-outs alone
        // would call them absent.
        $events = DB::table('check_events as e')
            ->join('children as ch', 'ch.id', '=', 'e.child_id')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->whereIn('f.centre_id', $c['centres'])
            ->whereDate('e.occurred_at', '>=', $c['from'])
            ->whereDate('e.occurred_at', '<=', $c['to'])
            ->whereNull('ch.deleted_at')
            ->orderBy('e.occurred_at')
            ->get(['e.child_id', 'e.event_type', 'e.occurred_at', 'ch.first_name', 'ch.last_name', 'ce.name as centre']);

        $last = [];
        foreach ($events as $ev) {
            $last[$ev->child_id] = $ev;
        }
        $stillIn = array_filter($last, fn ($e) => $e->event_type === 'check_in');

        foreach (array_slice($stillIn, 0, 8) as $e) {
            $rows[] = [
                'who' => trim($e->first_name . ' ' . ($e->last_name ?? '')),
                'what' => 'signed in but never signed out',
                'detail' => $e->centre,
            ];
        }

        // Staff whose punch was closed for them, or is still open.
        $punches = DB::table('time_punches as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.centre_id', $c['centres'])
            ->whereDate('p.punched_in_at', '>=', $c['from'])
            ->whereDate('p.punched_in_at', '<=', $c['to'])
            ->where(function ($q) {
                $q->whereNull('p.punched_out_at')->orWhere('p.source', 'auto');
            })
            ->limit(8)
            ->get(['u.first_name', 'u.last_name', 'p.punched_out_at', 'p.source']);

        foreach ($punches as $p) {
            $rows[] = [
                'who' => trim($p->first_name . ' ' . ($p->last_name ?? '')),
                'what' => $p->punched_out_at ? 'was clocked out automatically' : 'is still clocked in',
                'detail' => $p->punched_out_at ? 'hours may be wrong' : 'no clock-out yet',
            ];
        }

        if (! $rows) {
            return null;
        }

        return ['rows' => $rows, 'count' => count($stillIn) + $punches->count()];
    }

    /**
     * How each educator is doing, from what they actually did.
     *
     * Three parts, each shown in the email, because a director has to be able to say why
     * somebody scored what they did:
     *   recording (60) — observations and care moments, relative to the busiest educator
     *   clock-outs (20) — punches they closed themselves rather than the system closing
     *   sign-outs  (20) — children at their centre whose day was closed properly
     *
     * Relative rather than absolute: a fair target depends on room size and age group,
     * and the useful question is who is furthest from what this agency manages in practice.
     */
    private static function scorecard(array $c): ?array
    {
        $educators = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->where('ra.agency_id', $c['agency'])
            ->whereIn('ra.role', ['educator', 'home_visitor'])
            ->whereNull('u.deleted_at')
            ->distinct()
            ->get(['u.id', 'u.first_name', 'u.last_name', 'ra.centre_id']);
        if ($educators->isEmpty()) {
            return null;
        }

        $from = $c['from'];
        $to = $c['to'];
        $stats = [];

        foreach ($educators as $u) {
            $obs = DB::table('observations')->where('recorded_by_id', $u->id)
                ->whereDate('observed_at', '>=', $from)->whereDate('observed_at', '<=', $to)->count();

            $care = 0;
            if (Schema::hasTable('daily_care_logs')) {
                $care += DB::table('daily_care_logs')->where('recorded_by_id', $u->id)
                    ->whereDate('occurred_at', '>=', $from)->whereDate('occurred_at', '<=', $to)->count();
            }
            $care += DB::table('daily_events')->where('recorded_by_id', $u->id)
                ->whereDate('occurred_at', '>=', $from)->whereDate('occurred_at', '<=', $to)->count();

            $punches = DB::table('time_punches')->where('user_id', $u->id)
                ->whereDate('punched_in_at', '>=', $from)->whereDate('punched_in_at', '<=', $to)->count();
            $clean = DB::table('time_punches')->where('user_id', $u->id)
                ->whereDate('punched_in_at', '>=', $from)->whereDate('punched_in_at', '<=', $to)
                ->whereNotNull('punched_out_at')->where(fn ($q) => $q->where('source', '!=', 'auto')->orWhereNull('source'))
                ->count();

            $stats[$u->id] = [
                'who' => trim($u->first_name . ' ' . ($u->last_name ?? '')),
                'activity' => $obs + $care,
                'obs' => $obs,
                'care' => $care,
                'punches' => $punches,
                'clean' => $clean,
                'centre' => $u->centre_id,
            ];
        }

        // Nobody recorded anything and nobody clocked in: there is no signal, and inventing
        // a league table out of zeroes would be worse than saying nothing.
        $best = max(array_map(fn ($s) => $s['activity'], $stats)) ?: 0;
        if ($best === 0) {
            return null;
        }

        // Sign-out completion per centre: days a PERSON closed, against every day opened.
        // Shared by everyone at that centre, which is why the email calls it a centre
        // figure rather than presenting it as one educator's own.
        $signOut = [];
        foreach (array_unique(array_filter(array_column($stats, 'centre'))) as $centreId) {
            $opened = DB::table('check_events as e')
                ->join('children as ch', 'ch.id', '=', 'e.child_id')
                ->join('families as f', 'f.id', '=', 'ch.family_id')
                ->where('f.centre_id', $centreId)->where('e.event_type', 'check_in')
                ->whereDate('e.occurred_at', '>=', $from)->whereDate('e.occurred_at', '<=', $to)
                ->count();
            $byHand = DB::table('check_events as e')
                ->join('children as ch', 'ch.id', '=', 'e.child_id')
                ->join('families as f', 'f.id', '=', 'ch.family_id')
                ->where('f.centre_id', $centreId)->where('e.event_type', 'check_out')
                ->whereDate('e.occurred_at', '>=', $from)->whereDate('e.occurred_at', '<=', $to)
                ->where(function ($q) {
                    $q->whereNull('e.notes')->orWhere('e.notes', 'not like', '%Auto sign-off%');
                })
                ->count();
            // Nothing opened is not a failure — a centre closed for the period scores full.
            $signOut[$centreId] = $opened > 0 ? min(1, $byHand / $opened) : 1.0;
        }

        $rows = [];
        foreach ($stats as $s) {
            $recording = (int) round(min(1, $s['activity'] / $best) * 60);
            $clocking = $s['punches'] > 0 ? (int) round($s['clean'] / $s['punches'] * 20) : 20;
            // No punches at all is not a failing — a home visitor may never clock in — so
            // the clock-out share is treated as met rather than zero.
            $signRate = $signOut[$s['centre']] ?? 1.0;
            $signing = (int) round($signRate * 20);
            $score = $recording + $clocking + $signing;

            $rows[] = [
                'who' => $s['who'],
                'score' => min(100, $score),
                'detail' => $s['obs'] . ' obs, ' . $s['care'] . ' logs'
                    . ($s['punches'] ? ', ' . $s['clean'] . '/' . $s['punches'] . ' clock-outs' : '')
                    . ', ' . round($signRate * 100) . '% signed out',
                'tip' => self::scoreTip($score, $s + ['sign_rate' => $signRate]),
            ];
        }

        usort($rows, fn ($a, $b) => $a['score'] <=> $b['score']);

        return ['rows' => $rows, 'count' => count($rows)];
    }

    /** Advice matched to what is actually low, not the same paragraph under every name. */
    private static function scoreTip(int $score, array $s): string
    {
        if ($s['punches'] > 0 && $s['clean'] / $s['punches'] < 0.7) {
            return 'Mostly a clock-out habit — hours are being closed by the system, which makes timesheets unreliable.';
        }
        if (($s['sign_rate'] ?? 1) < 0.7) {
            return 'Children at this centre are often left signed in — attendance and ratio records are built from those times.';
        }
        if ($score >= 85) {
            return 'Doing well. Worth saying so at the next check-in.';
        }
        if ($s['obs'] === 0) {
            return 'No observations this period. One per child per week is the single biggest lift, and families notice them.';
        }
        if ($score < 55) {
            return 'Little of the day is being recorded. Care logs take seconds at the time and minutes at the end.';
        }

        return 'Close to the mark — a short note on each care log turns a tick into something a parent can read.';
    }

    private static function educators(array $c): ?array
    {
        if (! Schema::hasTable('engagement_scores')) return null;
        $cols = Schema::getColumnListing('engagement_scores');
        $scoreCol = in_array('score', $cols) ? 'score' : (in_array('value', $cols) ? 'value' : null);
        $userCol = in_array('user_id', $cols) ? 'user_id' : (in_array('educator_id', $cols) ? 'educator_id' : null);
        if (! $scoreCol || ! $userCol) return null;

        $rows = DB::table('engagement_scores as e')->join('users as u', 'u.id', '=', 'e.' . $userCol)
            ->whereDate('e.created_at', '>=', Carbon::parse($c['from'])->subDays(30)->toDateString())
            ->groupBy('u.id', 'u.first_name', 'u.last_name')
            ->selectRaw('u.first_name, u.last_name, AVG(e.' . $scoreCol . ') avg_score, COUNT(*) n')
            ->orderBy('avg_score')->limit(4)->get();
        if ($rows->isEmpty()) return null;

        // Tips are matched to the score band, so the advice changes with the problem
        // rather than being the same paragraph under every name.
        $tip = function (float $s): string {
            if ($s < 40) return 'Start with one observation per child per week — the score is mostly driven by how much of the day gets recorded.';
            if ($s < 60) return 'Photos and daily logs lift this fastest: families notice them, and they take seconds at the time rather than minutes at the end.';
            if ($s < 75) return 'Close to the mark. Adding a short note to each care log turns a tick into something a parent can read.';
            return 'Doing well — worth saying so at the next check-in.';
        };
        return ['rows' => $rows->map(fn ($r) => [
            'who' => trim($r->first_name . ' ' . $r->last_name),
            'score' => round((float) $r->avg_score),
            'tip' => $tip((float) $r->avg_score),
        ])->all()];
    }
}
