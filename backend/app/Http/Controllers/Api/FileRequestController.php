<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\FileRequestNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * "Send me these files." The admin side.
 *
 * The mirror of a form package: there, the agency supplies the document and the family
 * fills it in; here, the agency asks for documents the family already has. Both end in the
 * same place -- a row in `documents` on the person's own record -- so a parent has one
 * Documents screen rather than two half-answers.
 *
 * A request is a LIST. "Immunisation card (2 photos), photo ID (1), proof of address (1)"
 * is one ask with three lines, tracked line by line, because "have they sent everything?"
 * is the only question anybody has about it.
 */
final class FileRequestController extends Controller
{
    use ResolvesCentreContext;

    /** The category these land under in `documents`, and the source tag on each row. */
    public const CATEGORY = 'requested_file';
    public const SOURCE   = 'file_request_item';

    private function agencyId(Request $request): int
    {
        return (int) ($this->resolveAgencyId($request) ?: 0);
    }

    private function roles(int $uid): array
    {
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->pluck('role')->all();
        if (DB::table('guardians')->where('user_id', $uid)->exists()) {
            $roles[] = 'guardian';
        }

        return array_values(array_unique($roles));
    }

    private function isAdmin(Request $request): bool
    {
        return (bool) array_intersect(
            $this->roles((int) $request->user()->id),
            ['platform_admin', 'agency_admin', 'centre_director']
        );
    }

