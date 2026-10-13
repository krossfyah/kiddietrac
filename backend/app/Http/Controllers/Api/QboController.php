<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AccountingSyncException;
use App\Support\QboClient;
use App\Support\QboSync;
use App\Support\XeroClient;
use App\Support\XeroSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * QUICKBOOKS ONLINE — Settings → QuickBooks (rebuilt 2026-10-09 on the Xero model, at
 * Anthony's go-ahead). What the old controller got wrong, and why it never worked:
 *   · it read invoices.agency_id, which does not exist, so every push was refused;
 *   · it told Intuit to return to /api/v1/qbo/callback, but the route is /api/qbo/callback;
 *   · it created a new customer on every push, and trusted the OAuth state as given.
 * Now: one-use server-held OAuth state, encrypted tokens with locked refresh, customers and
 * invoices linked so re-syncs update, voids and payments follow, a sync log, and optional
 * automatic sync (qbo:sync). Endpoints keep their old paths; new ones are added.
 */
final class QboController extends Controller
{
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
        $ok = DB::table('role_assignments')->where('user_id', (int) $request->user()->id)->where('active', true)
            ->where(function ($w) use ($agencyId) {
                $w->where('role', 'platform_admin')->orWhere(function ($a) use ($agencyId) { $a->where('role', 'agency_admin')->where('agency_id', $agencyId); });
            })->exists();
        abort_unless($ok, 403, 'QuickBooks settings are for agency admins.');
        return $agencyId;
    }

    private function audit(Request $request, int $agencyId, string $action, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'agency',
                'entity_id' => $agencyId, 'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    /* ───────────── config ───────────── */

    public function showConfig(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = QboClient::row($agencyId);
        [$cid, , $fallback] = QboClient::creds($r);
        $connected = QboClient::connected($r);
        $linked = $connected ? DB::table('qbo_links')->where(['agency_id' => $agencyId, 'realm_id' => $r->realm_id])->selectRaw('kind, COUNT(*) n')->groupBy('kind')->pluck('n', 'kind') : collect();
        return response()->json([
            'agency_id' => $agencyId,
            'redirect_uri' => QboClient::redirectUri(),
            'client_id' => $r->client_id ?? '',
            'has_secret' => ! empty($r->client_secret),
            'environment' => $r->environment ?? 'production',
            'using_platform_fallback' => $fallback,
            'configured' => (bool) $cid,
            'connected' => $connected,
            'company_name' => $r->company_name ?? null,
            'country' => $r->country ?? null,
            'connected_at' => $r->connected_at ?? null,
            'connected_by' => $r && $r->connected_by_id ? trim((string) DB::table('users')->where('id', $r->connected_by_id)->value(DB::raw("CONCAT_WS(' ', first_name, last_name)"))) : null,
            'income_item_id' => $r->income_item_id ?? null,
            'subsidy_item_id' => $r->subsidy_item_id ?? null,
            'deposit_account_id' => $r->deposit_account_id ?? null,
            'tax_code_exempt' => $r->tax_code_exempt ?? null,
            'tax_code_taxable' => $r->tax_code_taxable ?? null,
            'sync_from' => $r->sync_from ?? null,
            'auto_sync' => (bool) ($r->auto_sync ?? false),
            'sync_payments' => (bool) ($r->sync_payments ?? true),
            'last_sync_at' => $r->last_sync_at ?? null,
            'last_error' => $r->last_error ?? null,
            'linked' => ['customers' => (int) ($linked['customer'] ?? 0), 'invoices' => (int) ($linked['invoice'] ?? 0), 'payments' => (int) ($linked['payment'] ?? 0)],
            'pending' => $connected ? count(QboSync::pendingIds($agencyId)) : 0,
        ]);
    }

    public function saveConfig(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $d = $request->validate([
            'client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'environment' => ['sometimes', 'in:sandbox,production'],
            'income_item_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'subsidy_item_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'deposit_account_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tax_code_exempt' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tax_code_taxable' => ['sometimes', 'nullable', 'string', 'max:32'],
            'sync_from' => ['sometimes', 'nullable', 'date'],
            'auto_sync' => ['sometimes', 'boolean'],
            'sync_payments' => ['sometimes', 'boolean'],
        ]);
        $r = QboClient::row($agencyId);
        $u = ['updated_at' => now()];
        foreach (['income_item_id', 'subsidy_item_id', 'deposit_account_id', 'tax_code_exempt', 'tax_code_taxable', 'sync_from'] as $k) {
            if (array_key_exists($k, $d)) $u[$k] = ($d[$k] === '' ? null : $d[$k]);
        }
        foreach (['auto_sync', 'sync_payments'] as $k) { if (array_key_exists($k, $d)) $u[$k] = (bool) $d[$k]; }
        $credsChanged = false;
        if (array_key_exists('client_id', $d)) {
            $new = trim((string) $d['client_id']) ?: null;
            if ($new !== ($r->client_id ?? null)) { $u['client_id'] = $new; $credsChanged = true; }
        }
        if (! empty($d['client_secret'])) { $u['client_secret'] = XeroClient::enc(trim($d['client_secret'])); $credsChanged = true; }
        if (array_key_exists('environment', $d) && $d['environment'] !== ($r->environment ?? 'production')) { $u['environment'] = $d['environment']; $credsChanged = true; }
        if ($credsChanged && $r && ! empty($r->refresh_token)) {
            QboClient::revoke($r);
            $u += ['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'realm_id' => null, 'company_name' => null];
        }
        if ($r) DB::table('agency_qbo')->where('agency_id', $agencyId)->update($u);
        else DB::table('agency_qbo')->insert(array_merge(['agency_id' => $agencyId, 'created_at' => now()], $u));
        $this->audit($request, $agencyId, 'qbo.settings_saved', 'QuickBooks settings saved' . ($credsChanged ? ' (app credentials changed)' : '') . ': ' . implode(', ', array_diff(array_keys($u), ['updated_at', 'client_secret', 'access_token', 'refresh_token'])));
        return response()->json(['ok' => true]);
    }

    public function clearConfig(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = QboClient::row($agencyId);
        if ($r) {
            QboClient::revoke($r);
            DB::table('agency_qbo')->where('agency_id', $agencyId)->update(['client_id' => null, 'client_secret' => null, 'access_token' => null, 'refresh_token' => null,
                'token_expires_at' => null, 'realm_id' => null, 'company_name' => null, 'auto_sync' => false, 'updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'qbo.cleared', 'QuickBooks app credentials cleared and QuickBooks disconnected');
        return response()->json(['ok' => true]);
    }

    /* ───────────── OAuth ───────────── */

    public function connect(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = QboClient::row($agencyId);
        [$cid] = QboClient::creds($r);
        if (! $cid) return response()->json(['message' => 'Save your Intuit app\'s client ID and secret first.'], 422);
        if (! $r) DB::table('agency_qbo')->insert(['agency_id' => $agencyId, 'created_at' => now(), 'updated_at' => now()]);
        $state = bin2hex(random_bytes(24));
        Cache::put('qbo-state:' . $state, ['agency_id' => $agencyId, 'user_id' => (int) $request->user()->id], now()->addMinutes(15));
        return response()->json(['authorize_url' => 'https://appcenter.intuit.com/connect/oauth2?' . http_build_query([
            'client_id' => $cid, 'response_type' => 'code', 'scope' => 'com.intuit.quickbooks.accounting',
            'redirect_uri' => QboClient::redirectUri(), 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986)]);
    }

    private function page(bool $ok, string $title, string $text)
    {
        $e = fn ($s) => htmlspecialchars($s, ENT_QUOTES);
        return response('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QuickBooks</title></head>'
            . '<body style="font-family:system-ui,sans-serif;padding:40px 20px;text-align:center;color:#0F172A;background:#F8FAFC;">'
            . '<div style="max-width:460px;margin:0 auto;background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:28px;">'
            . '<div style="font-size:40px;">' . ($ok ? '✅' : '⚠️') . '</div><h2 style="margin:8px 0;">' . $e($title) . '</h2>'
            . '<p style="color:#475569;line-height:1.5;">' . $e($text) . '</p><p style="color:#94A3B8;font-size:13px;">You can close this window and return to KiddieTrac.</p></div>'
            . '<script>try{window.opener&&window.opener.postMessage({kt:"qbo",ok:' . ($ok ? 'true' : 'false') . '},"*")}catch(e){}' . ($ok ? 'setTimeout(function(){window.close()},2500)' : '') . '</script></body></html>', 200)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** GET /api/qbo/callback — public; the one-use state proves who started it. */
    public function callback(Request $request)
    {
        $st = Cache::pull('qbo-state:' . (string) $request->query('state'));
        if (! $st) return $this->page(false, 'Link expired', 'This QuickBooks sign-in link has expired or was already used. Start again from Settings → QuickBooks.');
        $agencyId = (int) $st['agency_id'];
        if ($err = $request->query('error')) {
            return $this->page(false, 'QuickBooks was not connected', $err === 'access_denied' ? 'The connection was cancelled in QuickBooks.' : 'QuickBooks said: ' . $err);
        }
        $r = QboClient::row($agencyId);
        $realm = (string) $request->query('realmId');
        if (! $r || ! $request->query('code') || $realm === '') return $this->page(false, 'QuickBooks was not connected', 'Something was missing from Intuit\'s reply. Please try again.');
        try {
            $tok = QboClient::tokenRequest($r, ['grant_type' => 'authorization_code', 'code' => (string) $request->query('code'), 'redirect_uri' => QboClient::redirectUri()]);
        } catch (AccountingSyncException $e) {
            return $this->page(false, 'QuickBooks was not connected', $e->getMessage());
        }
        DB::table('agency_qbo')->where('agency_id', $agencyId)->update([
            'realm_id' => $realm,
            'access_token' => XeroClient::enc($tok['access_token']),
            'refresh_token' => XeroClient::enc($tok['refresh_token'] ?? null),
            'token_expires_at' => now()->addSeconds((int) ($tok['expires_in'] ?? 3600)),
            'connected_by_id' => $st['user_id'], 'connected_at' => now(), 'last_error' => null, 'updated_at' => now(),
        ]);
        $name = null;
        try {
            $info = QboClient::call($agencyId, 'GET', 'companyinfo/' . $realm)['CompanyInfo'] ?? [];
            $name = $info['CompanyName'] ?? null;
            DB::table('agency_qbo')->where('agency_id', $agencyId)->update(['company_name' => $name, 'country' => $info['Country'] ?? null]);
        } catch (\Throwable $e) {}
        try {
            \App\Support\Audit::write(['user_id' => $st['user_id'], 'agency_id' => $agencyId, 'action' => 'qbo.connected', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'QuickBooks connected' . ($name ? ' to ' . $name : '')]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
        return $this->page(true, 'QuickBooks connected', 'KiddieTrac is connected' . ($name ? ' to ' . $name : '') . '. Finish choosing your products and accounts in Settings → QuickBooks.');
    }

    public function disconnect(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = QboClient::row($agencyId);
        if ($r) {
            QboClient::revoke($r);
            DB::table('agency_qbo')->where('agency_id', $agencyId)->update(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'realm_id' => null, 'updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'qbo.disconnected', 'QuickBooks disconnected' . ($r && $r->company_name ? ' from ' . $r->company_name : ''));
        return response()->json(['status' => 'disconnected']);
    }

    /** GET /qbo/status — kept for older callers. */
    public function status(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = QboClient::row($agencyId);
        [$cid] = QboClient::creds($r);
        return response()->json(['connected' => QboClient::connected($r), 'expires_at' => $r->token_expires_at ?? null, 'configured' => (bool) $cid,
            'environment' => $r->environment ?? 'production', 'self_configured' => ! empty($r->client_id), 'company_name' => $r->company_name ?? null]);
    }

    /* ───────────── mapping options ───────────── */

    public function options(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        try {
            $r = QboClient::row($agencyId);
            // Not connected: say so (422) rather than fail reading realm_id off nothing (500).
            if (! $r || empty($r->realm_id)) {
                return response()->json(['message' => 'Connect QuickBooks first.'], 422);
            }
            $info = QboClient::call($agencyId, 'GET', 'companyinfo/' . $r->realm_id)['CompanyInfo'] ?? [];
            $items = QboClient::query($agencyId, "select * from Item where Active = true and Type in ('Service', 'NonInventory') maxresults 1000", 'Item');
            $accts = QboClient::query($agencyId, "select * from Account where Active = true and AccountType in ('Bank', 'Other Current Asset') maxresults 1000", 'Account');
            $codes = [];
            try { $codes = QboClient::query($agencyId, 'select * from TaxCode where Active = true maxresults 1000', 'TaxCode'); } catch (AccountingSyncException $e) {}
        } catch (AccountingSyncException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $country = $info['Country'] ?? null;
        if (! empty($info['Country'])) DB::table('agency_qbo')->where('agency_id', $agencyId)->update(['country' => $info['Country'], 'company_name' => $info['CompanyName'] ?? null]);
        $taxCodes = array_map(fn ($t) => ['id' => (string) $t['Id'], 'name' => $t['Name'] . (! empty($t['Description']) && $t['Description'] !== $t['Name'] ? ' — ' . $t['Description'] : '')], $codes);
        if (strtoupper((string) $country) === 'US') $taxCodes = [['id' => 'NON', 'name' => 'Non-taxable (NON)'], ['id' => 'TAX', 'name' => 'Taxable (TAX)']];
        return response()->json([
            'company' => ['name' => $info['CompanyName'] ?? null, 'country' => $country],
            'items' => array_map(fn ($i) => ['id' => (string) $i['Id'], 'name' => $i['FullyQualifiedName'] ?? $i['Name'], 'type' => $i['Type'] ?? ''], $items),
            'deposit_accounts' => array_map(fn ($a) => ['id' => (string) $a['Id'], 'name' => $a['FullyQualifiedName'] ?? $a['Name'], 'type' => $a['AccountType'] ?? ''], $accts),
            'tax_codes' => $taxCodes,
        ]);
    }

    /* ───────────── sync ───────────── */

    public function syncAll(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        if (! QboClient::connected(QboClient::row($agencyId))) return response()->json(['message' => 'Connect QuickBooks first.'], 422);
        $lock = Cache::lock('qbo-sync-' . $agencyId, 120);
        if (! $lock->get()) return response()->json(['message' => 'A QuickBooks sync is already running for this agency — try again in a minute.'], 409);
        try { $out = QboSync::agency($agencyId, (int) $request->user()->id, 45); } finally { $lock->release(); }
        $this->audit($request, $agencyId, 'qbo.sync', sprintf('QuickBooks sync: %d created, %d updated, %d voided, %d failed, %d left', $out['created'], $out['updated'], $out['voided'], $out['failed'], $out['remaining']));
        // Shape kept for the old bulk caller too.
        return response()->json($out + ['synced' => $out['created'] + $out['updated']]);
    }

    /** POST /qbo/sync/invoices/bulk — the old button's path; same as Sync now. */
    public function bulkSyncInvoices(Request $request): JsonResponse
    {
        return $this->syncAll($request);
    }

    public function syncInvoice(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        abort_unless(XeroSync::agencyOfInvoice($id) === $agencyId, 404);
        try { $action = QboSync::invoice($agencyId, $id, (int) $request->user()->id); }
        catch (AccountingSyncException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['action' => $action]);
    }

    public function log(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $rows = DB::table('qbo_sync_log as l')->leftJoin('invoices as i', function ($j) { $j->on('i.id', '=', 'l.local_id')->where('l.kind', '=', 'invoice'); })
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.agency_id', $agencyId)->orderByDesc('l.id')->limit(min(200, max(1, (int) $request->input('limit', 50))))
            ->select('l.id', 'l.kind', 'l.local_id', 'l.action', 'l.message', 'l.created_at', 'i.invoice_number', 'u.first_name', 'u.last_name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'local_id' => $r->local_id, 'action' => $r->action, 'message' => $r->message, 'created_at' => $r->created_at,
                'invoice_number' => $r->invoice_number, 'by' => $r->first_name ? trim($r->first_name . ' ' . $r->last_name) : 'Automatic']);
        return response()->json(['log' => $rows]);
    }
}
