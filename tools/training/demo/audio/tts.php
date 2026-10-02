<?php
// Voice-over for the marketing product clips, one file per sentence (so captions can be timed exactly).
// Runs on the server: the Telnyx key never leaves it. Usage: php tts.php scripts.json outdir
require getenv('HOME') . '/kiddietrac/backend/vendor/autoload.php';
$app = require getenv('HOME') . '/kiddietrac/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = json_decode(file_get_contents($argv[1]), true);
$out = $argv[2];
@mkdir($out, 0755, true);
$key = App\Support\Telnyx::apiKey(2);
foreach ($s as $clip => $lines) {
    if ($clip === 'voice') continue;
    foreach ($lines as $i => $text) {
        $r = Illuminate\Support\Facades\Http::withToken($key)->timeout(60)
            ->post('https://api.telnyx.com/v2/text-to-speech/speech', ['voice' => $s['voice'], 'text' => $text]);
        if (! $r->successful() || strlen($r->body()) < 1000) { echo "FAIL $clip $i: ", $r->status(), "\n"; exit(1); }
        file_put_contents("$out/$clip-$i.mp3", $r->body());
        echo "$clip-$i ", strlen($r->body()), " bytes\n";
    }
}
