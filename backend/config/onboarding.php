<?php

return [
    // Master switch for the server-side onboarding gate (App\Http\Middleware\
    // EnsureOnboarded). When true, the API blocks all non-onboarding endpoints
    // for users whose onboarded_at is null. Disable in an emergency with
    // ONBOARDING_GATE=false in .env then `php artisan config:cache`.
    'gate' => filter_var(env('ONBOARDING_GATE', true), FILTER_VALIDATE_BOOLEAN),
];
