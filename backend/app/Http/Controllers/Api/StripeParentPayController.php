<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyStripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v22p51 — Stripe recurring auto-pay for families.
 * Distinct from StripeBillingController (which handles platform↔agency
 * billing). This is family↔agency: parents save a card, monthly invoices
 * auto-charge against it.
 *
 * Endpoints used by the parent portal:
 *   POST /parent/billing/setup-intent     → SetupIntent client_secret
 *   POST /parent/billing/save-card        → mark default PM + autopay on
 *   POST /parent/billing/confirm-autopay  → the same, from the Autopay screen (screen-autopay.js)
 *   POST /parent/billing/disable-autopay  → autopay off, card forgotten
 *   POST /parent/billing/autopay          → toggle autopay
 *   GET  /parent/billing/status           → current PM + autopay state
 *   GET  /parent/billing/autopay-status   → the same per family, for the Autopay screen
 *
 * Director side:
 *   POST /invoices/{id}/charge            → manually charge a saved card
 *
 * Cron (in console.php):
 *   invoices:autopay-charge runs daily at 03:00, charges any
 *   {status:sent, autopay_enabled=1, balance_due>0} invoice.
 *
 * THE AGENCY'S OWN STRIPE (2026-11-05, App\Support\AgencyStripe). Every call here used KiddieTrac's .env key, which is
 * a placeholder, so no parent could save a card. Cards, customers and charges now live on the centre's Stripe account
 * (Finance > Payment providers), the same one Apple Pay / Google Pay already used. The Autopay screen's status, confirm
 * and disable calls (AutopayController, a Stripe Connect design that was never switched on) are answered here too.
 */
final class StripeParentPayController extends Controller
{
    /** The family's agency Stripe client, or a 503 a parent can read. */
    private function stripe(int $familyId): array
    {
        $aid = AgencyStripe::agencyForFamily($familyId);
        $client = AgencyStripe::client($aid);
        abort_unless($client, 503, AgencyStripe::NOT_SET);

        return [$client, $aid];
    }

    public function status(Request $request): JsonResponse
    {
        $family = $this->parentFamily($request);
        $client = AgencyStripe::client(AgencyStripe::agencyForFamily((int) $family->id));
        return response()->json([
            // 'guardian' when a split-bill parent's own card pays their share; 'family' otherwise.
            'scope'           => $family->scope ?? 'family',
            'autopay_enabled' => (bool) $family->autopay_enabled,
            'has_card'        => !empty($family->autopay_payment_method_id),
            'card_last4'      => $family->autopay_payment_method_id
                ? ($family->autopay_card_last4 ?? null) ?: $this->cardLast4($client, $family->autopay_payment_method_id) : null,
            'card_payments'   => (bool) $client,
        ]);
    }

    /** GET /parent/billing/autopay-status - each of the parent's families, as the Autopay screen lists them. */
    public function autopayStatus(Request $request): JsonResponse
    {
        $ids = DB::table('guardians')->where('user_id', $request->user()->id)->pluck('family_id')->map(fn ($v) => (int) $v)->unique()->all();
        $out = [];
        foreach ($ids as $fid) {
            $f = DB::table('families')->where('id', $fid)->whereNull('deleted_at')->first(['id', 'family_name']);
            if (! $f) continue;
            $row = $this->parentFamily($request, (int) $fid);
            $out[] = [
                'family_id' => (int) $f->id, 'family_name' => $f->family_name, 'scope' => $row->scope ?? 'family',
                'autopay_enabled' => (bool) $row->autopay_enabled,
                'card_last4' => $row->autopay_card_last4 ?? null, 'card_brand' => $row->autopay_card_brand ?? null,
                'card_payments' => (bool) AgencyStripe::client(AgencyStripe::agencyForFamily((int) $f->id)),
            ];
        }

        return response()->json(['families' => $out]);
    }

