<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * kt:leak-integrity, files section (2026-11-28): stored file references that point outside their row's agency.
 *
 * Every text column of every table is searched for references to our protected or private files (the schema is read
 * each run, so a new column is covered the day it ships). For each row that holds one:
 *
 *   - FOREIGN FILE: the file's path names its owner (FileRefs::fromPath - an agency folder, a child, a form, a file
 *     request, a person) and none of the row's agencies is that owner;
 *   - SHARED FILE: the path names no owner, and rows of DIFFERENT agencies hold the same file.
 *
 * Reported only, never changed. As a side effect the run rebuilds file_owners ("row") and caches the column list, which
 * RefuseForeignFileRefs uses to recognise this agency's existing files.
 *
 * Not seen: sealed (encrypted) columns - chat messages and their attachments are stored encrypted (ChatPrivacy).
 */
final class FileRefIntegrity
{
    /** @return array{findings: array<string, array{0:int,1:array}>, checks:int, refs:int, rows:int, columns:int, untenanted:array} */
    public static function run(): array
    {
        $cols = DB::select("SELECT c.table_name t, c.column_name c FROM information_schema.columns c JOIN information_schema.tables t
            ON t.table_schema = c.table_schema AND t.table_name = c.table_name
            WHERE c.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
            AND c.data_type IN ('varchar','text','mediumtext','longtext','json','tinytext') AND (c.character_maximum_length IS NULL OR c.character_maximum_length >= 20)");
        $hasId = [];
        foreach (DB::select("SELECT table_name t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'id'") as $r) {
            $hasId[$r->t] = true;
        }
        $tenant = new RowTenant();
        $findings = []; $untenanted = []; $refCount = 0; $rowCount = 0; $live = []; $checks = 0;
        $owners = [];                 // rel => [agency => true] (row evidence, for the index)
        $noPath = [];                 // rel => [agency => ['table#id', ...]] for files whose path names no owner

        foreach ($cols as $r) {
            $t = $r->t; $c = $r->c;
            if (in_array($t, FileRefs::SKIP_TABLES, true) || str_starts_with($t, 'leak_') || empty($hasId[$t])) {
                continue;
            }
            $checks++;
            $q = fn () => DB::table($t)->where(function ($w) use ($c) {
                $w->where($c, 'like', '%storage%')->orWhere($c, 'like', '%private:%')->orWhere($c, 'like', '%/media/f%')->orWhere($c, 'like', '%media\\\\/f%')
                    ->orWhere($c, 'like', '%/media/e/%')->orWhere($c, 'like', '%media\\\\/e%');   // JSON writes / as \/
            });
            try {
                if (! $q()->exists()) continue;
            } catch (\Throwable $e) {
                continue;
            }
            $live[] = "{$t}.{$c}";
            $q()->orderBy('id')->chunk(500, function ($rows) use ($t, $c, $tenant, &$findings, &$untenanted, &$refCount, &$rowCount, &$owners, &$noPath) {
                foreach ($rows as $row) {
                    $refs = FileRefs::extract((string) ($row->{$c} ?? ''));
                    if (! $refs) continue;
                    $rowCount++;
                    $agencies = array_values(array_unique(array_map('intval', $tenant->agencies($t, $row))));
                    $single = count($agencies) === 1 && ! in_array($t, ['users'], true) && ! ($t === 'documents' && ($row->scope_type ?? '') === 'user');
                    foreach ($refs as $rel) {
                        if (FileRefs::isShared($rel)) continue;
                        $refCount++;
                        if (! $agencies) { $untenanted["{$t}.{$c}"] = ($untenanted["{$t}.{$c}"] ?? 0) + 1; continue; }
                        foreach ($agencies as $a) $owners[$rel][$a] = true;
                        $p = FileRefs::fromPath($rel);
                        $evidence = $p['agencies'];
                        foreach ($p['users'] as $u) $evidence = array_merge($evidence, FileRefs::memberships($u));
                        if ($evidence) {
                            if (! array_intersect($agencies, $evidence)) {
                                $k = "files: {$t}.{$c} names another agency's file";
                                $findings[$k][0] = ($findings[$k][0] ?? 0) + 1;
                                if (count($findings[$k][1] ?? []) < 10) {
                                    $findings[$k][1][] = "#{$row->id} (row agency " . implode('/', $agencies) . ', file of ' . implode('/', array_unique($evidence)) . ": {$rel})";
                                }
                            }
                        } elseif ($single && ! $p['users']) {
                            $noPath[$rel][$agencies[0]][] = "{$t}#{$row->id}";
                        }
                    }
                }
            });
        }

        foreach ($noPath as $rel => $byAgency) {
            if (count($byAgency) < 2) continue;
            $k = 'files: the same file is held by rows of different agencies';
            $findings[$k][0] = ($findings[$k][0] ?? 0) + 1;
            if (count($findings[$k][1] ?? []) < 10) {
                $parts = [];
                foreach ($byAgency as $a => $where) $parts[] = "agency {$a}: " . implode(',', array_slice($where, 0, 3));
                $findings[$k][1][] = "{$rel} (" . implode('; ', $parts) . ')';
            }
        }

        // The index RefuseForeignFileRefs reads: rebuilt from what the rows hold today. The upload ledger is kept.
        if ($live) {
            Cache::forever(FileRefs::COLUMNS_CACHE, $live);
        }
        try {
            DB::table('file_owners')->where('source', 'row')->delete();
            $batch = [];
            foreach ($owners as $rel => $as) {
                foreach (array_keys($as) as $a) {
                    $batch[] = ['rel_hash' => sha1($rel), 'rel' => mb_substr($rel, 0, 500), 'agency_id' => $a, 'user_id' => null, 'source' => 'row', 'created_at' => now()];
                    if (count($batch) >= 500) { DB::table('file_owners')->insertOrIgnore($batch); $batch = []; }
                }
            }
            if ($batch) DB::table('file_owners')->insertOrIgnore($batch);
        } catch (\Throwable $e) {
            report($e);
        }

        return ['findings' => $findings, 'checks' => $checks, 'refs' => $refCount, 'rows' => $rowCount, 'columns' => count($live), 'untenanted' => $untenanted];
    }
}
