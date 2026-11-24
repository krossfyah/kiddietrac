<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\Sensitive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRIVACY REQUESTS (2026-11-10, PIPEDA review, Anthony: "Tracker + export"). A person asks to see what is held about
 * them or their child, to correct it, to have it deleted, or to withdraw consent. PIPEDA Principle 4.9 and s. 8:
 *   - answer within 30 days of receiving it (s. 8(3)); one extension of up to 30 more, with notice (s. 8(4));
 *   - a missed deadline is deemed a refusal (s. 8(5)), and a refusal must give reasons;
 *   - before giving information out, confirm who is asking and remove other people's information (s. 9(1)).
 *
 * The agency is the organization the request is made to, so a request belongs to the ACTIVE agency and only its
 * admins (and platform admins acting in it) see or change it. Fails closed: no agency, no rows.
 * The export assembles ONE person's or ONE child's records in this agency into a single JSON file, from an explicit
 * table list: a table is read only through a column that ties its rows to this agency (agency_id, centre_id,
 * family_id, or child_id for a child of this agency) - a table with no such column is listed as left out, never read
 * across agencies. Security internals (password hashes, tokens, 2FA secrets, card/bank processor ids, IPs) are never
 * included, sealed fields are opened, images and signatures are named but not embedded.
 * Kept out of the global search (SearchRegistry::NOT_SEARCHABLE) and out of EnforcePlanFeatures: a legal duty.
 * Every write is audited by reference only - never the requester's name or what they asked.
 */
