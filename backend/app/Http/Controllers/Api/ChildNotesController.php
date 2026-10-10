<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NOTES ON A CHILD'S RECORD (2026-10-09, Anthony: "allow educators to add notes to child
 * record and see time/days and can edit if required which will trigger a notification to
 * admins/directors (email as well)" — and "also to home visitors as well").
 *
 * Staff-only notes: never shown to parents. Anyone who can open the child's record (the
 * room-scoped canAccessChildId rule) can read and add; the author edits their own note, and
 * a director or admin can edit any. Every edit keeps the previous text (child_note_revisions),
 * so "Edited" always has a before. Adding AND editing notify the child's centre directors and
 * the agency's admins — a bell and an email — with the before/after on an edit (his choice).
 */
final class ChildNotesController extends Controller
{
    use ResolvesCentreContext;

    private function child(Request $request, int $childId): array
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        abort_unless($child, 404);
        abort_unless($this->canAccessChildId($request->user(), $childId), 403, 'You can only add notes for children in your care.');
        $family = DB::table('families')->where('id', $child->family_id)->first();
        $centre = $family ? DB::table('centres')->where('id', $family->centre_id)->first() : null;
        abort_unless($centre, 404);

        return [$child, $centre, (int) $centre->agency_id];
    }

    private function isManager(int $userId, object $centre): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->where(function ($w) use ($centre) {
                $w->where('role', 'platform_admin')
                  ->orWhere(fn ($a) => $a->where('role', 'agency_admin')->where('agency_id', $centre->agency_id))
                  ->orWhere(fn ($d) => $d->where('role', 'centre_director')->where('centre_id', $centre->id));
            })->exists();
    }

    private function shape(object $n, int $me, bool $manager): array
    {
        return ['id' => (int) $n->id, 'body' => $n->body, 'author_id' => (int) $n->author_id, 'author' => trim(($n->first_name ?? '') . ' ' . ($n->last_name ?? '')) ?: 'Staff',
            'author_role' => $n->author_role, 'created_at' => $n->created_at, 'edited_at' => $n->edited_at, 'edit_count' => (int) $n->edit_count,
            'edited_by' => $n->edited_by_name, 'can_edit' => $manager || (int) $n->author_id === $me];
    }

    private function query()
    {
        return DB::table('child_notes as n')->leftJoin('users as u', 'u.id', '=', 'n.author_id')->leftJoin('users as e', 'e.id', '=', 'n.edited_by')
            ->whereNull('n.deleted_at')
            ->select('n.*', 'u.first_name', 'u.last_name', DB::raw("TRIM(CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,''))) as edited_by_name"));
    }

    /** GET /provider/children/{child}/notes */
    public function index(Request $request, int $childId): JsonResponse
    {
        [$child, $centre] = $this->child($request, $childId);
        $me = (int) $request->user()->id;
        $manager = $this->isManager($me, $centre);
        $rows = $this->query()->where('n.child_id', $childId)->orderByDesc('n.created_at')->orderByDesc('n.id')->limit(200)->get();

        return response()->json(['notes' => $rows->map(fn ($n) => $this->shape($n, $me, $manager))->values(), 'tz' => \App\Support\AgencyTime::tz((int) $centre->agency_id)]);
    }

    /** POST /provider/children/{child}/notes {body} */
    public function store(Request $request, int $childId): JsonResponse
    {
        [$child, $centre, $agencyId] = $this->child($request, $childId);
        $body = trim((string) $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']])['body']);
        $user = $request->user();
        $role = (string) (DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)->orderByRaw("FIELD(role,'educator','home_visitor','centre_director','agency_admin','platform_admin')")->value('role') ?? '');
        $id = DB::table('child_notes')->insertGetId(['agency_id' => $agencyId, 'centre_id' => (int) $centre->id, 'child_id' => $childId, 'author_id' => (int) $user->id,
            'author_role' => $role, 'body' => $body, 'edit_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, $childId, 'child.note_added', 'Added a note to ' . $this->childName($child) . "'s record", ['note_id' => $id]);
        $this->notify($agencyId, $centre, $child, $user, 'added', $body, null, $id);
        $me = (int) $user->id;

        return response()->json(['note' => $this->shape($this->query()->where('n.id', $id)->first(), $me, $this->isManager($me, $centre))]);
    }

    /** PATCH /provider/child-notes/{id} {body} — the author, or a director/admin. */
    public function update(Request $request, int $id): JsonResponse
    {
        $n = DB::table('child_notes')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($n, 404);
        [$child, $centre, $agencyId] = $this->child($request, (int) $n->child_id);
        $user = $request->user();
        $manager = $this->isManager((int) $user->id, $centre);
        abort_unless($manager || (int) $n->author_id === (int) $user->id, 403, 'Only the person who wrote this note, or a director, can edit it.');
        $body = trim((string) $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']])['body']);
        if ($body === $n->body) return response()->json(['note' => $this->shape($this->query()->where('n.id', $id)->first(), (int) $user->id, $manager), 'unchanged' => true]);
        DB::transaction(function () use ($n, $body, $user) {
            DB::table('child_note_revisions')->insert(['note_id' => (int) $n->id, 'body_before' => $n->body, 'body_after' => $body, 'edited_by' => (int) $user->id, 'created_at' => now()]);
            DB::table('child_notes')->where('id', $n->id)->update(['body' => $body, 'edited_at' => now(), 'edited_by' => (int) $user->id, 'edit_count' => DB::raw('edit_count + 1'), 'updated_at' => now()]);
        });
        $this->audit($request, $agencyId, (int) $n->child_id, 'child.note_edited', 'Edited a note on ' . $this->childName($child) . "'s record", ['note_id' => $id, 'before' => mb_substr($n->body, 0, 1000), 'after' => mb_substr($body, 0, 1000)]);
        $this->notify($agencyId, $centre, $child, $user, 'edited', $body, $n->body, $id);

        return response()->json(['note' => $this->shape($this->query()->where('n.id', $id)->first(), (int) $user->id, $manager)]);
    }

    /** GET /provider/child-notes/{id}/history — every earlier version, newest first. */
    public function history(Request $request, int $id): JsonResponse
    {
        $n = DB::table('child_notes')->where('id', $id)->first();
        abort_unless($n, 404);
        $this->child($request, (int) $n->child_id);
        $rows = DB::table('child_note_revisions as r')->leftJoin('users as u', 'u.id', '=', 'r.edited_by')->where('r.note_id', $id)->orderByDesc('r.id')
            ->get(['r.body_before', 'r.body_after', 'r.created_at', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as editor")]);

        return response()->json(['revisions' => $rows]);
    }

    private function childName(object $c): string
    {
        return trim(($c->preferred_name ?: $c->first_name) . ' ' . ($c->last_name ?? ''));
    }

    private function audit(Request $request, int $agencyId, int $childId, string $action, string $summary, array $extra): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'child', 'entity_id' => $childId,
                'payload' => json_encode(['summary' => $summary] + $extra), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    /** The child's centre directors and the agency's admins — bell + email, never the author. */
    private function notify(int $agencyId, object $centre, object $child, $actor, string $kind, string $body, ?string $before, int $noteId): void
    {
        try {
            $people = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', true)->whereNull('u.deleted_at')
                ->where(function ($w) use ($agencyId, $centre) {
                    $w->where(fn ($a) => $a->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId))
                      ->orWhere(fn ($d) => $d->where('ra.role', 'centre_director')->where('ra.centre_id', $centre->id));
                })
                ->where('u.id', '!=', (int) $actor->id)
                ->get(['u.id', 'u.email', 'u.first_name', 'u.last_name'])
                ->reject(fn ($p) => \App\Support\ChatAudience::isServiceEmail($p->email))
                ->unique('id');
            if ($people->isEmpty()) return;
            $actorName = trim($actor->first_name . ' ' . $actor->last_name) ?: 'A staff member';
            $childName = $this->childName($child);
            $title = $kind === 'edited' ? '✏️ ' . $actorName . ' edited a note on ' . $childName . "'s record" : '📝 ' . $actorName . ' added a note to ' . $childName . "'s record";
            $link = '#child-detail?id=' . (int) $child->id . '&tab=notes';
            $rows = [];
            foreach ($people as $p) {
                $rows[] = ['user_id' => (int) $p->id, 'type' => 'child_note', 'title' => $title, 'body' => mb_substr($body, 0, 300),
                    'data' => json_encode(['link' => $link, 'child_id' => (int) $child->id, 'note_id' => $noteId]), 'created_at' => now()];
            }
            \App\Support\Notify::write($rows);

            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $tz = \App\Support\AgencyTime::tz($agencyId);
            $when = now()->setTimezone($tz)->format('D M j, Y g:i A');
            $html = EmailTemplate::wrap($agencyId,
                '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">' . $e($actorName) . ' ' . ($kind === 'edited' ? 'edited a note on' : 'added a note to')
                . ' <b>' . $e($childName) . '</b>\'s record at ' . $e($centre->name) . ' on ' . $e($when) . '.</p>'
                . ($kind === 'edited' && $before !== null
                    ? '<div style="font-size:12px;font-weight:800;color:#64748B;letter-spacing:.05em;text-transform:uppercase;margin:10px 0 4px">Before</div>'
                      . '<div style="background:#FEF2F2;border-left:4px solid #FCA5A5;border-radius:8px;padding:10px 12px;font-size:14px;color:#7F1D1D;line-height:1.55">' . nl2br($e($before)) . '</div>'
                      . '<div style="font-size:12px;font-weight:800;color:#64748B;letter-spacing:.05em;text-transform:uppercase;margin:12px 0 4px">After</div>'
                    : '<div style="font-size:12px;font-weight:800;color:#64748B;letter-spacing:.05em;text-transform:uppercase;margin:10px 0 4px">Note</div>')
                . '<div style="background:#F0FDF4;border-left:4px solid #86EFAC;border-radius:8px;padding:10px 12px;font-size:14px;color:#14532D;line-height:1.55">' . nl2br($e($body)) . '</div>'
                . '<div style="margin:16px 0 0"><a href="https://app.kiddietrac.com/dashboard.html' . $e($link) . '" style="display:inline-block;padding:11px 18px;border-radius:10px;background:#1F6080;color:#fff;text-decoration:none;font-weight:700;font-size:14px">Open ' . $e($childName) . '\'s notes</a></div>'
                . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">Staff notes are never shown to parents.</p>',
                ['eyebrow' => 'CHILD RECORD', 'title' => $kind === 'edited' ? 'A note was edited' : 'A new note', 'subtitle' => $childName, 'preheader' => $title]);
            $subject = ($kind === 'edited' ? 'Note edited: ' : 'New note: ') . $childName . ' (' . $actorName . ')';
            $sent = [];
            foreach ($people as $p) {
                $addr = mb_strtolower(trim((string) $p->email));
                if (isset($sent[$addr]) || ! filter_var($addr, FILTER_VALIDATE_EMAIL) || \App\Support\Suppression::isUser((int) $p->id)) continue;
                $sent[$addr] = true;
                try {
                    AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($p, $subject) { $m->to($p->email, trim($p->first_name . ' ' . $p->last_name) ?: null)->subject($subject); });
                } catch (\Throwable $ex) { Log::warning('child note email failed', ['to' => $p->id, 'error' => $ex->getMessage()]); }
            }
        } catch (\Throwable $e) {
            Log::warning('child note notify failed', ['note' => $noteId, 'error' => $e->getMessage()]);
        }
    }
}
