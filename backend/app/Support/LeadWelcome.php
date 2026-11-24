<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * A new lead's welcome (2026-10-22): onto the subscriber list, then the welcome email
 * (App\Mail\LeadWelcomeMail), ONCE per address. Called from every place a lead arrives
 * with an email: the website's lead forms, the contact form, and website chat.
 *
 * Someone who unsubscribed is never quietly put back: only a form they filled in
 * themselves ($optedIn) re-subscribes them, exactly as the newsletter form always has.
 * Nothing here may ever fail the request that called it.
 */
final class LeadWelcome
{
    public static function send(string $email, string $name = '', string $source = '', string $agency = '', bool $optedIn = false, ?string $ip = null): bool
    {
        try {
            $email = strtolower(trim($email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! Schema::hasTable('site_subscribers')) {
                return false;
            }
            $row = DB::table('site_subscribers')->where('email', $email)->first();
            if ($row && ! empty($row->unsubscribed_at) && ! $optedIn) {
                return false;                                   // they said no; respect it
            }

            // Auto-subscribe (Anthony: "auto subscribe them to our subscribers list as well").
            $now = now();
            if (! $row) {
                DB::table('site_subscribers')->insert([
                    'email' => $email, 'name' => $name ?: null, 'agency_name' => $agency ?: null,
                    'source' => $source ?: 'lead', 'ip' => $ip ? substr($ip, 0, 45) : null,
                    'subscribed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } elseif (! empty($row->unsubscribed_at) && $optedIn) {
                DB::table('site_subscribers')->where('id', $row->id)->update([
                    'subscribed_at' => $now, 'unsubscribed_at' => null, 'unsubscribed_by' => null,
                    'unsubscribe_note' => null, 'updated_at' => $now,
                ]);
            }

            // Once per address. Claimed before sending so two requests cannot both send.
            if (Schema::hasColumn('site_subscribers', 'lead_welcome_sent_at')) {
                $claimed = DB::table('site_subscribers')->where('email', $email)->whereNull('lead_welcome_sent_at')
                    ->update(['lead_welcome_sent_at' => $now]);
                if (! $claimed) {
                    return false;
                }
            }

            // Queued: a chat line or a form must not wait on the mail server.
            Mail::to($email)->queue((new \App\Mail\LeadWelcomeMail($name ?: 'there', self::unsubscribeUrl($email), $source, $optedIn))->onQueue('mail'));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Lead welcome failed', ['email' => $email, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * ONTO THE SUBSCRIBER LIST ONLY (2026-10-23, Anthony: "add leads to subscribers list as
     * well"): every lead with an email, however it was made - typed in Sales, merged, from a
     * voicemail or call. No welcome email (a lead typed in by hand never asked for one), and
     * somebody who unsubscribed stays unsubscribed. True when a row was added.
     */
    public static function subscribe(string $email, string $name = '', string $source = 'lead', string $agency = ''): bool
    {
        try {
            $email = strtolower(trim($email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/@example\./', $email) || ! Schema::hasTable('site_subscribers')) {
                return false;
            }
            if (DB::table('site_subscribers')->where('email', $email)->exists()) {
                return false;                                   // already there, or said no
            }
            $now = now();
            DB::table('site_subscribers')->insert([
                'email' => $email, 'name' => $name ?: null, 'agency_name' => $agency ?: null, 'source' => $source ?: 'lead',
                'subscribed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Lead subscribe failed', ['email' => $email, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Every open lead with an email that is not on the list yet (kt:leads-subscribe). */
    public static function subscribeAllLeads(): int
    {
        $n = 0;
        foreach (DB::table('sales_leads')->whereNull('deleted_at')->whereNotNull('email')->where('email', '!=', '')
            ->get(['email', 'name', 'company']) as $l) {
            if (self::subscribe((string) $l->email, (string) $l->name, 'lead', (string) $l->company)) $n++;
        }
        return $n;
    }

    /** The same signed link MarketingSiteController's unsubscribe page checks. */
    public static function unsubscribeUrl(string $email): string
    {
        $e = strtolower(trim($email));
        $sig = hash_hmac('sha256', $e, (string) config('app.key'));

        return rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/')
            . '/api/v1/marketing-site/unsubscribe?e=' . urlencode($e) . '&t=' . $sig;
    }
}
