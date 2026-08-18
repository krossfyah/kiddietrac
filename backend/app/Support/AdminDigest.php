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