    /**
     * Which of these accounts belong to this agency.
     *
     * The same rule form packages use, and for the same reason: one email address can hold
     * accounts in several agencies, and a request that landed on the wrong one would put a
     * family's ID document on another tenant's record. Fails closed.
     *
     * @param  int[]  $candidateIds
     * @return int[]
     */
    private function agencyMemberIds(int $agencyId, array $candidateIds): array
    {
        $candidateIds = array_values(array_unique(array_filter(array_map('intval', $candidateIds))));
        if (! $agencyId || ! $candidateIds) {
            return [];
        }
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();

        $viaRole = DB::table('role_assignments')
            ->whereIn('user_id', $candidateIds)->where('active', 1)
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('agency_id', $agencyId);
                if ($centreIds) {
                    $q->orWhereIn('centre_id', $centreIds);
                }
            })->pluck('user_id')->all();

        $viaGuardian = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('g.user_id', $candidateIds)->whereNull('f.deleted_at')
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->pluck('g.user_id')->all();

        return array_values(array_unique(array_map('intval', array_merge($viaRole, $viaGuardian))));
    }

    /* ───────────────────────── list ───────────────────────── */

    /** GET /admin/file-requests */
    public function index(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['requests' => []], 403);
        }
        $agencyId = $this->agencyId($request);

        $rows = DB::table('file_requests as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.agency_id', $agencyId)
            ->orderByDesc('r.created_at')->orderByDesc('r.id')
            ->limit(300)
            ->get([
                'r.id', 'r.user_id', 'r.email', 'r.priority', 'r.due_on', 'r.note',
                'r.status', 'r.completed_at', 'r.notified_at', 'r.created_at',
                'r.requested_by_name',
                'u.first_name', 'u.last_name',
            ]);

        $ids = $rows->pluck('id')->all();
        $items = $ids
            ? DB::table('file_request_items')->whereIn('file_request_id', $ids)
                ->orderBy('display_order')->orderBy('id')->get()
            : collect();

        /* ONE query for every uploaded file across the page, then matched in PHP -- the
           per-row fan-out is what saturates this host at drop-off time. */
        $itemIds = $items->pluck('id')->map(fn ($v) => (int) $v)->all();
        $counts = [];
        if ($itemIds) {
            foreach (DB::table('documents')
                ->where('source_type', self::SOURCE)->whereIn('source_id', $itemIds)
                ->select('source_id', DB::raw('COUNT(*) as n'))
                ->groupBy('source_id')->get() as $c) {
                $counts[(int) $c->source_id] = (int) $c->n;
            }
        }

        $byReq = [];
        foreach ($items as $it) {
            $byReq[(int) $it->file_request_id][] = $it;
        }

        return response()->json([
            'requests' => $rows->map(function ($r) use ($byReq, $counts) {
                $its = $byReq[(int) $r->id] ?? [];
                $asked = 0;
                $got = 0;
                foreach ($its as $it) {
                    $asked += (int) $it->quantity;
                    $got += min((int) ($counts[(int) $it->id] ?? 0), (int) $it->quantity);
                }

                return [
                    'id'          => (int) $r->id,
                    'person'      => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ($r->email ?: '—'),
                    'email'       => $r->email,
                    'user_id'     => (int) $r->user_id,
                    'priority'    => $r->priority,
                    'due_on'      => $r->due_on,
                    'note'        => $r->note,
                    'status'      => $r->status,
                    'items'       => count($its),
                    'asked'       => $asked,
                    'received'    => $got,
                    'requested_by' => $r->requested_by_name,
                    'notified'    => (bool) $r->notified_at,
                    'completed_at' => $r->completed_at,
                    'created_at'  => $r->created_at,
                ];
            })->values(),
        ]);
    }

    /* ───────────────────────── create ───────────────────────── */

    /** POST /admin/file-requests */
    public function store(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $data = $request->validate([
            /* Either a chosen person or a typed address. Both end up as an account,
               because a file needs a record to live on. */
            'user_id'  => ['nullable', 'integer'],
            'email'    => ['nullable', 'email', 'max:190'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'due_on'   => ['nullable', 'date'],
            'note'     => ['nullable', 'string', 'max:2000'],
            'notify'   => ['sometimes', 'boolean'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.description'   => ['required', 'string', 'max:200'],
            'items.*.kind'          => ['nullable', 'in:any,pdf,image,document'],
            'items.*.quantity'      => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $agencyId = $this->agencyId($request);

        $userId = (int) ($data['user_id'] ?? 0);
        if (! $userId && ! empty($data['email'])) {
            /* One account per typed address, inside THIS agency -- the same rule form
               packages learned the hard way when one address resolved to three accounts
               across two tenants. */
            $candidates = DB::table('users')->whereNull('deleted_at')
                ->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower(trim($data['email']))])
                ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
            $mine = $this->agencyMemberIds($agencyId, $candidates);
            $userId = $mine ? min($mine) : 0;
        }
        if (! $userId) {
            return response()->json([
                'message' => 'No account at this agency uses that address. Files have to be requested from somebody with a record here.',
            ], 422);
        }
        if (! $this->agencyMemberIds($agencyId, [$userId])) {
            return response()->json(['message' => 'That person is not at this agency.'], 422);
        }

        $user = DB::table('users')->where('id', $userId)->whereNull('deleted_at')
            ->first(['id', 'first_name', 'last_name', 'email']);
        if (! $user) {
            return response()->json(['message' => 'That person could not be found.'], 422);
        }

        $me = $request->user();
        $notify = ! array_key_exists('notify', $data) || (bool) $data['notify'];

        $id = (int) DB::table('file_requests')->insertGetId([
            'agency_id'        => $agencyId,
            'requested_by_id'  => (int) $me->id,
            'requested_by_name' => trim(($me->first_name ?? '') . ' ' . ($me->last_name ?? '')) ?: ($me->email ?? null),
            'user_id'          => $userId,
            'email'            => $user->email,
            'priority'         => $data['priority'] ?? 'normal',
            'due_on'           => $data['due_on'] ?? null,
            'note'             => $data['note'] ?? null,
            'status'           => 'open',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $order = 0;
        foreach ($data['items'] as $it) {
            DB::table('file_request_items')->insert([
                'file_request_id' => $id,
                'description'     => trim((string) $it['description']),
                'kind'            => $it['kind'] ?? 'any',
                'quantity'        => (int) ($it['quantity'] ?? 1),
                'display_order'   => $order++,
                'created_at'      => now(),
            ]);
        }

        $emailed = false;
        if ($notify) {
            try {
                $emailed = FileRequestNotice::ask($id);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        try {
            Audit::write([
                'user_id'     => (int) $me->id,
                'agency_id'   => $agencyId,
                'action'      => 'file_request.created',
                'entity_type' => 'file_request',
                'entity_id'   => $id,
                /* Named, not counted -- an audit row saying "3 items" cannot answer the
                   question anybody asks it later. */
                'payload'     => json_encode([
                    'person'   => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                    'email'    => $user->email,
                    'priority' => $data['priority'] ?? 'normal',
                    'due_on'   => $data['due_on'] ?? null,
                    'items'    => array_map(fn ($i) => $i['description'] . ' x' . ($i['quantity'] ?? 1), $data['items']),
                    'emailed'  => $emailed,
                ]),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'id'      => $id,
            'emailed' => $emailed,
            'message' => count($data['items']) . ' file(s) requested from '
                . (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email)
                . ($notify ? ($emailed ? ' · emailed' : ' · the email could not be sent') : ' · no email sent') . '.',
        ], 201);
    }

    /* ───────────────────────── detail ───────────────────────── */

    /** GET /admin/file-requests/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $agencyId = $this->agencyId($request);
        $r = DB::table('file_requests as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.id', $id)->where('r.agency_id', $agencyId)
            ->first(['r.*', 'u.first_name', 'u.last_name']);
        if (! $r) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['request' => self::detail((int) $r->id, $r)]);
    }

    /**
     * One request with its lines and whatever has been uploaded against each.
     *
     * Shared with the signed upload page and the notices, so the admin, the recipient and
     * the email can never describe the same request differently.
     */
    public static function detail(int $id, ?object $row = null): array
    {
        $r = $row ?: DB::table('file_requests as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.id', $id)->first(['r.*', 'u.first_name', 'u.last_name']);
        if (! $r) {
            return [];
        }

        /* WHO IS ASKING, by name and by address.

           A stranger's email saying "send us your ID" is indistinguishable from a phishing
           attempt unless it names a person the reader knows and gives them a way to check.
           The name was already stored; the address is looked up here so the letter and the
           upload page can both show it. A separate small query on purpose -- detail() is
           called with a row that other callers built, and widening their SELECT to suit
           this would couple them to it. (Anthony, 2026-09-10) */
        $requesterEmail = ! empty($r->requested_by_id)
            ? DB::table('users')->where('id', $r->requested_by_id)->value('email')
            : null;

        $items = DB::table('file_request_items')->where('file_request_id', $id)
            ->orderBy('display_order')->orderBy('id')->get();

        $docs = DB::table('documents')
            ->where('source_type', self::SOURCE)
            ->whereIn('source_id', $items->pluck('id')->map(fn ($v) => (int) $v)->all() ?: [0])
            ->orderBy('id')
            ->get(['id', 'source_id', 'title', 'file_url', 'file_type', 'file_size', 'created_at']);

        $byItem = [];
        foreach ($docs as $d) {
            $byItem[(int) $d->source_id][] = [
                'id'         => (int) $d->id,
                'title'      => $d->title,
                'file_url'   => $d->file_url,
                'file_type'  => $d->file_type,
                'file_size'  => $d->file_size,
                'created_at' => $d->created_at,
            ];
        }

        $asked = 0;
        $got = 0;
        $lines = [];
        foreach ($items as $it) {
            $files = $byItem[(int) $it->id] ?? [];
            $asked += (int) $it->quantity;
            $got += min(count($files), (int) $it->quantity);
            $lines[] = [
                'id'          => (int) $it->id,
                'description' => $it->description,
                'kind'        => $it->kind,
                'quantity'    => (int) $it->quantity,
                'files'       => $files,
                'done'        => count($files) >= (int) $it->quantity,
            ];
        }

        return [
            'id'         => (int) $r->id,
            'person'     => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ($r->email ?: '—'),
            'email'      => $r->email,
            'user_id'    => (int) $r->user_id,
            'agency_id'  => (int) $r->agency_id,
            'priority'   => $r->priority,
            'due_on'     => $r->due_on,
            'note'       => $r->note,
            'status'     => $r->status,
            'requested_by' => $r->requested_by_name,
            'requested_by_email' => $requesterEmail,
            'created_at' => $r->created_at,
            'completed_at' => $r->completed_at,
            'asked'      => $asked,
            'received'   => $got,
            'items'      => $lines,
        ];
    }

    /**
     * Has everything been sent? Called after every upload.
     *
     * Flips the request to complete and tells the office, ONCE -- completed_at is the
     * guard, so a person who uploads a fourth file to a three-file request does not
     * trigger a second round of email.
     */
    public static function refreshStatus(int $requestId): void
    {
        try {
            $d = self::detail($requestId);
            if (! $d) {
                return;
            }
            $allDone = $d['items'] && ! array_filter($d['items'], fn ($i) => ! $i['done']);
            $row = DB::table('file_requests')->where('id', $requestId)->first(['status', 'completed_at']);
            if (! $row) {
                return;
            }

            if ($allDone && ! $row->completed_at) {
                DB::table('file_requests')->where('id', $requestId)
                    ->update(['status' => 'complete', 'completed_at' => now(), 'updated_at' => now()]);
                FileRequestNotice::completed($requestId);
            } elseif (! $allDone && $row->status === 'complete') {
                // A file was removed. The request is open again, quietly.
                DB::table('file_requests')->where('id', $requestId)
                    ->update(['status' => 'open', 'completed_at' => null, 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ───────────────────────── delete ───────────────────────── */

    /**
     * DELETE /admin/file-requests/{id}
     *
     * Removes the ASK. The files people already sent stay on their record -- they were
     * handed over in good faith and belong to the person, not to the request. Said out
     * loud in the response so nobody assumes otherwise.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $agencyId = $this->agencyId($request);
        $r = DB::table('file_requests')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $r) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $d = self::detail($id);
        $itemIds = DB::table('file_request_items')->where('file_request_id', $id)->pluck('id')->all();

        /* The documents keep their content and their place on the person's record; only
           the link back to a request that no longer exists is cleared. */
        if ($itemIds) {
            DB::table('documents')->where('source_type', self::SOURCE)->whereIn('source_id', $itemIds)
                ->update(['source_type' => null, 'source_id' => null]);
        }
        DB::table('file_request_items')->where('file_request_id', $id)->delete();
        DB::table('file_requests')->where('id', $id)->delete();

        try {
            Audit::write([
                'user_id'     => (int) $request->user()->id,
                'agency_id'   => $agencyId,
                'action'      => 'file_request.deleted',
                'entity_type' => 'file_request',
                'entity_id'   => $id,
                'payload'     => json_encode([
                    'person' => $d['person'] ?? null,
                    'items'  => array_map(fn ($i) => $i['description'] . ' x' . $i['quantity'], $d['items'] ?? []),
                    'files_kept' => $d['received'] ?? 0,
                ]),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'ok' => true,
            'message' => 'Request removed. Any files already sent stay on ' . ($d['person'] ?? 'their') . '\'s record.',
        ]);
    }

    /** POST /admin/file-requests/{id}/remind — ask again. */
    /* THE LINK THE FAMILY WAS SENT (2026-09-17).

       Anthony: "open link for the request files table."

       The upload page is signed and needs no login, and until now the only copy of that
       link was inside the email. When a parent says they never got it, or lost it, or is
       standing at the desk with the documents on their phone, staff had no way to reach
       the page at all - the only option was a reminder email and hope.

       IT IS THE SAME LINK, not a new one. temporarySignedRoute is deterministic for a
       given (request, user, expiry window), so opening this does not invalidate the one
       already in their inbox.

       It is a real credential - anyone holding it can upload against this request - so it
       is admin-only, agency-scoped, and the fetch is audited. */
    public function link(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $agencyId = $this->agencyId($request);
        $r = DB::table('file_requests')->where('id', $id)->where('agency_id', $agencyId)
            ->first(['id', 'user_id', 'email', 'status']);
        if (! $r) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $r->user_id) {
            return response()->json(['message' => 'This request has nobody attached to it.'], 422);
        }

        try {
            \App\Support\Audit::write([
                'agency_id' => $agencyId,
                'user_id' => $request->user()->id,
                'action' => 'file_request.link_viewed',
                'entity_type' => 'file_request',
                'entity_id' => $id,
                'payload' => json_encode([
                    'for' => $r->email,
                    'summary' => 'Opened the upload link for file request #' . $id . ' (' . $r->email . ').',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail over the audit row */ }

        return response()->json([
            'url' => \App\Http\Controllers\Api\SignedFileUploadController::linkFor($id, (int) $r->user_id),
            'to' => $r->email,
            'expires_days' => \App\Http\Controllers\Api\SignedFileUploadController::LINK_DAYS,
            'complete' => $r->status === 'complete',
        ]);
    }

    public function remind(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $agencyId = $this->agencyId($request);
        $r = DB::table('file_requests')->where('id', $id)->where('agency_id', $agencyId)->first(['id', 'status']);
        if (! $r) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($r->status === 'complete') {
            return response()->json(['message' => 'Everything has already been sent — there is nothing to chase.'], 422);
        }

        $ok = FileRequestNotice::ask($id, true);

        return response()->json([
            'ok' => $ok,
            'message' => $ok ? 'Reminder sent.' : 'The reminder could not be sent.',
        ]);
    }

    /** GET /admin/file-requests/{id}/files/{doc}/download — stream one file. */
    public function download(Request $request, int $id, int $doc)
    {
        abort_unless($this->isAdmin($request), 403);
        $agencyId = $this->agencyId($request);

        $req = DB::table('file_requests')->where('id', $id)->where('agency_id', $agencyId)->first(['id']);
        abort_unless($req, 404);

        /* The document must belong to THIS request. Possession of a document id is not
           authorisation to read it -- the check is the join, not the guess. */
        $itemIds = DB::table('file_request_items')->where('file_request_id', $id)->pluck('id')->all();
        $row = DB::table('documents')->where('id', $doc)
            ->where('source_type', self::SOURCE)
            ->whereIn('source_id', $itemIds ?: [0])
            ->first(['title', 'file_url', 'file_type']);
        abort_unless($row, 404);

        $rel = ltrim(str_replace('/storage/', '', (string) $row->file_url), '/');
        abort_unless($rel !== '' && Storage::disk('public')->exists($rel), 404);

        return response()->file(Storage::disk('public')->path($rel));
    }
}
