<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CallRecords;
use Illuminate\Console\Command;

/** kt:call-transcripts — transcribe new call/voicemail recordings and file them on leads (App\Support\CallRecords). */
final class CallTranscriptsCommand extends Command
{
    protected $signature = 'kt:call-transcripts {--max=6}';
    protected $description = 'Transcribe new voicemails and recorded calls, and file them on the lead with that phone number';

    public function handle(): int
    {
        $r = CallRecords::run(max(1, (int) $this->option('max')));
        $this->line('transcribed ' . $r['transcribed'] . ', filed ' . $r['filed'] . ', transcripts added ' . $r['updated']);
        return self::SUCCESS;
    }
}
