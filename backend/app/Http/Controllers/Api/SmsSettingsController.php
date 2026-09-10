<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use App\Support\SmsGateway;
use App\Support\Telnyx;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

/**
 * PER-AGENCY CARRIER CREDENTIALS, SET FROM THE PORTAL.
 *
 * TWO CARRIERS since 2026-09-10 -- Twilio, and Telnyx. The agency names which one
 * sends and whether the other catches a failure; App\Support\SmsGateway acts on that
 * choice. Telnyx also carries the VOICE channel, and its one API key covers both, so
 * it is asked for once here rather than twice on two screens.
 *
 * (originally, and still true of the Twilio half:)
 *
 * SMS used to read TWILIO_SID / TWILIO_TOKEN / TWILIO_FROM out of .env, which meant one
 * set of credentials for the whole platform and a shell session to change them. An agency
 * brings its own Twilio account and its own number, so the credentials belong beside the
 * agency, editable by the people who own them.
 *
 * Stored the same way the mailbox credentials are (EmailSettingsController): inside
 * agencies.settings under one key, with the secret encrypted at rest. The token is
 * WRITE-ONLY — a read says whether one is set and never what it is, so it cannot be
 * recovered through the API by anyone, including an admin of the agency.
 */
class SmsSettingsController extends Controller
{
    /** Account SID: AC + 32 hex. */
    private const SID_RE = '/^AC[0-9a-f]{32}$/i';
    /** A sender is either E.164 or a Messaging Service SID. */
    private const FROM_RE = '/^(\+[1-9]\d{7,14}|MG[0-9a-f]{32})$/i';
    /** API Key SID: SK + 32 hex. */
    private const KEY_RE = '/^SK[0-9a-f]{32}$/i';

    /* -- Telnyx shapes. Same reasoning as the Twilio ones above: a credential that
       is the wrong KIND of credential is the most common way this screen is filled
       in wrongly, and it should be caught here rather than by a refused send an
       hour later. Anthony pasted a Twilio OAuth client id into the Account SID box
       the first time, which is why that field says so out loud. */

    /** Telnyx API key: KEY + a long body. A public key pasted here is caught by this. */
    private const TELNYX_KEY_RE = '/^KEY[0-9A-Za-z_-]{20,}$/';

    /** Telnyx numbers are always E.164 -- there is no Messaging Service equivalent. */
    private const TELNYX_FROM_RE = '/^\+[1-9]\d{7,14}$/';

    /** Messaging profile id. */
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** The webhook signing key: raw Ed25519, base64 -- 32 bytes, so 44 characters. */
    private const ED25519_RE = '/^[A-Za-z0-9+\/]{42,44}={0,2}$/';

