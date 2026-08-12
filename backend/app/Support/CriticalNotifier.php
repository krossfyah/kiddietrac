<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

/**
 * Emails an immediate alert to the business owners whenever something
 * business-critical / sensitive is changed in the portal (mail routing,
 * maintenance windows, agency suspensions, etc.). Best-effort — an alert
 * failure must never block the actual change.
 *
 * Recipients default to the founders and are overridable via the
 * `critical.notify_emails` platform setting (comma-separated).
 */
class CriticalNotifier
{
    private const DEFAULT_RECIPIENTS = 'mr.anthonyhosein@gmail.com,info@kiddietrac.com';

    private static function recipients(): array
    {
        $csv = (string) PlatformSettings::get('critical.notify_emails', self::DEFAULT_RECIPIENTS);
        return array_values(array_filter(array_map('trim', explode(',', $csv)), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    /**
     * @param  string    $what   short label of the change, e.g. "Email settings updated"
     * @param  string[]  $lines  human-readable detail lines (do NOT include secrets)
     */
    public static function send(string $what, array $lines = [], ?string $actor = null): void
    {
        try {
            $to = self::recipients();
            if (! $to) {
                return;
            }
            $u = auth()->user();
            $actorName = $actor ?: (trim((string) ($u->name ?? '')) ?: (string) ($u->email ?? '')) ?: 'a user';
            $actorEmail = $u->email ?? null;
            $actorRoles = null;
            if ($u && isset($u->id)) {
                try {
                    $actorRoles = DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)
                        ->pluck('role')->unique()->implode(', ');
                } catch (\Throwable $e) {
                }
            }
            $when = now()->format('l, M j, Y \a\t g:i A T');
            $req = request();
            $ip = $req ? substr((string) $req->ip(), 0, 45) : null;
            $ua = $req ? substr((string) $req->userAgent(), 0, 220) : null;
            $path = $req ? strtoupper((string) $req->method()) . ' ' . $req->path() : null;

            // Detail table (who/when/where).
            $row = function ($k, $v) {
                return ($v === null || $v === '') ? '' :
                    '<tr><td style="padding:7px 16px 7px 0;color:#64748B;white-space:nowrap;vertical-align:top;font-size:13px;">' . e($k) . '</td>'
                    . '<td style="padding:7px 0;font-weight:600;color:#0F172A;font-size:13.5px;">' . e($v) . '</td></tr>';
            };
            $meta = '<table style="width:100%;border-collapse:collapse;margin:6px 0 4px;">'
                . $row('Change', $what)
                . $row('Changed by', $actorName)
                . $row('Account', $actorEmail)
                . $row('Role(s)', $actorRoles)
                . $row('When', $when)
                . $row('IP address', $ip)
                . $row('Request', $path)
                . $row('Device', $ua)
                . '</table>';

            $detail = count($lines)
                ? '<div style="font-weight:800;font-size:12px;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin:16px 0 6px;">What changed</div>'
                    . '<ul style="margin:0;padding-left:20px;color:#0F172A;font-size:13.5px;">'
                    . implode('', array_map(fn ($l) => '<li style="margin:4px 0;">' . e($l) . '</li>', $lines))
                    . '</ul>'
                : '';

            $slot = '<div style="background:#FEF2F2;border-left:4px solid #EF4444;border-radius:10px;padding:14px 16px;margin-bottom:14px;">'
                . '<div style="font-size:18px;font-weight:800;color:#B91C1C;">🔐 Critical change</div>'
                . '<div style="font-size:15px;color:#0F172A;margin-top:2px;">' . e($what) . '</div>'
                . '</div>'
                . '<p style="color:#334155;margin:0 0 6px;">A business-critical setting was just changed in KiddieTrac. Full details are below.</p>'
                . $meta
                . $detail
                . '<p style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:8px;padding:12px 14px;color:#9A3412;font-size:13px;margin-top:16px;">'
                . '<strong>Didn\'t make this change?</strong> Review the account\'s access and the portal audit log immediately, and rotate any exposed credentials.</p>';

            // Wrap in the branded KiddieTrac email template (official logo + footer).
            try {
                $html = View::make('emails.layout', [
                    'slot' => $slot,
                    'title' => 'Critical change · ' . $what,
                    'preheader' => $what . ' — by ' . $actorName . ' at ' . now()->format('g:i A'),
                ])->render();
            } catch (\Throwable $e) {
                // Fallback to unbranded if the template can't render — alert still goes out.
                $html = '<h2 style="color:#B91C1C;">🔐 Critical change: ' . e($what) . '</h2>' . $meta . $detail;
            }

            Mail::html($html, function ($m) use ($to, $what) {
                $first = array_shift($to);
                $m->to($first)->subject('🔐 KiddieTrac critical change: ' . $what);
                foreach ($to as $cc) {
                    $m->cc($cc);
                }
                // Always deliver — bypass the agency suppression kill-switch.
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
        } catch (\Throwable $e) {
            Log::warning('CriticalNotifier failed: ' . $e->getMessage());
        }
    }
}
