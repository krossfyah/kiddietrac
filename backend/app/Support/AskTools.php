<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE ONLY THINGS ASK KIDDIETRAC CAN LOOK AT (2026-11-03). Read-only, typed, scoped.
 *
 * Every tool:
 *  - reads only through AskScope: the active agency's OPEN centres, narrowed to a director's own centres
 *    ($this->s->centres(), never empty in a whereIn);
 *  - leaves out closed centres, withdrawn/deleted children and families that have left (App\Support\Live) unless the
 *    tool is explicitly about history (incidents in a past range still only lists live centres);
 *  - checks the person's permission key for its data area first (Permissions::allows via AskScope::allows) and
 *    answers {"not_allowed": ...} when the role does not include it;
 *  - returns small JSON with display names and reference codes (RefCode), never database ids, notes, health
 *    details, contact details or free text written by staff;
 *  - names the screen that shows the same data (links()).
 *
 * Inputs come from the model, so every one is validated here: dates must parse and ranges are capped, a centre or
 * room is matched by name ONLY among the centres in scope, limits are clamped.
 */
final class AskTools
{
    private const ROW_CAP = 25;

    /** Plain words for the audit log ("looked at balances owing, attendance"). */
    public const LABELS = [
        'balances_owing' => 'balances owing', 'invoices_due' => 'invoices due', 'autopay_status' => 'autopay',
        'attendance_on_day' => 'attendance', 'enrolment_by_room' => 'enrolment by room', 'expiring_certifications' => 'staff certifications and checks',
        'incidents_in_range' => 'incidents', 'upcoming_closures' => 'closures', 'staff_on_shift' => 'staff on shift',
        'waitlist_summary' => 'waitlist', 'ratios_now' => 'ratios', 'centres_and_rooms' => 'centres and rooms',
    ];

    private array $links = [];

    public function __construct(private readonly AskScope $s)
    {
    }

    /** Screens to offer as "Open …" chips, in the order the tools were used. */
    public function links(): array
    {
        return array_values($this->links);
    }

    private function link(string $label, string $hash): void
    {
        $this->links[$hash] = ['label' => $label, 'hash' => $hash];
    }

    // ── definitions sent to the model ───────────────────────────────────────────────────────────

    public function definitions(): array
    {
        $centre = ['type' => 'string', 'description' => 'Optional. Only this centre (its name, or part of it). Leave out for every centre the person can see.'];
        $date = fn (string $d) => ['type' => 'string', 'description' => $d . ' Format YYYY-MM-DD.'];
        $t = fn (string $name, string $desc, array $props = [], array $req = []) => [
            'name' => $name, 'description' => $desc,
            'input_schema' => ['type' => 'object', 'properties' => (object) $props, 'required' => $req, 'additionalProperties' => false],
        ];

        return [
            /* ONE ASSISTANT (Anthony, 2026-11-03: "should be combined into one on the top bar"). The help assistant
               (🤖, POST /help/ask) and Ask KiddieTrac were two buttons; for an admin or director with the AI Bundle the
               🤖 now opens this one, so it has to answer "how do I…" too. This tool hands it the help articles. */
            $t('how_to_use_kiddietrac', 'How to do something in KiddieTrac: where a screen or setting is, how a feature works, the steps to set something up. Returns the matching help articles; answer from them. Not for questions about the agency\'s own records.',
                ['question' => ['type' => 'string', 'description' => 'The person\'s how-to question, in their words.']], ['question']),
            $t('centres_and_rooms', 'The centres (providers) this person can see and the rooms in each, with capacity. Use it to match a centre or room name the person mentions.'),
            $t('balances_owing', 'Families who owe money now: total balance per family, how many invoices are open, and aging (current, 1-30, 31-60, 61-90, 90+ days past due). Biggest balances first.',
                ['centre' => $centre, 'min_amount' => ['type' => 'number', 'description' => 'Optional. Only balances of at least this amount.']]),
            $t('invoices_due', 'Open invoices that are overdue or due within the next N days, with family, invoice number, amount owing and due date.',
                ['centre' => $centre, 'days_ahead' => ['type' => 'integer', 'description' => 'Due within this many days from today (0-60, default 7). Overdue invoices are always included.'],
                 'only_overdue' => ['type' => 'boolean', 'description' => 'Optional. Only invoices already past due.']]),
            $t('autopay_status', 'Which families have automatic payments (autopay) on or off, and the card/bank on file (brand and last 4 only).',
                ['centre' => $centre, 'filter' => ['type' => 'string', 'enum' => ['off', 'on', 'all'], 'description' => 'Default off.']]),
            $t('attendance_on_day', 'Children\'s attendance for one day (default today): checked in now, picked up (checked out), reported absent, and expected but not yet in. Includes check-in/out times in the agency time zone.',
                ['centre' => $centre, 'room' => ['type' => 'string', 'description' => 'Optional. Only this room (name or part of it).'], 'date' => $date('Optional, default today.'),
                 'status' => ['type' => 'string', 'enum' => ['all', 'in', 'not_yet_in', 'absent', 'picked_up'], 'description' => 'Optional. Only children in this state. Default all.']]),
            $t('enrolment_by_room', 'How many children are enrolled in each room (and waitlisted for the centre), against room capacity.',
                ['centre' => $centre, 'room' => ['type' => 'string', 'description' => 'Optional. Only rooms whose name contains this.'], 'list_children' => ['type' => 'boolean', 'description' => 'Optional. Also list the children (first name + last initial) when one room is asked about.']]),
            $t('expiring_certifications', 'Staff certifications (first aid, CPR, ECE, etc.) and background / police checks that expire within a period or have already expired.',
                ['centre' => $centre, 'from' => $date('Optional, default today.'), 'to' => $date('Optional, default the end of this month.'),
                 'include_expired' => ['type' => 'boolean', 'description' => 'Optional. Also list ones already expired before the start date. Default true.']]),
            $t('incidents_in_range', 'Incident reports in a date range: date, child (first name + last initial), type, severity, room and where it is in review. No descriptions.',
                ['centre' => $centre, 'from' => $date('Optional, default 7 days ago.'), 'to' => $date('Optional, default today.'),
                 'type' => ['type' => 'string', 'enum' => ['general', 'injury', 'illness', 'serious_occurrence', 'behavioural', 'medication_error', 'other'], 'description' => 'Optional.']]),
            $t('upcoming_closures', 'Upcoming centre closures and holidays (dates, reason, whether billing is affected).',
                ['centre' => $centre, 'days_ahead' => ['type' => 'integer', 'description' => '1-366, default 60.']]),
            $t('staff_on_shift', 'Staff scheduled on a day (default today) with shift times and room, and who is clocked in right now.',
                ['centre' => $centre, 'date' => $date('Optional, default today.')]),
            $t('waitlist_summary', 'Children on the waitlist: how many, by centre and age group, with requested start dates (oldest application first).',
                ['centre' => $centre]),
            $t('ratios_now', 'Right now, per room: children checked in, educators clocked in or on shift, the required ratio and whether the room is in ratio.',
                ['centre' => $centre]),
        ];
    }

