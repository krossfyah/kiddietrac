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

];
