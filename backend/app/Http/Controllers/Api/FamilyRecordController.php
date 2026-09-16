<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The parts of a family record that could be read but never changed.
 *
 * A family could be created, and after that it was frozen: no way to add a second child,
 * no way to add or remove a guardian, and notes were one free-text box with no author and
 * no date that the next person to save overwrote. The API could do some of it — enrolling
 * a child, inviting a guardian — but nothing on the family record called any of it.
 */
class FamilyRecordController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesTenantAccess;

    // ── Documents ───────────────────────────────────────────────────────────

    /**
     * GET /admin/families/{family}/documents -- everything filed about this family.
     *
     * Three sources, one list, because the person looking does not care which table a
     * document happens to live in:
     *
     *   · the children's files      (scope child)  -- incident reports, medical notes
     *   · the family's own files    (scope family) -- anything filed at that level
     *   · the guardians' signed forms (scope user) -- consent, photo permission, policies
     *
     * The signed forms are RESOLVED through `guardians`, not copied to a family scope.
     * A signature is one fact; two rows saying it would drift the first time one was
     * deleted or a form renamed. The trade is a join, which is cheap, against a
     * duplicate, which is permanent.
     *
     * Only the signed forms are pulled from the guardian's own record, deliberately:
     * a user-scoped document can be anything an admin attached to that person
     * (a contract, a disciplinary note), and their family has no business seeing it
     * turn up here. An allowlist of one category, widened only on purpose.
     *
     * This also replaces a fan-out the family screen used to do -- one request per
     * child, because "there is no endpoint that takes a family". Per-child fan-out is
     * the shape that saturates this host at drop-off time.
     */
    public function documents(Request $request, int $familyId): JsonResponse
    {
        $this->assertFamily((int) $request->user()->id, $familyId);

        $children = DB::table('children')->where('family_id', $familyId)
            ->whereNull('deleted_at')->get(['id', 'first_name', 'last_name']);
        $childIds = $children->pluck('id')->map(fn ($v) => (int) $v)->all();
        $childName = [];
        foreach ($children as $c) {
            $childName[(int) $c->id] = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
        }

        $guardians = DB::table('guardians as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)->whereNull('u.deleted_at')
            ->get(['u.id', 'u.first_name', 'u.last_name']);
        $guardianIds = $guardians->pluck('id')->map(fn ($v) => (int) $v)->all();
        $guardianName = [];
        foreach ($guardians as $g) {
            $guardianName[(int) $g->id] = trim(($g->first_name ?? '') . ' ' . ($g->last_name ?? ''));
        }

        /* `?: [0]` on every list. A family with no children, or no guardian account yet,
           must match NOTHING -- an empty whereIn would match every row in the table. */
        $rows = DB::table('documents')
            ->where(function ($q) use ($childIds, $familyId, $guardianIds) {
                $q->where(function ($qq) use ($childIds) {
                    $qq->where('scope_type', 'child')->whereIn('scope_id', $childIds ?: [0]);
                })->orWhere(function ($qq) use ($familyId) {
                    $qq->where('scope_type', 'family')->where('scope_id', $familyId);
                })->orWhere(function ($qq) use ($guardianIds) {
                    $qq->where('scope_type', 'user')
                       ->whereIn('scope_id', $guardianIds ?: [0])
                       ->where('category', \App\Support\SignedFormFiler::CATEGORY);
                });
            })
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(['id', 'scope_type', 'scope_id', 'category', 'title', 'file_url',
                   'file_type', 'file_size', 'signed_at', 'expires_at', 'created_at']);

        return response()->json([
            'documents' => $rows->map(function ($d) use ($childName, $guardianName) {
                /* Who it is ABOUT, which is the column a reader scans. Left blank rather
                   than guessed at when the scope has no name attached. */
                $about = $d->scope_type === 'child'
                    ? ($childName[(int) $d->scope_id] ?? null)
                    : ($d->scope_type === 'user' ? ($guardianName[(int) $d->scope_id] ?? null) : null);

                return [
                    'id'         => (int) $d->id,
                    'title'      => $d->title,
                    'category'   => $d->category,
                    'scope'      => $d->scope_type,
                    'about'      => $about,
                    'file_url'   => $d->file_url,
                    'file_type'  => $d->file_type,
                    'file_size'  => $d->file_size,
                    'signed_at'  => $d->signed_at,
                    'expires_at' => $d->expires_at,
                    'created_at' => $d->created_at,
                ];
            })->values(),
        ]);
    }

    // ── Notes ───────────────────────────────────────────────────────────────

    /** GET /admin/families/{family}/notes */
    public function listNotes(Request $request, int $familyId): JsonResponse
    {
        $this->assertFamily((int) $request->user()->id, $familyId);

        $rows = DB::table('family_notes as n')
            ->leftJoin('users as u', 'u.id', '=', 'n.user_id')
            ->where('n.family_id', $familyId)
            ->orderByDesc('n.pinned')
            ->orderByDesc('n.created_at')
            ->limit(200)
            ->get(['n.id', 'n.body', 'n.pinned', 'n.created_at',
                   'u.first_name', 'u.last_name', 'u.photo_url']);

        return response()->json([
            'notes' => $rows->map(fn ($n) => [
                'id' => (int) $n->id,
                'body' => $n->body,
                'pinned' => (bool) $n->pinned,
                'created_at' => $n->created_at,
                'author' => trim(($n->first_name ?? '').' '.($n->last_name ?? '')) ?: 'System',
                'author_photo' => $n->photo_url,
            ]),
        ]);
    }

    /** POST /admin/families/{family}/notes */
    public function addNote(Request $request, int $familyId): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->assertFamily($userId, $familyId);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'pinned' => ['nullable', 'boolean'],
        ]);

        $id = DB::table('family_notes')->insertGetId([
            'family_id' => $familyId,
            'user_id' => $userId,
            'body' => trim($data['body']),
            'pinned' => ! empty($data['pinned']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($request, $familyId, 'family.note_added', ['note_id' => $id]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /**
     * DELETE /admin/families/{family}/notes/{note}
     *
     * Only the author can remove their own note, and only within the hour. A note is a
     * record of what somebody knew at a time; letting anyone delete anyone's later would
     * make the trail worth less than no trail at all. Beyond the hour, the way to correct
     * a note is to write another one.
     */
    public function deleteNote(Request $request, int $familyId, int $noteId): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->assertFamily($userId, $familyId);

        $note = DB::table('family_notes')->where('id', $noteId)->where('family_id', $familyId)->first();
        if (! $note) {
            return response()->json(['message' => 'Note not found'], 404);
        }
        if ((int) $note->user_id !== $userId) {
            return response()->json(['message' => 'Only the person who wrote a note can remove it.'], 403);
        }
        if (now()->diffInMinutes($note->created_at) > 60) {
            return response()->json([
                'message' => 'This note is more than an hour old. Add a new note correcting it instead.',
            ], 422);
        }

        DB::table('family_notes')->where('id', $noteId)->delete();
        $this->audit($request, $familyId, 'family.note_deleted', ['note_id' => $noteId]);

        return response()->json(['ok' => true]);
    }

    /** POST /admin/families/{family}/notes/{note}/pin  { pinned: bool } */
    public function pinNote(Request $request, int $familyId, int $noteId): JsonResponse
    {
        $this->assertFamily((int) $request->user()->id, $familyId);
        $pinned = $request->boolean('pinned', true);

        $n = DB::table('family_notes')->where('id', $noteId)->where('family_id', $familyId)->first();
        if (! $n) {
            return response()->json(['message' => 'Note not found'], 404);
        }

        DB::table('family_notes')->where('id', $noteId)
            ->update(['pinned' => $pinned, 'updated_at' => now()]);

        return response()->json(['ok' => true, 'pinned' => $pinned]);
    }

    /**
     * GET /admin/families/{family}/provider-history
     *
     * Every provider each of this family's children has been with, and when. The child
     * record answers this one child at a time; a parent asking "who has had my children
     * this year" was a question nobody could answer in one place.
     *
     * Built from enrolments — the record of where a child actually was — and attributed
     * from the audit log, which knows who changed a placement and when. Enrolments carry
     * no author of their own, so a move shows an author when one was recorded and simply
     * shows the date when it was not, rather than inventing "system".
     */
    public function providerHistory(Request $request, int $familyId): JsonResponse
    {
        $this->assertFamily((int) $request->user()->id, $familyId);

        $childIds = DB::table('children')->where('family_id', $familyId)
            ->whereNull('deleted_at')->pluck('id');
        if ($childIds->isEmpty()) {
            return response()->json(['history' => []]);
        }

        $rows = DB::table('enrollments as e')
            ->join('children as ch', 'ch.id', '=', 'e.child_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
            ->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')
            ->whereIn('e.child_id', $childIds)
            ->orderByDesc('e.start_date')->orderByDesc('e.id')
            ->get([
                'e.id', 'e.child_id', 'e.start_date', 'e.end_date', 'e.schedule',
                'ch.first_name', 'ch.preferred_name', 'ch.last_name',
                'r.name as room_name', 'c.name as centre_name',
            ]);

        /* Who changed a placement, from the audit trail. Keyed by child and day, which is
           as precise as the two sources can honestly be joined: an enrolment records the
           date it starts, not the moment somebody pressed save. */
        $actors = [];
        try {
            $audits = DB::table('audit_logs as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.entity_type', 'child')
                ->whereIn('a.entity_id', $childIds)
                ->whereIn('a.action', ['child.care_schedule_updated', 'child.transferred', 'transfer'])
                ->orderBy('a.created_at')
                ->get(['a.entity_id', 'a.created_at', 'u.first_name', 'u.last_name']);
            /* Collect every actor per child+day, then keep the attribution ONLY when
               they agree. An enrolment records the date it starts, not the moment
               somebody saved it, so several changes on one day cannot be told apart —
               and a guess would put a real person's name against a change they did not
               make. Ambiguous means unattributed. */
            $seen = [];
            foreach ($audits as $a) {
                $key = $a->entity_id.'|'.substr((string) $a->created_at, 0, 10);
                $who = trim(($a->first_name ?? '').' '.($a->last_name ?? ''));
                if ($who === '') {
                    continue;
                }
                $seen[$key][$who] = true;
            }
            foreach ($seen as $key => $names) {
                if (count($names) === 1) {
                    $actors[$key] = array_key_first($names);
                }
            }
        } catch (\Throwable $e) {
            // Attribution is a nicety; the history itself must still render.
        }

        return response()->json([
            'history' => $rows->map(function ($r) use ($actors) {
                $days = \App\Support\CareSchedule::daysOf($r->schedule);
                $key = $r->child_id.'|'.substr((string) $r->start_date, 0, 10);

                return [
                    'child_id' => (int) $r->child_id,
                    'child_name' => trim(($r->preferred_name ?: $r->first_name).' '.($r->last_name ?? '')),
                    'provider' => $r->centre_name ?: $r->room_name,
                    'room_name' => $r->room_name,
                    'start_date' => $r->start_date,
                    'end_date' => $r->end_date,
                    'current' => $r->end_date === null,
                    // Seven days means "every day they attend" — saying so is clearer
                    // than listing the whole week back at somebody.
                    'days' => count($days) === 7 ? null : array_map('ucfirst', $days),
                    'changed_by' => $actors[$key] ?? null,
                ];
            })->values(),
        ]);
    }

    // ── Guardians ───────────────────────────────────────────────────────────

    /**
     * DELETE /admin/families/{family}/guardians/{guardian}
     *
     * Unlinks the person from the family. It does NOT delete their user account: the same
     * person may be a guardian of another family, or staff here, and a wrong link on one
     * family is not a reason to remove somebody from the system.
     *
     * The last guardian cannot be removed — a family with no contactable adult is a
     * safety problem, not a tidier record.
     */
    public function removeGuardian(Request $request, int $familyId, int $guardianId): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->assertFamily($userId, $familyId);

        $g = DB::table('guardians')->where('id', $guardianId)->where('family_id', $familyId)->first();
        if (! $g) {
            return response()->json(['message' => 'That guardian is not on this family.'], 404);
        }

        $count = DB::table('guardians')->where('family_id', $familyId)->count();
        if ($count <= 1) {
            return response()->json([
                'message' => 'This is the family\'s only guardian. Add another before removing this one — '
                    .'a family with no contactable adult cannot be left on the system.',
            ], 422);
        }

        $person = DB::table('users')->where('id', $g->user_id)->first();

        DB::table('guardians')->where('id', $guardianId)->delete();

        /* If they guard nobody else, their guardian role here is now meaningless. The
           ACCOUNT stays — they may be staff, or a guardian at another agency. */
        try {
            $stillGuards = DB::table('guardians')->where('user_id', $g->user_id)->exists();
            if (! $stillGuards) {
                DB::table('role_assignments')->where('user_id', $g->user_id)
                    ->where('role', 'guardian')->update(['active' => 0]);
            }
        } catch (\Throwable $e) {
            Log::warning('Guardian role cleanup failed: '.$e->getMessage());
        }

        $this->audit($request, $familyId, 'family.guardian_removed', [
            'guardian_id' => $guardianId,
            'user_id' => $g->user_id,
            'name' => trim(($person->first_name ?? '').' '.($person->last_name ?? '')),
        ]);

        return response()->json(['ok' => true]);
    }

    // ── Shared ──────────────────────────────────────────────────────────────

    private function audit(Request $request, int $familyId, string $action, array $payload): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id ?? null,
                'agency_id' => DB::table('families as f')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('f.id', $familyId)->value('c.agency_id'),
                'action' => $action,
                'entity_type' => 'family',
                'entity_id' => $familyId,
                'payload' => json_encode($payload),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Family record audit failed: '.$e->getMessage());
        }
    }
}
