<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ZumRails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paying, and being paid, through Zūm Rails.
 *
 * The ZumRails client has existed for a while with nothing calling it — no endpoint, no
 * screen, no way in. This is the way in: a parent puts a bank account on file and settles
 * an invoice by EFT or Interac, and an agency sends money out or asks a family for it.
 *
 * Two rules run through all of it:
 *
 *  1. NOTHING HERE MEANS PAID. Zum accepts an instruction and settles later — an Interac
 *     request is not money until the parent approves it in their own bank. Every response
 *     says "submitted", and the webhook remains the only thing that marks an invoice
 *     paid. Reporting success here is how invoices get closed for money that never came.
 *
 *  2. NO CARD DATA. Cards are on Stripe, which tokenises in the browser. Zum's Canadian
 *     card API takes a raw PAN server-side, which would put this application in PCI
 *     scope, so no route here accepts card fields at all.
 */
final class ZumPaymentController extends Controller
{
    /** GET /parent/zum/status — can I pay this way, and with what? */
    /**
     * GET /parent/zum/connect-token — a token for Zum's own onboarding SDK.
     *
     * This is how a card gets added without the number ever reaching us: the parent
     * types it into Zum's frame, and we are handed an id afterwards. The token is
     * customer-scoped and expires within the hour, so it is not a credential worth
     * guarding like a password — but it is still minted per signed-in parent.
     */
    public function connectToken(Request $request)
    {
        $user = $request->user();
        $agencyId = ZumRails::agencyOf($user->id);
        if (! $agencyId || ! ZumRails::configured($agencyId)) {
            return response()->json(['message' => 'Online payment is not set up for your centre.'], 409);
        }

        $tok = ZumRails::connectToken($agencyId, (int) $user->id);
        if (! $tok) {
            $e = ZumRails::lastError();
            Log::warning('zum connect token failed', ['user' => $user->id, 'error' => $e]);

            return response()->json([
                'message' => 'We could not start the secure payment form. Please try again shortly.',
            ], 502);
        }

        return response()->json($tok);
    }

