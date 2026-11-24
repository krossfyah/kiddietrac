<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\ManagedFormController;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Counter-signatures the queue worker has not finished (2026-11-06).
 *
 * The stamp is made by App\Jobs\FinishCountersign because the web server's PHP cannot
 * run Ghostscript (proc_open is off there). From 2026-10-26 to 2026-11-06 every
 * counter-signature was recorded but went out as the parent's original, without the
 * counter-signature page, and nothing noticed. This makes that impossible to miss again:
 *
 *   (no options)        every 15 minutes: re-run anything unfinished after 10 minutes;
 *                       after an hour send the original instead and tell the platform admins.
 *   --resend-corrected  rebuild a past counter-signature that was sent without its page
 *                       and send both sides a corrected copy. --ids=1,2 or --all,
 *                       --dry-run to list only, --to=address for ONE sample copy that
 *                       changes nothing.
 */
class KtCountersignFinish extends Command
{
    protected $signature = 'kt:countersign-finish
        {--resend-corrected : Rebuild past counter-signatures sent without the page and send a corrected copy}
        {--ids= : Comma-separated sign-off ids (with --resend-corrected)}
        {--all : Every counter-signed form that has no stamped copy (with --resend-corrected)}
        {--to= : Send ONE sample copy to this address; nothing is changed}
        {--dry-run : List what would happen; send nothing}';

    protected $description = 'Finish counter-signatures the worker did not (and rebuild past ones sent without the page).';

    public function handle(): int
    {
        return $this->option('resend-corrected') ? $this->resendCorrected() : $this->guard();
    }

