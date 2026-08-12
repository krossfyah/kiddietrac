<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Staff tasks. Agency admins and centre directors assign tasks to educators;
 * the educator sees their open tasks and marks them in-progress / done.
 *
 * Scope: agency-wide for agency_admin / platform_admin; limited to the
 * director's own centre(s) for centre_director.
 */
class TaskController extends Controller
{
    /** Active agency for this request (header first, then the user's role assignment). */
    private function agencyId(Request $request): ?int
    {
        $active = (int) $request->header('X-Active-Agency-Id');
        if ($active && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($active) { $q->where('role', 'platform_admin')->orWhere('agency_id', $active); })->exists()) {
            return $active;
        }
        return (int) (DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)->whereNotNull('agency_id')->value('agency_id')) ?: null;
    }

    private function hasRole(Request $request, array $roles): bool
    {
        return DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)->whereIn('role', $roles)->exists();
    }

    private function isReviewer(Request $request): bool
    {
        return $this->hasRole($request, ['agency_admin', 'centre_director', 'platform_admin']);
    }

    /** Centre ids a centre_director is assigned to (in the active agency). Null = no restriction. */
    private function directorCentreIds(Request $request, ?int $agencyId): ?array
    {
        // Agency admins and platform admins see the whole agency — no centre restriction.
        if ($this->hasRole($request, ['agency_admin', 'platform_admin'])) {
            return null;
        }
        $ids = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', true)
            ->where('role', 'centre_director')
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
        return $ids; // [] means "no centres" → sees nothing
    }

    private function name($u): string
    {
        if (! $u) return 'Unknown';
        $n = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        return $n !== '' ? $n : ($u->email ?? 'Unknown');
    }

    // ── Educator: my tasks ───────────────────────────────────────────────
    /** GET /tasks/mine — tasks assigned to the current user, open first. */
    public function mine(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $rows = DB::table('tasks')
            ->leftJoin('users as ab', 'ab.id', '=', 'tasks.assigned_by')
            ->leftJoin('centres as c', 'c.id', '=', 'tasks.centre_id')
            ->whereNull('tasks.deleted_at')
            ->where('tasks.assigned_to', $uid)
            ->orderByRaw("FIELD(tasks.status,'open','in_progress','done')")
            ->orderByRaw('tasks.due_date IS NULL, tasks.due_date ASC')
            ->orderByDesc('tasks.created_at')
            ->get([
                'tasks.*',
                'ab.first_name as ab_first', 'ab.last_name as ab_last', 'ab.email as ab_email',
                'c.name as centre_name',
            ]);

        $tasks = $rows->map(fn ($r) => $this->shape($r))->all();
        $open = $rows->whereIn('status', ['open', 'in_progress'])->count();

        return response()->json(['tasks' => $tasks, 'open_count' => $open]);
    }

    /** PATCH /tasks/{id}/status — assignee (or a reviewer) changes the status. */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $row = DB::table('tasks')->whereNull('deleted_at')->where('id', $id)->first();
        abort_unless($row, 404, 'Task not found.');

        $uid = (int) $request->user()->id;
        abort_unless((int) $row->assigned_to === $uid || $this->isReviewer($request), 403, 'This task is not assigned to you.');

        $data = $request->validate(['status' => 'required|in:open,in_progress,done']);
        DB::table('tasks')->where('id', $id)->update([
            'status'       => $data['status'],
            'completed_at' => $data['status'] === 'done' ? now() : null,
            'updated_at'   => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    // ── Admin / director: manage tasks ───────────────────────────────────
    /** Roles a reviewer can assign tasks to. */
    private const ASSIGNABLE = ['educator', 'home_visitor', 'guardian'];

    /** GET /tasks/assignees — educators, home visitors and parents this reviewer may assign to. */
    public function assignees(Request $request): JsonResponse
    {
        abort_unless($this->isReviewer($request), 403);
        $agencyId = $this->agencyId($request);
        abort_unless($agencyId, 422, 'No agency for this account.');
        $centreIds = $this->directorCentreIds($request, $agencyId);

        $rows = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->whereIn('ra.role', self::ASSIGNABLE)->where('ra.active', true)
            ->where('ra.agency_id', $agencyId)
            ->when(is_array($centreIds), function ($q) use ($centreIds) {
                // Directors: educators are limited to their centre(s); home visitors
                // and parents are agency-level (no centre) so are always included.
                $q->where(function ($w) use ($centreIds) {
                    $w->whereIn('ra.centre_id', $centreIds ?: [-1])
                      ->orWhereIn('ra.role', ['home_visitor', 'guardian']);
                });
            })
            ->orderByRaw("FIELD(ra.role,'educator','home_visitor','guardian')")
            ->orderBy('u.first_name')->orderBy('u.last_name')
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'ra.role', 'ra.centre_id', 'c.name as centre_name'])
            ->unique('id')->values();

        $labels = ['educator' => 'Educator', 'home_visitor' => 'Home visitor', 'guardian' => 'Parent'];
        $out = $rows->map(fn ($r) => [
            'id'          => (int) $r->id,
            'name'        => $this->name($r),
            'role'        => $r->role,
            'role_label'  => $labels[$r->role] ?? ucfirst((string) $r->role),
            'centre_id'   => $r->centre_id ? (int) $r->centre_id : null,
            'centre_name' => $r->centre_name,
        ])->all();

        return response()->json(['assignees' => $out]);
    }

    /** GET /tasks — every task in scope (agency, or the director's centres). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->isReviewer($request), 403);
        $agencyId = $this->agencyId($request);
        $isPlatform = $this->hasRole($request, ['platform_admin']);
        $allMode = $isPlatform && strtolower(trim((string) $request->header('X-Active-Agency-Id'))) === 'all';
        $centreIds = $this->directorCentreIds($request, $agencyId);

        if (! $allMode) {
            abort_unless($agencyId, 422, 'No agency for this account.');
        }

        $rows = DB::table('tasks')
            ->leftJoin('users as at', 'at.id', '=', 'tasks.assigned_to')
            ->leftJoin('users as ab', 'ab.id', '=', 'tasks.assigned_by')
            ->leftJoin('centres as c', 'c.id', '=', 'tasks.centre_id')
            ->whereNull('tasks.deleted_at')
            ->when(! $allMode && $agencyId, fn ($q) => $q->where('tasks.agency_id', $agencyId))
            ->when(is_array($centreIds), fn ($q) => $q->whereIn('tasks.centre_id', $centreIds ?: [-1]))
            ->orderByRaw("FIELD(tasks.status,'open','in_progress','done')")
            ->orderByRaw('tasks.due_date IS NULL, tasks.due_date ASC')
            ->orderByDesc('tasks.created_at')
            ->get([
                'tasks.*',
                'at.first_name as at_first', 'at.last_name as at_last', 'at.email as at_email',
                'ab.first_name as ab_first', 'ab.last_name as ab_last', 'ab.email as ab_email',
                'c.name as centre_name',
            ]);

        $tasks = $rows->map(function ($r) {
            $t = $this->shape($r);
            $t['assigned_to_name'] = $this->name((object) ['first_name' => $r->at_first, 'last_name' => $r->at_last, 'email' => $r->at_email]);
            return $t;
        })->all();

        return response()->json([
            'tasks'      => $tasks,
            'is_reviewer'=> true,
            'counts'     => [
                'open'        => $rows->where('status', 'open')->count(),
                'in_progress' => $rows->where('status', 'in_progress')->count(),
                'done'        => $rows->where('status', 'done')->count(),
            ],
        ]);
    }

    /** POST /tasks — create/assign a task to an educator. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->isReviewer($request), 403);
        $agencyId = $this->agencyId($request);
        abort_unless($agencyId, 422, 'Pick an agency before assigning tasks.');

        $data = $request->validate([
            'assigned_to' => 'required|integer',
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string|max:4000',
            'priority'    => 'nullable|in:low,normal,high',
            'due_date'    => 'nullable|date',
        ]);

        // The assignee must be an educator / home visitor / parent in this agency
        // (and, for a director, an educator in their centre).
        $centreIds = $this->directorCentreIds($request, $agencyId);
        $assignee = DB::table('role_assignments')
            ->whereIn('role', self::ASSIGNABLE)->where('active', true)
            ->where('agency_id', $agencyId)->where('user_id', $data['assigned_to'])
            ->when(is_array($centreIds), function ($q) use ($centreIds) {
                $q->where(function ($w) use ($centreIds) {
                    $w->whereIn('centre_id', $centreIds ?: [-1])->orWhereIn('role', ['home_visitor', 'guardian']);
                });
            })
            ->first();
        abort_unless($assignee, 422, 'That person is not assignable in your agency/centre.');

        $id = DB::table('tasks')->insertGetId([
            'agency_id'   => $agencyId,
            'centre_id'   => $assignee->centre_id,
            'assigned_to' => (int) $data['assigned_to'],
            'assigned_by' => (int) $request->user()->id,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'priority'    => $data['priority'] ?? 'normal',
            'due_date'    => $data['due_date'] ?? null,
            'status'      => 'open',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** PATCH /tasks/{id} — reviewer edits a task. */
    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless($this->isReviewer($request), 403);
        $row = DB::table('tasks')->whereNull('deleted_at')->where('id', $id)->first();
        abort_unless($row, 404, 'Task not found.');
        $this->assertSameScope($request, $row);

        $data = $request->validate([
            'title'       => 'sometimes|string|max:200',
            'description' => 'nullable|string|max:4000',
            'priority'    => 'sometimes|in:low,normal,high',
            'due_date'    => 'nullable|date',
            'status'      => 'sometimes|in:open,in_progress,done',
            'assigned_to' => 'sometimes|integer',
        ]);

        $update = [];
        foreach (['title', 'description', 'priority', 'due_date', 'assigned_to'] as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }
        if (array_key_exists('status', $data)) {
            $update['status'] = $data['status'];
            $update['completed_at'] = $data['status'] === 'done' ? now() : null;
        }
        if (array_key_exists('assigned_to', $update)) {
            $ra = DB::table('role_assignments')->whereIn('role', self::ASSIGNABLE)->where('active', true)
                ->where('agency_id', $row->agency_id)->where('user_id', $update['assigned_to'])->first();
            abort_unless($ra, 422, 'That person is not assignable in this agency.');
            $update['centre_id'] = $ra->centre_id;
        }
        $update['updated_at'] = now();
        DB::table('tasks')->where('id', $id)->update($update);

        return response()->json(['ok' => true]);
    }

    /** DELETE /tasks/{id} — reviewer soft-deletes a task. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless($this->isReviewer($request), 403);
        $row = DB::table('tasks')->whereNull('deleted_at')->where('id', $id)->first();
        abort_unless($row, 404, 'Task not found.');
        $this->assertSameScope($request, $row);
        DB::table('tasks')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    private function assertSameScope(Request $request, $row): void
    {
        if ($this->hasRole($request, ['platform_admin'])) return;
        abort_unless((int) $row->agency_id === (int) $this->agencyId($request), 403);
        $centreIds = $this->directorCentreIds($request, (int) $row->agency_id);
        if (is_array($centreIds)) {
            abort_unless(in_array((int) $row->centre_id, $centreIds, true), 403);
        }
    }

    private function shape($r): array
    {
        return [
            'id'              => (int) $r->id,
            'title'           => $r->title,
            'description'     => $r->description,
            'priority'        => $r->priority,
            'due_date'        => $r->due_date,
            'status'          => $r->status,
            'completed_at'    => $r->completed_at,
            'centre_id'       => $r->centre_id ? (int) $r->centre_id : null,
            'centre_name'     => $r->centre_name ?? null,
            'assigned_by_name'=> $this->name((object) ['first_name' => $r->ab_first ?? null, 'last_name' => $r->ab_last ?? null, 'email' => $r->ab_email ?? null]),
            'created_at'      => $r->created_at,
        ];
    }
}
