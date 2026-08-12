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
        ]);
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