    private function resolveAgencyId(Request $request): int
    {
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($header) { $q->where('role', 'platform_admin')->orWhere('agency_id', $header); })->exists()) {
            return $header;
        }

        return (int) DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin'])
            ->value('agency_id');
    }

    /* Admins only, NOT directors. Email settings admit a centre director because a
       mailbox is a site-level thing; these credentials bill the agency for every message
       and switch on texting for all of its centres at once. */
    private function assertAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin'])
            ->exists();
        abort_unless($ok, 403, 'Agency admins only');
    }

    public static function readConfig(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];

        return (isset($settings['sms_config']) && is_array($settings['sms_config'])) ? $settings['sms_config'] : [];
    }

    private function writeConfig(int $agencyId, array $cfg): void
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $settings['sms_config'] = $cfg;
        DB::table('agencies')->where('id', $agencyId)
            ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
    }

    /** GET /admin/sms-settings */
    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $agency = DB::table('agencies')->where('id', $agencyId)
            ->select('id', 'name', 'sms_enabled', 'voice_enabled')->first();
        abort_unless($agency, 404, 'Agency not found');

        $cfg = self::readConfig($agencyId);
        $tel = Telnyx::readConfig($agencyId);
        $pref = SmsGateway::preference($agencyId);
        $settingsRaw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $top = $settingsRaw ? (json_decode((string) $settingsRaw, true) ?: []) : [];

        return response()->json([
            'agency_id'    => $agency->id,
            'agency_name'  => $agency->name,
            'sms_enabled'  => (bool) $agency->sms_enabled,
            'account_sid'  => (string) ($cfg['account_sid'] ?? ''),
            'from'         => (string) ($cfg['from'] ?? ''),
            // Neither secret ever leaves the server — only whether one is stored.
            'has_auth_token'     => ! empty($cfg['auth_token']),
            'api_key_sid'        => (string) ($cfg['api_key_sid'] ?? ''),
            'has_api_key_secret' => ! empty($cfg['api_key_secret']),
            // Both must be true before a single text can go out, and an admin who does not
            // know that spends a long time wondering why nothing sends.
            'notifications_enabled' => ($top['notifications_enabled'] ?? true) !== false,
            'inbound_webhook' => url('/api/v1/sms/inbound'),

            /* -- THE CHOICE BETWEEN THE TWO CARRIERS --
               `*_ready` is answered by the same code the SEND path uses, not by looking
               at which boxes have text in them. A screen that says "configured" while
               sending fails is worse than one that says nothing. */
            'provider' => $pref['primary'],
            'failover' => $pref['failover'],
            'twilio_ready' => SmsGateway::ready('twilio', $agencyId),
            'telnyx_ready' => SmsGateway::ready('telnyx', $agencyId),

            /* -- TELNYX --
               Neither the API key nor anything derived from it ever leaves the server;
               only whether one is stored, exactly like the Twilio token above. The public
               key is not a secret -- it is how we CHECK a signature, not how we make one --
               so it is shown back, which lets an admin confirm they pasted the right one. */
            'telnyx' => [
                'has_api_key' => ! empty($tel['api_key']),
                'public_key' => (string) ($tel['public_key'] ?? ''),
                'sms_from' => (string) ($tel['sms_from'] ?? ''),
                'messaging_profile_id' => (string) ($tel['messaging_profile_id'] ?? ''),
                'voice_connection_id' => (string) ($tel['voice_connection_id'] ?? ''),
                'voice_from' => (string) ($tel['voice_from'] ?? ''),
                'voice_caller_name' => (string) ($tel['voice_caller_name'] ?? ''),
                'voice_voice' => (string) ($tel['voice_voice'] ?? ''),
                'voice_language' => (string) ($tel['voice_language'] ?? ''),
            ],

            /* -- VOICE --
               Its own master switch, off until somebody turns it on. Credentials arriving
               on this screen must not be enough on their own to start ringing parents. */
            'voice_enabled' => (bool) ($agency->voice_enabled ?? false),
            'voice_ready' => Telnyx::voiceConfig($agencyId) !== null,

            /* Per-agency webhook addresses. The agency is in the PATH because a webhook
               does not otherwise say whose Telnyx account it came from, and the signing
               key has to be chosen before anything in the body can be trusted. */
            'telnyx_inbound_webhook' => url('/api/v1/sms/telnyx/inbound/' . $agencyId),
            'telnyx_voice_webhook' => url('/api/v1/voice/telnyx/webhook/' . $agencyId),

            // Sends per carrier over the last 30 days -- the first question anybody has
            // after switching carrier is whether the new one is actually working.
            'recent_by_provider' => SmsGateway::recentByProvider($agencyId),

            /* THE TEST LOG. A connection test that only flashes a line of green text
               answers "is it working NOW" and nothing else. What an admin actually
               needs, halfway through pasting four credentials, is "what did it say the
               last three times, and has it ever passed" -- so every attempt is kept and
               handed back with the screen. */
            'recent_tests' => self::recentTests($agencyId),
        ]);
    }

    /**
     * The last few connection tests for this agency, newest first.
     *
     * Read back out of the audit log rather than from a table of its own: a credential
     * test is exactly the kind of administrative act the audit log exists for, it is
     * already agency-scoped and already retained, and a second store would be a second
     * thing to purge.
     */
    private static function recentTests(int $agencyId, int $limit = 12): array
    {
        $rows = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->where('al.agency_id', $agencyId)
            ->where('al.action', 'sms.settings.test')
            ->orderByDesc('al.created_at')->orderByDesc('al.id')
            ->limit($limit)
            ->get(['al.payload', 'al.created_at', 'u.first_name', 'u.last_name']);

        $out = [];
        foreach ($rows as $r) {
            $p = json_decode((string) $r->payload, true) ?: [];
            $out[] = [
                'at' => $r->created_at,
                'by' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'system',
                'provider' => $p['provider'] ?? '',
                'ok' => (bool) ($p['ok'] ?? false),
                'message' => $p['message'] ?? '',
                'checks' => $p['checks'] ?? [],
                'ms' => $p['ms'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Record one test attempt, and hand the caller back exactly what the screen renders.
     *
     * Auditing is wrapped because a failure to write history must never be the reason a
     * test appears to fail -- the same rule the settings save follows.
     */
    private function recordTest(Request $request, int $agencyId, string $provider, bool $ok, string $message, array $checks, float $ms): array
    {
        try {
            \App\Support\Audit::write([
                'user_id' => optional($request->user())->id,
                'agency_id' => $agencyId,
                'action' => 'sms.settings.test',
                'entity_type' => 'agency',
                'entity_id' => $agencyId,
                'payload' => json_encode([
                    'summary' => 'Tested ' . $provider . ' credentials — ' . ($ok ? 'passed' : 'failed')
                        . ($message !== '' ? ': ' . $message : ''),
                    'provider' => $provider,
                    'ok' => $ok,
                    'message' => $message,
                    'checks' => $checks,
                    'ms' => (int) round($ms),
                ]),
            ]);
        } catch (\Throwable $e) {
            // History is worth having, not worth failing a test over.
        }

        return [
            'ok' => $ok,
            'provider' => $provider,
            'message' => $message,
            'checks' => $checks,
            'ms' => (int) round($ms),
            'recent_tests' => self::recentTests($agencyId),
        ];
    }

    /** PATCH /admin/sms-settings */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 404, 'No agency in context');

        $data = $request->validate([
            'account_sid'    => ['nullable', 'string', 'regex:' . self::SID_RE],
            'auth_token'     => ['nullable', 'string', 'min:24', 'max:200'],
            'api_key_sid'    => ['nullable', 'string', 'regex:' . self::KEY_RE],
            'api_key_secret' => ['nullable', 'string', 'min:16', 'max:200'],
            'from'           => ['nullable', 'string', 'regex:' . self::FROM_RE],
            'sms_enabled'    => ['nullable', 'boolean'],

            // Which carrier sends, and whether the other one catches a refusal.
            'provider'       => ['nullable', 'string', 'in:twilio,telnyx'],
            'failover'       => ['nullable', 'boolean'],

            // Telnyx. One API key covers messaging and voice.
            'telnyx_api_key'              => ['nullable', 'string', 'regex:' . self::TELNYX_KEY_RE],
            'telnyx_public_key'           => ['nullable', 'string', 'regex:' . self::ED25519_RE],
            'telnyx_sms_from'             => ['nullable', 'string', 'regex:' . self::TELNYX_FROM_RE],
            'telnyx_messaging_profile_id' => ['nullable', 'string', 'regex:' . self::UUID_RE],
            'telnyx_voice_connection_id'  => ['nullable', 'string', 'regex:/^[0-9a-zA-Z-]{6,64}$/'],
            'telnyx_voice_from'           => ['nullable', 'string', 'regex:' . self::TELNYX_FROM_RE],
            'telnyx_voice_caller_name'    => ['nullable', 'string', 'max:128'],
            'telnyx_voice_voice'          => ['nullable', 'string', 'max:80'],
            'telnyx_voice_language'       => ['nullable', 'string', 'max:12'],
            'voice_enabled'               => ['nullable', 'boolean'],
        ], [
            'api_key_sid.regex' => 'An API Key SID looks like SK followed by 32 characters.',
            'account_sid.regex' => 'An Account SID looks like AC followed by 32 characters. An OAuth client id (OQ…) will not work here.',
            'from.regex'        => 'Use the number in full international form (+16475550123) or a Messaging Service SID (MG…).',
            'auth_token.min'    => 'That token looks too short to be a Twilio auth token.',
            'telnyx_api_key.regex' => 'A Telnyx API key starts with KEY. The public key is a different '
                . 'credential and belongs in the webhook field below.',
            'telnyx_public_key.regex' => 'The webhook public key is the base64 string from Mission Control '
                . '→ Keys & Credentials → Public Key. It is 44 characters and does not start with KEY.',
            'telnyx_sms_from.regex' => 'Use the number in full international form (+16475550123).',
            'telnyx_voice_from.regex' => 'Use the number in full international form (+16475550123).',
            'telnyx_messaging_profile_id.regex' => 'A messaging profile id is a UUID.',
        ]);

        $cfg = self::readConfig($agencyId);

        if ($request->exists('account_sid')) { $cfg['account_sid'] = trim((string) $data['account_sid']); }
        if ($request->exists('from'))        { $cfg['from'] = trim((string) $data['from']); }
        if ($request->exists('api_key_sid')) { $cfg['api_key_sid'] = trim((string) $data['api_key_sid']); }

        if (! empty($data['api_key_secret'])) {
            $cfg['api_key_secret'] = Crypt::encryptString($data['api_key_secret']);
        }
        if ($request->boolean('clear_api_key')) {
            unset($cfg['api_key_sid'], $cfg['api_key_secret']);
        }

        /* Blank means "leave the stored one alone", so saving the form after changing only
           the number does not wipe the token. Clearing is explicit, via the flag below. */
        if (! empty($data['auth_token'])) {
            $cfg['auth_token'] = Crypt::encryptString($data['auth_token']);
        }
        if ($request->boolean('clear_auth_token')) {
            unset($cfg['auth_token']);
        }

        /* WHICH CARRIER, and whether the other one catches a failure. Stored beside the
           Twilio credentials rather than in the Telnyx block, because it is a decision
           ABOUT the pair and belongs with neither one of them. */
        if ($request->exists('provider')) {
            $cfg['provider'] = in_array($data['provider'] ?? '', SmsGateway::PROVIDERS, true)
                ? $data['provider'] : 'twilio';
        }
        if ($request->exists('failover')) {
            $cfg['failover'] = $request->boolean('failover');
        }

        $this->writeConfig($agencyId, $cfg);

        /* -- TELNYX --
           Same write-only rule as the Twilio token: blank leaves the stored key alone,
           so correcting a typo in the caller name cannot wipe the credential, and
           clearing is explicit. */
        $tel = Telnyx::readConfig($agencyId);

        if (! empty($data['telnyx_api_key'])) {
            $tel['api_key'] = Crypt::encryptString($data['telnyx_api_key']);
        }
        if ($request->boolean('clear_telnyx_api_key')) {
            unset($tel['api_key']);
        }

        foreach ([
            'telnyx_public_key' => 'public_key',
            'telnyx_sms_from' => 'sms_from',
            'telnyx_messaging_profile_id' => 'messaging_profile_id',
            'telnyx_voice_connection_id' => 'voice_connection_id',
            'telnyx_voice_from' => 'voice_from',
            'telnyx_voice_caller_name' => 'voice_caller_name',
            'telnyx_voice_voice' => 'voice_voice',
            'telnyx_voice_language' => 'voice_language',
        ] as $field => $key) {
            // exists(), not empty(): these are not secrets, so an emptied box means
            // "remove this", which is the only way to unset a wrong number.
            if ($request->exists($field)) {
                $tel[$key] = trim((string) ($data[$field] ?? ''));
            }
        }

        Telnyx::writeConfig($agencyId, $tel);

        /* THE VOICE MASTER SWITCH CANNOT BE TURNED ON BLIND. Everything else on this
           screen is a credential; this one starts phones ringing. Refusing it while the
           configuration is incomplete means the switch never sits ON over a setup that
           would place no calls -- which is the state that has people concluding voice is
           broken when it was never actually on. */
        if ($request->exists('voice_enabled')) {
            $wantVoice = $request->boolean('voice_enabled');
            if ($wantVoice && Telnyx::voiceConfig($agencyId) === null) {
                return response()->json([
                    'message' => 'Voice calls need a Telnyx API key, a Call Control connection id and '
                        . 'a caller number before they can be switched on.',
                    'errors' => ['voice_enabled' => ['Telnyx voice is not fully configured yet.']],
                ], 422);
            }
            DB::table('agencies')->where('id', $agencyId)->update(['voice_enabled' => $wantVoice ? 1 : 0]);
        }

        if ($request->exists('sms_enabled')) {
            DB::table('agencies')->where('id', $agencyId)
                ->update(['sms_enabled' => $request->boolean('sms_enabled') ? 1 : 0]);
        }

        /* Never the token, not even its length. An audit row is read by more people than
           the screen is. */
        try {
            \App\Support\Audit::write([
                'user_id'     => optional($request->user())->id,
                'agency_id'   => $agencyId,
                'action'      => 'sms.settings.updated',
                'entity_type' => 'agency',
                'entity_id'   => $agencyId,
                'payload'     => json_encode([
                    'summary' => 'Updated SMS and voice settings — sending on '
                        . ($cfg['provider'] ?? 'twilio')
                        . (($cfg['from'] ?? '') ? ' (Twilio sender ' . $cfg['from'] . ')' : ''),
                    'account_sid' => $cfg['account_sid'] ?? '',
                    'from'        => $cfg['from'] ?? '',
                    'token_set'   => ! empty($cfg['auth_token']),
                    'api_key_sid' => $cfg['api_key_sid'] ?? '',
                    'api_key_set' => ! empty($cfg['api_key_secret']),
                    'sms_enabled' => $request->exists('sms_enabled') ? $request->boolean('sms_enabled') : null,
                    'provider' => $cfg['provider'] ?? 'twilio',
                    'failover' => ($cfg['failover'] ?? true) !== false,
                    'telnyx_key_set' => ! empty($tel['api_key']),
                    'telnyx_sms_from' => $tel['sms_from'] ?? '',
                    'telnyx_voice_from' => $tel['voice_from'] ?? '',
                    'voice_enabled' => $request->exists('voice_enabled') ? $request->boolean('voice_enabled') : null,
                ]),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never be the reason a save fails.
        }

        return $this->show($request);
    }

    /**
     * POST /admin/sms-settings/test
     *
     * Checks a carrier's credentials by READING its account, not by sending anything.
     * A test that texts somebody needs a real consenting recipient and costs money to
     * discover a typo; reading the account proves the credentials are accepted, which is
     * the thing that is actually being asked.
     *
     * Which carrier is checked comes from the request, defaulting to the one the agency
     * has chosen to send on. Checking the wrong one is exactly the sort of test that
     * passes while sending fails.
     *
     * ── ALWAYS ANSWERS 200 ──
     * Even when the credentials are refused. This is a DIAGNOSTIC: the interesting
     * payload is the list of which checks passed and which did not, and a 4xx throws
     * that away at every HTTP client that treats non-2xx as "no body worth reading".
     * `ok` carries the verdict.
     */
    public function test(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $provider = strtolower(trim((string) $request->input('provider', '')));
        if (! in_array($provider, SmsGateway::PROVIDERS, true)) {
            $provider = SmsGateway::preference($agencyId)['primary'];
        }

        $t0 = microtime(true);
        [$ok, $message, $checks] = $provider === 'telnyx'
            ? $this->probeTelnyx($agencyId)
            : $this->probeTwilio($agencyId);
        $ms = (microtime(true) - $t0) * 1000;

        return response()->json(
            $this->recordTest($request, $agencyId, $provider, $ok, $message, $checks, $ms)
        );
    }

    /** One line of the report. */
    private static function check(string $label, bool $ok, string $detail): array
    {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * @return array{0:bool,1:string,2:array}
     */
    private function probeTwilio(int $agencyId): array
    {
        $saved = self::readConfig($agencyId);
        $checks = [];

        $sidOk = (bool) preg_match(self::SID_RE, (string) ($saved['account_sid'] ?? ''));
        $checks[] = self::check('Account SID', $sidOk,
            $sidOk ? 'Looks like an Account SID.' : 'Missing, or not in the AC… form.');

        $hasKey = ! empty($saved['api_key_sid']) && ! empty($saved['api_key_secret']);
        $hasTok = ! empty($saved['auth_token']);
        $checks[] = self::check('Credential', $hasKey || $hasTok,
            $hasKey ? 'API key stored — used in preference to the auth token.'
                : ($hasTok ? 'Auth token stored.' : 'Neither an API key nor an auth token is stored.'));

        $fromOk = (bool) preg_match(self::FROM_RE, (string) ($saved['from'] ?? ''));
        $checks[] = self::check('Sending number', $fromOk,
            $fromOk ? (string) $saved['from'] : 'Missing, or not E.164 / a Messaging Service SID.');

        /* Resolved by the SAME code the send path uses. A test that builds its own
           credentials can pass while sending still fails, which is worse than no test. */
        $cfg = SmsController::twilioConfig($agencyId);
        if (! $cfg) {
            $checks[] = self::check('Twilio account', false, 'Not attempted — fill in the fields above first.');

            return [false, 'Twilio is not fully configured yet.', $checks];
        }

        try {
            $account = (new Client($cfg['user'], $cfg['pass'], $cfg['account']))
                ->api->v2010->accounts($cfg['account'])->fetch();
            $checks[] = self::check('Twilio account', true,
                '"' . $account->friendlyName . '" (' . $account->status . ')');

            return [true, 'Connected to Twilio as "' . $account->friendlyName . '".', $checks];
        } catch (\Throwable $e) {
            Log::warning('Twilio credential check failed', ['agency' => $agencyId, 'msg' => $e->getMessage()]);
            // Twilio's own wording is the useful part -- it distinguishes a bad token
            // from a suspended account.
            $checks[] = self::check('Twilio account', false, $e->getMessage());

            return [false, 'Twilio refused those credentials.', $checks];
        }
    }

    /**
     * Reads the Telnyx account balance -- the cheapest endpoint that requires a valid
     * key and proves the account is live rather than merely that the key parses.
     *
     * Reports on the VOICE side too, because one key covers both and an admin who has
     * filled in the messaging half has no other way to find out that the call control
     * connection id is still missing.
     *
     * @return array{0:bool,1:string,2:array}
     */
    private function probeTelnyx(int $agencyId): array
    {
        $checks = [];
        $key = Telnyx::apiKey($agencyId);

        if ($key === '') {
            $checks[] = self::check('API key', false, 'No API key stored.');

            return [false, 'Telnyx still needs an API key.', $checks];
        }

        $r = Telnyx::whoami($key);
        if (! $r['ok']) {
            Log::warning('Telnyx credential check failed', ['agency' => $agencyId, 'msg' => $r['error']]);
            $checks[] = self::check('API key', false, (string) $r['error']);

            return [false, 'Telnyx refused that API key.', $checks];
        }

        $bal = $r['body']['data'] ?? [];
        $checks[] = self::check('API key', true, isset($bal['balance'])
            ? ('Accepted. Balance ' . $bal['balance'] . ' ' . (string) ($bal['currency'] ?? '') . '.')
            : 'Accepted.');

        /* Named individually rather than as one "not configured", because "which box is
           still empty" is the actual question and the screen cannot answer it: the API
           key is write-only, so it cannot tell whether a failure is the key or the
           number. */
        $sms = Telnyx::smsConfig($agencyId);
        $checks[] = self::check('Text messages', $sms !== null, $sms
            ? ($sms['from'] !== '' ? ('Sending from ' . $sms['from'] . '.') : 'Sending via the messaging profile.')
            : 'Needs a sending number or a messaging profile id.');

        $voice = Telnyx::voiceConfig($agencyId);
        $checks[] = self::check('Voice calls', $voice !== null, $voice
            ? ('Calling from ' . $voice['from'] . ' on connection ' . $voice['connection_id'] . '.')
            : 'Needs a Call Control connection id and a caller number.');

        $pub = Telnyx::publicKey($agencyId) !== '';
        $checks[] = self::check('Webhook signing key', $pub, $pub
            ? 'Stored — replies and call events will be verified.'
            : 'Missing. Without it every inbound reply and call event is refused.');

        /* The API key alone is a pass: it is the credential being tested. The rest are
           reported so the gaps are visible, but a missing voice connection is not a
           reason to call a working messaging setup broken. */
        $gaps = count(array_filter($checks, fn ($c) => ! $c['ok']));

        return [
            true,
            $gaps === 0 ? 'Connected to Telnyx. Everything is set up.'
                : ('Connected to Telnyx. ' . $gaps . ' thing' . ($gaps === 1 ? '' : 's') . ' still to finish.'),
            $checks,
        ];
    }
}
