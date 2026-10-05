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

Every video is: a branded intro card (`brand.js`, the real wordmark animated frame by frame)
with Clara introducing the guide → the recording, with eased zooms on every highlighted
field/option (logged during recording, applied with zoompan) and fades → an outro with the
animated logo. Frames are captured through Chrome's screencast and timed by arrival, so the
picture stays in step with the narration (page.screencast ran ~2.3x fast — do not go back).

Accounts: parent 94, educator 84, home_visitor 232, director 100 (Rebecca Bright, centre 18),
admin 234 (Demo Admin, agency_admin) — all Test Agency 6. `explore` steps split on `>`,
screens on `||`; run it under Git Bash with MSYS_NO_PATHCONV=1 when a step contains `=`.

`assets/poster.y4m` (fake camera feed, ~20 MB) is not committed. Rebuild it with
`node mkposter.js && ffmpeg -loop 1 -i assets/poster.png -t 3 -r 15 -pix_fmt yuv420p assets/poster.y4m`.
Never tap anything in a script that moves money or contacts people — ring it instead.
