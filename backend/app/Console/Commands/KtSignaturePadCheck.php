<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Every signature goes through the shared pads (2026-11-06).
 *
 * Anthony chose saved signatures + initials for everyone, on every signing screen. Nine
 * screens drew their own signature canvas, so a saved signature (and every fix the shared
 * pads carry - blurry phones, a stroke lost off the edge, the overlay hidden behind a sheet)
 * reached only half of them. They were all moved onto kt-signature-pad.js. This fails on a
 * front-end file that draws on a canvas near the word "sign" without being on the list
 * below, so a new screen cannot quietly hand-roll its own again.
 *
 * Nightly; an alert to the platform admins on a failure. --root to check another copy.
 */
class KtSignaturePadCheck extends Command
{
    protected $signature = 'kt:signature-pad-check {--root= : parent-portal folder to scan (default: the live one)} {--quiet-ok} {--no-alert : report only}';

    protected $description = 'Fail on any signing screen that draws its own signature canvas instead of KT.signaturePad / KT.signaturePadInline.';

    /** Canvas code that is not a signature pad, each with the reason. */
    public const ALLOW = [
        'js/kt-signature-pad.js' => 'the pads themselves',
        'js/kt-form-filler.js' => 'renders PDF pages with pdf.js; signs through KT.signaturePad',
        'js/kt-doc-viewer.js' => 'rasterises a document for printing',
        'js/kt-face-check.js' => 'camera frames for the profile-photo face check',
    ];

    public function handle(): int
    {
        $root = rtrim($this->option('root') ?: base_path('../parent-portal'), '/');
        $files = array_merge(glob($root . '/js/*.js') ?: [], glob($root . '/*.html') ?: []);
        $bad = [];
        foreach ($files as $f) {
            $rel = ltrim(substr($f, strlen($root)), '/');
            if (isset(self::ALLOW[$rel]) || preg_match('/\.(bak|orig)|\.min\.js$|^dashboard(\.src)?\.html$/', $rel)) {
                continue;
            }
            $lines = @file($f) ?: [];
            $n = count($lines);
            for ($i = 0; $i < $n; $i++) {
                if (! preg_match("/<canvas|createElement\\(\\s*['\"]canvas|el\\(\\s*['\"]canvas|getContext\\(\\s*['\"]2d/i", $lines[$i])) {
                    continue;
                }
                // A canvas with signing words within 25 lines either side is a signature pad.
                $win = implode('', array_slice($lines, max(0, $i - 25), 51));
                if (preg_match('/signature|sign here|sign above|sign below|initial here/i', $win)) {
                    $bad[] = ['line' => $rel . ':' . ($i + 1) . '  ' . trim(mb_substr($lines[$i], 0, 140))];
                    break;   // one per file is enough to act on
                }
            }
        }

        foreach ($bad as $b) {
            $this->error('own signature canvas: ' . $b['line']);
        }
        if ($bad && ! $this->option('no-alert')) {
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Signature screens - ' . count($bad) . ' not on the shared pad',
                'These screens draw their own signature canvas, so saved signatures and initials do not reach them. Use KT.signaturePad(opts) or KT.signaturePadInline(host, opts) from js/kt-signature-pad.js, or add the file to KtSignaturePadCheck::ALLOW with the reason if the canvas is not a signature.',
                ['Own signature canvas' => $bad]
            );
        }
        if ($bad) {
            return self::FAILURE;
        }
        if (! $this->option('quiet-ok')) {
            $this->info('Every signing screen uses the shared pad (' . count($files) . ' files checked).');
        }

        return self::SUCCESS;
    }
}
