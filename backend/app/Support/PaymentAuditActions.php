<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Which audit actions belong to a payment PROVIDER rather than to the portal.
 *
 * The Payment integrations tab and the main audit log are two halves of one split:
 * whatever this class claims is shown there and hidden here. So the definition lives
 * in exactly one place and both sides call it — write the list twice and a row either
 * shows up in both tabs or falls down the gap between them, and a missing payment
 * event is not the kind of thing anybody notices until they need it.
 *
 * Deliberately NARROW. Generating an invoice, editing a fee plan or voiding a platform
 * bill are billing OPERATIONS the portal performed itself; they stay in the main log
 * where an admin looks for "what did we do". This tab is for the conversation with
 * Zūm Rails and Stripe — money instructed, settled, refused, and the cards and bank
 * accounts that back it.
 */
final class PaymentAuditActions
{
    /**
     * SQL LIKE patterns, matched against audit_logs.action.
     *
     * Kept as patterns rather than an exact list because most of these rows are route
     * audits ("post:api/v1/parent/zum/pay"), and a new endpoint under an existing path
     * should be classified correctly the day it ships rather than the day somebody
     * remembers to add it here.
     */
    public const PATTERNS = [
        // ── Zūm Rails ────────────────────────────────────────────────
        'zum.%',                              // settled / failed / requested / sent
        '%zumrails%',                         // provider settings, webhook
        '%/zum/%',                            // /parent/zum/*, /director/zum/*

        // ── Stripe ───────────────────────────────────────────────────
        'stripe.%',
        '%stripe%',
        '%/billing/setup-intent%',            // adding a card
        '%/billing/save-card%',
        '%/billing/ach-%',                    // bank debit setup
        '%/billing/autopay%',
        '%/wallet%',                          // saved payment methods
        '%/charge%',                          // charging a saved method

        // ── provider configuration, either of them ───────────────────
        '%payment-providers%',
    ];

    /** Restrict a query to payment-provider rows. */
    public static function only(Builder $q, string $column = 'action'): Builder
    {
        return $q->where(function ($x) use ($column) {
            foreach (self::PATTERNS as $p) {
                $x->orWhere($column, 'like', $p);
            }
        });
    }

    /** Exclude payment-provider rows — the main audit log. */
    public static function except(Builder $q, string $column = 'action'): Builder
    {
        return $q->where(function ($x) use ($column) {
            foreach (self::PATTERNS as $p) {
                $x->where($column, 'not like', $p);
            }
        });
    }

    /** Same test in PHP, for anything not going through the database. */
    public static function matches(?string $action): bool
    {
        $a = (string) $action;
        foreach (self::PATTERNS as $p) {
            $regex = '/^' . str_replace('%', '.*', preg_quote($p, '/')) . '$/i';
            if (preg_match($regex, $a)) {
                return true;
            }
        }

        return false;
    }
}
