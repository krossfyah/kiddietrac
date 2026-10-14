<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\FcmService;
use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PARENTS REPORT AN ISSUE (2026-10-13, Anthony: "add a report an issue section for parents to
 * report an issue to staff and add drop down selections to choose from and notes to be added
 * - full sweep").
 *
 * A parent picks the child (or "my family in general"), a category, a more specific reason,
 * and writes what happened. The centre's director(s) and the agency's admins are told by
 * bell, push and email; the child's room educators by bell and push. A report about STAFF
 * CONDUCT never goes to educators and educators cannot open one: a parent raising a concern
 * about an educator must not have it read first by that educator.
 *
 * Staff answer on #parent-issues: reply to the family, leave an internal note the family
 * never sees, set the status, assign it. The family is told by bell and email each time
 * there is a reply or the status changes, and sees every reply on #report-issue.
 *
 * Scoping: the ACTIVE agency, then the caller's centres (App\Support\Visibility, so a
 * director sees their centres only), then, for an educator, the children in their own rooms.
 * Everything fails closed.
 */
final class ParentIssueController extends Controller
{
    use ResolvesCentreContext;

    /** key => [label, [reasons]]. 'staff' is sensitive (see the class comment). */
    public const CATEGORIES = [
        'billing' => ['Billing & payments', ['Invoice amount looks wrong', 'Payment or autopay problem', 'Subsidy or CWELCC fee', 'Receipt or tax slip', 'Other billing question']],
        'health' => ['Health, medication or allergy', ['Medication not given, or given wrongly', 'Allergy or diet not followed', 'Illness or symptoms', 'Health record needs updating', 'Other health concern']],
        'injury' => ['Injury or incident follow-up', ['Injury I was not told about', 'Question about an incident report', 'Bump, bite or scratch', 'Other']],
        'behaviour' => ['Behaviour or social', ['Bullying or conflict with another child', "My child's behaviour", 'How a situation was handled', 'Other']],
        'food' => ['Food & meals', ['Did not eat or not enough food', 'Menu question', 'Food quality', 'Other']],
        'communication' => ['Communication', ['Did not get the daily report or photos', 'Message not answered', 'Notice or announcement unclear', 'Other']],
        'staff' => ['Concern about a staff member', ['How a staff member spoke or acted', 'Supervision concern', 'Other']],
        'safety' => ['Safety or the building', ['Building or playground hazard', 'Cleanliness', 'Doors, gates or security', 'Other']],
        'pickup' => ['Pick-up & drop-off', ['Sign-in or sign-out time is wrong', 'Problem with a pick-up person', 'Late pick-up fee', 'Other']],
        'lost' => ['Lost or damaged item', ['Clothing', 'Bottle, lunch box or bag', 'Comfort item or toy', 'Other']],
        'app' => ['KiddieTrac app or website', ["Can't sign in", 'Notifications not arriving', "Something isn't working", 'Other']],
        'other' => ['Something else', []],
    ];

