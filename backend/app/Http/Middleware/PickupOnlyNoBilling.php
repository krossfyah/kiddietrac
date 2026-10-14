<?php

namespace App\Http\Middleware;

use App\Support\PickupLogin;
use Closure;
use Illuminate\Http\Request;

/**
 * A pick-up login (App\Support\PickupLogin) sees the parent view MINUS BILLING (2026-10-13).
 * Hiding the Billing menu is not enough on its own — the API would still answer — so any
 * request whose path names money is refused here for those people. Matched on whole path
 * segments, so "report-cards" is not "cards" and "payroll" is not "payments".
 */
class PickupOnlyNoBilling
{
    private const MONEY = [
        'invoice', 'invoices', 'external-invoices', 'billing', 'billing-schedule', 'payment', 'payments',
        'payment-methods', 'statement', 'statements', 'receipt', 'receipts', 'tax-receipt', 'tax-receipts',
        'autopay', 'balance', 'balances', 'ledger', 'ledgers', 'account-ledgers', 'fee-plan', 'fee-plans',
        'deposit', 'deposits', 'refund', 'refunds', 'credit', 'credits', 'checkout', 'stripe', 'helcim',
        'pfcc', 'subsidy', 'subsidies', 'cards', 'card', 'pay', 'eft',
    ];

    public function handle(Request $request, Closure $next)
    {
        $segments = array_map('strtolower', explode('/', trim($request->path(), '/')));
        if (! array_intersect($segments, self::MONEY)) return $next($request);

        try {
            $user = $request->user() ?: auth('sanctum')->user();
        } catch (\Throwable $e) {
            $user = null;
        }
        if ($user && PickupLogin::isPickupOnly((int) $user->id)) {
            return response()->json(['message' => 'Your pick-up access does not include billing.'], 403);
        }

        return $next($request);
    }
}
