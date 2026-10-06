<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Superadmin-only outbound-mail configuration (sendmail vs Microsoft Graph).
 * The client secret is write-only + encrypted at rest; it's never returned.
 */
class MailSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $s = PlatformSettings::all();
        $expires = $s['mail.graph.secret_expires_at'] ?? null;
        $daysLeft = null;
        if ($expires) {
            try { $daysLeft = (int) round(now()->floatDiffInDays(\Illuminate\Support\Carbon::parse($expires), false)); } catch (\Throwable $e) {}
        }

        return response()->json([
            'mailer'            => $s['mail.mailer'] ?? 'sendmail',
            'from'              => $s['mail.from'] ?? config('mail.from.address'),
            'from_name'         => $s['mail.from_name'] ?? config('mail.from.name'),
            'graph' => [
                'tenant'        => $s['mail.graph.tenant'] ?? null,
                'client_id'     => $s['mail.graph.client_id'] ?? null,
                'from'          => $s['mail.graph.from'] ?? null,
                'secret_set'    => ! empty($s['mail.graph.client_secret']),
                'secret_expires_at' => $expires,
                'secret_days_left'  => $daysLeft,
            ],
            'last_test'         => $s['mail.last_test'] ?? null,
            // The Google OAuth client behind "Connect Gmail" on RSVP sender accounts.
            // The secret is write-only, like the Graph one.
            'google_gmail' => [
                'client_id'    => $s['rsvp.google.client_id'] ?? null,
                'secret_set'   => ! empty($s['rsvp.google.client_secret']),
                'redirect_uri' => \App\Support\RsvpGmail::REDIRECT_URI,
                'in_use'       => \App\Support\RsvpGmail::clientKind(),
                'last_check'   => $s['rsvp.google.last_check'] ?? null,
            ],
        ]);
    }

    /**
     * Save the Google OAuth client used by RSVP "Connect Gmail". Separate from save() so
     * it never touches the platform mailer. A blank secret keeps the stored one; an empty
     * client id with clear=1 removes both.
     */
    public function saveGoogle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id'     => ['nullable', 'string', 'max:200', 'regex:/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/i'],
            'client_secret' => 'nullable|string|max:200',
            'clear'         => 'nullable|boolean',
        ], ['client_id.regex' => 'That is not a Google OAuth client ID — it ends in .apps.googleusercontent.com.']);

        if (! empty($data['clear'])) {
            PlatformSettings::set('rsvp.google.client_id', null);
            PlatformSettings::set('rsvp.google.client_secret', null);
            PlatformSettings::set('rsvp.google.last_check', null);
            \App\Support\CriticalNotifier::send('Gmail sign-in client removed', ['RSVP "Connect Gmail" no longer has a Google client.']);

            return response()->json(['ok' => true]);
        }
        $id = trim((string) ($data['client_id'] ?? ''));
        if ($id === '') {
            return response()->json(['message' => 'Enter the Client ID.'], 422);
        }
        $haveSecret = ! empty($data['client_secret']) || PlatformSettings::getSecret('rsvp.google.client_secret');
        if (! $haveSecret) {
            return response()->json(['message' => 'Enter the Client secret.'], 422);
        }
        $changedId = $id !== (string) PlatformSettings::get('rsvp.google.client_id');
        PlatformSettings::set('rsvp.google.client_id', $id);
        if (! empty($data['client_secret'])) {
            PlatformSettings::setSecret('rsvp.google.client_secret', trim($data['client_secret']));
        }
        $check = $this->googleCheckResult();
        PlatformSettings::set('rsvp.google.last_check', json_encode($check));
        \App\Support\CriticalNotifier::send('Gmail sign-in client updated', array_values(array_filter([
            $changedId ? 'Client ID set to ' . $id : null,
            ! empty($data['client_secret']) ? 'The client secret was changed.' : null,
            'Check with Google: ' . $check['result'],
        ])));

        return response()->json(['ok' => true, 'check' => $check]);
    }

    public function checkGoogle(Request $request): JsonResponse
    {
        $check = $this->googleCheckResult();
        PlatformSettings::set('rsvp.google.last_check', json_encode($check));

        return response()->json(['check' => $check]);
    }

    /* ASK GOOGLE WHETHER THE PAIR IS REAL, without anyone signing in: exchange a code
       that cannot exist. A wrong ID or secret is refused as invalid_client; a right pair
       gets as far as the code and is refused as invalid_grant. */
    private function googleCheckResult(): array
    {
        $id = \App\Support\RsvpGmail::clientId();
        $secret = \App\Support\RsvpGmail::clientSecret();
        if (! $id || ! $secret) {
            return ['ok' => false, 'result' => 'not set', 'at' => now()->toDateTimeString()];
        }
        try {
            $r = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'code' => 'kt-credential-check', 'client_id' => $id, 'client_secret' => $secret,
                'redirect_uri' => \App\Support\RsvpGmail::REDIRECT_URI, 'grant_type' => 'authorization_code',
            ]);
            $err = (string) $r->json('error');
            if ($err === 'invalid_grant') {
                return ['ok' => true, 'result' => 'Google accepted the client ID and secret', 'at' => now()->toDateTimeString()];
            }
            $why = $err === 'invalid_client' ? 'Google does not recognise this client ID and secret pair — check both were copied exactly.' : ('Google said: ' . substr($err . ' ' . (string) $r->json('error_description'), 0, 200));

            return ['ok' => false, 'result' => $why, 'at' => now()->toDateTimeString()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'result' => 'Could not reach Google: ' . substr($e->getMessage(), 0, 160), 'at' => now()->toDateTimeString()];
        }
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mailer'            => 'required|in:sendmail,graph,failover',
            'from'              => 'nullable|email|max:160',
            'from_name'         => 'nullable|string|max:120',
            'graph_tenant'      => 'nullable|string|max:120',
            'graph_client_id'   => 'nullable|string|max:120',
            'graph_from'        => 'nullable|email|max:160',
            'graph_client_secret' => 'nullable|string|max:400',   // only when (re)setting it
            'graph_secret_expires_at' => 'nullable|date',
        ]);

        if (array_key_exists('from', $data))        PlatformSettings::set('mail.from', $data['from']);
        if (array_key_exists('from_name', $data))   PlatformSettings::set('mail.from_name', $data['from_name']);
        if (array_key_exists('graph_tenant', $data)) PlatformSettings::set('mail.graph.tenant', $data['graph_tenant']);
        if (array_key_exists('graph_client_id', $data)) PlatformSettings::set('mail.graph.client_id', $data['graph_client_id']);
        if (array_key_exists('graph_from', $data))  PlatformSettings::set('mail.graph.from', $data['graph_from']);
        if (array_key_exists('graph_secret_expires_at', $data)) PlatformSettings::set('mail.graph.secret_expires_at', $data['graph_secret_expires_at']);
        // Only overwrite the secret when a new one is actually provided.
        if (! empty($data['graph_client_secret'])) {
            PlatformSettings::setSecret('mail.graph.client_secret', $data['graph_client_secret']);
        }
        PlatformSettings::set('mail.mailer', $data['mailer']);

        // Re-apply immediately so the change is live without a redeploy.
        PlatformSettings::applyMail();

        // Business-critical change → alert the owners.
        \App\Support\CriticalNotifier::send('Email/mail settings updated', array_values(array_filter([
            'Active mailer set to: ' . $data['mailer'],
            ! empty($data['graph_client_secret']) ? 'The Microsoft Graph client secret was changed.' : null,
            ! empty($data['graph_tenant']) ? 'Graph tenant set to ' . $data['graph_tenant'] : null,
            ! empty($data['graph_client_id']) ? 'Graph client id set to ' . $data['graph_client_id'] : null,
            ! empty($data['graph_from']) ? 'Graph sender set to ' . $data['graph_from'] : null,
            ! empty($data['from']) ? 'From address set to ' . $data['from'] : null,
        ])));

        return response()->json(['ok' => true]);
    }

    /**
     * Test the current config: directly validate the Graph credentials (mint a
     * token) AND send a real test email to the given address.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => 'required|email']);
        PlatformSettings::applyMail();

        // 1) Direct Graph credential check (so the operator sees the real cause,
        //    not a failover-masked success).
        $graph = config('mail.mailers.graph', []);
        $graphResult = 'not configured';
        if (! empty($graph['tenant']) && ! empty($graph['client_id']) && ! empty($graph['client_secret'])) {
            try {
                $r = Http::asForm()->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->timeout(20)
                    ->post('https://login.microsoftonline.com/'.rawurlencode($graph['tenant']).'/oauth2/v2.0/token', [
                        'client_id' => $graph['client_id'], 'client_secret' => $graph['client_secret'],
                        'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
                    ]);
                $graphResult = $r->json('access_token') ? 'ok' : ('token error: '.substr((string) ($r->json('error_description') ?? $r->body()), 0, 200));
            } catch (\Throwable $e) {
                $graphResult = 'error: '.$e->getMessage();
            }
        }

        // 2) Actually send a test through the active mailer.
        $sendOk = false;
        $sendErr = null;
        try {
            Mail::html('<p>KiddieTrac mail test — sent '.now()->format('M j, g:i A').' via <strong>'.config('mail.default').'</strong>.</p>', function ($m) use ($data) {
                $m->to($data['to'])->subject('KiddieTrac mail test');
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
            $sendOk = true;
        } catch (\Throwable $e) {
            $sendErr = $e->getMessage();
        }

        PlatformSettings::set('mail.last_test', json_encode([
            'at' => now()->toDateTimeString(), 'to' => $data['to'],
            'graph' => $graphResult, 'sent' => $sendOk, 'error' => $sendErr,
            'mailer' => config('mail.default'),
        ]));

        return response()->json([
            'graph_credentials' => $graphResult,
            'sent'              => $sendOk,
            'via'               => config('mail.default'),
            'error'             => $sendErr,
        ]);
    }
}
