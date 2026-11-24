<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AuditNarrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * No raw audit rows (2026-10-31, Anthony: "we had a rule to never use raw logging and to put as much detail as we can
 * to understand what and who").
 *
 * Fails when:
 *   1. a NAMED action (not a route row) written in the last --days days has no sentence in AuditNarrator
 *      (VERBS / MORE / patterns) - it would read as its code;
 *   2. the newest row of such an action has neither a payload "summary" nor an AuditDescriber sentence nor any
 *      recorded field - the Details column would be empty or raw.
 * Route rows ("post:api/v1/...") are covered by kt:audit-labels.
 */
class AuditRawCheckCommand extends Command
{
    protected $signature = 'kt:audit-raw-check {--days=7 : how far back to look}';

    protected $description = 'Fail when recent audit actions would show as raw codes (no sentence / no details)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $rows = DB::table('audit_logs')->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('action, count(*) n, max(id) last_id')->groupBy('action')->get();
        $noVerb = [];
        $noDetail = [];
        foreach ($rows as $r) {
            $a = (string) preg_replace('/ \[fail\]$/i', '', (string) $r->action);
            if ($a === '' || str_contains($a, ':') || str_starts_with($a, 'generated::')) continue;
            if (AuditNarrator::verbFor($a) === null) {
                $noVerb[] = $a . ' (' . $r->n . ')';
            }
            $last = DB::table('audit_logs')->where('id', $r->last_id)->first(['payload', 'entity_type', 'entity_id']);
            $p = json_decode((string) ($last->payload ?? ''), true);
            $p = is_array($p) ? $p : [];
            $described = ! empty($p['summary']) || \App\Support\AuditDescriber::describe($a, $p) !== null;
            // A manual one-off row is still readable when it names its subject (shown as the "What").
            $hasSubject = ! empty($last->entity_type) && (int) ($last->entity_id ?? 0) > 0;
            if (! $described && ! $hasSubject && count(array_filter($p, fn ($v) => $v !== null && $v !== '' && $v !== [])) === 0) {
                $noDetail[] = $a . ' (' . $r->n . ', newest #' . $r->last_id . ')';
            }
        }
        if ($noVerb) {
            $this->error('Actions with no sentence in AuditNarrator (add them to VERBS/MORE):');
            foreach ($noVerb as $x) $this->line('  ' . $x);
        }
        if ($noDetail) {
            $this->error('Actions whose newest row records nothing to describe (store a summary when writing):');
            foreach ($noDetail as $x) $this->line('  ' . $x);
        }
        if (! $noVerb && ! $noDetail) {
            $this->info('Every named audit action of the last ' . $days . ' day(s) reads as a sentence with details.');
            return self::SUCCESS;
        }
        return self::FAILURE;
    }
}
