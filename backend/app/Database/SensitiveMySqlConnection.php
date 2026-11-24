<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Sensitive;
use Illuminate\Database\MySqlConnection;

/**
 * The MySQL connection, with children's health fields sealed on the way in and opened on
 * the way out (App\Support\Sensitive explains why it lives here and not at call sites).
 *
 * Reads: every row from select()/cursor() has sealed values decrypted.
 * Writes: INSERT / UPDATE statements on a table in Sensitive::COLUMNS have the bindings of
 * the listed columns sealed. The statement text is parsed only as far as Laravel's own
 * grammar writes it (`insert into `t` (`a`, `b`) values (?, ?)`, `update `t` … set `a` = ?`);
 * anything else — a raw expression, an unusual shape — is passed through UNCHANGED, so a
 * write is never broken, only possibly left in plaintext for the sweep to seal.
 */
class SensitiveMySqlConnection extends MySqlConnection
{
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        return Sensitive::openRows(parent::select($query, $bindings, $useReadPdo, $fetchUsing));
    }

    public function selectResultSets($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        return array_map(fn ($set) => Sensitive::openRows($set), parent::selectResultSets($query, $bindings, $useReadPdo, $fetchUsing));
    }

    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        foreach (parent::cursor($query, $bindings, $useReadPdo, $fetchUsing) as $row) {
            yield Sensitive::openRow($row);
        }
    }

    /* MySqlConnection runs inserts itself (to capture lastInsertId), not through statement(),
       so insert()/insertGetId()/Eloquent create() need their own hook. */
    public function insert($query, $bindings = [], $sequence = null)
    {
        return parent::insert($query, $this->sealBindings((string) $query, $bindings), $sequence);
    }

    public function statement($query, $bindings = [])
    {
        return parent::statement($query, $this->sealBindings((string) $query, $bindings));
    }

    public function affectingStatement($query, $bindings = [])
    {
        return parent::affectingStatement($query, $this->sealBindings((string) $query, $bindings));
    }

    /** Seal the bindings that land in a protected column; anything unrecognised is left as it was. */
    protected function sealBindings(string $query, $bindings)
    {
        if (! is_array($bindings) || ! $bindings || ! Sensitive::enabled()) {
            return $bindings;
        }
        try {
            if (! preg_match('/^\s*(insert(?:\s+ignore)?\s+into|replace\s+into|update)\s+`?(\w+)`?/i', $query, $m)) {
                return $bindings;
            }
            $table = strtolower($m[2]);
            $cols = Sensitive::COLUMNS[$table] ?? null;
            if (! $cols) {
                return $bindings;
            }
            $hit = false;
            foreach ($cols as $c => $_) {
                if (stripos($query, $c) !== false) { $hit = true; break; }
            }
            if (! $hit) {
                return $bindings;
            }
            $map = stripos($m[1], 'update') === 0
                ? $this->updateMap($query, strlen($m[0]))
                : $this->insertMap($query, count($bindings));
            if (! $map) {
                return $bindings;
            }
            $keys = array_keys($bindings);
            foreach ($map as $pos => $column) {
                if (! isset($cols[$column]) || ! array_key_exists($pos, $keys)) {
                    continue;
                }
                $k = $keys[$pos];
                $bindings[$k] = Sensitive::seal($bindings[$k], $cols[$column]);
            }
        } catch (\Throwable $e) {
            report($e);   // never fail a write over this; the sweep will seal it
        }
        return $bindings;
    }

    /** insert into `t` (`a`, `b`) values (?, ?), (?, ?)  ->  [bindingIndex => column] */
    private function insertMap(string $q, int $n): array
    {
        if (! preg_match('/\(\s*((?:`?[\w.]+`?\s*,\s*)*`?[\w.]+`?)\s*\)\s*values\s*/i', $q, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $cols = array_map(fn ($c) => $this->bare($c), explode(',', $m[1][0]));
        $w = count($cols);
        $values = substr($q, $m[0][1] + strlen($m[0][0]));
        if (preg_match('/\bon\s+duplicate\s+key\s+update\b/i', $values, $d, PREG_OFFSET_CAPTURE)) {
            $values = substr($values, 0, $d[0][1]);
        }
        // Laravel writes one placeholder per value; anything else is not ours to guess at.
        if ($w === 0 || substr_count($values, '?') !== $n || $n % $w !== 0) {
            return [];
        }
        $map = [];
        for ($i = 0; $i < $n; $i++) {
            $map[$i] = $cols[$i % $w];
        }
        return $map;
    }

    /** update `t` [joins] set `a` = ?, `b` = `b` + 1 where …  ->  [bindingIndex => column] */
    private function updateMap(string $q, int $from): array
    {
        $len = strlen($q);
        $depth = 0; $quote = null; $set = -1; $end = $len; $marks = 0; $before = 0;
        for ($i = $from; $i < $len; $i++) {
            $ch = $q[$i];
            if ($quote) { if ($ch === $quote) { $quote = null; } continue; }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; continue; }
            if ($ch === '(') { $depth++; continue; }
            if ($ch === ')') { $depth--; continue; }
            if ($ch === '?') { $marks++; continue; }
            if ($depth === 0 && ($ch === ' ' || $ch === "\n" || $ch === "\t")) {
                $rest = substr($q, $i + 1, 7);
                if ($set < 0 && preg_match('/^set\s/i', $rest)) {
                    $set = $i + 4; $before = $marks;   // join bindings come first
                } elseif ($set >= 0 && preg_match('/^(where|order|limit)\s/i', $rest)) {
                    $end = $i; break;
                }
            }
        }
        if ($set < 0) {
            return [];
        }
        $map = [];
        $idx = $before;
        foreach ($this->splitTop(substr($q, $set, $end - $set)) as $assign) {
            $marksHere = $this->countMarks($assign);
            if ($marksHere === 1 && preg_match('/^\s*((?:`?\w+`?\.)?`?\w+`?)\s*=\s*\?\s*$/', $assign, $a)) {
                $map[$idx] = $this->bare($a[1]);
            }
            $idx += $marksHere;
        }
        return $map;
    }

    private function splitTop(string $s): array
    {
        $out = []; $buf = ''; $depth = 0; $quote = null;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($quote) { $buf .= $ch; if ($ch === $quote) { $quote = null; } continue; }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buf .= $ch; continue; }
            if ($ch === '(') { $depth++; }
            if ($ch === ')') { $depth--; }
            if ($ch === ',' && $depth === 0) { $out[] = $buf; $buf = ''; continue; }
            $buf .= $ch;
        }
        if (trim($buf) !== '') { $out[] = $buf; }
        return $out;
    }

    private function countMarks(string $s): int
    {
        $n = 0; $quote = null; $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($quote) { if ($ch === $quote) { $quote = null; } continue; }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; continue; }
            if ($ch === '?') { $n++; }
        }
        return $n;
    }

    private function bare(string $c): string
    {
        $c = trim(str_replace('`', '', $c));
        $p = strrpos($c, '.');
        return strtolower($p === false ? $c : substr($c, $p + 1));
    }
}
