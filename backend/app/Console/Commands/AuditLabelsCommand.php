<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AuditLabels;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Lists every API write route that has no sentence in resources/audit-labels.txt (2026-10-20).
 * Such a route still shows a readable generated label in the audit log, but a person should
 * write the real one: run this after adding routes, and add the line it prints.
 *
 * COVERAGE (2026-10-21): it also fails when a SIGNED-OUT write route would leave no audit
 * row. AuditActivity audits every signed-in write, but nothing signed out - so each such
 * route must audit itself in its controller, be listed in AuditActivity::GUEST, or be named
 * in UNAUDITED below with the reason. Anthony found a password reset missing from the log;
 * this makes the next gap fail loudly instead of being found by a person.
 */
final class AuditLabelsCommand extends Command
{
    protected $signature = 'kt:audit-labels {--quiet-ok : print nothing when complete} {--labels-only : skip the signed-out coverage check}';

    /** Signed-out write routes that deliberately write no audit row, and why. */
    private const UNAUDITED = [
        'api/stripe/parent-webhook'               => 'payment provider webhook: recorded in payment logs',
        'api/v1/stripe/webhook'                   => 'payment provider webhook: recorded in payment logs',
        'api/v1/zumrails/webhook'                 => 'payment provider webhook: recorded in payment logs',
        'api/v1/sms/inbound'                      => 'carrier webhook: every text is in the SMS log',
        'api/v1/sms/telnyx/inbound/{agency}'      => 'carrier webhook: every text is in the SMS log',
        'api/v1/voice/telnyx/webhook/{agency}'    => 'carrier webhook: every call is in the voice log',
        'api/v1/voice/dialer/hook/{id}/{token?}/{step?}' => 'carrier call steps for a portal call: placing it (call.placed), playing a recording and changing settings are audited; the steps update the call log',
        'api/v1/voice/voicemail/{token}'          => 'carrier webhook: voicemail is stored with the support ticket',
        'api/v1/walkie/device/heard'      => 'the phone listener saying it played a walkie-talkie talk (no change to any record); a busy reply is audited as walkie.busy',
        'api/v1/pickup-pass/view'                 => 'a temporary pickup person reads their own pass (no change)',
        'api/v1/pickup-pass/sign-out'             => 'audited in App\\Support\\TempPickups as temp_pickup.used / temp_pickup.alert',
        'api/v1/voice/ivr/{token}/start'          => 'carrier call step: every call to the line begins here (a recognised parent is audited as phone_line.call)',
        'api/v1/voice/ivr/{token}/parent/{step}'  => 'carrier call step for the parent line (verify, report and message are each audited as phone_line.*)',
        'api/v1/voice/ivr/{token}/screen'         => 'carrier call-leg step: a staff phone being offered the call (the choice and outcome are audited)',
        'api/v1/voice/ivr/{token}/screen-ok'      => 'carrier call-leg step: a staff phone accepting (the outcome is audited in /dialed)',
        'api/v1/voice/ivr/{token}/recorded'       => 'carrier callback: a call recording is ready (saved and transcribed by kt:call-transcripts; no person acts)',
        'api/v1/auth/passkey/options'             => 'issues a challenge, changes nothing',
        'api/v1/auth/reset/check'                 => 'says whether a reset link is still good, changes nothing (POST keeps the token out of URLs); the reset and a resend are audited',
        'api/v1/forms/fill/{form}/{u}/draft'      => 'autosave of a draft; the submission itself is audited',
        'api/v1/login'                            => 'redirect only',
        'storage/{path}'                          => 'framework file route',
    ];
    /** Whole namespaces of visitor or diagnostic traffic (not a person's action in an agency). */
    private const UNAUDITED_PREFIX = ['api/v1/marketing-site/' => 'website visitors', 'api/v1/diag/' => 'browser diagnostics'];

    protected $description = 'List API write routes missing a plain-English audit label';

    public function handle(): int
    {
        $missing = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }
            foreach ($r->methods() as $m) {
                if (! in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                if (! AuditLabels::has($m, $uri)) {
                    $missing[] = strtolower($m) . ' ' . preg_replace('#^api/v1/#', '', $uri)
                        . ' | ' . AuditLabels::generated(strtolower($m), $uri) . '   <- generated, write a real one';
                }
            }
        }
        $missing = array_values(array_unique($missing));
        $uncovered = $this->option('labels-only') ? [] : $this->signedOutWithoutAudit();
        if ($uncovered) {
            $this->error(count($uncovered) . ' signed-out write route(s) would leave NO audit row:');
            foreach ($uncovered as $line) {
                $this->line('  ' . $line);
            }
            $this->line('  -> audit it in the controller, add it to AuditActivity::GUEST, or list it in UNAUDITED with the reason.');
        }
        if (! $missing && ! $uncovered) {
            if (! $this->option('quiet-ok')) {
                $this->info('Every API write route has an audit label.');
            }

            return self::SUCCESS;
        }
        if ($missing) {
            $this->warn(count($missing) . ' write route(s) have no audit label in resources/audit-labels.txt:');
            foreach ($missing as $line) {
                $this->line('  ' . $line);
            }
        }

        return self::FAILURE;
    }

    /** Signed-out write routes with no controller audit, no GUEST entry and no stated reason. */
    private function signedOutWithoutAudit(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            $writes = array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
            if (! $writes) {
                continue;
            }
            $mw = implode(' ', array_map('strval', $r->gatherMiddleware()));
            if (stripos($mw, 'auth') !== false) {
                continue;                               // AuditActivity covers it
            }
            if (isset(self::UNAUDITED[$uri])) {
                continue;
            }
            foreach (self::UNAUDITED_PREFIX as $pre => $why) {
                if (str_starts_with($uri, $pre)) {
                    continue 2;
                }
            }
            foreach (array_keys(\App\Http\Middleware\AuditActivity::GUEST) as $re) {
                if (preg_match($re, preg_replace('/\{[^}]+\}/', '1', $uri))) {   // "1" fits both (\d+) and ([^/]+)
                    continue 2;
                }
            }
            if ($this->controllerAudits($r->getActionName())) {
                continue;
            }
            $out[] = implode('|', $writes) . ' ' . $uri . '  (' . $r->getActionName() . ')';
        }
        return $out;
    }

    /** Does the handler (or a $this->helper it calls) write an audit row? */
    private function controllerAudits(string $action): bool
    {
        if (! str_contains($action, '@')) {
            return false;                               // a closure: say so, let a person decide
        }
        [$cls, $meth] = explode('@', $action, 2);
        try {
            $ref = new \ReflectionMethod($cls, $meth);
            $src = file($ref->getFileName());
            $body = implode('', array_slice($src, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
            $re = '/Audit::write|->audit\(|->audited\(|audit_logs|->auditSignIn\(/';
            if (preg_match($re, $body)) {
                return true;
            }
            preg_match_all('/\$this->(\w+)\(/', $body, $m);
            foreach (array_unique($m[1]) as $h) {
                if (! method_exists($cls, $h)) {
                    continue;
                }
                $hr = new \ReflectionMethod($cls, $h);
                $hb = implode('', array_slice($src, $hr->getStartLine() - 1, $hr->getEndLine() - $hr->getStartLine() + 1));
                if (preg_match($re, $hb)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
        }
        return false;
    }
}
