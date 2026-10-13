<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FILES BETWEEN EDUCATORS AND FAMILIES (2026-10-09, Anthony: "add a send/receive files for
 * educators and parents where they can exchange files such as printables and homework").
 *
 * Staff (educator, home visitor, director, admin) send to the families of the children they
 * can reach — the same room-scoped rule as the child record (canAccessChildId), so an
 * educator with rooms reaches the families in those rooms and nobody else. A parent sees only
 * their own family's files and sends back to their child's provider; those land on the family
 * and are seen by the staff who can reach it. Fail closed everywhere: no access, no row.
 *
 * Files sit on the PRIVATE local disk and are only ever streamed through this controller,
 * after the same check; every download and delete is audited. One upload sent to several
 * families is stored once with a row per family.
 */
final class FamilyFilesController extends Controller
{
    use ResolvesCentreContext;

    private const MAX_KB = 20480;          // 20 MB
    private const MAX_FILES = 10;
    private const EXT = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf'];
    public const SETTINGS_KEY = 'family_files';
    /* Both on by default (2026-10-13, Anthony: "Notifications should be sent out if a file
       was sent or received via email"). Staff were off unless an agency switched it on. */
    public const DEFAULTS = ['email_parents' => true, 'email_staff' => true];

    /* ───────────── who can reach what ───────────── */

    private function staffChildIds(Request $request): array
    {
        $user = $request->user();
        $ids = \App\Support\Visibility::childIds((int) ($this->resolveAgencyId($request) ?: 0), $user);
        if (! $ids) return [];
        $live = Live::enrolledChildren(DB::table('children'))->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
        return array_values(array_filter($live, fn ($cid) => $this->canAccessChildId($user, (int) $cid)));
    }

    private function staffFamilyIds(Request $request): array
    {
        $kids = $this->staffChildIds($request);
        if (! $kids) return [];
        $q = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->whereIn('c.id', $kids);
        Live::families($q, 'f');
        return $q->distinct()->pluck('f.id')->map(fn ($v) => (int) $v)->all();
    }

    private function guardianFamilyIds(int $userId): array
    {
        $q = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->where('g.user_id', $userId);
        Live::families($q, 'f');
        return $q->distinct()->pluck('f.id')->map(fn ($v) => (int) $v)->all();
    }

    private static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $mine = isset($s[self::SETTINGS_KEY]) && is_array($s[self::SETTINGS_KEY]) ? $s[self::SETTINGS_KEY] : [];
        return array_merge(self::DEFAULTS, array_intersect_key($mine, self::DEFAULTS));
    }

    /** Staff who can reach this family (for "a parent sent you a file"). */
    private function staffForFamily(int $familyId, int $exceptUserId): array
    {
        $fam = DB::table('families')->where('id', $familyId)->first();
        if (! $fam) return [];
        $kids = Live::enrolledChildren(DB::table('children'))->where('family_id', $familyId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $rooms = DB::table('enrollments')->whereIn('child_id', $kids ?: [0])->whereNull('end_date')->pluck('room_id')
            ->merge(DB::table('children')->whereIn('id', $kids ?: [0])->pluck('primary_room_id'))->filter()->unique()->all();
        $cands = DB::table('role_assignments')->where('active', true)->where('centre_id', $fam->centre_id)
            ->whereIn('role', ['educator', 'centre_director', 'home_visitor'])->pluck('user_id')
            ->merge(DB::table('educator_rooms')->whereIn('room_id', $rooms ?: [0])->pluck('user_id'))
            ->map(fn ($v) => (int) $v)->unique()->all();
        $out = [];
        foreach ($cands as $uid) {
            if ($uid === $exceptUserId) continue;
            $u = DB::table('users')->where('id', $uid)->whereNull('deleted_at')->first();
            if (! $u || \App\Support\ChatAudience::isServiceEmail($u->email)) continue;
            foreach ($kids as $cid) { if ($this->canAccessChildId($u, $cid)) { $out[] = $uid; break; } }
        }
        return $out;
    }

    /** "Singh family" — but not "Singh Family family" (39 of 128 names already end in it). */
    private static function famLabel(?string $name): string
    {
        $n = trim((string) $name);
        if ($n === '') return 'a family';
        return preg_match('/\bfamily$/i', $n) ? $n : $n . ' family';
    }

    private function name(?object $u): string
    {
        return $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Someone') : 'Someone';
    }

    private function audit(Request $request, int $agencyId, string $action, string $summary, int $id): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'family_file',
                'entity_id' => $id, 'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private function present($rows, int $meId): array
    {
        $users = DB::table('users')->whereIn('id', collect($rows)->pluck('uploaded_by')->unique()->all() ?: [0])->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $fams = DB::table('families')->whereIn('id', collect($rows)->pluck('family_id')->unique()->all() ?: [0])->pluck('family_name', 'id');
        $kids = DB::table('children')->whereIn('id', collect($rows)->pluck('child_id')->filter()->unique()->all() ?: [0])->get(['id', 'first_name', 'preferred_name'])->keyBy('id');
        return collect($rows)->map(fn ($r) => [
            'id' => (int) $r->id, 'direction' => $r->direction, 'title' => $r->title, 'note' => $r->note,
            'name' => $r->original_name, 'mime' => $r->mime, 'size' => (int) $r->size, 'created_at' => $r->created_at,
            'from' => $this->name($users[$r->uploaded_by] ?? null), 'mine' => (int) $r->uploaded_by === $meId,
            'family_id' => (int) $r->family_id, 'family' => $fams[$r->family_id] ?? null,
            'child' => $r->child_id && isset($kids[$r->child_id]) ? ($kids[$r->child_id]->preferred_name ?: $kids[$r->child_id]->first_name) : null,
            'new' => ! $r->first_viewed_at && (int) $r->uploaded_by !== $meId,
        ])->values()->all();
    }

    /* ───────────── staff ───────────── */

    /** GET /provider/files/recipients — families this person can send to, grouped by room. */
    public function recipients(Request $request): JsonResponse
    {
        $kids = $this->staffChildIds($request);
        if (! $kids) return response()->json(['families' => [], 'my_rooms' => []]);
        $rows = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->leftJoin('rooms as r', 'r.id', '=', 'c.primary_room_id')
            ->whereIn('c.id', $kids)->whereNull('f.deleted_at')->whereNull('f.departure_applied_at')
            ->orderBy('f.family_name')->orderBy('c.first_name')
            ->get(['f.id as family_id', 'f.family_name', 'c.id as child_id', 'c.first_name', 'c.preferred_name', 'c.primary_room_id', 'r.name as room']);
        $fams = [];
        foreach ($rows as $r) {
            $fid = (int) $r->family_id;
            if (! isset($fams[$fid])) $fams[$fid] = ['family_id' => $fid, 'family' => $r->family_name, 'children' => [], 'room_ids' => []];
            $fams[$fid]['children'][] = ['id' => (int) $r->child_id, 'name' => $r->preferred_name ?: $r->first_name, 'room' => $r->room];
            if ($r->primary_room_id) $fams[$fid]['room_ids'][] = (int) $r->primary_room_id;
        }
        $myRooms = DB::table('educator_rooms as er')->join('rooms as r', 'r.id', '=', 'er.room_id')->where('er.user_id', $request->user()->id)->get(['r.id', 'r.name']);

        return response()->json(['families' => array_values($fams), 'my_rooms' => $myRooms]);
    }

    /** GET /provider/files?box=received|sent&family_id= */
    public function staffList(Request $request): JsonResponse
    {
        $me = (int) $request->user()->id;
        $fams = $this->staffFamilyIds($request);
        $q = DB::table('family_files')->whereNull('deleted_at')->whereIn('family_id', $fams ?: [0]);
        if ($fid = (int) $request->input('family_id')) $q->where('family_id', $fid);
        /* ALL (2026-10-13, Anthony: "files are not shown for educators like it does for
           parents and also admins/director view not there"). A parent sees every file for
           their family whoever sent it; staff saw only what families sent and what they sent
           themselves, so a file a colleague or the director sent their families was invisible,
           and admins/directors had no view of the exchanges in their scope at all. "all" is
           every file, both ways, for the families this person can reach. */
        $box = (string) $request->input('box');
        if ($box === 'sent') $q->where('uploaded_by', $me);
        elseif ($box !== 'all') $q->where('direction', 'from_family');
        $rows = $q->orderByDesc('id')->limit(300)->get();

        return response()->json(['files' => $this->present($rows, $me), 'unread' => DB::table('family_files')->whereNull('deleted_at')
            ->whereIn('family_id', $fams ?: [0])->where('direction', 'from_family')->whereNull('first_viewed_at')->count()]);
    }

    /** POST /provider/files (multipart: files[], title?, note?, family_ids[] | room_id, child_id?) */
    public function staffSend(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:' . self::MAX_FILES],
            'files.*' => ['file', 'max:' . self::MAX_KB, 'mimes:' . implode(',', self::EXT)],
            'title' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:1000'],
            'family_ids' => ['nullable', 'array', 'max:200'],
            'family_ids.*' => ['integer'],
            'room_id' => ['nullable', 'integer'],
            'child_id' => ['nullable', 'integer'],
        ], ['files.*.mimes' => 'Allowed: PDF, pictures, Word, Excel, PowerPoint, text.', 'files.*.max' => 'Each file can be at most 20 MB.']);
        $allowed = $this->staffFamilyIds($request);
        $target = array_map('intval', (array) $request->input('family_ids', []));
        if ($rid = (int) $request->input('room_id')) {
            // "Everyone in my room": only a room this person is assigned to, only families they can reach.
            abort_unless(DB::table('educator_rooms')->where('user_id', $request->user()->id)->where('room_id', $rid)->exists(), 403, 'That is not one of your rooms.');
            $inRoom = Live::enrolledChildren(DB::table('children'))->where('primary_room_id', $rid)->pluck('family_id')->map(fn ($v) => (int) $v)->all();
            $target = array_merge($target, $inRoom);
        }
        $target = array_values(array_unique(array_intersect($target, $allowed)));
        if (! $target) return response()->json(['message' => 'Choose at least one family you work with.'], 422);
        $childId = (int) $request->input('child_id') ?: null;
        if ($childId && (! $this->canAccessChildId($request->user(), $childId) || count($target) !== 1
            || (int) DB::table('children')->where('id', $childId)->value('family_id') !== $target[0])) {
            $childId = null;
        }

        return response()->json($this->store($request, $target, 'to_family', $childId));
    }

    /* ───────────── parents ───────────── */

    /** GET /parent/files?box=received|sent */
    public function parentList(Request $request): JsonResponse
    {
        $me = (int) $request->user()->id;
        $fams = $this->guardianFamilyIds($me);
        $q = DB::table('family_files')->whereNull('deleted_at')->whereIn('family_id', $fams ?: [0]);
        if ($request->input('box') === 'sent') $q->where('direction', 'from_family');
        else $q->where('direction', 'to_family');
        $rows = $q->orderByDesc('id')->limit(300)->get();

        return response()->json(['files' => $this->present($rows, $me), 'families' => DB::table('families')->whereIn('id', $fams ?: [0])->get(['id', 'family_name']),
            'unread' => DB::table('family_files')->whereNull('deleted_at')->whereIn('family_id', $fams ?: [0])->where('direction', 'to_family')->whereNull('first_viewed_at')->count()]);
    }

    /** POST /parent/files (multipart: files[], title?, note?, family_id?) — to the child's provider. */
    public function parentSend(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:' . self::MAX_FILES],
            'files.*' => ['file', 'max:' . self::MAX_KB, 'mimes:' . implode(',', self::EXT)],
            'title' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:1000'],
            'family_id' => ['nullable', 'integer'],
        ], ['files.*.mimes' => 'Allowed: PDF, pictures, Word, Excel, PowerPoint, text.', 'files.*.max' => 'Each file can be at most 20 MB.']);
        $fams = $this->guardianFamilyIds((int) $request->user()->id);
        $fid = (int) $request->input('family_id') ?: ($fams[0] ?? 0);
        abort_unless($fid && in_array($fid, $fams, true), 403, 'You can only send files for your own family.');

        return response()->json($this->store($request, [$fid], 'from_family', null));
    }

    /* ───────────── both ───────────── */

    private function canSee(Request $request, object $row): bool
    {
        $u = $request->user();
        if (DB::table('guardians')->where('user_id', $u->id)->where('family_id', $row->family_id)->exists()) {
            return in_array((int) $row->family_id, $this->guardianFamilyIds((int) $u->id), true);
        }
        return in_array((int) $row->family_id, $this->staffFamilyIds($request), true);
    }

    /** GET /{provider|parent}/files/{id}/download */
    public function download(Request $request, int $id)
    {
        $row = DB::table('family_files')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($row && $this->canSee($request, $row), 404);
        abort_unless(Storage::disk('local')->exists($row->path), 410, 'This file is no longer available.');
        if (! $row->first_viewed_at && (int) $row->uploaded_by !== (int) $request->user()->id) {
            DB::table('family_files')->where('id', $id)->whereNull('first_viewed_at')->update(['first_viewed_at' => now(), 'first_viewed_by' => $request->user()->id]);
        }
        $this->audit($request, (int) $row->agency_id, 'family_file.downloaded', 'Opened the file "' . $row->original_name . '"', $id);
        $safe = preg_replace('/[^\w.\- ]+/u', '_', $row->original_name) ?: 'file';

        return response()->stream(function () use ($row) {
            $s = Storage::disk('local')->readStream($row->path);
            fpassthru($s);
            if (is_resource($s)) fclose($s);
        }, 200, [
            'Content-Type' => $row->mime ?: 'application/octet-stream',
            'Content-Length' => (string) $row->size,
            'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment') . '; filename="' . $safe . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** DELETE /{provider|parent}/files/{id} — the sender only. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = DB::table('family_files')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($row && $this->canSee($request, $row), 404);
        abort_unless((int) $row->uploaded_by === (int) $request->user()->id, 403, 'Only the person who sent a file can delete it.');
        DB::table('family_files')->where('id', $id)->update(['deleted_at' => now()]);
        // The stored file goes once no live row points at it (a send to several families shares it).
        if (! DB::table('family_files')->where('path', $row->path)->whereNull('deleted_at')->exists()) {
            try { Storage::disk('local')->delete($row->path); } catch (\Throwable $e) {}
        }
        $this->audit($request, (int) $row->agency_id, 'family_file.deleted', 'Deleted the file "' . $row->original_name . '"', $id);

        return response()->json(['ok' => true]);
    }

    /* ───────────── storing + telling people ───────────── */

    private function store(Request $request, array $familyIds, string $direction, ?int $childId): array
    {
        $me = $request->user();
        $batch = (string) Str::uuid();
        $title = trim((string) $request->input('title', ''));
        $note = trim((string) $request->input('note', '')) ?: null;
        $families = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')->whereIn('f.id', $familyIds)
            ->get(['f.id', 'f.family_name', 'f.centre_id', 'c.agency_id', 'c.name as centre_name'])->keyBy('id');
        $saved = [];
        foreach ($request->file('files') as $file) {
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $orig = mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', (string) $file->getClientOriginalName()) ?: ('file.' . $ext), 0, 180);
            $path = $file->storeAs('family-files/' . now()->format('Y/m'), Str::uuid() . '.' . $ext, 'local');
            $saved[] = ['path' => $path, 'name' => $orig, 'mime' => $file->getMimeType() ?: $file->getClientMimeType(), 'size' => (int) $file->getSize(),
                'title' => $title !== '' ? (count($request->file('files')) > 1 ? $title . ' — ' . pathinfo($orig, PATHINFO_FILENAME) : $title) : pathinfo($orig, PATHINFO_FILENAME)];
        }
        $n = 0;
        foreach ($familyIds as $fid) {
            $f = $families[$fid] ?? null;
            if (! $f) continue;
            foreach ($saved as $s) {
                DB::table('family_files')->insert(['agency_id' => (int) $f->agency_id, 'centre_id' => (int) $f->centre_id, 'family_id' => (int) $fid, 'child_id' => $childId,
                    'direction' => $direction, 'uploaded_by' => (int) $me->id, 'batch' => $batch, 'title' => mb_substr($s['title'], 0, 190), 'note' => $note,
                    'path' => $s['path'], 'original_name' => $s['name'], 'mime' => mb_substr((string) $s['mime'], 0, 120), 'size' => $s['size'], 'created_at' => now()]);
                $n++;
            }
            $this->notify($request, $f, $direction, $saved, $note);
        }
        $agencyId = (int) ($families->first()->agency_id ?? 0);
        $this->audit($request, $agencyId, 'family_file.sent', $this->name($me) . ' sent ' . count($saved) . ' file(s) '
            . ($direction === 'to_family' ? 'to ' . count($families) . ' family(ies)' : 'to their provider') . ': ' . implode(', ', array_column($saved, 'name')), 0);

        return ['ok' => true, 'files' => count($saved), 'families' => count($families), 'rows' => $n];
    }

    private function notify(Request $request, object $family, string $direction, array $saved, ?string $note): void
    {
        $me = $request->user();
        $sender = $this->name($me);
        $what = count($saved) === 1 ? '"' . $saved[0]['title'] . '"' : count($saved) . ' files';
        $s = self::settings((int) $family->agency_id);
        if ($direction === 'to_family') {
            $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $family->id)
                ->whereNull('u.deleted_at')->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);
            $mailed = [];
            foreach ($guardians as $g) {
                try {
                    \App\Support\Notify::write(['user_id' => (int) $g->id, 'type' => 'family_file', 'title' => '📎 ' . $sender . ' sent you ' . $what,
                        'body' => $note ? mb_substr($note, 0, 200) : 'Open Files to view or download it.', 'data' => json_encode(['link' => '#files']), 'created_at' => now()]);
                } catch (\Throwable $e) {}
                $email = mb_strtolower(trim((string) $g->email));
                if (empty($s['email_parents']) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($mailed[$email])
                    || \App\Support\ChatAudience::isServiceEmail($email) || \App\Support\Suppression::isUser((int) $g->id)) continue;
                $mailed[$email] = true;
                $this->mail((int) $family->agency_id, $g, $sender, $what, $note, (string) $family->centre_name, true);
            }
        } else {
            foreach ($this->staffForFamily((int) $family->id, (int) $me->id) as $uid) {
                try {
                    \App\Support\Notify::write(['user_id' => $uid, 'type' => 'family_file', 'title' => '📎 The ' . self::famLabel($family->family_name) . ' sent ' . $what,
                        'body' => $note ? mb_substr($note, 0, 200) : 'From ' . $sender . '. Open Files to view it.', 'data' => json_encode(['link' => '#files']), 'created_at' => now()]);
                } catch (\Throwable $e) {}
                if (empty($s['email_staff']) || \App\Support\Suppression::isUser($uid)) continue;
                $u = DB::table('users')->where('id', $uid)->first(['id', 'email', 'first_name', 'last_name']);
                if ($u && filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)) $this->mail((int) $family->agency_id, $u, $sender . ' (' . self::famLabel($family->family_name) . ')', $what, $note, (string) $family->centre_name, false);
            }
        }
    }

    /** "You have a new file" — never the file itself. */
    private function mail(int $agencyId, object $to, string $sender, string $what, ?string $note, string $centre, bool $toParent): void
    {
        try {
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $html = EmailTemplate::wrap($agencyId,
                '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">Hi ' . $e($to->first_name ?: 'there') . ',</p>'
                . '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6"><b>' . $e($sender) . '</b> sent you ' . $e($what) . ' in KiddieTrac.</p>'
                . ($note ? '<div style="background:#F1F5F9;border-radius:10px;padding:12px 14px;margin:0 0 12px;font-size:14.5px;color:#0F172A;line-height:1.55">' . nl2br($e($note)) . '</div>' : '')
                . '<div style="margin:6px 0 4px"><a href="https://app.kiddietrac.com/dashboard.html#files" style="display:inline-block;padding:11px 18px;border-radius:10px;background:#1F6080;color:#fff;text-decoration:none;font-weight:700;font-size:14px">Open Files</a></div>'
                . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">For your privacy, files are kept in KiddieTrac and are not attached to emails.</p>',
                ['eyebrow' => 'NEW FILE', 'title' => $sender . ' sent you a file', 'subtitle' => $centre, 'preheader' => $sender . ' sent you ' . $what]);
            $subject = '📎 ' . $sender . ' sent you ' . $what;
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $subject) {
                $m->to($to->email, trim(($to->first_name ?? '') . ' ' . ($to->last_name ?? '')) ?: null)->subject($subject);
            });
        } catch (\Throwable $ex) {
            Log::warning('family file email failed', ['user' => $to->id ?? null, 'error' => $ex->getMessage()]);
        }
    }

    /* ───────────── agency setting ───────────── */

    public function showSettings(Request $request): JsonResponse
    {
        $a = (int) ($this->resolveAgencyId($request) ?: 0);
        abort_unless($a, 403);
        return response()->json(['settings' => self::settings($a)]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $a = (int) ($this->resolveAgencyId($request) ?: 0);
        abort_unless($a && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where(fn ($q) => $q->where('role', 'platform_admin')->orWhere(fn ($w) => $w->where('role', 'agency_admin')->where('agency_id', $a)))->exists(), 403, 'Agency administrators only.');
        $d = $request->validate(['email_parents' => ['sometimes', 'boolean'], 'email_staff' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($a, $d) {
            $raw = DB::table('agencies')->where('id', $a)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $cur = array_merge(self::DEFAULTS, $s[self::SETTINGS_KEY] ?? []);
            foreach ($d as $k => $v) $cur[$k] = (bool) $v;
            $s[self::SETTINGS_KEY] = array_intersect_key($cur, self::DEFAULTS);
            DB::table('agencies')->where('id', $a)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        $this->audit($request, $a, 'family_file.settings', 'File-sharing email settings changed: ' . json_encode($d), 0);

        return response()->json(['settings' => self::settings($a)]);
    }
}
