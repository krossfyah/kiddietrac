# Invoice tax + generate-time line items — agreed spec

Decided with Anthony, 18 Aug 2026. Build against this rather than re-deciding it.

## 1. Tax base — base + all line items

Tax applies to the **subtotal after lines are summed**, not to the base alone and not
per-line. The document reads:

```
Hours 10 × $25.00        $250.00
MISC — Parking            $12.50
Expense — Materials       $40.00
────────────────────────────────
Subtotal                 $302.50
HST 13%                   $39.33
────────────────────────────────
Total                    $341.83
```

## 2. Rate source — agency default, per-invoice override

- `agencies.settings->billing.tax_rate` (number) and `.tax_label` (e.g. "HST"), set in
  Settings → Billing.
- The generate dialog **pre-fills** from that and stays editable.
- Rationale: nobody retypes 13% forty times and eventually types 1.3%.

## 3. Zero-rating — a per-invoice "Apply tax" toggle

Off means tax is zero **and the PDF says "No tax applied"** rather than silently omitting
the line. Handles a small-supplier contractor or an out-of-province one without editing
agency settings.

---

## Implementation notes

**The one thing that must not be got wrong.** `PayeeInvoiceController::retotal()` is
already the single place that derives `amount` (base + lines). Tax must be computed
**inside that same function**, immediately after the line sum — never alongside it.
Two places computing a total is exactly how a header and its own document start
disagreeing, which is the bug the derived total exists to prevent.

**Schema** — `payee_invoices` gains:
- `tax_rate` decimal(5,2) nullable
- `tax_amount` decimal(10,2) nullable
- `tax_label` varchar(16) nullable
- `tax_applied` boolean default false

`amount` stays the **grand total** (subtotal + tax) so every existing reader — the
ledger, the totals-by-status query, `/auth/me/payee-invoices`, the digest — keeps
working untouched. Store the subtotal separately if it is needed for display; do not
change what `amount` means.

**Rounding** — round tax once, at the end, to 2dp. Rounding per line then summing drifts
by a cent and the PDF stops adding up.

**Generate-time lines** — the dialog collects lines *before* submit, so `store()` needs to
accept a `lines[]` array and create them in the same request. The existing
`POST /payee-invoices/{id}/lines` stays for editing afterwards.

**Editing** — already blocked at paid/void by `assertEditable()`. Changing the tax rate or
the toggle is an edit and must go through the same guard.

## Verify before calling it done

Test each of these against the live API, not by reading the code:

1. base + lines + 13% → grand total matches the worked example above to the cent
2. toggle off → `tax_amount` is 0, PDF reads "No tax applied"
3. adding a line **after** tax was applied re-derives tax (not just the subtotal)
4. removing a line does the same
5. editing the rate on an unpaid invoice re-derives; on a paid one → 422
6. a negative line (discount) reduces the taxable subtotal correctly
7. the PDF, the ledger row and `/auth/me/payee-invoices` all show the same total
