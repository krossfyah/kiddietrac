<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automated billing reminders — global master switch
    |--------------------------------------------------------------------------
    | Platform-wide kill switch for the billing:reminders sender. OFF by
    | default: even when an agency enables invoice/overdue reminders in its
    | Billing → Reminders settings, NOTHING is emailed to families until this
    | is turned on (set BILLING_REMINDERS_ENABLED=true in .env). This is the
    | safety gate before real parents start receiving automated mail.
    */
    'reminders_enabled' => (bool) env('BILLING_REMINDERS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Overdue-invoice late fees — global master switch
    |--------------------------------------------------------------------------
    | Gates invoices:apply-late-fees, which adds monthly interest to invoices
    | past their due date. OFF by default, and off for a specific reason: the
    | per-agency rate lives in agencies.late_fee_percent, which DEFAULTS to
    | 1.50 — so an agency that has never once thought about late fees still
    | looks configured to charge them. Nobody has opted in; they inherited a
    | column default.
    |
    | Until 2026-08-31 the scheduler called a command name that did not exist,
    | so this has never run and no invoice has ever carried a late fee. Turning
    | it on is therefore a first-time billing change affecting real families,
    | not the resumption of something they were used to. Review each agency's
    | percent, cap and grace days first, then set LATE_FEES_ENABLED=true.
    */
    'late_fees_enabled' => (bool) env('LATE_FEES_ENABLED', false),

];
