<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHO DO WE ALREADY KNOW? (2026-10-31, Anthony: "if I created a new batch can you detect emails, business names, contacts
 * that we already reached out to and flag these"). Every row of a new import is checked against:
 *   - prospects we already contacted (emailed, called, replied, interested, converted, do-not-contact ...),
 *   - prospects sitting in another batch, not contacted yet,
 *   - Sales leads,
 *   - existing customers (real agencies and their centres - demo agencies never count),
 *   - unsubscribed addresses (sales_suppressions).
 * Clues, strongest first: the email address; the phone (last 10 digits); the business name (case, punctuation and
 * "Inc/Ltd/Corp…" ignored; same city when both sides have one); the website / email domain (not gmail & co.).
 * Indexes are built once per request, so a 6,000-row import checks in well under a second.
 */
final class ProspectMatcher
{
    private const FREE = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'yahoo.ca', 'live.com', 'live.ca', 'icloud.com', 'me.com', 'aol.com', 'rogers.com', 'bell.net', 'sympatico.ca', 'shaw.ca', 'telus.net', 'cogeco.ca', 'msn.com', 'protonmail.com'];
    private const LEVEL = ['contacted' => 3, 'customer' => 3, 'lead' => 3, 'unsubscribed' => 3, 'exists' => 2];

    private array $email = [], $phone = [], $name = [], $domain = [];
    private ?int $exceptBatch;

    public function __construct(?int $exceptBatch = null)
    {
        $this->exceptBatch = $exceptBatch;
        $batchNames = DB::table('sales_prospect_batches')->pluck('name', 'id');
        foreach (DB::table('sales_prospects')->whereNull('deleted_at')
            ->get(['id', 'batch_id', 'organization', 'name', 'email', 'phone', 'website', 'city', 'status', 'emails_sent', 'calls', 'last_contacted_at', 'replied_at']) as $p) {
            if ($exceptBatch && (int) $p->batch_id === $exceptBatch) continue;
            $contacted = $p->emails_sent > 0 || $p->calls > 0 || $p->replied_at || ! in_array($p->status, ['new'], true);
            $this->add([
                'kind' => $contacted ? 'contacted' : 'exists', 'id' => (int) $p->id,
                'label' => trim((string) ($p->organization ?: $p->name)),
                'status' => ColdReach::STATUSES[$p->status] ?? $p->status,
                'batch' => $p->batch_id ? ($batchNames[$p->batch_id] ?? null) : 'Added by hand',
                'when' => $p->last_contacted_at,
            ], $p->email, $p->phone, $p->organization, $p->city, $p->website);
        }
        foreach (DB::table('sales_leads')->whereNull('deleted_at')->get(['id', 'company', 'name', 'email', 'phone', 'website', 'city', 'owner_email', 'owner_phone', 'stage']) as $l) {
            $m = ['kind' => 'lead', 'id' => (int) $l->id, 'label' => trim((string) ($l->company ?: $l->name)), 'status' => 'Lead · ' . str_replace('_', ' ', (string) $l->stage)];
            $this->add($m, $l->email, $l->phone, $l->company, $l->city, $l->website);
            if ($l->owner_email || $l->owner_phone) $this->add($m, $l->owner_email, $l->owner_phone, null, null, null);
        }
        $real = TestAgencies::realIds() ?: [0];
        foreach (DB::table('agencies')->whereIn('id', $real)->whereNull('deleted_at')->get(['id', 'name', 'legal_name', 'contact_email', 'contact_phone', 'website', 'city']) as $a) {
            $m = ['kind' => 'customer', 'id' => (int) $a->id, 'label' => $a->name, 'status' => 'Customer (agency)'];
            $this->add($m, $a->contact_email, $a->contact_phone, $a->name, $a->city, $a->website);
            if ($a->legal_name) $this->add($m, null, null, $a->legal_name, $a->city, null);
        }
        foreach (DB::table('centres')->whereIn('agency_id', $real)->whereNull('deleted_at')->get(['id', 'agency_id', 'name', 'email', 'phone', 'city']) as $c) {
            $this->add(['kind' => 'customer', 'id' => (int) $c->agency_id, 'label' => $c->name, 'status' => 'Customer (centre)'], $c->email, $c->phone, $c->name, $c->city, null);
        }
        foreach (DB::table('sales_suppressions')->pluck('email') as $e) {
            $this->add(['kind' => 'unsubscribed', 'id' => 0, 'label' => $e, 'status' => 'Unsubscribed from sales email'], $e, null, null, null, null);
        }
    }

    public static function normEmail(?string $e): ?string { $e = mb_strtolower(trim((string) $e)); return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null; }
    public static function normPhone(?string $p): ?string { $d = preg_replace('/\D/', '', (string) $p); return strlen($d) >= 10 ? substr($d, -10) : null; }
    public static function normName(?string $n): ?string
    {
        $n = mb_strtolower(trim((string) $n));
        if ($n === '') return null;
        $n = strtr($n, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'î' => 'i', 'ô' => 'o', 'û' => 'u', '&' => ' and ', '’' => '', "'" => '']);
        $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n);
        $n = preg_replace('/\b(inc|incorporated|ltd|limited|corp|corporation|co|llc|the|ltee)\b/', ' ', $n);
        $n = trim(preg_replace('/\s+/', ' ', $n));
        return strlen($n) >= 4 ? $n : null;
    }
    public static function normDomain(?string $website, ?string $email = null): ?string
    {
        $d = '';
        if ($website) { $d = mb_strtolower(preg_replace('#^(https?://)?(www\.)?#i', '', trim($website))); $d = explode('/', $d)[0]; }
        elseif ($email && str_contains($email, '@')) $d = mb_strtolower(substr(strrchr($email, '@'), 1));
        $d = preg_replace('/^www\./', '', $d);
        return ($d !== '' && str_contains($d, '.') && ! in_array($d, self::FREE, true)) ? $d : null;
    }

    private function add(array $m, $email, $phone, $org, $city, $website): void
    {
        if ($e = self::normEmail($email)) { $this->email[$e][] = $m; }
        if ($p = self::normPhone($phone)) { $this->phone[$p][] = $m; }
        if ($n = self::normName($org)) { $this->name[$n][] = $m + ['city' => mb_strtolower(trim((string) $city))]; }
        if ($d = self::normDomain($website, $email)) { $this->domain[$d][] = $m; }
    }

    /**
     * Matches for one row: [{kind, how, id, label, status, batch?, when?}], strongest first, one per known record.
     */
    public function match(array $row): array
    {
        $hits = [];
        $put = function (array $list, string $how) use (&$hits) {
            foreach ($list as $m) {
                $k = $m['kind'] . ':' . $m['id'] . ':' . ($m['label'] ?? '');
                if (! isset($hits[$k])) { unset($m['city']); $hits[$k] = $m + ['how' => $how]; }
            }
        };
        if ($e = self::normEmail($row['email'] ?? null)) $put($this->email[$e] ?? [], 'email');
        if ($p = self::normPhone($row['phone'] ?? null)) $put($this->phone[$p] ?? [], 'phone');
        if ($n = self::normName($row['organization'] ?? null)) {
            $city = mb_strtolower(trim((string) ($row['city'] ?? '')));
            $put(array_filter($this->name[$n] ?? [], fn ($m) => $city === '' || $m['city'] === '' || $m['city'] === $city), 'business name');
        }
        // A shared website is one organisation (a franchise, a YMCA): worth flagging when we contacted, signed or lost it,
        // not when a sister location merely sits uncontacted in another batch.
        if ($d = self::normDomain($row['website'] ?? null, $row['email'] ?? null)) $put(array_filter($this->domain[$d] ?? [], fn ($m) => $m['kind'] !== 'exists'), 'website');
        $hits = array_values($hits);
        usort($hits, fn ($a, $b) => (self::LEVEL[$b['kind']] ?? 0) <=> (self::LEVEL[$a['kind']] ?? 0));
        return array_slice($hits, 0, 5);
    }

    /** "Contacted before · Ontario batch · emailed Oct 30 (email)" - one line for a badge. */
    public static function summary(array $hits): ?string
    {
        if (! $hits) return null;
        $h = $hits[0];
        $what = ['contacted' => 'Already contacted', 'exists' => 'Already in another batch', 'lead' => 'Existing lead', 'customer' => 'Existing customer', 'unsubscribed' => 'Unsubscribed'][$h['kind']] ?? 'Known';
        $bits = array_filter([$h['label'] ?? null, $h['status'] ?? null, $h['batch'] ?? null]);
        return $what . ' · ' . implode(' · ', $bits) . ' (same ' . $h['how'] . ')' . (count($hits) > 1 ? ' +' . (count($hits) - 1) . ' more' : '');
    }
}
