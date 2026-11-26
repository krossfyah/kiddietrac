<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\QuickNotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quick notes (2026-11-26). The rules are App\Support\QuickNotes.
 *
 *   GET    /quick-notes?tab=mine|agency&q=&linked_type=&linked_id=   list (pinned first, newest first)
 *   GET    /quick-notes/{id}                                         one note
 *   GET    /quick-notes/reminders?from=Y-m-d&to=Y-m-d                 notes with a reminder date in the range
 *   POST   /quick-notes                                              create
 *   PATCH  /quick-notes/{id}                                         change (title, body, colour, pinned, centre, link, reminder)
 *   DELETE /quick-notes/{id}                                         delete (soft)
 *   GET    /quick-notes/{id}/history                                 who changed what
 *   GET    /quick-notes/link-search?type=child|family|staff&q=       records this person may link a note to
 */
final class QuickNotesController extends Controller
{
    private const FIELDS = ['title', 'body', 'colour', 'pinned', 'centre_id', 'linked_type', 'linked_id', 'remind_on', 'visibility'];

    public function index(Request $request): JsonResponse
    {
        $ctx = QuickNotes::ctx($request);
        $q = QuickNotes::scope(DB::table('quick_notes as n'), $ctx, 'n.');
        $tab = (string) $request->query('tab', '');
        if ($tab === 'mine') $q->where('n.visibility', 'private');
        elseif ($tab === 'agency') $q->where('n.visibility', 'agency');

        $lt = (string) $request->query('linked_type', '');
        if ($lt !== '') {
            // A record's notes: only when this person may see that record at all.
            if (! QuickNotes::record($request, $ctx, $lt, $request->query('linked_id'))) {
                return response()->json(['notes' => [], 'context' => $this->context($ctx)]);
            }
            $q->where('n.linked_type', $lt)->where('n.linked_id', (int) $request->query('linked_id'));
        }
        $s = trim((string) $request->query('q', ''));
        if ($s !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($s, 0, 80)) . '%';
            $q->where(fn ($w) => $w->where('n.title', 'like', $like)->orWhere('n.body', 'like', $like));
        }
        $rows = $q->orderByDesc('n.pinned')->orderByDesc('n.updated_at')->limit(300)->get();

        return response()->json(['notes' => $this->present($request, $ctx, $rows), 'context' => $this->context($ctx)]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $ctx = QuickNotes::ctx($request);
        return response()->json(['note' => $this->present($request, $ctx, collect([QuickNotes::find($request, $id)]))[0], 'context' => $this->context($ctx)]);
    }

    public function reminders(Request $request): JsonResponse
    {
        $ctx = QuickNotes::ctx($request);
        $from = $this->date($request->query('from')) ?: AgencyTime::today($ctx['agency']);
        $to = $this->date($request->query('to')) ?: $from;
        if ($to < $from) [$from, $to] = [$to, $from];
        $rows = QuickNotes::scope(DB::table('quick_notes as n'), $ctx, 'n.')
            ->whereBetween('n.remind_on', [$from, $to])->orderBy('n.remind_on')->limit(200)->get();

        return response()->json(['notes' => $this->present($request, $ctx, $rows)]);
    }

