<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * v22p51 — QuickBooks Online integration.
 * v22p73 — credentials are now configurable PER AGENCY by the agency admin.
 *
 * Each agency can supply their own Intuit app credentials
 * (qbo_client_id / qbo_client_secret / qbo_environment on the agencies row).
 * If an agency hasn't set their own, the platform env() values are used as a
 * fallback. The OAuth redirect URI is shared (our /qbo/callback) — each agency
 * registers that URI in their own Intuit app.
 */
final class QboController extends Controller
{
    /** Resolve the effective QBO config for an agency (per-agency, env fallback). */
    private function cfg($agency): array
    {
        $clientId = $agency->qbo_client_id ?? null;
        $clientSecret = null;
        if (!empty($agency->qbo_client_secret)) {
            try { $clientSecret = Crypt::decryptString($agency->qbo_client_secret); }
            catch (\Throwable $e) { $clientSecret = $agency->qbo_client_secret; }
        }
        if (!$clientId)     $clientId = env('QBO_CLIENT_ID');
        if (!$clientSecret) $clientSecret = env('QBO_CLIENT_SECRET');

        $env = $agency->qbo_environment ?? null ?: env('QBO_ENV', 'production');
        $redirect = env('QBO_REDIRECT_URI') ?: (rtrim((string) config('app.url'), '/') . '/api/v1/qbo/callback');

        return [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirect,
            'env'           => $env === 'sandbox' ? 'sandbox' : 'production',
        ];
    }

    private function apiBase(string $env, string $realmId): string
    {
        return ($env === 'sandbox')
            ? 'https://sandbox-quickbooks.api.intuit.com/v3/company/' . $realmId
            : 'https://quickbooks.api.intuit.com/v3/company/' . $realmId;
    }

    /* ───────────── Per-agency config (admin) ───────────── */

    /** GET /admin/qbo-config */
    public function showConfig(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $a = DB::table('agencies')->where('id', $agencyId)->first();
        $cfg = $this->cfg($a);
        return response()->json([
            'agency_id'    => $agencyId,
            'client_id'    => $a->qbo_client_id ?? '',
            'environment'  => $a->qbo_environment ?: 'production',
            'has_secret'   => !empty($a->qbo_client_secret),
            'redirect_uri' => $cfg['redirect_uri'],
            'using_platform_fallback' => empty($a->qbo_client_id) && (bool) env('QBO_CLIENT_ID'),
            'configured'   => (bool) $cfg['client_id'],
            'connected'    => !empty($a->qbo_realm_id),
        ]);
    }

    /** PATCH /admin/qbo-config { client_id, client_secret?, environment } */
    public function saveConfig(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $data = $request->validate([
            'client_id'     => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'environment'   => ['nullable', 'in:sandbox,production'],
        ]);

        $update = [
            'qbo_client_id'   => $data['client_id'] ?: null,
            'qbo_environment' => $data['environment'] ?? 'production',
            'updated_at'      => now(),
        ];
        // Only overwrite the secret when a new one is supplied
        if (!empty($data['client_secret'])) {
            $update['qbo_client_secret'] = Crypt::encryptString($data['client_secret']);
        }
        DB::table('agencies')->where('id', $agencyId)->update($update);
        return response()->json(['ok' => true]);
    }

    /** DELETE /admin/qbo-config — clear agency credentials (and disconnect) */
    public function clearConfig(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        DB::table('agencies')->where('id', $agencyId)->update([
            'qbo_client_id' => null, 'qbo_client_secret' => null,
            'qbo_realm_id' => null, 'qbo_access_token' => null,
            'qbo_refresh_token' => null, 'qbo_token_expires_at' => null,
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }

    /* ───────────── OAuth flow ───────────── */

    public function connect(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $cfg = $this->cfg($agency);
        abort_unless($cfg['client_id'], 503, 'QuickBooks not configured for this agency');

        $state = bin2hex(random_bytes(16)) . '|' . $agencyId;
        $params = http_build_query([
            'client_id'     => $cfg['client_id'],
            'response_type' => 'code',
            'scope'         => 'com.intuit.quickbooks.accounting',
            'redirect_uri'  => $cfg['redirect_uri'],
            'state'         => $state,
        ]);
        return response()->json([
            'authorize_url' => 'https://appcenter.intuit.com/connect/oauth2?' . $params,
        ]);
    }

    public function callback(Request $request)
    {
        $code = $request->query('code');
        $realmId = $request->query('realmId');
        $state = $request->query('state');
        $parts = explode('|', (string) $state);
        $agencyId = (int) ($parts[1] ?? 0);
        abort_unless($code && $realmId && $agencyId, 400, 'missing params');

        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        abort_unless($agency, 404);
        $cfg = $this->cfg($agency);
        abort_unless($cfg['client_id'], 503, 'QBO not configured');

        $res = Http::asForm()->withBasicAuth($cfg['client_id'], $cfg['client_secret'])
            ->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $cfg['redirect_uri'],
            ]);
        if (!$res->ok()) {
            return response()->json(['error' => 'token exchange failed', 'detail' => $res->body()], 502);
        }
        $tok = $res->json();
        DB::table('agencies')->where('id', $agencyId)->update([
            'qbo_realm_id'         => $realmId,
            'qbo_access_token'     => $tok['access_token'],
            'qbo_refresh_token'    => $tok['refresh_token'],
            'qbo_token_expires_at' => now()->addSeconds((int) ($tok['expires_in'] ?? 3600)),
            'updated_at'           => now(),
        ]);
        return response('<html><body style="font-family:sans-serif;padding:40px;text-align:center;"><h2>QuickBooks connected ✓</h2><p>You can close this window and return to KiddieTrac.</p><script>setTimeout(()=>window.close(),2000)</script></body></html>')
            ->header('Content-Type', 'text/html');
    }

