<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Add a What's new entry (2026-11-02).
 *
 * Anthony: "did we add any new features and why are we not adding to this list?" The list had not been touched
 * since 2026-10-22 while ~45 user-visible features shipped. Adding one meant hand-editing an 80 KB JSON file on the
 * server, and an entry written without roles silently showed to admins only. This is the one-line way to do it,
 * with the checks built in. EVERY USER-VISIBLE FEATURE SHIPS WITH AN ENTRY.
 *
 *   php8.3 artisan kiddietrac:whats-new-add --title="Coming up" --body="Your daily summary now lists ..." \
 *       --roles=guardian [--type=new|improved] [--icon=📅] [--date=YYYY-MM-DD]
 *   php8.3 artisan kiddietrac:whats-new-add --file=/tmp/entries.json   (a JSON array of entries, same fields)
 *   php8.3 artisan kiddietrac:whats-new-add --list                     (the newest 15, and how stale the list is)
 *
 * roles: guardian, educator, home_visitor, centre_director, agency_admin, platform_admin, sales_rep, reseller_admin,
 * or the shortcuts parents, staff (admins + directors + educators + home visitors), admins (agency_admin,
 * platform_admin, centre_director), everyone, sales. The panel shows type new and improved for 92 days; the Monday
 * email (kiddietrac:whats-new-digest) sends staff the entries tagged for them. A backup of the JSON is kept
 * (whats-new.json.bak-add-<stamp>) and the file is written atomically.
 */
class WhatsNewAddCommand extends Command
{
    protected $signature = 'kiddietrac:whats-new-add
        {--title= : short title (2-6 words)}
        {--body= : one or two plain sentences for the people who see it}
        {--roles= : comma list of roles or shortcuts (parents, staff, admins, everyone, sales)}
        {--type=new : new or improved}
        {--icon=✨ : one emoji}
        {--date= : YYYY-MM-DD, default today}
        {--file= : JSON array of entries to add instead}
        {--list : show the newest entries and stop}
        {--dry-run : validate and print, write nothing}';

    protected $description = "Add an entry to the What's new panel + weekly email (whats-new.json)";

    private const ROLES = ['guardian', 'educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin', 'sales_rep', 'reseller_admin'];
    private const SHORT = [
        'parents' => ['guardian'],
        'admins' => ['agency_admin', 'platform_admin', 'centre_director'],
        'staff' => ['agency_admin', 'platform_admin', 'centre_director', 'educator', 'home_visitor'],
        'everyone' => ['agency_admin', 'platform_admin', 'centre_director', 'educator', 'home_visitor', 'guardian', 'sales_rep', 'reseller_admin'],
        'sales' => ['platform_admin', 'sales_rep'],
    ];