    private function guard(): int
    {
        $rows = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->whereNotNull('s.countersigned_at')->whereNull('s.countersign_finished_at')
            ->where('s.countersigned_at', '<', now()->subMinutes(10))
            ->orderBy('s.id')
            ->get(['s.id', 's.countersigned_at', 'f.title', 'f.agency_id']);

        $ctl = app(ManagedFormController::class);
        $late = [];
        foreach ($rows as $r) {
            $old = Carbon::parse($r->countersigned_at)->lt(now()->subHour());
            $line = '#' . $r->id . ' "' . $r->title . '" (agency ' . $r->agency_id . ', counter-signed ' . $r->countersigned_at . ')';
            if ($this->option('dry-run')) {
                $this->line('would finish ' . $line . ($old ? ' - unstamped if need be' : ''));
                continue;
            }
            try {
                $res = $ctl->finishCountersign((int) $r->id, ['allow_unstamped' => $old]);
            } catch (\Throwable $e) {
                $res = ['status' => 'error: ' . $e->getMessage(), 'stamped' => false, 'sent' => []];
            }
            $this->line($line . ': ' . $res['status'] . ($res['stamped'] ? ', stamped' : ''));
            if ($res['status'] !== 'done' || ! $res['stamped']) {
                // TEST AGENCIES ARE NOT ALERTED ON (2026-11-09, Anthony: "alerts for test agency's should all be turnt off"): the form is still finished above.
                if (in_array((int) $r->agency_id, \App\Support\TestAgencies::ids(), true)) {
                    continue;
                }
                if ($old || $res['status'] !== 'deferred') {
                    $late[] = ['line' => $line . ' - ' . ($res['status'] === 'done'
                        ? 'sent WITHOUT the counter-signature page (the stamp failed)'
                        : $res['status'])];
                }
            }
        }

        if ($late) {
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Counter-signed forms not stamped - ' . count($late),
                'The queue worker could not make the counter-signed copy of these forms. After an hour the original is sent instead, so the family and agency are not left waiting, but it has no counter-signature page. Check that Ghostscript runs for the command-line PHP (laravel.log: "countersign stamp failed"), then rebuild them with: php artisan kt:countersign-finish --resend-corrected --ids=...',
                ['Not stamped' => $late]
            );
        }
        return self::SUCCESS;
    }

    private function resendCorrected(): int
    {
        $q = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->whereNotNull('s.countersigned_at')->whereNull('s.countersigned_file_url');
        if ($this->option('ids')) {
            $q->whereIn('s.id', array_filter(array_map('intval', explode(',', (string) $this->option('ids')))));
        } elseif (! $this->option('all')) {
            $this->error('Give --ids=... or --all.');
            return self::FAILURE;
        }
        $rows = $q->orderBy('s.id')->get([
            's.id', 's.user_id', 's.countersigned_at', 's.countersign_reviewed_url', 's.countersign_annotations',
            's.filled_file_url', 's.returned_to', 'f.title', 'f.agency_id', 'f.notify_email', 'u.email as signer_email',
        ]);
        $to = $this->option('to') ? trim((string) $this->option('to')) : null;
        if ($to && ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('--to is not an email address.');
            return self::FAILURE;
        }
        if ($to && $rows->count() !== 1) {
            $this->error('A sample (--to) is one form: give exactly one id.');
            return self::FAILURE;
        }

        $ctl = app(ManagedFormController::class);
        foreach ($rows as $r) {
            [$reviewed, $annotations] = $this->recover($r);
            $base = $reviewed ?: $r->filled_file_url;
            $hasBase = $base && is_file(Storage::disk('public')->path(preg_replace('#^/storage/#', '', (string) $base)));
            $this->line('#' . $r->id . ' "' . $r->title . '" agency ' . $r->agency_id . ' counter-signed ' . $r->countersigned_at
                . ' | base: ' . ($reviewed ? 'reviewed copy' : 'original') . ($hasBase ? '' : ' (FILE MISSING)')
                . ' | additions: ' . count($annotations ?? [])
                . ' | to: ' . ($to ?: ($r->returned_to ?: 'parent + office')));
            if ($this->option('dry-run') || ! $hasBase) {
                continue;
            }

            if (! $to) {
                // Kept on the row so the rebuilt copy is reproducible.
                DB::table('managed_form_signoffs')->where('id', $r->id)->update([
                    'countersign_reviewed_url' => $r->countersign_reviewed_url ?: $reviewed,
                    'countersign_annotations' => $r->countersign_annotations ?: ($annotations ? json_encode($annotations) : null),
                ]);
            }
            // A sample takes the recovered copy and additions as options and writes nothing.
            $res = $ctl->finishCountersign((int) $r->id, $to
                ? ['corrected' => true, 'only_to' => $to, 'reviewed_url' => $reviewed, 'annotations' => $annotations]
                : ['corrected' => true]);
            $this->line('  -> ' . $res['status'] . ($res['stamped'] ? ', stamped' : ', NOT stamped') . ', sent to: ' . (implode(', ', $res['sent']) ?: 'nobody'));
        }
        return self::SUCCESS;
    }

    /**
     * Before 2026-11-06 the reviewed copy's path and the additions were not kept on the
     * row. The reviewed file is the one written to that agency's reviewed/ folder in the
     * seconds before the counter-signature; the additions are in its audit entry.
     */
    private function recover(object $r): array
    {
        $reviewed = $r->countersign_reviewed_url ?: null;
        if (! $reviewed) {
            /* STRICT, BECAUSE A WRONG MATCH IS ANOTHER FAMILY'S FORM. The old request wrote
               the reviewed file and then the row within the same second or two, so the
               file is 0-5 seconds OLDER than countersigned_at. Staff counter-sign in
               runs a minute apart; a looser window picked a neighbour's file for 5 of the
               58 (measured 2026-11-06, caught before anything was sent). And a file
               belongs only to the counter-signature nearest after it. */
            $at = Carbon::parse($r->countersigned_at)->getTimestamp();
            $best = null;
            foreach (Storage::disk('public')->files('managed-forms/' . $r->agency_id . '/reviewed') as $f) {
                $gap = $at - Storage::disk('public')->lastModified($f);
                if ($gap >= 0 && $gap <= 5 && (! $best || $gap < $best[1])) {
                    $best = [$f, $gap];
                }
            }
            if ($best) {
                $m = Storage::disk('public')->lastModified($best[0]);
                $closer = DB::table('managed_form_signoffs as s')->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
                    ->where('f.agency_id', $r->agency_id)->where('s.id', '!=', $r->id)
                    ->whereBetween('s.countersigned_at', [Carbon::createFromTimestamp($m)->toDateTimeString(), Carbon::createFromTimestamp($at)->subSecond()->toDateTimeString()])
                    ->exists();
                if ($closer) {
                    $best = null;   // another counter-signature sits between: not provably this one's
                }
            }
            $reviewed = $best ? '/storage/' . $best[0] : null;
        }

        $annotations = json_decode((string) ($r->countersign_annotations ?? ''), true) ?: null;
        if (! $annotations) {
            $payload = DB::table('audit_logs')->where('action', 'managed_form.countersigned')
                ->where('entity_id', $r->id)->orderByDesc('id')->value('payload');
            $list = (json_decode((string) $payload, true) ?: [])['annotations'] ?? [];
            foreach ($list as $a) {
                if (preg_match('/^p(\d+):\s?(.*)$/s', (string) $a, $m)) {
                    $annotations[] = ['page' => (int) $m[1], 'text' => $m[2]];
                }
            }
        }
        return [$reviewed, $annotations];
    }
}