    public function disconnect(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        DB::table('agencies')->where('id', $agencyId)->update([
            'qbo_realm_id' => null, 'qbo_access_token' => null,
            'qbo_refresh_token' => null, 'qbo_token_expires_at' => null,
            'updated_at' => now(),
        ]);
        return response()->json(['status' => 'disconnected']);
    }

    public function status(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->first();
        $cfg = $this->cfg($row);
        return response()->json([
            'connected'  => !empty($row->qbo_realm_id),
            'expires_at' => $row->qbo_token_expires_at ?? null,
            'configured' => (bool) $cfg['client_id'],
            'environment' => $cfg['env'],
            'self_configured' => !empty($row->qbo_client_id),
        ]);
    }

    public function syncInvoice(Request $request, int $invoiceId): JsonResponse
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($inv, 404);
        $this->assertAgencyAdmin($request, (int) $inv->agency_id);

        $agency = DB::table('agencies')->where('id', $inv->agency_id)->first();
        $cfg = $this->cfg($agency);
        abort_unless($cfg['client_id'], 503, 'QBO not configured');
        if (empty($agency->qbo_access_token) || empty($agency->qbo_realm_id)) {
            return response()->json(['error' => 'agency not connected to QBO'], 422);
        }
        $token = $this->ensureFreshToken($agency, $cfg);
        $family = DB::table('families')->where('id', $inv->family_id)->first();
        $base = $this->apiBase($cfg['env'], $agency->qbo_realm_id);

        $custBody = ['DisplayName' => $family->family_name];
        $custRes = Http::withToken($token)->withHeaders(['Accept' => 'application/json'])
            ->post($base . '/customer', $custBody);
        if (!$custRes->ok()) {
            return response()->json(['error' => 'customer push failed', 'body' => $custRes->body()], 502);
        }
        $customerId = $custRes->json('Customer.Id');

        $lines = DB::table('invoice_lines')->where('invoice_id', $invoiceId)->get();
        $qboLines = $lines->map(function ($l) {
            return [
                'Amount' => (float) $l->amount,
                'DetailType' => 'SalesItemLineDetail',
                'Description' => $l->description ?? $l->line_type,
                'SalesItemLineDetail' => ['ItemRef' => ['value' => '1', 'name' => 'Services']],
            ];
        })->all();
        $invBody = [
            'Line' => $qboLines,
            'CustomerRef' => ['value' => $customerId],
            'DocNumber' => (string) $inv->invoice_number,
        ];
        $invRes = Http::withToken($token)->post($base . '/invoice', $invBody);
        if (!$invRes->ok()) {
            return response()->json(['error' => 'invoice push failed', 'body' => $invRes->body()], 502);
        }
        return response()->json([
            'qbo_invoice_id' => $invRes->json('Invoice.Id'),
            'qbo_customer_id' => $customerId,
        ]);
    }

    public function bulkSyncInvoices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_ids'   => 'nullable|array|max:500',
            'invoice_ids.*' => 'integer',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        // If no explicit list, sync this agency's recent unsynced invoices
        $ids = $data['invoice_ids'] ?? null;
        if (!$ids) {
            $ids = DB::table('invoices')->where('agency_id', $agencyId)
                ->orderByDesc('id')->limit(100)->pluck('id')->all();
        }
        $results = [];
        foreach ($ids as $iid) {
            try {
                $res = $this->syncInvoice($request, (int) $iid);
                $payload = json_decode($res->getContent(), true);
                $results[] = ['invoice_id' => $iid, 'status' => $res->status() === 200 ? 'ok' : 'fail', 'detail' => $payload];
            } catch (\Throwable $e) {
                $results[] = ['invoice_id' => $iid, 'status' => 'fail', 'detail' => $e->getMessage()];
            }
        }
        $ok = count(array_filter($results, fn ($r) => $r['status'] === 'ok'));
        return response()->json(['results' => $results, 'synced' => $ok, 'failed' => count($results) - $ok]);
    }

    private function ensureFreshToken($agency, array $cfg): string
    {
        if (!empty($agency->qbo_token_expires_at) && now()->lt($agency->qbo_token_expires_at)) {
            return $agency->qbo_access_token;
        }
        $res = Http::asForm()->withBasicAuth($cfg['client_id'], $cfg['client_secret'])
            ->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $agency->qbo_refresh_token,
            ]);
        if (!$res->ok()) abort(502, 'qbo refresh failed');
        $tok = $res->json();
        DB::table('agencies')->where('id', $agency->id)->update([
            'qbo_access_token'     => $tok['access_token'],
            'qbo_refresh_token'    => $tok['refresh_token'] ?? $agency->qbo_refresh_token,
            'qbo_token_expires_at' => now()->addSeconds((int) ($tok['expires_in'] ?? 3600)),
        ]);
        return $tok['access_token'];
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    private function assertAgencyAdmin(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->where('role', 'agency_admin')
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
