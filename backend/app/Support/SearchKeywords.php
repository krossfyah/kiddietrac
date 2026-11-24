<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * THE WORDS ON EVERY SCREEN, KEPT CURRENT (2026-11-09, Anthony: "can you wire up a middleware to add new words for the
 * global search as they become available and to monitor?").
 *
 * The global search finds a screen by the words drawn on it (search-widget.js KT.navSearch). Those words started as a
 * one-off crawl; now the portal learns them as people use it: once a day per screen + subtab, a browser reports the
 * headings, labels, column titles and buttons it shows (POST /diag/search-words). Each phrase passes three gates
 * before it is kept, and nothing that fails is stored (only counted):
 *   1. rules     - 4-48 characters, no digits, dates, greetings, links, or generic words (Save, Next...);
 *   2. source    - written word for word in the portal's own code (parent-portal js + dashboard.html), so nothing a
 *                  person typed (a name, a note, a record) can get in;
 *   3. names     - no word of it is a real person's / child's first or last name, and it holds no centre, room,
 *                  agency or family name. The name list is kept as short hashes, never as names.
 * search_keywords holds what passed (role + screen + phrase + subtab); search-keys:health (nightly) retires words a
 * busy screen no longer shows and emails the platform admins only when learning has stopped. Server health shows it.
 */
final class SearchKeywords
{
    public const ROLES = ['agency_admin', 'centre_director', 'educator', 'guardian', 'home_visitor', 'sales_rep',
        'reseller_admin', 'platform_admin', 'auditor'];
    /** A role without words of its own reads the agency admin's (its menu still decides what can match). */
    public const FALLBACK = ['platform_admin' => 'agency_admin', 'reseller_admin' => 'agency_admin', 'auditor' => 'agency_admin'];
    public const MAX_PHRASES = 60;
    public const RETIRE_DAYS = 90;

    private const STOP = ['all', 'prev', 'next', 'previous', 'refresh', 'save', 'cancel', 'close', 'edit', 'delete', 'remove',
        'add', 'new', 'back', 'done', 'ok', 'yes', 'no', 'apply', 'clear', 'reset', 'search', 'filter', 'filters', 'export',
        'print', 'download', 'upload', 'view', 'open', 'more', 'less', 'show', 'hide', 'today', 'yesterday', 'tomorrow',
        'submit', 'send', 'continue', 'confirm', 'select', 'choose', 'none', 'other', 'total', 'name', 'email', 'phone',
        'date', 'time', 'status', 'actions', 'action', 'details', 'notes', 'note', 'type', 'on', 'off', 'loading', 'enabled',
        'disabled', 'active', 'inactive', 'optional', 'required', 'help', 'settings', 'view all', 'see all', 'learn more',
        'read more', 'try again', 'retry', 'cards', 'table', 'list', 'grid', 'tiles'];
    /** Portal words that are also a name field on some test / service account (reviewed 2026-11-09). */
    private const NOT_NAMES = ['child', 'test', 'home', 'parent', 'visitor', 'admin', 'user', 'sales', 'grant', 'sage'];

    /** Gate 1: the shape of a UI phrase. */
    public static function ruleOk(string $s): bool
    {
        $l = mb_strtolower($s);
        $n = mb_strlen($s);
        if ($n < 4 || $n > 48 || preg_match('/\d/', $s) || in_array($l, self::STOP, true)) return false;
        // ALL CAPS is an eyebrow or a CSS-uppercased header read off the screen, not the words as written.
        if (! preg_match('/\p{Ll}/u', $s)) return false;
        if (preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\b/', $l)
            || preg_match('/\b(mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)(day)?\b/', $l)
            || preg_match('/^(good (morning|afternoon|evening|night)|hi|hello|welcome)\b/', $l)) return false;
        if (preg_match('/[@$%#]|https?:/', $s) || ! preg_match('/[a-zA-Z]{3}/', $s)) return false;

        return count(preg_split('/\s+/', trim($s))) <= 7;
    }

    /** Gate 2: written in the portal's own code. The code is read once per deploy (keyed by dashboard.html's time). */
    public static function inSource(string $s): bool
    {
        $blob = self::sourceBlob();
        foreach (array_unique([$s, str_replace('&', '&amp;', $s), str_replace('’', "'", $s), str_replace("'", "\\'", $s),
            str_replace('’', '\\u2019', $s), str_replace("'", '’', $s)]) as $v) {
            if ($v !== '' && strpos($blob, $v) !== false) return true;
        }

        return false;
    }

    private static ?string $blob = null;

    private static function sourceBlob(): string
    {
        if (self::$blob !== null) return self::$blob;
        $root = base_path('../parent-portal');
        $stamp = (string) @filemtime($root . '/dashboard.html');
        $file = storage_path('app/kt-search-source-' . md5($stamp) . '.txt');
        if (! is_file($file)) {
            $parts = [(string) @file_get_contents($root . '/dashboard.html')];
            foreach (glob($root . '/js/*.js') ?: [] as $f) $parts[] = (string) @file_get_contents($f);
            foreach (glob(storage_path('app/kt-search-source-*.txt')) ?: [] as $old) @unlink($old);
            @file_put_contents($file . '.tmp', implode("\n", $parts));
            @rename($file . '.tmp', $file);
        }

        return self::$blob = (string) @file_get_contents($file);
    }

    /** Gate 3: holds a real name. Name words are compared as short hashes; the cache never holds a name. */
    public static function hasName(string $s): bool
    {
        $n = self::names();
        $l = mb_strtolower($s);
        foreach (preg_split('/[^\p{L}\']+/u', $l) as $w) {
            if (mb_strlen($w) >= 3 && isset($n['tok'][self::h($w)])) return true;
        }
        // Whole centre / room / agency / family names: every run of 1-6 words of the phrase.
        $words = array_values(array_filter(preg_split('/\s+/', $l)));
        for ($i = 0; $i < count($words); $i++) {
            $run = '';
            for ($j = $i; $j < min(count($words), $i + 6); $j++) {
                $run = trim($run . ' ' . $words[$j]);
                if (isset($n['whole'][self::h($run)])) return true;
            }
        }

        return false;
    }

    private static function h(string $s): string
    {
        return substr(md5($s), 0, 12);
    }

    private static ?array $names = null;

    private static function names(): array
    {
        return self::$names ??= Cache::remember('kt_sk_names_v1', 1800, function () {
            $tok = [];
            foreach ([['users', ['first_name', 'last_name', 'preferred_name']], ['children', ['first_name', 'last_name', 'preferred_name']],
                ['guardians', ['first_name', 'last_name']], ['emergency_contacts', ['first_name', 'last_name', 'name']],
                ['authorized_pickups', ['first_name', 'last_name', 'name']]] as [$t, $cols]) {
                if (! \Illuminate\Support\Facades\Schema::hasTable($t)) continue;
                $cols = array_values(array_filter($cols, fn ($c) => \Illuminate\Support\Facades\Schema::hasColumn($t, $c)));
                if (! $cols) continue;
                foreach (DB::table($t)->select($cols)->cursor() as $r) {
                    foreach ($cols as $c) {
                        foreach (preg_split('/[^\p{L}\']+/u', mb_strtolower((string) $r->$c)) as $w) {
                            if (mb_strlen($w) >= 3 && ! in_array($w, self::NOT_NAMES, true)) $tok[self::h($w)] = 1;
                        }
                    }
                }
            }
            $whole = [];
            foreach ([['centres', 'name'], ['rooms', 'name'], ['agencies', 'name'], ['families', 'name'], ['families', 'family_name']] as [$t, $c]) {
                if (! \Illuminate\Support\Facades\Schema::hasTable($t) || ! \Illuminate\Support\Facades\Schema::hasColumn($t, $c)) continue;
                foreach (DB::table($t)->whereNotNull($c)->pluck($c) as $v) {
                    $v = trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $v)));
                    if (mb_strlen($v) >= 4) $whole[self::h($v)] = 1;
                }
            }

            return ['tok' => $tok, 'whole' => $whole];
        });
    }

    /**
     * One browser's report of a screen. Returns which phrases were new (accepted), already known, and the positions
     * of the rejected ones (the browser remembers a fingerprint of those so it never sends them again).
     */
    public static function report(string $role, string $hash, string $tab, array $phrases): array
    {
        $tab = self::ruleOk($tab) && self::inSource($tab) && ! self::hasName($tab) ? $tab : '';
        $now = now();
        $clean = [];
        foreach (array_slice($phrases, 0, self::MAX_PHRASES) as $i => $p) {
            $p = trim(preg_replace(['/\s+/u', '/(\x{2026}|\.{3})$/u'], [' ', ''], (string) $p));
            $clean[$i] = $p;
        }
        $known = DB::table('search_keywords')->where('role', $role)->where('hash', $hash)
            ->whereIn('phrase', array_values(array_filter($clean)) ?: [''])->pluck('id', 'phrase');
        $out = ['accepted' => [], 'known' => 0, 'rejected' => []];
        $c = ['accepted' => 0, 'known' => 0, 'rej_rule' => 0, 'rej_source' => 0, 'rej_name' => 0];
        $knownIds = [];
        foreach ($clean as $i => $p) {
            if (isset($known[$p])) { $knownIds[] = $known[$p]; $c['known']++; $out['known']++; continue; }
            $why = ! self::ruleOk($p) ? 'rej_rule' : (! self::inSource($p) ? 'rej_source' : (self::hasName($p) ? 'rej_name' : null));
            if ($why) { $c[$why]++; $out['rejected'][] = $i; continue; }
            $ins = DB::table('search_keywords')->insertOrIgnore(['role' => $role, 'hash' => $hash, 'phrase' => $p, 'tab' => $tab,
                'source' => 'seen', 'first_seen_at' => $now, 'last_seen_at' => $now, 'seen_count' => 1]);
            if ($ins) { $c['accepted']++; $out['accepted'][] = $tab !== '' ? [$p, $tab] : [$p]; }
        }
        if ($knownIds) {
            DB::table('search_keywords')->whereIn('id', $knownIds)->update(['last_seen_at' => $now, 'retired_at' => null,
                'seen_count' => DB::raw('seen_count + 1')]);
        }
        DB::table('search_keyword_screens')->upsert([['role' => $role, 'hash' => $hash, 'first_report_at' => $now, 'last_report_at' => $now, 'reports' => 1]],
            ['role', 'hash'], ['last_report_at' => $now, 'reports' => DB::raw('reports + 1')]);
        $day = $now->copy()->setTimezone('America/Toronto')->toDateString();
        DB::table('search_keyword_days')->upsert([['day' => $day, 'role' => $role, 'reports' => 1] + $c], ['day', 'role'],
            ['reports' => DB::raw('reports + 1')] + array_map(fn ($k) => DB::raw("$k + " . (int) $c[$k]), array_combine(array_keys($c), array_keys($c))));
        if ($c['accepted']) Cache::forever('kt_sk_version', (string) $now->getTimestampMs());

        return $out;
    }

    /** The search index for one role: {screen: [[phrase, subtab?]...]}, plus a version that changes when it grows. */
    public static function index(string $role): array
    {
        $role = in_array($role, self::ROLES, true) ? $role : 'agency_admin';
        $use = DB::table('search_keywords')->where('role', $role)->whereNull('retired_at')->exists() ? $role : (self::FALLBACK[$role] ?? $role);
        $keys = [];
        foreach (DB::table('search_keywords')->where('role', $use)->whereNull('retired_at')->orderBy('hash')->orderBy('id')
            ->get(['hash', 'phrase', 'tab']) as $r) {
            $keys[$r->hash][] = $r->tab !== '' ? [$r->phrase, $r->tab] : [$r->phrase];
        }

        return ['version' => self::version(), 'role' => $use, 'keys' => (object) $keys];
    }

    public static function version(): string
    {
        return (string) Cache::get('kt_sk_version', '0');
    }

    /** Retire words a screen no longer shows: not seen for RETIRE_DAYS while that screen was reported this week. */
    public static function retire(): int
    {
        return DB::table('search_keywords as k')
            ->join('search_keyword_screens as s', fn ($j) => $j->on('s.role', '=', 'k.role')->on('s.hash', '=', 'k.hash'))
            ->whereNull('k.retired_at')->where('k.last_seen_at', '<', now()->subDays(self::RETIRE_DAYS))
            ->where('s.last_report_at', '>', now()->subDays(7))
            ->update(['k.retired_at' => now()]);
    }

    /** For Server health and the nightly check. */
    public static function health(): array
    {
        $day = now()->setTimezone('America/Toronto')->toDateString();
        $sum = fn ($from) => (array) DB::table('search_keyword_days')->where('day', '>=', $from)
            ->selectRaw('COALESCE(SUM(reports),0) reports, COALESCE(SUM(accepted),0) accepted, COALESCE(SUM(known),0) known, COALESCE(SUM(rej_rule),0) rej_rule, COALESCE(SUM(rej_source),0) rej_source, COALESCE(SUM(rej_name),0) rej_name')->first();
        $live = DB::table('search_keywords')->whereNull('retired_at')->selectRaw('role, COUNT(*) n, COUNT(DISTINCT hash) screens')->groupBy('role')->get();
        $firstWord = DB::table('search_keywords')->where('source', 'seen')->selectRaw('role, hash, MIN(first_seen_at) f')->groupBy('role', 'hash');
        $newScreens = DB::query()->fromSub($firstWord, 'x')
            ->whereNotExists(fn ($q) => $q->from('search_keywords as c')->whereColumn('c.role', 'x.role')->whereColumn('c.hash', 'x.hash')->where('c.source', 'crawl'))
            ->where('x.f', '>=', now()->subDays(7))->orderByDesc('x.f')->limit(12)->get(['x.role', 'x.hash']);
        $bare = DB::table('search_keyword_screens as s')->where('s.last_report_at', '>=', now()->subDays(7))
            ->whereNotExists(fn ($q) => $q->from('search_keywords as k')->whereColumn('k.role', 's.role')->whereColumn('k.hash', 's.hash')->whereNull('k.retired_at'))
            ->orderBy('s.hash')->limit(12)->get(['s.role', 's.hash']);
        $last = DB::table('search_keyword_screens')->max('last_report_at');

        return [
            'today' => $sum($day),
            'week' => $sum(now()->setTimezone('America/Toronto')->subDays(6)->toDateString()),
            'live_total' => (int) $live->sum('n'),
            'by_role' => $live->map(fn ($r) => ['role' => $r->role, 'phrases' => (int) $r->n, 'screens' => (int) $r->screens])->values(),
            'learned_7d' => DB::table('search_keywords')->where('source', 'seen')->where('first_seen_at', '>=', now()->subDays(7))->count(),
            'retired_30d' => DB::table('search_keywords')->where('retired_at', '>=', now()->subDays(30))->count(),
            'new_screens_7d' => $newScreens,
            'screens_without_words' => $bare,
            'last_report_at' => $last ? \Illuminate\Support\Carbon::parse($last)->toIso8601String() : null,
            'hours_since_report' => $last ? (int) \Illuminate\Support\Carbon::parse($last)->diffInHours(now()) : null,
            'version' => self::version(),
        ];
    }
}
