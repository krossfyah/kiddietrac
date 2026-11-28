<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p63 — Multi-step document workflow.
 * Creator picks ordered list of signers (by role + optional explicit user).
 * Each signer signs in turn; next signer gets a notification when prior
 * step completes. When the last step is done, workflow becomes 'complete'.
 */
final class DocumentWorkflowController extends Controller
{
    /* The trait was never used here (2026-11-05 leak sweep): show() and sign() called
       isPlatformAdminUser()/userBelongsToAgency() from it, so both answered 500 on every id. */
    use ResolvesCentreContext;

    private const MANAGER_ROLES = ['agency_admin', 'centre_director', 'platform_admin'];

    /** Admin / director (or a platform admin) in the ACTIVE agency. */
    private function isManager(Request $request): bool
    {
        return (bool) array_intersect(\App\Support\AgencyRole::names($request), self::MANAGER_ROLES);
    }

    /** May this person sign THIS step: the named signer, or (unnamed step) holder of its role in the active agency. */
    private function maySignStep(Request $request, object $w, object $step): bool
    {
        $uid = (int) $request->user()->id;
        if ($step->signer_user_id) {
            return (int) $step->signer_user_id === $uid && $this->userBelongsToAgency($uid, (int) $w->agency_id);
        }
        $agencyId = (int) ($this->resolveAgencyId($request) ?: 0);
        // An unnamed PARENT step belongs to the family the document is about, not to every parent of the agency
        // (2026-11-27, person boundary - reader and guard move together with stepSigners()).
        if ((string) $step->signer_role === 'guardian') {
            return $agencyId > 0 && (int) $w->agency_id === $agencyId && in_array($uid, $this->stepSigners($w, $step), true);
        }
        return $agencyId > 0 && (int) $w->agency_id === $agencyId
            && in_array((string) $step->signer_role, \App\Support\AgencyRole::names($request), true);
    }

