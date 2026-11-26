<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC'S OWN REPORTS (2026-10-31, Anthony: "add a report that only superadmin can see for sales reports, leads
 * reports, cold call reports" + "campaign report"). Reports → KiddieTrac group. Platform data only - never an
 * agency's. Every route is role:platform_admin AND this controller re-checks it (a view-as or a stale role never
 * opens it). Real figures only: anything the platform does not record comes back null / listed in not_tracked.
 */
class PlatformReportsController extends Controller
{
    private function guard(Request $r): array
    {
        $u = $r->user();
        abort_unless($u && DB::table('role_assignments')->where('user_id', $u->id)->where('role', 'platform_admin')->where('active', 1)->exists(), 403);
        $ok = fn ($v) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v);
        $from = $ok($r->query('from')) ? (string) $r->query('from') : now()->startOfYear()->toDateString();
        $to = $ok($r->query('to')) ? (string) $r->query('to') : now()->toDateString();
        return [$from, $to];
    }

    private function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) return [];
        return DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)])->all();
    }

    private static function month($d): string { return $d ? substr((string) $d, 0, 7) : ''; }

    /** GET /platform/reports/sales - pipeline, proposals, won/lost, conversion, revenue booked, by month and owner. */
    public function sales(Request $r): JsonResponse
    {
        [$from, $to] = $this->guard($r);
        $leads = DB::table('sales_leads')->whereNull('deleted_at')->get(['id', 'name', 'company', 'stage', 'status', 'value', 'value_currency', 'owner_id', 'lost_reason', 'created_at', 'converted_at', 'updated_at', 'expected_close']);
        $inRange = fn ($d) => $d && substr((string) $d, 0, 10) >= $from && substr((string) $d, 0, 10) <= $to;

        $stages = $leads->where('status', 'open')->groupBy('stage')->map(fn ($s, $k) => ['stage' => $k ?: 'new', 'leads' => $s->count(), 'value' => round((float) $s->sum('value'), 2)])->values();
        $quotes = DB::table('sales_quotes')->whereNull('deleted_at')->get(['id', 'lead_id', 'number', 'status', 'total', 'currency', 'sent_at', 'client_signed_at', 'declined_at', 'approval_status', 'created_at', 'prepared_by']);
        $q = [
            'created' => $quotes->filter(fn ($x) => $inRange($x->created_at))->count(),
            'sent' => $quotes->filter(fn ($x) => $inRange($x->sent_at))->count(),
            'signed' => $quotes->filter(fn ($x) => $inRange($x->client_signed_at))->count(),
            'declined' => $quotes->filter(fn ($x) => $inRange($x->declined_at))->count(),
            'sent_value' => round((float) $quotes->filter(fn ($x) => $inRange($x->sent_at))->sum('total'), 2),
            'signed_value' => round((float) $quotes->filter(fn ($x) => $inRange($x->client_signed_at))->sum('total'), 2),
        ];
        $won = $leads->where('status', 'won'); $lost = $leads->where('status', 'lost');
        $closed = $won->count() + $lost->count();
        $reasons = $lost->groupBy(fn ($l) => trim((string) $l->lost_reason) ?: 'No reason given')->map(fn ($s, $k) => ['reason' => $k, 'leads' => $s->count()])->values();

        // Revenue booked: the same figures as the KiddieTrac sales report (platform invoices + licences).
        $rev = null;
        try {
            $req = Request::create('/', 'GET', ['from' => $from, 'to' => $to]);
            $req->setUserResolver(fn () => $r->user());
            $res = app(SalesReportsController::class)->platform($req)->getData(true);
            $t = $res['totals'] ?? [];
            $rev = ['invoiced_cents' => isset($t['invoiced']) ? (int) $t['invoiced'] : null, 'collected_cents' => isset($t['collected']) ? (int) $t['collected'] : null, 'booked_cents' => $t['booked_cents'] ?? null,
                'mrr_cents' => $t['mrr_cents'] ?? null, 'arr_cents' => $t['arr_cents'] ?? null, 'customers' => $t['customers'] ?? null, 'raw_totals' => $t];
        } catch (\Throwable $e) { $rev = null; }

        $owners = $this->names($leads->pluck('owner_id')->all());
        $byOwner = $leads->groupBy(fn ($l) => (int) $l->owner_id)->map(function ($s, $oid) use ($owners) {
            return ['owner' => $owners[$oid] ?? 'Unassigned', 'open' => $s->where('status', 'open')->count(), 'won' => $s->where('status', 'won')->count(),
                'lost' => $s->where('status', 'lost')->count(), 'pipeline' => round((float) $s->where('status', 'open')->sum('value'), 2)];
        })->values();

        $months = [];
        $bump = function ($d, $k, $v = 1) use (&$months, $inRange) { if (! $inRange($d)) return; $m = self::month($d); $months[$m] = $months[$m] ?? ['month' => $m, 'leads' => 0, 'quotes_sent' => 0, 'won' => 0, 'lost' => 0]; $months[$m][$k] += $v; };
        foreach ($leads as $l) { $bump($l->created_at, 'leads'); if ($l->status === 'won') $bump($l->converted_at ?: $l->updated_at, 'won'); if ($l->status === 'lost') $bump($l->updated_at, 'lost'); }
        foreach ($quotes as $x) { $bump($x->sent_at, 'quotes_sent'); }
        ksort($months);

        return response()->json([
            'from' => $from, 'to' => $to,
            'totals' => ['open_leads' => $leads->where('status', 'open')->count(), 'pipeline_value' => round((float) $leads->where('status', 'open')->sum('value'), 2),
                'won' => $won->count(), 'lost' => $lost->count(), 'win_rate' => $closed ? round($won->count() / $closed * 100, 1) : null,
                'quote_accept_rate' => $q['sent'] ? round($q['signed'] / $q['sent'] * 100, 1) : null],
            'stages' => $stages, 'quotes' => $q, 'lost_reasons' => $reasons, 'revenue' => $rev,
            'by_owner' => $byOwner, 'months' => array_values($months),
            'won_list' => $won->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->company ?: $l->name, 'value' => (float) $l->value, 'when' => $l->converted_at ?: $l->updated_at])->values(),
            'quote_list' => $quotes->sortByDesc('created_at')->map(fn ($x) => ['number' => $x->number, 'status' => $x->status, 'total' => (float) $x->total, 'currency' => $x->currency,
                'sent_at' => $x->sent_at, 'signed_at' => $x->client_signed_at, 'declined_at' => $x->declined_at])->values(),
            'not_tracked' => [],
        ]);
    }

    /** GET /platform/reports/leads - by source, stage funnel, response time, follow-ups, meetings, conversion, aging. */
    public function leads(Request $r): JsonResponse
    {
        [$from, $to] = $this->guard($r);
        $leads = DB::table('sales_leads')->whereNull('deleted_at')
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->get(['id', 'name', 'company', 'email', 'source', 'stage', 'status', 'owner_id', 'follow_up_date', 'last_activity_at', 'created_at', 'converted_agency_id', 'converted_at', 'value']);
        $ids = $leads->pluck('id')->all() ?: [0];
        $src = function ($s) {
            $s = strtolower(trim((string) $s));
            if ($s === '' || $s === 'manual') return 'Added by hand';
            if (str_contains($s, 'chat')) return 'Website chat';
            if (str_contains($s, 'site') || str_contains($s, 'web')) return 'Website';
            if (str_contains($s, 'cold')) return 'Cold reach';
            if (str_contains($s, 'refer')) return 'Referral';
            if (str_contains($s, 'book')) return 'Booking';
            return ucfirst($s);
        };
        $bySource = $leads->groupBy(fn ($l) => $src($l->source))->map(fn ($s, $k) => ['source' => $k, 'leads' => $s->count(),
            'won' => $s->where('status', 'won')->count(), 'converted' => $s->whereNotNull('converted_agency_id')->count()])->sortByDesc('leads')->values();

        $order = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won'];
        $stageCount = $leads->groupBy(fn ($l) => $l->status === 'won' ? 'won' : ($l->stage ?: 'new'))->map->count();
        $funnel = [];
        foreach ($order as $i => $st) { // "reached at least this stage"
            $n = 0; foreach ($stageCount as $k => $c) { $pos = array_search($k, $order, true); if ($pos !== false && $pos >= $i) $n += $c; }
            $funnel[] = ['stage' => $st, 'leads' => $n];
        }

        $firstTouch = DB::table('sales_activities')->whereIn('lead_id', $ids)->whereIn('type', ['call', 'email', 'text', 'meeting'])
            ->groupBy('lead_id')->selectRaw('lead_id, MIN(created_at) t')->pluck('t', 'lead_id');
        $hrs = [];
        foreach ($leads as $l) { if (isset($firstTouch[$l->id])) { $h = Carbon::parse($l->created_at)->diffInMinutes(Carbon::parse($firstTouch[$l->id]), false) / 60; if ($h >= 0) $hrs[] = $h; } }
        sort($hrs);
        $median = $hrs ? round($hrs[intdiv(count($hrs), 2)], 1) : null;

        $today = now()->toDateString();
        $open = $leads->where('status', 'open');
        $due = DB::table('sales_activities')->whereIn('lead_id', $ids)->where('done', 0)->whereNotNull('due_date')->get(['lead_id', 'due_date', 'type', 'body']);
        $meet = DB::table('marketing_bookings')->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])->get(['status', 'sales_lead_id', 'starts_at']);
        $aging = ['0–7 days' => 0, '8–30 days' => 0, '31–90 days' => 0, '90+ days' => 0];
        foreach ($open as $l) {
            $d = Carbon::parse($l->last_activity_at ?: $l->created_at)->diffInDays(now());
            $aging[$d <= 7 ? '0–7 days' : ($d <= 30 ? '8–30 days' : ($d <= 90 ? '31–90 days' : '90+ days'))]++;
        }
        $owners = $this->names($leads->pluck('owner_id')->all());
        return response()->json([
            'from' => $from, 'to' => $to,
            'totals' => ['leads' => $leads->count(), 'open' => $open->count(), 'won' => $leads->where('status', 'won')->count(), 'lost' => $leads->where('status', 'lost')->count(),
                'converted' => $leads->whereNotNull('converted_agency_id')->count(),
                'conversion_rate' => $leads->count() ? round($leads->whereNotNull('converted_agency_id')->count() / $leads->count() * 100, 1) : null,
                'median_first_response_hours' => $median, 'responded' => count($hrs),
                'followups_due_today' => $open->where('follow_up_date', $today)->count() + $due->where('due_date', $today)->count(),
                'followups_overdue' => $open->filter(fn ($l) => $l->follow_up_date && $l->follow_up_date < $today)->count() + $due->filter(fn ($a) => $a->due_date < $today)->count(),
                'meetings_booked' => $meet->whereIn('status', ['booked', 'requested'])->count(), 'meetings_total' => $meet->count()],
            'by_source' => $bySource, 'funnel' => $funnel, 'aging' => collect($aging)->map(fn ($v, $k) => ['bucket' => $k, 'leads' => $v])->values(),
            'meetings' => $meet->groupBy('status')->map(fn ($s, $k) => ['status' => $k, 'count' => $s->count()])->values(),
            'list' => $leads->sortByDesc('created_at')->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->company ?: $l->name, 'contact' => $l->name, 'source' => $src($l->source),
                'stage' => $l->stage, 'status' => $l->status, 'owner' => $owners[(int) $l->owner_id] ?? '', 'created_at' => $l->created_at,
                'first_response_hours' => isset($firstTouch[$l->id]) ? round(max(0, Carbon::parse($l->created_at)->diffInMinutes(Carbon::parse($firstTouch[$l->id]), false)) / 60, 1) : null,
                'follow_up_date' => $l->follow_up_date, 'last_activity_at' => $l->last_activity_at, 'converted' => (bool) $l->converted_agency_id])->values(),
            'not_tracked' => [],
        ]);
    }

    /** GET /platform/reports/coldreach - batches, step funnel, opens/clicks/replies, calls, unsubscribes, meetings, volume vs cap. */
    public function coldreach(Request $r): JsonResponse
    {
        [$from, $to] = $this->guard($r);
        $p = DB::table('sales_prospects')->whereNull('deleted_at');
        $batches = DB::table('sales_prospect_batches as b')->whereNull('b.deleted_at')
            ->leftJoin('sales_prospects as p', function ($j) { $j->on('p.batch_id', '=', 'b.id')->whereNull('p.deleted_at'); })
            ->groupBy('b.id', 'b.name', 'b.created_at')
            ->selectRaw("b.id, b.name, b.created_at, COUNT(p.id) prospects,
                SUM(p.email IS NOT NULL AND p.email != '') with_email, SUM(p.emails_sent > 0) emailed, SUM(p.calls > 0) called,
                SUM(p.last_opened_at IS NOT NULL OR p.opens > 0) opened, SUM(p.replied_at IS NOT NULL) replied,
                SUM(p.status = 'do_not_contact') dnc, SUM(p.lead_id IS NOT NULL) to_lead, SUM(p.queued_at IS NOT NULL) queued")
            ->orderByDesc('b.created_at')->get();

        $touches = DB::table('sales_prospect_touches')->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])->get(['prospect_id', 'kind', 'outcome', 'step', 'automatic', 'opened_at', 'open_count', 'token', 'created_at']);
        $emails = $touches->where('kind', 'email');
        $steps = $emails->where('outcome', 'sent')->groupBy(fn ($t) => (int) $t->step)->map(function ($s, $k) {
            return ['step' => (int) $k, 'label' => (int) $k === 0 ? 'First email' : 'Follow-up ' . (int) $k, 'sent' => $s->count(), 'opened' => $s->whereNotNull('opened_at')->count()];
        })->sortBy('step')->values();
        $tokens = $emails->pluck('token')->filter()->values()->all();
        $clicked = $tokens ? DB::table('sales_campaign_recipients')->whereIn('token', $tokens)->where('click_count', '>', 0)->count() : 0;
        $calls = $touches->where('kind', 'call')->groupBy(fn ($t) => $t->outcome ?: 'logged')->map(fn ($s, $k) => ['outcome' => $k, 'count' => $s->count()])->values();

        $leadIds = (clone $p)->whereNotNull('lead_id')->pluck('lead_id')->all();
        $meetings = $leadIds ? DB::table('marketing_bookings')->whereIn('sales_lead_id', $leadIds)->whereIn('status', ['booked', 'requested', 'held', 'no_show', 'rescheduled'])->count() : 0;
        $daily = $emails->where('outcome', 'sent')->groupBy(fn ($t) => substr((string) $t->created_at, 0, 10))->map(fn ($s, $k) => ['day' => $k, 'sent' => $s->count()])->sortBy('day')->values();
        $cap = null; try { $cap = (int) (\App\Support\ColdReach::settings()['daily_cap'] ?? 0) ?: null; } catch (\Throwable $e) {}
        $statuses = (clone $p)->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status');
        $replies = (clone $p)->whereNotNull('replied_at')->whereBetween(DB::raw('DATE(replied_at)'), [$from, $to])->count();
        $supp = Schema::hasTable('sales_suppressions') ? DB::table('sales_suppressions')->count() : 0;

        return response()->json([
            'from' => $from, 'to' => $to,
            'totals' => ['prospects' => (int) (clone $p)->count(), 'emailed' => (int) (clone $p)->where('emails_sent', '>', 0)->count(),
                'called' => (int) (clone $p)->where('calls', '>', 0)->count(), 'queued' => (int) (clone $p)->whereNotNull('queued_at')->count(),
                'emails_sent' => $emails->where('outcome', 'sent')->count(), 'emails_failed' => $emails->where('outcome', 'failed')->count(),
                'opened' => $emails->whereNotNull('opened_at')->count(), 'clicked' => $clicked, 'replies' => $replies,
                'calls' => $touches->where('kind', 'call')->count(), 'do_not_contact' => (int) ($statuses['do_not_contact'] ?? 0),
                'unsubscribed' => $supp, 'meetings_booked' => $meetings, 'to_leads' => count($leadIds), 'daily_cap' => $cap],
            'statuses' => collect($statuses)->map(fn ($n, $k) => ['status' => $k, 'count' => (int) $n])->values(),
            'steps' => $steps, 'calls' => $calls, 'daily' => $daily, 'batches' => $batches,
            'not_tracked' => ['Opens for automatic follow-ups sent as plain emails (only designed emails carry a tracker)', 'Opens and clicks can include email security scanners that open links automatically'],
        ]);
    }

    /** GET /platform/reports/campaigns - KiddieTrac's own sales campaigns: sent, opened, clicked, responded, leads, proposals. */
    public function campaigns(Request $r): JsonResponse
    {
        [$from, $to] = $this->guard($r);
        $camps = DB::table('sales_campaigns')->whereNull('deleted_at')->orderByDesc(DB::raw('COALESCE(sent_at, created_at)'))->get();
        $rows = $camps->map(function ($c) use ($from, $to) {
            $rec = DB::table('sales_campaign_recipients')->where('campaign_id', $c->id)
                ->selectRaw("COUNT(*) total, SUM(status = 'sent') sent, SUM(status = 'failed') failed, SUM(opened_at IS NOT NULL) opened,
                    SUM(clicked_at IS NOT NULL) clicked, SUM(responded_at IS NOT NULL) responded, SUM(lead_id IS NOT NULL) leads")->first();
            $leadIds = DB::table('sales_campaign_recipients')->where('campaign_id', $c->id)->whereNotNull('lead_id')->pluck('lead_id')->all();
            $proposals = $leadIds ? DB::table('sales_quotes')->whereIn('lead_id', $leadIds)->whereNull('deleted_at')->whereNotNull('sent_at')->count() : 0;
            $won = $leadIds ? DB::table('sales_leads')->whereIn('id', $leadIds)->where('status', 'won')->count() : 0;
            $sent = (int) ($rec->sent ?? 0);
            $links = DB::table('sales_campaign_recipients')->where('campaign_id', $c->id)->whereNotNull('last_link')
                ->groupBy('last_link')->selectRaw('last_link, COUNT(*) n')->orderByDesc('n')->limit(3)->pluck('n', 'last_link');
            return ['id' => (int) $c->id, 'name' => $c->name, 'subject' => $c->subject, 'status' => $c->status, 'sent_at' => $c->sent_at, 'scheduled_for' => $c->scheduled_for,
                'recipients' => (int) ($rec->total ?? 0), 'sent' => $sent, 'failed' => (int) ($rec->failed ?? 0),
                'opened' => (int) ($rec->opened ?? 0), 'clicked' => (int) ($rec->clicked ?? 0), 'responded' => (int) ($rec->responded ?? 0),
                'unsubscribed' => (int) $c->unsub_count, 'leads' => (int) ($rec->leads ?? 0), 'proposals' => $proposals, 'won' => $won,
                'open_rate' => $sent ? round(((int) $rec->opened) / $sent * 100, 1) : null, 'click_rate' => $sent ? round(((int) $rec->clicked) / $sent * 100, 1) : null,
                'top_links' => collect($links)->map(fn ($n, $u) => ['link' => $u, 'clicks' => (int) $n])->values()];
        })->values();
        $sum = fn ($k) => (int) $rows->sum($k);
        return response()->json([
            'from' => $from, 'to' => $to, 'campaigns' => $rows,
            'totals' => ['campaigns' => $rows->count(), 'sent' => $sum('sent'), 'opened' => $sum('opened'), 'clicked' => $sum('clicked'),
                'responded' => $sum('responded'), 'leads' => $sum('leads'), 'proposals' => $sum('proposals'), 'won' => $sum('won'), 'unsubscribed' => $sum('unsubscribed'),
                'open_rate' => $sum('sent') ? round($sum('opened') / $sum('sent') * 100, 1) : null],
            'not_tracked' => ['Top links show each recipient\'s last link clicked only'],
        ]);
    }
}
