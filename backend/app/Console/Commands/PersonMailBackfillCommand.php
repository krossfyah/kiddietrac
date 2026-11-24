<?php

namespace App\Console\Commands;

use App\Support\MicrosoftMailbox;
use App\Support\PersonMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * kt:person-mail-backfill - link the recent past (2026-11-07).
 *
 * kiddietrac:mail-watch links mail as it arrives. When an agency turns "link emails to people" on, this files the last
 * --days of its Microsoft mailboxes' Inbox (and Sent Items, if the agency chose it) the same way: only live people of
 * that agency, never twice, never back after an unlink. --dry-run reports what it would link and writes nothing.
 */
class PersonMailBackfillCommand extends Command
{
    protected $signature = 'kt:person-mail-backfill {--account= : one mailbox id} {--days=30} {--dry-run}';
    protected $description = 'Link the last N days of an agency mailbox to the people it is from / sent to (the agency setting must be on)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $days = max(1, min(365, (int) $this->option('days')));
        $q = DB::table('email_accounts')->whereNull('deleted_at')->whereNotNull('agency_id')->whereNull('shared_scope')
            ->where('auth_type', 'microsoft')->whereNotNull('oauth_token')->whereNull('auth_failed_at');
        if ($this->option('account')) $q->where('id', (int) $this->option('account'));
        $own = [];
        foreach (DB::table('email_accounts')->whereNull('deleted_at')->get(['email_address', 'oauth_identity']) as $r) {
            foreach ([$r->email_address, $r->oauth_identity] as $x) if ($x) $own[mb_strtolower(trim($x))] = true;
        }
        $since = now()->subDays($days)->utc()->format('Y-m-d\TH:i:s\Z');
        foreach ($q->orderBy('id')->get() as $a) {
            if (! PersonMail::linkable($a)) { $this->line($a->id . ' ' . $a->email_address . ': linking is off for agency ' . $a->agency_id); continue; }
            $s = PersonMail::settings((int) $a->agency_id);
            if (! $s['auto']) { $this->line($a->id . ' ' . $a->email_address . ': agency links by hand only'); continue; }
            $folders = ['inbox' => ['in', 'receivedDateTime']];
            if ($s['sent']) $folders['sentitems'] = ['out', 'sentDateTime'];
            $total = 0; $people = [];
            foreach ($folders as $f => [$dir, $field]) {
                for ($skip = 0; $skip < 2000; $skip += 100) {
                    try {
                        $rows = MicrosoftMailbox::call($a, 'GET', '/mailFolders/' . $f . '/messages', [
                            '$filter' => $field . ' ge ' . $since, '$orderby' => $field . ' desc', '$top' => 100, '$skip' => $skip,
                            '$select' => 'id,internetMessageId,from,toRecipients,ccRecipients,bccRecipients,subject,receivedDateTime,sentDateTime,bodyPreview,hasAttachments,isDraft',
                        ])->json('value') ?? [];
                    } catch (\Throwable $e) {
                        $this->warn($a->id . ' ' . $f . ': ' . $e->getMessage());
                        break;
                    }
                    foreach ($rows as $m) {
                        if (! empty($m['isDraft'])) continue;
                        foreach (PersonMail::autoLink($a, PersonMail::graphMeta($m, $dir), $own, $dry) as $p) {
                            $total++;
                            $people[$p['as'] . ' ' . $p['type'] . ':' . $p['id']] = true;
                        }
                    }
                    if (count($rows) < 100) break;
                }
            }
            $this->line($a->id . ' ' . $a->email_address . ': ' . $total . ' link' . ($total === 1 ? '' : 's') . ' to ' . count($people) . ' people'
                . ($dry ? ' (dry run - nothing written)' : '') . ($people ? ' [' . implode(', ', array_slice(array_keys($people), 0, 12)) . ']' : ''));
        }

        return self::SUCCESS;
    }
}
