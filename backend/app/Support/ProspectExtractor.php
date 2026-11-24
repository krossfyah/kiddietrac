<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cold reach → Import (2026-10-30). Anthony: "if i import list - have AI or system strip out the relevant important
 * [information] to populate the cold lead (person's name, address, company name, website, ph#, email, business
 * address and create the prospect record)".
 *
 * Takes whatever was pasted or uploaded - a spreadsheet saved as CSV with any column names, a directory listing copied
 * from a website, an email signature block, one centre per line - and returns tidy prospect rows for a preview the
 * person checks before anything is saved.
 *  1. AI first (Claude, as JSON) when it is switched on and has credit.
 *  2. Otherwise - or when the AI answer is unusable - the rules below: header synonyms for tables, and for free text
 *     one record per block, picking out email, phone, website, street address, postal code, province, organization
 *     and person by pattern.
 * Nothing here saves anything; SalesProspectController::import does, after the preview.
 */
final class ProspectExtractor
{
    public const FIELDS = ['organization', 'name', 'title', 'email', 'phone', 'website', 'address', 'city', 'province', 'postal_code', 'country'];
    private const PROV = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'];
    private const PROV_NAMES = ['ontario' => 'ON', 'quebec' => 'QC', 'québec' => 'QC', 'british columbia' => 'BC', 'alberta' => 'AB', 'manitoba' => 'MB',
        'saskatchewan' => 'SK', 'nova scotia' => 'NS', 'new brunswick' => 'NB', 'newfoundland and labrador' => 'NL', 'newfoundland' => 'NL',
        'prince edward island' => 'PE', 'yukon' => 'YT', 'northwest territories' => 'NT', 'nunavut' => 'NU'];
    private const US = ['AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO',
        'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'DC'];
    private const FREE_MAIL = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'yahoo.ca', 'live.com', 'live.ca', 'icloud.com', 'me.com', 'aol.com', 'rogers.com', 'bell.net', 'sympatico.ca', 'shaw.ca', 'telus.net', 'cogeco.ca', 'msn.com', 'protonmail.com'];
    private const ORG_WORDS = '/\b(day ?care|child ?care|childcare|centre|center|school|learning|academy|montessori|preschool|pre-school|nursery|kids?|kinder|early|play|tots|little|ymca|ymca|inc\.?|ltd\.?|llc|corp|co-op|cooperative|program|garderie|cpe|services?)\b/i';
    private const STREET = '/\b\d{1,6}[A-Za-z]?\s+[\w\'.\- ]{2,40}\b(street|st|road|rd|avenue|ave|boulevard|blvd|drive|dr|lane|ln|way|crescent|cres|court|ct|highway|hwy|place|pl|parkway|pkwy|line|sideroad|concession|trail|circle|cir|square|sq|terrace|gate|rue|chemin)\b\.?/i';

    /** @return array{rows: array<int, array>, method: string} */
    public static function extract(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", self::clean($text)));
        if ($text === '') return ['rows' => [], 'method' => 'rules'];
        // A table (CSV / tab / semicolon with a header row) is already structured: read it column by column, any size.
        $table = self::asTable($text);
        if ($table) return ['rows' => self::tidyAll($table), 'method' => 'table'];
        // Free text: the AI reads it in pieces (no length limit), the rules take over for any piece it cannot.
        $rows = []; $usedAi = false;
        foreach (self::chunks($text, 40000) as $piece) {
            $ai = self::viaAi($piece);
            if ($ai !== null && count($ai)) { $rows = array_merge($rows, $ai); $usedAi = true; }
            else $rows = array_merge($rows, self::viaRules($piece));
        }
        return ['rows' => self::tidyAll($rows), 'method' => $usedAi ? 'ai' : 'rules'];
    }

