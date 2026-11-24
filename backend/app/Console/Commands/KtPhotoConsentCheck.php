<?php

namespace App\Console\Commands;

use App\Support\MediaConsent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PHOTO PERMISSION GUARD (2026-11-06).
 *
 * Anthony: "add guards for all new agencies as well so we never run into this issue ever again". Every agency, new
 * ones included - nothing here is per-agency or switchable. Two checks, nightly:
 *
 *   DATA  a visible photo that tags a child whose family said "do not photograph" (a tag written by some path that
 *         skipped MediaConsent) is HIDDEN at once - kept on file, never deleted - and reported.
 *   CODE  a file under app/ that reads the photos table and is not in MediaConsent::READERS (with its reason) FAILS:
 *         a new screen, email or export showing photos must apply the rule or say why it does not need to.
 *         A READERS entry for a file that no longer exists is a warning.
 *
 *   php artisan kt:photo-consent-check            (fixes the data, emails the platform admins on any finding)
 *   php artisan kt:photo-consent-check --dry-run  (reports only, changes nothing)
 *   php artisan kt:photo-consent-check --no-mail  (prints only)
 */
class KtPhotoConsentCheck extends Command
{
    protected $signature = 'kt:photo-consent-check {--dry-run : report only, hide nothing} {--no-mail : print only, do not email the platform admins}';
    protected $description = 'Hide photos of children whose family said no, and fail on code that reads photos without the photo-permission rule.';

    /** How a photos-table read looks in this codebase. */
    private const READ_PATTERN = '/(?:table|join|leftJoin|rightJoin|from)\(\s*[\'"]photos(?:\s+as\s+\w+)?[\'"]|\b(?:from|join)\s+`?photos`?\b|Models\\\\Photo\b|\bPhoto::/i';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $fixed = [];
        $fail = [];
        $warn = [];

        /* DATA */
        try {
            $noIds = [];
            foreach (DB::table('child_media_consents')->where('level', MediaConsent::NONE)->distinct()->pluck('child_id') as $cid) {
                if (MediaConsent::levelOf((int) $cid) === MediaConsent::NONE) $noIds[] = (int) $cid;
            }
            foreach ($noIds as $cid) {
                $visible = DB::table('photos as p')->join('centres as ce', 'ce.id', '=', 'p.centre_id')->whereNull('p.hidden_at')
                    ->whereJsonContains('p.child_ids', $cid)->get(['p.id', 'p.centre_id', 'ce.agency_id']);
                if ($visible->isEmpty()) continue;
                $n = $dry ? $visible->count() : MediaConsent::hidePhotosOf($cid, 'no_consent', null);
                // TEST AGENCIES ARE NOT ALERTED ON (2026-11-09, Anthony: "alerts for test agency's should all be turnt off"): the photos are still hidden, nobody is emailed about it.
                if (in_array((int) $visible->first()->agency_id, \App\Support\TestAgencies::ids(), true)) continue;
                // Ids only - no child names in a platform email.
                $fixed[] = ['line' => 'child #' . $cid . ' (agency ' . $visible->first()->agency_id . '): ' . $n . ' visible photo(s) '
                    . ($dry ? 'WOULD BE hidden' : 'hidden') . ' - photo ' . $visible->pluck('id')->implode(', ')];
            }
            // The older media table (POST /provider/photos) has no hidden flag: report it so a person removes the tag.
            if ($noIds && \Illuminate\Support\Facades\Schema::hasTable('media_child_tags')) {
                foreach (DB::table('media_child_tags')->whereIn('child_id', $noIds)->get(['media_id', 'child_id']) as $m) {
                    $fail[] = ['line' => 'media #' . $m->media_id . ' (older media table) tags child #' . $m->child_id . ', whose family said do not photograph - remove the tag'];
                }
            }
        } catch (Throwable $e) {
            $fail[] = ['line' => 'data check could not run: ' . $e->getMessage()];
        }

        /* CODE */
        $root = app_path();
        $listed = MediaConsent::READERS;
        $seen = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! $f->isFile() || substr($f->getFilename(), -4) !== '.php') continue;   // .php.bak-* copies are not code
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            $src = (string) @file_get_contents($f->getPathname());
            if (! preg_match(self::READ_PATTERN, $src)) continue;
            $seen[$rel] = true;
            if (! isset($listed[$rel])) {
                $fail[] = ['line' => $rel . ' reads the photos table but is not in App\Support\MediaConsent::READERS - apply MediaConsent::filter() / visibleTo() (or say why it is safe) and list it'];
            }
        }
        foreach ($listed as $rel => $why) {
            if (! is_file($root . '/' . $rel)) $warn[] = ['line' => 'READERS lists ' . $rel . ', which no longer exists'];
        }

        $this->line('Photo permission guard' . ($dry ? ' (dry run)' : ''));
        $this->line('  readers found: ' . count($seen) . ', listed: ' . count($listed));
        foreach ($fixed as $r) $this->warn('  DATA ' . $r['line']);
        foreach ($warn as $r) $this->line('  WARN ' . $r['line']);
        foreach ($fail as $r) $this->error('  FAIL ' . $r['line']);

        if (! $fixed && ! $fail) {
            $this->info('Photo permissions OK.');
            return self::SUCCESS;
        }
        Log::critical('[PHOTO-CONSENT] ' . count($fixed) . ' hidden, ' . count($fail) . ' failure(s)', array_column(array_merge($fixed, $fail), 'line'));
        if (! $this->option('no-mail') && ! $dry) {
            $sections = [];
            if ($fixed) $sections['Photos hidden (a family said do not photograph)'] = $fixed;
            if ($fail) $sections['Code that shows photos without the rule'] = $fail;
            if ($warn) $sections['Warnings'] = $warn;
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Photo permissions - ' . count($fixed) . ' hidden, ' . count($fail) . ' code failure(s)',
                'A photo tagged a child whose family asked that they not be photographed (it has now been hidden, not deleted), or code reads photos without applying App\Support\MediaConsent. Find how the tag got past the rule.',
                $sections
            );
        }

        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
