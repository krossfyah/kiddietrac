<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Backups. PLATFORM ADMIN ONLY, and deliberately so.
 *
 * There is ONE database behind every agency on this platform, so a backup is not an
 * agency's own data — it is everybody's. An agency admin managing their own centre has no
 * business setting the retention on a file that contains every other agency's children.
 *
 * NOTHING HERE SERVES A DUMP. The screen lists names, sizes and dates; it will not let
 * anyone download one through the browser, and there is no route that could. A 7 MB gzip
 * of this database is every child, every guardian, every address and every medical note
 * on the platform, and a signed URL for it is a capability nobody should be able to
 * forward, cache or leave in a downloads folder. Restores are done over SSH, by a person,
 * on purpose. The command's own docblock carries the one-line restore.
 */
class BackupController extends Controller
{
    private function assertPlatformAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)->where('role', 'platform_admin')->exists();

        abort_unless($ok, 403, 'Only a platform administrator can manage database backups.');
    }

    private function dir(): string
    {
        return base_path('../backups');
    }

    /** GET /admin/backups — status, settings, and what is actually on disk. */
    public function index(Request $request): JsonResponse
    {
        $this->assertPlatformAdmin($request);

        $files = glob($this->dir() . '/kiddietrac-*.sql.gz') ?: [];
        rsort($files);

        $list = [];
        $total = 0;
        foreach ($files as $f) {
            $size = (int) @filesize($f);
            $total += $size;
            $list[] = [
                'name' => basename($f),
                'size_mb' => round($size / 1048576, 2),
                'taken_at' => date('Y-m-d H:i:s', (int) @filemtime($f)),
            ];
        }

        $lastOk = (string) PlatformSettings::get('backup.last_ok_at', '');
        $hoursOld = null;
        if ($lastOk !== '') {
            try { $hoursOld = round(now()->diffInMinutes(\Illuminate\Support\Carbon::parse($lastOk)) / 60, 1); }
            catch (\Throwable $e) {}
        }

        return response()->json([
            'settings' => [
                'enabled' => PlatformSettings::get('backup.enabled', '1') !== '0',
                'time' => (string) PlatformSettings::get('backup.time', '03:30'),
                'keep' => (int) PlatformSettings::get('backup.keep', 14),
            ],
            'status' => [
                'last_ok_at' => $lastOk ?: null,
                'hours_since' => $hoursOld,
                'last_size_mb' => PlatformSettings::get('backup.last_size_mb') !== null
                    ? (float) PlatformSettings::get('backup.last_size_mb') : null,
                'last_error' => PlatformSettings::get('backup.last_error') ?: null,
                'on_disk' => count($list),
                'total_mb' => round($total / 1048576, 1),
                'writable' => is_dir($this->dir()) && is_writable($this->dir()),
                /* Said out loud: the whole schedule depends on one crontab line, and if it
                   stops nothing else will notice. */
                'scheduler_seen_at' => $this->schedulerLastRan(),
            ],
            'backups' => array_slice($list, 0, 30),
        ]);
    }

    /**
     * Is the per-minute cron still alive?
     *
     * A backup job that is configured perfectly and never runs looks identical, from this
     * screen, to one that is working — until the day somebody needs a restore. The
     * scheduler writes to its log every minute, so the log's mtime is the cheapest honest
     * answer available without adding another moving part.
     */
    private function schedulerLastRan(): ?string
    {
        $log = storage_path('logs/scheduler.log');
        if (! is_file($log)) {
            return null;
        }

        return date('Y-m-d H:i:s', (int) @filemtime($log));
    }

    /** POST /admin/backups/settings */
    public function update(Request $request): JsonResponse
    {
        $this->assertPlatformAdmin($request);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            // A real clock time; the scheduler reads this to decide when to fire.
            'time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            // 90 days of 7 MB is ~630 MB. The ceiling is the disk, not a preference.
            'keep' => 'required|integer|min:1|max:90',
        ]);

        $before = [
            'enabled' => PlatformSettings::get('backup.enabled', '1'),
            'time' => PlatformSettings::get('backup.time', '03:30'),
            'keep' => PlatformSettings::get('backup.keep', 14),
        ];

        PlatformSettings::set('backup.enabled', $data['enabled'] ? '1' : '0');
        PlatformSettings::set('backup.time', $data['time']);
        PlatformSettings::set('backup.keep', (string) $data['keep']);

        $this->audit($request, 'platform.backup_settings_changed', [
            'summary' => 'Database backup settings changed: '
                . ($data['enabled'] ? 'ON' : 'OFF') . ', daily at ' . $data['time']
                . ', keeping ' . $data['keep'] . ' copies'
                . ' (was ' . ($before['enabled'] === '0' ? 'OFF' : 'ON') . ', '
                . $before['time'] . ', keeping ' . $before['keep'] . ').'
                . ($data['enabled'] ? '' : ' WITH BACKUPS OFF THERE IS NO NIGHTLY COPY OF THIS DATABASE.'),
            'before' => $before,
            'after' => $data,
        ]);

        return $this->index($request);
    }

    /**
     * POST /admin/backups/run — take one now.
     *
     * Synchronous on purpose: the person pressing it wants to know whether it WORKED, and
     * a queued job would answer "accepted" whether or not mysqldump could even start. The
     * command caps itself at 600s and the dump currently takes a few seconds.
     */
    public function run(Request $request): JsonResponse
    {
        $this->assertPlatformAdmin($request);

        $code = Artisan::call('db:backup');
        $out = trim(Artisan::output());

        $this->audit($request, 'platform.backup_run_manually', [
            'summary' => 'A database backup was taken by hand from the portal. Result: '
                . ($code === 0 ? 'succeeded' : 'FAILED') . '. ' . substr($out, 0, 300),
            'exit_code' => $code,
        ]);

        return response()->json([
            'ok' => $code === 0,
            'output' => $out !== '' ? $out : null,
        ], $code === 0 ? 200 : 422);
    }

    private function audit(Request $request, string $action, array $payload): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => optional($request->user())->id,
                /* Platform-level on purpose: this is not any one agency's event. */
                'agency_id' => null,
                'action' => $action,
                'entity_type' => 'platform',
                'entity_id' => null,
                'payload' => json_encode($payload),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
