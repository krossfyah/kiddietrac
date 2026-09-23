<?php

declare(strict_types=1);

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://app.kiddietrac.com',
        'https://kiddietrac.com',
        'https://www.kiddietrac.com',
        'http://localhost:8080',
        'http://localhost:5173',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    /* EVERY REQUEST WAS TWO REQUESTS (2026-09-18).

       Anthony: "sometimes i get the Could not load: Network error - check your connection
       when using the portal - why?"

       max_age 0 tells the browser not to cache the CORS preflight for even a second, so
       EVERY call the portal makes is preceded by its own OPTIONS request. The portal sends
       Authorization and X-Active-Agency-Id, which makes every request non-simple, so there
       is no call that escapes it. Measured in this month's access log: 496,791 of the
       1.17M requests to the API are preflights.

       That doubling is what pushes the account into its hosting concurrency limit. 43,967
       requests came back 508 Resource Limit Reached this month, and 39,493 of them were
       the OPTIONS preflight for ONE endpoint, /notifications/unread-count.

       And a 508 is fatal to the caller in a way a 500 is not: it is served by the web
       server before PHP runs - all 43,967 of them are the same 288-byte error page - so
       this file never executes and the response carries no Access-Control-Allow-Origin.
       The browser then refuses to hand the response to JavaScript, fetch() REJECTS rather
       than resolving, and app.js can only report "Network error - check your connection"
       at somebody whose connection is fine.

       24 hours. The values above change about once a year; a browser holding a stale copy
       for a day is not a risk worth 496,791 requests a month. */
    'max_age' => 86400,

    'supports_credentials' => true,
];
