<?php

namespace App\Jobs;

use App\Support\PlaySparkPicture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Draws one Play Spark picture (App\Support\PlaySparkPicture). On its own 'pictures' queue, which the every-minute
 * worker takes LAST (mail, default, pictures), so a 30-second drawing never holds up an email. One attempt per job:
 * PlaySparkPicture counts tries itself and the screen asks again once after 10 minutes.
 */
class DrawPlaySparkPicture implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 150;

    public function __construct(public int $pictureId)
    {
        $this->onQueue('pictures');
    }

    public function handle(): void
    {
        PlaySparkPicture::run($this->pictureId);
    }
}