    public const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];

    private const SENSITIVE = ['staff'];

    // ── Parent ──────────────────────────────────────────────────────────────

    /** GET /parent/issues/options -- the categories and the caller's children. */
    public function parentOptions(Request $request): JsonResponse
    {
        $links = $this->parentFamilies((int) $request->user()->id);
        $pickupOnly = $links->isNotEmpty() && $links->every(fn ($l) => (int) $l->billing_hidden === 1);
        // Current children only (App\Support\Live): a withdrawn child is not in today's care.
        $children = DB::table('children as ch')->whereIn('ch.family_id', $links->pluck('family_id')->all() ?: [0])
            ->tap(fn ($q) => \App\Support\Live::children($q, 'ch'))
            ->orderBy('ch.first_name')->get(['ch.id', 'ch.family_id', 'ch.first_name', 'ch.preferred_name', 'ch.last_name']);

        return response()->json([
            'categories' => $this->categoryList($pickupOnly ? ['billing'] : []),
            'children' => $children->map(fn ($c) => ['id' => (int) $c->id, 'family_id' => (int) $c->family_id,
                'name' => trim(($c->preferred_name ?: $c->first_name) . ' ' . $c->last_name)])->values(),
            'families' => $links->map(fn ($l) => ['id' => (int) $l->family_id, 'name' => $l->family_name])->values(),
        ]);
    }

    /** GET /parent/issues -- what the caller has reported, with the centre's replies. */
    public function parentIndex(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $rows = DB::table('parent_issues')->where('reported_by', $uid)->orderByDesc('id')->limit(100)->get();
        $msgs = DB::table('parent_issue_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.issue_id', $rows->pluck('id')->all() ?: [0])->where('m.internal', 0)
            ->orderBy('m.id')->get(['m.issue_id', 'm.author_side', 'm.body', 'm.created_at', 'u.first_name', 'u.last_name'])
            ->groupBy('issue_id');

        return response()->json(['issues' => $rows->map(function ($r) use ($msgs) {
            $tz = AgencyTime::tz((int) $r->agency_id);

            return $this->shape($r, $tz) + [
                'messages' => ($msgs->get($r->id) ?? collect())->map(fn ($m) => [
                    'side' => $m->author_side,
                    'who' => $m->author_side === 'parent' ? 'You' : (trim($m->first_name . ' ' . $m->last_name) ?: 'The centre'),
                    'body' => $m->body,
                    'at' => $this->fmt($m->created_at, $tz),
                ])->values(),
            ];
        })->values()]);
    }

    /** POST /parent/issues {child_id?, family_id?, category, subcategory?, notes} */
    public function parentStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'nullable|integer',
            'family_id' => 'nullable|integer',
            'category' => 'required|string|in:' . implode(',', array_keys(self::CATEGORIES)),
            'subcategory' => 'nullable|string|max:120',
            'notes' => 'required|string|min:10|max:4000',
        ], ['notes.min' => 'Please describe the issue in a little more detail (at least 10 characters).']);
        $uid = (int) $request->user()->id;
        $links = $this->parentFamilies($uid)->keyBy('family_id');

        if (! empty($data['child_id'])) {
            $child = DB::table('children')->where('id', $data['child_id'])->whereNull('deleted_at')->first(['id', 'family_id']);
            abort_unless($child && $links->has((int) $child->family_id), 404, 'That child is not on your account.');
            $familyId = (int) $child->family_id;
        } else {
            $familyId = (int) ($data['family_id'] ?? ($links->count() === 1 ? $links->keys()->first() : 0));
            abort_unless($familyId && $links->has($familyId), 422, 'Choose which child (or family) this is about.');
        }
        $link = $links->get($familyId);
        if ($data['category'] === 'billing' && (int) $link->billing_hidden === 1) {
            abort(403, 'Billing questions go to the parents who pay the account.');
        }
        $sub = trim((string) ($data['subcategory'] ?? ''));
        $reasons = self::CATEGORIES[$data['category']][1];
        if ($sub !== '' && $reasons && ! in_array($sub, $reasons, true)) {
            return response()->json(['message' => 'Choose a reason from the list.', 'errors' => ['subcategory' => ['Choose a reason from the list.']]], 422);
        }
        $centreId = (int) DB::table('families')->where('id', $familyId)->value('centre_id');
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        abort_unless($agencyId, 404);

        $now = now();
        $id = (int) DB::table('parent_issues')->insertGetId([
            'agency_id' => $agencyId, 'centre_id' => $centreId ?: null, 'family_id' => $familyId,
            'child_id' => $data['child_id'] ?? null, 'reported_by' => $uid,
            'category' => $data['category'], 'subcategory' => $sub !== '' ? $sub : null,
            'notes' => trim($data['notes']), 'status' => 'open',
            'last_activity_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $issue = DB::table('parent_issues')->find($id);
        $this->audit($uid, $agencyId, 'parent_issue.reported', $issue, $this->who($uid) . ' reported an issue: '
            . $this->label($issue) . ($issue->child_id ? ' (' . $this->childName((int) $issue->child_id) . ')' : '') . '.');
        $this->tellStaffNew($issue);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** POST /parent/issues/{id}/messages {body} -- the family adds to their report. */
    public function parentMessage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|min:2|max:4000']);
        $uid = (int) $request->user()->id;
        $issue = DB::table('parent_issues')->where('id', $id)->where('reported_by', $uid)->first();
        abort_unless($issue, 404);
        DB::table('parent_issue_messages')->insert(['issue_id' => $id, 'user_id' => $uid, 'author_side' => 'parent',
            'body' => trim($data['body']), 'internal' => 0, 'created_at' => now()]);
        $upd = ['last_activity_at' => now(), 'updated_at' => now()];
        // Writing again about a settled report reopens it: the family is saying it is not settled.
        if (in_array($issue->status, ['resolved', 'closed'], true)) {
            $upd += ['status' => 'open', 'resolved_at' => null, 'resolved_by' => null];
        }
        DB::table('parent_issues')->where('id', $id)->update($upd);
        $this->audit($uid, (int) $issue->agency_id, 'parent_issue.parent_reply', $issue,
            $this->who($uid) . ' added to their report #' . $id . (isset($upd['status']) ? ' (reopened)' : '') . '.');
        $this->tellStaffFollowUp($issue, isset($upd['status']));

        return response()->json(['ok' => true]);
    }

    // ── Staff ───────────────────────────────────────────────────────────────

    /** GET /parent-issues?status=&category=&centre_id= */
    public function staffIndex(Request $request): JsonResponse
    {
        [$agencyId, $role] = $this->staffScope($request);
        $q = $this->scopedQuery($request, $agencyId, $role);
        $counts = (clone $q)->groupBy('i.status')->selectRaw('i.status, COUNT(*) n')->pluck('n', 'status');
        if ($request->filled('status') && $request->query('status') !== 'all') {
            $request->query('status') === 'active'
                ? $q->whereIn('i.status', ['open', 'in_progress'])
                : $q->where('i.status', (string) $request->query('status'));
        }
        if ($request->filled('category')) $q->where('i.category', (string) $request->query('category'));
        if ($request->filled('centre_id')) $q->where('i.centre_id', (int) $request->query('centre_id'));
        $rows = $q->orderByRaw("FIELD(i.status,'open','in_progress','resolved','closed')")->orderByDesc('i.last_activity_at')
            ->limit(300)->get(['i.*']);
        $tz = AgencyTime::tz($agencyId);

        return response()->json([
            'role' => $role,
            'issues' => $rows->map(fn ($r) => $this->shape($r, $tz, true))->values(),
            'counts' => $counts,
            'categories' => $this->categoryList($role === 'educator' ? self::SENSITIVE : []),
            'statuses' => self::STATUSES,
            'centres' => $role === 'educator' ? [] : DB::table('centres')->whereIn('id', $this->staffCentres($request, $agencyId) ?: [0])
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** GET /parent-issues/{id} -- the report, every message (internal notes too), who it can go to. */
    public function staffShow(Request $request, int $id): JsonResponse
    {
        [$agencyId, $role] = $this->staffScope($request);
        $issue = $this->staffIssue($request, $agencyId, $role, $id);
        $tz = AgencyTime::tz($agencyId);
        $msgs = DB::table('parent_issue_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.issue_id', $id)->orderBy('m.id')
            ->get(['m.author_side', 'm.body', 'm.internal', 'm.created_at', 'u.first_name', 'u.last_name']);
        $assignable = $role === 'educator' ? [] : $this->assignable($issue);

        return response()->json([
            'issue' => $this->shape($issue, $tz, true) + [
                'reporter_email' => DB::table('users')->where('id', $issue->reported_by)->value('email'),
                'reporter_phone' => DB::table('users')->where('id', $issue->reported_by)->value('phone'),
            ],
            'messages' => $msgs->map(fn ($m) => [
                'side' => $m->author_side, 'internal' => (bool) $m->internal,
                'who' => trim($m->first_name . ' ' . $m->last_name) ?: ($m->author_side === 'parent' ? 'Parent' : 'Staff'),
                'body' => $m->body, 'at' => $this->fmt($m->created_at, $tz),
            ])->values(),
            'assignable' => $assignable,
            'can_assign' => $role !== 'educator',
            'statuses' => self::STATUSES,
        ]);
    }

    /** PATCH /parent-issues/{id} {status?, assigned_to?, resolution_note?} */
    public function staffUpdate(Request $request, int $id): JsonResponse
    {
        [$agencyId, $role] = $this->staffScope($request);
        $issue = $this->staffIssue($request, $agencyId, $role, $id);
        $data = $request->validate([
            'status' => 'sometimes|in:' . implode(',', array_keys(self::STATUSES)),
            'assigned_to' => 'sometimes|nullable|integer',
            'resolution_note' => 'sometimes|nullable|string|max:2000',
        ]);
        $uid = (int) $request->user()->id;
        $upd = ['updated_at' => now(), 'last_activity_at' => now()];
        $said = [];
        if (array_key_exists('assigned_to', $data)) {
            abort_if($role === 'educator', 403, 'Only a director or admin can assign a report.');
            $to = $data['assigned_to'] ? (int) $data['assigned_to'] : null;
            if ($to !== null && ! collect($this->assignable($issue))->contains('id', $to)) {
                return response()->json(['message' => 'That person cannot be assigned this report.'], 422);
            }
            if ($to !== (int) ($issue->assigned_to ?? 0) && ! ($to === null && ! $issue->assigned_to)) {
                $upd['assigned_to'] = $to;
                $said[] = $to ? 'assigned it to ' . $this->who($to) : 'unassigned it';
            }
        }
        $statusChanged = isset($data['status']) && $data['status'] !== $issue->status;
        if ($statusChanged) {
            $upd['status'] = $data['status'];
            if (in_array($data['status'], ['resolved', 'closed'], true)) {
                $upd += ['resolved_at' => now(), 'resolved_by' => $uid];
            } else {
                $upd += ['resolved_at' => null, 'resolved_by' => null];
            }
            $said[] = 'set it to ' . strtolower(self::STATUSES[$data['status']]);
        }
        $note = trim((string) ($data['resolution_note'] ?? ''));
        if ($note !== '') {
            $upd['resolution_note'] = $note;
            DB::table('parent_issue_messages')->insert(['issue_id' => $id, 'user_id' => $uid, 'author_side' => 'staff',
                'body' => $note, 'internal' => 0, 'created_at' => now()]);
        }
        if (count($upd) === 2 && $note === '') {
            return response()->json(['ok' => true, 'changed' => false]);
        }
        DB::table('parent_issues')->where('id', $id)->update($upd);
        if ($said || $note !== '') {
            $this->audit($uid, $agencyId, 'parent_issue.updated', $issue, $this->who($uid) . ' ' . implode(' and ', $said ?: ['replied to'])
                . ' parent report #' . $id . ' (' . $this->label($issue) . ').');
        }
        if (! empty($upd['assigned_to'])) {
            $this->bell([(int) $upd['assigned_to']], 'Parent report assigned to you', $this->label($issue) . ' — ' . $this->familyLabel($issue), $id, false);
        }
        if ($statusChanged || $note !== '') {
            $this->tellParent(DB::table('parent_issues')->find($id), $statusChanged ? $data['status'] : null, $note);
        }

        return response()->json(['ok' => true, 'changed' => true]);
    }

    /** POST /parent-issues/{id}/messages {body, internal?} */
    public function staffMessage(Request $request, int $id): JsonResponse
    {
        [$agencyId, $role] = $this->staffScope($request);
        $issue = $this->staffIssue($request, $agencyId, $role, $id);
        $data = $request->validate(['body' => 'required|string|min:2|max:4000', 'internal' => 'sometimes|boolean']);
        $uid = (int) $request->user()->id;
        $internal = (bool) ($data['internal'] ?? false);
        DB::table('parent_issue_messages')->insert(['issue_id' => $id, 'user_id' => $uid, 'author_side' => 'staff',
            'body' => trim($data['body']), 'internal' => $internal ? 1 : 0, 'created_at' => now()]);
        $upd = ['last_activity_at' => now(), 'updated_at' => now()];
        // The first reply to the family takes it out of "open".
        if (! $internal && $issue->status === 'open') $upd['status'] = 'in_progress';
        DB::table('parent_issues')->where('id', $id)->update($upd);
        $this->audit($uid, $agencyId, $internal ? 'parent_issue.internal_note' : 'parent_issue.replied', $issue,
            $this->who($uid) . ($internal ? ' added an internal note to' : ' replied to the family on') . ' parent report #' . $id . '.');
        if (! $internal) {
            $this->tellParent(DB::table('parent_issues')->find($id), $upd['status'] ?? null, trim($data['body']));
        }

        return response()->json(['ok' => true]);
    }

    // ── Scoping ─────────────────────────────────────────────────────────────

    /** [agencyId, 'admin'|'director'|'educator'] or 403. */
    private function staffScope(Request $request): array
    {
        $agencyId = (int) ($this->resolveAgencyId($request) ?: 0);
        abort_unless($agencyId, 403, 'Choose an agency first.');
        $roles = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)->get(['role', 'agency_id', 'centre_id']);
        if ($roles->contains(fn ($r) => $r->role === 'platform_admin' || ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId))) {
            return [$agencyId, 'admin'];
        }
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($x) => (int) $x)->all();
        if ($roles->contains(fn ($r) => $r->role === 'centre_director' && ((int) $r->agency_id === $agencyId || in_array((int) $r->centre_id, $centres, true)))) {
            return [$agencyId, 'director'];
        }
        if ($roles->contains(fn ($r) => $r->role === 'educator' && ((int) $r->agency_id === $agencyId || in_array((int) $r->centre_id, $centres, true)))) {
            return [$agencyId, 'educator'];
        }
        abort(403, 'Parent reports are for centre staff.');
    }

    private function staffCentres(Request $request, int $agencyId): array
    {
        return \App\Support\Visibility::centreIds($agencyId, $request->user());
    }

    /** Children in the educator's own rooms (open enrolments). */
    private function educatorChildIds(int $userId): array
    {
        $rooms = DB::table('educator_rooms')->where('user_id', $userId)->pluck('room_id')->all();
        if (! $rooms) return [];
        $today = now()->toDateString();

        return DB::table('enrollments')->whereIn('room_id', $rooms)
            ->where(function ($q) use ($today) { $q->whereNull('end_date')->orWhere('end_date', '>=', $today); })
            ->distinct()->pluck('child_id')->map(fn ($x) => (int) $x)->all();
    }

    private function scopedQuery(Request $request, int $agencyId, string $role)
    {
        $q = DB::table('parent_issues as i')->where('i.agency_id', $agencyId)
            ->whereIn('i.centre_id', $this->staffCentres($request, $agencyId) ?: [0]);
        if ($role === 'educator') {
            $q->whereIn('i.child_id', $this->educatorChildIds((int) $request->user()->id) ?: [0])
              ->whereNotIn('i.category', self::SENSITIVE);
        }

        return $q;
    }

    private function staffIssue(Request $request, int $agencyId, string $role, int $id): object
    {
        $issue = $this->scopedQuery($request, $agencyId, $role)->where('i.id', $id)->first(['i.*']);
        abort_unless($issue, 404);

        return $issue;
    }

    /** Directors of the report's centre and the agency's admins. */
    private function assignable(object $issue): array
    {
        return DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->whereNull('u.deleted_at')
            ->where(function ($q) use ($issue) {
                $q->where(fn ($x) => $x->where('ra.role', 'agency_admin')->where('ra.agency_id', $issue->agency_id))
                  ->orWhere(fn ($x) => $x->where('ra.role', 'centre_director')->where('ra.centre_id', $issue->centre_id));
                if ($issue->child_id && ! in_array($issue->category, self::SENSITIVE, true)) {
                    $rooms = DB::table('enrollments')->where('child_id', $issue->child_id)
                        ->where(fn ($e) => $e->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()))->pluck('room_id')->all();
                    if ($rooms) {
                        $q->orWhereIn('u.id', DB::table('educator_rooms')->whereIn('room_id', $rooms)->pluck('user_id')->all() ?: [0]);
                    }
                }
            })
            ->distinct()->orderBy('u.first_name')->get(['u.id', 'u.first_name', 'u.last_name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name)])->values()->all();
    }

    private function parentFamilies(int $uid)
    {
        return DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->where('g.user_id', $uid)->whereNull('f.deleted_at')
            ->get(['g.family_id', 'g.billing_hidden', 'f.family_name']);
    }

    // ── Notifications ───────────────────────────────────────────────────────

    /** Directors of the centre + agency admins (email too); room educators (bell + push) unless sensitive. */
    private function staffRecipients(object $issue): array
    {
        $lead = DB::table('role_assignments')->where('active', 1)
            ->where(function ($q) use ($issue) {
                $q->where(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $issue->agency_id))
                  ->orWhere(fn ($x) => $x->where('role', 'centre_director')->where('centre_id', $issue->centre_id));
            })->pluck('user_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
        $edu = [];
        if ($issue->child_id && ! in_array($issue->category, self::SENSITIVE, true)) {
            $rooms = DB::table('enrollments')->where('child_id', $issue->child_id)
                ->where(fn ($e) => $e->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()))->pluck('room_id')->all();
            if ($rooms) {
                $edu = DB::table('educator_rooms as er')->join('users as u', 'u.id', '=', 'er.user_id')
                    ->whereIn('er.room_id', $rooms)->whereNull('u.deleted_at')->where('u.status', 'active')
                    ->pluck('u.id')->map(fn ($x) => (int) $x)->unique()->values()->all();
            }
        }
        $lead = array_values(array_diff($lead, [(int) $issue->reported_by]));
        $edu = array_values(array_diff($edu, $lead, [(int) $issue->reported_by]));

        return [$lead, $edu];
    }

    private function tellStaffNew(object $issue): void
    {
        try {
            [$lead, $edu] = $this->staffRecipients($issue);
            $reporter = $this->who((int) $issue->reported_by);
            $title = (in_array($issue->category, self::SENSITIVE, true) ? '⚠️ ' : '') . 'Parent report: ' . $this->label($issue);
            $body = $reporter . ' (' . $this->familyLabel($issue) . '): ' . mb_substr($issue->notes, 0, 180);
            $this->bell(array_merge($lead, $edu), $title, $body, (int) $issue->id, true);

            $to = DB::table('users')->whereIn('id', $lead ?: [0])->whereNull('deleted_at')->pluck('email')
                ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
            if (! $to) return;
            $tz = AgencyTime::tz((int) $issue->agency_id);
            $rows = [
                'From' => $reporter, 'Family' => $this->familyLabel($issue),
                'About' => $issue->child_id ? $this->childName((int) $issue->child_id) : 'The family in general',
                'Centre' => (string) DB::table('centres')->where('id', $issue->centre_id)->value('name'),
                'Category' => self::CATEGORIES[$issue->category][0] ?? $issue->category,
                'Reason' => $issue->subcategory ?: '—',
                'Reported' => $this->fmt($issue->created_at, $tz),
            ];
            $html = '<p style="margin:0 0 14px;">A parent has reported an issue to the centre.</p>'
                . '<table role="presentation" style="border-collapse:collapse;font-size:14px;margin:0 0 14px;">';
            foreach ($rows as $k => $v) {
                $html .= '<tr><td style="padding:6px 12px 6px 0;color:#64748B;white-space:nowrap;vertical-align:top;">' . e($k) . '</td>'
                    . '<td style="padding:6px 0;color:#0F172A;font-weight:600;">' . e((string) $v) . '</td></tr>';
            }
            $html .= '</table><div style="background:#F8FAFC;border-left:3px solid #1F6080;padding:10px 14px;margin:0 0 14px;white-space:pre-wrap;font-size:14px;">'
                . e($issue->notes) . '</div>'
                . '<p style="margin:0;font-size:13px;color:#475569;">Reply to the family, assign it or close it in KiddieTrac → Parent issues.'
                . (in_array($issue->category, self::SENSITIVE, true) ? ' This is a concern about a staff member, so it has gone to directors and admins only.' : '') . '</p>';
            $this->mail((int) $issue->agency_id, $to, $title, $html, 'Parent issues');
        } catch (\Throwable $e) {
            Log::warning('parent issue: staff notify failed', ['issue' => $issue->id, 'error' => $e->getMessage()]);
        }
    }

    private function tellStaffFollowUp(object $issue, bool $reopened): void
    {
        try {
            [$lead, $edu] = $this->staffRecipients($issue);
            $ids = $issue->assigned_to ? array_values(array_unique([(int) $issue->assigned_to, ...$lead])) : array_merge($lead, $edu);
            $this->bell($ids, ($reopened ? 'Parent report reopened: ' : 'Parent replied: ') . $this->label($issue),
                $this->who((int) $issue->reported_by) . ' added to their report about ' . ($issue->child_id ? $this->childName((int) $issue->child_id) : 'their family') . '.',
                (int) $issue->id, true);
        } catch (\Throwable $e) {
            Log::warning('parent issue: follow-up notify failed', ['issue' => $issue->id, 'error' => $e->getMessage()]);
        }
    }

    /** The reporting parent: bell + push + email. */
    private function tellParent(object $issue, ?string $newStatus, string $reply): void
    {
        try {
            $uid = (int) $issue->reported_by;
            $centre = (string) DB::table('centres')->where('id', $issue->centre_id)->value('name') ?: 'The centre';
            $title = $newStatus ? 'Your report is ' . strtolower(self::STATUSES[$newStatus]) : $centre . ' replied to your report';
            $body = $this->label($issue) . ($reply !== '' ? ' — ' . mb_substr($reply, 0, 160) : '');
            Notify::write(['user_id' => $uid, 'type' => 'parent_issue', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => '#report-issue', 'issue_id' => (int) $issue->id]), 'created_at' => now()]);
            try { app(FcmService::class)->sendToUser($uid, $title, $body, '#report-issue'); } catch (\Throwable $e) {}

            $u = DB::table('users')->where('id', $uid)->whereNull('deleted_at')->first(['email', 'first_name']);
            if (! $u || ! filter_var($u->email, FILTER_VALIDATE_EMAIL)) return;
            $html = '<p style="margin:0 0 14px;">Hi ' . e($u->first_name ?: 'there') . ',</p>'
                . '<p style="margin:0 0 14px;">' . e($centre) . ' has '
                . ($reply !== '' ? 'replied to' : 'updated') . ' the issue you reported: <strong>' . e($this->label($issue)) . '</strong>.</p>'
                . ($newStatus ? '<p style="margin:0 0 14px;">Status: <strong>' . e(self::STATUSES[$newStatus]) . '</strong></p>' : '')
                . ($reply !== '' ? '<div style="background:#F8FAFC;border-left:3px solid #1F6080;padding:10px 14px;margin:0 0 14px;white-space:pre-wrap;font-size:14px;">' . e($reply) . '</div>' : '')
                . '<p style="margin:0;font-size:13px;color:#475569;">Open KiddieTrac → Report an issue to read the whole conversation or add more.</p>';
            $this->mail((int) $issue->agency_id, [(string) $u->email], $title, $html, 'Your report');
        } catch (\Throwable $e) {
            Log::warning('parent issue: parent notify failed', ['issue' => $issue->id, 'error' => $e->getMessage()]);
        }
    }

    private function bell(array $ids, string $title, string $body, int $issueId, bool $push): void
    {
        foreach (array_values(array_unique(array_filter($ids))) as $uid) {
            try {
                Notify::write(['user_id' => (int) $uid, 'type' => 'parent_issue', 'title' => mb_substr($title, 0, 190), 'body' => mb_substr($body, 0, 500),
                    'data' => json_encode(['link' => '#parent-issues?id=' . $issueId, 'issue_id' => $issueId]), 'created_at' => now()]);
                if ($push) app(FcmService::class)->sendToUser((int) $uid, $title, $body, '#parent-issues?id=' . $issueId);
            } catch (\Throwable $e) {
                Log::warning('parent issue: bell failed', ['user' => $uid, 'error' => $e->getMessage()]);
            }
        }
    }

    private function mail(int $agencyId, array $to, string $title, string $bodyHtml, string $eyebrow): void
    {
        $html = \App\Services\EmailTemplate::wrap($agencyId, $bodyHtml, ['eyebrow' => strtoupper($eyebrow), 'title' => $title, 'preheader' => $title]);
        dispatch(function () use ($agencyId, $to, $title, $html) {
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $title, $agencyId) {
                $m->to($to[0])->subject($title);
                if (count($to) > 1) $m->bcc(array_slice($to, 1));
                try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId); } catch (\Throwable $e) {}
            });
        })->onQueue('mail');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function categoryList(array $except = []): array
    {
        $out = [];
        foreach (self::CATEGORIES as $k => [$label, $reasons]) {
            if (in_array($k, $except, true)) continue;
            $out[] = ['key' => $k, 'label' => $label, 'reasons' => $reasons];
        }

        return $out;
    }

    private function shape(object $r, string $tz, bool $staff = false): array
    {
        $out = [
            'id' => (int) $r->id,
            'category' => $r->category,
            'category_label' => self::CATEGORIES[$r->category][0] ?? $r->category,
            'subcategory' => $r->subcategory,
            'notes' => $r->notes,
            'status' => $r->status,
            'status_label' => self::STATUSES[$r->status] ?? $r->status,
            'child' => $r->child_id ? $this->childName((int) $r->child_id) : null,
            'created' => $this->fmt($r->created_at, $tz),
            'updated' => $this->fmt($r->last_activity_at ?? $r->updated_at, $tz),
            'resolution_note' => $r->resolution_note,
        ];
        if ($staff) {
            $out += [
                'family' => (string) DB::table('families')->where('id', $r->family_id)->value('family_name'),
                'reporter' => $this->who((int) $r->reported_by),
                'centre' => (string) DB::table('centres')->where('id', $r->centre_id)->value('name'),
                'assigned_to' => $r->assigned_to ? (int) $r->assigned_to : null,
                'assigned_name' => $r->assigned_to ? $this->who((int) $r->assigned_to) : null,
                'sensitive' => in_array($r->category, self::SENSITIVE, true),
                'replies' => (int) DB::table('parent_issue_messages')->where('issue_id', $r->id)->count(),
            ];
        }

        return $out;
    }

    private function fmt($ts, string $tz): ?string
    {
        return $ts ? Carbon::parse($ts)->timezone($tz)->format('M j, Y g:i A') : null;
    }

    private function label(object $issue): string
    {
        return (self::CATEGORIES[$issue->category][0] ?? $issue->category) . ($issue->subcategory ? ' · ' . $issue->subcategory : '');
    }

    private function who(int $uid): string
    {
        $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);

        return $u ? (trim($u->first_name . ' ' . $u->last_name) ?: 'Someone') : 'Someone';
    }

    private function childName(int $id): string
    {
        $c = DB::table('children')->where('id', $id)->first(['first_name', 'preferred_name', 'last_name']);

        return $c ? trim(($c->preferred_name ?: $c->first_name) . ' ' . $c->last_name) : 'a child';
    }

    private function familyLabel(object $issue): string
    {
        $n = (string) DB::table('families')->where('id', $issue->family_id)->value('family_name');

        return $n !== '' ? $n . (str_contains(strtolower($n), 'family') ? '' : ' family') : 'family #' . $issue->family_id;
    }

    private function audit(int $by, int $agencyId, string $action, object $issue, string $summary): void
    {
        try {
            Audit::write([
                'user_id' => $by, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => 'parent_issue', 'entity_id' => (int) $issue->id,
                'payload' => json_encode(['summary' => $summary, 'family_id' => (int) $issue->family_id,
                    'child_id' => $issue->child_id ? (int) $issue->child_id : null, 'category' => $issue->category]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
