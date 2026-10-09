<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AccountingSyncException;
use App\Support\Sage50Export;
use App\Support\SageClient;
use App\Support\SageSync;
use App\Support\XeroClient;
use App\Support\XeroSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SAGE — Settings → Sage (2026-10-09). Two products on one screen:
 *   · Sage Accounting (cloud): own app credentials, OAuth (one-use server-held state),
 *     business picker, ledger/bank/tax mapping, Sync now, sync log, optional sage:sync.
 *   · Sage 50 (desktop): account numbers, then import files — a general journal (.TXT)
 *     holding only what is new since the last file, and a Customers CSV. Past files can be
 *     downloaded again.
 * Agency admins of the active agency and platform admins.
 */
final class SageController extends Controller
{
    private function agencyAdmin(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) {
            abort_unless($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists(), 400, 'Select an agency first.');
            return $active;
        }
        $admin = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'agency_admin')->where('active', true);
        if ($active && (clone $admin)->where('agency_id', $active)->exists()) return $active;
        $id = (int) ($admin->value('agency_id') ?: 0);
        abort_unless($id, 403, 'Sage settings are for agency admins.');
        return $id;
    }

    private function audit(Request $request, int $agencyId, string $action, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'agency',
                'entity_id' => $agencyId, 'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private function ensureRow(int $agencyId): object
    {
        if (! SageClient::row($agencyId)) DB::table('agency_sage')->insert(['agency_id' => $agencyId, 'created_at' => now(), 'updated_at' => now()]);
        return SageClient::row($agencyId);
    }

    /* ───────────── config ───────────── */

    public function showConfig(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = SageClient::row($agencyId);
        $connected = SageClient::connected($r);
        $linked = $connected ? DB::table('sage_links')->where(['agency_id' => $agencyId, 'business_id' => $r->business_id])->selectRaw('kind, COUNT(*) n')->groupBy('kind')->pluck('n', 'kind') : collect();
        $lastExport = DB::table('sage50_exports')->where('agency_id', $agencyId)->orderByDesc('id')->first(['id', 'created_at', 'entries']);
        return response()->json([
            'redirect_uri' => SageClient::redirectUri(),
            'client_id' => $r->client_id ?? '', 'has_secret' => ! empty($r->client_secret),
            'configured' => ! empty($r->client_id) && ! empty($r->client_secret),
            'connected' => $connected, 'authorised' => $r && ! empty($r->refresh_token),
            'business_id' => $r->business_id ?? null, 'business_name' => $r->business_name ?? null, 'country' => $r->country ?? null,
            'businesses' => $r && $r->businesses_json ? json_decode($r->businesses_json, true) : [],
            'connected_at' => $r->connected_at ?? null,
            'connected_by' => $r && $r->connected_by_id ? trim((string) DB::table('users')->where('id', $r->connected_by_id)->value(DB::raw("CONCAT_WS(' ', first_name, last_name)"))) : null,
            'ledger_account_id' => $r->ledger_account_id ?? null, 'subsidy_ledger_account_id' => $r->subsidy_ledger_account_id ?? null,
            'bank_account_id' => $r->bank_account_id ?? null, 'tax_rate_exempt' => $r->tax_rate_exempt ?? null, 'tax_rate_taxable' => $r->tax_rate_taxable ?? null,
            'sync_from' => $r->sync_from ?? null, 'auto_sync' => (bool) ($r->auto_sync ?? false), 'sync_payments' => (bool) ($r->sync_payments ?? true),
            'last_sync_at' => $r->last_sync_at ?? null, 'last_error' => $r->last_error ?? null,
            'linked' => ['contacts' => (int) ($linked['contact'] ?? 0), 'invoices' => (int) ($linked['invoice'] ?? 0), 'payments' => (int) ($linked['payment'] ?? 0)],
            'pending' => $connected ? count(SageSync::pendingIds($agencyId)) : 0,
            's50' => ['receivable' => $r->s50_receivable ?? '', 'revenue' => $r->s50_revenue ?? '', 'subsidy' => $r->s50_subsidy ?? '',
                'discount' => $r->s50_discount ?? '', 'tax' => $r->s50_tax ?? '', 'bank' => $r->s50_bank ?? '',
                'last_export' => $lastExport],
        ]);
    }

    public function saveConfig(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $acct = ['sometimes', 'nullable', 'regex:/^\d{4,8}$/'];
        $d = $request->validate([
            'client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ledger_account_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subsidy_ledger_account_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bank_account_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tax_rate_exempt' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tax_rate_taxable' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sync_from' => ['sometimes', 'nullable', 'date'],
            'auto_sync' => ['sometimes', 'boolean'],
            'sync_payments' => ['sometimes', 'boolean'],
            's50_receivable' => $acct, 's50_revenue' => $acct, 's50_subsidy' => $acct, 's50_discount' => $acct, 's50_tax' => $acct, 's50_bank' => $acct,
        ], ['regex' => 'Sage 50 account numbers are 4 to 8 digits.']);
        $r = $this->ensureRow($agencyId);
        $u = ['updated_at' => now()];
        foreach (['ledger_account_id', 'subsidy_ledger_account_id', 'bank_account_id', 'tax_rate_exempt', 'tax_rate_taxable', 'sync_from',
                  's50_receivable', 's50_revenue', 's50_subsidy', 's50_discount', 's50_tax', 's50_bank'] as $k) {
            if (array_key_exists($k, $d)) $u[$k] = ($d[$k] === '' ? null : $d[$k]);
        }
        if (! empty($u['s50_receivable']) && ! empty($u['s50_bank']) && $u['s50_receivable'] === $u['s50_bank']) {
            return response()->json(['message' => 'The bank account and the family receivable account must be different accounts.'], 422);
        }
        foreach (['auto_sync', 'sync_payments'] as $k) { if (array_key_exists($k, $d)) $u[$k] = (bool) $d[$k]; }
        $creds = false;
        if (array_key_exists('client_id', $d)) { $new = trim((string) $d['client_id']) ?: null; if ($new !== $r->client_id) { $u['client_id'] = $new; $creds = true; } }
        if (! empty($d['client_secret'])) { $u['client_secret'] = XeroClient::enc(trim($d['client_secret'])); $creds = true; }
        if ($creds && ! empty($r->refresh_token)) {
            SageClient::revoke($r);
            $u += ['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'business_id' => null, 'business_name' => null, 'businesses_json' => null];
        }
        DB::table('agency_sage')->where('agency_id', $agencyId)->update($u);
        $this->audit($request, $agencyId, 'sage.settings_saved', 'Sage settings saved' . ($creds ? ' (app credentials changed)' : '') . ': ' . implode(', ', array_diff(array_keys($u), ['updated_at', 'client_secret', 'access_token', 'refresh_token'])));
        return response()->json(['ok' => true]);
    }

    public function clearConfig(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = SageClient::row($agencyId);
        if ($r) {
            SageClient::revoke($r);
            DB::table('agency_sage')->where('agency_id', $agencyId)->update(['client_id' => null, 'client_secret' => null, 'access_token' => null, 'refresh_token' => null,
                'token_expires_at' => null, 'business_id' => null, 'business_name' => null, 'businesses_json' => null, 'auto_sync' => false, 'updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'sage.cleared', 'Sage Accounting app credentials cleared and Sage disconnected');
        return response()->json(['ok' => true]);
    }

    /* ───────────── OAuth ───────────── */

    public function connect(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = SageClient::row($agencyId);
        if (! $r || empty($r->client_id) || empty($r->client_secret)) return response()->json(['message' => 'Save your Sage app\'s client ID and secret first.'], 422);
        $state = bin2hex(random_bytes(24));
        Cache::put('sage-state:' . $state, ['agency_id' => $agencyId, 'user_id' => (int) $request->user()->id], now()->addMinutes(15));
        return response()->json(['authorize_url' => 'https://www.sageone.com/oauth2/auth/central?' . http_build_query([
            'filter' => 'apiv3.1', 'response_type' => 'code', 'client_id' => $r->client_id, 'redirect_uri' => SageClient::redirectUri(),
            'scope' => 'full_access', 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986)]);
    }

    private function page(bool $ok, string $title, string $text)
    {
        $e = fn ($s) => htmlspecialchars($s, ENT_QUOTES);
        return response('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sage</title></head>'
            . '<body style="font-family:system-ui,sans-serif;padding:40px 20px;text-align:center;color:#0F172A;background:#F8FAFC;">'
            . '<div style="max-width:460px;margin:0 auto;background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:28px;">'
            . '<div style="font-size:40px;">' . ($ok ? '✅' : '⚠️') . '</div><h2 style="margin:8px 0;">' . $e($title) . '</h2>'
            . '<p style="color:#475569;line-height:1.5;">' . $e($text) . '</p><p style="color:#94A3B8;font-size:13px;">You can close this window and return to KiddieTrac.</p></div>'
            . '<script>try{window.opener&&window.opener.postMessage({kt:"sage",ok:' . ($ok ? 'true' : 'false') . '},"*")}catch(e){}' . ($ok ? 'setTimeout(function(){window.close()},2500)' : '') . '</script></body></html>', 200)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function callback(Request $request)
    {
        $st = Cache::pull('sage-state:' . (string) $request->query('state'));
        if (! $st) return $this->page(false, 'Link expired', 'This Sage sign-in link has expired or was already used. Start again from Settings → Sage.');
        $agencyId = (int) $st['agency_id'];
        if ($err = $request->query('error')) return $this->page(false, 'Sage was not connected', $err === 'access_denied' ? 'The connection was cancelled in Sage.' : 'Sage said: ' . $err);
        $r = SageClient::row($agencyId);
        if (! $r || ! $request->query('code')) return $this->page(false, 'Sage was not connected', 'Something was missing from Sage\'s reply. Please try again.');
        try {
            $tok = SageClient::tokenRequest($r, ['grant_type' => 'authorization_code', 'code' => (string) $request->query('code'), 'redirect_uri' => SageClient::redirectUri()]);
            $biz = SageClient::businesses((string) $tok['access_token']);
        } catch (AccountingSyncException $e) {
            return $this->page(false, 'Sage was not connected', $e->getMessage());
        }
        if (! $biz) return $this->page(false, 'No business found', 'Sage did not give access to any business.');
        $keep = $r->business_id && collect($biz)->firstWhere('id', $r->business_id) ? $r->business_id : null;
        $pick = $keep ?: (count($biz) === 1 ? $biz[0]['id'] : null);
        $chosen = $pick ? collect($biz)->firstWhere('id', $pick) : null;
        DB::table('agency_sage')->where('agency_id', $agencyId)->update([
            'access_token' => XeroClient::enc($tok['access_token']), 'refresh_token' => XeroClient::enc($tok['refresh_token'] ?? null),
            'token_expires_at' => now()->addSeconds((int) ($tok['expires_in'] ?? 300)),
            'businesses_json' => json_encode($biz), 'business_id' => $pick, 'business_name' => $chosen['name'] ?? null, 'country' => $chosen['country'] ?? null,
            'connected_by_id' => $st['user_id'], 'connected_at' => now(), 'last_error' => null, 'updated_at' => now(),
        ]);
        try {
            \App\Support\Audit::write(['user_id' => $st['user_id'], 'agency_id' => $agencyId, 'action' => 'sage.connected', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Sage Accounting connected' . ($chosen ? ' to ' . $chosen['name'] : ' (' . count($biz) . ' businesses — choose one)')]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
        return $chosen
            ? $this->page(true, 'Sage connected', 'KiddieTrac is connected to ' . $chosen['name'] . '. Finish choosing your accounts in Settings → Sage.')
            : $this->page(true, 'Choose your business', 'You gave access to ' . count($biz) . ' Sage businesses. Pick the one to use in Settings → Sage.');
    }

    public function chooseBusiness(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = SageClient::row($agencyId);
        $b = $r && $r->businesses_json ? collect(json_decode($r->businesses_json, true))->firstWhere('id', (string) $request->input('business_id')) : null;
        abort_unless($b, 422, 'That business was not part of this connection.');
        DB::table('agency_sage')->where('agency_id', $agencyId)->update(['business_id' => $b['id'], 'business_name' => $b['name'], 'country' => $b['country'] ?? null, 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'sage.business_chosen', 'Sage business set to ' . $b['name']);
        return response()->json(['ok' => true]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $r = SageClient::row($agencyId);
        if ($r) {
            SageClient::revoke($r);
            DB::table('agency_sage')->where('agency_id', $agencyId)->update(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null,
                'business_id' => null, 'business_name' => null, 'businesses_json' => null, 'updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'sage.disconnected', 'Sage Accounting disconnected' . ($r && $r->business_name ? ' from ' . $r->business_name : ''));
        return response()->json(['ok' => true]);
    }

    public function options(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        try {
            $ledgers = SageClient::all($agencyId, 'ledger_accounts', ['visible_in' => 'sales', 'attributes' => 'nominal_code,ledger_account_type']);
            $banks = SageClient::all($agencyId, 'bank_accounts');
            $rates = SageClient::all($agencyId, 'tax_rates', ['attributes' => 'percentage,is_visible']);
        } catch (AccountingSyncException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $r = SageClient::row($agencyId);
        return response()->json([
            'business' => ['name' => $r->business_name, 'country' => $r->country],
            'ledgers' => array_map(fn ($l) => ['id' => $l['id'], 'name' => trim(($l['nominal_code'] ?? '') . ' — ' . ($l['displayed_as'] ?? ''), ' —')], $ledgers),
            'banks' => array_map(fn ($b) => ['id' => $b['id'], 'name' => $b['displayed_as'] ?? 'Bank account'], $banks),
            'tax_rates' => array_values(array_map(fn ($t) => ['id' => $t['id'], 'name' => ($t['displayed_as'] ?? $t['id']) . (isset($t['percentage']) ? ' (' . $t['percentage'] . '%)' : '')],
                array_filter($rates, fn ($t) => ($t['is_visible'] ?? true) !== false))),
        ]);
    }

    /* ───────────── cloud sync ───────────── */

    public function syncAll(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        if (! SageClient::connected(SageClient::row($agencyId))) return response()->json(['message' => 'Connect Sage first.'], 422);
        $lock = Cache::lock('sage-sync-' . $agencyId, 120);
        if (! $lock->get()) return response()->json(['message' => 'A Sage sync is already running for this agency — try again in a minute.'], 409);
        try { $out = SageSync::agency($agencyId, (int) $request->user()->id, 45); } finally { $lock->release(); }
        $this->audit($request, $agencyId, 'sage.sync', sprintf('Sage sync: %d created, %d updated, %d voided, %d failed, %d left', $out['created'], $out['updated'], $out['voided'], $out['failed'], $out['remaining']));
        return response()->json($out);
    }

    public function syncInvoice(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        abort_unless(XeroSync::agencyOfInvoice($id) === $agencyId, 404);
        try { $a = SageSync::invoice($agencyId, $id, (int) $request->user()->id); }
        catch (AccountingSyncException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['action' => $a]);
    }

    public function log(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        $rows = DB::table('sage_sync_log as l')->leftJoin('invoices as i', function ($j) { $j->on('i.id', '=', 'l.local_id')->where('l.kind', '=', 'invoice'); })
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')->where('l.agency_id', $agencyId)->orderByDesc('l.id')->limit(min(200, max(1, (int) $request->input('limit', 50))))
            ->select('l.id', 'l.kind', 'l.local_id', 'l.action', 'l.message', 'l.created_at', 'i.invoice_number', 'u.first_name', 'u.last_name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'local_id' => $r->local_id, 'action' => $r->action, 'message' => $r->message, 'created_at' => $r->created_at,
                'invoice_number' => $r->invoice_number, 'by' => $r->first_name ? trim($r->first_name . ' ' . $r->last_name) : 'Automatic']);
        return response()->json(['log' => $rows]);
    }

    /* ───────────── Sage 50 files ───────────── */

    private function range(Request $request): array
    {
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        return [$d['from'] ?? null, $d['to'] ?? null];
    }

    /** GET /sage50/preview?from=&to= — what the next journal file would hold. */
    public function s50Preview(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        [$from, $to] = $this->range($request);
        [, $sum] = Sage50Export::build($agencyId, $from, $to, false);
        return response()->json($sum);
    }

    /** POST /sage50/export {from,to} — build, remember and return the journal file. */
    public function s50Export(Request $request)
    {
        $agencyId = $this->agencyAdmin($request);
        [$from, $to] = $this->range($request);
        $lock = Cache::lock('sage50-export-' . $agencyId, 60);
        if (! $lock->get()) return response()->json(['message' => 'An export is already being made — try again in a moment.'], 409);
        try { [$content, $sum] = Sage50Export::build($agencyId, $from, $to, true, (int) $request->user()->id); } finally { $lock->release(); }
        if (! $sum['entries']) return response()->json(['message' => 'Nothing new to export' . ($sum['warnings'] ? ' — ' . implode(' ', $sum['warnings']) : '.')], 422);
        $this->audit($request, $agencyId, 'sage50.export', sprintf('Sage 50 journal file #%d: %d entries (%d invoices, %d payments, %d adjustments, %d reversals), debits %.2f',
            $sum['export_id'], $sum['entries'], $sum['invoices'], $sum['payments'], $sum['adjustments'], $sum['reversals'], $sum['debits']));
        return response(self::ansi($content), 200, ['Content-Type' => 'text/plain; charset=windows-1252',
            'Content-Disposition' => 'attachment; filename="kiddietrac-sage50-journal-' . $sum['export_id'] . '.txt"', 'X-Export-Summary' => json_encode($sum), 'Access-Control-Expose-Headers' => 'Content-Disposition, X-Export-Summary']);
    }

    /** Sage 50 reads import files in the Windows code page, not UTF-8 (accents in family names). */
    private static function ansi(string $s): string
    {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    public function s50Exports(Request $request): JsonResponse
    {
        $agencyId = $this->agencyAdmin($request);
        return response()->json(['exports' => DB::table('sage50_exports as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')->where('e.agency_id', $agencyId)
            ->orderByDesc('e.id')->limit(50)->get(['e.id', 'e.date_from', 'e.date_to', 'e.entries', 'e.total_debits', 'e.created_at', 'u.first_name', 'u.last_name'])]);
    }

    public function s50Download(Request $request, int $id)
    {
        $agencyId = $this->agencyAdmin($request);
        $e = DB::table('sage50_exports')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($e, 404);
        return response(self::ansi($e->content), 200, ['Content-Type' => 'text/plain; charset=windows-1252', 'Content-Disposition' => 'attachment; filename="kiddietrac-sage50-journal-' . $e->id . '.txt"', 'Access-Control-Expose-Headers' => 'Content-Disposition']);
    }

    public function s50Customers(Request $request)
    {
        $agencyId = $this->agencyAdmin($request);
        $this->audit($request, $agencyId, 'sage50.customers_export', 'Sage 50 customers CSV downloaded');
        return response(Sage50Export::customersCsv($agencyId), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="kiddietrac-sage50-customers.csv"']);
    }
}
