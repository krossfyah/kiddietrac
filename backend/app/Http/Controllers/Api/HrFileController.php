<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\Features;
use App\Support\HrDocs;
use App\Support\HrLeave;
use App\Support\HrLeaving;
use App\Support\HrPay;
use App\Support\ProtectedMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 4 (2026-11-27): the document vault, sick notes on time off, pay + role history,
 * the leaving pack (checklist, ROE prep, payout, undo, returning employee), "download all their data".
 * App\Support\HrDocs, HrPay, HrLeaving hold the rules. All routes under hr/ (the HR add-on).
 *
 * WHO (fails closed, inside the ACTIVE agency):
 *  - managed = agency admins: the agency's staff AND former staff; directors: those placed at their own centres.
 *  - a file manager (hr_employee_files.manager_user_id) may VIEW that person's documents, never sick/time-off notes.
 *  - your own file: you see what is shared with you (visibility employee); you never upload to, edit, or change the
 *    pay/role of your own file, and you never see your own managers-only documents.
 *  - pay fields: managers with the payroll feature (HrController::canSeePay). Leaving writes, company folders, the
 *    data download: agency admins.
 */
final class HrFileController extends Controller
{
    use HrAccess;

    private const NOTE_TYPES = ['sick_note', 'leave_note'];

    // ── access ────────────────────────────────────────────────────────────────────────

    /** @return int[] staff and former staff this caller manages */
    private function managed(Request $request, int $aid): array
    {
        if (! $this->hrIsManager($request)) return [];

        return array_values(array_unique(array_merge($this->hrPeople($request, $aid), HrLeaving::leaverIds($aid, CentreScope::ids($request, $aid)))));
    }

    private function needManaged(Request $request, int $aid, int $id, bool $notSelf = false): void
    {
        abort_unless(in_array($id, $this->managed($request, $aid), true), 404, 'That person was not found.');
        abort_if($notSelf && $id === (int) $request->user()->id, 403, 'Someone else has to change your own HR file.');
    }

    private function canSeePay(Request $request, int $aid): bool
    {
        return $this->hrIsManager($request) && Features::on($aid, 'payroll');
    }

