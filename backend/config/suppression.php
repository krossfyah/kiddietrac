<?php

// Email/notification suppression config. Read via config() (NOT env() directly)
// so the values survive `php artisan config:cache` — env() returns null outside
// config files once config is cached, which silently disabled the kill-switch.
return [
    // Comma-separated agency ids whose outbound comms are suppressed while testing.
    // Used in DENYLIST mode (see 'mode' below).
    'agencies'  => env('MAIL_SUPPRESS_AGENCIES', ''),
    // Comma-separated addresses exempt from the .env switch (the tester's inbox).
    'allowlist' => env('MAIL_SUPPRESS_ALLOWLIST', ''),

    // 'denylist'  — suppress exactly the agencies in 'agencies' (legacy default).
    // 'allowlist' — suppress ALL agencies EXCEPT those in 'allow_agencies', so a
    //               newly-created agency is OFF by default until explicitly allowed.
    'mode'           => env('MAIL_SUPPRESS_MODE', 'denylist'),
    // Comma-separated agency ids permitted to send, in allowlist mode.
    'allow_agencies' => env('MAIL_ALLOW_AGENCIES', ''),

    /* Missed-message chat emails. The 2026-08-20 flood (one email per recipient per
       CONVERSATION — 39 into one inbox from a single run) was fixed by grouping into one
       digest per person, keyed by email address. This comment said "stays OFF" while the
       default here was already true, which is exactly the kind of disagreement that gets
       trusted instead of checked — so: this is the master kill switch, it is ON, and
       CHAT_EMAILS_ENABLED=false in .env turns the whole feature off without a deploy. */
    'chat_emails_enabled' => env('CHAT_EMAILS_ENABLED', true),
];
