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
        return $agencyId > 0 && (int) $w->agency_id === $agencyId
            && in_array((string) $step->signer_role, \App\Support\AgencyRole::names($request), true);
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
            $q->whereIn('id', $workflowIds);
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
            // Notify first-step signer immediately
            if ($i === 0 && !empty($s['signer_user_id'])) {
                \App\Support\Notify::write([
                    'user_id' => $s['signer_user_id'], 'type' => 'document_workflow',
                    'title' => 'Awaiting your signature: ' . $data['title'],
                    'body' => 'Please review and sign.',
                    'data' => json_encode(['link' => '#doc-workflows', 'workflow_id' => $workflowId]),
                    'created_at' => now(),
                ]);
            }
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
        $roleStep = $inActive && DB::table('document_workflow_steps')->where('workflow_id', $id)
            ->whereNull('signer_user_id')->whereIn('signer_role', \App\Support\AgencyRole::names($request))->exists();
        abort_unless(($inActive && $this->isManager($request)) || $roleStep
            || ($named && $this->userBelongsToAgency($uid, (int) $w->agency_id)), 403);
        $steps = DB::table('document_workflow_steps as s')
            ->leftJoin('users as u', 'u.id', '=', 's.signer_user_id')
            ->where('s.workflow_id', $id)
            ->orderBy('s.step_order')
            ->select('s.*', DB::raw("CONCAT(u.first_name,' ',u.last_name) as signer_name"))
            ->get();
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
        DB::table('document_workflow_steps')->where('id', $step->id)->update([
            'signer_user_id' => $u->id,
            'signed_at' => now(),
            'signature_data' => $data['signature_data'],
            'signature_hash' => $hash,
            'signer_ip' => $request->ip(),
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
            if ($nextStep->signer_user_id) {
                \App\Support\Notify::write([
                    'user_id' => $nextStep->signer_user_id, 'type' => 'document_workflow',
                    'title' => 'Your turn to sign: ' . $workflow->title,
                    'body' => 'Previous step complete.',
                    'data' => json_encode(['link' => '#doc-workflows', 'workflow_id' => $id]),
                    'created_at' => now(),
                ]);
            }
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
