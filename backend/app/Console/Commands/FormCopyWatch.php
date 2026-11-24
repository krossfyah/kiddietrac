<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\ManagedFormController;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A signed form whose completed copy never reached the address on the form (2026-10-25).
 *
 * Every signing route sends it (ManagedFormController::sendCompletedCopy); this catches any
 * route that forgets, or a send that died. Signed in the window, older than 10 minutes (the
 * mail queue runs every minute), form has an address, notified_at still empty.
 * --fix sends the missing copies; platform admins get a bell either way, so the cause is fixed.
 */
class FormCopyWatch extends Command
{
    protected $signature = 'kt:form-copy-watch {--hours=6} {--fix}';
    protected $description = 'Find (and with --fix, send) completed form copies that never went to the form address';

    public function handle(): int
    {
        $rows = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->whereNotNull('s.signed_at')->whereNull('s.notified_at')
            ->where('s.signed_at', '>=', now()->subHours(max(1, (int) $this->option('hours'))))
            ->where('s.signed_at', '<=', now()->subMinutes(10))
            ->whereNotNull('f.notify_email')->where('f.notify_email', '<>', '')
            ->orderBy('s.id')
            ->get(['s.id', 'f.agency_id', 'f.title', 'f.notify_email', 's.signed_at', 'u.first_name', 'u.last_name']);

        $sent = 0;
        foreach ($rows as $r) {
            $who = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
            $this->warn("  #{$r->id} ag{$r->agency_id} {$r->title} - {$who} - signed {$r->signed_at} -> {$r->notify_email}");
            if ($this->option('fix')) {
                try { if (app(ManagedFormController::class)->sendCompletedCopy((int) $r->id)) $sent++; } catch (\Throwable $e) { report($e); }
            }
        }

        if ($rows->count()) {
            $admins = DB::table('role_assignments')->where('role', 'platform_admin')->where('active', 1)->pluck('user_id')->unique();
            Notify::write($admins->map(fn ($u) => ['user_id' => (int) $u, 'type' => 'form_copy',
                'title' => '⚠️ ' . $rows->count() . ' completed form copy(s) had not been emailed' . ($this->option('fix') ? ' — sent now' : ''),
                'body' => $rows->take(3)->map(fn ($r) => $r->title . ' → ' . $r->notify_email)->implode(' · '),
                'data' => json_encode(['link' => '#forms-manager']), 'created_at' => now()])->values()->all());
        }
        $this->info($rows->count() . ' missing completed copy(s)' . ($this->option('fix') ? ", {$sent} queued" : ''));

        return self::SUCCESS;
    }
}
