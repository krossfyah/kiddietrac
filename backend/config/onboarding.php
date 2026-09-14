<?php

return [
    // Master switch for the server-side onboarding gate (App\Http\Middleware\
    // EnsureOnboarded). When true, the API blocks all non-onboarding endpoints
    // for users whose onboarded_at is null. Disable in an emergency with
    // ONBOARDING_GATE=false in .env then `php artisan config:cache`.
    'gate' => filter_var(env('ONBOARDING_GATE', true), FILTER_VALIDATE_BOOLEAN),

    // Master switch for the password-change gate (App\Http\Middleware\
    // EnsurePasswordChanged). When true, the API blocks everything outside auth/*
    // for users whose must_change_password is set — i.e. anyone still carrying a
    // temporary password an administrator mailed them. Disable in an emergency with
    // PASSWORD_CHANGE_GATE=false in .env then `php artisan config:cache`.
    'password_gate' => filter_var(env('PASSWORD_CHANGE_GATE', true), FILTER_VALIDATE_BOOLEAN),
];
