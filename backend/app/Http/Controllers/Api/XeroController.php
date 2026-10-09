<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\XeroClient;
use App\Support\XeroException;
use App\Support\XeroSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * XERO — Settings → Xero (2026-10-09). Same shape as QuickBooks: the agency admin enters its
 * own Xero app's client id and secret, connects, then maps accounts. Beyond QuickBooks: the
 * OAuth state is verified server-side (one use, 15 minutes), tokens are encrypted, contacts
 * and invoices are linked so re-syncs update instead of duplicating, payments and refunds
 * follow, and an optional schedule keeps it in step (xero:sync, every 30 minutes).
 */
final class XeroController extends Controller
{
    /* ───────────── access ───────────── */

    private function resolveAgencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId && DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
                ->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        if (DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    private function agencyAdmin(Request $request): int
    {
        $agencyId = $this->resolveAgencyId($request);
        $uid = (int) $request->user()->id;
        $ok = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where(function ($w) use ($agencyId) {
                $w->where('role', 'platform_admin')->orWhere(function ($a) use ($agencyId) { $a->where('role', 'agency_admin')->where('agency_id', $agencyId); });
            })->exists();
        abort_unless($ok, 403, 'Xero settings are for agency admins.');
        return $agencyId;
    }

    private function audit(Request $request, int $agencyId, string $action, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'agency',
                'entity_id' => $agencyId, 'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private function fail(XeroException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    /* ───────────── config ───────────── */

    /** GET /admin/xero-config — everything the settings screen shows. */
    public function showConfig(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = XeroClient::row($agencyId);
        $connected = XeroClient::connected($r);
        $linked = $connected ? DB::table('xero_links')->where(['agency_id' => $agencyId, 'tenant_id' => $r->tenant_id])
            ->selectRaw('kind, COUNT(*) n')->groupBy('kind')->pluck('n', 'kind') : collect();
        return response()->json([
            'redirect_uri' => XeroClient::redirectUri(),
            'client_id' => $r->client_id ?? '',
            'has_secret' => ! empty($r->client_secret),
            'scope_mode' => $r->scope_mode ?? 'granular',
            'configured' => ! empty($r->client_id) && ! empty($r->client_secret),
            'connected' => $connected,
            'authorised' => $r && ! empty($r->refresh_token),
            'tenant_id' => $r->tenant_id ?? null,
            'tenant_name' => $r->tenant_name ?? null,
            'tenants' => $r && $r->tenants_json ? json_decode($r->tenants_json, true) : [],
            'connected_at' => $r->connected_at ?? null,
            'connected_by' => $r && $r->connected_by_id ? trim((string) DB::table('users')->where('id', $r->connected_by_id)->value(DB::raw("CONCAT_WS(' ', first_name, last_name)"))) : null,
            'revenue_account_code' => $r->revenue_account_code ?? null,
            'subsidy_account_code' => $r->subsidy_account_code ?? null,
            'payment_account_code' => $r->payment_account_code ?? null,
            'tax_type' => $r->tax_type ?? null,
            'invoice_status' => $r->invoice_status ?? 'AUTHORISED',
            'sync_from' => $r->sync_from ?? null,
            'auto_sync' => (bool) ($r->auto_sync ?? false),
            'sync_payments' => (bool) ($r->sync_payments ?? true),
            'last_sync_at' => $r->last_sync_at ?? null,
            'last_error' => $r->last_error ?? null,
            'linked' => ['contacts' => (int) ($linked['contact'] ?? 0), 'invoices' => (int) ($linked['invoice'] ?? 0), 'payments' => (int) ($linked['payment'] ?? 0)],
            'pending' => $connected ? count(XeroSync::pendingIds($agencyId)) : 0,
        ]);
    }

    /** PATCH /admin/xero-config — credentials and/or mapping; only the fields sent change. */
    public function saveConfig(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $d = $request->validate([
            'client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scope_mode' => ['sometimes', 'in:granular,classic'],
            'revenue_account_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'subsidy_account_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'payment_account_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'tax_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'invoice_status' => ['sometimes', 'in:AUTHORISED,DRAFT'],
            'sync_from' => ['sometimes', 'nullable', 'date'],
            'auto_sync' => ['sometimes', 'boolean'],
            'sync_payments' => ['sometimes', 'boolean'],
        ]);
        $r = XeroClient::row($agencyId);
        $u = ['updated_at' => now()];
        foreach (['scope_mode', 'revenue_account_code', 'subsidy_account_code', 'payment_account_code', 'tax_type', 'invoice_status', 'sync_from'] as $k) {
            if (array_key_exists($k, $d)) $u[$k] = ($d[$k] === '' ? null : $d[$k]);
        }
        foreach (['auto_sync', 'sync_payments'] as $k) { if (array_key_exists($k, $d)) $u[$k] = (bool) $d[$k]; }
        $credsChanged = false;
        if (array_key_exists('client_id', $d)) {
            $new = trim((string) $d['client_id']) ?: null;
            if ($new !== ($r->client_id ?? null)) { $u['client_id'] = $new; $credsChanged = true; }
        }
        if (! empty($d['client_secret'])) { $u['client_secret'] = XeroClient::enc(trim($d['client_secret'])); $credsChanged = true; }
        if ($credsChanged && $r && ! empty($r->refresh_token)) {
            // A different app cannot use the old app's tokens: end that connection cleanly.
            XeroClient::revoke($r);
            $u += ['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'tenant_id' => null, 'tenant_name' => null, 'tenants_json' => null];
        }
        if ($r) DB::table('agency_xero')->where('agency_id', $agencyId)->update($u);
        else DB::table('agency_xero')->insert(array_merge(['agency_id' => $agencyId, 'created_at' => now()], $u));
        $this->audit($request, $agencyId, 'xero.settings_saved', 'Xero settings saved' . ($credsChanged ? ' (app credentials changed)' : '') . ': ' . implode(', ', array_diff(array_keys($u), ['updated_at', 'client_secret', 'access_token', 'refresh_token'])));
        return response()->json(['ok' => true]);
    }

    /** DELETE /admin/xero-config — disconnect and forget the app credentials. Links stay (history). */
    public function clearConfig(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = XeroClient::row($agencyId);
        if ($r) {
            XeroClient::revoke($r);
            DB::table('agency_xero')->where('agency_id', $agencyId)->update(['client_id' => null, 'client_secret' => null, 'access_token' => null, 'refresh_token' => null,
                'token_expires_at' => null, 'tenant_id' => null, 'tenant_name' => null, 'tenants_json' => null, 'auto_sync' => false, 'updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'xero.cleared', 'Xero app credentials cleared and Xero disconnected');
        return response()->json(['ok' => true]);
    }

    /* ───────────── OAuth ───────────── */

    /** GET /xero/connect — the Xero consent URL. */
    public function connect(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = XeroClient::row($agencyId);
        if (! $r || empty($r->client_id) || empty($r->client_secret)) return response()->json(['message' => 'Save your Xero app\'s client ID and secret first.'], 422);
        $state = bin2hex(random_bytes(24));
        Cache::put('xero-state:' . $state, ['agency_id' => $agencyId, 'user_id' => (int) $request->user()->id], now()->addMinutes(15));
        return response()->json(['authorize_url' => 'https://login.xero.com/identity/connect/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $r->client_id,
            'redirect_uri' => XeroClient::redirectUri(),
            'scope' => XeroClient::SCOPES[$r->scope_mode ?? 'granular'] ?? XeroClient::SCOPES['granular'],
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986)]);
    }

    private function page(bool $ok, string $title, string $text)
    {
        $e = fn ($s) => htmlspecialchars($s, ENT_QUOTES);
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Xero</title></head>'
            . '<body style="font-family:system-ui,sans-serif;padding:40px 20px;text-align:center;color:#0F172A;background:#F8FAFC;">'
            . '<div style="max-width:460px;margin:0 auto;background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:28px;">'
            . '<div style="font-size:40px;">' . ($ok ? '✅' : '⚠️') . '</div><h2 style="margin:8px 0;">' . $e($title) . '</h2>'
            . '<p style="color:#475569;line-height:1.5;">' . $e($text) . '</p>'
            . '<p style="color:#94A3B8;font-size:13px;">You can close this window and return to KiddieTrac.</p></div>'
            . '<script>try{window.opener&&window.opener.postMessage({kt:"xero",ok:' . ($ok ? 'true' : 'false') . '},"*")}catch(e){}' . ($ok ? 'setTimeout(function(){window.close()},2500)' : '') . '</script></body></html>';
        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** GET /xero/callback — public; the one-use state proves who started it. */
    public function callback(Request $request)
    {
        $st = Cache::pull('xero-state:' . (string) $request->query('state'));
        if (! $st) return $this->page(false, 'Link expired', 'This Xero sign-in link has expired or was already used. Start again from Settings → Xero.');
        $agencyId = (int) $st['agency_id'];
        if ($err = $request->query('error')) {
            $hint = $err === 'invalid_scope'
                ? 'Your Xero app did not accept the requested permissions. In Settings → Xero, switch "Xero app type" to the other option and connect again.'
                : ($err === 'access_denied' ? 'The connection was cancelled in Xero.' : 'Xero said: ' . $err . ($request->query('error_description') ? ' — ' . $request->query('error_description') : ''));
            return $this->page(false, 'Xero was not connected', $hint);
        }
        $r = XeroClient::row($agencyId);
        if (! $r || ! $request->query('code')) return $this->page(false, 'Xero was not connected', 'Something was missing from Xero\'s reply. Please try again.');
        try {
            $tok = XeroClient::tokenRequest($r, ['grant_type' => 'authorization_code', 'code' => (string) $request->query('code'), 'redirect_uri' => XeroClient::redirectUri()]);
            $orgs = XeroClient::connections((string) $tok['access_token']);
        } catch (XeroException $e) {
            return $this->page(false, 'Xero was not connected', $e->getMessage());
        }
        if (! $orgs) return $this->page(false, 'No organisation chosen', 'Xero did not give access to any organisation. Connect again and pick your organisation in Xero.');
        $prev = $r->tenant_id;
        $keep = $prev && collect($orgs)->firstWhere('id', $prev) ? $prev : null;
        $pick = $keep ?: (count($orgs) === 1 ? $orgs[0]['id'] : null);
        DB::table('agency_xero')->where('agency_id', $agencyId)->update([
            'access_token' => XeroClient::enc($tok['access_token']),
            'refresh_token' => XeroClient::enc($tok['refresh_token'] ?? null),
            'token_expires_at' => now()->addSeconds((int) ($tok['expires_in'] ?? 1800)),
            'tenants_json' => json_encode($orgs),
            'tenant_id' => $pick,
            'tenant_name' => $pick ? collect($orgs)->firstWhere('id', $pick)['name'] : null,
            'connected_by_id' => $st['user_id'], 'connected_at' => now(), 'last_error' => null, 'updated_at' => now(),
        ]);
        try {
            \App\Support\Audit::write(['user_id' => $st['user_id'], 'agency_id' => $agencyId, 'action' => 'xero.connected', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Xero connected' . ($pick ? ' to ' . collect($orgs)->firstWhere('id', $pick)['name'] : ' (' . count($orgs) . ' organisations — choose one)')]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
        return $pick
            ? $this->page(true, 'Xero connected', 'KiddieTrac is connected to ' . collect($orgs)->firstWhere('id', $pick)['name'] . '. Finish choosing your accounts in Settings → Xero.')
            : $this->page(true, 'Choose your organisation', 'You gave access to ' . count($orgs) . ' Xero organisations. Pick the one to use in Settings → Xero.');
    }

    /** POST /xero/tenant { tenant_id } — pick the organisation when several were authorised. */
    public function chooseTenant(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $id = (string) $request->input('tenant_id');
        $r = XeroClient::row($agencyId);
        $org = $r && $r->tenants_json ? collect(json_decode($r->tenants_json, true))->firstWhere('id', $id) : null;
        abort_unless($org, 422, 'That organisation was not part of this connection.');
        DB::table('agency_xero')->where('agency_id', $agencyId)->update(['tenant_id' => $id, 'tenant_name' => $org['name'], 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'xero.organisation_chosen', 'Xero organisation set to ' . $org['name']);
        return response()->json(['ok' => true]);
    }

    /** POST /xero/disconnect */
    public function disconnect(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = XeroClient::row($agencyId);
        if ($r) {
            XeroClient::revoke($r);
            DB::table('agency_xero')->where('agency_id', $agencyId)->update(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null,
                'tenant_id' => null, 'tenant_name' => null, 'tenants_json' => null, 'updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'xero.disconnected', 'Xero disconnected' . ($r && $r->tenant_name ? ' from ' . $r->tenant_name : ''));
        return response()->json(['ok' => true]);
    }

    /* ───────────── mapping options ───────────── */

    /** GET /xero/options — accounts and tax rates from the connected organisation. */
    public function options(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        try {
            $org = XeroClient::call($agencyId, 'GET', 'Organisation')['Organisations'][0] ?? [];
            $accts = XeroClient::call($agencyId, 'GET', 'Accounts')['Accounts'] ?? [];
            $rates = XeroClient::call($agencyId, 'GET', 'TaxRates')['TaxRates'] ?? [];
        } catch (XeroException $e) {
            return $this->fail($e);
        }
        $active = array_values(array_filter($accts, fn ($a) => ($a['Status'] ?? '') === 'ACTIVE' && ! empty($a['Code'])));
        $pick = fn ($a) => ['code' => $a['Code'], 'name' => $a['Name'], 'type' => $a['Type'] ?? ''];
        return response()->json([
            'organisation' => ['name' => $org['Name'] ?? null, 'currency' => $org['BaseCurrency'] ?? null, 'country' => $org['CountryCode'] ?? null],
            'revenue' => array_map($pick, array_values(array_filter($active, fn ($a) => ($a['Class'] ?? '') === 'REVENUE'))),
            'bank' => array_map($pick, array_values(array_filter($active, fn ($a) => ($a['Type'] ?? '') === 'BANK' || ! empty($a['EnablePaymentsToAccount'])))),
            'tax_rates' => array_map(fn ($t) => ['type' => $t['TaxType'], 'name' => $t['Name'], 'rate' => $t['EffectiveRate'] ?? $t['DisplayTaxRate'] ?? null],
                array_values(array_filter($rates, fn ($t) => ($t['Status'] ?? '') === 'ACTIVE' && ! empty($t['CanApplyToRevenue'])))),
        ]);
    }

    /* ───────────── sync ───────────── */

    /** POST /xero/sync — sync everything pending (time-boxed; call again for the rest). */
    public function syncAll(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        if (! XeroClient::connected(XeroClient::row($agencyId))) return response()->json(['message' => 'Connect Xero first.'], 422);
        $lock = Cache::lock('xero-sync-' . $agencyId, 120);
        if (! $lock->get()) return response()->json(['message' => 'A Xero sync is already running for this agency — try again in a minute.'], 409);
        try {
            $out = XeroSync::agency($agencyId, (int) $request->user()->id, 45);
        } finally { $lock->release(); }
        $this->audit($request, $agencyId, 'xero.sync', sprintf('Xero sync: %d created, %d updated, %d voided, %d failed, %d left',
            $out['created'], $out['updated'], $out['voided'], $out['failed'], $out['remaining']));
        return response()->json($out);
    }

    /** POST /xero/sync/invoice/{id} */
    public function syncInvoice(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        abort_unless(XeroSync::agencyOfInvoice($id) === $agencyId, 404);
        try {
            $action = XeroSync::invoice($agencyId, $id, (int) $request->user()->id);
        } catch (XeroException $e) {
            return $this->fail($e);
        }
        return response()->json(['action' => $action]);
    }

    /** GET /xero/log?limit= — recent sync outcomes. */
    public function log(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $rows = DB::table('xero_sync_log as l')->leftJoin('invoices as i', function ($j) { $j->on('i.id', '=', 'l.local_id')->where('l.kind', '=', 'invoice'); })
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.agency_id', $agencyId)->orderByDesc('l.id')->limit(min(200, max(1, (int) $request->input('limit', 50))))
            ->select('l.id', 'l.kind', 'l.local_id', 'l.action', 'l.message', 'l.created_at', 'i.invoice_number', 'u.first_name', 'u.last_name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'local_id' => $r->local_id, 'action' => $r->action, 'message' => $r->message, 'created_at' => $r->created_at,
                'invoice_number' => $r->invoice_number, 'by' => $r->first_name ? trim($r->first_name . ' ' . $r->last_name) : 'Automatic']);
        return response()->json(['log' => $rows]);
    }
}
