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
];
