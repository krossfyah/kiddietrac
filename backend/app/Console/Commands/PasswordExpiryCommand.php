<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PasswordExpiryMail;
use App\Support\Audit;
use App\Services\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The nightly sweep that actually sends the expiry warnings (2026-09-21).
 *
 * Quiet by design. It warns at 14, 7 and 1 days and then once on the day it expires, and
 * each of those is sent ONCE - password_expiry_notified_at records which step a person
 * has had, so a nightly run does not become a nightly nag. Being nagged daily about a
 * password is how people learn to ignore email from us.
 *
 * Only accounts somebody actually signs in to: deactivated and never-invited accounts are
 * skipped, because warning a parent who has never claimed their account that a password
 * they have never set is expiring is noise with no action attached.
 */
final class PasswordExpiryCommand extends Command
{
    protected $signature = 'kiddietrac:password-expiry {--dry-run : list who would be written to, send nothing}';

    protected $description = 'Warn people whose KiddieTrac password is about to expire';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $people = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.active', true)
            ->whereNull('u.deleted_at')
            ->whereIn('u.status', ['active'])
            ->whereNotNull('u.email')->where('u.email', '!=', '')
            ->whereNotNull('u.password_changed_at')
            ->distinct()
            ->get(['u.id', 'u.email', 'u.first_name', 'u.last_name', 'u.password_changed_at', 'u.password_expiry_notified_at']);

        $sent = 0; $skipped = 0; $failed = 0;

        foreach ($people as $p) {
            $left = PasswordPolicy::daysLeft((string) $p->password_changed_at);
            if ($left === null) { continue; }

            /* Which warning does today call for? The largest step they have reached, so
               somebody who was away for a fortnight gets the URGENT one, not the 14-day
               one they missed. */
            $step = null;
            if ($left < 0) { $step = 'expired'; }
            else { foreach (PasswordPolicy::WARN_AT_DAYS as $d) { if ($left <= $d) { $step = (string) $d; } } }

            if ($step === null) { $skipped++; continue; }

            /* Sent once per step. The marker holds the step, so moving from 14 to 7 sends
               again but a second night at 7 does not. */
            $marker = $step . '|' . now()->toDateString();
            if (str_starts_with((string) ($p->password_expiry_notified_at ?? ''), $step . '|')) {
                $skipped++;
                continue;
            }

            if ($dry) {
                $this->line(sprintf('  would warn %-34s %s (%d days left)', $p->email, $step, $left));
                $sent++;
                continue;
            }

            if (PasswordExpiryMail::send((int) $p->id, (int) $left)) {
                $sent++;
            } else {
                $failed++;
            }
            DB::table('users')->where('id', $p->id)->update(['password_expiry_notified_at' => $marker]);
        }

        $summary = 'Password expiry sweep: warned ' . $sent . ', skipped ' . $skipped
            . ($failed ? ', ' . $failed . ' could not be delivered' : '') . '.';
        $this->info($summary);

        if (! $dry) {
            try {
                Audit::write([
                    'user_id' => null,
                    'action' => 'security.password_expiry_swept',
                    'entity_type' => 'system',
                    'payload' => json_encode(['summary' => $summary, 'warned' => $sent, 'failed' => $failed]),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
            }
        }

        return self::SUCCESS;
    }
}
