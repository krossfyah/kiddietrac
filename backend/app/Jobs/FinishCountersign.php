<?php

namespace App\Jobs;

use App\Http\Controllers\Api\ManagedFormController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Stamp, file, send and share one counter-signature (2026-11-06).
 *
 * Queued because the stamp shells out to Ghostscript, and the web server's PHP has
 * proc_open switched off - inside a request it can never run. The worker is the
 * command-line PHP, where it can. One try: a failure is left for kt:countersign-finish,
 * which retries the stamp and, after an hour, sends the original and tells the
 * platform admins. A retried job must never send the same form twice, and
 * finishCountersign() refuses a row that is already finished.
 */
class FinishCountersign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $signoffId) {}

    public function handle(): void
    {
        try {
            $r = app(ManagedFormController::class)->finishCountersign($this->signoffId);
            if ($r['status'] === 'deferred') {
                Log::warning('FinishCountersign #' . $this->signoffId . ': the PDF could not be stamped; kt:countersign-finish will retry.');
            }
        } catch (\Throwable $e) {
            Log::error('FinishCountersign #' . $this->signoffId . ' failed: ' . $e->getMessage());
        }
    }
}
