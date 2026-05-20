<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * v22p51 — QuickBooks Online integration.
 *
 * Inert until env vars are set:
 *   QBO_CLIENT_ID
 *   QBO_CLIENT_SECRET
 *   QBO_REDIRECT_URI       e.g. https://api.kiddietrac.com/api/v1/qbo/callback
 *   QBO_ENV                'sandbox' or 'production'
 *
 * Flow:
 *   1. GET /qbo/connect    → returns OAuth authorize URL.
 *   2. (User authorises on Intuit.)
 *   3. GET /qbo/callback   → exchanges code for tokens; saves to agency row.
 *   4. POST /qbo/sync/invoice/{id}  → pushes one KiddieTrac invoice into QBO.
 *   5. POST /qbo/disconnect.
 */
final class QboController extends Controller
{
    public function connect(Request $request): JsonResponse
    {
        abort_unless(env('QBO_CLIENT_ID'), 503, 'QBO not configured');
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $state = bin2hex(random_bytes(16)) . '|' . $agencyId;
        $params = http_build_query([
            'client_id'     => env('QBO_CLIENT_ID'),
            'response_type' => 'code',
            'scope'         => 'com.intuit.quickbooks.accounting',
            'redirect_uri'  => env('QBO_REDIRECT_URI'),
            'state'         => $state,
        ]);
        return response()->json([
            'authorize_url' => 'https://appcenter.intuit.com/connect/oauth2?' . $params,
        ]);
    }

    public function callback(Request $request)
    {
        abort_unless(env('QBO_CLIENT_ID'), 503);
        $code = $request->query('code');
        $realmId = $request->query('realmId');
        $state = $request->query('state');
        $parts = explode('|', (string) $state);
        $agencyId = (int) ($parts[1] ?? 0);
        abort_unless($code && $realmId && $agencyId, 400, 'missing params');

        $res = Http::asForm()->withBasicAuth(env('QBO_CLIENT_ID'), env('QBO_CLIENT_SECRET'))
            ->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => env('QBO_REDIRECT_URI'),
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
        $row = DB::table('agencies')->where('id', $agencyId)
            ->select('qbo_realm_id', 'qbo_token_expires_at')->first();
        return response()->json([
            'connected' => !empty($row->qbo_realm_id),
            'expires_at' => $row->qbo_token_expires_at ?? null,
            'configured' => (bool) env('QBO_CLIENT_ID'),
        ]);
    }

    /**
     * Push a single invoice into QBO. Creates customer + invoice as needed.
     */
    public function syncInvoice(Request $request, int $invoiceId): JsonResponse
    {
        abort_unless(env('QBO_CLIENT_ID'), 503);
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($inv, 404);
        $this->assertAgencyAdmin($request, (int) $inv->agency_id);

        $agency = DB::table('agencies')->where('id', $inv->agency_id)->first();
        if (empty($agency->qbo_access_token) || empty($agency->qbo_realm_id)) {
            return response()->json(['error' => 'agency not connected to QBO'], 422);
        }
        $token = $this->ensureFreshToken($agency);
        $family = DB::table('families')->where('id', $inv->family_id)->first();
        $base = (env('QBO_ENV') === 'sandbox')
            ? 'https://sandbox-quickbooks.api.intuit.com/v3/company/' . $agency->qbo_realm_id
            : 'https://quickbooks.api.intuit.com/v3/company/' . $agency->qbo_realm_id;

        // upsert customer
        $custBody = ['DisplayName' => $family->family_name];
        $custRes = Http::withToken($token)->withHeaders(['Accept' => 'application/json'])
            ->post($base . '/customer', $custBody);
        if (!$custRes->ok()) {
            return response()->json(['error' => 'customer push failed', 'body' => $custRes->body()], 502);
        }
        $customerId = $custRes->json('Customer.Id');

        // build invoice
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

    private function ensureFreshToken($agency): string
    {
        if (!empty($agency->qbo_token_expires_at) && now()->lt($agency->qbo_token_expires_at)) {
            return $agency->qbo_access_token;
        }
        $res = Http::asForm()->withBasicAuth(env('QBO_CLIENT_ID'), env('QBO_CLIENT_SECRET'))
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
        if ($activeId) return $activeId;
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