    private function fileManagerOf(int $aid, int $userId): int
    {
        return (int) DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $userId)->value('manager_user_id');
    }

    /** 'manage' | 'view' | null for one document. */
    private function access(Request $request, int $aid, object $d): ?string
    {
        $me = (int) $request->user()->id;
        if ((int) $d->agency_id !== $aid || $d->archived_at) return null;
        if (! $d->user_id) {
            $f = DB::table('hr_doc_folders')->where('id', $d->folder_id)->where('agency_id', $aid)->whereNull('archived_at')->first();
            if (! $f || ! in_array($me, HrLeave::staffIds($aid), true)) return null;
            if ($this->hrIsAdmin($request)) return 'manage';

            return HrDocs::folderOpenTo($f, $me, false) ? 'view' : null;
        }
        $owner = (int) $d->user_id;
        if ($owner === $me) return $d->visibility === 'employee' ? 'view' : null;
        if (in_array($owner, $this->managed($request, $aid), true)) return 'manage';
        if (! in_array($d->doc_type, self::NOTE_TYPES, true) && $this->fileManagerOf($aid, $owner) === $me && in_array($owner, HrLeave::staffIds($aid), true)) return 'view';

        return null;
    }

    private function doc(Request $request, int $aid, int $id, string $need = 'view'): object
    {
        $d = DB::table('hr_documents')->where('id', $id)->first();
        $a = $d ? $this->access($request, $aid, $d) : null;
        abort_unless($a !== null && ($need === 'view' || $a === 'manage'), 404, 'That document was not found.');

        return $d;
    }

    private function names(iterable $rows, string $col = 'uploaded_by_id'): array
    {
        $ids = [];
        foreach ($rows as $r) if ($r->{$col}) $ids[] = (int) $r->{$col};

        return $this->hrNames(array_values(array_unique($ids)));
    }

    private function dateRules(): array
    {
        return ['expires_on' => 'nullable|date_format:Y-m-d', 'follow_up_on' => 'nullable|date_format:Y-m-d', 'follow_up_note' => 'nullable|string|max:255'];
    }

    // ── my documents ──────────────────────────────────────────────────────────────────

    /** GET /hr/me/documents — what is on my file and shared with me, and the company documents I can open. */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 404, 'Your HR file was not found.');
        $docs = DB::table('hr_documents')->where('agency_id', $aid)->where('user_id', $me)->where('visibility', 'employee')->whereNull('archived_at')
            ->orderByDesc('created_at')->get();
        $company = HrDocs::companyFor($aid, $me, false);
        $todo = 0;
        foreach ($company as $f) foreach ($f['documents'] as $d) if (($d['requires_accept'] && ! $d['accepted_at']) || ! $d['read_at']) $todo++;
        $names = $this->names($docs);

        return response()->json(['documents' => $docs->map(fn ($d) => HrDocs::shape($d, $names))->values(), 'company' => $company, 'to_do' => $todo,
            'types' => HrDocs::TYPES]);
    }

    /** GET /hr/documents/{id}/open — a signed preview link for someone allowed to see it. Marks it read; managers' opens are audited. */
    public function open(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $d = $this->doc($request, $aid, $id);
        $me = (int) $request->user()->id;
        if (! $d->user_id || (int) $d->user_id === $me) HrDocs::markRead($id, $me);
        if ($d->user_id && (int) $d->user_id !== $me) {
            $this->hrNote($aid, in_array($d->doc_type, self::NOTE_TYPES, true) ? 'hr.sick_note_viewed' : 'hr.document_opened', 'user', (int) $d->user_id, ['document_id' => $id]);
        }

        return response()->json(['url' => ProtectedMedia::sign($d->file_path, $d->file_name), 'name' => $d->file_name, 'mime' => $d->file_mime,
            'title' => $d->title, 'previewable' => HrDocs::shape($d)['previewable']]);
    }

    /** POST /hr/documents/{id}/accept — "I have read and accept" on a company document that asks for it. */
    public function accept(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $d = $this->doc($request, $aid, $id);
        abort_unless(! $d->user_id && $d->requires_accept, 422, 'This document does not ask for acceptance.');
        $me = (int) $request->user()->id;
        HrDocs::markRead($id, $me);
        DB::table('hr_document_receipts')->where('document_id', $id)->where('user_id', $me)->whereNull('accepted_at')->update(['accepted_at' => now()]);
        $this->hrNote($aid, 'hr.document_accepted', 'hr_document', $id, []);

        return response()->json(['ok' => true]);
    }

    // ── notes on time off (sick notes) ───────────────────────────────────────────────

    private function requestFor(Request $request, int $aid, int $id): object
    {
        $r = DB::table('time_off_requests')->where('id', $id)->where('agency_id', $aid)->first();
        $me = (int) $request->user()->id;
        abort_unless($r && ((int) $r->user_id === $me || in_array((int) $r->user_id, $this->hrPeople($request, $aid), true)), 404, 'That request was not found.');

        return $r;
    }

    /** POST /hr/time-off/{id}/note — the person (or their manager) attaches a note or photo and the dates it covers. */
    public function attachNote(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $r = $this->requestFor($request, $aid, $id);
        $d = $request->validate(['file' => 'required|' . HrDocs::FILE_RULE, 'covers_from' => 'nullable|date_format:Y-m-d', 'covers_to' => 'nullable|date_format:Y-m-d|after_or_equal:covers_from'],
            ['file.required' => 'Choose a photo or a file.', 'file.mimes' => 'Use a photo, a PDF or a document.', 'file.max' => 'That file is larger than 15 MB.',
             'covers_to.after_or_equal' => 'The last day covered is before the first.']);
        abort_if(DB::table('hr_documents')->where('time_off_request_id', $id)->whereNull('archived_at')->count() >= 5, 422, 'This request already has five notes.');
        $type = HrLeave::typeForRequest($r);
        $sick = $r->request_type === 'sick' || ($type && ($type->maps_request_type ?? null) === 'sick');
        $f = HrDocs::put($aid, $request->file('file'));
        $docId = (int) DB::table('hr_documents')->insertGetId($f + ['agency_id' => $aid, 'user_id' => (int) $r->user_id, 'doc_type' => $sick ? 'sick_note' : 'leave_note',
            'title' => $sick ? 'Sick note' : 'Time-off note', 'visibility' => 'employee', 'time_off_request_id' => $id,
            'covers_from' => $d['covers_from'] ?? substr((string) $r->start_at, 0, 10), 'covers_to' => $d['covers_to'] ?? substr((string) $r->end_at, 0, 10),
            'uploaded_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.time_off_note_added', 'time_off_request', $id, ['document_id' => $docId]);

        return response()->json(['ok' => true, 'id' => $docId], 201);
    }

    /** GET /hr/time-off/notes?ids=1,2 — the notes on requests this caller can see (own, or people they manage). */
    public function notes(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string) $request->query('ids'))))), 0, 300);
        $me = (int) $request->user()->id;
        $people = $this->hrPeople($request, $aid);
        $ok = DB::table('time_off_requests')->where('agency_id', $aid)->whereIn('id', $ids ?: [0])
            ->where(fn ($w) => $w->where('user_id', $me)->orWhereIn('user_id', $people ?: [0]))->pluck('id')->all();
        $rows = DB::table('hr_documents')->where('agency_id', $aid)->whereIn('time_off_request_id', $ok ?: [0])->whereNull('archived_at')->get();
        $out = [];
        foreach ($rows as $d) $out[(int) $d->time_off_request_id][] = HrDocs::shape($d);

        return response()->json(['notes' => (object) $out]);
    }

    // ── a person's documents ──────────────────────────────────────────────────────────

    /** GET /hr/people/{id}/documents */
    public function personDocs(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        $manage = in_array($id, $this->managed($request, $aid), true) && $id !== $me;
        $view = $manage || ($this->fileManagerOf($aid, $id) === $me && in_array($id, HrLeave::staffIds($aid), true));
        abort_unless($view, 404, 'That person was not found.');
        $docs = DB::table('hr_documents')->where('agency_id', $aid)->where('user_id', $id)->whereNull('archived_at')
            ->when(! $manage, fn ($q) => $q->whereNotIn('doc_type', self::NOTE_TYPES))->orderByDesc('created_at')->get();
        $names = $this->names($docs);
        // Files filed on the user record before HR (the signed NDA, admin attachments): linked, never copied.
        $legacy = DB::table('documents')->where('scope_type', 'user')->where('scope_id', $id)->count();

        return response()->json(['documents' => $docs->map(fn ($d) => HrDocs::shape($d, $names))->values(), 'types' => HrDocs::TYPES,
            'can' => ['manage' => $manage], 'user_record_files' => $legacy]);
    }

    /** POST /hr/people/{id}/documents — one or many files (multipart files[]) onto a person's file. */
    public function upload(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->needManaged($request, $aid, $id, true);
        $d = $request->validate(['files' => 'required|array|min:1|max:20', 'files.*' => HrDocs::FILE_RULE,
            'doc_type' => 'required|in:' . implode(',', array_keys(HrDocs::TYPES)), 'title' => 'nullable|string|max:200',
            'visibility' => 'required|in:employee,managers'] + $this->dateRules(),
            ['files.required' => 'Choose at least one file.', 'files.max' => 'Up to 20 files at a time.', 'files.*.mimes' => 'Use a photo, a PDF or an Office document.', 'files.*.max' => 'Each file can be up to 15 MB.']);
        abort_if(in_array($d['doc_type'], self::NOTE_TYPES, true) && ! empty($d['follow_up_note']), 422, 'Sick notes do not take a written note: keep health details out of the file.');
        $files = $request->file('files');
        $ids = [];
        DB::transaction(function () use ($files, $d, $aid, $id, $request, &$ids) {
            foreach ($files as $f) {
                $p = HrDocs::put($aid, $f);
                $ids[] = (int) DB::table('hr_documents')->insertGetId($p + ['agency_id' => $aid, 'user_id' => $id, 'doc_type' => $d['doc_type'],
                    'title' => count($files) === 1 && ! empty($d['title']) ? $d['title'] : HrDocs::titleFrom($p['file_name']),
                    'visibility' => $d['visibility'], 'expires_on' => $d['expires_on'] ?? null, 'follow_up_on' => $d['follow_up_on'] ?? null,
                    'follow_up_note' => $d['follow_up_note'] ?? null, 'uploaded_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->hrNote($aid, 'hr.documents_uploaded', 'user', $id, ['count' => count($ids), 'doc_type' => $d['doc_type'], 'visibility' => $d['visibility']]);

        return response()->json(['ok' => true, 'ids' => $ids], 201);
    }

    /** PUT /hr/documents/{id} — title, type, who sees it, dates. */
    public function update(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $doc = $this->doc($request, $aid, $id, 'manage');
        abort_if($doc->user_id && (int) $doc->user_id === (int) $request->user()->id, 403, 'Someone else has to change your own HR file.');
        $d = $request->validate(['title' => 'required|string|max:200', 'doc_type' => 'required|in:' . implode(',', array_keys(HrDocs::TYPES)),
            'visibility' => 'nullable|in:employee,managers', 'folder_id' => 'nullable|integer', 'requires_accept' => 'nullable|boolean'] + $this->dateRules());
        abort_if(in_array($d['doc_type'], self::NOTE_TYPES, true) && ! empty($d['follow_up_note']), 422, 'Sick notes do not take a written note: keep health details out of the file.');
        $row = ['title' => $d['title'], 'doc_type' => $d['doc_type'], 'expires_on' => $d['expires_on'] ?? null, 'follow_up_on' => $d['follow_up_on'] ?? null,
            'follow_up_note' => $d['follow_up_note'] ?? null, 'updated_at' => now()];
        if ($doc->user_id) {
            $row['visibility'] = $d['visibility'] ?? $doc->visibility;
        } else {
            if (! empty($d['folder_id'])) {
                abort_unless(DB::table('hr_doc_folders')->where('id', $d['folder_id'])->where('agency_id', $aid)->whereNull('archived_at')->exists(), 422, 'Choose one of your folders.');
                $row['folder_id'] = (int) $d['folder_id'];
            }
            $row['requires_accept'] = (bool) ($d['requires_accept'] ?? $doc->requires_accept);
        }
        // A changed date gets its reminders again.
        if ((string) $doc->expires_on !== (string) $row['expires_on']) DB::table('hr_document_alerts')->where('document_id', $id)->where('step_key', 'like', 'x%')->delete();
        if ((string) $doc->follow_up_on !== (string) $row['follow_up_on']) DB::table('hr_document_alerts')->where('document_id', $id)->where('step_key', 'like', 'f%')->delete();
        DB::table('hr_documents')->where('id', $id)->update($row);
        $this->hrNote($aid, 'hr.document_updated', 'hr_document', $id, ['user_id' => $doc->user_id]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/documents/{id}/archive — removed from view; the file is kept (record retention). */
    public function archive(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $doc = $this->doc($request, $aid, $id, 'manage');
        abort_if($doc->user_id && (int) $doc->user_id === (int) $request->user()->id, 403, 'Someone else has to change your own HR file.');
        DB::table('hr_documents')->where('id', $id)->update(['archived_at' => now(), 'archived_by_id' => (int) $request->user()->id, 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.document_archived', 'hr_document', $id, ['user_id' => $doc->user_id, 'title' => $doc->title]);

        return response()->json(['ok' => true]);
    }

    // ── company folders ───────────────────────────────────────────────────────────────

    /** GET /hr/doc-folders — company folders this caller can open; admins also get receipts and who can be chosen. */
    public function folders(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true) || $this->hrIsAdmin($request), 404, 'Not found.');
        $admin = $this->hrIsAdmin($request);
        $company = HrDocs::companyFor($aid, $me, $admin);
        $staff = [];
        if ($admin) {
            $raw = DB::table('hr_doc_folders')->where('agency_id', $aid)->whereNull('archived_at')->get()->keyBy('id');
            foreach ($company as &$f) {
                $aud = HrDocs::audience($aid, $raw[$f['id']]);
                $f['people_ids'] = array_map('intval', (array) (json_decode((string) $raw[$f['id']]->people_ids, true) ?: []));
                $f['audience'] = count($aud);
                $docIds = array_column($f['documents'], 'id');
                $counts = DB::table('hr_document_receipts')->whereIn('document_id', $docIds ?: [0])->whereIn('user_id', $aud ?: [0])
                    ->selectRaw('document_id, SUM(read_at IS NOT NULL) seen, SUM(accepted_at IS NOT NULL) accepted')->groupBy('document_id')->get()->keyBy('document_id');
                foreach ($f['documents'] as &$d) {
                    $d['seen'] = (int) ($counts[$d['id']]->seen ?? 0);
                    $d['accepted'] = (int) ($counts[$d['id']]->accepted ?? 0);
                }
                unset($d);
            }
            unset($f);
            $staff = DB::table('users')->whereIn('id', HrLeave::staffIds($aid) ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $this->hrName($u)])->all();
        }

        return response()->json(['folders' => $company, 'can' => ['manage' => $admin], 'staff' => $staff, 'types' => HrDocs::TYPES]);
    }

    private function folderRow(Request $request, int $aid): array
    {
        $d = $request->validate(['name' => 'required|string|max:120', 'visibility' => 'nullable|in:admins,everyone,people',
            'people_ids' => 'nullable|array|max:500', 'people_ids.*' => 'integer']);
        $vis = $d['visibility'] ?? 'admins';
        $people = array_values(array_intersect(array_map('intval', $d['people_ids'] ?? []), HrLeave::staffIds($aid)));
        abort_if($vis === 'people' && ! $people, 422, 'Choose at least one person, or show it to admins only.');

        return ['name' => trim($d['name']), 'visibility' => $vis, 'people_ids' => $vis === 'people' ? json_encode($people) : null, 'updated_at' => now()];
    }

    /** POST /hr/doc-folders — a new folder starts admins-only unless chosen otherwise. */
    public function createFolder(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $row = $this->folderRow($request, $aid);
        $id = (int) DB::table('hr_doc_folders')->insertGetId($row + ['agency_id' => $aid, 'created_by_id' => (int) $request->user()->id, 'created_at' => now()]);
        $this->hrNote($aid, 'hr.doc_folder_created', 'hr_doc_folder', $id, ['visibility' => $row['visibility']]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** PUT /hr/doc-folders/{id} */
    public function updateFolder(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        abort_unless(DB::table('hr_doc_folders')->where('id', $id)->where('agency_id', $aid)->whereNull('archived_at')->exists(), 404, 'That folder was not found.');
        $row = $this->folderRow($request, $aid);
        DB::table('hr_doc_folders')->where('id', $id)->update($row);
        $this->hrNote($aid, 'hr.doc_folder_updated', 'hr_doc_folder', $id, ['visibility' => $row['visibility']]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/doc-folders/{id}/archive — only an empty folder. */
    public function archiveFolder(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        abort_unless(DB::table('hr_doc_folders')->where('id', $id)->where('agency_id', $aid)->whereNull('archived_at')->exists(), 404, 'That folder was not found.');
        abort_if(DB::table('hr_documents')->where('folder_id', $id)->whereNull('archived_at')->exists(), 422, 'Remove or move the documents in this folder first.');
        DB::table('hr_doc_folders')->where('id', $id)->update(['archived_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.doc_folder_archived', 'hr_doc_folder', $id, []);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/doc-folders/{id}/documents — bulk upload into a company folder. */
    public function uploadCompany(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        abort_unless(DB::table('hr_doc_folders')->where('id', $id)->where('agency_id', $aid)->whereNull('archived_at')->exists(), 404, 'That folder was not found.');
        $d = $request->validate(['files' => 'required|array|min:1|max:20', 'files.*' => HrDocs::FILE_RULE, 'title' => 'nullable|string|max:200',
            'doc_type' => 'nullable|in:' . implode(',', array_keys(HrDocs::TYPES)), 'requires_accept' => 'nullable|boolean'] + $this->dateRules(),
            ['files.required' => 'Choose at least one file.', 'files.max' => 'Up to 20 files at a time.', 'files.*.mimes' => 'Use a PDF, a photo or an Office document.', 'files.*.max' => 'Each file can be up to 15 MB.']);
        $files = $request->file('files');
        $ids = [];
        DB::transaction(function () use ($files, $d, $aid, $id, $request, &$ids) {
            foreach ($files as $f) {
                $p = HrDocs::put($aid, $f);
                $ids[] = (int) DB::table('hr_documents')->insertGetId($p + ['agency_id' => $aid, 'folder_id' => $id, 'doc_type' => $d['doc_type'] ?? 'policy',
                    'title' => count($files) === 1 && ! empty($d['title']) ? $d['title'] : HrDocs::titleFrom($p['file_name']),
                    'requires_accept' => (bool) ($d['requires_accept'] ?? false), 'expires_on' => $d['expires_on'] ?? null, 'follow_up_on' => $d['follow_up_on'] ?? null,
                    'follow_up_note' => $d['follow_up_note'] ?? null, 'uploaded_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->hrNote($aid, 'hr.company_documents_uploaded', 'hr_doc_folder', $id, ['count' => count($ids)]);

        return response()->json(['ok' => true, 'ids' => $ids], 201);
    }

    /** GET /hr/documents/{id}/receipts — who has opened / accepted a company document, and who has not. */
    public function receipts(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $doc = $this->doc($request, $aid, $id, 'manage');
        abort_if((bool) $doc->user_id, 404, 'Not found.');
        $folder = DB::table('hr_doc_folders')->where('id', $doc->folder_id)->first();
        $aud = HrDocs::audience($aid, $folder);
        $rc = DB::table('hr_document_receipts')->where('document_id', $id)->get()->keyBy('user_id');
        $people = DB::table('users')->whereIn('id', $aud ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $this->hrName($u), 'read_at' => $rc[$u->id]->read_at ?? null, 'accepted_at' => $rc[$u->id]->accepted_at ?? null]);

        return response()->json(['document' => HrDocs::shape($doc), 'people' => $people->values()]);
    }

    // ── pay + role history ────────────────────────────────────────────────────────────

    /** GET /hr/people/{id}/history */
    public function history(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->needManaged($request, $aid, $id);
        $pay = $this->canSeePay($request, $aid);
        $cur = HrPay::current($aid, $id);
        if (! $pay) $cur = array_diff_key($cur, array_flip(HrPay::PAY_FIELDS));

        return response()->json(['current' => $cur, 'changes' => HrPay::history($aid, $id, $pay), 'today' => AgencyTime::today($aid),
            'can' => ['pay' => $pay, 'change' => $id !== (int) $request->user()->id && in_array($id, HrLeave::staffIds($aid), true)],
            'employment_types' => HrLeave::EMPLOYMENT, 'pay_types' => HrPay::PAY_TYPES]);
    }

    /** POST /hr/people/{id}/changes — a pay / role / hours change from a date (today or earlier applies now). */
    public function change(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->needManaged($request, $aid, $id, true);
        abort_unless(in_array($id, HrLeave::staffIds($aid), true), 422, 'They have left. Rehire them first.');
        $d = $request->validate(['effective_on' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:255',
            'pay_rate' => 'nullable|numeric|min:0|max:1000000', 'pay_type' => 'nullable|in:' . implode(',', array_keys(HrPay::PAY_TYPES)),
            'job_title' => 'nullable|string|max:120', 'employment_type' => 'nullable|in:' . implode(',', array_keys(HrLeave::EMPLOYMENT)),
            'hours_per_week' => 'nullable|numeric|min:0|max:80'], ['reason.required' => 'Say why (it is kept with the change).']);
        $ch = [];
        foreach (HrPay::FIELDS as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null && $d[$k] !== '') $ch[$k] = in_array($k, ['pay_rate', 'hours_per_week'], true) ? round((float) $d[$k], 2) : $d[$k];
        }
        abort_unless($ch, 422, 'Change at least one thing.');
        abort_if(array_intersect_key($ch, array_flip(HrPay::PAY_FIELDS)) && ! $this->canSeePay($request, $aid), 403, 'You cannot change pay.');
        $cid = (int) DB::table('hr_pay_changes')->insertGetId(['agency_id' => $aid, 'user_id' => $id, 'effective_on' => $d['effective_on'],
            'changes' => json_encode($ch), 'reason' => $d['reason'], 'status' => 'scheduled', 'source' => 'change',
            'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $now = $d['effective_on'] <= AgencyTime::today($aid);
        if ($now) HrPay::apply(DB::table('hr_pay_changes')->where('id', $cid)->first());
        $this->hrNote($aid, $now ? 'hr.pay_change_made' : 'hr.pay_change_scheduled', 'user', $id, ['change_id' => $cid, 'fields' => array_keys($ch), 'effective_on' => $d['effective_on']]);

        return response()->json(['ok' => true, 'id' => $cid, 'applied' => $now], 201);
    }

    /** POST /hr/pay-changes/{id}/cancel — a change that has not applied yet. */
    public function cancelChange(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $row = DB::table('hr_pay_changes')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($row, 404, 'That change was not found.');
        $this->needManaged($request, $aid, (int) $row->user_id, true);
        abort_unless($row->status === 'scheduled', 422, 'That change has already applied. Make a new change to set it back.');
        abort_if(array_intersect_key((array) json_decode((string) $row->changes, true), array_flip(HrPay::PAY_FIELDS)) && ! $this->canSeePay($request, $aid), 403, 'You cannot change pay.');
        DB::table('hr_pay_changes')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_id' => (int) $request->user()->id, 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.pay_change_cancelled', 'user', (int) $row->user_id, ['change_id' => $id]);

        return response()->json(['ok' => true]);
    }

    // ── leaving ───────────────────────────────────────────────────────────────────────

    private function leavingShape(?object $r): ?array
    {
        if (! $r) return null;

        return ['id' => (int) $r->id, 'status' => $r->status, 'last_day' => $r->last_day, 'notice_on' => $r->notice_on, 'reason_code' => $r->reason_code,
            'reason_note' => $r->reason_note, 'employment_start' => $r->employment_start, 'completed_at' => $r->completed_at, 'undone_at' => $r->undone_at,
            'undo_reason' => $r->undo_reason, 'rehired_on' => $r->rehired_on, 'payout' => $r->payout ? json_decode((string) $r->payout, true) : null];
    }

    /** GET /hr/people/{id}/leaving — the leaving pack. */
    public function leaving(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->needManaged($request, $aid, $id);
        $row = HrLeaving::open($aid, $id);
        $pay = $this->canSeePay($request, $aid);
        $u = DB::table('users')->where('id', $id)->first(['id', 'first_name', 'last_name', 'hire_date', 'deleted_at', 'status']);
        $reports = DB::table('hr_employee_files as f')->join('users as u', 'u.id', '=', 'f.user_id')->where('f.agency_id', $aid)->where('f.manager_user_id', $id)
            ->whereIn('f.user_id', HrLeave::staffIds($aid) ?: [0])->get(['u.id', 'u.first_name', 'u.last_name'])->map(fn ($x) => ['id' => (int) $x->id, 'name' => $this->hrName($x)]);
        $past = DB::table('hr_leavings')->where('agency_id', $aid)->where('user_id', $id)->where(fn ($w) => $w->where('status', 'undone')->orWhereNotNull('rehired_on'))
            ->orderByDesc('id')->get()->map(fn ($r) => $this->leavingShape($r));

        return response()->json(['person' => ['id' => $id, 'name' => $this->hrName($u), 'first_name' => $u->first_name ?? '', 'start_date' => $u && $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null,
                'account_closed' => $u && ($u->deleted_at || $u->status === 'deactivated'), 'is_leaver' => in_array($id, HrLeaving::leaverIds($aid), true)],
            'leaving' => $this->leavingShape($row), 'checklist' => HrLeaving::checklist($aid, $id, $row), 'payout' => HrLeaving::payout($aid, $id, $pay),
            'reports' => $reports, 'managers' => $this->hrManagerChoices($aid, $id), 'past' => $past, 'reasons' => HrLeaving::REASONS,
            'can' => ['edit' => $this->hrIsAdmin($request) && $id !== (int) $request->user()->id, 'pay' => $pay, 'offboard' => $this->hrIsAdmin($request)],
            'today' => AgencyTime::today($aid)]);
    }

    /** POST /hr/people/{id}/leaving — start or save the leaving (dates, reason, checklist ticks, payout amounts). Admins. */
    public function saveLeaving(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $this->needManaged($request, $aid, $id, true);
        $d = $request->validate(['last_day' => 'nullable|date_format:Y-m-d', 'notice_on' => 'nullable|date_format:Y-m-d', 'reason_code' => 'nullable|in:' . implode(',', array_keys(HrLeaving::REASONS)),
            'reason_note' => 'nullable|string|max:500', 'checklist' => 'nullable|array', 'checklist.*.done' => 'nullable|boolean', 'checklist.*.note' => 'nullable|string|max:255',
            'payout' => 'nullable|array', 'payout.*.leave_type_id' => 'required_with:payout|integer', 'payout.*.value' => 'nullable|numeric|min:0|max:1000000']);
        $row = HrLeaving::open($aid, $id);
        abort_if($row && $row->status === 'completed' && ! empty($d['last_day']) && $d['last_day'] !== $row->last_day, 422, 'They have already left. Undo the leaving to change the last day.');
        $me = (int) $request->user()->id;
        $names = $this->hrNames([$me]);
        $saved = $row ? (array) (json_decode((string) $row->checklist, true) ?: []) : [];
        foreach ((array) ($d['checklist'] ?? []) as $k => $v) {
            if (! isset(HrLeaving::CHECKLIST[$k]) || in_array($k, ['access', 'reports'], true)) continue;
            $was = (bool) ($saved[$k]['done'] ?? false);
            $done = (bool) ($v['done'] ?? false);
            $saved[$k] = ['done' => $done, 'note' => $v['note'] ?? ($saved[$k]['note'] ?? null),
                'by' => $done && ! $was ? ($names[$me] ?? null) : ($saved[$k]['by'] ?? null), 'at' => $done && ! $was ? now()->toIso8601String() : ($saved[$k]['at'] ?? null)];
        }
        $vals = [];
        foreach ((array) ($d['payout'] ?? []) as $p) $vals[(int) $p['leave_type_id']] = $p['value'] ?? null;
        $calc = HrLeaving::payout($aid, $id, $this->canSeePay($request, $aid));
        if ($vals && $this->canSeePay($request, $aid)) {
            foreach ($calc['lines'] as &$l) if (! empty($l['included']) && array_key_exists($l['leave_type_id'], $vals) && $vals[$l['leave_type_id']] !== null) $l['value'] = round((float) $vals[$l['leave_type_id']], 2);
            unset($l);
            $calc['total'] = round(array_sum(array_map(fn ($l) => ! empty($l['included']) ? (float) $l['value'] : 0.0, $calc['lines'])), 2);
        }
        $vals2 = ['last_day' => $d['last_day'] ?? ($row->last_day ?? null), 'notice_on' => $d['notice_on'] ?? ($row->notice_on ?? null),
            'reason_code' => $d['reason_code'] ?? ($row->reason_code ?? null), 'reason_note' => array_key_exists('reason_note', $d) ? $d['reason_note'] : ($row->reason_note ?? null),
            'checklist' => json_encode($saved), 'payout' => json_encode($calc), 'updated_at' => now()];
        if ($row) {
            DB::table('hr_leavings')->where('id', $row->id)->update($vals2);
            $lid = (int) $row->id;
        } else {
            $hire = DB::table('users')->where('id', $id)->value('hire_date');
            $lid = (int) DB::table('hr_leavings')->insertGetId($vals2 + ['agency_id' => $aid, 'user_id' => $id, 'status' => 'open',
                'employment_start' => $hire ? substr((string) $hire, 0, 10) : null, 'started_by_id' => $me, 'created_at' => now()]);
        }
        // The file shows the planned last day straight away; it turns Terminated when the leaving is completed.
        if ($vals2['last_day'] && (! $row || $row->status === 'open')) {
            $this->fileUpdate($aid, $id, ['end_date' => $vals2['last_day'], 'end_reason' => $this->reasonText($vals2['reason_code'], $vals2['reason_note'])]);
        }
        $this->hrNote($aid, $row ? 'hr.leaving_saved' : 'hr.leaving_started', 'user', $id, ['leaving_id' => $lid, 'last_day' => $vals2['last_day'], 'reason_code' => $vals2['reason_code']]);

        return response()->json(['ok' => true, 'id' => $lid]);
    }

    private function reasonText(?string $code, ?string $note): ?string
    {
        $t = $code ? (HrLeaving::REASONS[$code] ?? null) : null;

        return mb_substr(trim(implode(' - ', array_filter([$t, $note]))), 0, 255) ?: null;
    }

    private function fileUpdate(int $aid, int $id, array $row): void
    {
        $q = DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $id);
        if ($q->exists()) $q->update($row + ['updated_at' => now()]);
        else DB::table('hr_employee_files')->insert($row + ['agency_id' => $aid, 'user_id' => $id, 'status' => $row['status'] ?? 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function leavingRow(Request $request, int $aid, int $leavingId): object
    {
        $r = DB::table('hr_leavings')->where('id', $leavingId)->where('agency_id', $aid)->first();
        abort_unless($r, 404, 'That leaving was not found.');
        $this->hrNeedAdmin($request);
        $this->needManaged($request, $aid, (int) $r->user_id, true);

        return $r;
    }

    /** POST /hr/leavings/{id}/complete — they have left: file Terminated, payout booked, reports re-homed. */
    public function complete(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $r = $this->leavingRow($request, $aid, $id);
        abort_unless($r->status === 'open', 422, 'This leaving is already completed.');
        abort_unless($r->last_day && $r->reason_code, 422, 'Add the last day and the reason first.');
        $d = $request->validate(['pay_out' => 'nullable|boolean', 'new_manager_id' => 'nullable|integer']);
        $uid = (int) $r->user_id;
        if (! empty($d['new_manager_id'])) {
            abort_unless(in_array((int) $d['new_manager_id'], array_column($this->hrManagerChoices($aid, $uid), 'id'), true), 422, 'Choose a manager from this agency.');
        }
        $me = (int) $request->user()->id;
        $booked = 0;
        DB::transaction(function () use ($aid, $r, $d, $uid, $me, &$booked) {
            $payout = (array) (json_decode((string) $r->payout, true) ?: []);
            if (! empty($d['pay_out'])) $booked = HrLeaving::bookPayout($aid, $uid, (int) $r->id, (array) ($payout['lines'] ?? []), $me);
            DB::table('hr_employee_files')->where('agency_id', $aid)->where('manager_user_id', $uid)->update(['manager_user_id' => $d['new_manager_id'] ?? null, 'updated_at' => now()]);
            $this->fileUpdate($aid, $uid, ['status' => 'terminated', 'end_date' => $r->last_day, 'end_reason' => $this->reasonText($r->reason_code, $r->reason_note)]);
            $payout['paid_out'] = ! empty($d['pay_out']);
            DB::table('hr_leavings')->where('id', $r->id)->update(['status' => 'completed', 'completed_at' => now(), 'completed_by_id' => $me, 'payout' => json_encode($payout), 'updated_at' => now()]);
        });
        $this->hrNote($aid, 'hr.leaving_completed', 'user', $uid, ['leaving_id' => (int) $r->id, 'last_day' => $r->last_day, 'reason_code' => $r->reason_code, 'payout_rows' => $booked]);

        return response()->json(['ok' => true, 'payout_rows' => $booked]);
    }

    /** POST /hr/leavings/{id}/undo — a mistaken termination: everything set back, the record kept as "undone". */
    public function undo(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $r = $this->leavingRow($request, $aid, $id);
        abort_unless(in_array($r->status, ['open', 'completed'], true) && ! $r->rehired_on, 422, 'This leaving cannot be undone.');
        $d = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => 'Say why it is being undone.']);
        $uid = (int) $r->user_id;
        $me = (int) $request->user()->id;
        $reopened = $this->reopenAccount($request, $aid, $uid);
        DB::transaction(function () use ($aid, $r, $d, $uid, $me) {
            HrLeaving::reversePayout($aid, (int) $r->id, $me);
            $this->fileUpdate($aid, $uid, ['status' => 'active', 'end_date' => null, 'end_reason' => null]);
            DB::table('hr_leavings')->where('id', $r->id)->update(['status' => 'undone', 'undone_at' => now(), 'undone_by_id' => $me, 'undo_reason' => $d['reason'], 'updated_at' => now()]);
        });
        $this->hrNote($aid, 'hr.leaving_undone', 'user', $uid, ['leaving_id' => (int) $r->id, 'account_reopened' => $reopened, 'reason' => $d['reason']]);

        return response()->json(['ok' => true, 'account_reopened' => $reopened]);
    }

    /** Reopen a closed account through the real endpoint (AdminController::reactivateUser), never a copy of it. */
    private function reopenAccount(Request $request, int $aid, int $uid): ?bool
    {
        $u = DB::table('users')->where('id', $uid)->first(['deleted_at', 'status']);
        if (! $u || (! $u->deleted_at && $u->status !== 'deactivated')) return null;
        $actor = $request->user();
        $sub = Request::create('/hr-reopen', 'POST');
        $sub->setUserResolver(fn () => $actor);
        $sub->headers->set('X-Active-Agency-Id', (string) $aid);
        $resp = app(AdminController::class)->reactivateUser($sub, $uid);
        abort_if($resp->getStatusCode() >= 300, 422, (string) (json_decode((string) $resp->getContent(), true)['message'] ?? 'Their account could not be reopened.'));

        return true;
    }

    /** POST /hr/people/{id}/rehire — a returning employee: the old employment stays on record, a new one starts. */
    public function rehire(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $this->needManaged($request, $aid, $id, true);
        abort_unless(in_array($id, HrLeaving::leaverIds($aid), true), 422, 'They have not left.');
        $d = $request->validate(['start_date' => 'required|date_format:Y-m-d', 'zero_balances' => 'nullable|boolean', 'job_title' => 'nullable|string|max:120'],
            ['start_date.required' => 'Pick their new start date.']);
        $me = (int) $request->user()->id;
        $row = HrLeaving::open($aid, $id);
        $file = HrLeave::file($aid, $id);
        if (! $row) {   // left before the leaving pack existed: keep that employment on record first
            $hire = DB::table('users')->where('id', $id)->value('hire_date');
            $lid = (int) DB::table('hr_leavings')->insertGetId(['agency_id' => $aid, 'user_id' => $id, 'status' => 'completed', 'last_day' => $file->end_date ?? null,
                'reason_note' => $file->end_reason ?? null, 'employment_start' => $hire ? substr((string) $hire, 0, 10) : null, 'checklist' => '{}',
                'started_by_id' => $me, 'completed_at' => now(), 'completed_by_id' => $me, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('hr_leavings')->where('id', $lid)->first();
        }
        abort_if($row->last_day && $d['start_date'] <= $row->last_day, 422, 'The new start date has to be after their last day.');
        $reopened = $this->reopenAccount($request, $aid, $id);
        $zeroed = 0;
        DB::transaction(function () use ($aid, $id, $d, $row, $me, &$zeroed) {
            if (! empty($d['zero_balances'])) $zeroed = HrLeaving::zeroBalances($aid, $id, (int) $row->id, $me);
            $f = ['status' => 'active', 'end_date' => null, 'end_reason' => null];
            if (! empty($d['job_title'])) $f['job_title'] = $d['job_title'];
            $this->fileUpdate($aid, $id, $f);
            DB::table('users')->where('id', $id)->update(['hire_date' => $d['start_date'], 'updated_at' => now()]);
            DB::table('hr_leavings')->where('id', $row->id)->update(['status' => 'completed', 'rehired_on' => $d['start_date'], 'rehired_by_id' => $me, 'updated_at' => now()]);
        });
        $this->hrNote($aid, 'hr.rehired', 'user', $id, ['leaving_id' => (int) $row->id, 'start_date' => $d['start_date'], 'account_reopened' => $reopened, 'balances_zeroed' => $zeroed]);

        return response()->json(['ok' => true, 'account_reopened' => $reopened, 'balances_zeroed' => $zeroed]);
    }

    /** GET /hr/leavers — former staff this caller manages. */
    public function leavers(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $ids = HrLeaving::leaverIds($aid, CentreScope::ids($request, $aid));
        $users = DB::table('users')->whereIn('id', $ids ?: [0])->get(['id', 'first_name', 'last_name', 'photo_url', 'sex', 'hire_date', 'deleted_at', 'status'])->keyBy('id');
        $files = DB::table('hr_employee_files')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->get()->keyBy('user_id');
        $rows = DB::table('hr_leavings')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->whereIn('status', ['open', 'completed'])->whereNull('rehired_on')
            ->orderBy('id')->get()->keyBy('user_id');
        $out = [];
        foreach ($ids as $i) {
            $u = $users[$i] ?? null;
            if (! $u) continue;
            $f = $files[$i] ?? null;
            $l = $rows[$i] ?? null;
            $out[] = ['id' => $i, 'name' => $this->hrName($u), 'photo_url' => $u->photo_url, 'sex' => $u->sex, 'job_title' => $f->job_title ?? null,
                'last_day' => $l->last_day ?? ($f->end_date ?? null), 'reason' => $l && $l->reason_code ? HrLeaving::REASONS[$l->reason_code] ?? null : ($f->end_reason ?? null),
                'leaving_status' => $l->status ?? null, 'account_closed' => (bool) ($u->deleted_at || $u->status === 'deactivated'),
                'start_date' => $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null];
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['last_day'], (string) $a['last_day']));

        return response()->json(['leavers' => $out, 'can' => ['edit' => $this->hrIsAdmin($request), 'export' => $this->hrIsAdmin($request)]]);
    }

    /** GET /hr/people/{id}/roe — the ROE prep sheet (JSON, or ?format=csv). Earnings only for callers who may see pay. */
    public function roe(Request $request, int $id)
    {
        $aid = $this->hrAgency($request);
        $this->needManaged($request, $aid, $id);
        $data = HrLeaving::roe($aid, $id, $this->canSeePay($request, $aid));
        $this->hrNote($aid, 'hr.roe_prepared', 'user', $id, ['format' => $request->query('format') === 'csv' ? 'csv' : 'screen']);
        if ($request->query('format') !== 'csv') return response()->json($data);
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['ROE preparation sheet - not filed with Service Canada']);
        foreach ([['Employee', $data['employee']['name']], ['Employer', $data['employer']], ['First day worked', $data['first_day_worked']], ['Last day for which paid', $data['last_day_paid']],
            ['Final pay period ending', $data['final_period_end']], ['Pay period type', $data['pay_frequency']], ['Reason code', trim(($data['reason_code'] ?? '') . ' ' . ($data['reason_label'] ?? ''))],
            ['Total insurable hours', $data['total_hours']], ['Total insurable earnings', $data['total_gross']], ['Vacation pay paid out on leaving', $data['vacation_paid_out']], ['Source', $data['source']]] as $l) fputcsv($fh, $l);
        fputcsv($fh, []);
        fputcsv($fh, ['Period start', 'Period end', 'Hours', 'Hours from', 'Gross earnings', 'Vacation pay']);
        foreach ($data['periods'] as $p) fputcsv($fh, [$p['period_start'], $p['period_end'], $p['hours'], $p['hours_from'], $p['gross'], $p['vacation_pay']]);
        fputcsv($fh, []);
        fputcsv($fh, [$data['notice']]);
        rewind($fh);
        $csv = stream_get_contents($fh);
        $name = 'ROE-prep-' . preg_replace('/[^A-Za-z0-9]+/', '-', $data['employee']['name']) . '.csv';

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'private, no-store']);
    }

    /** GET /hr/people/{id}/export — "download all their data": one ZIP of their HR records and files. Agency admins; audited. */
    public function export(Request $request, int $id)
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $this->needManaged($request, $aid, $id);
        $u = DB::table('users')->where('id', $id)->first(['id', 'first_name', 'last_name', 'preferred_name', 'email', 'phone', 'hire_date', 'date_of_birth', 'pay_rate', 'pay_type', 'created_at']);
        $tmp = tempnam(sys_get_temp_dir(), 'hrx');
        $zip = new \ZipArchive();
        abort_unless($zip->open($tmp, \ZipArchive::OVERWRITE) === true, 500, 'The download could not be built.');
        $csv = function (string $name, iterable $rows) use ($zip) {
            $fh = fopen('php://temp', 'w+');
            $head = false;
            foreach ($rows as $r) {
                $r = (array) $r;
                if (! $head) { fputcsv($fh, array_keys($r)); $head = true; }
                fputcsv($fh, array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), array_values($r)));
            }
            if (! $head) fputcsv($fh, ['(none)']);
            rewind($fh);
            $zip->addFromString($name, (string) stream_get_contents($fh));
        };
        $prof = DB::table('user_profiles')->where('user_id', $id)->first(['emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation']);
        $payout = DB::table('staff_payout_methods')->where('user_id', $id)->first(['method', 'account_hint', 'interac_email_hint', 'updated_at']);
        $zip->addFromString('person.json', json_encode(['person' => $u, 'employee_file' => HrLeave::file($aid, $id), 'emergency_contact' => $prof,
            'pay_destination' => $payout, 'exported_at' => now()->toIso8601String(), 'agency' => DB::table('agencies')->where('id', $aid)->value('name')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $csv('leave-ledger.csv', DB::table('hr_leave_ledger as l')->leftJoin('hr_leave_types as t', 't.id', '=', 'l.leave_type_id')->where('l.agency_id', $aid)->where('l.user_id', $id)
            ->orderBy('l.entry_date')->get(['l.entry_date', 't.name as leave_type', 'l.amount', 'l.kind', 'l.reason', 'l.created_at']));
        $csv('time-off-requests.csv', DB::table('time_off_requests')->where('agency_id', $aid)->where('user_id', $id)->orderBy('start_at')
            ->get(['id', 'request_type', 'start_at', 'end_at', 'all_day', 'status', 'reason', 'decided_at', 'created_at']));
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('id')->all();
        $csv('time-punches.csv', DB::table('time_punches')->where('user_id', $id)->whereIn('centre_id', $centres ?: [0])->orderBy('punched_in_at')
            ->get(['punched_in_at', 'punched_out_at', 'source', 'out_reason', 'late_minutes', 'correction_note']));
        $csv('pay-and-role-history.csv', array_map(fn ($r) => ['effective_on' => $r['effective_on'], 'status' => $r['status'], 'changes' => $r['changes'], 'before' => $r['before'], 'reason' => $r['reason'], 'by' => $r['by']], HrPay::history($aid, $id, true)));
        $csv('payslips.csv', DB::table('payroll_documents')->where('agency_id', $aid)->where('user_id', $id)->orderBy('period_end')
            ->get(['kind', 'reference', 'period_start', 'period_end', 'units', 'unit_label', 'rate', 'gross', 'vacation_pay', 'net', 'status', 'issued_at', 'paid_at']));
        $csv('certifications.csv', DB::table('staff_certifications')->where('user_id', $id)->where(fn ($w) => $w->where('agency_id', $aid)->orWhereNull('agency_id'))
            ->get(['title', 'cert_type', 'issued_at', 'expires_at', 'verified_at']));
        $csv('leavings.csv', DB::table('hr_leavings')->where('agency_id', $aid)->where('user_id', $id)->get(['status', 'employment_start', 'last_day', 'notice_on', 'reason_code', 'reason_note', 'completed_at', 'undone_at', 'rehired_on']));
        foreach (['hr_overtime_requests' => 'overtime.csv', 'hr_claims' => 'claims.csv', 'hr_reviews' => 'reviews.csv', 'hr_goals' => 'goals.csv'] as $t => $file) {
            if (\Illuminate\Support\Facades\Schema::hasTable($t) && \Illuminate\Support\Facades\Schema::hasColumns($t, ['agency_id', 'user_id'])) {
                $cols = array_values(array_diff(\Illuminate\Support\Facades\Schema::getColumnListing($t), ['receipt_path']));
                $csv($file, DB::table($t)->where('agency_id', $aid)->where('user_id', $id)->get($cols));
            }
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('hr_injuries')) {
            $csv('injuries.csv', DB::table('hr_injuries')->where('agency_id', $aid)->where('user_id', $id)->get(\App\Support\HrInjuries::LIST_COLS));
        }
        $docs = DB::table('hr_documents')->where('agency_id', $aid)->where('user_id', $id)->get();
        $csv('documents.csv', $docs->map(fn ($d) => ['title' => $d->title, 'type' => HrDocs::TYPES[$d->doc_type] ?? $d->doc_type, 'file' => $d->file_name, 'visibility' => $d->visibility,
            'expires_on' => $d->expires_on, 'covers_from' => $d->covers_from, 'covers_to' => $d->covers_to, 'archived' => $d->archived_at ? 'yes' : 'no', 'added' => $d->created_at]));
        $disk = Storage::disk('public');
        foreach ($docs as $d) {
            $rel = ProtectedMedia::relative($d->file_path);
            if ($rel && $disk->exists($rel)) $zip->addFile($disk->path($rel), 'documents/' . $d->id . '-' . (ProtectedMedia::safeName($d->file_name) ?: basename($rel)));
        }
        $zip->addFromString('README.txt', "Everything KiddieTrac HR holds on this person for " . DB::table('agencies')->where('id', $aid)->value('name') . ".\n"
            . "Not included: the SIN (agency admins reveal it one at a time from the employee file) and bank numbers (only the hint is shown).\n"
            . "Injury health details are sealed: reveal each one from HR > Injuries (audited) if the request needs them.\n");
        $zip->close();
        $this->hrNote($aid, 'hr.person_data_exported', 'user', $id, ['documents' => $docs->count()]);
        $name = 'HR-data-' . preg_replace('/[^A-Za-z0-9]+/', '-', trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))) . '.zip';

        return response()->download($tmp, $name, ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