    /**
     * POST /parent/zum/connect-complete — attach what they just added.
     *
     * The body carries the Zum user id the SDK reported. It is NOT trusted:
     * ZumRails::linkConnected() reads the record back from Zum and refuses unless the
     * email matches this account and nobody else already owns it. Without that, a
     * page could post another family's id and charge their card.
     */
    public function connectComplete(Request $request)
    {
        $data = $request->validate([
            'zum_user_id' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $agencyId = ZumRails::agencyOf($user->id);
        if (! $agencyId) {
            return response()->json(['message' => 'Online payment is not set up for your centre.'], 409);
        }

        $err = ZumRails::linkConnected($agencyId, (int) $user->id, (string) $data['zum_user_id']);
        if ($err) {
            return response()->json(['message' => $err], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Your payment method is saved.',
            'methods' => ZumRails::methodsOnFile((int) $user->id),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $agencyId = ZumRails::agencyOf($userId);
        $configured = $agencyId ? ZumRails::configured($agencyId) : false;

        if (! $configured) {
            return response()->json([
                'configured' => false,
                'methods' => [],
                'on_file' => ['bank' => false, 'card' => false],
            ]);
        }

        $onFile = ZumRails::methodsOnFile($userId);
        $mode = \App\Support\PaymentProviders::mode($agencyId, \App\Support\PaymentProviders::ZUM);

        return response()->json([
            'configured' => true,
            /* Passed through so the screen can label it. A sandbox provider on a live
               agency is a real hazard — the buttons work, the money does not move, and
               a parent believes their invoice is paid. */
            'mode' => $mode,
            'sandbox' => $mode !== 'production',
            /* Interac needs nothing on file — Zum emails or texts the request and the
               parent approves it in their own banking. EFT needs an account first. */
            'methods' => [
                /* Card first when there is one: it settles immediately, where Interac
                   waits on the parent approving it in their banking and a direct debit
                   takes two to three days. `ready` follows what Zum actually holds —
                   the same rule EFT already used for a bank account. */
                ['key' => 'card', 'label' => 'Credit or debit card', 'ready' => (bool) ($onFile['card'] ?? false)],
                ['key' => 'interac', 'label' => 'Interac e-Transfer', 'ready' => true],
                ['key' => 'eft', 'label' => 'Direct debit from your bank', 'ready' => (bool) $onFile['bank']],
            ],
            'on_file' => $onFile,
        ]);
    }

    /** POST /parent/zum/bank-account — put an account on file for EFT. */
    public function saveBankAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'institution_number' => ['required', 'string', 'max:10'],
            'transit_number' => ['required', 'string', 'max:10'],
            'account_number' => ['required', 'string', 'max:20'],
        ]);

        $error = ZumRails::saveBankAccount(
            (int) $request->user()->id,
            $data['institution_number'],
            $data['transit_number'],
            $data['account_number']
        );
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        /* Audited WITHOUT the numbers. That an account was added is worth recording;
           the account itself is not ours to keep, here or anywhere else. */
        $this->audit($request, 'zum.bank_account_saved', (int) $request->user()->id, []);

        return response()->json(['message' => 'Your bank account is on file.']);
    }

    /** POST /parent/zum/pay — settle one of my invoices. */
    public function pay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'method' => ['required', 'in:eft,interac,card'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $userId = (int) $request->user()->id;
        $invoice = $this->invoiceForPayer($userId, (int) $data['invoice_id']);
        if (! $invoice) {
            return response()->json(['message' => 'That invoice is not yours.'], 403);
        }

        $due = round((float) $invoice->balance_due, 2);
        $amount = isset($data['amount']) ? round((float) $data['amount'], 2) : $due;
        if ($amount <= 0) {
            return response()->json(['message' => 'That invoice has nothing outstanding.'], 422);
        }
        if ($amount > $due + 0.005) {
            return response()->json(['message' => 'That is more than the invoice balance.'], 422);
        }

        /* A card must actually be on file. Without this the request would reach
           chargeCard(), fail at Zum, and the parent would be told "that could not be
           submitted" when the real answer is "add a card first". */
        if ($data['method'] === 'card' && ! (ZumRails::methodsOnFile($userId)['card'] ?? false)) {
            return response()->json([
                'message' => 'There is no card on your account yet. Add one first.',
            ], 422);
        }

        return $this->submit(
            $request,
            $data['method'],
            $userId,
            $amount,
            ['invoice_id' => $invoice->id],
            'Invoice ' . ($invoice->invoice_number ?: $invoice->id)
        );
    }

    /** POST /director/zum/request — ask a family for money. */
    public function requestFromFamily(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:eft,interac'],
            'invoice_id' => ['nullable', 'integer'],
            'comment' => ['nullable', 'string', 'max:200'],
        ]);

        if (! $this->staffMayAct($request, (int) $data['user_id'])) {
            return response()->json(['message' => 'That person is not in your agency.'], 403);
        }