    public function setupIntent(Request $request): JsonResponse
    {
        $family = $this->parentFamily($request, $request->filled('family_id') ? (int) $request->input('family_id') : null);
        [$client, $aid] = $this->stripe((int) $family->id);
        $customerId = $this->customer($client, $family, (string) $request->user()->email);
        $si = $client->setupIntents->create([
            'customer' => $customerId,
            'usage'    => 'off_session',
            'payment_method_types' => ['card'],
            'metadata' => ['family_id' => (string) $family->id],
        ]);
        return response()->json([
            'client_secret' => $si->client_secret,
            'publishable_key' => AgencyStripe::publishable($aid),
        ]);
    }

    public function saveCard(Request $request): JsonResponse
    {
        $data = $request->validate(['payment_method' => 'required|string|max:120']);
        $family = $this->parentFamily($request);
        $card = $this->keepCard($request, $family, $data['payment_method']);
        return response()->json(['status' => 'saved', 'autopay_enabled' => true, 'card_last4' => $card['last4'], 'card_brand' => $card['brand']]);
    }

    /** POST /parent/billing/confirm-autopay {family_id, payment_method_id} - the Autopay screen's "Save card". */
    public function confirmAutopay(Request $request): JsonResponse
    {
        $data = $request->validate(['family_id' => 'required|integer', 'payment_method_id' => 'required|string|max:120']);
        $family = $this->parentFamily($request, (int) $data['family_id']);
        $card = $this->keepCard($request, $family, $data['payment_method_id']);
        return response()->json(['success' => true, 'card_last4' => $card['last4'], 'card_brand' => $card['brand']]);
    }

    /** POST /parent/billing/disable-autopay {family_id} - autopay off and the card forgotten here. */
    public function disableAutopay(Request $request): JsonResponse
    {
        $data = $request->validate(['family_id' => 'required|integer']);
        $family = $this->parentFamily($request, (int) $data['family_id']);
        DB::table($family->_table ?? 'families')->where('id', $family->_id ?? $family->id)->update([
            'autopay_enabled' => 0, 'autopay_payment_method_id' => null, 'autopay_card_last4' => null, 'autopay_card_brand' => null, 'updated_at' => now(),
        ]);
        \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => AgencyStripe::agencyForFamily((int) $family->id),
            'action' => 'autopay.disabled', 'entity_type' => 'family', 'entity_id' => (int) $family->id,
            'payload' => json_encode(['summary' => 'Turned off autopay' . (($family->scope ?? 'family') === 'guardian' ? ' for their share' : '')]), 'created_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function toggleAutopay(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $family = $this->parentFamily($request);
        if ($data['enabled'] && empty($family->autopay_payment_method_id)) {
            return response()->json(['error' => 'Save a card first'], 422);
        }
        DB::table($family->_table ?? 'families')->where('id', $family->_id ?? $family->id)->update([
            'autopay_enabled' => $data['enabled'] ? 1 : 0,
            'updated_at'      => now(),
        ]);
        return response()->json(['autopay_enabled' => $data['enabled']]);
    }

    /**
     * Manual charge: director presses "Charge saved card" on an invoice.
     */
    public function chargeInvoice(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404);
        /* SCOPE (2026-10-30): the route only checked the ROLE, so a director or admin of one agency could charge a saved
           card on any agency's invoice by id. Now: an active role in the invoice's agency, or platform admin. */
        $invAgency = (int) DB::table('centres')->where('id', $invoice->centre_id)->value('agency_id');
        $mayCharge = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->where(fn ($w) => $w->where('role', 'platform_admin')->orWhere(fn ($x) => $x->whereIn('role', ['agency_admin', 'centre_director'])->where('agency_id', $invAgency)))->exists();
        abort_unless($mayCharge, 403);
        $client = AgencyStripe::client($invAgency);
        abort_unless($client, 503, AgencyStripe::NOT_SET);
        abort_if(in_array($invoice->status, ['void', 'draft', 'refunded'], true), 422, 'This invoice cannot be charged (' . $invoice->status . ').');
        $family = DB::table('families')->where('id', $invoice->family_id)->first();
        abort_unless($family && $family->autopay_payment_method_id && $family->stripe_customer_id, 422, 'Family has no saved card');
        $cents = (int) round(((float) $invoice->balance_due) * 100);
        abort_unless($cents > 0, 422, 'Nothing to charge');