    public function handle(): int
    {
        $path = base_path('../parent-portal/whats-new.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! isset($data['entries']) || ! is_array($data['entries'])) {
            $this->error("whats-new.json not found or unreadable at {$path}");
            return self::FAILURE;
        }

        if ($this->option('list')) {
            $dated = array_values(array_filter($data['entries'], fn ($e) => ! empty($e['date'])));
            usort($dated, fn ($a, $b) => strcmp($b['date'], $a['date']));
            foreach (array_slice($dated, 0, 15) as $e) {
                $this->line(sprintf('%s  %-8s %s  [%s]', $e['date'], $e['type'] ?? '', $e['title'] ?? '', implode(',', $e['roles'] ?? ['(admins only)'])));
            }
            $newest = $dated[0]['date'] ?? null;
            if ($newest) {
                $days = (int) floor((time() - strtotime($newest)) / 86400);
                $this->line("Newest entry: {$newest} ({$days} days ago)" . ($days > 7 ? ' - has nothing shipped since? Add the entries.' : ''));
            }
            return self::SUCCESS;
        }

        if ($this->option('file')) {
            $raw = json_decode((string) @file_get_contents((string) $this->option('file')), true);
            if (! is_array($raw) || ! array_is_list($raw)) {
                $this->error('--file must hold a JSON array of entries');
                return self::FAILURE;
            }
        } else {
            $raw = [[
                'type' => $this->option('type'),
                'date' => $this->option('date') ?: date('Y-m-d'),
                'icon' => $this->option('icon'),
                'title' => $this->option('title'),
                'body' => $this->option('body'),
                'roles' => $this->option('roles'),
            ]];
        }

        $have = [];
        foreach ($data['entries'] as $e) {
            $have[mb_strtolower(trim((string) ($e['title'] ?? '')))] = true;
        }
        $add = [];
        foreach ($raw as $i => $e) {
            $n = $i + 1;
            $title = trim((string) ($e['title'] ?? ''));
            $body = trim((string) ($e['body'] ?? ''));
            $type = (string) ($e['type'] ?? 'new');
            $date = (string) ($e['date'] ?? '') ?: date('Y-m-d');
            $roles = $e['roles'] ?? '';
            $roles = is_array($roles) ? $roles : array_filter(array_map('trim', explode(',', (string) $roles)));
            $expanded = [];
            foreach ($roles as $r) {
                foreach (self::SHORT[$r] ?? [$r] as $x) {
                    $expanded[$x] = true;
                }
            }
            $roles = array_keys($expanded);
            $bad = array_diff($roles, self::ROLES);
            $err = [];
            if ($title === '') { $err[] = 'title is required'; }
            if ($body === '') { $err[] = 'body is required'; }
            if (! in_array($type, ['new', 'improved', 'upcoming'], true)) { $err[] = "type must be new, improved or upcoming (got {$type})"; }
            if ($type !== 'upcoming' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $err[] = "date must be YYYY-MM-DD (got {$date})"; }
            if (! $roles) { $err[] = 'roles are required (an entry without roles is shown to admins only)'; }
            if ($bad) { $err[] = 'unknown roles: ' . implode(', ', $bad); }
            if (isset($have[mb_strtolower($title)])) { $err[] = "an entry titled \"{$title}\" already exists"; }
            if ($err) {
                $this->error("Entry {$n}: " . implode('; ', $err));
                return self::FAILURE;
            }
            $have[mb_strtolower($title)] = true;
            $entry = ['type' => $type, 'date' => $type === 'upcoming' ? '' : $date, 'icon' => (string) ($e['icon'] ?? '✨') ?: '✨', 'title' => $title, 'body' => $body, 'roles' => $roles];
            foreach (['title_fr', 'body_fr', 'title_es', 'body_es', 'title_hi', 'body_hi'] as $k) {
                if (! empty($e[$k])) { $entry[$k] = (string) $e[$k]; }
            }
            $add[] = $entry;
            $this->line(sprintf('+ %s %-8s %s  [%s]', $entry['date'], $type, $title, implode(',', $roles)));
        }

        if ($this->option('dry-run')) {
            $this->info(count($add) . ' entr' . (count($add) === 1 ? 'y' : 'ies') . ' valid (dry run, nothing written)');
            return self::SUCCESS;
        }

        // Newest first, the way the file has always been kept; same-day entries keep the order given (headline first).
        usort($add, fn ($a, $b) => strcmp($b['date'], $a['date']));
        $data['entries'] = array_merge($add, $data['entries']);
        $newest = max(array_merge([(string) ($data['updated'] ?? '')], array_column($add, 'date')));
        $data['updated'] = $newest;

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || json_decode($json, true) === null) {
            $this->error('Could not encode the list - nothing written');
            return self::FAILURE;
        }
        @copy($path, $path . '.bak-add-' . date('YmdHis'));
        $tmp = $path . '.uploading';
        if (file_put_contents($tmp, $json . "\n") === false || ! rename($tmp, $path)) {
            @unlink($tmp);
            $this->error('Could not write whats-new.json');
            return self::FAILURE;
        }
        @chmod($path, 0664);
        $this->info(count($add) . ' entr' . (count($add) === 1 ? 'y' : 'ies') . " added to What's new.");
        return self::SUCCESS;
    }
}
