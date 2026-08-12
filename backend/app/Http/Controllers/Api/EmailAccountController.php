<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Email Client — connected mail accounts (the Outlook-style setup wizard).
 * Accounts are per-user; secrets are Crypt-encrypted and never returned.
 */
class EmailAccountController extends Controller
{
    /** Known providers → sensible IMAP/SMTP defaults so the wizard can prefill. */
    private function providerDefaults(string $provider): array
    {
        return [
            'microsoft' => ['imap_host' => 'outlook.office365.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls'],
            'google'    => ['imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls'],
        ][$provider] ?? [];
    }

    private function agencyId(Request $request): ?int
    {
        $uid = $request->user()->id;
        $h = (int) $request->header('X-Active-Agency-Id');
        // Honour the header only if the user actually belongs to it; else their own.
        if ($h && DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where('agency_id', $h)->exists()) {
            return $h;
        }
        return DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereNotNull('agency_id')->value('agency_id') ?: null;
    }

    /** Shape a row for the client — NEVER leak the secret. */
    private function present($a): array
    {
        return [
            'id' => $a->id,
            'display_name' => $a->display_name,
            'email_address' => $a->email_address,
            'provider' => $a->provider,
            'imap_host' => $a->imap_host, 'imap_port' => $a->imap_port, 'imap_encryption' => $a->imap_encryption,
            'smtp_host' => $a->smtp_host, 'smtp_port' => $a->smtp_port, 'smtp_encryption' => $a->smtp_encryption,
            'username' => $a->username,
            'has_secret' => ! empty($a->secret),
            'is_default' => (bool) $a->is_default,
            'status' => $a->status,
            'status_detail' => $a->status_detail,
            'signature_html' => $a->signature_html,
            'signature_enabled' => (bool) $a->signature_enabled,
            'ooo_enabled' => (bool) $a->ooo_enabled,
            'ooo_subject' => $a->ooo_subject,
            'ooo_message' => $a->ooo_message,
            'ooo_start' => $a->ooo_start,
            'ooo_end' => $a->ooo_end,
        ];
    }