    public function store(Request $request): JsonResponse
    {
        $ctx = QuickNotes::ctx($request);
        $v = $this->validated($request, true);
        $vis = ($v['visibility'] ?? 'private') === 'agency' ? 'agency' : 'private';
        abort_if($vis === 'agency' && ! $ctx['agency'], 422, 'Pick an agency first to share a note with it.');
        abort_if(trim((string) ($v['title'] ?? '')) === '' && trim((string) ($v['body'] ?? '')) === '', 422, 'Write something first.');

        $row = [
            'agency_id' => $ctx['agency'], 'owner_user_id' => $ctx['uid'], 'visibility' => $vis,
            'title' => $this->clean($v['title'] ?? null, 160), 'body' => $this->clean($v['body'] ?? null, 5000),
            'colour' => $v['colour'] ?? null, 'pinned' => ! empty($v['pinned']), 'remind_on' => $v['remind_on'] ?? null,
            'centre_id' => null, 'linked_type' => null, 'linked_id' => null,
            'updated_by' => $ctx['uid'], 'created_at' => now(), 'updated_at' => now(),
        ];
        $this->applyPlace($request, $ctx, $row, $v, $vis);
        $id = DB::table('quick_notes')->insertGetId($row);
        $this->revision($id, $ctx['uid'], 'created', null);
        $this->audit($ctx, 'quick_note.created', $id, $vis, []);

        return response()->json(['note' => $this->present($request, $ctx, collect([QuickNotes::find($request, $id)]))[0]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $ctx = QuickNotes::ctx($request);
        $n = QuickNotes::find($request, $id);
        $v = $this->validated($request, false);
        $row = [];
        foreach (['title' => 160, 'body' => 5000] as $f => $max) {
            if (array_key_exists($f, $v)) $row[$f] = $this->clean($v[$f], $max);
        }
        foreach (['colour', 'remind_on'] as $f) if (array_key_exists($f, $v)) $row[$f] = $v[$f] ?: null;
        if (array_key_exists('pinned', $v)) $row['pinned'] = ! empty($v['pinned']);
        // Sharing a private note (owner only); an agency note never goes back to private (others may rely on it).
        $vis = $n->visibility;
        if (array_key_exists('visibility', $v) && $v['visibility'] !== $vis) {
            abort_unless($vis === 'private' && $v['visibility'] === 'agency' && (int) $n->owner_user_id === $ctx['uid'], 422, 'A shared note stays shared.');
            abort_unless($ctx['agency'] && (! $n->agency_id || (int) $n->agency_id === $ctx['agency']), 422, 'Pick an agency first to share a note with it.');
            $vis = 'agency'; $row['visibility'] = 'agency'; $row['agency_id'] = $ctx['agency'];
        }
        if (array_key_exists('centre_id', $v) || array_key_exists('linked_type', $v) || array_key_exists('linked_id', $v) || isset($row['visibility'])) {
            $place = ['centre_id' => $n->centre_id, 'linked_type' => $n->linked_type, 'linked_id' => $n->linked_id];
            $vv = $v + ['centre_id' => $n->centre_id, 'linked_type' => $n->linked_type, 'linked_id' => $n->linked_id];
            $this->applyPlace($request, $ctx, $place, $vv, $vis);
            $row += $place;
        }
        $merged = array_merge((array) $n, $row);
        abort_if(trim((string) ($merged['title'] ?? '')) === '' && trim((string) ($merged['body'] ?? '')) === '', 422, 'A note needs some words.');

        $changes = [];
        foreach ($row as $f => $new) {
            $old = $n->{$f} ?? null;
            if ((string) $old !== (string) (is_bool($new) ? (int) $new : $new)) $changes[$f] = [$old, is_bool($new) ? (int) $new : $new];
        }
        if (! $changes) return response()->json(['note' => $this->present($request, $ctx, collect([$n]))[0], 'unchanged' => true]);
        $row['updated_by'] = $ctx['uid']; $row['updated_at'] = now();
        DB::table('quick_notes')->where('id', $id)->update($row);
        $this->revision($id, $ctx['uid'], 'updated', $changes);
        $this->audit($ctx, 'quick_note.updated', $id, $vis, array_keys($changes));

        return response()->json(['note' => $this->present($request, $ctx, collect([QuickNotes::find($request, $id)]))[0]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $ctx = QuickNotes::ctx($request);
        $n = QuickNotes::find($request, $id);
        abort_unless(QuickNotes::canDelete($ctx, $n), 403, 'Only its author, an admin or the centre director can delete a shared note.');
        DB::table('quick_notes')->where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $ctx['uid']]);
        $this->revision($id, $ctx['uid'], 'deleted', null);
        $this->audit($ctx, 'quick_note.deleted', $id, $n->visibility, []);

        return response()->json(['ok' => true]);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $ctx = QuickNotes::ctx($request);
        $n = QuickNotes::find($request, $id);
        $tz = AgencyTime::tz($ctx['agency']);
        $rows = DB::table('quick_note_revisions as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.note_id', $n->id)->orderByDesc('r.id')->limit(100)
            ->get(['r.action', 'r.changes', 'r.created_at', 'u.first_name', 'u.last_name']);
        $out = $rows->map(function ($r) use ($tz) {
            $ch = json_decode((string) $r->changes, true) ?: [];
            return [
                'action' => $r->action,
                'who' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Someone',
                'at' => $r->created_at ? \Carbon\Carbon::parse($r->created_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i') : null,
                'fields' => array_keys($ch),
                'changes' => array_map(fn ($p) => ['from' => $p[0] ?? null, 'to' => $p[1] ?? null], $ch),
            ];
        })->values();

        return response()->json(['history' => $out]);
    }

    /** Records to link a note to, within what this person may see. */
    public function linkSearch(Request $request): JsonResponse
    {
        $ctx = QuickNotes::ctx($request);
        $type = (string) $request->query('type', 'child');
        $s = trim((string) $request->query('q', ''));
        if (! $ctx['agency'] || ! in_array($type, QuickNotes::LINKS, true) || mb_strlen($s) < 2) return response()->json(['results' => []]);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($s, 0, 60)) . '%';
        $aid = $ctx['agency'];
        if ($type === 'child') {
            $ids = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
                ->where('ce.agency_id', $aid)->whereIn('f.centre_id', $ctx['centres'] ?: [0])->where(fn ($q) => \App\Support\Live::children($q, 'c'))
                ->where(fn ($w) => $w->where('c.first_name', 'like', $like)->orWhere('c.last_name', 'like', $like)->orWhere('c.preferred_name', 'like', $like))
                ->limit(40)->pluck('c.id');
        } elseif ($type === 'family') {
            $ids = DB::table('families as f')->whereIn('f.centre_id', $ctx['centres'] ?: [0])->where(fn ($q) => \App\Support\Live::families($q, 'f'))
                ->where('f.family_name', 'like', $like)->limit(40)->pluck('f.id');
        } else {
            $centreIds = \App\Support\AgencyRole::centreIds($aid);
            $ids = DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')->where('ra.active', true)
                ->whereIn('ra.role', QuickNotes::STAFF)->where(fn ($q) => $q->where('ra.agency_id', $aid)->orWhereIn('ra.centre_id', $centreIds ?: [0]))
                ->whereNull('u.deleted_at')->where(fn ($w) => $w->where('u.first_name', 'like', $like)->orWhere('u.last_name', 'like', $like))
                // Directors, educators, home visitors: only the people of their centres, BEFORE the limit (QuickNotes::record agrees).
                ->when(! $ctx['wide'], fn ($q) => $q->whereIn('u.id', \App\Support\CentreScope::peopleIds($ctx['centres']) ?: [0]))
                ->distinct()->limit(40)->pluck('u.id');
        }
        $out = [];
        foreach ($ids as $id) {
            $r = QuickNotes::record($request, $ctx, $type, $id);
            if ($r) $out[] = ['type' => $type, 'id' => (int) $id, 'label' => $r[0]];
            if (count($out) >= 12) break;
        }
        return response()->json(['results' => $out]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function context(array $ctx): array
    {
        $centres = $ctx['agency'] ? DB::table('centres')->whereIn('id', $ctx['centres'] ?: [0])->orderBy('name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->values()->all() : [];
        return [
            'agency_id' => $ctx['agency'], 'can_share' => (bool) $ctx['agency'], 'centres' => $centres,
            'today' => AgencyTime::today($ctx['agency']), 'colours' => QuickNotes::COLOURS,
        ];
    }

    private function validated(Request $request, bool $create): array
    {
        $v = $request->validate([
            'title' => 'nullable|string|max:160',
            'body' => 'nullable|string|max:5000',
            'colour' => 'nullable|string|in:' . implode(',', QuickNotes::COLOURS),
            'pinned' => 'nullable|boolean',
            'visibility' => 'nullable|string|in:private,agency',
            'centre_id' => 'nullable|integer',
            'linked_type' => 'nullable|string|in:' . implode(',', QuickNotes::LINKS),
            'linked_id' => 'nullable|integer',
            'remind_on' => 'nullable|date_format:Y-m-d',
        ]);
        return array_intersect_key($v, array_flip(self::FIELDS));
    }

    /** Centre and link, checked against what this person may see. Linking a shared note to a child or family puts it at
        that record's centre, so only the staff of that centre see it. */
    private function applyPlace(Request $request, array $ctx, array &$row, array $v, string $vis): void
    {
        $lt = $v['linked_type'] ?? null; $lid = $v['linked_id'] ?? null;
        if ($lt && $lid) {
            $rec = QuickNotes::record($request, $ctx, $lt, $lid);
            abort_unless($rec, 422, 'You can only link a note to someone you can see.');
            $row['linked_type'] = $lt; $row['linked_id'] = (int) $lid;
            if ($vis === 'agency' && $rec[1]) $v['centre_id'] = $rec[1];
        } else {
            $row['linked_type'] = null; $row['linked_id'] = null;
        }
        $cid = $v['centre_id'] ?? null;
        if ($vis !== 'agency') { $row['centre_id'] = null; return; }
        if ($cid) {
            abort_unless(in_array((int) $cid, $ctx['centres'], true), 422, 'Pick one of your centres.');
            $row['centre_id'] = (int) $cid;
        } else {
            $row['centre_id'] = null;
        }
    }

    private function present(Request $request, array $ctx, $rows): array
    {
        $rows = collect($rows);
        $users = DB::table('users')->whereIn('id', $rows->pluck('owner_user_id')->merge($rows->pluck('updated_by'))->filter()->unique()->all() ?: [0])
            ->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $centres = DB::table('centres')->whereIn('id', $rows->pluck('centre_id')->filter()->unique()->all() ?: [0])->pluck('name', 'id');
        $tz = AgencyTime::tz($ctx['agency']);
        $name = fn ($id) => ($u = $users->get($id)) ? trim($u->first_name . ' ' . $u->last_name) : null;
        $local = fn ($t) => $t ? \Carbon\Carbon::parse($t, 'UTC')->setTimezone($tz)->format('Y-m-d H:i') : null;

        return $rows->map(function ($n) use ($request, $ctx, $name, $centres, $local) {
            $link = null;
            if ($n->linked_type && $n->linked_id) {
                $rec = QuickNotes::record($request, $ctx, $n->linked_type, $n->linked_id);
                $link = $rec ? ['type' => $n->linked_type, 'id' => (int) $n->linked_id, 'label' => $rec[0]] : ['type' => $n->linked_type, 'id' => null, 'label' => null];
            }
            return [
                'id' => (int) $n->id, 'visibility' => $n->visibility, 'title' => $n->title, 'body' => $n->body,
                'colour' => $n->colour, 'pinned' => (bool) $n->pinned, 'remind_on' => $n->remind_on,
                'centre_id' => $n->centre_id ? (int) $n->centre_id : null, 'centre_name' => $n->centre_id ? ($centres[$n->centre_id] ?? null) : null,
                'link' => $link, 'mine' => (int) $n->owner_user_id === $ctx['uid'],
                'author' => $name($n->owner_user_id), 'updated_by' => $name($n->updated_by),
                'created_local' => $local($n->created_at), 'updated_local' => $local($n->updated_at),
                'can_delete' => QuickNotes::canDelete($ctx, $n),
            ];
        })->values()->all();
    }

    private function clean($s, int $max): ?string
    {
        if ($s === null) return null;
        $s = trim(str_replace("\r\n", "\n", (string) $s));
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function date($s): ?string
    {
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    private function revision(int $noteId, int $uid, string $action, ?array $changes): void
    {
        DB::table('quick_note_revisions')->insert(['note_id' => $noteId, 'user_id' => $uid, 'action' => $action,
            'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null, 'created_at' => now()]);
    }

    /** Every change is a NAMED audit entry: which fields changed, never the words. AuditActivity skips quick-notes
        (it would copy the request body - a private note's text - into the agency's log), so this is the record. */
    private function audit(array $ctx, string $action, int $id, string $vis, array $fields): void
    {
        try {
            Audit::write(['user_id' => $ctx['uid'], 'agency_id' => $ctx['agency'], 'action' => $action,
                'entity_type' => 'quick_note', 'entity_id' => $id, 'payload' => json_encode(['visibility' => $vis, 'fields' => $fields])]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