    // ── dispatch ────────────────────────────────────────────────────────────────────────────────

    public function call(string $name, array $in): array
    {
        $map = [
            'centres_and_rooms' => ['centres.view', 'centresAndRooms'],
            'balances_owing' => ['invoices.view', 'balancesOwing'],
            'invoices_due' => ['invoices.view', 'invoicesDue'],
            'autopay_status' => ['invoices.view', 'autopayStatus'],
            'attendance_on_day' => ['children.view', 'attendanceOnDay'],
            'enrolment_by_room' => ['rooms.view', 'enrolmentByRoom'],
            'expiring_certifications' => ['staff.certifications', 'expiringCertifications'],
            'incidents_in_range' => ['incidents.view', 'incidentsInRange'],
            'upcoming_closures' => ['centres.view', 'upcomingClosures'],
            'staff_on_shift' => ['staff.view', 'staffOnShift'],
            'waitlist_summary' => ['waitlist.manage', 'waitlistSummary'],
            'ratios_now' => ['rooms.view', 'ratiosNow'],
        ];
        // Help articles are the same for every centre and need no permission beyond being here.
        if ($name === 'how_to_use_kiddietrac') {
            return $this->howTo($in);
        }
        if (! isset($map[$name])) {
            return ['error' => 'There is no tool called ' . mb_substr($name, 0, 40) . '.'];
        }
        [$perm, $fn] = $map[$name];
        if (! $this->s->allows($perm)) {
            return ['not_allowed' => 'This person\'s role does not include ' . (self::LABELS[$name] ?? 'this information') . '.'];
        }
        if (! $this->s->centreIds) {
            return ['error' => 'This person has no centres to look at in this agency.'];
        }
        try {
            return $this->$fn($in);
        } catch (AskInputError $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Ask KiddieTrac tool failed', ['tool' => $name, 'agency' => $this->s->agencyId, 'error' => $e->getMessage()]);

            return ['error' => 'That information could not be read just now.'];
        }
    }

    // ── shared input helpers ────────────────────────────────────────────────────────────────────

    /** The centres to read: all in scope, or the ones whose name matches - matched ONLY inside the scope. */
    private function howTo(array $in): array
    {
        $q = mb_substr(trim((string) ($in['question'] ?? '')), 0, 300);
        if ($q === '') {
            return ['error' => 'Say what the person wants to do.'];
        }
        // Three articles, trimmed: enough to answer from, without paying for the whole help centre on every question.
        $role = $this->s->isWide() ? 'agency_admin' : 'centre_director';
        $rel = app(\App\Services\HelpService::class)->relevantForRole($role, $q, 3, 2500);
        $out = [];
        foreach ($rel['articles'] as $a) {
            if (preg_match('/^## ARTICLE: (.+)$/m', $a, $t) && preg_match('/^SLUG: (.+)$/m', $a, $sl)) {
                $this->link('Help: ' . trim($t[1]), '#help/' . trim($sl[1]));
            }
            $out[] = $a;
        }
        return $out ? ['articles' => $out] : ['no_match' => 'No help article covers this. Suggest the Help centre (#help) or support@kiddietrac.com.'];
    }

    private function centres(array $in): array
    {
        $names = $this->s->centreNames();
        $want = trim((string) ($in['centre'] ?? ''));
        if ($want === '') {
            return array_keys($names);
        }
        $hit = array_keys(array_filter($names, fn ($n) => mb_stripos($n, $want) !== false));
        if (! $hit) {
            throw new AskInputError('No centre called "' . mb_substr($want, 0, 60) . '" among the centres this person can see: ' . implode('; ', array_values($names)) . '.');
        }

        return $hit;
    }

    private function today(): string
    {
        return Carbon::now($this->s->tz)->toDateString();
    }

