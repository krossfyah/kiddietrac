<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SalesCustomers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales -> Customers (2026-11-10). KiddieTrac's own customers: every won lead (App\Support\SalesCustomers), what they
 * bought, and - for superadmins only - where their account stands in AR (platform_invoices / payments by agency).
 * Sales reps see the customers and their services, never the money owed: that is the AR/AP screen's, which is
 * platform_admin only, and this fails closed the same way.
 */
class SalesCustomerController extends Controller
{
    private function superadmin(Request $r): bool
    {
        $u = $r->user();
        return $u && (! empty($u->is_platform_admin)
            || DB::table('role_assignments')->where('user_id', $u->id)->where('role', 'platform_admin')->where('active', 1)->exists());
    }

    /** AR per agency in cents: invoiced, paid, outstanding, overdue, last payment, count. Void invoices left out. */
    private function arByAgency(array $agencyIds): array
    {
        if (! $agencyIds) return [];
        $now = now()->toDateTimeString();
        return DB::table('platform_invoices')->whereIn('agency_id', $agencyIds)->where('status', '!=', 'void')
            ->selectRaw("agency_id, COUNT(*) AS n, SUM(amount_cents) AS invoiced, SUM(amount_paid_cents) AS paid,
                SUM(CASE WHEN status = 'issued' THEN GREATEST(amount_cents - amount_paid_cents, 0) ELSE 0 END) AS outstanding,
                SUM(CASE WHEN status = 'issued' AND due_at IS NOT NULL AND due_at < ? THEN GREATEST(amount_cents - amount_paid_cents, 0) ELSE 0 END) AS overdue,
                MAX(paid_at) AS last_paid", [$now])
            ->groupBy('agency_id')->get()->keyBy('agency_id')->all();
    }

    private static function iso($t): ?string
    {
        return $t ? \Illuminate\Support\Carbon::parse($t)->toIso8601String() : null;
    }

    private function shape(object $c, array $agencies, array $ar, bool $super, array $owners): array
    {
        $svc = json_decode((string) $c->services, true) ?: [];
        $named = array_values(array_filter(array_map(fn ($s) => ($s['kind'] ?? '') === 'discount' ? null : trim((string) preg_replace('/\s+—.*$/u', '', (string) $s['description'])), $svc)));
        $a = $c->agency_id ? ($ar[$c->agency_id] ?? null) : null;
        return [
            'id' => (int) $c->id, 'number' => $c->customer_number, 'company' => $c->company, 'contact' => $c->contact_name,
            'email' => $c->email, 'phone' => $c->phone, 'place' => trim(implode(', ', array_filter([$c->city, $c->province]))),
            'status' => $c->status, 'lead_id' => $c->lead_id ? (int) $c->lead_id : null, 'quote_id' => $c->quote_id ? (int) $c->quote_id : null,
            'quote_number' => $c->quote_id ? DB::table('sales_quotes')->where('id', $c->quote_id)->value('number') : null,
            'agency_id' => $c->agency_id ? (int) $c->agency_id : null, 'agency' => $c->agency_id ? ($agencies[$c->agency_id] ?? null) : null,
            'billing_period' => $c->billing_period, 'currency' => $c->currency ?: 'CAD',
            'mrr' => (float) $c->mrr, 'arr' => round((float) $c->mrr * 12, 2), 'one_time' => (float) $c->one_time, 'annual_value' => (float) $c->annual_value,
            'services' => $svc, 'services_summary' => implode(' · ', array_slice($named, 0, 4)) . (count($named) > 4 ? ' +' . (count($named) - 4) : ''),
            // A customer set up outside Sales (no lead) carries its plan as services, not a proposal.
            'no_services' => ! $c->quote_id && ! $svc, 'won_at' => self::iso($c->won_at), 'owner' => $owners[$c->lead_id] ?? null, 'notes' => $c->notes,
            // AR: superadmins only (fail closed).
            'ar' => $super && $c->agency_id ? [
                'invoices' => (int) ($a->n ?? 0), 'invoiced' => round(((int) ($a->invoiced ?? 0)) / 100, 2), 'paid' => round(((int) ($a->paid ?? 0)) / 100, 2),
                'outstanding' => round(((int) ($a->outstanding ?? 0)) / 100, 2), 'overdue' => round(((int) ($a->overdue ?? 0)) / 100, 2),
                'last_paid' => self::iso($a->last_paid ?? null),
            ] : null,
        ];
    }

    /** GET /sales/customers */
    public function index(Request $r): JsonResponse
    {
        SalesCustomers::sweep();
        $super = $this->superadmin($r);
        $rows = DB::table('sales_customers')->orderByDesc('won_at')->orderByDesc('id')->get();
        $agencyIds = $rows->pluck('agency_id')->filter()->unique()->values()->all();
        $agencies = $agencyIds ? DB::table('agencies')->whereIn('id', $agencyIds)->pluck('name', 'id')->all() : [];
        $ar = $super ? $this->arByAgency($agencyIds) : [];
        $owners = DB::table('sales_leads as l')->leftJoin('users as u', 'u.id', '=', 'l.owner_id')->whereIn('l.id', $rows->pluck('lead_id')->all())
            ->pluck('u.first_name', 'l.id')->all();
        $list = $rows->map(fn ($c) => $this->shape($c, $agencies, $ar, $super, $owners))->values()->all();

        $live = array_values(array_filter($list, fn ($c) => in_array($c['status'], ['active', 'onboarding'], true)));
        $mrr = array_sum(array_column($live, 'mrr'));
        $stats = [
            'total' => count($list), 'live' => count($live),
            'active' => count(array_filter($list, fn ($c) => $c['status'] === 'active')),
            'onboarding' => count(array_filter($list, fn ($c) => $c['status'] === 'onboarding')),
            'paused' => count(array_filter($list, fn ($c) => $c['status'] === 'paused')),
            'cancelled' => count(array_filter($list, fn ($c) => $c['status'] === 'cancelled')),
            'no_services' => count(array_filter($list, fn ($c) => $c['no_services'])),
            'no_account' => count(array_filter($live, fn ($c) => ! $c['agency_id'])),
            'mrr' => round($mrr, 2), 'arr' => round($mrr * 12, 2), 'avg_mrr' => $live ? round($mrr / count($live), 2) : 0,
            'one_time' => round(array_sum(array_column($live, 'one_time')), 2),
            'new_this_month' => count(array_filter($list, fn ($c) => $c['won_at'] && substr($c['won_at'], 0, 7) === now()->format('Y-m'))),
            'currencies' => array_values(array_unique(array_column($list, 'currency'))),
        ];
        if ($super) {
            $stats['ar_outstanding'] = round(array_sum(array_map(fn ($c) => $c['ar']['outstanding'] ?? 0, $list)), 2);
            $stats['ar_overdue'] = round(array_sum(array_map(fn ($c) => $c['ar']['overdue'] ?? 0, $list)), 2);
            $stats['ar_paid'] = round(array_sum(array_map(fn ($c) => $c['ar']['paid'] ?? 0, $list)), 2);
            $stats['ar_overdue_count'] = count(array_filter($list, fn ($c) => ($c['ar']['overdue'] ?? 0) > 0));
        }

        // Last 12 months: customers won, the monthly revenue they added, and monthly revenue running total (live ones).
        $months = [];
        for ($i = 11; $i >= 0; $i--) { $m = now()->startOfMonth()->subMonths($i)->format('Y-m'); $months[$m] = ['month' => $m, 'won' => 0, 'new_mrr' => 0, 'mrr' => 0]; }
        $first = array_key_first($months);
        $before = 0;
        foreach ($list as $c) {
            $m = $c['won_at'] ? substr($c['won_at'], 0, 7) : null;
            if (! $m) continue;
            if (isset($months[$m])) { $months[$m]['won']++; if (in_array($c['status'], ['active', 'onboarding'], true)) $months[$m]['new_mrr'] += $c['mrr']; }
            elseif ($m < $first && in_array($c['status'], ['active', 'onboarding'], true)) $before += $c['mrr'];
        }
        $run = $before;
        foreach ($months as $k => $row) { $run += $row['new_mrr']; $months[$k]['mrr'] = round($run, 2); $months[$k]['new_mrr'] = round($row['new_mrr'], 2); }

        // What they pay for, per month (live customers, recurring lines, discounts left out).
        $mix = [];
        foreach ($live as $c) {
            foreach ($c['services'] as $s) {
                if (($s['kind'] ?? '') === 'discount' || ($s['unit'] ?? '') === 'one-time' || (float) $s['line_total'] <= 0) continue;
                $name = trim((string) preg_replace('/\s+—.*$/u', '', (string) $s['description'])) ?: 'Other';
                $mix[$name] = ($mix[$name] ?? 0) + ($c['billing_period'] === 'year' ? (float) $s['line_total'] / 12 : (float) $s['line_total']);
            }
        }
        arsort($mix);
        $mixOut = [];
        foreach (array_slice($mix, 0, 5, true) as $k => $v) $mixOut[] = ['label' => $k, 'value' => round($v, 2)];
        if (($rest = array_sum(array_slice($mix, 5))) > 0) $mixOut[] = ['label' => 'Other', 'value' => round($rest, 2)];

        return response()->json(['customers' => $list, 'stats' => $stats, 'by_month' => array_values($months), 'mix' => $mixOut,
            'statuses' => SalesCustomers::STATUSES, 'can_see_ar' => $super]);
    }

    /** GET /sales/customers/{id}: one customer, with its invoices and payments for superadmins. */
    public function show(Request $r, int $id): JsonResponse
    {
        $c = DB::table('sales_customers')->find($id);
        abort_unless($c, 404, 'Not found.');
        $super = $this->superadmin($r);
        $agencies = $c->agency_id ? DB::table('agencies')->where('id', $c->agency_id)->pluck('name', 'id')->all() : [];
        $ar = $super && $c->agency_id ? $this->arByAgency([(int) $c->agency_id]) : [];
        $owners = DB::table('sales_leads as l')->leftJoin('users as u', 'u.id', '=', 'l.owner_id')->where('l.id', $c->lead_id)->pluck('u.first_name', 'l.id')->all();
        $out = $this->shape($c, $agencies, $ar, $super, $owners);
        // The price with every discount and the totals before/after, and the agency in full (2026-11-11).
        $out['pricing'] = SalesCustomers::pricing($c);
        $out['agency_info'] = $this->agencyInfo($c);
        // Referral credits (2026-11-26): given to this customer, and who referred it. Amounts for superadmins only.
        $out['referrals'] = $c->agency_id ? \App\Support\ReferralCredits::forAgency((int) $c->agency_id, $c->lead_id ? (int) $c->lead_id : null, $super)
            : ['given' => [], 'referred_by' => null];
        if ($super && $c->agency_id) {
            $out['invoices'] = DB::table('platform_invoices')->where('agency_id', $c->agency_id)->orderByDesc('issued_at')->orderByDesc('id')->limit(100)
                ->get(['id', 'number', 'status', 'amount_cents', 'amount_paid_cents', 'currency', 'issued_at', 'due_at', 'paid_at', 'period_start', 'period_end'])
                ->map(fn ($i) => ['id' => (int) $i->id, 'number' => $i->number, 'status' => $i->status, 'amount' => round($i->amount_cents / 100, 2),
                    'period_start' => $i->period_start ? substr((string) $i->period_start, 0, 10) : null, 'period_end' => $i->period_end ? substr((string) $i->period_end, 0, 10) : null,
                    'paid' => round(((int) $i->amount_paid_cents) / 100, 2), 'currency' => $i->currency, 'issued_at' => self::iso($i->issued_at),
                    'due_at' => self::iso($i->due_at), 'paid_at' => self::iso($i->paid_at),
                    'overdue' => $i->status === 'issued' && $i->due_at && \Illuminate\Support\Carbon::parse($i->due_at)->isPast()])->values();
            $out['payments'] = DB::table('platform_invoice_payments')->where('agency_id', $c->agency_id)->orderByDesc('paid_on')->orderByDesc('id')->limit(100)
                ->get(['id', 'amount_cents', 'currency', 'method', 'paid_on', 'status', 'receipt_number'])
                ->map(fn ($p) => ['amount' => round($p->amount_cents / 100, 2), 'currency' => $p->currency, 'method' => $p->method,
                    'paid_on' => $p->paid_on ? substr((string) $p->paid_on, 0, 10) : null, 'status' => $p->status, 'receipt' => $p->receipt_number])->values();
        }

        return response()->json(['customer' => $out, 'statuses' => SalesCustomers::STATUSES, 'can_see_ar' => $super]);
    }

    /** The customer's agency in full: owner, address, contact, plan (2026-11-11). Null until Approve sets one up. */
    private function agencyInfo(object $c): ?array
    {
        if (! $c->agency_id) return null;
        $a = DB::table('agencies')->where('id', $c->agency_id)->first(['id', 'name', 'legal_name', 'logo_url', 'brand_logo_url', 'contact_email', 'contact_phone', 'website',
            'address_line1', 'address_line2', 'city', 'province', 'postal_code', 'country', 'timezone', 'billing_status', 'plan_code', 'plan_amount_cents', 'plan_currency',
            'billing_interval', 'billing_starts_at', 'next_invoice_at', 'tax_label', 'tax_rate_bps', 'tax_registration', 'ref_prefix', 'invoice_emails', 'created_at']);
        if (! $a) return null;
        $admins = $this->agencyAdmins((int) $a->id);
        $owner = $c->owner_user_id ? collect($admins)->firstWhere('id', (int) $c->owner_user_id) : null;
        if ($c->owner_user_id && ! $owner && ($u = \App\Models\User::withoutGlobalScopes()->find($c->owner_user_id))) {
            $owner = ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name), 'email' => $u->email, 'phone' => $u->phone];
        }
        /* NOT PICKED YET = THE FIRST ADMIN (2026-11-11, Anthony: "why does it prompt to set a owner"). Nobody had chosen
           one, so every customer asked. The admin who has held the agency longest (their earliest live agency_admin role)
           stands in, marked derived, until someone picks another; nothing is stored. */
        if (! $owner && $admins) {
            $ids = array_column($admins, 'id');
            $first = DB::table('role_assignments')->where('agency_id', $a->id)->where('role', 'agency_admin')->where('active', 1)
                ->whereIn('user_id', $ids)->orderBy('created_at')->orderBy('id')->value('user_id');
            $owner = collect($admins)->firstWhere('id', (int) ($first ?: $ids[0]));
            if ($owner) $owner['derived'] = true;
        }
        $logo = $a->brand_logo_url ?: $a->logo_url;
        if ($logo && str_starts_with($logo, '/')) $logo = 'https://api.kiddietrac.com' . $logo;
        return [
            'id' => (int) $a->id, 'name' => $a->name, 'legal_name' => $a->legal_name, 'logo_url' => $logo,
            'email' => $a->contact_email, 'phone' => $a->contact_phone, 'website' => $a->website,
            'address_line1' => $a->address_line1, 'address_line2' => $a->address_line2, 'city' => $a->city, 'province' => $a->province,
            'postal_code' => $a->postal_code, 'country' => $a->country, 'timezone' => $a->timezone,
            'billing_status' => $a->billing_status, 'plan_label' => $a->plan_code ? ucwords(str_replace(['_', '-'], ' ', $a->plan_code)) : null,
            'plan_amount' => $a->plan_amount_cents !== null ? round($a->plan_amount_cents / 100, 2) : null, 'billing_interval' => $a->billing_interval,
            'billing_starts_at' => $a->billing_starts_at ? substr((string) $a->billing_starts_at, 0, 10) : null,
            'next_invoice_at' => $a->next_invoice_at ? substr((string) $a->next_invoice_at, 0, 10) : null,
            'tax_label' => $a->tax_label, 'tax_rate' => $a->tax_rate_bps !== null ? round($a->tax_rate_bps / 100, 2) : null, 'tax_registration' => $a->tax_registration,
            'ref_prefix' => $a->ref_prefix, 'invoice_emails' => $a->invoice_emails, 'created_at' => self::iso($a->created_at),
            'centres' => DB::table('centres')->where('agency_id', $a->id)->whereNull('deleted_at')->count(),
            'owner' => $owner, 'admins' => $admins,
        ];
    }

    /** The agency's live admins, the people who can be its owner. Integration accounts (no mailbox) are not people. */
    private function agencyAdmins(int $agencyId): array
    {
        $ids = DB::table('role_assignments')->where('agency_id', $agencyId)->where('role', 'agency_admin')->where('active', 1)->pluck('user_id')->unique()->all();
        return \App\Models\User::withoutGlobalScopes()->whereIn('id', $ids)->whereNull('deleted_at')->whereNotIn('status', ['deactivated', 'suspended'])->orderBy('id')->get()
            ->filter(fn ($u) => ! \App\Listeners\SuppressAgencyMail::isIntegrationAccount($u->email))
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name), 'email' => $u->email, 'phone' => $u->phone])->values()->all();
    }

    /** PATCH /sales/customers/{id}: status, owner and notes. */
    public function update(Request $r, int $id): JsonResponse
    {
        $c = DB::table('sales_customers')->find($id);
        abort_unless($c, 404, 'Not found.');
        $d = $r->validate(['status' => 'nullable|in:' . implode(',', array_keys(SalesCustomers::STATUSES)), 'notes' => 'nullable|string|max:5000', 'owner_user_id' => 'nullable|integer']);
        $upd = ['updated_at' => now()];
        $said = [];
        if (! empty($d['status']) && $d['status'] !== $c->status) {
            $upd['status'] = $d['status'];
            $upd['cancelled_at'] = $d['status'] === 'cancelled' ? now() : null;
            $said[] = 'status ' . (SalesCustomers::STATUSES[$c->status] ?? $c->status) . ' → ' . SalesCustomers::STATUSES[$d['status']];
        }
        if (array_key_exists('notes', $d) && (string) $d['notes'] !== (string) $c->notes) {
            $upd['notes'] = $d['notes'] !== null && trim($d['notes']) !== '' ? trim($d['notes']) : null;
            $said[] = 'notes';
        }
        if (array_key_exists('owner_user_id', $d) && (int) $d['owner_user_id'] !== (int) $c->owner_user_id) {
            $pick = $d['owner_user_id'] ? collect($c->agency_id ? $this->agencyAdmins((int) $c->agency_id) : [])->firstWhere('id', (int) $d['owner_user_id']) : null;
            abort_if($d['owner_user_id'] && ! $pick, 422, 'The owner must be one of the agency\'s admins.');
            $upd['owner_user_id'] = $pick ? $pick['id'] : null;
            $said[] = 'owner ' . ($pick ? $pick['name'] : 'cleared');
        }
        DB::table('sales_customers')->where('id', $id)->update($upd);
        if ($said) {
            try {
                if ($c->lead_id) \App\Models\SalesActivity::create(['lead_id' => $c->lead_id, 'user_id' => $r->user()->id, 'type' => 'note', 'done' => true,
                    'body' => '🏆 Customer ' . $c->customer_number . ' updated: ' . implode(', ', $said)]);
            } catch (\Throwable $e) {
                report($e);
            }
            \App\Support\Audit::write(['user_id' => $r->user()->id, 'agency_id' => null, 'action' => 'sales_customer.updated', 'entity_type' => 'sales_customer', 'entity_id' => $id,
                'payload' => json_encode(['summary' => 'Updated customer ' . $c->customer_number . ': ' . implode(', ', $said)]), 'created_at' => now()]);
        }

        return $this->show($r, $id);
    }
}
