<?php

/*
 | Integration credentials, mirrored from .env at BOOT.
 |
 | These 38 keys were read with env() from inside controllers and services.
 | env() returns null once `php artisan config:cache` has run, so caching the
 | config silently disabled payments, push, SMS and SSO — which is why the
 | deploy routine had to forbid a standard Laravel optimisation.
 |
 | Reading them here means the values survive caching. Call sites are being
 | migrated to config('integrations.*') one integration at a time; until then
 | this file changes nothing on its own.
 */

return [
    'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
    'anthropic_model' => env('ANTHROPIC_MODEL'),
    'apple_client_id' => env('APPLE_CLIENT_ID'),
    'apple_client_secret' => env('APPLE_CLIENT_SECRET'),
    'app_env' => env('APP_ENV'),
    'app_public_url' => env('APP_PUBLIC_URL'),
    'default_admin_agency_id' => env('DEFAULT_ADMIN_AGENCY_ID'),
    'fcm_credentials' => env('FCM_CREDENTIALS'),
    'fcm_project_id' => env('FCM_PROJECT_ID'),
    'google_client_id' => env('GOOGLE_CLIENT_ID'),
    'kt_tz_normalize' => env('KT_TZ_NORMALIZE'),
    'microsoft_client_id' => env('MICROSOFT_CLIENT_ID'),
    'platform_admin_user_id' => env('PLATFORM_ADMIN_USER_ID'),
    'portal_url' => env('PORTAL_URL'),
    'qbo_client_id' => env('QBO_CLIENT_ID'),
    'qbo_client_secret' => env('QBO_CLIENT_SECRET'),
    'qbo_env' => env('QBO_ENV'),
    'qbo_redirect_uri' => env('QBO_REDIRECT_URI'),
    'security_alert_email' => env('SECURITY_ALERT_EMAIL'),
    'sms_keyword_replies' => env('SMS_KEYWORD_REPLIES'),
    'stripe_connect_refresh_url' => env('STRIPE_CONNECT_REFRESH_URL'),
    'stripe_connect_return_url' => env('STRIPE_CONNECT_RETURN_URL'),
    'stripe_currency' => env('STRIPE_CURRENCY'),
    'stripe_key' => env('STRIPE_KEY'),
    'stripe_monthly_price_id' => env('STRIPE_MONTHLY_PRICE_ID'),
    'stripe_parent_webhook_secret' => env('STRIPE_PARENT_WEBHOOK_SECRET'),
    'stripe_platform_fee_pct' => env('STRIPE_PLATFORM_FEE_PCT'),
    'stripe_publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
    'stripe_secret' => env('STRIPE_SECRET'),
    'stripe_secret_key' => env('STRIPE_SECRET_KEY'),
    'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'support_inbox' => env('SUPPORT_INBOX'),
    'twilio_from' => env('TWILIO_FROM'),
    'twilio_sid' => env('TWILIO_SID'),
    'twilio_token' => env('TWILIO_TOKEN'),
    'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
    'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
    'vapid_subject' => env('VAPID_SUBJECT'),
];
