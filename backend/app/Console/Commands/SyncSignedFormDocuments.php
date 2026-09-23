<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SignedFormFiler;
use Illuminate\Console\Command;

/**
 * File any signed form whose document never got written.
 *
 * WHY THIS EXISTS. Filing happens at signature time and is deliberately best-effort — a
 * problem writing the document must never turn a successful submission into an error the
 * parent sees. The cost of that choice is silence: if the account did not exist yet, or
 * the write failed, the form is complete in Forms Manager and invisible on the family's
 * record, and nobody finds out until somebody goes looking for a document that should be
 * there.
 *
 * So the same question gets asked again, nightly, with the answer reported. A count that
 * should always be zero is a working alarm; one that is quietly non-zero is exactly the
 * bug this mechanism was meant to prevent.
 *
 * Idempotent: file() keys on (source_type, source_id), so a second pass rewrites the same
 * rows rather than adding more, and dates them by when the form was SIGNED rather than
 * when this happened to run.
 */
class SyncSignedFormDocuments extends Command
{
    protected $signature = 'forms:sync-documents
                            {--agency= : restrict to one agency id}
                            {--dry-run : report what is unfiled without writing}';

    protected $description = 'File signed forms whose document row is missing (runs nightly)';

    public function handle(): int
    {
        $agencyId = $this->option('agency') ? (int) $this->option('agency') : null;

        $before = SignedFormFiler::unfiledCount($agencyId);
        $this->line('Unfiled signed forms: ' . $before . ($agencyId ? ' (agency ' . $agencyId . ')' : ''));

        if ($before === 0) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing written.');

            return self::SUCCESS;
        }

        SignedFormFiler::backfill($agencyId);
        $after = SignedFormFiler::unfiledCount($agencyId);

        $this->info('Filed ' . ($before - $after) . '; still unfiled: ' . $after);

        /* Left visible rather than swallowed: a sign-off that cannot be filed after a
           full pass has something else wrong with it — no completed file, or a user that
           no longer exists — and that is worth a human looking, not a silent retry
           forever. */
        if ($after > 0) {
            \Illuminate\Support\Facades\Log::warning('forms:sync-documents left rows unfiled', [
                'agency_id' => $agencyId, 'remaining' => $after,
            ]);
        }

        return self::SUCCESS;
    }
}