class PrivacyRequestController extends Controller
{
    public const KINDS = [
        'access' => 'See their information', 'correction' => 'Correct their information', 'deletion' => 'Delete their information',
        'withdraw_consent' => 'Withdraw consent', 'other' => 'Other privacy question',
    ];
    private const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'completed' => 'Completed', 'refused' => 'Refused', 'withdrawn' => 'Withdrawn'];
    private const DONE = ['completed', 'refused', 'withdrawn'];
    private const RELATIONS = ['self' => 'The person themselves', 'parent' => 'Parent or guardian of the child', 'representative' => 'Someone acting for them'];

    private const LABELS = [
        'kind' => 'Request', 'status' => 'Status', 'requester_name' => 'Asked by', 'requester_relation' => 'Relationship',
        'subject_type' => 'About', 'subject_id' => 'About', 'received_on' => 'Received on', 'extended_to' => 'Extended to',
        'extension_reason' => 'Why it was extended', 'identity_verified_on' => 'Identity confirmed on', 'identity_method' => 'How identity was confirmed',
        'details' => 'What they asked for', 'response' => 'Answer given', 'completed_on' => 'Answered on',
    ];

    /** Child-keyed tables in the export. A child of this agency owns its rows, so child_id alone scopes them. */
    private const CHILD_TABLES = [
        'enrollments' => 'Enrolment', 'check_events' => 'Check-ins and check-outs', 'child_absences' => 'Absences',
        'daily_events' => 'Daily care entries', 'daily_care_logs' => 'Daily care logs', 'observations' => 'Observations',
        'report_cards' => 'Report cards', 'incidents' => 'Incident reports', 'immunizations' => 'Immunizations',
        'medications' => 'Medications', 'medication_logs' => 'Medication given', 'wellness_screenings' => 'Wellness screenings',
        'safe_arrival_checks' => 'Safe arrival checks', 'safe_departure_checks' => 'Safe departure checks',
        'field_trip_permissions' => 'Field trip permissions', 'child_media_consents' => 'Photo permissions',
        'edocument_signatures' => 'Signed documents', 'kiosk_signatures' => 'Sign-in and sign-out signatures',
        'subsidies' => 'Subsidies', 'withdrawal_requests' => 'Withdrawal requests', 'child_notes' => 'Notes',
        'photo_tags' => 'Photos they are tagged in',
    ];

    /** Person-keyed tables. Each is read only through its agency/centre/family column (see export()). */
    private const USER_TABLES = [
        'role_assignments' => 'Roles', 'guardians' => 'Families they belong to', 'emergency_contacts' => 'Emergency contact for',
        'time_punches' => 'Time clock entries', 'time_off_requests' => 'Time off requests', 'staff_certifications' => 'Certifications',
        'background_checks' => 'Background checks', 'payroll_documents' => 'Pay documents', 'pd_hours' => 'Professional development hours',
        'shifts' => 'Shifts', 'training_progress' => 'Training progress', 'managed_form_signoffs' => 'Forms they signed off',
        'notification_prefs' => 'Notification settings', 'user_profiles' => 'Profile extras',
        'privacy_acknowledgments' => 'When they confirmed reading the privacy notice',
    ];

    /** Never in an export: security internals and processor ids, not information "about" the person. */
    private const DENY = '/(password|token|secret|recovery_code|api_key|_hash$|remember|stripe|payment_method_id|_ip$|user_agent|notifications_seen_id|last_agency_id|external_id|external_source|ref_agency_id|kiosk_pin)/i';
    private const ROW_CAP = 5000;

    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $today = AgencyTime::today($agencyId);
        $rows = DB::table('privacy_requests')->where('agency_id', $agencyId)->orderByDesc('received_on')->orderByDesc('id')->get();
        $list = $rows->map(fn ($r) => $this->shape($r, $agencyId, $today))->values();

        return response()->json([
            'today' => $today,
            'requests' => $list,
            'kinds' => self::KINDS,
            'counts' => [
                'open' => $list->where('done', false)->count(),
                'due_soon' => $list->filter(fn ($r) => ! $r['done'] && $r['days_left'] >= 0 && $r['days_left'] <= 7)->count(),
                'overdue' => $list->filter(fn ($r) => ! $r['done'] && $r['days_left'] < 0)->count(),
                'kept' => $list->count(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $r = $this->find($agencyId, $id);
        $events = DB::table('privacy_request_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.request_id', $r->id)->orderBy('e.id')
            ->get(['e.id', 'e.kind', 'e.body', 'e.created_at', 'u.first_name']);
        $tz = AgencyTime::tz($agencyId);

        return response()->json([
            'request' => $this->shape($r, $agencyId, AgencyTime::today($agencyId)),
            'events' => $events->map(fn ($e) => [
                'id' => (int) $e->id, 'kind' => $e->kind, 'body' => $e->body, 'by' => $e->first_name ?: 'KiddieTrac',
                'at' => $e->created_at ? Carbon::parse($e->created_at, 'UTC')->setTimezone($tz)->format('M j, Y g:i A') : null,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $data = $this->validated($request, $agencyId, true, null);
        $uid = (int) $request->user()->id;
        $id = null; $ref = null;
        DB::transaction(function () use ($agencyId, $data, $uid, &$id, &$ref) {
            $year = substr((string) $data['received_on'], 0, 4);
            $n = DB::table('privacy_requests')->where('agency_id', $agencyId)->where('ref', 'like', "PR-{$year}-%")->lockForUpdate()->count();
            do {
                $n++;
                $ref = sprintf('PR-%s-%03d', $year, $n);
            } while (DB::table('privacy_requests')->where('agency_id', $agencyId)->where('ref', $ref)->exists());
            $row = $this->columns($data) + [
                'agency_id' => $agencyId, 'ref' => $ref, 'status' => $data['status'] ?? 'open',
                'due_on' => Carbon::parse($data['received_on'])->addDays(30)->toDateString(),
                'created_by_id' => $uid, 'updated_by_id' => $uid, 'created_at' => now(), 'updated_at' => now(),
            ];
            if (in_array($row['status'], self::DONE, true)) $row['closed_at'] = now();
            $id = DB::table('privacy_requests')->insertGetId($row);
            DB::table('privacy_request_events')->insert(['request_id' => $id, 'user_id' => $uid, 'kind' => 'created',
                'body' => 'Logged the request.', 'created_at' => now()]);
        });
        $this->audit($request, $agencyId, 'privacy_request.created', $id, 'Logged privacy request ' . $ref . ' (' . self::KINDS[$data['kind']] . ')');

        return $this->show($request, $id);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $r = $this->find($agencyId, $id);
        $data = $this->validated($request, $agencyId, false, $r);
        $uid = (int) $request->user()->id;
        $cols = $this->columns($data);
        if (array_key_exists('status', $data)) $cols['status'] = $data['status'];
        $changes = [];
        foreach ($cols as $k => $v) {
            if ((string) ($r->{$k} ?? '') === (string) ($v ?? '')) { unset($cols[$k]); continue; }
            if ($k === 'subject_type') continue;                      // said once, with subject_id
            $changes[] = self::LABELS[$k] . ': ' . $this->said($k, $v, $agencyId, $cols['subject_type'] ?? $r->subject_type);
        }
        if (! $cols) return $this->show($request, $id);
        if (isset($cols['received_on'])) $cols['due_on'] = Carbon::parse($cols['received_on'])->addDays(30)->toDateString();
        if (isset($cols['status'])) $cols['closed_at'] = in_array($cols['status'], self::DONE, true) ? now() : null;
        $cols['updated_by_id'] = $uid;
        $cols['updated_at'] = now();
        DB::table('privacy_requests')->where('id', $r->id)->where('agency_id', $agencyId)->update($cols);
        DB::table('privacy_request_events')->insert(['request_id' => $r->id, 'user_id' => $uid, 'kind' => 'changed',
            'body' => mb_substr(implode("\n", $changes), 0, 8000), 'created_at' => now()]);
        $this->audit($request, $agencyId, 'privacy_request.updated', (int) $r->id, 'Updated privacy request ' . $r->ref
            . ' (' . implode(', ', array_unique(array_map(fn ($c) => strtok($c, ':'), $changes))) . ')');

        return $this->show($request, $id);
    }

    public function note(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $r = $this->find($agencyId, $id);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        DB::table('privacy_request_events')->insert(['request_id' => $r->id, 'user_id' => (int) $request->user()->id, 'kind' => 'note',
            'body' => trim($data['body']), 'created_at' => now()]);
        DB::table('privacy_requests')->where('id', $r->id)->update(['updated_at' => now(), 'updated_by_id' => (int) $request->user()->id]);
        $this->audit($request, $agencyId, 'privacy_request.note', (int) $r->id, 'Added a step to privacy request ' . $r->ref);

        return $this->show($request, $id);
    }

    /** People and children of THIS agency, by name, to say whose information a request is about. */
    public function subjects(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) return response()->json(['people' => [], 'children' => []]);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all() ?: [0];
        $name = fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
            ->orWhere('preferred_name', 'like', $like)->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);

        $people = DB::table('users')->whereNull('deleted_at')->where($name)
            ->whereIn('id', fn ($s) => $s->select('user_id')->from('role_assignments')
                ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereIn('centre_id', $centreIds)))
            ->orderBy('first_name')->limit(15)->get(['id', 'first_name', 'last_name']);
        $children = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)->whereNull('c.deleted_at')
            ->where(fn ($w) => $w->where('c.first_name', 'like', $like)->orWhere('c.last_name', 'like', $like)
                ->orWhere('c.preferred_name', 'like', $like)->orWhereRaw("CONCAT(c.first_name, ' ', c.last_name) LIKE ?", [$like]))
            ->orderBy('c.first_name')->limit(15)->get(['c.id', 'c.first_name', 'c.last_name', 'c.date_of_birth']);

        return response()->json([
            'people' => $people->map(fn ($u) => ['type' => 'user', 'id' => (int) $u->id, 'label' => $this->subjectLabel('user', (int) $u->id, $agencyId)])->values(),
            'children' => $children->map(fn ($c) => ['type' => 'child', 'id' => (int) $c->id, 'label' => $this->subjectLabel('child', (int) $c->id, $agencyId)])->values(),
        ]);
    }

    /**
     * Everything this agency holds on the request's person or child, as one JSON file. A working copy for the admin:
     * the file says to remove other people's information before it is given out (s. 9(1)).
     */
    public function export(Request $request, int $id)
    {
        $agencyId = $this->agency($request);
        $r = $this->find($agencyId, $id);
        abort_unless($r->subject_type && $r->subject_id, 422, 'Choose whose information this request is about first.');
        abort_unless($this->subjectInAgency($r->subject_type, (int) $r->subject_id, $agencyId), 403, 'That person or child is not in this agency.');

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($v) => (int) $v)->all() ?: [0];
        $familyIds = DB::table('families')->whereIn('centre_id', $centreIds)->pluck('id')->map(fn ($v) => (int) $v)->all() ?: [0];
        $sections = [];
        $left = [];
        $rowCount = 0;
        $take = function (string $table, string $label, callable $where) use (&$sections, &$left, &$rowCount) {
            if (! Schema::hasTable($table)) return;
            $q = DB::table($table);
            $where($q);
            $rows = $q->orderBy(Schema::hasColumn($table, 'id') ? 'id' : DB::raw('1'))->limit(self::ROW_CAP + 1)->get();
            if ($rows->isEmpty()) return;
            $capped = $rows->count() > self::ROW_CAP;
            $clean = $rows->take(self::ROW_CAP)->map(fn ($row) => $this->clean($row))->values()->all();
            $rowCount += count($clean);
            $sections[$table] = ['label' => $label, 'rows' => count($clean)] + ($capped ? ['note' => 'Only the first ' . self::ROW_CAP . ' rows are included.'] : []) + ['records' => $clean];
        };

        if ($r->subject_type === 'child') {
            $cid = (int) $r->subject_id;
            $take('children', 'The child\'s record', fn ($q) => $q->where('id', $cid));
            $fid = (int) DB::table('children')->where('id', $cid)->value('family_id');
            $take('families', 'Family', fn ($q) => $q->where('id', $fid));
            $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $fid)
                ->get(['u.first_name', 'u.last_name', 'g.relationship', 'g.is_primary', 'g.can_pickup']);
            if ($guardians->isNotEmpty()) {
                $sections['guardians'] = ['label' => 'Parents and guardians on the family', 'rows' => $guardians->count(), 'records' => $guardians->map(fn ($g) => (array) $g)->all()];
            }
            foreach (self::CHILD_TABLES as $t => $label) {
                if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'child_id')) continue;
                $take($t, $label, fn ($q) => $q->where('child_id', $cid));
            }
        } else {
            $uid = (int) $r->subject_id;
            $take('users', 'Account and contact details', fn ($q) => $q->where('id', $uid));
            foreach (self::USER_TABLES as $t => $label) {
                if (! Schema::hasTable($t)) continue;
                $col = Schema::hasColumn($t, 'user_id') ? 'user_id' : null;
                if (! $col) continue;
                // Only through a column that ties the row to THIS agency - never a person's rows from another agency.
                if (Schema::hasColumn($t, 'agency_id')) $scope = fn ($q) => $q->where('agency_id', $agencyId);
                elseif (Schema::hasColumn($t, 'centre_id')) $scope = fn ($q) => $q->whereIn('centre_id', $centreIds);
                elseif (Schema::hasColumn($t, 'family_id')) $scope = fn ($q) => $q->whereIn('family_id', $familyIds);
                else { $left[] = $label . ' (' . $t . ') - not stored per agency, so not included; ask KiddieTrac support if it is needed'; continue; }
                $take($t, $label, function ($q) use ($col, $uid, $scope) { $q->where($col, $uid); $scope($q); });
            }
            $mine = DB::table('guardians')->where('user_id', $uid)->whereIn('family_id', $familyIds)->pluck('family_id')->all();
            if ($mine) {
                $take('families', 'Their family', fn ($q) => $q->whereIn('id', $mine));
                $kids = DB::table('children')->whereIn('family_id', $mine)->whereNull('deleted_at')->get(['first_name', 'last_name', 'date_of_birth', 'enrollment_status']);
                $sections['children'] = ['label' => 'Children on their family (each child\'s full record is a separate request)', 'rows' => $kids->count(),
                    'records' => $kids->map(fn ($c) => (array) $c)->all()];
            }
            $left[] = 'Messages and chats - not copied here: they hold other people’s words too. Copy the ones asked for from the conversation itself.';
        }

        $today = AgencyTime::today($agencyId);
        $file = [
            'request' => $r->ref,
            'prepared_on' => $today,
            'about' => $this->subjectLabel($r->subject_type, (int) $r->subject_id, $agencyId),
            'before_you_send' => 'This is a working copy for the agency. Before giving it to anyone: confirm who is asking, remove other people\'s '
                . 'information (other children, other families, staff notes about third parties), and give it in a form the person can understand. '
                . 'Images and signatures are named but not included. Security data (passwords, sign-in tokens, two-factor secrets, payment processor ids) is never included.',
            'left_out' => $left,
            'sections' => $sections,
        ];
        DB::table('privacy_request_events')->insert(['request_id' => $r->id, 'user_id' => (int) $request->user()->id, 'kind' => 'exported',
            'body' => 'Downloaded the records (' . count($sections) . ' sections, ' . $rowCount . ' rows).', 'created_at' => now()]);
        $this->audit($request, $agencyId, 'privacy_request.exported', (int) $r->id, 'Downloaded the records for privacy request ' . $r->ref);

        return response(json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="privacy-request-' . $r->ref . '-' . $today . '.json"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /* ── helpers ──────────────────────────────────────────────────────────────────────────────────────────── */

    /** The active agency, for its admin or a platform admin. Fails closed. */
    private function agency(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $activeId = (int) $request->header('X-Active-Agency-Id');
        $platform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', 1)->exists();
        if (! $activeId) {
            abort_if($platform, 400, 'Select an agency first.');
            $activeId = (int) DB::table('role_assignments')->where('user_id', $uid)->where('role', 'agency_admin')->where('active', 1)->value('agency_id');
        }
        abort_unless($activeId, 403, 'Only an agency admin can see privacy requests.');
        abort_unless($platform || DB::table('role_assignments')->where('user_id', $uid)->where('agency_id', $activeId)
            ->where('role', 'agency_admin')->where('active', 1)->exists(), 403, 'Only an agency admin can see privacy requests.');

        return $activeId;
    }

    private function find(int $agencyId, int $id): object
    {
        $r = DB::table('privacy_requests')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($r, 404, 'That privacy request was not found.');

        return $r;
    }

    private function subjectInAgency(string $type, int $id, int $agencyId): bool
    {
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all() ?: [0];
        if ($type === 'child') {
            return DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
                ->where('c.id', $id)->whereIn('f.centre_id', $centreIds)->exists();
        }

        return DB::table('role_assignments')->where('user_id', $id)
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereIn('centre_id', $centreIds))->exists();
    }

    /** "Ava Smith · ILH-C-0042" - a name and the agency's reference, never a raw id. */
    private function subjectLabel(?string $type, ?int $id, int $agencyId): ?string
    {
        if (! $type || ! $id) return null;
        if ($type === 'child') {
            $c = DB::table('children')->where('id', $id)->first(['first_name', 'last_name']);
            if (! $c) return 'A child (record removed)';
            $ref = null;
            try { $ref = \App\Support\RefCode::ensureChild($id); } catch (\Throwable $e) {}

            return trim($c->first_name . ' ' . $c->last_name) . ' (child)' . ($ref ? ' · ' . $ref : '');
        }
        $u = DB::table('users')->where('id', $id)->first(['first_name', 'last_name']);
        if (! $u) return 'A person (account removed)';
        $roles = DB::table('role_assignments')->where('user_id', $id)->where('agency_id', $agencyId)->where('active', 1)->pluck('role')->unique()
            ->map(fn ($r) => str_replace('_', ' ', $r))->implode(', ');
        $ref = null;
        try { $ref = \App\Support\RefCode::ensureUser($id, $agencyId); } catch (\Throwable $e) {}

        return trim($u->first_name . ' ' . $u->last_name) . ($roles ? ' (' . $roles . ')' : '') . ($ref ? ' · ' . $ref : '');
    }

    private function validated(Request $request, int $agencyId, bool $creating, ?object $current): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'kind' => [$req, 'in:' . implode(',', array_keys(self::KINDS))],
            'status' => ['sometimes', 'in:' . implode(',', array_keys(self::STATUSES))],
            'requester_name' => [$req, 'string', 'max:160'],
            'requester_relation' => ['sometimes', 'in:' . implode(',', array_keys(self::RELATIONS))],
            'subject_type' => ['sometimes', 'nullable', 'in:user,child'],
            'subject_id' => ['sometimes', 'nullable', 'integer'],
            'received_on' => [$req, 'date_format:Y-m-d'],
            'extended_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'extension_reason' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'identity_verified_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'identity_method' => ['sometimes', 'nullable', 'string', 'max:200'],
            'details' => [$req, 'string', 'max:8000'],
            'response' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'completed_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        if (array_key_exists('subject_type', $data) || array_key_exists('subject_id', $data)) {
            $type = $data['subject_type'] ?? null;
            $sid = $data['subject_id'] ?? null;
            if (! $type || ! $sid) { $data['subject_type'] = null; $data['subject_id'] = null; }
            else abort_unless($this->subjectInAgency($type, (int) $sid, $agencyId), 422, 'That person or child is not in this agency.');
        }
        // One extension, of up to 30 days past the original due date, with a reason (s. 8(4)).
        if (! empty($data['extended_to'])) {
            $received = $data['received_on'] ?? $current->received_on;
            $due = Carbon::parse((string) $received)->addDays(30);
            $to = Carbon::parse($data['extended_to']);
            abort_if($to->lte($due) || $to->gt($due->copy()->addDays(30)), 422, 'An extension can be up to 30 days past the due date of ' . $due->format('M j, Y') . '.');
            $reason = array_key_exists('extension_reason', $data) ? $data['extension_reason'] : ($current->extension_reason ?? null);
            abort_if(trim((string) $reason) === '', 422, 'Say why it is being extended - the person has to be told.');
        }
        $status = $data['status'] ?? ($current->status ?? 'open');
        if ($status === 'refused') {
            $resp = array_key_exists('response', $data) ? $data['response'] : ($current->response ?? null);
            abort_if(trim((string) $resp) === '', 422, 'A refusal needs its reasons in "Answer given".');
        }

        return $data;
    }

    private function columns(array $data): array
    {
        $cols = [];
        foreach (array_keys(self::LABELS) as $k) {
            if ($k === 'status' || ! array_key_exists($k, $data)) continue;
            $v = $data[$k];
            if (is_string($v)) $v = trim($v) === '' ? null : trim($v);
            $cols[$k] = $v;
        }

        return $cols;
    }

    private function said(string $k, $v, int $agencyId, ?string $subjectType): string
    {
        if ($v === null || $v === '') return 'blank';
        return match ($k) {
            'kind' => self::KINDS[$v] ?? (string) $v,
            'status' => self::STATUSES[$v] ?? (string) $v,
            'requester_relation' => self::RELATIONS[$v] ?? (string) $v,
            'subject_id' => (string) $this->subjectLabel($subjectType, (int) $v, $agencyId),
            default => mb_strlen((string) $v) > 140 ? mb_substr((string) $v, 0, 140) . '…' : (string) $v,
        };
    }

    private function shape(object $r, int $agencyId, string $today): array
    {
        $a = (array) $r;
        $a['id'] = (int) $r->id;
        $a['subject_id'] = $r->subject_id ? (int) $r->subject_id : null;
        $a['kind_label'] = self::KINDS[$r->kind] ?? $r->kind;
        $a['status_label'] = self::STATUSES[$r->status] ?? $r->status;
        $a['subject_label'] = $this->subjectLabel($r->subject_type, $a['subject_id'], $agencyId);
        $a['done'] = in_array($r->status, self::DONE, true);
        $a['answer_by'] = $r->extended_to ?: $r->due_on;
        $a['days_left'] = (int) Carbon::parse($today)->diffInDays(Carbon::parse((string) $a['answer_by']), false);
        $due = [];
        if (! $a['done']) {
            if (! $r->identity_verified_on) $due[] = 'Confirm who is asking';
            if (! $r->subject_id) $due[] = 'Choose whose information it is about';
            $due[] = $a['days_left'] < 0 ? 'Answer - ' . abs($a['days_left']) . ' day' . (abs($a['days_left']) === 1 ? '' : 's') . ' overdue'
                : 'Answer by ' . Carbon::parse((string) $a['answer_by'])->format('M j');
        }
        $a['to_do'] = $due;
        unset($a['agency_id'], $a['created_by_id'], $a['updated_by_id']);

        return $a;
    }

    /** One exported row: security internals out, sealed fields opened, images named not embedded. */
    private function clean(object $row): array
    {
        $row = Sensitive::openRow($row);
        $out = [];
        foreach ((array) $row as $k => $v) {
            if (preg_match(self::DENY, (string) $k)) continue;
            if (is_string($v)) {
                if (str_starts_with($v, 'data:')) { $v = '[image or signature - not included]'; }
                elseif (Sensitive::isSealed($v)) { $v = '[could not be opened]'; }
                elseif (mb_strlen($v) > 20000) { $v = mb_substr($v, 0, 20000) . ' … [shortened; ' . mb_strlen($v) . ' characters in full]'; }
                elseif (($v[0] ?? '') === '{' || ($v[0] ?? '') === '[') {
                    $j = json_decode($v, true);
                    if (is_array($j)) $v = $j;
                }
            }
            $out[$k] = $v;
        }

        return $out;
    }

    private function audit(Request $request, int $agencyId, string $action, ?int $id, string $summary): void
    {
        try {
            Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => 'privacy_request', 'entity_id' => $id, 'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
