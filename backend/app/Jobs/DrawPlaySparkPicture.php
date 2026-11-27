<?php

namespace App\Jobs;

use App\Support\PlaySparkPicture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Draws one Play Spark picture (App\Support\PlaySparkPicture). On its own 'pictures' queue with its OWN worker
 * (routes/console.php, 2026-11-27): a v2 drawing (plan, five drawings at once, a check, maybe a redraw) takes one to
 * three minutes, and the mail worker runs one process at a time, so pictures must never sit in front of email. One
 * attempt per job: PlaySparkPicture counts tries itself and the screen asks again once after 10 minutes.
 */
class DrawPlaySparkPicture implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 420;

    public function __construct(public int $pictureId)
    {
        $this->onQueue('pictures');
    }

    public function handle(): void
    {
        PlaySparkPicture::run($this->pictureId);
    }
}