    /**
     * An uploaded spreadsheet (xlsx, xls, ods, csv, tsv) → rows. Read with PhpSpreadsheet as cells, never as text, so a
     * binary file can never turn into garbage. Every sheet with a recognisable header row is read; no row limit.
     */
    public static function fromFile(string $path, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, ['txt', 'text'], true)) return self::extract((string) file_get_contents($path));
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(true);
        if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) $reader->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::GUESS_ENCODING);
        $book = $reader->load($path);
        $rows = []; $anyTable = false; $loose = [];
        foreach ($book->getAllSheets() as $sheet) {
            try { $grid = self::sheetGrid($sheet); } catch (\Throwable $e) {
                Log::info('cold reach import: sheet skipped', ['sheet' => $sheet->getTitle(), 'error' => $e->getMessage()]);
                continue;   // one unreadable sheet (a summary tab, a chart) never stops the others
            }
            $grid = array_values(array_filter($grid, fn ($r) => is_array($r) && count(array_filter($r, fn ($c) => trim((string) $c) !== '')) > 0));
            if (! $grid) continue;
            $t = self::fromGrid($grid);
            if ($t !== null) {
                $title = $sheet->getTitle();
                foreach ($t as &$x) { $x['sheet'] = $title; }
                unset($x);
                $rows = array_merge($rows, $t); $anyTable = true; continue;
            }
            foreach ($grid as $r) $loose[] = implode("\n", array_filter(array_map(fn ($c) => trim((string) $c), $r), fn ($c) => $c !== ''));
        }
        $book->disconnectWorksheets();
        if (! $anyTable && $loose) return self::extract(implode("\n\n", $loose));   // no header anywhere: read cells as text blocks
        return ['rows' => self::tidyAll($rows), 'method' => 'table'];
    }

    /**
     * A sheet's cells as text, WITHOUT recalculating formulas. Excel saves each formula's last result in the file;
     * that is what is read. Recalculating (toArray(..., true)) failed on a real workbook ("Summary!C5 -> Formula Error")
     * and took the whole import down with it (2026-10-30).
     */
    private static function sheetGrid(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $grid = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(false);
            $r = [];
            foreach ($cells as $cell) {
                $v = null;
                try {
                    if ($cell->isFormula()) {
                        $v = $cell->getOldCalculatedValue();
                    } else {
                        $v = $cell->getValue();
                        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) $v = $v->getPlainText();
                        // Dates and numbers as the person sees them; a phone number stored as a number keeps its digits.
                        if (is_float($v) || is_int($v)) $v = \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell) ? $cell->getFormattedValue() : (string) (is_float($v) && floor($v) == $v ? number_format($v, 0, '.', '') : $v);
                    }
                } catch (\Throwable $e) { $v = null; }
                $r[] = is_scalar($v) ? (string) $v : '';
            }
            $grid[] = $r;
        }
        return $grid;
    }

    private static function chunks(string $text, int $size): array
    {
        if (mb_strlen($text) <= $size) return [$text];
        $out = []; $cur = '';
        foreach (preg_split("/\n\s*\n/", $text) as $block) {
            if ($cur !== '' && mb_strlen($cur) + mb_strlen($block) > $size) { $out[] = $cur; $cur = ''; }
            $cur .= ($cur === '' ? '' : "\n\n") . $block;
        }
        if ($cur !== '') $out[] = $cur;
        return $out;
    }

    /** Text with control characters, binary runs and replacement characters removed. */
    private static function clean(string $s): string
    {
        if (! mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{FFFD}/u', '', $s) ?? '';
        return $s;
    }

    private static function viaAi(string $text): ?array
    {
        try {
            if (AiConfig::offFor(null)) return null;
            $key = AiConfig::key();
            if (! $key) return null;
            $prompt = "Extract every business contact from the text below into JSON. Each contact is one childcare business / "
                . "centre / organization (or a person at one). Return ONLY a JSON array of objects with these keys (use null when "
                . "unknown, never invent data): organization, name (the person), title, email, phone, website, address (street "
                . "line only), city, province (2-letter code), postal_code, country (2-letter code). If one organization lists "
                . "several people, return one object per person. Ignore headers, page furniture and duplicates.\n\nTEXT:\n" . mb_substr($text, 0, 60000);
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
                ->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                    'model' => AiConfig::model(), 'max_tokens' => 8000,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
            AiConfig::noteReply($r->successful(), $r->status(), $r->successful() ? '' : (string) $r->json('error.message'));
            if (! $r->successful()) return null;
            $out = (string) AiConfig::text($r->json());
            if (! preg_match('/\[[\s\S]*\]/', $out, $m)) return null;
            $rows = json_decode($m[0], true);
            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : null;
        } catch (\Throwable $e) {
            Log::info('prospect extract: AI unavailable, using rules', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ── rules ──────────────────────────────────────────────────────────────────────────────────────────────────

    private static function viaRules(string $text): array
    {
        // Free text: one record per block (blank-line separated); a block-less list with one email per line = a line each.
        $blocks = preg_split("/\n\s*\n+/", $text);
        if (count($blocks) === 1) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
            $withEmail = array_filter($lines, fn ($l) => preg_match('/@[\w.-]+\.\w{2,}/', $l));
            if (count($withEmail) >= 2 && count($withEmail) >= count($lines) * 0.6) $blocks = $lines;
        }
        $rows = [];
        foreach ($blocks as $b) {
            $r = self::fromBlock($b);
            if ($r) $rows[] = $r;
        }
        return $rows;
    }

    /** A table with a header row (comma, tab, semicolon or pipe separated; quoted fields may hold line breaks). */
    private static function asTable(string $text): ?array
    {
        $lines = array_values(array_filter(explode("\n", $text), fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) return null;
        $sep = null;
        foreach (["\t", ',', ';', '|'] as $s) { if (substr_count($lines[0], $s) >= 1 && substr_count($lines[1], $s) >= 1) { $sep = $s; break; } }
        if ($sep === null) return null;
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $text); rewind($fh);
        $grid = [];
        while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) { if (count(array_filter($r, fn ($c) => trim((string) $c) !== '')) > 0) $grid[] = $r; }
        fclose($fh);
        return self::fromGrid($grid);
    }

    /**
     * Rows and columns → prospects. The header row is the row (among the first 15 - title rows above a table are
     * common) whose cells name the most known columns; at least two must match, or this is not a contact table.
     */
    public static function fromGrid(array $grid): ?array
    {
        $syn = self::synonyms();
        $best = -1; $bestMap = [];
        foreach (array_slice($grid, 0, 15, true) as $ri => $row) {
            $map = [];
            foreach ($row as $i => $h) {
                $h = trim(preg_replace('/\s+/', ' ', (string) $h), " \t\"'*:");
                if ($h === '' || mb_strlen($h) > 40) continue;
                foreach ($syn as $k => $re) { if (! in_array($k, $map, true) && preg_match($re, $h)) { $map[$i] = $k; break; } }
            }
            if (count($map) > count($bestMap)) { $bestMap = $map; $best = $ri; }
        }
        if (count($bestMap) < 2) return null;
        $rows = [];
        foreach (array_slice($grid, $best + 1) as $c) {
            $r = [];
            foreach ($bestMap as $i => $k) { $v = trim(preg_replace('/\s+/', ' ', (string) ($c[$i] ?? ''))); if ($v !== '') $r[$k] = isset($r[$k]) ? $r[$k] . ' ' . $v : $v; }
            if (isset($r['first']) || isset($r['last'])) { $r['name'] = trim(($r['first'] ?? '') . ' ' . ($r['last'] ?? '')); unset($r['first'], $r['last']); }
            if (isset($r['address2'])) { $r['address'] = trim(($r['address'] ?? '') . ', ' . $r['address2'], ', '); unset($r['address2']); }
            // The operator (licensee) is the organization only when the sheet has no centre name; otherwise a note.
            if (isset($r['operator'])) {
                if (empty($r['organization'])) $r['organization'] = $r['operator'];
                elseif (mb_strtolower($r['operator']) !== mb_strtolower($r['organization'])) $r['notes'] = 'Operator: ' . $r['operator'];
                unset($r['operator']);
            }
            // A whole address in one column: split it.
            if (! empty($r['address']) && empty($r['city'])) $r = array_merge(self::splitAddress($r['address']), array_filter($r, fn ($v, $k) => $k !== 'address', ARRAY_FILTER_USE_BOTH));
            // An email in the website column / a website in the email column, a phone stored as a number.
            if (empty($r['website']) && ! empty($r['email'])) {
                $dom = substr((string) strrchr($r['email'], '@'), 1);
                if ($dom && ! in_array(strtolower($dom), self::FREE_MAIL, true)) $r['website'] = strtolower($dom);
            }
            if (array_filter($r)) $rows[] = $r;
        }
        return $rows;
    }

    /**
     * Header names → fields. Checked in this order, and each pattern is anchored, so "Main site name" is the centre
     * (not the website - the word "site" alone matched Website and pushed the real Website column out, 2026-10-30),
     * "Main site address" is the address, and "Email source" / "Website notes" are not the email or the website.
     */
    private static function synonyms(): array
    {
        $org = 'organi[sz]ation|company|business|centre|center|daycare|day care|child ?care|program|programme|school|facility|provider|account|site|main site|location|agency|operating';
        return [
            'email' => '/^(e-?mail|e-?mail address|contact e-?mail|primary e-?mail|business e-?mail|work e-?mail)$/i',
            'phone' => '/^(phone|phone ?#|phone number|telephone|tel|main phone|business phone|contact phone|contact number|mobile|cell|work phone)$/i',
            'website' => '/^(web ?site|website url|url|web|homepage|web address|site url)$/i',
            'address' => '/^((street|civic|mailing|business|site|main site|location|physical|centre|center)\s+)?address( ?1| line ?1)?$|^street$/i',
            'address2' => '/^(address ?2|address line ?2|unit|suite|unit ?\/ ?suite)$/i',
            'postal_code' => '/^(postal|postal code|zip|zip code|post code|postcode|postal ?\/ ?zip)$/i',
            'city' => '/^(city|town|municipality|city ?\/ ?town|community)$/i',
            'province' => '/^(province|state|prov|province ?\/ ?state)$/i',
            'country' => '/^country$/i',
            'organization' => '/^(' . $org . ')( \w+)?( name)?$|^name of (the )?(' . $org . ')$|^(legal|operating|trade|business|company|centre|center|site|main site) name$/i',
            'operator' => '/^(operator|licensee|owner organi[sz]ation|parent company|operator \(licensee\)|licensee \(operator\)|operator name|licensee name)$/i',
            'name' => '/^(name|contact|contact name|contact person|full name|person|owner|owner name|director|director name|supervisor|primary contact|key contact|attention|attn)$/i',
            'first' => '/^first( name)?$|^given name$/i', 'last' => '/^(last|sur)( ?name)?$|^family name$/i',
            'title' => '/^(title|role|position|job title|contact title|contact role)$/i',
        ];
    }

    private static function fromBlock(string $b): ?array
    {
        $lines = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/\s+/', ' ', $l), " \t•·-|,;"), explode("\n", $b))));
        if (! $lines) return null;
        // One line holding a whole record ("Org - Person - phone - email - address"): its pieces are the lines.
        if (count($lines) === 1) {
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s+[-–—|•·]\s+|\s*\|\s*/u', $lines[0]))));
            if (count($parts) >= 3) $lines = $parts;
        }
        $all = implode("\n", $lines);
        $r = [];
        if (preg_match('/[\w.+\'-]+@[\w-]+(\.[\w-]+)+/', $all, $m)) $r['email'] = mb_strtolower($m[0]);
        // Same line only ([ .-], never a newline), and not glued to a postal code or another number.
        if (preg_match('/(?<![\w])(\+?1[ .-])?\(?\d{3}\)?[ .-]?\d{3}[ .-]?\d{4}(?!\d)( *(x|ext\.?) *\d{1,5})?/i', $all, $m)) $r['phone'] = trim($m[0]);
        if (preg_match('/\b((https?:\/\/)?(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)*\.(ca|com|org|net|info|biz|co|us|education|school)(\/[^\s,]*)?)\b/i', preg_replace('/[\w.+\'-]+@[\w.-]+/', ' ', $all), $m)) $r['website'] = $m[1];
        if (empty($r['website']) && ! empty($r['email'])) {
            $dom = substr(strrchr($r['email'], '@'), 1);
            if ($dom && ! in_array($dom, self::FREE_MAIL, true)) $r['website'] = $dom;
        }
        $used = [];
        foreach ($lines as $i => $l) {
            if (preg_match(self::STREET, $l)) {
                $r = array_merge($r, self::splitAddress($l . (isset($lines[$i + 1]) && ! preg_match('/@|\d{3}[\s.-]?\d{4}/', $lines[$i + 1]) && preg_match('/\b([A-Z]\d[A-Z]\s?\d[A-Z]\d|\d{5})\b|,\s*[A-Z]{2}\b/i', $lines[$i + 1]) ? ', ' . $lines[$i + 1] : '')));
                $used[$i] = 1; if (isset($lines[$i + 1]) && ! empty($r['postal_code']) && stripos($lines[$i], $r['postal_code']) === false) $used[$i + 1] = 1;
                break;
            }
        }
        if (empty($r['postal_code']) && preg_match('/\b([A-Z]\d[A-Z])\s?(\d[A-Z]\d)\b/i', $all, $m)) $r['postal_code'] = strtoupper($m[1] . ' ' . $m[2]);
        // Labelled lines ("Contact: Jane Doe", "Director - Jane Doe").
        foreach ($lines as $i => $l) {
            if (preg_match('/^(contact|owner|director|supervisor|name|attn|attention|principal|manager)\s*[:\-–]\s*(.+)$/i', $l, $m)) { $r['name'] = trim($m[2]); if (empty($r['title']) && preg_match('/^(owner|director|supervisor|principal|manager)$/i', $m[1])) $r['title'] = ucfirst(strtolower($m[1])); $used[$i] = 1; }
            elseif (preg_match('/^(company|organi[sz]ation|business|centre|center|daycare)\s*[:\-–]\s*(.+)$/i', $l, $m)) { $r['organization'] = trim($m[2]); $used[$i] = 1; }
        }
        // What is left: the organization line and the person line.
        foreach ($lines as $i => $l) {
            if (isset($used[$i]) || preg_match('/@|https?:|www\.|\d{3}[\s.-]?\d{3}[\s.-]?\d{4}/i', $l) || mb_strlen($l) > 90) continue;
            $clean = trim(preg_replace('/^(tel|phone|email|e-mail|web|website|fax)\s*[:\-]\s*/i', '', $l));
            if ($clean === '') continue;
            if (empty($r['organization']) && preg_match(self::ORG_WORDS, $clean)) { $r['organization'] = $clean; continue; }
            if (empty($r['name']) && preg_match('/^[A-Z][a-zA-Z\'’.-]+(\s+[A-Z][a-zA-Z\'’.-]+){1,3}(,\s*[\w .]+)?$/u', $clean) && ! preg_match(self::ORG_WORDS, $clean)) {
                if (preg_match('/^(.+?),\s*(.+)$/', $clean, $m)) { $r['name'] = $m[1]; $r['title'] = $m[2]; } else $r['name'] = $clean;
                continue;
            }
            if (empty($r['organization']) && $i === 0) $r['organization'] = $clean;
        }
        // A single line "Org Name, email, phone" style.
        if (empty($r['organization']) && empty($r['name']) && count($lines) === 1) {
            $first = trim(preg_split('/[,|;\t]/', $lines[0])[0]);
            if ($first !== '' && ! preg_match('/@|\d{3}/', $first)) $r['organization'] = $first;
        }
        if (empty($r['organization']) && empty($r['name']) && empty($r['email'])) return null;
        return $r;
    }

    /** "123 Main St, Unit 4, Barrie, ON L4M 1A1" → address / city / province / postal / country. */
    public static function splitAddress(string $s): array
    {
        $out = [];
        $s = trim(preg_replace('/\s+/', ' ', $s), ' ,');
        if (preg_match('/\b([A-Z]\d[A-Z])\s?(\d[A-Z]\d)\b/i', $s, $m)) { $out['postal_code'] = strtoupper($m[1] . ' ' . $m[2]); $out['country'] = 'CA'; $s = trim(str_replace($m[0], '', $s), ' ,'); }
        elseif (preg_match('/\b(\d{5})(-\d{4})?\s*$/', $s, $m)) { $out['postal_code'] = $m[0]; $out['country'] = 'US'; $s = trim(substr($s, 0, -strlen($m[0])), ' ,'); }
        if (preg_match('/\b(canada|usa|united states)\s*$/i', $s, $m)) { $out['country'] = stripos($m[1], 'canada') !== false ? 'CA' : 'US'; $s = trim(substr($s, 0, -strlen($m[0])), ' ,'); }
        $parts = array_values(array_filter(array_map('trim', explode(',', $s))));
        // province: last part (or last word of it)
        if ($parts) {
            $last = end($parts);
            $code = strtoupper(trim($last));
            $name = mb_strtolower(trim($last));
            if (in_array($code, self::PROV, true) || in_array($code, self::US, true)) { $out['province'] = $code; array_pop($parts); }
            elseif (isset(self::PROV_NAMES[$name])) { $out['province'] = self::PROV_NAMES[$name]; array_pop($parts); }
            elseif (preg_match('/^(.+?)\s+([A-Z]{2})$/', trim($last), $m) && (in_array($m[2], self::PROV, true) || in_array($m[2], self::US, true))) {
                $out['province'] = $m[2]; $parts[count($parts) - 1] = $m[1];
            }
            if (! empty($out['province']) && empty($out['country'])) $out['country'] = in_array($out['province'], self::PROV, true) ? 'CA' : 'US';
        }
        if (count($parts) >= 2) { $out['city'] = array_pop($parts); $out['address'] = implode(', ', $parts); }
        elseif (count($parts) === 1) { if (preg_match('/\d/', $parts[0])) $out['address'] = $parts[0]; else $out['city'] = $parts[0]; }
        return $out;
    }

    private static function tidyAll(array $rows): array
    {
        $out = []; $seen = [];
        foreach ($rows as $r) {
            $t = [];
            foreach (self::FIELDS as $f) {
                $v = $r[$f] ?? null;
                $v = is_scalar($v) ? trim(preg_replace('/\s+/u', ' ', self::clean((string) $v))) : '';
                // Not data: placeholders, and anything that is not readable text (binary, symbol runs, overlong).
                if ($v === '' || preg_match('/^(null|n\/?a|none|-+|\.+|#n\/a|#value!|#ref!|0)$/i', $v)) { $t[$f] = null; continue; }
                $letters = preg_match_all('/[\p{L}\p{N}]/u', $v);
                if (mb_strlen($v) > 200 || $letters < max(1, (int) (mb_strlen($v) * 0.45))) { $t[$f] = null; continue; }
                $t[$f] = $v;
            }
            if ($t['email']) { $t['email'] = mb_strtolower(trim($t['email'], '<>()[] ;,')); if (! filter_var($t['email'], FILTER_VALIDATE_EMAIL)) $t['email'] = null; }
            if ($t['phone']) { $digits = preg_replace('/\D/', '', $t['phone']); if (strlen($digits) < 7 || strlen($digits) > 15) $t['phone'] = null; }
            if ($t['postal_code'] && ! preg_match('/^([A-Za-z]\d[A-Za-z] ?\d[A-Za-z]\d|\d{5}(-\d{4})?)$/', $t['postal_code'])) $t['postal_code'] = null;
            if ($t['postal_code'] && preg_match('/^([A-Za-z]\d[A-Za-z]) ?(\d[A-Za-z]\d)$/', $t['postal_code'], $pm)) $t['postal_code'] = strtoupper($pm[1] . ' ' . $pm[2]);
            // No province given: a Canadian postal code says which (its first letter).
            if (! $t['province'] && $t['postal_code'] && preg_match('/^[A-Z]\d[A-Z]/', $t['postal_code'])) {
                $t['province'] = ['A' => 'NL', 'B' => 'NS', 'C' => 'PE', 'E' => 'NB', 'G' => 'QC', 'H' => 'QC', 'J' => 'QC', 'K' => 'ON', 'L' => 'ON', 'M' => 'ON', 'N' => 'ON', 'P' => 'ON',
                    'R' => 'MB', 'S' => 'SK', 'T' => 'AB', 'V' => 'BC', 'Y' => 'YT'][$t['postal_code'][0]] ?? null;
                $t['country'] = $t['country'] ?: 'CA';
            }
            if ($t['website'] && ! preg_match('/^(https?:\/\/)?[\w-]+(\.[\w-]+)+(\/\S*)?$/i', $t['website'])) $t['website'] = null;
            foreach (['organization', 'name', 'city'] as $f) { if ($t[$f] && preg_match('/@|^\+?[\d\s().-]{7,}$/', $t[$f])) $t[$f] = null; }   // an email / phone in a name column
            if ($t['province']) { $p = strtoupper($t['province']); $t['province'] = self::PROV_NAMES[mb_strtolower($t['province'])] ?? (strlen($p) <= 3 ? $p : $t['province']); }
            if ($t['country']) { $c = strtoupper($t['country']); $t['country'] = in_array($c, ['CANADA', 'CA'], true) ? 'CA' : (in_array($c, ['USA', 'US', 'UNITED STATES'], true) ? 'US' : (strlen($c) === 2 ? $c : null)); }
            if ($t['website']) $t['website'] = preg_replace('#^https?://#i', '', rtrim($t['website'], '/'));
            if (! $t['organization'] && ! $t['name'] && ! $t['email']) continue;
            // A prospect needs a way to reach them: an email, a phone, or a street address. A row with only a name
            // (a summary line, a heading, a total) is not a contact.
            if (! $t['email'] && ! $t['phone'] && ! $t['address'] && ! $t['website']) continue;
            $t['sheet'] = isset($r['sheet']) ? mb_substr((string) $r['sheet'], 0, 60) : null;
            $t['notes'] = isset($r['notes']) && is_scalar($r['notes']) ? mb_substr(trim((string) $r['notes']), 0, 300) : null;
            // The same contact on two sheets is one prospect: by email, else by centre + postal code / address.
            $keys = array_filter([$t['email'], ($t['organization'] && ($t['postal_code'] || $t['address'])) ? mb_strtolower($t['organization'] . '|' . ($t['postal_code'] ?: $t['address'])) : null]);
            if (! $keys) $keys = [mb_strtolower(($t['organization'] ?? '') . '|' . ($t['name'] ?? ''))];
            $dup = false; foreach ($keys as $k) { if (isset($seen[$k])) { $dup = true; break; } }
            if ($dup) continue;
            foreach ($keys as $k) $seen[$k] = 1;
            $out[] = $t;
        }
        return $out;
    }
}