        try {
            /* Recorded HERE (2026-11-05): it waited for a webhook that was never connected, so a charge that went
               through left the invoice unpaid. The key stops a double click charging twice the same day. */
            $pi = $client->paymentIntents->create([
                'amount'   => $cents,
                'currency' => AgencyStripe::currency($invAgency),
                'customer' => $family->stripe_customer_id,
                'payment_method' => $family->autopay_payment_method_id,
                'off_session' => true,
                'confirm' => true,
                'description' => 'Invoice ' . ($invoice->invoice_number ?? $invoice->id),
                'metadata' => [
                    'invoice_id' => (string) $invoice->id,
                    'family_id'  => (string) $family->id,
                    'agency_id'  => (string) $invAgency,
                    'source'     => 'staff-charge',
                ],
            ], ['idempotency_key' => 'charge-' . $invoice->id . '-' . $cents . '-' . $family->autopay_payment_method_id . '-' . now()->format('Y-m-d')]);   // tz-ok: a dedupe key
            if ($pi->status === 'succeeded') {
                \App\Support\AutopayRunner::record((int) $invoice->id, (int) $family->id, (string) $pi->id, ((int) $pi->amount_received) / 100, null, 'Saved card (charged by staff)');
            }
            $after = DB::table('invoices')->where('id', $invoice->id)->first(['balance_due', 'status']);
            return response()->json(['status' => $pi->status, 'amount' => $cents, 'balance_due' => (float) ($after->balance_due ?? 0), 'invoice_status' => $after->status ?? null]);
        } catch (\Throwable $e) {
            Log::warning('Stripe charge failed', ['invoice' => $invoiceId, 'msg' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Stripe webhook — payment_intent.succeeded creates a payment row.
     */
    public function webhook(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $sig = $request->header('Stripe-Signature');
        $secret = env('STRIPE_PARENT_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET'));
        $event = null;
        try {
            $event = \Stripe\Webhook::constructEvent($body, $sig, (string) $secret);
        } catch (\Throwable $e) {
            /* AGENCY STRIPE ACCOUNTS (2026-11-03): Apple Pay / Google Pay invoice payments go to the agency's own
               Stripe (Payment providers), which signs its events with its own webhook secret. From 2026-11-05 every
               family payment does, and AgencyStripe::connectWebhook stores the secret when the keys are saved. */
            foreach (DB::table('agency_payment_providers')->where('provider', 'stripe')->where('enabled', true)->pluck('agency_id') as $aid) {
                $s = (string) (\App\Support\PaymentProviders::config((int) $aid, 'stripe')['webhook_secret'] ?? '');
                if ($s === '') continue;
                try { $event = \Stripe\Webhook::constructEvent($body, $sig, $s); break; } catch (\Throwable $e2) {}
            }
            if (! $event) return response()->json(['error' => 'sig'], 400);
        }
        if ($event->type === 'payment_intent.succeeded') {
            $pi = $event->data->object;
            $invoiceId = (int) ($pi->metadata->invoice_id ?? 0);
            if ($invoiceId && ! empty($pi->metadata->kt_wallet)) {
                // Apple Pay / Google Pay: recorded with how it was paid (dedupes with stripeConfirm()).
                \App\Http\Controllers\Api\WalletPayController::recordIntent($pi);
            } elseif ($invoiceId) {
                // Was where('stripe_pi_id') -- a column that does not exist, so the check threw
                // and no webhook payment was ever written. AutopayRunner::record dedupes on
                // stripe_payment_id (the run may already have recorded this charge).
                $amount = ((int) $pi->amount_received) / 100;
                \App\Support\AutopayRunner::record($invoiceId, isset($pi->metadata->family_id) ? (int) $pi->metadata->family_id : null, (string) $pi->id, $amount,
                    ! empty($pi->metadata->payer_guardian_id) ? (int) $pi->metadata->payer_guardian_id : null);
            }
        }
        return response()->json(['ok' => true]);
    }

    /** The family's Stripe customer on THIS agency account, made (or remade after a change of account) when missing. */
    private function customer(\Stripe\StripeClient $client, object $family, string $email): string
    {
        $id = (string) ($family->stripe_customer_id ?? '');
        if ($id !== '') {
            try { $c = $client->customers->retrieve($id); if (empty($c->deleted)) return $id; } catch (\Throwable $e) { /* not on this account */ }
        }
        $c = $client->customers->create([
            'name'  => (string) $family->family_name,
            'email' => $email,
            'metadata' => ['family_id' => (string) $family->id] + (($family->scope ?? '') === 'guardian' ? ['guardian_id' => (string) $family->_id] : []),
        ]);
        DB::table($family->_table ?? 'families')->where('id', $family->_id ?? $family->id)->update(['stripe_customer_id' => $c->id, 'updated_at' => now()]);
        $family->stripe_customer_id = $c->id;

        return $c->id;
    }

    /** Attach the card the parent just confirmed, make it the default and switch autopay on. Returns [last4, brand]. */
    private function keepCard(Request $request, object $family, string $pmId): array
    {
        [$client, $aid] = $this->stripe((int) $family->id);
        $customerId = $this->customer($client, $family, (string) $request->user()->email);
        try {
            $pm = $client->paymentMethods->retrieve($pmId);
        } catch (\Throwable $e) {
            abort(422, 'That card could not be found. Please add it again.');
        }
        if (($pm->customer ?? null) !== $customerId) {
            $client->paymentMethods->attach($pmId, ['customer' => $customerId]);
        }
        $client->customers->update($customerId, ['invoice_settings' => ['default_payment_method' => $pmId]]);
        $last4 = $pm->card->last4 ?? null; $brand = $pm->card->brand ?? null;
        DB::table($family->_table ?? 'families')->where('id', $family->_id ?? $family->id)->update([
            'autopay_payment_method_id' => $pmId, 'autopay_card_last4' => $last4, 'autopay_card_brand' => $brand,
            'autopay_enabled' => 1, 'updated_at' => now(),
        ]);
        \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $aid, 'action' => 'autopay.enabled',
            'entity_type' => 'family', 'entity_id' => (int) $family->id,
            'payload' => json_encode(['summary' => 'Saved a ' . ($brand ?: 'card') . ' ending ' . ($last4 ?: '????') . ' for autopay'
                . (($family->scope ?? 'family') === 'guardian' ? ' (their share)' : ''), 'last4' => $last4, 'brand' => $brand]), 'created_at' => now()]);

        return ['last4' => $last4, 'brand' => $brand];
    }

