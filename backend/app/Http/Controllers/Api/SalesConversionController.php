<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesLead;
use App\Models\SalesQuote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * APPROVE A PROPOSAL, AND THE PROSPECT BECOMES A CUSTOMER (2026-10-23, Anthony: "wire leads to
 * have function to approve the proposals manually or reject and if approved move this
 * prospect to a real customer with setting them up in the system with billing and type of
 * license based on the proposal and all of the commercials ... a contract will need to be
 * signed and uploaded/attached before anything can be moved forward").
 *
 * Anthony's choices (question prompt):
 *  - The contract: the proposal e-signed by both sides counts, OR a signed contract uploaded
 *    here. Without one, Approve refuses.
 *  - Billing: the customer is set up with the plan, price, interval and tax from the proposal,
 *    but NOT billed yet (next_invoice_at stays empty) until billing is switched on in
 *    Platform → Invoices → Billing plans.
 *  - Licence: worked out from the proposal's catalogue lines (Starter/Growth/Agency →
 *    starter/growth/enterprise, Whitelabel → white-label, SMS Bundle → texting on, Reseller →
 *    also a Reseller account) and shown for confirmation before converting.
 *  - The welcome invite is held: "Send welcome invite" sends it when Anthony chooses.
 *
 * Agency creation reuses PlatformController::createAgency (same validation, same records),
 * with kt_hold_invite so it sends nothing.
 */
class SalesConversionController extends Controller
{
    private function quote(int $id): SalesQuote
    {
        return SalesQuote::with('items')->findOrFail($id);
    }

    private function contractOk(SalesQuote $q): bool
    {
        return ($q->client_signed_at && $q->countersigned_at) || $q->contract_path;
    }

    private function log(SalesLead $lead, string $type, string $body): void
    {
        try { app(SalesController::class)->logExternal($lead, $type, $body); } catch (\Throwable $e) {}
    }

    /* ── The signed contract ──────────────────────────────────────── */

