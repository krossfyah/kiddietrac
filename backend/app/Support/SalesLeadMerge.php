<?php

namespace App\Support;

use App\Http\Controllers\Api\SalesController;
use App\Models\SalesLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DUPLICATE LEADS: FIND, ADVISE, MERGE (2026-10-23, Anthony: "under leads there may be
 * duplicates can you add a merge button and detect duplicates and advise").
 *
 * groups() compares every live lead with every other on email (gmail dots and +tags
 * ignored), phone (last 10 digits), website domain, company name (punctuation and
 * Inc/Ltd/LLC dropped), a near-identical company name, a shared business email domain and
 * the same full contact name. Pairs a person marked "not the same" are skipped. Each group
 * comes with the reasons and a recommendation of which lead to keep: the one with the most
 * history (bookings, proposals, activity), then the furthest stage, then the oldest.
 *
 * merge() moves everything that points at the duplicate onto the kept lead - activity,
 * files, proposals, bookings, intake forms, chats, NDAs, calls, calendar entries - fills
 * the kept lead's EMPTY fields from the duplicate (never overwrites), keeps the further
 * stage, joins the notes, writes a merge entry on the kept lead's timeline, audits it with
 * a snapshot of the duplicate, and soft-deletes the duplicate (recoverable).
 */
class SalesLeadMerge
{
    /** Every column that holds a sales lead id. */
    public const REFS = [
        ['sales_activities', 'lead_id'], ['sales_activity_files', 'lead_id'], ['sales_quotes', 'lead_id'],
        ['marketing_bookings', 'sales_lead_id'], ['prospect_intakes', 'sales_lead_id'], ['web_chats', 'lead_id'],
        ['prospect_ndas', 'lead_id'], ['outbound_calls', 'lead_id'], ['sales_calendar_events', 'lead_id'],
    ];

    private const FILL = ['name', 'company', 'email', 'phone', 'website', 'address', 'city', 'province', 'postal_code', 'country',
        'current_solution', 'owner_name', 'owner_title', 'owner_email', 'owner_phone', 'num_children', 'num_locations', 'title',
        'owner_id', 'value', 'value_quote_id', 'value_currency', 'expected_close', 'follow_up_date', 'lost_reason'];

    private const FREE_MAIL = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.ca', 'hotmail.com', 'hotmail.ca', 'outlook.com', 'live.com', 'live.ca',
        'msn.com', 'icloud.com', 'me.com', 'aol.com', 'protonmail.com', 'proton.me', 'rogers.com', 'bell.net', 'sympatico.ca', 'shaw.ca', 'telus.net', 'cogeco.ca', 'videotron.ca'];

    public static function email(?string $e): ?string
    {
        $e = strtolower(trim((string) $e));
        if (! str_contains($e, '@')) return null;
        [$u, $d] = explode('@', $e, 2);
        $u = explode('+', $u)[0];
        if (in_array($d, ['gmail.com', 'googlemail.com'], true)) { $u = str_replace('.', '', $u); $d = 'gmail.com'; }
        return $u . '@' . $d;
    }

    public static function company(?string $c): string
    {
        $c = strtolower((string) $c);
        $c = preg_replace('/[^a-z0-9]+/', ' ', $c);
        $c = preg_replace('/\b(inc|incorporated|ltd|limited|llc|corp|corporation|co|the)\b/', ' ', $c);
        return trim(preg_replace('/\s+/', ' ', $c));
    }

    private static function site(?string $w): ?string
    {
        $w = strtolower(trim((string) $w));
        if ($w === '') return null;
        $w = preg_replace('#^[a-z]+://#', '', $w);
        $w = preg_replace('#^www\.#', '', $w);
        $w = explode('/', $w)[0];
        return $w !== '' ? $w : null;
    }

    /** Why two leads look alike, strongest first; empty = not alike. */
    public static function reasons(object $a, object $b): array
    {
        $r = [];
        $ea = self::email($a->email); $eb = self::email($b->email);
        if ($ea && $ea === $eb) $r[] = 'Same email';
        $pa = substr(preg_replace('/\D/', '', (string) $a->phone), -10); $pb = substr(preg_replace('/\D/', '', (string) $b->phone), -10);
        if (strlen($pa) === 10 && $pa === $pb) $r[] = 'Same phone';
        $wa = self::site($a->website); $wb = self::site($b->website);
        if ($wa && $wa === $wb) $r[] = 'Same website';
        $ca = self::company($a->company); $cb = self::company($b->company);
        if (strlen($ca) >= 4 && $ca === $cb) $r[] = 'Same company name';
        elseif (strlen($ca) >= 6 && strlen($cb) >= 6) {
            similar_text($ca, $cb, $pct);
            if ($pct >= 88 || ((str_contains($ca, $cb) || str_contains($cb, $ca)) && min(strlen($ca), strlen($cb)) >= 8)) $r[] = 'Very similar company name';
        }
        // The company name sitting in the other lead's email ("kidsvillagechildcarecentre@gmail.com").
        foreach ([[$ca, $eb], [$cb, $ea]] as [$c, $e]) {
            if (strlen($c) >= 8 && $e && str_contains(explode('@', $e)[0], str_replace(' ', '', $c))) { $r[] = 'Company name is in the other email'; break; }
        }
        $da = $ea ? explode('@', $ea)[1] : null; $db = $eb ? explode('@', $eb)[1] : null;
        if ($da && $da === $db && $ea !== $eb && ! in_array($da, self::FREE_MAIL, true)) $r[] = 'Same business email domain (' . $da . ')';
        $na = self::company($a->name); $nb = self::company($b->name);
        if (str_contains($na, ' ') && $na === $nb) $r[] = 'Same contact name';
        return $r;
    }

    private static function weight(object $l, array $counts): array
    {
        $order = array_keys(SalesController::STAGES);
        $stage = $l->stage === 'lost' ? -1 : (int) array_search($l->stage, $order, true);
        return [($counts['bookings'][$l->id] ?? 0) * 5 + ($counts['quotes'][$l->id] ?? 0) * 4 + ($counts['activities'][$l->id] ?? 0), $stage, -strtotime((string) $l->created_at)];
    }

    /** Groups of likely duplicates, each with reasons, a suggested lead to keep, and advice. */
    public static function groups(): array
    {
        $leads = DB::table('sales_leads')->whereNull('deleted_at')->get();
        $dismissed = [];
        if (Schema::hasTable('sales_lead_not_duplicates')) {
            foreach (DB::table('sales_lead_not_duplicates')->get(['lead_a', 'lead_b']) as $d) $dismissed[min($d->lead_a, $d->lead_b) . '-' . max($d->lead_a, $d->lead_b)] = true;
        }
        $parent = []; $pairs = [];
        $find = function ($x) use (&$parent, &$find) { return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x])); };
        foreach ($leads as $l) $parent[$l->id] = $l->id;
        $arr = $leads->values()->all(); $n = count($arr);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $arr[$i]; $b = $arr[$j];
                $key = min($a->id, $b->id) . '-' . max($a->id, $b->id);
                if (isset($dismissed[$key])) continue;
                $r = self::reasons($a, $b);
                if (! $r) continue;
                $pairs[$key] = $r;
                $parent[$find($a->id)] = $find($b->id);
            }
        }
        if (! $pairs) return [];

        $ids = $leads->pluck('id');
        $counts = [
            'activities' => DB::table('sales_activities')->whereIn('lead_id', $ids)->whereNotIn('type', ['edit', 'stage'])->groupBy('lead_id')->selectRaw('lead_id k, count(*) n')->pluck('n', 'k')->all(),
            'quotes' => DB::table('sales_quotes')->whereIn('lead_id', $ids)->groupBy('lead_id')->selectRaw('lead_id k, count(*) n')->pluck('n', 'k')->all(),
            'bookings' => DB::table('marketing_bookings')->whereIn('sales_lead_id', $ids)->groupBy('sales_lead_id')->selectRaw('sales_lead_id k, count(*) n')->pluck('n', 'k')->all(),
        ];
        $by = $leads->keyBy('id');
        $sets = [];
        foreach ($pairs as $key => $_) { [$x] = explode('-', $key); $sets[$find((int) $x)] = true; }

        $out = [];
        foreach (array_keys($sets) as $root) {
            $members = array_values(array_filter($ids->all(), fn ($id) => $find($id) === $root));
            usort($members, fn ($p, $q) => self::weight($by[$q], $counts) <=> self::weight($by[$p], $counts));
            $keep = $members[0];
            $why = [];
            foreach ($pairs as $key => $r) { [$x, $y] = array_map('intval', explode('-', $key)); if (in_array($x, $members, true) && in_array($y, $members, true)) $why = array_merge($why, $r); }
            $why = array_values(array_unique($why));
            $strong = (bool) array_intersect($why, ['Same email', 'Same phone', 'Same website', 'Same company name', 'Company name is in the other email']);
            $k = $by[$keep];
            $label = fn ($l) => ($l->company ?: $l->name ?: $l->email ?: 'Lead #' . $l->id);
            $hist = fn ($id) => trim(implode(', ', array_filter([
                ($counts['bookings'][$id] ?? 0) ? ($counts['bookings'][$id] . ' booking' . ($counts['bookings'][$id] > 1 ? 's' : '')) : null,
                ($counts['quotes'][$id] ?? 0) ? ($counts['quotes'][$id] . ' proposal' . ($counts['quotes'][$id] > 1 ? 's' : '')) : null,
                ($counts['activities'][$id] ?? 0) ? ($counts['activities'][$id] . ' activit' . ($counts['activities'][$id] > 1 ? 'ies' : 'y')) : null,
            ])));
            $advice = ($strong ? 'Very likely the same prospect' : 'Possibly the same prospect') . ' (' . strtolower(implode('; ', $why)) . '). '
                . 'Keep “' . $label($k) . '”' . ($hist($keep) ? ' — it has ' . $hist($keep) : ' — it is the oldest') . ' — and merge the other' . (count($members) > 2 ? 's' : '') . ' into it. '
                . 'Nothing is lost: activity, proposals, bookings and files move across, and empty details are filled in.';
            $out[] = [
                'confidence' => $strong ? 'high' : 'medium', 'reasons' => $why, 'keep_id' => $keep, 'advice' => $advice,
                'leads' => array_map(fn ($id) => [
                    'id' => $id, 'name' => $by[$id]->name, 'company' => $by[$id]->company, 'email' => $by[$id]->email, 'phone' => $by[$id]->phone,
                    'website' => $by[$id]->website, 'stage' => $by[$id]->stage, 'source' => $by[$id]->source, 'created_at' => (string) $by[$id]->created_at,
                    'history' => $hist($id) ?: 'no activity yet',
                ], $members),
            ];
        }
        usort($out, fn ($a, $b) => ($b['confidence'] === 'high') <=> ($a['confidence'] === 'high'));
        return $out;
    }

    /** Merge $fromId INTO $keepId. Returns a summary of what moved. */
    public static function merge(int $keepId, int $fromId, int $userId, ?string $ip = null): array
    {
        abort_if($keepId === $fromId, 422, 'Choose two different leads.');
        $keep = SalesLead::findOrFail($keepId);
        $from = SalesLead::findOrFail($fromId);
        $snapshot = $from->toArray();

        return DB::transaction(function () use ($keep, $from, $userId, $ip, $snapshot) {
            $moved = [];
            foreach (self::REFS as [$t, $c]) {
                if (! Schema::hasTable($t) || ! Schema::hasColumn($t, $c)) continue;
                $n = DB::table($t)->where($c, $from->id)->update([$c => $keep->id]);
                if ($n) $moved[$t] = $n;
            }

            $filled = [];
            foreach (self::FILL as $col) {
                $mine = $keep->getAttribute($col); $theirs = $from->getAttribute($col);
                if (($mine === null || $mine === '' ) && $theirs !== null && $theirs !== '') { $keep->setAttribute($col, $theirs); $filled[] = $col; }
            }
            // The further stage wins; an open lead beats a lost one.
            $order = array_keys(SalesController::STAGES);
            $rank = fn ($s) => $s === 'lost' ? -1 : (int) array_search($s, $order, true);
            if ($rank($from->stage) > $rank($keep->stage)) { $keep->stage = $from->stage; $keep->status = $from->status; $filled[] = 'stage'; }
            $extra = [];
            if ($from->email && self::email($from->email) !== self::email($keep->email)) $extra[] = 'Other email: ' . $from->email;
            if ($from->phone && $from->phone !== $keep->phone) $extra[] = 'Other phone: ' . $from->phone;
            if ($from->name && $from->name !== $keep->name && ! in_array($from->name, [$from->email], true)) $extra[] = 'Other contact: ' . $from->name;
            if ($from->notes || $extra) {
                $keep->notes = trim(($keep->notes ? $keep->notes . "\n\n" : '') . '— Merged from lead #' . $from->id . ' —' . ($extra ? "\n" . implode("\n", $extra) : '') . ($from->notes ? "\n" . $from->notes : ''));
            }
            if ($from->created_at && $keep->created_at && $from->created_at->lt($keep->created_at)) $keep->created_at = $from->created_at;
            $keep->last_activity_at = now();
            $keep->save();

            $label = fn ($l) => ($l->company ?: $l->name ?: $l->email ?: 'lead #' . $l->id);
            $names = ['sales_activities' => 'activities', 'sales_activity_files' => 'files', 'sales_quotes' => 'proposals', 'marketing_bookings' => 'bookings',
                'prospect_intakes' => 'intake forms', 'web_chats' => 'chats', 'prospect_ndas' => 'NDAs', 'outbound_calls' => 'calls', 'sales_calendar_events' => 'calendar entries'];
            $summary = 'Merged “' . $label($from) . '” (lead #' . $from->id . ($from->email ? ', ' . $from->email : '') . ') into this lead'
                . ($moved ? ': moved ' . implode(', ', array_map(fn ($t, $n) => $n . ' ' . $names[$t], array_keys($moved), $moved)) : '')
                . ($filled ? '; filled in ' . implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c), $filled)) : '') . '.';
            \App\Models\SalesActivity::create(['lead_id' => $keep->id, 'user_id' => $userId, 'type' => 'edit', 'body' => $summary, 'done' => true]);
            \App\Support\Audit::write(['user_id' => $userId, 'agency_id' => null, 'action' => 'sales.lead_merged', 'entity_type' => 'sales_lead', 'entity_id' => $keep->id,
                'payload' => json_encode(['summary' => 'Merged sales lead “' . $label($from) . '” into “' . $label($keep) . '”', 'moved' => $moved, 'filled' => $filled, 'merged_lead' => $snapshot]),
                'ip_address' => $ip, 'created_at' => now()]);

            $from->delete();   // soft delete: recoverable from the audit snapshot / deleted_at
            DB::table('sales_lead_not_duplicates')->where('lead_a', $from->id)->orWhere('lead_b', $from->id)->delete();
            try { app(SalesController::class)->syncLeadValue($keep->fresh()); } catch (\Throwable $e) {}
            return ['ok' => true, 'keep_id' => $keep->id, 'moved' => $moved, 'filled' => $filled, 'summary' => $summary];
        });
    }

    public static function dismiss(array $ids, int $userId): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        for ($i = 0; $i < count($ids); $i++) for ($j = $i + 1; $j < count($ids); $j++) {
            DB::table('sales_lead_not_duplicates')->updateOrInsert(['lead_a' => min($ids[$i], $ids[$j]), 'lead_b' => max($ids[$i], $ids[$j])], ['user_id' => $userId, 'created_at' => now()]);
        }
    }
}
