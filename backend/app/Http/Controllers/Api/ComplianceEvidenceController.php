<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → Compliance evidence (platform admin) — SOC 2 Type I readiness (2026-09-29).
 *
 * The report itself comes from an independent CPA firm. What an auditor asks for first is
 * (a) the written policies and control matrix and (b) evidence that the controls exist
 * and run. This controller serves both, read-only, from live data:
 *   - summary: MFA coverage, admin accounts, logins/failures, backups, alerts, TLS, jobs
 *   - CSV exports: access review, authentication/security events, backups, role changes
 *   - text exports: the scheduler's job list, recent code changes (git)
 *   - the policy documents (resources/compliance/soc2/*.md)
 *   - quarterly access reviews: a reviewer signs off a snapshot of every admin account
 * Every export is itself audited (compliance.evidence_exported).
 */
class ComplianceEvidenceController extends Controller
{
    private const ADMIN_ROLES = ['platform_admin', 'agency_admin', 'centre_director', 'auditor'];

    public function summary(Request $request): JsonResponse
    {
        $this->assertPlatformAdmin($request);
        $since = now()->subDays(30);
        $admins = $this->adminRows();
        $users = DB::table('users')->whereNull('deleted_at');
        $backupDir = base_path('../backups');
        $files = glob($backupDir . '/kiddietrac-*.sql.gz') ?: [];
        rsort($files);
        $lastReview = DB::table('access_reviews')->orderByDesc('reviewed_at')->first();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'mfa' => [
                'admin_rows' => $admins->count(),
                'admin_with_mfa' => $admins->where('two_factor_enabled', 1)->count(),
                'admin_with_passkey' => $admins->where('passkeys', '>', 0)->count(),
                'users' => (clone $users)->count(),
                'users_with_mfa' => (clone $users)->where('two_factor_enabled', 1)->count(),
            ],
            'admins_never_signed_in' => $admins->whereNull('last_login_at')->count(),
            'admins_idle_90d' => $admins->filter(fn ($a) => $a->last_login_at && $a->last_login_at < now()->subDays(90)->toDateTimeString())->count(),
            'auth_30d' => DB::table('audit_logs')->where('created_at', '>=', $since)
                ->whereIn('action', ['login', 'login_failed', 'mfa_failed', 'security.login_unlocked', 'password_changed'])
                ->selectRaw('action, COUNT(*) as n')->groupBy('action')->pluck('n', 'action'),
            'security_alerts_30d' => DB::table('audit_logs')->where('created_at', '>=', $since)->where('action', 'like', 'security.%')->count(),
            'audit_log' => ['rows' => DB::table('audit_logs')->count(), 'since' => DB::table('audit_logs')->min('created_at')],
            'backups' => [
                'on_disk' => count($files),
                'latest' => $files ? date('Y-m-d H:i', (int) filemtime($files[0])) : null,
                'last_ok_at' => PlatformSettings::get('backup.last_ok_at') ?: null,
                'last_error' => PlatformSettings::get('backup.last_error') ?: null,
                'offsite' => false,
            ],
            'tls' => [
                'app' => $this->certExpiry('app.kiddietrac.com'),
                'api' => $this->certExpiry('api.kiddietrac.com'),
            ],
            'tokens' => [
                'active' => DB::table('personal_access_tokens')->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })->count(),
                'without_expiry' => DB::table('personal_access_tokens')->whereNull('expires_at')->count(),
            ],
            'access_reviews' => [
                'count' => DB::table('access_reviews')->count(),
                'last' => $lastReview ? ['reviewed_at' => $lastReview->reviewed_at, 'reviewer' => $lastReview->reviewer_name, 'admins' => $lastReview->admin_count] : null,
                'due' => ! $lastReview || $lastReview->reviewed_at < now()->subDays(92)->toDateTimeString(),
            ],
            'documents' => $this->documents(),
        ]);
    }

    /* ── exports ── */

    public function accessReviewCsv(Request $request): Response
    {
        $this->assertPlatformAdmin($request);
        $rows = $this->adminRows();
        $this->audited($request, 'access-review.csv', $rows->count());

        return $this->csv('access-review-' . now()->format('Y-m-d') . '.csv',
            ['User ID', 'Name', 'Email', 'Role', 'Agency', 'Centre', 'Active', 'Role granted', 'Last sign-in', 'Last sign-in IP', 'MFA', 'Passkeys', 'Password changed', 'Must change password', 'Account status'],
            $rows->map(fn ($r) => [$r->user_id, $r->name, $r->email, $r->role, $r->agency, $r->centre, $r->active ? 'yes' : 'no', $r->granted_at,
                $r->last_login_at ?: 'never', $r->last_login_ip, $r->two_factor_enabled ? 'yes' : 'no', $r->passkeys, $r->password_changed_at,
                $r->must_change_password ? 'yes' : 'no', $r->status])->all());
    }

    public function authEventsCsv(Request $request): Response
    {
        $this->assertPlatformAdmin($request);
        $days = max(1, min(365, (int) $request->query('days', 90)));
        $rows = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.created_at', '>=', now()->subDays($days))
            ->where(function ($q) {
                $q->whereIn('a.action', ['login', 'login_failed', 'logout', 'mfa_failed', 'mfa_enabled', 'mfa_disabled', 'password_changed', 'password_reset'])
                  ->orWhere('a.action', 'like', 'security.%')->orWhere('a.action', 'like', 'role.%')->orWhere('a.action', 'like', 'settings.%');
            })
            ->orderBy('a.id')->limit(100000)
            ->get(['a.created_at', 'a.action', 'a.user_id', 'u.email', 'a.agency_id', 'a.ip_address', 'a.user_agent', 'a.payload']);
        $this->audited($request, 'auth-events.csv', $rows->count(), ['days' => $days]);

        return $this->csv('security-events-' . $days . 'd-' . now()->format('Y-m-d') . '.csv',
            ['When (UTC)', 'Action', 'User ID', 'User email', 'Agency ID', 'IP', 'User agent', 'Detail'],
            $rows->map(function ($r) {
                $p = json_decode((string) $r->payload, true);
                $detail = is_array($p) ? (string) ($p['summary'] ?? $p['reason'] ?? $p['email'] ?? '') : '';
                return [$r->created_at, $r->action, $r->user_id, $r->email, $r->agency_id, $r->ip_address, mb_substr((string) $r->user_agent, 0, 160), mb_substr($detail, 0, 300)];
            })->all());
    }

    public function backupsCsv(Request $request): Response
    {
        $this->assertPlatformAdmin($request);
        $files = glob(base_path('../backups') . '/kiddietrac-*.sql.gz') ?: [];
        rsort($files);
        $this->audited($request, 'backups.csv', count($files));

        return $this->csv('backups-' . now()->format('Y-m-d') . '.csv', ['File', 'Taken (server time)', 'Size (MB)', 'Integrity (gzip header)'],
            array_map(function ($f) {
                $h = @fopen($f, 'rb');
                $magic = $h ? bin2hex((string) fread($h, 2)) : '';
                if ($h) fclose($h);
                return [basename($f), date('Y-m-d H:i:s', (int) filemtime($f)), round(filesize($f) / 1048576, 2), $magic === '1f8b' ? 'ok' : 'NOT GZIP'];
            }, $files));
    }

    public function scheduleTxt(Request $request): Response
    {
        $this->assertPlatformAdmin($request);
        try {
            Artisan::call('schedule:list');
            $out = Artisan::output();
        } catch (\Throwable $e) {
            $out = 'Could not list the schedule: ' . $e->getMessage();
        }
        $this->audited($request, 'schedule.txt', substr_count($out, "\n"));

        return new Response("KiddieTrac scheduled jobs — generated " . now()->toDateTimeString() . " UTC\n\n" . $out, 200,
            ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="scheduled-jobs-' . now()->format('Y-m-d') . '.txt"']);
    }

    public function changesTxt(Request $request): Response
    {
        $this->assertPlatformAdmin($request);
        $days = max(1, min(365, (int) $request->query('days', 90)));
        $repo = realpath(base_path('..'));
        $out = null;
        if (function_exists('proc_open') && $repo) {
            $p = @proc_open(['git', '-C', $repo, 'log', '--since=' . $days . '.days', '--date=iso', '--pretty=format:%h | %ad | %an | %s'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($p)) {
                $out = stream_get_contents($pipes[1]);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
            }
        }
        $out = $out ?: 'The git history could not be read from the web process on this host. Export it over SSH with: git log --since=' . $days . '.days --date=iso';
        $this->audited($request, 'changes.txt', substr_count($out, "\n") + 1, ['days' => $days]);

        return new Response("KiddieTrac code changes, last {$days} days — generated " . now()->toDateTimeString() . " UTC\n\n" . $out . "\n", 200,
            ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="code-changes-' . $days . 'd-' . now()->format('Y-m-d') . '.txt"']);
    }

    public function document(Request $request, string $file): Response
    {
        $this->assertPlatformAdmin($request);
        $path = resource_path('compliance/soc2/' . basename($file));
        abort_unless(preg_match('/^[0-9a-z-]+\.md$/', basename($file)) && is_file($path), 404);
        $this->audited($request, 'document:' . basename($file), 1);

        return new Response((string) file_get_contents($path), 200, ['Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="KiddieTrac-SOC2-' . basename($file) . '"']);
    }

    /* ── access reviews ── */

    public function reviews(Request $request): JsonResponse
    {
        $this->assertPlatformAdmin($request);
        return response()->json(['reviews' => DB::table('access_reviews')->orderByDesc('reviewed_at')->limit(40)
            ->get(['id', 'reviewed_at', 'reviewer_name', 'admin_count', 'findings', 'actions_taken'])]);
    }

    public function recordReview(Request $request): JsonResponse
    {
        $this->assertPlatformAdmin($request);
        $data = $request->validate([
            'findings' => ['required', 'string', 'max:4000'],
            'actions_taken' => ['nullable', 'string', 'max:4000'],
            'confirm' => ['accepted'],
        ]);
        $rows = $this->adminRows();
        $u = $request->user();
        $id = DB::table('access_reviews')->insertGetId([
            'reviewed_at' => now(), 'reviewer_id' => (int) $u->id,
            'reviewer_name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email,
            'admin_count' => $rows->count(), 'snapshot' => json_encode($rows->values(), JSON_UNESCAPED_UNICODE),
            'findings' => $data['findings'], 'actions_taken' => $data['actions_taken'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Support\Audit::write(['user_id' => (int) $u->id, 'agency_id' => null, 'action' => 'compliance.access_review',
            'entity_type' => 'access_review', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Access review signed off (' . $rows->count() . ' admin role rows)']), 'created_at' => now()]);

        return response()->json(['id' => $id], 201);
    }

    /* ── helpers ── */

    private function adminRows()
    {
        return DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('agencies as a', 'a.id', '=', 'r.agency_id')->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')
            ->whereIn('r.role', self::ADMIN_ROLES)->where('r.active', 1)->whereNull('u.deleted_at')
            ->orderBy('r.role')->orderBy('u.last_name')
            ->get(['r.user_id', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as name"), 'u.email', 'r.role',
                'a.name as agency', 'c.name as centre', 'r.active', 'r.created_at as granted_at', 'u.last_login_at', 'u.last_login_ip',
                'u.two_factor_enabled', 'u.password_changed_at', 'u.must_change_password', 'u.status',
                DB::raw('(SELECT COUNT(*) FROM user_passkeys p WHERE p.user_id = u.id) as passkeys')]);
    }

    private function certExpiry(string $host): ?array
    {
        try {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
            $c = @stream_socket_client('ssl://' . $host . ':443', $errno, $err, 6, STREAM_CLIENT_CONNECT, $ctx);
            if (! $c) return null;
            $cert = stream_context_get_params($c)['options']['ssl']['peer_certificate'] ?? null;
            fclose($c);
            $info = $cert ? openssl_x509_parse($cert) : null;
            if (! $info) return null;
            $to = (int) $info['validTo_time_t'];
            return ['expires' => date('Y-m-d', $to), 'days_left' => (int) floor(($to - time()) / 86400), 'issuer' => $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? '')];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function documents(): array
    {
        $out = [];
        foreach (glob(resource_path('compliance/soc2/*.md')) ?: [] as $f) {
            $first = '';
            $h = @fopen($f, 'r');
            while ($h && ($line = fgets($h)) !== false) { if (str_starts_with($line, '# ')) { $first = trim(substr($line, 2)); break; } }
            if ($h) fclose($h);
            $out[] = ['file' => basename($f), 'title' => $first ?: basename($f), 'updated' => date('Y-m-d', (int) filemtime($f))];
        }
        return $out;
    }

    private function csv(string $name, array $head, array $rows): Response
    {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $head);
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn ($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v, $r));
        }
        rewind($fh);

        return new Response("\xEF\xBB\xBF" . stream_get_contents($fh), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"']);
    }

    private function audited(Request $request, string $what, int $count, array $extra = []): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'compliance.evidence_exported',
                'entity_type' => 'evidence', 'entity_id' => null,
                'payload' => json_encode(['summary' => 'Exported ' . $what . ' (' . $count . ' rows)'] + $extra), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }

    private function assertPlatformAdmin(Request $request): void
    {
        abort_unless(DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', 1)->exists(), 403);
    }
}
