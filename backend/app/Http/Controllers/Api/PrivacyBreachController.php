<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\PrivacyBreachAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PRIVACY BREACH REGISTER (2026-11-06, Anthony: "add a breach section in the portal to record these types of events -
 * fully wire this up"), after the 2026-09-23 immunization emails reached provider staff they were never meant for.
 *
 * PIPEDA, as it applies here:
 *   - s. 10.3 + Breach of Security Safeguards Regulations s. 6: EVERY breach of security safeguards involving personal
 *     information is recorded, and the record is kept 24 months after the day the organization decides a breach
 *     occurred - reported or not. So nothing here deletes; retain_until only ever moves later.
 *   - s. 10.1: where there is a real risk of significant harm (RROSH: sensitivity x likelihood of misuse), report to
 *     the Privacy Commissioner and notify each affected person as soon as feasible. The register holds the assessment,
 *     the reasoning, and the dates both were done - what the Commissioner may ask to see.
 *
 * The agency is the organization accountable for its families' information, so a breach belongs to the ACTIVE agency
 * and only that agency's admins (and platform admins acting in it) change it. Its centre directors READ the records
 * for their own centres plus agency-wide ones (2026-11-06, with the alert emails - App\Support\PrivacyBreachAlerts).
 * Fails closed: no agency, no rows.
 * Kept out of the global search on purpose (SearchRegistry::NOT_SEARCHABLE) and out of EnforcePlanFeatures: a legal
 * duty is never a paid feature. Every write is audited by reference and title only - never the personal details.
 */
class PrivacyBreachController extends Controller
{
    public const INFO_TYPES = [
        'names' => 'Names', 'contact' => 'Contact details (email, phone, address)', 'children' => 'Children\'s information',
        'health' => 'Health or immunization information', 'financial' => 'Financial or payment information',
        'government_id' => 'Government ID (SIN, health card)', 'photos' => 'Photos or video', 'attendance' => 'Attendance or location',
        'credentials' => 'Sign-in credentials', 'other' => 'Other',
    ];

    private const STATUSES = ['open' => 'Open', 'assessing' => 'Assessing', 'reported' => 'Reported', 'closed' => 'Closed'];
    private const LEVELS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];
    private const RROSH = ['undetermined' => 'Not decided yet', 'yes' => 'Yes - real risk of significant harm', 'no' => 'No real risk of significant harm'];

    /** Field => label, for the timeline's "what changed" line. */
    private const LABELS = [
        'title' => 'Title', 'status' => 'Status', 'centre_id' => 'Centre', 'occurred_on' => 'Happened on', 'occurred_to' => 'Happened until',
        'discovered_on' => 'Discovered on', 'determined_on' => 'Confirmed as a breach on', 'contained_on' => 'Contained on',
        'description' => 'What happened', 'cause' => 'Cause', 'info_types' => 'Information involved', 'info_detail' => 'Information detail',
        'individuals_affected' => 'People affected', 'children_affected' => 'Children affected', 'recipients' => 'Who received it',
        'containment' => 'Steps to contain', 'sensitivity' => 'Sensitivity', 'misuse_likelihood' => 'Likelihood of misuse',
        'rrosh' => 'Real risk of significant harm', 'rrosh_reasoning' => 'Reasoning', 'opc_reported_on' => 'Reported to the Commissioner on',
        'opc_reference' => 'Commissioner file number', 'individuals_notified_on' => 'People notified on',
        'notification_method' => 'How people were notified', 'others_notified' => 'Others notified',
    ];

    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request, true);
        $today = AgencyTime::today($agencyId);
        $rows = $this->scoped(DB::table('privacy_breaches')->where('agency_id', $agencyId))->orderByDesc('discovered_on')->orderByDesc('id')->get();
        $centres = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        $names = $centres->pluck('name', 'id');

        return response()->json([
            'today' => $today,
            'can_edit' => ! $this->director,
            'breaches' => $rows->map(fn ($b) => $this->shape($b, $names, $today))->values(),
            'centres' => $centres,
            'info_types' => self::INFO_TYPES,
            'counts' => [
                'open' => $rows->where('status', '!=', 'closed')->count(),
                'needs_decision' => $rows->filter(fn ($b) => $b->status !== 'closed' && $b->rrosh === 'undetermined')->count(),
                'report_due' => $rows->filter(fn ($b) => $b->rrosh === 'yes' && (! $b->opc_reported_on || ! $b->individuals_notified_on))->count(),
                'kept' => $rows->count(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request, true);
        $b = $this->find($agencyId, $id);
        $names = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id');
        $events =DB::table('privacy_breach_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.breach_id', $b->id)->orderBy('e.id')
            ->get(['e.id', 'e.kind', 'e.body', 'e.created_at', 'u.first_name']);
        $tz = AgencyTime::tz($agencyId);

        return response()->json([
            'can_edit' => ! $this->director,
            'breach' => $this->shape($b, $names, AgencyTime::today($agencyId)),
            'events' => $events->map(fn ($e) => [
                'id' => (int) $e->id, 'kind' => $e->kind, 'body' => $e->body, 'by' => $e->first_name ?: 'KiddieTrac',
                'at' => $e->created_at ? Carbon::parse($e->created_at, 'UTC')->setTimezone($tz)->format('M j, Y g:i A') : null,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $data = $this->validated($request, $agencyId, true);
        $uid = (int) $request->user()->id;
        $id = null; $ref = null;
        DB::transaction(function () use ($agencyId, $data, $uid, &$id, &$ref) {
            $year = substr((string) $data['discovered_on'], 0, 4);
            $n = DB::table('privacy_breaches')->where('agency_id', $agencyId)->where('ref', 'like', "PB-{$year}-%")->lockForUpdate()->count();
            do {
                $n++;
                $ref = sprintf('PB-%s-%03d', $year, $n);
            } while (DB::table('privacy_breaches')->where('agency_id', $agencyId)->where('ref', $ref)->exists());
            $row = $this->columns($data) + [
                'agency_id' => $agencyId, 'ref' => $ref, 'status' => $data['status'] ?? 'open',
                'retain_until' => $this->retainUntil($data, null),
                'created_by_id' => $uid, 'updated_by_id' => $uid, 'created_at' => now(), 'updated_at' => now(),
            ];
            if (($row['status'] ?? '') === 'closed') $row['closed_at'] = now();
            $id = DB::table('privacy_breaches')->insertGetId($row);
            DB::table('privacy_breach_events')->insert(['breach_id' => $id, 'user_id' => $uid, 'kind' => 'created',
                'body' => 'Recorded the breach.', 'created_at' => now()]);
        });
        $this->audit($request, $agencyId, 'privacy_breach.created', $id, 'Recorded privacy breach ' . $ref . ': ' . $data['title']);
        \App\Support\PrivacyBreachAlerts::recorded((int) $id, $uid);

        return $this->show($request, $id);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $b = $this->find($agencyId, $id);
        $data = $this->validated($request, $agencyId, false);
        $uid = (int) $request->user()->id;
        $cols = $this->columns($data);
        if (array_key_exists('status', $data)) $cols['status'] = $data['status'];
        $names = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id');
        $changes = [];
        foreach ($cols as $k => $v) {
            $old = $b->{$k};
            if ($k === 'info_types') {
                $o = json_decode((string) $old, true) ?: [];
                $nv = json_decode((string) $v, true) ?: [];
                sort($o); sort($nv);
                if ($o === $nv) { unset($cols[$k]); continue; }
                $changes[] = self::LABELS[$k] . ': ' . ($nv ? implode(', ', array_map(fn ($x) => self::INFO_TYPES[$x] ?? $x, $nv)) : 'none');
                continue;
            }
            if ((string) ($old ?? '') === (string) ($v ?? '')) { unset($cols[$k]); continue; }
            $changes[] = self::LABELS[$k] . ': ' . $this->said($k, $v, $names);
        }
        if (! $cols) return $this->show($request, $id);
        $merged = array_merge((array) $b, $cols);
        $cols['retain_until'] = $this->retainUntil($merged, (string) $b->retain_until);
        if (($cols['status'] ?? null) === 'closed') $cols['closed_at'] = now();
        elseif (isset($cols['status'])) $cols['closed_at'] = null;
        $cols['updated_by_id'] = $uid;
        $cols['updated_at'] = now();
        DB::table('privacy_breaches')->where('id', $b->id)->where('agency_id', $agencyId)->update($cols);
        DB::table('privacy_breach_events')->insert(['breach_id' => $b->id, 'user_id' => $uid, 'kind' => 'changed',
            'body' => mb_substr(implode("\n", $changes), 0, 8000), 'created_at' => now()]);
        $this->audit($request, $agencyId, 'privacy_breach.updated', (int) $b->id, 'Updated privacy breach ' . $b->ref
            . ' (' . implode(', ', array_map(fn ($c) => strtok($c, ':'), $changes)) . ')');
        \App\Support\PrivacyBreachAlerts::changed($b, $uid);

        return $this->show($request, $id);
    }

    public function note(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $b = $this->find($agencyId, $id);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        DB::table('privacy_breach_events')->insert(['breach_id' => $b->id, 'user_id' => (int) $request->user()->id, 'kind' => 'note',
            'body' => trim($data['body']), 'created_at' => now()]);
        DB::table('privacy_breaches')->where('id', $b->id)->update(['updated_at' => now(), 'updated_by_id' => (int) $request->user()->id]);
        $this->audit($request, $agencyId, 'privacy_breach.note', (int) $b->id, 'Added a step to privacy breach ' . $b->ref);

        return $this->show($request, $id);
    }

    /** Who gets the breach alerts, and when (App\Support\PrivacyBreachAlerts::DEFAULTS). Admins only. */
    public function alertSettings(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);

        return response()->json(['settings' => PrivacyBreachAlerts::settings($agencyId), 'defaults' => PrivacyBreachAlerts::DEFAULTS,
            'people' => PrivacyBreachAlerts::eligible($agencyId), 'can_platform' => $this->isPlatform($request)]);
    }

    /** The 'platform' alert switch is the platform's, not the agency's: only a platform admin changes it. */
    private function isPlatform(Request $request): bool
    {
        return DB::table('role_assignments')->where('user_id', (int) $request->user()->id)->where('role', 'platform_admin')->where('active', 1)->exists();
    }

    public function saveAlertSettings(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $in = $request->validate([
            'platform' => ['sometimes', 'boolean'], 'admins' => ['sometimes', 'boolean'], 'directors' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'boolean'], 'bell' => ['sometimes', 'boolean'], 'reminders' => ['sometimes', 'boolean'],
            'muted' => ['sometimes', 'array', 'max:500'], 'muted.*' => ['integer'],
            'extra_emails' => ['sometimes', 'array', 'max:5'], 'extra_emails.*' => ['email:rfc', 'max:190'],
            'events' => ['sometimes', 'array'], 'events.recorded' => ['sometimes', 'boolean'], 'events.risk' => ['sometimes', 'boolean'],
            'events.reported' => ['sometimes', 'boolean'], 'events.closed' => ['sometimes', 'boolean'],
            'reminder_hour' => ['sometimes', 'integer', 'min:0', 'max:23'], 'overdue_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);
        if (! $this->isPlatform($request)) unset($in['platform']);
        // Only this agency's admins and directors can be muted.
        if (isset($in['muted'])) {
            $ok = array_column(PrivacyBreachAlerts::eligible($agencyId), 'user_id');
            $in['muted'] = array_values(array_intersect(array_map('intval', $in['muted']), $ok));
        }
        $before = PrivacyBreachAlerts::settings($agencyId);
        $after = PrivacyBreachAlerts::save($agencyId, $in);
        $changed = [];
        foreach ($after as $k => $v) if ($v != ($before[$k] ?? null)) $changed[] = $k;
        $this->audit($request, $agencyId, 'privacy_breach.alert_settings', null, 'Changed privacy breach alert settings'
            . ($changed ? ' (' . implode(', ', $changed) . ')' : ''));

        return response()->json(['settings' => $after, 'defaults' => PrivacyBreachAlerts::DEFAULTS, 'people' => PrivacyBreachAlerts::eligible($agencyId),
            'can_platform' => $this->isPlatform($request)]);
    }

    /** The whole register as a CSV - what the Commissioner may ask to see. */
    public function export(Request $request)
    {
        $agencyId = $this->agency($request);
        $names = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id');
        $rows = DB::table('privacy_breaches')->where('agency_id', $agencyId)->orderBy('discovered_on')->get();
        $cols = array_merge(['ref' => 'Reference'], self::LABELS, ['retain_until' => 'Keep record until']);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, array_merge(array_values($cols), ['Steps and changes']));
        foreach ($rows as $b) {
            $line = [];
            foreach (array_keys($cols) as $k) {
                $v = $b->{$k} ?? null;
                $line[] = $k === 'info_types'
                    ? implode('; ', array_map(fn ($x) => self::INFO_TYPES[$x] ?? $x, json_decode((string) $v, true) ?: []))
                    : ($k === 'ref' || $k === 'retain_until' ? (string) $v : $this->said($k, $v, $names));
            }
            $line[] = DB::table('privacy_breach_events')->where('breach_id', $b->id)->orderBy('id')->get(['created_at', 'body'])
                ->map(fn ($e) => substr((string) $e->created_at, 0, 10) . ': ' . str_replace("\n", '; ', $e->body))->implode(' | ');
            fputcsv($out, $line);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        $this->audit($request, $agencyId, 'privacy_breach.exported', null, 'Downloaded the privacy breach register (' . $rows->count() . ')');

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="privacy-breach-register-' . AgencyTime::today($agencyId) . '.csv"']);
    }

    /* ── helpers ──────────────────────────────────────────────────────────────────────────────────────────── */

    /**
     * The active agency, for its admin (or a platform admin) - or, READ-ONLY, for one of its centre directors
     * ($readOnly). Sets $this->scope: null = every record; int[] = a director's centres (plus agency-wide records).
     * A director gets the alert emails (PrivacyBreachAlerts), so the reader and the guard move together: the
     * director's centres here are the ones PrivacyBreachAlerts::people() emails them about. Fails closed.
     */
    private function agency(Request $request, bool $readOnly = false): int
    {
        $uid = (int) $request->user()->id;
        $activeId = (int) $request->header('X-Active-Agency-Id');
        $platform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', 1)->exists();
        if (! $activeId) {
            abort_if($platform, 400, 'Select an agency first.');
            $activeId = (int) DB::table('role_assignments')->where('user_id', $uid)->whereIn('role', $readOnly ? ['agency_admin', 'centre_director'] : ['agency_admin'])
                ->where('active', 1)->orderByRaw("role = 'agency_admin' desc")->value('agency_id');
        }
        abort_unless($activeId, 403, 'Only an agency admin can see the privacy breach register.');
        $this->scope = null;
        $admin = $platform || DB::table('role_assignments')->where('user_id', $uid)->where('agency_id', $activeId)
            ->where('role', 'agency_admin')->where('active', 1)->exists();
        if (! $admin) {
            $director = $readOnly ? DB::table('role_assignments')->where('user_id', $uid)->where('agency_id', $activeId)
                ->where('role', 'centre_director')->where('active', 1)->get(['centre_id']) : collect();
            abort_unless($director->isNotEmpty(), 403, $readOnly ? 'Only admins and centre directors can see the privacy breach register.'
                : 'Only an agency admin can change the privacy breach register.');
            // A director with no centre on the role is agency-wide, as in PrivacyBreachAlerts::people().
            $this->scope = $director->contains(fn ($r) => $r->centre_id === null) ? null
                : $director->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            $this->director = true;
        }

        return $activeId;
    }

    /** Null = every record; int[] = a director's centres. */
    private ?array $scope = null;
    private bool $director = false;

    /** Director scope on a query: their centres and agency-wide (centre-less) records. */
    private function scoped($q)
    {
        if ($this->scope === null) return $q;
        $ids = $this->scope ?: [0];

        return $q->where(fn ($w) => $w->whereIn('centre_id', $ids)->orWhereNull('centre_id'));
    }

    private function find(int $agencyId, int $id): object
    {
        $b = $this->scoped(DB::table('privacy_breaches')->where('id', $id)->where('agency_id', $agencyId))->first();
        abort_unless($b, 404, 'That breach record was not found.');

        return $b;
    }

    private function validated(Request $request, int $agencyId, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'description' => [$req, 'string', 'max:8000'],
            'discovered_on' => [$req, 'date_format:Y-m-d'],
            'status' => ['sometimes', 'in:' . implode(',', array_keys(self::STATUSES))],
            'centre_id' => ['sometimes', 'nullable', 'integer'],
            'occurred_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'occurred_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'determined_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'contained_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'cause' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'info_types' => ['sometimes', 'nullable', 'array'],
            'info_types.*' => ['string', 'in:' . implode(',', array_keys(self::INFO_TYPES))],
            'info_detail' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'individuals_affected' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'children_affected' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'recipients' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'containment' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'sensitivity' => ['sometimes', 'nullable', 'in:' . implode(',', array_keys(self::LEVELS))],
            'misuse_likelihood' => ['sometimes', 'nullable', 'in:' . implode(',', array_keys(self::LEVELS))],
            'rrosh' => ['sometimes', 'in:' . implode(',', array_keys(self::RROSH))],
            'rrosh_reasoning' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'opc_reported_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'opc_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'individuals_notified_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notification_method' => ['sometimes', 'nullable', 'string', 'max:200'],
            'others_notified' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);
        if (! empty($data['centre_id'])) {
            abort_unless(DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->exists(), 422, 'That centre is not in this agency.');
        }

        return $data;
    }

    /** Validated input -> table columns (status handled by the caller). */
    private function columns(array $data): array
    {
        $cols = [];
        foreach (array_keys(self::LABELS) as $k) {
            if ($k === 'status' || ! array_key_exists($k, $data)) continue;
            $v = $data[$k];
            if ($k === 'info_types') $v = json_encode(array_values(array_unique($v ?? [])));
            elseif (is_string($v)) $v = trim($v) === '' ? null : trim($v);
            $cols[$k] = $v;
        }

        return $cols;
    }

    /** 24 months from the day it was confirmed as a breach (else discovered). Never earlier than it already was. */
    private function retainUntil(array $b, ?string $current): string
    {
        $from = $b['determined_on'] ?? null ?: ($b['discovered_on'] ?? null);
        $until = Carbon::parse((string) $from)->addMonths(24)->toDateString();

        return $current && $current > $until ? $current : $until;
    }

    private function said(string $k, $v, $names): string
    {
        if ($v === null || $v === '') return 'blank';
        return match ($k) {
            'status' => self::STATUSES[$v] ?? (string) $v,
            'sensitivity', 'misuse_likelihood' => self::LEVELS[$v] ?? (string) $v,
            'rrosh' => self::RROSH[$v] ?? (string) $v,
            'centre_id' => (string) ($names[$v] ?? 'a centre'),
            default => mb_strlen((string) $v) > 140 ? mb_substr((string) $v, 0, 140) . '…' : (string) $v,
        };
    }

    private function shape(object $b, $names, string $today): array
    {
        $a = (array) $b;
        $a['id'] = (int) $b->id;
        $a['info_types'] = json_decode((string) $b->info_types, true) ?: [];
        $a['centre_name'] = $b->centre_id ? ($names[$b->centre_id] ?? null) : null;
        $a['status_label'] = self::STATUSES[$b->status] ?? $b->status;
        $a['rrosh_label'] = self::RROSH[$b->rrosh] ?? $b->rrosh;
        // What still has to happen, in words, for the list and the record.
        $due = [];
        if ($b->status !== 'closed' && $b->rrosh === 'undetermined') $due[] = 'Decide whether there is a real risk of significant harm';
        if ($b->rrosh === 'yes' && ! $b->opc_reported_on) $due[] = 'Report to the Privacy Commissioner';
        if ($b->rrosh === 'yes' && ! $b->individuals_notified_on) $due[] = 'Notify the people affected';
        if ($b->status !== 'closed' && ! $b->contained_on) $due[] = 'Record when it was contained';
        $a['to_do'] = $due;
        $a['days_open'] = (int) Carbon::parse((string) $b->discovered_on)->diffInDays(Carbon::parse($today));
        unset($a['agency_id'], $a['created_by_id'], $a['updated_by_id']);

        return $a;
    }

    private function audit(Request $request, int $agencyId, string $action, ?int $id, string $summary): void
    {
        try {
            Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => 'privacy_breach', 'entity_id' => $id, 'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