    /** POST /sales/quotes/{quote}/contract (multipart: file) */
    public function uploadContract(Request $r, int $quote): JsonResponse
    {
        $q = $this->quote($quote);
        abort_if($q->converted_agency_id, 422, 'This proposal has already been converted; the contract is locked.');
        $r->validate(['file' => ['required', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,heic,doc,docx']]);
        $f = $r->file('file');
        $name = preg_replace('/[\x00-\x1F"\\\\\/]+/', '_', (string) $f->getClientOriginalName()) ?: 'contract.pdf';
        $path = 'sales-contracts/' . $q->id . '/' . Str::random(32) . '.' . strtolower($f->getClientOriginalExtension() ?: 'pdf');
        Storage::disk('local')->put($path, file_get_contents($f->getRealPath()));
        if ($q->contract_path && $q->contract_path !== $path) Storage::disk('local')->delete($q->contract_path);
        $q->forceFill(['contract_path' => $path, 'contract_name' => mb_substr($name, 0, 200), 'contract_size' => (int) $f->getSize(),
            'contract_uploaded_at' => now(), 'contract_uploaded_by' => $r->user()->id])->save();
        if ($lead = SalesLead::find($q->lead_id)) $this->log($lead, 'note', '📎 Signed contract attached to ' . ($q->number ?: 'the proposal') . ': ' . $name);
        \App\Support\Audit::write(['user_id' => $r->user()->id, 'action' => 'sales.contract_uploaded', 'entity_type' => 'sales_quote', 'entity_id' => $q->id,
            'payload' => json_encode(['summary' => 'Attached the signed contract to ' . $q->number . ' (' . $name . ')']), 'created_at' => now()]);
        return response()->json(['ok' => true, 'contract_name' => $q->contract_name, 'contract_uploaded_at' => now()->toIso8601String()]);
    }

    /** GET /sales/quotes/{quote}/contract */
    public function downloadContract(int $quote)
    {
        $q = $this->quote($quote);
        abort_unless($q->contract_path && Storage::disk('local')->exists($q->contract_path), 404, 'No contract attached.');
        return \App\Support\FileResponse::download($q->contract_path, $q->contract_name ?: 'contract.pdf');
    }

    /** DELETE /sales/quotes/{quote}/contract — only before conversion. */
    public function removeContract(Request $r, int $quote): JsonResponse
    {
        $q = $this->quote($quote);
        abort_if($q->converted_agency_id, 422, 'This proposal has already been converted; the contract is locked.');
        if ($q->contract_path) Storage::disk('local')->delete($q->contract_path);
        $name = $q->contract_name;
        $q->forceFill(['contract_path' => null, 'contract_name' => null, 'contract_size' => null, 'contract_uploaded_at' => null, 'contract_uploaded_by' => null])->save();
        if ($lead = SalesLead::find($q->lead_id)) $this->log($lead, 'note', '🗑 Removed the contract from ' . ($q->number ?: 'the proposal') . ($name ? ': ' . $name : ''));
        return response()->json(['ok' => true]);
    }

    /* ── What the proposal means for the customer ─────────────────── */

    /** The licence and commercials the proposal implies. */
    private function terms(SalesQuote $q): array
    {
        $products = DB::table('sales_products')->whereIn('id', $q->items->pluck('product_id')->filter()->unique())->get()->keyBy('id');
        $plan = null; $white = false; $sms = false; $reseller = false;
        $recurring = 0.0; $oneTime = []; $lines = [];
        foreach ($q->items as $it) {
            $p = $it->product_id ? $products->get($it->product_id) : null;
            $n = mb_strtolower(($p->name ?? '') . ' ' . $it->description);
            $unit = $p->unit ?? null;
            if (preg_match('/reseller/', $n)) { $reseller = true; }
            elseif (preg_match('/white\s*-?\s*label/', $n)) { $white = true; }
            elseif (preg_match('/\bsms\b|text bundle|texting/', $n)) { $sms = true; }
            if (! preg_match('/reseller/', $n)) {
                if (preg_match('/\bagency\b|enterprise/', $n)) $plan = 'enterprise';
                elseif (preg_match('/growth/', $n) && $plan !== 'enterprise') $plan = 'growth';
                elseif (preg_match('/starter/', $n) && ! $plan) $plan = 'starter';
            }
            $isOnce = $unit === 'one-time' || (! $unit && preg_match('/setup|set-up|onboarding|one[- ]time|training|import/', $n));
            $amt = (float) $it->line_total;
            if ($isOnce) $oneTime[] = ['description' => $it->description, 'amount' => round($amt, 2)];
            else $recurring += $amt;
            $lines[] = ['description' => $it->description, 'qty' => (float) $it->qty, 'unit_price' => (float) $it->unit_price, 'line_total' => round($amt, 2), 'one_time' => (bool) $isOnce];
        }
        if (! $plan) $plan = $reseller ? 'enterprise' : 'starter';
        // The quote's discount is one amount over the whole subtotal: the recurring part carries its share.
        $sub = (float) $q->subtotal; $disc = (float) $q->discount;
        $recurringNet = $sub > 0 ? max(0, $recurring - $disc * ($recurring / $sub)) : $recurring;
        $taxes = is_array($q->taxes) ? $q->taxes : (json_decode((string) $q->taxes, true) ?: []);
        $rate = 0.0; $labels = [];
        foreach ($taxes as $t) { $rate += (float) ($t['rate'] ?? 0); if (! empty($t['label'])) $labels[] = $t['label']; }
        $cur = strtoupper((string) ($q->currency ?: 'CAD'));
        return [
            'plan_code' => $plan, 'white_label' => $white, 'sms' => $sms, 'reseller' => $reseller,
            'plan_amount' => round($recurringNet, 2), 'billing_interval' => $q->billing_period === 'year' ? 'annual' : 'monthly',
            'currency' => in_array($cur, ['CAD', 'USD'], true) ? $cur : 'CAD', 'currency_note' => in_array($cur, ['CAD', 'USD'], true) ? null : 'The proposal is in ' . $cur . '; platform billing runs in CAD or USD.',
            'tax_rate_bps' => (int) round($rate * 100), 'tax_label' => $labels ? implode(' + ', $labels) : null,
            'one_time' => $oneTime, 'discount' => $disc, 'lines' => $lines,
        ];
    }

    private static function country(?string $c): string
    {
        $c = trim((string) $c);
        if (strlen($c) === 2) return strtoupper($c);
        $map = ['canada' => 'CA', 'united states' => 'US', 'usa' => 'US', 'us' => 'US', 'united kingdom' => 'GB', 'uk' => 'GB', 'australia' => 'AU', 'new zealand' => 'NZ', 'ireland' => 'IE'];
        return $map[mb_strtolower($c)] ?? 'CA';
    }

    /** GET /sales/quotes/{quote}/conversion — the checks, the licence, and the customer's details pre-filled. */
    public function preview(int $quote): JsonResponse
    {
        $q = $this->quote($quote);
        $lead = SalesLead::findOrFail($q->lead_id);
        $parts = preg_split('/\s+/', trim((string) $lead->name), 2);
        $tz = ['BC' => 'America/Vancouver', 'AB' => 'America/Edmonton', 'SK' => 'America/Regina', 'MB' => 'America/Winnipeg', 'NS' => 'America/Halifax', 'NB' => 'America/Moncton', 'NL' => 'America/St_Johns', 'PE' => 'America/Halifax'];
        $prov = strtoupper(trim((string) $lead->province));
        return response()->json([
            'quote' => ['id' => $q->id, 'number' => $q->number, 'total' => (float) $q->total, 'currency' => $q->currency ?: 'CAD', 'billing_period' => $q->billing_period,
                'status' => $q->status, 'approval_status' => $q->approval_status, 'converted_agency_id' => $q->converted_agency_id],
            'checks' => [
                'signed_online' => (bool) ($q->client_signed_at && $q->countersigned_at),
                'client_signed' => (bool) $q->client_signed_at,
                'contract' => $q->contract_path ? ['name' => $q->contract_name, 'uploaded_at' => $q->contract_uploaded_at ? \Illuminate\Support\Carbon::parse($q->contract_uploaded_at)->toIso8601String() : null] : null,
                'contract_ok' => $this->contractOk($q),
                'already_converted' => (bool) ($q->converted_agency_id || $lead->converted_agency_id),
                'declined' => $q->status === 'declined',
            ],
            'terms' => $this->terms($q),
            'customer' => [
                'name' => $lead->company ?: $lead->name, 'contact_email' => $lead->email, 'contact_phone' => $lead->phone,
                'address_line1' => $lead->address, 'city' => $lead->city, 'province' => $lead->province, 'postal_code' => $lead->postal_code,
                'country' => self::country($lead->country), 'timezone' => $tz[$prov] ?? 'America/Toronto', 'default_locale' => 'en',
                'admin_first_name' => $parts[0] ?? '', 'admin_last_name' => $parts[1] ?? '', 'admin_email' => $lead->email, 'admin_phone' => $lead->phone,
                'admin_email_taken' => $lead->email ? DB::table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower($lead->email)])->exists() : false,
            ],
        ]);
    }

    /* ── Reject ───────────────────────────────────────────────────── */

    /** POST /sales/quotes/{quote}/reject {reason} */
    public function reject(Request $r, int $quote): JsonResponse
    {
        $q = $this->quote($quote);
        abort_if($q->converted_agency_id, 422, 'This proposal has already been converted.');
        $reason = trim((string) $r->validate(['reason' => ['required', 'string', 'max:500']])['reason']);
        $q->forceFill(['approval_status' => 'rejected', 'rejected_at' => now(), 'rejected_by' => $r->user()->id, 'reject_reason' => $reason,
            'status' => 'declined', 'declined_at' => $q->declined_at ?: now(), 'decline_reason' => $q->decline_reason ?: ('Not approved: ' . $reason),
            'followup_next_at' => null])->save();
        if ($lead = SalesLead::find($q->lead_id)) $this->log($lead, 'note', '✖ ' . ($q->number ?: 'Proposal') . ' was not approved: ' . $reason);
        \App\Support\Audit::write(['user_id' => $r->user()->id, 'action' => 'sales.proposal_rejected', 'entity_type' => 'sales_quote', 'entity_id' => $q->id,
            'payload' => json_encode(['summary' => 'Did not approve ' . $q->number . ': ' . $reason]), 'created_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /* ── Approve and convert ──────────────────────────────────────── */

    /** POST /sales/quotes/{quote}/approve {customer fields…, plan_code, plan_amount, billing_interval, currency, white_label, sms, reseller} */
    public function approve(Request $r, int $quote): JsonResponse
    {
        $q = $this->quote($quote);
        $lead = SalesLead::findOrFail($q->lead_id);
        abort_if($q->converted_agency_id || $lead->converted_agency_id, 422, 'This prospect has already been set up as a customer.');
        abort_if($q->status === 'declined', 422, 'This proposal was declined or not approved.');
        if (! $this->contractOk($q)) {
            return response()->json(['message' => 'Attach the signed contract first (or have the proposal signed online by both sides).'], 422);
        }
        $c = $r->validate([
            'plan_code' => ['required', 'in:starter,growth,enterprise'],
            'plan_amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'billing_interval' => ['required', 'in:weekly,biweekly,monthly,quarterly,annual'],
            'currency' => ['required', 'in:CAD,USD'],
            'white_label' => ['boolean'], 'sms' => ['boolean'], 'reseller' => ['boolean'],
            'tax_rate_bps' => ['nullable', 'integer', 'min:0', 'max:5000'], 'tax_label' => ['nullable', 'string', 'max:40'],
        ]);

        // The agency itself: PlatformController::createAgency validates and provisions; nothing is emailed.
        $fields = ['name', 'contact_email', 'contact_phone', 'timezone', 'address_line1', 'address_line2', 'city', 'province', 'postal_code', 'country',
            'default_locale', 'admin_first_name', 'admin_last_name', 'admin_email', 'admin_phone'];
        $sub = Request::create('/api/v1/platform/agencies', 'POST', array_merge($r->only($fields), [
            'plan_code' => $c['plan_code'], 'plan_amount_cents' => (int) round($c['plan_amount'] * 100),
        ]));
        $sub->setUserResolver(fn () => $r->user());
        $sub->attributes->set('kt_hold_invite', true);
        $res = app(PlatformController::class)->createAgency($sub);
        $made = $res->getData(true);
        $agencyId = (int) ($made['agency']['id'] ?? 0);
        abort_unless($agencyId, 500, 'The customer could not be set up.');

        $terms = $this->terms($q);
        $settings = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $settings['commercial'] = ['source' => 'sales_quote', 'quote_id' => $q->id, 'quote_number' => $q->number, 'lead_id' => $lead->id,
            'approved_at' => now()->toIso8601String(), 'approved_by' => $r->user()->id, 'lines' => $terms['lines'], 'one_time' => $terms['one_time'],
            'discount' => $terms['discount'], 'proposal_total' => (float) $q->total, 'proposal_currency' => $q->currency ?: 'CAD', 'billing_period' => $q->billing_period];
        DB::table('agencies')->where('id', $agencyId)->update([
            // Set up, not billed (Anthony's choice): no trial clock, no next invoice until billing is switched on.
            'billing_status' => 'active', 'trial_ends_at' => null, 'next_invoice_at' => null,
            'billing_interval' => $c['billing_interval'], 'plan_currency' => $c['currency'], 'plan_amount_cents' => (int) round($c['plan_amount'] * 100),
            'tax_rate_bps' => $c['tax_rate_bps'] ?? $terms['tax_rate_bps'], 'tax_label' => $c['tax_label'] ?? $terms['tax_label'],
            'powered_by_visible' => ! empty($c['white_label']) ? 0 : 1, 'sms_enabled' => ! empty($c['sms']) ? 1 : 0,
            'legal_name' => $r->input('name'), 'settings' => json_encode($settings), 'updated_at' => now(),
        ]);

        // A reseller: their own reseller account, owning this agency, with the admin as its reseller admin.
        $resellerNote = null;
        if (! empty($c['reseller'])) {
            try {
                $rc = app(ResellerController::class);
                $s1 = Request::create('/', 'POST', ['name' => $r->input('name'), 'slug' => Str::slug($r->input('name')) . '-reseller']); $s1->setUserResolver(fn () => $r->user());
                $rid = (int) ($rc->store($s1)->getData(true)['reseller']['id'] ?? 0);
                if ($rid) {
                    $s2 = Request::create('/', 'PUT', ['agency_ids' => [$agencyId]]); $s2->setUserResolver(fn () => $r->user()); $rc->setAgencies($s2, $rid);
                    $s3 = Request::create('/', 'POST', ['email' => $r->input('admin_email')]); $s3->setUserResolver(fn () => $r->user()); $rc->addAdmin($s3, $rid);
                    $resellerNote = 'reseller account #' . $rid;
                }
            } catch (\Throwable $e) {
                $resellerNote = 'reseller account NOT created (' . $e->getMessage() . ') — set it up under Platform → Resellers';
            }
        }

        /* LICENCES FROM THE PROPOSAL (2026-10-24, App\Support\Licences). Each plan / add-on line
           becomes a licence (part number, price after the proposal's discount, the chosen billing
           term, auto-renew). A choice ticked on the approval screen with no line of its own (a plan
           level, white-label, texting, reseller) is licensed from the catalogue at no charge, so
           the licence matches what was approved. */
        $licNote = '';
        try {
            $prods = DB::table('sales_products')->whereIn('id', $q->items->pluck('product_id')->filter()->unique())->get()->keyBy('id');
            $recur = 0.0;
            foreach ($q->items as $it) { $pp = $it->product_id ? $prods->get($it->product_id) : null; if ($pp && in_array($pp->kind, ['plan', 'addon'], true)) $recur += (float) $it->line_total; }
            $disc = (float) $q->discount; $sub = (float) $q->subtotal;
            $factor = ($recur > 0 && $sub > 0 && $disc > 0) ? max(0, 1 - ($disc * ($recur / $sub)) / $recur) : 1;
            $granted = []; $haveFeat = []; $havePlan = false;
            foreach ($q->items as $it) {
                $pp = $it->product_id ? $prods->get($it->product_id) : null;
                if (! $pp || ! in_array($pp->kind, ['plan', 'addon'], true)) continue;
                $qty = max(1, (float) $it->qty);
                \App\Support\Licences::grant($agencyId, (int) $pp->id, ['qty' => $qty, 'unit_price' => round((float) $it->unit_price * $factor, 2),
                    'interval' => $c['billing_interval'], 'currency' => $c['currency'], 'source' => 'quote', 'quote_id' => $q->id]);
                $granted[] = $pp->part_number;
                if ($pp->kind === 'plan') $havePlan = true;
                foreach ((array) (json_decode((string) $pp->feature_keys, true) ?: []) as $fk) $haveFeat[$fk] = true;
            }
            $extra = [];
            if (! $havePlan) $extra[] = DB::table('sales_products')->where('kind', 'plan')->where('plan_code', $c['plan_code'])->where('active', 1)->orderBy('id')->first();
            foreach (['white_label' => ! empty($c['white_label']), 'sms' => ! empty($c['sms']), 'reseller' => ! empty($c['reseller'])] as $fk => $on) {
                if (! $on || isset($haveFeat[$fk])) continue;
                $extra[] = DB::table('sales_products')->where('kind', 'addon')->where('feature_keys', 'like', '%"' . $fk . '"%')->orderBy('id')->first();
            }
            foreach (array_filter($extra) as $pp) {
                \App\Support\Licences::grant($agencyId, (int) $pp->id, ['unit_price' => $pp->kind === 'plan' && ! $havePlan ? (float) $c['plan_amount'] : 0,
                    'interval' => $c['billing_interval'], 'currency' => $c['currency'], 'source' => 'quote', 'quote_id' => $q->id, 'notes' => 'Included on approval of ' . $q->number]);
                $granted[] = $pp->part_number;
            }
            \App\Support\Licences::sync($agencyId);
            $licNote = $granted ? ' Licences: ' . implode(', ', array_filter($granted)) . '.' : '';
        } catch (\Throwable $e) {
            $licNote = ' Licences NOT created (' . $e->getMessage() . ') — add them under Platform → Licences.';
        }

        $q->forceFill(['approval_status' => 'approved', 'approved_at' => now(), 'approved_by' => $r->user()->id, 'status' => 'accepted',
            'converted_agency_id' => $agencyId, 'followup_next_at' => null])->save();
        $lead->forceFill(['stage' => 'won', 'status' => 'won', 'converted_agency_id' => $agencyId, 'converted_at' => now()])->save();

        $money = number_format((float) $c['plan_amount'], 2) . ' ' . $c['currency'] . ' ' . ($c['billing_interval'] === 'annual' ? 'a year' : ($c['billing_interval'] === 'monthly' ? 'a month' : $c['billing_interval']));
        $lic = ucfirst($c['plan_code']) . (! empty($c['white_label']) ? ' · white-label' : '') . (! empty($c['sms']) ? ' · texting' : '') . (! empty($c['reseller']) ? ' · reseller' : '');
        $this->log($lead, 'stage', '✅ ' . ($q->number ?: 'Proposal') . ' approved — set up as a customer: ' . $r->input('name') . ' (' . $lic . ', ' . $money . ').'
            . ' Billing not started; welcome invite not sent yet.' . ($resellerNote ? ' Reseller: ' . $resellerNote . '.' : '') . $licNote);
        \App\Support\Audit::write(['user_id' => $r->user()->id, 'action' => 'sales.proposal_approved', 'entity_type' => 'agency', 'entity_id' => $agencyId,
            'payload' => json_encode(['summary' => 'Approved ' . $q->number . ' and set up ' . $r->input('name') . ' as a customer (' . $lic . ', ' . $money . ')',
                'quote_id' => $q->id, 'lead_id' => $lead->id, 'reseller' => $resellerNote]), 'created_at' => now()]);

        return response()->json(['ok' => true, 'agency_id' => $agencyId, 'admin_user_id' => $made['admin_user_id'] ?? null, 'reseller' => $resellerNote], 201);
    }

    /** POST /sales/quotes/{quote}/send-invite — the held welcome email, when Anthony chooses. */
    public function sendInvite(Request $r, int $quote): JsonResponse
    {
        $q = $this->quote($quote);
        abort_unless($q->converted_agency_id, 422, 'Approve and set up the customer first.');
        $res = app(PlatformController::class)->resendInvite($r, (int) $q->converted_agency_id);
        if ($res->getStatusCode() >= 400) return $res;
        $q->forceFill(['invite_sent_at' => now()])->save();
        if ($lead = SalesLead::find($q->lead_id)) $this->log($lead, 'email', '📨 Welcome invite sent to the new customer’s admin.');
        return response()->json(['ok' => true, 'invite_sent_at' => now()->toIso8601String()]);
    }
}
