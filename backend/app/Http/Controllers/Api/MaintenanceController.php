<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Scheduled platform maintenance (super-admin). One window row at a time.
 * The login block lives in AuthController; this manages the window + the
 * heads-up emails.
 */
class MaintenanceController extends Controller
{
    /** The current window row (latest), or null. */
    public static function window(): ?object
    {
        return DB::table('maintenance_windows')->orderByDesc('id')->first();
    }

    /** Is the portal in maintenance right now? */
    public static function isDown(): bool
    {
        $w = self::window();
        if (! $w || ! $w->active) {
            return false;
        }
        $now = now();
        if ($w->starts_at && $now->lt(Carbon::parse($w->starts_at))) {
            return false;
        }
        if ($w->ends_at && $now->gt(Carbon::parse($w->ends_at))) {
            return false;
        }

        return true;
    }

    public function show(Request $request): JsonResponse
    {
        $w = self::window();

        return response()->json([
            'window' => $w ? [
                'id' => (int) $w->id,
                'starts_at' => $w->starts_at,
                'ends_at' => $w->ends_at,
                'message' => $w->message,
                'active' => (bool) $w->active,
                'notified_at' => $w->notified_at,
            ] : null,
            'is_down_now' => self::isDown(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:1000'],
            'active' => ['required', 'boolean'],
        ]);

        $row = [
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'message' => $data['message'] ?? null,
            'active' => (bool) $data['active'],
            'updated_at' => now(),
        ];

        $existing = self::window();
        if ($existing) {
            DB::table('maintenance_windows')->where('id', $existing->id)->update($row);
            $id = $existing->id;
        } else {
            $row['created_by'] = $request->user()->id ?? null;
            $row['created_at'] = now();
            $id = DB::table('maintenance_windows')->insertGetId($row);
        }

        // Business-critical change → alert the owners.
        \App\Support\CriticalNotifier::send('Scheduled maintenance window ' . ($existing ? 'updated' : 'created'), array_values(array_filter([
            isset($row['starts_at']) ? 'Starts: ' . $row['starts_at'] : null,
            isset($row['ends_at']) ? 'Ends: ' . $row['ends_at'] : null,
            isset($row['is_active']) ? 'Active: ' . ($row['is_active'] ? 'yes' : 'no') : null,
            'Portal login will be blocked during the window (platform admins exempt).',
        ])));

        return response()->json(['message' => 'Saved', 'id' => $id, 'is_down_now' => self::isDown()]);
    }

    /** Email the maintenance notice to a single test address. */
    public function sendTest(Request $request): JsonResponse
    {
        $to = $request->validate(['email' => ['required', 'email']])['email'];
        $w = self::window() ?: (object) ['starts_at' => null, 'ends_at' => null, 'message' => null];
        $ok = self::sendNotice([$to], $w, true);

        return $ok
            ? response()->json(['message' => 'Test sent to ' . $to])
            : response()->json(['message' => 'Could not send test email.'], 500);
    }

    /** Email all users the maintenance heads-up. */
    public function notifyAll(Request $request): JsonResponse
    {
        $w = self::window();
        if (! $w) {
            return response()->json(['message' => 'Set up the maintenance window first.'], 422);
        }

        $emails = DB::table('users')->whereNotNull('email')->where('email', '!=', '')
            ->distinct()->pluck('email')->all();
        $sent = 0;
        foreach (array_chunk($emails, 40) as $chunk) {
            if (self::sendNotice($chunk, $w, false)) {
                $sent += count($chunk);
            }
        }
        DB::table('maintenance_windows')->where('id', $w->id)->update(['notified_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => "Heads-up emailed to {$sent} address(es)."]);
    }

    /**
     * Send the branded maintenance notice. Bcc's the batch (recipients don't see
     * each other) and carries the suppression-bypass header — a downtime notice
     * is operational, so it must reach everyone regardless of agency toggles.
     *
     * @param string[] $to
     */
    private static function sendNotice(array $to, object $w, bool $isTest): bool
    {
        $to = array_values(array_filter($to));
        if (! $to) {
            return false;
        }

        $fmt = function ($v) {
            if (! $v) {
                return null;
            }
            try { return Carbon::parse($v)->format('D, M j, Y \a\t g:i A'); } catch (\Throwable $e) { return (string) $v; }
        };
        $start = $fmt($w->starts_at ?? null);
        $end = $fmt($w->ends_at ?? null);
        $when = $start ? ('<p><strong>When:</strong> ' . e($start) . ($end ? ' &ndash; ' . e($end) : '') . '</p>') : '';
        $msg = ! empty($w->message) ? '<p style="background:#EEF1F8;border-left:3px solid #3BBBBE;padding:14px 16px;border-radius:6px;">' . nl2br(e($w->message)) . '</p>' : '';

        // Render through the shared branded layout (official logo + privacy/terms
        // footer). Sends from the configured noreply address.
        $content = '<h1>🛠️ Scheduled maintenance</h1>'
            . ($isTest ? '<p style="background:#FEF3C7;color:#92400E;border-radius:8px;padding:10px 14px;font-size:14px;">This is a <strong>test</strong> of the maintenance notice.</p>' : '')
            . '<p>Hello,</p>'
            . '<p>KiddieTrac will be temporarily unavailable for scheduled maintenance. During this time you won\'t be able to sign in. We\'re sorry for any inconvenience.</p>'
            . $when
            . $msg;

        try {
            $html = view('emails.layout', [
                'slot' => $content,
                'title' => 'Scheduled maintenance' . ($isTest ? ' (test)' : ''),
                'preheader' => 'KiddieTrac scheduled-maintenance notice',
            ])->render();
        } catch (\Throwable $e) {
            Log::warning('Maintenance layout render failed: ' . $e->getMessage());

            return false;
        }

        try {
            Mail::html($html, function ($m) use ($to, $isTest) {
                // Operational notice — always deliver, from the noreply address.
                $m->from(config('mail.from.address', 'noreply@kiddietrac.com'), config('mail.from.name', 'KiddieTrac'));
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                $first = array_shift($to);
                $m->to($first)->subject('KiddieTrac — scheduled maintenance' . ($isTest ? ' (test)' : ''));
                foreach ($to as $bcc) {
                    $m->bcc($bcc);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Maintenance notice send failed: ' . $e->getMessage());

            return false;
        }

        return true;
    }
}