    /** The record whose card pays: the family's, or the guardian's own when they pay a split share. $familyId must be theirs. */
    private function parentFamily(Request $request, ?int $familyId = null)
    {
        $u = $request->user();
        $q = DB::table('guardians')->where('user_id', $u->id);
        $famId = $familyId ? ($q->where('family_id', $familyId)->exists() ? $familyId : null) : $q->value('family_id');
        abort_unless($famId, $familyId ? 403 : 404, $familyId ? 'That family is not yours.' : 'No family linked');
        $f = DB::table('families')->where('id', $famId)->first();
        abort_unless($f, 404);
        /* SPLIT BILLING (2026-10-30): a guardian who pays a share keeps their OWN card and autopay (guardians.*), and
           AutopayRunner charges just their share. The rest of this controller works on whichever record this returns. */
        $g = DB::table('guardians')->where('user_id', $u->id)->where('family_id', $famId)->first();
        $cfg = \App\Support\BillingSplit::config((int) $famId);
        if ($g && $cfg && $cfg['mode'] !== 'single' && in_array((int) $g->id, \App\Support\BillingSplit::guardiansOf($cfg), true)) {
            return (object) ['id' => $f->id, 'family_name' => $f->family_name, '_table' => 'guardians', '_id' => (int) $g->id, 'scope' => 'guardian',
                'stripe_customer_id' => $g->stripe_customer_id, 'autopay_enabled' => $g->autopay_enabled, 'autopay_payment_method_id' => $g->autopay_payment_method_id,
                'autopay_card_last4' => $g->autopay_card_last4, 'autopay_card_brand' => $g->autopay_card_brand];
        }
        return $f;
    }

    private function cardLast4(?\Stripe\StripeClient $client, string $pmId): ?string
    {
        if (! $client) return null;
        try {
            return $client->paymentMethods->retrieve($pmId)->card->last4 ?? null;
        } catch (\Throwable $e) { return null; }
    }
}