        return $this->submit($request, $data['method'], (int) $data['user_id'], round((float) $data['amount'], 2),
            array_filter(['invoice_id' => $data['invoice_id'] ?? null]),
            (string) ($data['comment'] ?? ''));
    }

    /** POST /director/zum/send — pay somebody out by Interac. */
    public function sendOut(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payroll_document_id' => ['nullable', 'integer'],
            'comment' => ['nullable', 'string', 'max:200'],
        ]);

        if (! $this->staffMayAct($request, (int) $data['user_id'])) {
            return response()->json(['message' => 'That person is not in your agency.'], 403);
        }

        $rowId = ZumRails::sendInterac(
            (int) $data['user_id'],
            round((float) $data['amount'], 2),
            array_filter(['payroll_document_id' => $data['payroll_document_id'] ?? null]),
            (string) ($data['comment'] ?? '')
        );

        // forStaff: a director can act on "the wallet is empty"; a parent cannot.
        return $this->result($request, $rowId, 'zum.sent', (int) $data['user_id'],
            'Sent. The money arrives when they accept the transfer.', true);
    }

    // ── shared ─────────────────────────────────────────────────────────────────

    private function submit(Request $request, string $method, int $userId, float $amount, array $links, string $comment): JsonResponse
    {
        if ($method === 'card') {
            $rowId = ZumRails::chargeCard($userId, $amount, $links, $comment);
        } elseif ($method === 'eft') {
            $rowId = ZumRails::collectEft($userId, $amount, $links, $comment);
        } else {
            $rowId = ZumRails::requestInterac($userId, $amount, $links, $comment);
        }

        $said = [
            'card' => 'Paid. Card payments are taken straight away.',
            'eft' => 'Submitted. Direct debits usually settle in two to three business days.',
            'interac' => 'Sent. You will get an Interac request to approve in your online banking.',
        ];

        return $this->result($request, $rowId, 'zum.requested', $userId,
            $said[$method] ?? $said['interac']);
    }

    private function result(Request $request, ?int $rowId, string $action, int $subjectId, string $message, bool $forStaff = false): JsonResponse
    {
        /* The provider's own sentence, for staff only. "There is not enough balance in
           wallet" is the difference between a director topping up the wallet and a
           director filing a bug against us; a parent can do nothing with it, and it
           leaks provider internals to someone outside the agency. */
        $why = static function () use ($forStaff): string {
            if (! $forStaff) {
                return '';
            }
            $e = ZumRails::lastError();
            $m = trim((string) ($e['message'] ?? ''));

            return $m === '' ? '' : ' ' . rtrim($m, '.') . '.';
        };

        if (! $rowId) {
            return response()->json([
                'message' => $forStaff
                    ? 'That could not be submitted. Nothing has been sent.' . $why()
                    : 'That could not be submitted. Nothing has been charged — please try again or tell your centre.',
            ], 422);
        }

        $row = DB::table('zum_transactions')->where('id', $rowId)->first();
        if ($row && $row->status === 'failed') {
            return response()->json([
                'message' => $forStaff
                    ? 'Your bank provider did not accept that. Nothing has been sent.' . $why()
                    : 'Your bank provider did not accept that. Nothing has been charged.',
            ], 422);
        }

        $this->audit($request, $action, $subjectId, [
            'zum_row' => $rowId,
            'amount' => $row->amount ?? null,
            'method' => $row->method ?? null,
            'direction' => $row->direction ?? null,
        ]);

        return response()->json([
            'id' => $rowId,
            // Never "paid". The webhook decides that.
            'status' => 'submitted',
            'message' => $message,
        ]);
    }

    /** An invoice belonging to a family this user is a guardian of. */
    private function invoiceForPayer(int $userId, int $invoiceId): ?object
    {
        $familyIds = DB::table('guardians')->where('user_id', $userId)->pluck('family_id');
        if ($familyIds->isEmpty()) {
            return null;
        }

        return DB::table('invoices')->where('id', $invoiceId)
            ->whereIn('family_id', $familyIds)
            ->first(['id', 'invoice_number', 'balance_due', 'status']);
    }

    /** Staff may only move money for somebody in their own agency. */
    private function staffMayAct(Request $request, int $subjectId): bool
    {
        $actor = (int) $request->user()->id;
        $mine = DB::table('role_assignments')->where('user_id', $actor)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->pluck('agency_id')->filter()->unique();
        if ($mine->isEmpty()) {
            return false;
        }
        $theirs = ZumRails::agencyOf($subjectId);

        return $theirs !== null && $mine->contains($theirs);
    }

    private function audit(Request $request, string $action, int $subjectId, array $payload): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id ?? null,
                'agency_id' => ZumRails::agencyOf($subjectId),
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => $subjectId,
                'payload' => json_encode($payload),
                'ip_address' => substr((string) $request->ip(), 0, 45),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Zum audit failed', ['action' => $action, 'e' => $e->getMessage()]);
        }
    }
}
