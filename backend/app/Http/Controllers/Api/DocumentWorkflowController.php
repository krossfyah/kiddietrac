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
    public function listMine(Request $request): JsonResponse
    {
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        $q = DB::table('document_workflows')->orderByDesc('created_at');
        if ($isStaff) {
            $agencyId = (int) ($request->header('X-Active-Agency-Id')
                ?: DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->value('agency_id'));
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
        $agencyId = (int) ($request->header('X-Active-Agency-Id')
            ?: DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->value('agency_id'));

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
                DB::table('notifications')->insert([
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
        // SECURITY (v22p94): the workflow must belong to the caller's agency.
        abort_unless($this->isPlatformAdminUser($request->user()) || $this->userBelongsToAgency($request->user()->id, (int) $w->agency_id), 403);
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
        // SECURITY (v22p94): only someone in the workflow's agency may sign/advance
        // it (in addition to the per-step assigned-signer check below).
        abort_unless($this->isPlatformAdminUser($u) || $this->userBelongsToAgency($u->id, (int) $workflow->agency_id), 403);
        abort_unless($workflow->status === 'in_progress', 422, 'Workflow already complete');
        abort_unless($workflow->current_step === $data['step_order'], 422, 'Not the current step');

        $step = DB::table('document_workflow_steps')->where('workflow_id', $id)
            ->where('step_order', $data['step_order'])->first();
        abort_unless($step, 404);
        if ($step->signer_user_id && $step->signer_user_id !== $u->id) abort(403, 'Not the assigned signer');

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
                DB::table('notifications')->insert([
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
            DB::table('notifications')->insert([
                'user_id' => $workflow->started_by_user_id, 'type' => 'document_workflow',
                'title' => 'Document fully signed: ' . $workflow->title,
                'body' => 'All signers have completed.',
                'data' => json_encode(['link' => '#doc-workflows', 'workflow_id' => $id]),
                'created_at' => now(),
            ]);
        }

        return response()->json(['status' => 'signed', 'workflow_status' => $nextStep ? 'in_progress' : 'complete']);
    }
}