    /** GET /email/accounts */
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('email_accounts')->where('user_id', $request->user()->id)
            ->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('id')->get();
        return response()->json(['accounts' => $rows->map(fn ($a) => $this->present($a))->values()]);
    }

    /** POST /email/accounts — the wizard's finish step. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name'    => ['nullable', 'string', 'max:120'],
            'email_address'   => ['required', 'email', 'max:190'],
            'provider'        => ['required', 'in:microsoft,google,imap,other'],
            'username'        => ['nullable', 'string', 'max:190'],
            'secret'          => ['nullable', 'string', 'max:2000'],
            'imap_host'       => ['nullable', 'string', 'max:190'],
            'imap_port'       => ['nullable', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['nullable', 'in:ssl,tls,none'],
            'smtp_host'       => ['nullable', 'string', 'max:190'],
            'smtp_port'       => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['nullable', 'in:ssl,tls,none'],
            'is_default'      => ['nullable', 'boolean'],
            'signature_html'  => ['nullable', 'string', 'max:20000'],
        ]);

        $defaults = $this->providerDefaults($data['provider']);
        $uid = $request->user()->id;
        $first = ! DB::table('email_accounts')->where('user_id', $uid)->whereNull('deleted_at')->exists();

        $row = [
            'user_id'         => $uid,
            'agency_id'       => $this->agencyId($request),
            'display_name'    => $data['display_name'] ?? null,
            'email_address'   => $data['email_address'],
            'provider'        => $data['provider'],
            'imap_host'       => $data['imap_host'] ?? ($defaults['imap_host'] ?? null),
            'imap_port'       => $data['imap_port'] ?? ($defaults['imap_port'] ?? null),
            'imap_encryption' => $data['imap_encryption'] ?? ($defaults['imap_encryption'] ?? null),
            'smtp_host'       => $data['smtp_host'] ?? ($defaults['smtp_host'] ?? null),
            'smtp_port'       => $data['smtp_port'] ?? ($defaults['smtp_port'] ?? null),
            'smtp_encryption' => $data['smtp_encryption'] ?? ($defaults['smtp_encryption'] ?? null),
            'username'        => $data['username'] ?? $data['email_address'],
            'secret'          => ! empty($data['secret']) ? Crypt::encryptString($data['secret']) : null,
            'is_default'      => $data['is_default'] ?? $first,   // first account is default
            'status'          => 'pending',
            'signature_html'  => $data['signature_html'] ?? null,
            'created_at'      => now(), 'updated_at' => now(),
        ];
        $id = DB::table('email_accounts')->insertGetId($row);
        if ($row['is_default']) {
            DB::table('email_accounts')->where('user_id', $uid)->where('id', '!=', $id)->update(['is_default' => false]);
        }
        $a = DB::table('email_accounts')->find($id);
        return response()->json(['account' => $this->present($a)], 201);
    }

    /** PATCH /email/accounts/{id} — settings, signature, OOO, default. */
    public function update(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where('user_id', $request->user()->id)
            ->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate([
            'display_name'      => ['sometimes', 'nullable', 'string', 'max:120'],
            'username'          => ['sometimes', 'nullable', 'string', 'max:190'],
            'secret'            => ['sometimes', 'nullable', 'string', 'max:2000'],
            'imap_host'         => ['sometimes', 'nullable', 'string', 'max:190'],
            'imap_port'         => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'imap_encryption'   => ['sometimes', 'nullable', 'in:ssl,tls,none'],
            'smtp_host'         => ['sometimes', 'nullable', 'string', 'max:190'],
            'smtp_port'         => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption'   => ['sometimes', 'nullable', 'in:ssl,tls,none'],
            'is_default'        => ['sometimes', 'boolean'],
            'signature_html'    => ['sometimes', 'nullable', 'string', 'max:20000'],
            'signature_enabled' => ['sometimes', 'boolean'],
            'ooo_enabled'       => ['sometimes', 'boolean'],
            'ooo_subject'       => ['sometimes', 'nullable', 'string', 'max:190'],
            'ooo_message'       => ['sometimes', 'nullable', 'string', 'max:20000'],
            'ooo_start'         => ['sometimes', 'nullable', 'date'],
            'ooo_end'           => ['sometimes', 'nullable', 'date'],
        ]);

        $upd = [];
        foreach (['display_name', 'username', 'imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption', 'is_default', 'signature_html', 'signature_enabled', 'ooo_enabled', 'ooo_subject', 'ooo_message', 'ooo_start', 'ooo_end'] as $k) {
            if (array_key_exists($k, $data)) $upd[$k] = $data[$k];
        }
        // Only overwrite the secret when a new non-empty one is supplied.
        if (array_key_exists('secret', $data) && ! empty($data['secret'])) {
            $upd['secret'] = Crypt::encryptString($data['secret']);
        }
        $upd['updated_at'] = now();
        DB::table('email_accounts')->where('id', $id)->update($upd);

        if (($data['is_default'] ?? false) === true) {
            DB::table('email_accounts')->where('user_id', $request->user()->id)->where('id', '!=', $id)->update(['is_default' => false]);
        }
        $a = DB::table('email_accounts')->find($id);
        return response()->json(['account' => $this->present($a)]);
    }

    /** DELETE /email/accounts/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where('user_id', $request->user()->id)
            ->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);
        DB::table('email_accounts')->where('id', $id)->update(['deleted_at' => now(), 'is_default' => false]);
        // Promote another account to default if we removed the default one.
        if ($a->is_default) {
            $next = DB::table('email_accounts')->where('user_id', $request->user()->id)->whereNull('deleted_at')->orderBy('id')->first();
            if ($next) DB::table('email_accounts')->where('id', $next->id)->update(['is_default' => true]);
        }
        return response()->json(['ok' => true]);
    }

    /**
     * POST /email/accounts/{id}/test — reachability check of the configured IMAP +
     * SMTP hosts (a real TCP connect, not a claim of authenticated success). Updates
     * status so the account list can show a live badge.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where('user_id', $request->user()->id)
            ->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);

        $checks = [];

        // IMAP: a REAL sign-in test (imap_open) when the extension is available —
        // this validates the saved password, not just server reachability.
        if ($a->imap_host && $a->imap_port && function_exists('imap_open')) {
            $secret = '';
            try { $secret = $a->secret ? Crypt::decryptString($a->secret) : ''; } catch (\Throwable $e) {}
            if ($secret === '') {
                $checks['IMAP'] = 'no password saved';
            } else {
                $enc = $a->imap_encryption === 'ssl' ? '/imap/ssl/novalidate-cert'
                    : ($a->imap_encryption === 'tls' ? '/imap/tls/novalidate-cert' : '/imap/notls');
                imap_timeout(IMAP_OPENTIMEOUT, 10);
                imap_errors();
                $s = @imap_open('{' . $a->imap_host . ':' . ((int) $a->imap_port) . $enc . '}INBOX', $a->username ?: $a->email_address, $secret, 0, 1);
                if ($s) { $checks['IMAP'] = 'signed in'; @imap_close($s); }
                else { $checks['IMAP'] = 'sign-in failed (' . (imap_last_error() ?: 'check password / app password') . ')'; }
            }
        } else {
            // Fallback: plain TCP reachability.
            $errno = 0; $errstr = '';
            $conn = $a->imap_host ? @fsockopen($a->imap_encryption === 'ssl' ? "ssl://{$a->imap_host}" : $a->imap_host, (int) $a->imap_port ?: 143, $errno, $errstr, 6) : false;
            $checks['IMAP'] = $conn ? (fclose($conn) ? 'reachable' : 'reachable') : ('unreachable (' . ($errstr ?: 'no route') . ')');
        }

        // SMTP: TCP reachability (a full auth test needs a send attempt).
        if ($a->smtp_host && $a->smtp_port) {
            $errno = 0; $errstr = '';
            $conn = @fsockopen($a->smtp_host, (int) $a->smtp_port, $errno, $errstr, 6);
            $checks['SMTP'] = $conn ? (fclose($conn) ? 'reachable' : 'reachable') : ('unreachable (' . ($errstr ?: 'no route') . ')');
        } else {
            $checks['SMTP'] = 'not configured';
        }

        $imapOk = in_array($checks['IMAP'] ?? '', ['signed in', 'reachable'], true);
        $smtpOk = ! str_starts_with($checks['SMTP'] ?? '', 'unreachable');
        $ok = $imapOk && $smtpOk;
        DB::table('email_accounts')->where('id', $id)->update([
            'status' => $ok ? 'connected' : 'error',
            'status_detail' => collect($checks)->map(fn ($v, $k) => "$k: $v")->implode(' · '),
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => $ok, 'checks' => $checks]);
    }
}