    /* WHO IS TOLD IT IS THEIR TURN (2026-11-27). A step with no named signer told nobody, so the workflow stalled
       until someone happened to look. Now: the named signer; for an unnamed STAFF step, everyone who may sign it
       (holders of that role in the workflow's agency, never a platform outsider); for an unnamed PARENT step, the
       parents of the family the document is about (related family/child), never every parent in the agency. */
    private function stepSigners(object $w, object $step): array
    {
        if ($step->signer_user_id) return [(int) $step->signer_user_id];
        $agencyId = (int) $w->agency_id;
        if ($step->signer_role === 'guardian') {
            $familyId = 0;
            if ($w->related_table === 'families') $familyId = (int) $w->related_id;
            if ($w->related_table === 'children') $familyId = (int) DB::table('children')->where('id', (int) $w->related_id)->value('family_id');
            if (! $familyId || (int) DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('f.id', $familyId)->value('c.agency_id') !== $agencyId) return [];
            return DB::table('guardians')->where('family_id', $familyId)->whereNotNull('user_id')
                ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        }
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        return \App\Support\AgencyOutsiders::exclude(DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id'))
            ->whereNull('u.deleted_at')->where('ra.active', 1)->where('ra.role', $step->signer_role)
            ->where(fn ($q) => $q->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centreIds ?: [0]))
            ->pluck('u.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function tellSigners(object $w, object $step, string $title, string $body, int $exceptUserId = 0): void
    {
        foreach ($this->stepSigners($w, $step) as $uid) {
            if ($uid === $exceptUserId) continue;
            \App\Support\Notify::write([
                'user_id' => $uid, 'type' => 'document_workflow',
                'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => '#doc-workflows', 'workflow_id' => (int) $w->id]),
                'created_at' => now(),
            ]);
        }
    }

    public function listMine(Request $request): JsonResponse
    {
        $u = $request->user();
        $isStaff = $this->isManager($request);
        $q = DB::table('document_workflows')->orderByDesc('created_at');
        if ($isStaff) {
            /* The active agency, membership-checked (2026-11-05 leak sweep): the raw header, or the
               first agency of ANY role, picked the list's agency; a platform admin with no agency gets none. */
            $agencyId = (int) ($this->resolveAgencyId($request) ?: 0);
            $q->where('agency_id', $agencyId);
        } else {
            // For parents: workflows where they are a signer
            $workflowIds = DB::table('document_workflow_steps')->where('signer_user_id', $u->id)->pluck('workflow_id');
            /* An educator is now told about an unnamed educator step (tellSigners), so the list shows the ones in
               the active agency waiting on a role they hold; show()/sign() already let them in. Staff roles only:
               an unnamed parent step is reached through its own family's notice, never listed to every parent. */
            $staffRoles = array_values(array_intersect(\App\Support\AgencyRole::names($request), ['educator', 'centre_director', 'agency_admin']));
            $agencyId = (int) ($this->resolveAgencyId($request) ?: 0);
            if ($staffRoles && $agencyId) {
                $workflowIds = $workflowIds->merge(DB::table('document_workflow_steps as s')
                    ->join('document_workflows as w', 'w.id', '=', 's.workflow_id')
                    ->where('w.agency_id', $agencyId)->whereNull('s.signer_user_id')
                    ->whereIn('s.signer_role', $staffRoles)->pluck('s.workflow_id'));
            }
            $q->whereIn('id', $workflowIds->unique()->values());
        }
        $rows = $q->limit(100)->get();
        return response()->json(['data' => $rows]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => 'required|string|max:60',
            'title' => 'required|string|max:200',
            'document_text' => 'required|string|max:50000',
            'related_table' => 'nullable|string|max:40',
            'related_id' => 'nullable|integer',
            'steps' => 'required|array|min:1|max:6',
            'steps.*.signer_role' => 'required|string|in:guardian,educator,centre_director,agency_admin',
            'steps.*.signer_user_id' => 'nullable|integer',
            'steps.*.signer_label' => 'required|string|max:120',
        ]);
        $u = $request->user();
        /* Only an admin/director starts a workflow, in the active agency, naming signers from that
           agency (2026-11-05 leak sweep): the route has no role gate, so any signed-in person (a parent
           too) could start one and push "awaiting your signature" to any user id in any agency. */
        abort_unless($this->isManager($request), 403);
        $agencyId = (int) ($this->resolveAgencyId($request) ?: 0);
        abort_unless($agencyId > 0, 422, 'Pick an agency before starting a document workflow.');
        foreach ($data['steps'] as $s) {
            if (! empty($s['signer_user_id']) && ! $this->userBelongsToAgency((int) $s['signer_user_id'], $agencyId)) {
                return response()->json(['message' => 'One of the signers is not part of this agency.'], 422);
            }
        }

        $workflowId = DB::table('document_workflows')->insertGetId([
            'agency_id' => $agencyId,
            'document_type' => $data['document_type'],
            'title' => $data['title'],
            'document_text' => $data['document_text'],
            'status' => 'in_progress',
            'current_step' => 1,
            'started_by_user_id' => $u->id,
            'related_table' => $data['related_table'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($data['steps'] as $i => $s) {
            DB::table('document_workflow_steps')->insert([
                'workflow_id' => $workflowId,
                'step_order' => $i + 1,
                'signer_role' => $s['signer_role'],
                'signer_user_id' => $s['signer_user_id'] ?? null,
                'signer_label' => $s['signer_label'],
                'status' => $i === 0 ? 'awaiting' : 'pending',
                'created_at' => now(),
            ]);
        }
        // Tell whoever signs the first step (named, or everyone who may sign it - see stepSigners).
        $w = DB::table('document_workflows')->where('id', $workflowId)->first();
        $first = DB::table('document_workflow_steps')->where('workflow_id', $workflowId)->where('step_order', 1)->first();
        if ($w && $first) {
            $this->tellSigners($w, $first, 'Awaiting your signature: ' . $data['title'], 'Please review and sign.', (int) $u->id);
        }
        return response()->json(['id' => $workflowId], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $w = DB::table('document_workflows')->where('id', $id)->first();
        abort_unless($w, 404);
        /* Admins/directors of the workflow's agency (the ACTIVE one, so a platform admin only in the agency
           they switched into), or a participant: the starter, a named signer, or a holder of an unnamed
           step's role there (2026-11-05 leak sweep). It was "anyone with any role in that agency", so every
           parent and educator of the agency could read every workflow's document text; platform was global. */
        $uid = (int) $request->user()->id;
        $agencyId = (int) ($this->resolveAgencyId($request) ?: 0);
        $inActive = $agencyId > 0 && (int) $w->agency_id === $agencyId;
        $named = (int) $w->started_by_user_id === $uid
            || DB::table('document_workflow_steps')->where('workflow_id', $id)->where('signer_user_id', $uid)->exists();
        $staffNames = array_values(array_diff(\App\Support\AgencyRole::names($request), ['guardian']));
        $roleStep = $inActive && DB::table('document_workflow_steps')->where('workflow_id', $id)
            ->whereNull('signer_user_id')->whereIn('signer_role', $staffNames ?: ['-'])->exists();
        // A parent reads an unnamed parent step's document only when it is about their own family (stepSigners).
        if (! $roleStep && $inActive) {
            foreach (DB::table('document_workflow_steps')->where('workflow_id', $id)->whereNull('signer_user_id')
                         ->where('signer_role', 'guardian')->get() as $gs) {
                if (in_array($uid, $this->stepSigners($w, $gs), true)) { $roleStep = true; break; }
            }
        }
        abort_unless(($inActive && $this->isManager($request)) || $roleStep
            || ($named && $this->userBelongsToAgency($uid, (int) $w->agency_id)), 403);
        $steps = DB::table('document_workflow_steps as s')
            ->leftJoin('users as u', 'u.id', '=', 's.signer_user_id')
            ->where('s.workflow_id', $id)
            ->orderBy('s.step_order')
            ->select('s.*', DB::raw("CONCAT(u.first_name,' ',u.last_name) as signer_name"))
            ->get()
            // Signature stamp (2026-11-27): signed date + time, IP and approximate place under each signer.
            ->map(function ($s) use ($w) {
                $s->stamp = $s->signed_at
                    ? (\App\Support\SignatureStamp::lines($s->signed_at, $s->signer_ip ?? null, $s->signer_location ?? null, (int) $w->agency_id) ?: null)
                    : null;
                return $s;
            });
        return response()->json(['workflow' => $w, 'steps' => $steps]);
    }

    public function sign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'step_order' => 'required|integer',
            'signature_data' => 'required|string',
            'notes' => 'nullable|string|max:1000',
        ]);
        $u = $request->user();
        $workflow = DB::table('document_workflows')->where('id', $id)->first();
        abort_unless($workflow, 404);
        abort_unless($workflow->status === 'in_progress', 422, 'Workflow already complete');
        abort_unless($workflow->current_step === $data['step_order'], 422, 'Not the current step');

        $step = DB::table('document_workflow_steps')->where('workflow_id', $id)
            ->where('step_order', $data['step_order'])->first();
        abort_unless($step, 404);
        /* Only this step's signer signs it (2026-11-05 leak sweep): the named person (still in the
           workflow's agency), or for an unnamed step someone holding its signer_role in that agency.
           An unnamed step could be signed by anyone with any role there (a parent signing as director),
           and a platform admin could sign any agency's. */
        abort_unless($this->maySignStep($request, $workflow, $step), 403, 'Not the assigned signer');

        $hash = hash('sha256', $workflow->document_text . '|' . $u->id . '|' . $step->id . '|' . microtime(true));
        // Signature stamp (2026-11-27): IP + approximate place, read on the server at signing time.
        $stamp = \App\Support\SignatureStamp::capture($request);
        DB::table('document_workflow_steps')->where('id', $step->id)->update([
            'signer_user_id' => $u->id,
            'signed_at' => now(),
            'signature_data' => $data['signature_data'],
            'signature_hash' => $hash,
            'signer_ip' => $stamp['ip'] ?? $request->ip(),
            'signer_location' => $stamp['location'],
            'status' => 'signed',
            'notes' => $data['notes'] ?? null,
        ]);

        // Advance workflow
        $nextOrder = $step->step_order + 1;
        $nextStep = DB::table('document_workflow_steps')->where('workflow_id', $id)
            ->where('step_order', $nextOrder)->first();
        if ($nextStep) {
            DB::table('document_workflows')->where('id', $id)->update([
                'current_step' => $nextOrder, 'updated_at' => now(),
            ]);
            DB::table('document_workflow_steps')->where('id', $nextStep->id)
                ->update(['status' => 'awaiting']);
            $this->tellSigners($workflow, $nextStep, 'Your turn to sign: ' . $workflow->title, 'Previous step complete.', (int) $u->id);
        } else {
            DB::table('document_workflows')->where('id', $id)->update([
                'status' => 'complete', 'completed_at' => now(), 'updated_at' => now(),
            ]);
            // Notify the originator
            \App\Support\Notify::write([
                'user_id' => $workflow->started_by_user_id, 'type' => 'document_workflow',
                'title' => 'Document fully signed: ' . $workflow->title,
                'body' => 'All signers have completed.',
                'data' => json_encode(['link' => '#doc-workflows', 'workflow_id' => $id]),
                'created_at' => now(),
            ]);
            // And everyone gets the signed copy (2026-11-06): the bell alone handed nobody the document.
            \App\Jobs\SendCompletedWorkflow::dispatch((int) $id)->onQueue('mail');
        }

        return response()->json(['status' => 'signed', 'workflow_status' => $nextStep ? 'in_progress' : 'complete']);
    }
}