    private function date($v, string $default): string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') return $default;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new AskInputError('Dates must be written YYYY-MM-DD.');
        try {
            return Carbon::createFromFormat('Y-m-d', $v, $this->s->tz)->toDateString();
        } catch (\Throwable $e) {
            throw new AskInputError('That is not a real date: ' . $v . '.');
        }
    }

    private function fmtDate(?string $d): ?string
    {
        if (! $d) return null;
        try { return Carbon::parse(substr($d, 0, 10))->format('M j, Y'); } catch (\Throwable $e) { return null; }
    }

    /** A UTC datetime from the database shown as an agency-zone time. */
    private function fmtTime(?string $utc): ?string
    {
        if (! $utc) return null;
        try { return Carbon::parse($utc, 'UTC')->setTimezone($this->s->tz)->format('g:i A'); } catch (\Throwable $e) { return null; }
    }

    private function money($v): string
    {
        return '$' . number_format((float) $v, 2);
    }

    /** "Ava M." - enough to recognise, no full surname in an AI prompt. */
    private function shortName(?string $first, ?string $last, ?string $preferred = null): string
    {
        $f = trim((string) ($preferred ?: $first));
        $l = trim((string) $last);

        return self::clean(trim($f . ($l !== '' ? ' ' . mb_substr($l, 0, 1) . '.' : ''))) ?: 'Unnamed';
    }

    /** Values from the records are data: strip control characters and cap the length. */
    private static function clean($v, int $max = 80): string
    {
        $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v);

        return mb_substr(trim((string) $v), 0, $max);
    }

    private function cap(array $rows, ?int $cap = null): array
    {
        $cap = $cap ?? self::ROW_CAP;

        return ['rows' => array_slice($rows, 0, $cap), 'more' => max(0, count($rows) - $cap)];
    }

    // ── the tools ───────────────────────────────────────────────────────────────────────────────

    private function centresAndRooms(array $in): array
    {
        $names = $this->s->centreNames();
        $rooms = DB::table('rooms')->whereIn('centre_id', array_keys($names) ?: [0])->where('active', 1)
            ->orderBy('name')->get(['centre_id', 'name', 'age_group', 'capacity']);
        $out = [];
        foreach ($names as $id => $n) {
            $out[] = ['centre' => self::clean($n), 'rooms' => $rooms->where('centre_id', $id)->map(fn ($r) => array_filter([
                'room' => self::clean($r->name), 'age_group' => $r->age_group, 'capacity' => $r->capacity ? (int) $r->capacity : null,
            ], fn ($v) => $v !== null))->values()->all()];
        }

        return ['centres' => $out];
    }

    /* BILLING. KiddieTrac invoices (invoices) plus invoices synced from an outside billing system
       (external_invoices); an outside invoice already mirrored onto a KiddieTrac invoice (applied_to_invoice_id)
       is counted once, through the KiddieTrac one. Open = not paid / void / draft, balance above zero. */
    private function openInvoices(array $centreIds): array
    {
        $fam = Live::families(DB::table('families'))->whereIn('families.centre_id', $centreIds ?: [0]);
        $famIds = $fam->pluck('families.id')->map(fn ($v) => (int) $v)->all();
        if (! $famIds) return [];
        $rows = [];
        foreach (DB::table('invoices')->whereIn('family_id', $famIds)->whereIn('centre_id', $centreIds)
            ->whereNotIn('status', ['paid', 'void', 'draft', 'refunded', 'cancelled'])->where('balance_due', '>', 0.004)
            ->get(['family_id', 'invoice_number', 'balance_due', 'due_at', 'issued_at']) as $r) {
            $rows[] = ['family_id' => (int) $r->family_id, 'number' => $r->invoice_number, 'owing' => (float) $r->balance_due, 'due' => $r->due_at ?: $r->issued_at];
        }
        foreach (DB::table('external_invoices')->where('agency_id', $this->s->agencyId)->whereIn('family_id', $famIds)
            ->whereNull('applied_to_invoice_id')->whereNotIn(DB::raw('LOWER(status)'), ['paid', 'void', 'draft', 'cancelled', 'refunded'])->where('balance_due', '>', 0.004)
            ->get(['family_id', 'number', 'balance_due', 'due_at', 'issued_at']) as $r) {
            $rows[] = ['family_id' => (int) $r->family_id, 'number' => $r->number, 'owing' => (float) $r->balance_due, 'due' => $r->due_at ?: $r->issued_at];
        }

        return $rows;
    }

    private function familyInfo(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (! $ids) return [];
        $codes = RefCode::families($ids);
        $names = $this->s->centreNames();
        $out = [];
        foreach (DB::table('families')->whereIn('id', $ids)->get(['id', 'family_name', 'centre_id']) as $f) {
            $out[(int) $f->id] = ['family' => self::clean($f->family_name) ?: 'Family', 'ref' => $codes[(int) $f->id] ?? null, 'centre' => self::clean($names[(int) $f->centre_id] ?? '')];
        }

        return $out;
    }

    private function balancesOwing(array $in): array
    {
        $centres = $this->centres($in);
        $min = max(0, (float) ($in['min_amount'] ?? 0));
        $today = Carbon::parse($this->today());
        $by = [];
        foreach ($this->openInvoices($centres) as $r) {
            $f = $r['family_id'];
            $by[$f] ??= ['owing' => 0.0, 'invoices' => 0, 'current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'oldest_due' => null];
            $by[$f]['owing'] += $r['owing'];
            $by[$f]['invoices']++;
            $late = $r['due'] ? max(0, (int) Carbon::parse(substr($r['due'], 0, 10))->diffInDays($today, false)) : 0;
            $bucket = $late <= 0 ? 'current' : ($late <= 30 ? 'd1_30' : ($late <= 60 ? 'd31_60' : ($late <= 90 ? 'd61_90' : 'd90_plus')));
            $by[$f][$bucket] += $r['owing'];
            if ($r['due'] && (! $by[$f]['oldest_due'] || $r['due'] < $by[$f]['oldest_due'])) $by[$f]['oldest_due'] = substr($r['due'], 0, 10);
        }
        $by = array_filter($by, fn ($v) => $v['owing'] >= max($min, 0.005));
        uasort($by, fn ($a, $b) => $b['owing'] <=> $a['owing']);
        $info = $this->familyInfo(array_keys($by));
        $rows = []; $tot = ['owing' => 0.0, 'current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0];
        foreach ($by as $fid => $v) {
            foreach ($tot as $k => $_) $tot[$k] += $v[$k];
            $rows[] = ($info[$fid] ?? ['family' => 'Family']) + [
                'owing' => $this->money($v['owing']), 'open_invoices' => $v['invoices'],
                'aging' => array_filter(['current' => $v['current'], '1-30' => $v['d1_30'], '31-60' => $v['d31_60'], '61-90' => $v['d61_90'], '90+' => $v['d90_plus']], fn ($x) => $x > 0.004),
                'oldest_due' => $this->fmtDate($v['oldest_due']),
            ];
        }
        foreach ($rows as &$r) { $r['aging'] = array_map(fn ($x) => $this->money($x), $r['aging']); } unset($r);
        $this->link('Open Accounting', '#external-billing');
        $this->link('Open Reports (aging)', '#reports');
        $left = $this->leftOwing($centres);

        return ['as_of' => $this->fmtDate($this->today()), 'families_owing' => count($rows), 'total_owing' => $this->money($tot['owing']),
            'aging_totals' => array_map(fn ($x) => $this->money($x), ['current' => $tot['current'], '1-30 days late' => $tot['d1_30'], '31-60' => $tot['d31_60'], '61-90' => $tot['d61_90'], '90+' => $tot['d90_plus']]),
            'note' => 'Current families only. Split invoices count in full against the family.',
        ] + ($left ? ['families_who_have_left_still_owing' => $left] : []) + $this->cap($rows);
    }

    /** Families that have left (or were archived) with money still owing: a count and total only, never names. */
    private function leftOwing(array $centreIds): ?array
    {
        $gone = DB::table('families')->whereIn('centre_id', $centreIds ?: [0])
            ->where(fn ($w) => $w->whereNotNull('deleted_at')->orWhereNotNull('departure_applied_at'))->pluck('id')->all();
        if (! $gone) return null;
        $a = DB::table('invoices')->whereIn('family_id', $gone)->whereIn('centre_id', $centreIds)
            ->whereNotIn('status', ['paid', 'void', 'draft', 'refunded', 'cancelled'])->where('balance_due', '>', 0.004)
            ->select('family_id', 'balance_due')->get();
        $b = DB::table('external_invoices')->where('agency_id', $this->s->agencyId)->whereIn('family_id', $gone)->whereNull('applied_to_invoice_id')
            ->whereNotIn(DB::raw('LOWER(status)'), ['paid', 'void', 'draft', 'cancelled', 'refunded'])->where('balance_due', '>', 0.004)
            ->select('family_id', 'balance_due')->get();
        $all = $a->concat($b);
        if ($all->isEmpty()) return null;

        return ['families' => $all->pluck('family_id')->unique()->count(), 'total' => $this->money($all->sum('balance_due'))];
    }

    private function invoicesDue(array $in): array
    {
        $centres = $this->centres($in);
        $days = max(0, min(60, (int) ($in['days_ahead'] ?? 7)));
        $onlyOver = ! empty($in['only_overdue']);
        $today = $this->today();
        $until = Carbon::parse($today)->addDays($days)->toDateString();
        $rows = array_filter($this->openInvoices($centres), function ($r) use ($today, $until, $onlyOver) {
            $d = $r['due'] ? substr($r['due'], 0, 10) : null;
            if (! $d) return false;
            return $onlyOver ? $d < $today : $d <= $until;
        });
        usort($rows, fn ($a, $b) => strcmp((string) $a['due'], (string) $b['due']));
        $info = $this->familyInfo(array_column($rows, 'family_id'));
        $over = 0; $sum = 0.0;
        $out = array_map(function ($r) use ($info, $today, &$over, &$sum) {
            $d = substr($r['due'], 0, 10); $late = $d < $today; if ($late) $over++; $sum += $r['owing'];
            return ($info[$r['family_id']] ?? ['family' => 'Family']) + ['invoice' => self::clean($r['number']), 'owing' => $this->money($r['owing']), 'due' => $this->fmtDate($d), 'overdue' => $late];
        }, $rows);
        $this->link('Open Accounting', '#external-billing');

        return ['as_of' => $this->fmtDate($today), 'window' => $onlyOver ? 'overdue only' : 'overdue, or due by ' . $this->fmtDate($until),
            'count' => count($out), 'overdue_count' => $over, 'total_owing' => $this->money($sum)] + $this->cap($out);
    }

    private function autopayStatus(array $in): array
    {
        $centres = $this->centres($in);
        $filter = in_array($in['filter'] ?? 'off', ['on', 'off', 'all'], true) ? ($in['filter'] ?? 'off') : 'off';
        $fams = Live::families(DB::table('families'))->whereIn('families.centre_id', $centres)
            // only families with a child still with us
            ->whereExists(fn ($q) => Live::children($q->from('children')->whereColumn('children.family_id', 'families.id')->select(DB::raw(1))))
            ->get(['families.id', 'families.autopay_enabled', 'families.autopay_card_brand', 'families.autopay_card_last4', 'families.ach_last4', 'families.ach_bank_name']);
        // A guardian can carry their own autopay (split billing): the family counts as on when anyone has it on.
        $gOn = DB::table('guardians')->whereIn('family_id', $fams->pluck('id')->all() ?: [0])->where('autopay_enabled', 1)
            ->get(['family_id', 'autopay_card_brand', 'autopay_card_last4'])->keyBy('family_id');
        $rows = []; $on = 0; $off = 0;
        $info = $this->familyInfo($fams->pluck('id')->map(fn ($v) => (int) $v)->all());
        foreach ($fams as $f) {
            $g = $gOn->get($f->id);
            $isOn = (bool) $f->autopay_enabled || (bool) $g;
            $isOn ? $on++ : $off++;
            if ($filter === 'on' && ! $isOn) continue;
            if ($filter === 'off' && $isOn) continue;
            $method = null;
            if ($isOn) {
                if ($f->autopay_card_last4) $method = trim(($f->autopay_card_brand ?: 'Card') . ' ending ' . $f->autopay_card_last4);
                elseif ($f->ach_last4) $method = trim(($f->ach_bank_name ?: 'Bank') . ' ending ' . $f->ach_last4);
                elseif ($g && $g->autopay_card_last4) $method = trim(($g->autopay_card_brand ?: 'Card') . ' ending ' . $g->autopay_card_last4 . ' (one parent)');
            }
            $rows[] = ($info[(int) $f->id] ?? ['family' => 'Family']) + ['autopay' => $isOn ? 'on' : 'off'] + ($method ? ['method' => self::clean($method)] : []);
        }
        usort($rows, fn ($a, $b) => strcmp($a['family'], $b['family']));
        $this->link('Open Payment plans', '#payment-plans');

        return ['families' => $on + $off, 'autopay_on' => $on, 'autopay_off' => $off, 'showing' => $filter] + $this->cap($rows, 40);
    }

    /** Children still with us at these centres (enrolled), with their room: the open enrolment's room (what the room
        screens count), else the child's primary room. Rooms outside the scope are not named. */
    private function enrolledChildren(array $centres, ?string $roomLike = null, bool $skipSuspended = false)
    {
        $kids = Live::enrolledChildren(DB::table('children'))
            ->join('families', 'families.id', '=', 'children.family_id')
            ->whereIn('families.centre_id', $centres)
            ->whereNull('families.deleted_at')->whereNull('families.departure_applied_at')
            ->when($skipSuspended, fn ($q) => $q->whereNull('families.suspended_at'))
            ->get(['children.id', 'children.first_name', 'children.last_name', 'children.preferred_name', 'children.expected_dropoff_time',
                'children.primary_room_id', 'families.centre_id']);
        $today = $this->today();
        $enr = DB::table('enrollments')->whereIn('child_id', $kids->pluck('id')->all() ?: [0])
            ->where(fn ($w) => $w->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->where(fn ($w) => $w->whereNull('start_date')->orWhere('start_date', '<=', $today))
            ->orderBy('start_date')->get(['child_id', 'room_id'])->keyBy('child_id');   // latest start wins
        $rooms = DB::table('rooms')->whereIn('centre_id', $centres)->pluck('name', 'id');
        foreach ($kids as $k) {
            $rid = (int) (optional($enr->get($k->id))->room_id ?: $k->primary_room_id);
            $k->room_id = $rooms->has($rid) ? $rid : 0;
            $k->room = $rooms->get($rid);
        }
        if ($roomLike !== null && $roomLike !== '') {
            $kids = $kids->filter(fn ($k) => $k->room !== null && mb_stripos($k->room, $roomLike) !== false)->values();
        }

        return $kids;
    }

    private function attendanceOnDay(array $in): array
    {
        $centres = $this->centres($in);
        $date = $this->date($in['date'] ?? null, $this->today());
        if ($date > $this->today()) throw new AskInputError('Attendance is only known for today and earlier days.');
        $room = trim((string) ($in['room'] ?? ''));
        $want = in_array($in['status'] ?? 'all', ['all', 'in', 'not_yet_in', 'absent', 'picked_up'], true) ? ($in['status'] ?? 'all') : 'all';
        $kids = $this->enrolledChildren($centres, $room, true);
        if ($room !== '' && $kids->isEmpty()) {
            throw new AskInputError('No enrolled children in a room called "' . mb_substr($room, 0, 40) . '" at the centres this person can see.');
        }
        $ids = $kids->pluck('id')->map(fn ($v) => (int) $v)->all();
        [$from, $to] = AgencyTime::dayRange($this->s->agencyId, $date);
        // Today: nothing later than now counts (as the day brief) - a pre-entered event has not happened yet.
        if ($date === $this->today()) $to = min($to, Carbon::now('UTC')->format('Y-m-d H:i:s'));
        $ev = DB::table('check_events')->whereIn('child_id', $ids ?: [0])->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
            ->orderBy('occurred_at')->get(['child_id', 'event_type', 'occurred_at']);
        /* $first = the arrival of the visit they are in now (or were last in); $nIn counts
           visits, so "signed in 2 times" can be said (2026-11-05). */
        $first = []; $last = []; $nIn = [];
        foreach ($ev as $e) {
            $c = (int) $e->child_id;
            if ($e->event_type === 'check_in' && (! isset($last[$c]) || $last[$c]->event_type !== 'check_in')) { $first[$c] = $e->occurred_at; $nIn[$c] = ($nIn[$c] ?? 0) + 1; }
            $last[$c] = $e;
        }
        $absent = DB::table('child_absences')->whereIn('child_id', $ids ?: [0])->where('absent_on', $date)->pluck('reason', 'child_id');
        $scheduled = $this->scheduledOn($kids, $date);
        $closed = [];
        foreach ($centres as $cid) { if (Closures::isClosed($cid, $date)) $closed[] = self::clean($this->s->centreNames()[$cid] ?? ''); }

        $groups = ['in' => [], 'picked_up' => [], 'absent' => [], 'not_yet_in' => []];
        foreach ($kids as $k) {
            $c = (int) $k->id;
            $row = ['child' => $this->shortName($k->first_name, $k->last_name, $k->preferred_name), 'room' => self::clean($k->room ?? '') ?: null]
                + (($nIn[$c] ?? 0) > 1 ? ['visits_today' => $nIn[$c]] : []);
            if (isset($last[$c]) && $last[$c]->event_type === 'check_in') {
                $groups['in'][] = $row + ['in_at' => $this->fmtTime($first[$c] ?? $last[$c]->occurred_at)];
            } elseif (isset($last[$c]) && $last[$c]->event_type === 'check_out') {
                $groups['picked_up'][] = $row + ['in_at' => $this->fmtTime($first[$c] ?? null), 'out_at' => $this->fmtTime($last[$c]->occurred_at)];
            } elseif ($absent->has($c)) {
                $groups['absent'][] = $row + ['reason' => self::clean($absent[$c] ?? '', 40) ?: null];
            } elseif ($scheduled === null || isset($scheduled[$c])) {
                $groups['not_yet_in'][] = $row + ($k->expected_dropoff_time ? ['usually_arrives' => Carbon::parse($k->expected_dropoff_time)->format('g:i A')] : []);
            }
        }
        $counts = array_map('count', $groups);
        $out = ['date' => $this->fmtDate($date) . ($date === $this->today() ? ' (today)' : ''), 'enrolled' => count($ids), 'counts' => $counts];
        if ($scheduled !== null) $out['note'] = '"not_yet_in" counts only children scheduled to attend that day.';
        if ($closed) $out['closed_centres'] = $closed;
        foreach ($groups as $g => $rows) {
            if ($want !== 'all' && $want !== $g) continue;
            $out[$g] = array_map(fn ($r) => array_filter($r, fn ($v) => $v !== null), $this->cap($rows, 30)['rows']);
            if (count($rows) > 30) $out[$g . '_more'] = count($rows) - 30;
        }
        $this->link('Open Today', '#provider-day');

        return $out;
    }

    /** child id => true for children scheduled that day (ScheduledToday, the Daily Overview's rule: an open enrolment
        whose care schedule includes the weekday, at a centre open that day; a child with no enrolment counts). */
    private function scheduledOn($kids, string $date): ?array
    {
        try {
            $byCentre = [];
            foreach ($kids as $k) $byCentre[(int) $k->centre_id][] = (int) $k->id;
            $open = ScheduledToday::centresOpen(array_keys($byCentre), $date);
            $out = [];
            foreach (ScheduledToday::children($byCentre, $date, $open) as $ids) {
                foreach ($ids as $id => $yes) { if ($yes) $out[(int) $id] = true; }
            }

            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function enrolmentByRoom(array $in): array
    {
        $centres = $this->centres($in);
        $roomLike = trim((string) ($in['room'] ?? ''));
        $names = $this->s->centreNames();
        $rooms = DB::table('rooms')->whereIn('centre_id', $centres)->where('active', 1)
            ->when($roomLike !== '', fn ($q) => $q->where('name', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $roomLike) . '%'))
            ->orderBy('centre_id')->orderBy('name')->get(['id', 'centre_id', 'name', 'age_group', 'capacity']);
        if ($roomLike !== '' && $rooms->isEmpty()) {
            throw new AskInputError('No room called "' . mb_substr($roomLike, 0, 40) . '" at the centres this person can see.');
        }
        $kids = $this->enrolledChildren($centres);
        $byRoom = $kids->groupBy(fn ($k) => (int) $k->room_id);
        $rows = [];
        foreach ($rooms as $r) {
            $list = $byRoom->get((int) $r->id, collect());
            $row = ['centre' => self::clean($names[(int) $r->centre_id] ?? ''), 'room' => self::clean($r->name), 'enrolled' => $list->count(),
                'capacity' => $r->capacity ? (int) $r->capacity : null];
            if (! empty($in['list_children']) && $rooms->count() === 1) {
                $row['children'] = $list->map(fn ($k) => $this->shortName($k->first_name, $k->last_name, $k->preferred_name))->sort()->values()->all();
            }
            $rows[] = array_filter($row, fn ($v) => $v !== null);
        }
        $noRoom = $byRoom->get(0, collect())->count();
        $wait = Live::children(DB::table('children'))->where('children.enrollment_status', 'waitlist')
            ->join('families', 'families.id', '=', 'children.family_id')->whereIn('families.centre_id', $centres)
            ->whereNull('families.deleted_at')->count();
        $this->link('Open Children', $this->s->isWide() ? '#admin-children' : '#children');

        // With a room filter the total is those rooms' children only; the whole-scope total would read as a contradiction.
        $total = $roomLike === '' ? ['enrolled_total' => $kids->count()] : ['enrolled_in_matching_rooms' => array_sum(array_column($rows, 'enrolled'))];

        return $total + ['rooms' => $rows]
            + ($noRoom && $roomLike === '' ? ['enrolled_without_a_room' => $noRoom] : [])
            + ($roomLike === '' ? ['waitlisted' => $wait] : []);
    }

    /** Staff at these centres: user id => [name, centre], from active assignments. */
    private function staffAt(array $centres): array
    {
        $wide = $this->s->isWide() && count($centres) === count($this->s->centreIds);
        $q = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->whereIn('ra.role', ['educator', 'centre_director', 'home_visitor', 'agency_admin'])
            ->where(function ($w) use ($centres, $wide) {
                $w->whereIn('ra.centre_id', $centres);
                // Office staff held at agency level (no centre) belong to an agency-wide view of every centre.
                if ($wide) $w->orWhere(fn ($x) => $x->whereNull('ra.centre_id')->where('ra.agency_id', $this->s->agencyId));
            });
        $rows = Audience::excludeOff($q, 'u')
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.preferred_name', 'ra.centre_id']);
        $out = [];
        $names = $this->s->centreNames();
        foreach ($rows as $r) {
            $out[(int) $r->id] ??= ['name' => self::clean(trim(($r->preferred_name ?: $r->first_name) . ' ' . $r->last_name)), 'centres' => []];
            if ($r->centre_id) $out[(int) $r->id]['centres'][] = self::clean($names[(int) $r->centre_id] ?? '');
        }

        return $out;
    }

    private function expiringCertifications(array $in): array
    {
        $centres = $this->centres($in);
        $from = $this->date($in['from'] ?? null, $this->today());
        $to = $this->date($in['to'] ?? null, Carbon::parse($this->today())->endOfMonth()->toDateString());
        if ($to < $from) [$from, $to] = [$to, $from];
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 400) throw new AskInputError('Ask about at most about a year at a time.');
        $withExpired = ! array_key_exists('include_expired', $in) || ! empty($in['include_expired']);
        $staff = $this->staffAt($centres);
        // An agency admin also sees agency-level staff (no centre), e.g. office staff with certificates on file.
        $uids = array_keys($staff);
        $codes = RefCode::users($uids, $this->s->agencyId, 'S');
        $rows = [];
        $add = function ($uid, $what, $exp) use (&$rows, $staff, $codes, $from) {
            $rows[] = ['staff' => $staff[$uid]['name'] ?? 'Staff member', 'ref' => $codes[$uid] ?? null, 'centre' => implode(', ', array_unique($staff[$uid]['centres'] ?? [])),
                'item' => self::clean($what, 60), 'expires' => $this->fmtDate($exp), 'status' => $exp < $from ? 'expired' : 'expiring', '_d' => $exp];
        };
        $q = DB::table('staff_certifications')->where('agency_id', $this->s->agencyId)->whereIn('user_id', $uids ?: [0])
            ->where('active', 1)->whereNotNull('expires_at')->where('expires_at', '<=', $to)
            ->when(! $withExpired, fn ($w) => $w->where('expires_at', '>=', $from))->get(['user_id', 'cert_type', 'title', 'expires_at']);
        // Only the newest certificate of each kind per person counts: a renewed one replaces the expired one.
        $newest = DB::table('staff_certifications')->where('agency_id', $this->s->agencyId)->whereIn('user_id', $uids ?: [0])->where('active', 1)
            ->groupBy('user_id', 'cert_type')->select('user_id', 'cert_type', DB::raw('MAX(expires_at) as mx'))->get()
            ->keyBy(fn ($r) => $r->user_id . '|' . $r->cert_type);
        foreach ($q as $c) {
            $mx = optional($newest->get($c->user_id . '|' . $c->cert_type))->mx;
            if ($mx && $mx > $c->expires_at) continue;   // renewed
            $add((int) $c->user_id, $c->title ?: str_replace('_', ' ', (string) $c->cert_type), substr($c->expires_at, 0, 10));
        }
        $bq = DB::table('background_checks')->where('agency_id', $this->s->agencyId)->whereIn('user_id', $uids ?: [0])
            ->whereNotNull('expires_at')->where('expires_at', '<=', $to)
            ->when(! $withExpired, fn ($w) => $w->where('expires_at', '>=', $from))->get(['user_id', 'check_type', 'expires_at']);
        $bNewest = DB::table('background_checks')->where('agency_id', $this->s->agencyId)->whereIn('user_id', $uids ?: [0])
            ->groupBy('user_id', 'check_type')->select('user_id', 'check_type', DB::raw('MAX(expires_at) as mx'))->get()
            ->keyBy(fn ($r) => $r->user_id . '|' . $r->check_type);
        foreach ($bq as $b) {
            $mx = optional($bNewest->get($b->user_id . '|' . $b->check_type))->mx;
            if ($mx && $mx > $b->expires_at) continue;
            $add((int) $b->user_id, ($b->check_type ? str_replace('_', ' ', (string) $b->check_type) : 'Background') . ' check', substr($b->expires_at, 0, 10));
        }
        usort($rows, fn ($a, $b) => strcmp($a['_d'], $b['_d']));
        $rows = array_map(function ($r) { unset($r['_d']); return array_filter($r, fn ($v) => $v !== null && $v !== ''); }, $rows);
        $this->link('Open Certifications', '#certifications');
        $this->link('Open Background checks', '#background-checks');

        return ['period' => $this->fmtDate($from) . ' to ' . $this->fmtDate($to), 'includes_already_expired' => $withExpired,
            'count' => count($rows), 'expired' => count(array_filter($rows, fn ($r) => $r['status'] === 'expired'))] + $this->cap($rows, 40);
    }

    private function incidentsInRange(array $in): array
    {
        $centres = $this->centres($in);
        $today = $this->today();
        $from = $this->date($in['from'] ?? null, Carbon::parse($today)->subDays(6)->toDateString());
        $to = $this->date($in['to'] ?? null, $today);
        if ($to < $from) [$from, $to] = [$to, $from];
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) throw new AskInputError('Ask about at most a year at a time.');
        [$a] = AgencyTime::dayRange($this->s->agencyId, $from);
        [, $b] = AgencyTime::dayRange($this->s->agencyId, $to);
        $type = (string) ($in['type'] ?? '');
        // The child's centre decides (incidents carry a room, not always one); live centres only.
        $q = DB::table('incidents as i')->join('children as c', 'c.id', '=', 'i.child_id')->join('families as f', 'f.id', '=', 'c.family_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'i.room_id')
            ->whereIn('f.centre_id', $centres)->whereNull('c.deleted_at')
            ->where('i.occurred_at', '>=', $a)->where('i.occurred_at', '<', $b)
            ->when(in_array($type, ['general', 'injury', 'illness', 'serious_occurrence', 'behavioural', 'medication_error', 'other'], true), fn ($w) => $w->where('i.incident_type', $type))
            ->orderBy('i.occurred_at', 'desc')
            ->get(['i.occurred_at', 'i.incident_type', 'i.severity', 'i.status', 'i.is_serious_occurrence', 'i.serious_occurrence', 'i.first_aid_administered', 'i.parent_notified_at',
                'c.first_name', 'c.last_name', 'c.preferred_name', 'r.name as room', 'f.centre_id']);
        $names = $this->s->centreNames();
        $status = ['draft' => 'draft (not submitted)', 'submitted' => 'waiting for review', 'director_reviewed' => 'reviewed', 'parent_notified' => 'family told', 'acknowledged' => 'family acknowledged', 'closed' => 'closed'];
        $rows = []; $byType = [];
        foreach ($q as $i) {
            $byType[$i->incident_type] = ($byType[$i->incident_type] ?? 0) + 1;
            $rows[] = array_filter([
                'when' => Carbon::parse($i->occurred_at, 'UTC')->setTimezone($this->s->tz)->format('M j, g:i A'),
                'child' => $this->shortName($i->first_name, $i->last_name, $i->preferred_name),
                'type' => str_replace('_', ' ', (string) $i->incident_type), 'severity' => $i->severity,
                'serious_occurrence' => ($i->is_serious_occurrence || $i->serious_occurrence) ? true : null,
                'room' => self::clean($i->room ?? '') ?: null, 'centre' => count($names) > 1 ? self::clean($names[(int) $i->centre_id] ?? '') : null,
                'status' => $status[$i->status] ?? $i->status, 'family_told' => $i->parent_notified_at ? true : null,
            ], fn ($v) => $v !== null && $v !== '');
        }
        $this->link('Open Incidents', '#incidents');

        return ['period' => $this->fmtDate($from) . ' to ' . $this->fmtDate($to), 'count' => count($rows), 'by_type' => $byType] + $this->cap($rows, 30);
    }

    private function upcomingClosures(array $in): array
    {
        $centres = $this->centres($in);
        $days = max(1, min(366, (int) ($in['days_ahead'] ?? 60)));
        $today = $this->today();
        $until = Carbon::parse($today)->addDays($days)->toDateString();
        $names = $this->s->centreNames();
        $q = DB::table('centre_closures')->whereIn('centre_id', $centres)
            ->where(fn ($w) => $w->where('closure_date', '>=', $today)->orWhere('end_date', '>=', $today))
            ->where('closure_date', '<=', $until)->orderBy('closure_date')
            ->get(['centre_id', 'closure_date', 'end_date', 'closure_type', 'reason', 'affects_billing']);
        // One closure for several centres reads as one row naming them.
        $grp = [];
        foreach ($q as $c) {
            $k = $c->closure_date . '|' . $c->end_date . '|' . $c->reason;
            $grp[$k] ??= ['from' => $this->fmtDate($c->closure_date), 'to' => $c->end_date && $c->end_date !== $c->closure_date ? $this->fmtDate($c->end_date) : null,
                'reason' => self::clean($c->reason ?: str_replace('_', ' ', (string) $c->closure_type), 60), 'billing_affected' => (bool) $c->affects_billing, 'centres' => []];
            $grp[$k]['centres'][] = self::clean($names[(int) $c->centre_id] ?? '');
        }
        $rows = array_map(function ($g) use ($centres) {
            $g['centres'] = count($g['centres']) === count($centres) && count($centres) > 1 ? 'all centres' : implode(', ', $g['centres']);
            return array_filter($g, fn ($v) => $v !== null);
        }, array_values($grp));
        $this->link('Open Closures', '#closures');

        return ['window' => $this->fmtDate($today) . ' to ' . $this->fmtDate($until), 'count' => count($rows)] + $this->cap($rows, 30);
    }

    private function staffOnShift(array $in): array
    {
        $centres = $this->centres($in);
        $date = $this->date($in['date'] ?? null, $this->today());
        [$a, $b] = AgencyTime::dayRange($this->s->agencyId, $date);
        $names = $this->s->centreNames();
        $staff = $this->staffAt($centres);
        $shifts = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->whereIn('r.centre_id', $centres)
            ->where('s.status', '!=', 'cancelled')->where('s.starts_at', '<', $b)->where('s.ends_at', '>', $a)
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->orderBy('s.starts_at')->get(['s.user_id', 's.starts_at', 's.ends_at', 'r.name as room', 'r.centre_id', 'u.first_name', 'u.last_name', 'u.preferred_name']);
        $isToday = $date === $this->today();
        $clocked = $isToday ? $this->clockedIn($centres) : collect();
        $rows = [];
        foreach ($shifts as $s) {
            $rows[] = array_filter(['staff' => self::clean(trim(($s->preferred_name ?: $s->first_name) . ' ' . $s->last_name)),
                'shift' => $this->fmtTime($s->starts_at) . ' - ' . $this->fmtTime($s->ends_at), 'room' => self::clean($s->room),
                'centre' => count($names) > 1 ? self::clean($names[(int) $s->centre_id] ?? '') : null,
                'clocked_in' => $isToday ? ($clocked->has($s->user_id) ? 'since ' . $this->fmtTime($clocked[$s->user_id]->punched_in_at) : 'no') : null], fn ($v) => $v !== null);
        }
        // Clocked in without a shift on the schedule.
        $onShift = $shifts->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $extra = [];
        foreach ($clocked as $uid => $p) {
            if (in_array((int) $uid, $onShift, true)) continue;
            $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name', 'preferred_name']);
            if (! $u) continue;
            $extra[] = array_filter(['staff' => self::clean(trim(($u->preferred_name ?: $u->first_name) . ' ' . $u->last_name)), 'clocked_in' => 'since ' . $this->fmtTime($p->punched_in_at),
                'centre' => count($names) > 1 ? self::clean($names[(int) $p->centre_id] ?? '') : null]);
        }
        $this->link('Open Staff calendar', '#staff-calendar');

        return ['date' => $this->fmtDate($date) . ($isToday ? ' (today)' : ''), 'shifts' => count($rows),
            'clocked_in_now' => $isToday ? $clocked->count() : null] + $this->cap($rows, 40)
            + ($extra ? ['clocked_in_without_a_shift' => $extra] : []);
    }

    /** Open clock-ins at these centres: user id => {punched_in_at, centre_id}. */
    private function clockedIn(array $centres)
    {
        $since = Carbon::now('UTC')->subHours(20)->format('Y-m-d H:i:s');
        $a = DB::table('time_punches')->whereIn('centre_id', $centres)->whereNull('punched_out_at')->where('punched_in_at', '>=', $since)
            ->get(['user_id', 'punched_in_at', 'centre_id']);
        $b = DB::table('time_entries')->whereIn('centre_id', $centres)->whereNull('clocked_out_at')->where('clocked_in_at', '>=', $since)
            ->get(['user_id', 'clocked_in_at as punched_in_at', 'centre_id']);

        return $a->concat($b)->sortBy('punched_in_at')->keyBy('user_id');
    }

    private function waitlistSummary(array $in): array
    {
        $centres = $this->centres($in);
        $names = $this->s->centreNames();
        $kids = Live::children(DB::table('children'))->where('children.enrollment_status', 'waitlist')
            ->join('families', 'families.id', '=', 'children.family_id')->whereIn('families.centre_id', $centres)
            ->whereNull('families.deleted_at')->whereNull('families.departure_applied_at')
            ->orderByRaw('children.applied_at IS NULL, children.applied_at')
            ->get(['children.first_name', 'children.last_name', 'children.preferred_name', 'children.date_of_birth', 'children.applied_at', 'children.expected_start_date',
                'children.preferred_room_age_group', 'families.centre_id']);
        $rows = []; $byCentre = []; $byAge = [];
        foreach ($kids as $k) {
            $cn = self::clean($names[(int) $k->centre_id] ?? '');
            $age = mb_strtolower(self::clean($k->preferred_room_age_group ?: ($k->date_of_birth ? $this->ageBand($k->date_of_birth) : 'unknown'), 30));
            $byCentre[$cn] = ($byCentre[$cn] ?? 0) + 1; $byAge[$age] = ($byAge[$age] ?? 0) + 1;
            $rows[] = array_filter(['child' => $this->shortName($k->first_name, $k->last_name, $k->preferred_name), 'centre' => count($names) > 1 ? $cn : null,
                'age_group' => $age, 'applied' => $this->fmtDate($k->applied_at), 'wants_to_start' => $this->fmtDate($k->expected_start_date)], fn ($v) => $v !== null);
        }
        $out = ['waitlisted' => count($rows), 'by_centre' => $byCentre, 'by_age_group' => $byAge] + $this->cap($rows, 30);
        // Enquiries synced from an outside waitlist are agency-wide (no centre): only for an agency-wide person.
        if ($this->s->isWide() && trim((string) ($in['centre'] ?? '')) === '') {
            $ext = DB::table('external_waitlist')->where('agency_id', $this->s->agencyId)->whereNull('deleted_at')
                ->where(DB::raw('LOWER(status)'), 'waiting')->count();
            if ($ext) { $out['synced_waitlist_waiting'] = $ext; $this->link('Open Synced waitlist', '#synced-waitlist'); }
        }
        $this->link('Open Waitlist', '#waitlist');

        return $out;
    }

    private function ageBand(string $dob): string
    {
        $m = Carbon::parse($dob)->diffInMonths(Carbon::parse($this->today()));
        return $m < 18 ? 'infant' : ($m < 30 ? 'toddler' : ($m < 72 ? 'preschool' : 'school age'));
    }

    private function ratiosNow(array $in): array
    {
        $centres = $this->centres($in);
        $names = $this->s->centreNames();
        $rooms = DB::table('rooms')->whereIn('centre_id', $centres)->where('active', true)->orderBy('centre_id')->orderBy('name')->get();
        $label = ['breach' => 'OUT OF RATIO', 'over_group' => 'over the group size', 'attention' => 'needs attention', 'tight' => 'at the limit', 'ok' => 'in ratio'];
        $rows = []; $bad = 0;
        foreach ($rooms as $room) {
            // RatioRules: the same engine as Room ratios and the 15-minute breach alerts (Ontario rules where they apply).
            $v = RatioRules::evaluate($room);
            $st = RatioAlerts::status($v);
            if (in_array($st, ['breach', 'over_group'], true)) $bad++;
            $kids = (int) ($v['children_present'] ?? 0); $have = (float) ($v['educators_present'] ?? 0);
            if ($kids === 0 && $have <= 0) continue;
            $target = $v['ratio_target'] ?? null;
            $rows[] = array_filter(['room' => self::clean($room->name), 'centre' => count($names) > 1 ? self::clean($names[(int) $room->centre_id] ?? '') : null,
                'children_in' => $kids, 'educators_present' => $have, 'educators_required' => (int) ($v['required_educators'] ?? 0),
                'ratio' => is_scalar($target) ? (string) $target : null, 'status' => $label[$st] ?? $st], fn ($x) => $x !== null && $x !== '');
        }
        usort($rows, fn ($a, $b) => strcmp(($a['status'] === 'in ratio' ? 'z' : 'a') . $a['room'], ($b['status'] === 'in ratio' ? 'z' : 'a') . $b['room']));
        $this->link('Open Room ratios', '#room-ratios');

        return ['as_of' => Carbon::now($this->s->tz)->format('g:i A') . ' today', 'rooms_in_use' => count($rows), 'rooms_out_of_ratio' => $bad] + $this->cap($rows, 40);
    }
}

/** A tool input the model got wrong: the message goes back to the model so it can correct itself. */
final class AskInputError extends \RuntimeException
{
}
