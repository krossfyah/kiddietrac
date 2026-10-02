# Training video recorder

Records the voiced, step-by-step tutorials shown under Help & guides → Training.
Every video is made on the Test Agency (6) demo accounts; real people are scrubbed
from the page (`demo/harness.js`), and each lesson's setup/teardown removes anything
it creates. Videos upload as DRAFTS — a director/admin publishes them after review.

Layout (relative paths matter):

    recorder/   tut.js, build-<audience>.js, mk*.js, assets/
    demo/       harness.js (scrubber), audio/tts.php (runs on the server; the TTS key never leaves it)
                + tokens.json containing {} and `npm i puppeteer-core` here
    vid/        `npm i @ffmpeg-installer/ffmpeg` (ffmpeg for mixing)

Usage (from recorder/):

    node build-parent.js                         # writes scripts/parent/*.json
    node tut.js explore parent "#today>text:Photos" phone   # screenshot + tappable texts
    node tut.js make scripts/parent/your-daily-view.json    # narrate, record, mix, upload draft

`assets/poster.y4m` (fake camera feed, ~20 MB) is not committed. Rebuild it with
`node mkposter.js && ffmpeg -loop 1 -i assets/poster.png -t 3 -r 15 -pix_fmt yuv420p assets/poster.y4m`.
Never tap anything in a script that moves money or contacts people — ring it instead.
