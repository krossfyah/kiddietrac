<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CHAT CONVERSATIONS IN THE AUDIT LOG (2026-10-09, Anthony: "add to audit log a subtab for
 * chat conversations that tracks all conversations with users etc").
 *
 * Every conversation in the ACTIVE agency, family (parent <-> staff) and staff-to-staff,
 * with its full transcript — deleted and edited messages included and flagged, because an
 * audit record that silently drops what somebody took back is not a record.
 *
 * Who: platform admins and agency admins of the active agency only (his choice). Centre
 * directors reach the rest of the Audit log but not this — they are a party to many of
 * these threads. Fails closed: no resolvable agency, no rows.
 *
 * Reading somebody's transcript is itself audited (chat.transcript_viewed), so the people
 * who watch the conversations are on the record too.
 */
final class ChatAuditController extends Controller
{
    /** The agency this admin may audit, or null. Same rule as AdminController::getAgencyId. */
    private function agencyId(Request $request): ?int
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        if (\App\Support\UserRoles::has($request, 'platform_admin')) {
            return ($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists()) ? $active : null;
        }
        $admin = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'agency_admin')->where('active', true);
        if ($active && (clone $admin)->where('agency_id', $active)->exists()) return $active;
        $id = (int) ($admin->value('agency_id') ?: 0);
        return $id ?: null;
    }

    private static function name(?object $u): string
    {
        if (! $u) return 'Unknown';
        $n = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        return $n !== '' ? $n : (string) ($u->email ?? 'Unknown');
    }

    /** GET /admin/chat-audit?kind=&q=&since=&until=&limit=&offset= */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        if (! $agencyId) return response()->json(['message' => 'Chat conversations are for agency admins.', 'conversations' => [], 'total' => 0], 403);

        $kind  = (string) $request->input('kind', '');
        $q     = trim((string) $request->input('q', ''));
        $since = (string) $request->input('since', '');
        $until = (string) $request->input('until', '');
        $limit = min(200, max(1, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $tz = \App\Support\AgencyTime::tz($agencyId);
        // From/To are agency-local dates; compare against stored UTC.
        $sinceUtc = $since !== '' ? \Illuminate\Support\Carbon::parse($since . ' 00:00:00', $tz)->utc() : null;
        $untilUtc = $until !== '' ? \Illuminate\Support\Carbon::parse($until . ' 23:59:59', $tz)->utc() : null;
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        $rows = [];

        if ($kind === '' || $kind === 'family') {
            $fam = DB::table('conversations as c')
                ->join('centres as ce', 'ce.id', '=', 'c.centre_id')
                ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
                ->leftJoin('children as ch', 'ch.id', '=', 'c.child_id')
                ->where('ce.agency_id', $agencyId)
                ->select('c.id', 'c.subject', 'c.channel', 'c.family_id', 'c.last_message_at', 'c.created_at', 'c.deleted_at',
                    'ce.name as centre_name', 'f.family_name', 'ch.first_name as child_first', 'ch.last_name as child_last');
            if ($sinceUtc) $fam->where('c.last_message_at', '>=', $sinceUtc);
            if ($untilUtc) $fam->where('c.created_at', '<=', $untilUtc);
            if ($q !== '') {
                $fam->where(function ($w) use ($like) {
                    $w->where('c.subject', 'like', $like)->orWhere('f.family_name', 'like', $like)->orWhere('ce.name', 'like', $like)
                      ->orWhere('ch.first_name', 'like', $like)->orWhere('ch.last_name', 'like', $like)
                      ->orWhereExists(function ($s) use ($like) {
                          $s->from('messages as m')->whereColumn('m.conversation_id', 'c.id')->where('m.body', 'like', $like);
                      })
                      ->orWhereExists(function ($s) use ($like) {
                          $s->from('messages as m')->join('users as u', 'u.id', '=', 'm.sender_id')->whereColumn('m.conversation_id', 'c.id')
                            ->where(function ($x) use ($like) { $x->where('u.first_name', 'like', $like)->orWhere('u.last_name', 'like', $like)->orWhere('u.email', 'like', $like); });
                      });
                });
            }
            foreach ($fam->get() as $c) {
                $rows[] = ['kind' => 'family', 'id' => (int) $c->id, 'c' => $c, 'last' => $c->last_message_at ?: $c->created_at];
            }
        }

        if ($kind === '' || $kind === 'staff') {
            $st = DB::table('staff_threads as t')->where('t.agency_id', $agencyId)
                ->select('t.id', 't.title', 't.is_group', 't.created_by', 't.last_message_at', 't.created_at');
            if ($sinceUtc) $st->where('t.last_message_at', '>=', $sinceUtc);
            if ($untilUtc) $st->where('t.created_at', '<=', $untilUtc);
            if ($q !== '') {
                $st->where(function ($w) use ($like) {
                    $w->where('t.title', 'like', $like)
                      ->orWhereExists(function ($s) use ($like) {
                          $s->from('staff_messages as m')->whereColumn('m.thread_id', 't.id')->where('m.body', 'like', $like);
                      })
                      ->orWhereExists(function ($s) use ($like) {
                          $s->from('staff_thread_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')->whereColumn('p.thread_id', 't.id')
                            ->where(function ($x) use ($like) { $x->where('u.first_name', 'like', $like)->orWhere('u.last_name', 'like', $like)->orWhere('u.email', 'like', $like); });
                      });
                });
            }
            foreach ($st->get() as $t) {
                $rows[] = ['kind' => 'staff', 'id' => (int) $t->id, 'c' => $t, 'last' => $t->last_message_at ?: $t->created_at];
            }
        }

        usort($rows, fn ($a, $b) => strcmp((string) $b['last'], (string) $a['last']) ?: ($b['id'] <=> $a['id']));
        $total = count($rows);
        $page = array_slice($rows, $offset, $limit);

        // Counts and people for this page only.
        $famIds = array_values(array_map(fn ($r) => $r['id'], array_filter($page, fn ($r) => $r['kind'] === 'family')));
        $stIds  = array_values(array_map(fn ($r) => $r['id'], array_filter($page, fn ($r) => $r['kind'] === 'staff')));
        $famCounts = $famIds ? DB::table('messages')->whereIn('conversation_id', $famIds)
            ->selectRaw('conversation_id as id, COUNT(*) as n, SUM(deleted_at IS NOT NULL) as del')->groupBy('conversation_id')->get()->keyBy('id') : collect();
        $stCounts = $stIds ? DB::table('staff_messages')->whereIn('thread_id', $stIds)
            ->selectRaw('thread_id as id, COUNT(*) as n, 0 as del')->groupBy('thread_id')->get()->keyBy('id') : collect();
        $famSenders = $famIds ? DB::table('messages as m')->join('users as u', 'u.id', '=', 'm.sender_id')->whereIn('m.conversation_id', $famIds)
            ->select('m.conversation_id as cid', 'u.id', 'u.first_name', 'u.last_name', 'u.email')->distinct()->get()->groupBy('cid') : collect();
        $famIdsOfConv = array_values(array_unique(array_filter(array_map(fn ($r) => $r['kind'] === 'family' ? (int) $r['c']->family_id : 0, $page))));
        $guardians = $famIdsOfConv ? DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->whereIn('g.family_id', $famIdsOfConv)
            ->select('g.family_id', 'u.id', 'u.first_name', 'u.last_name', 'u.email')->get()->groupBy('family_id') : collect();
        $stPeople = $stIds ? DB::table('staff_thread_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')->whereIn('p.thread_id', $stIds)
            ->select('p.thread_id as cid', 'u.id', 'u.first_name', 'u.last_name', 'u.email')->get()->groupBy('cid') : collect();

        $out = [];
        foreach ($page as $r) {
            $c = $r['c'];
            if ($r['kind'] === 'family') {
                $people = [];
                foreach (($guardians[$c->family_id] ?? []) as $u) $people[$u->id] = self::name($u);
                foreach (($famSenders[$c->id] ?? []) as $u) $people[$u->id] = self::name($u);
                $child = trim(($c->child_first ?? '') . ' ' . ($c->child_last ?? ''));
                $title = ($c->family_name ? $c->family_name . ' family' : 'Family') . ($child !== '' ? ' · ' . $child : '');
                $cnt = $famCounts[$c->id] ?? null;
                $out[] = ['kind' => 'family', 'id' => (int) $c->id, 'title' => $title, 'subject' => $c->subject ?: null,
                    'centre' => $c->centre_name, 'channel' => $c->channel ?: 'chat', 'people' => array_values($people),
                    'messages' => (int) ($cnt->n ?? 0), 'deleted' => (int) ($cnt->del ?? 0),
                    'last_message_at' => $c->last_message_at, 'created_at' => $c->created_at, 'closed' => (bool) $c->deleted_at];
            } else {
                $people = [];
                foreach (($stPeople[$c->id] ?? []) as $u) $people[$u->id] = self::name($u);
                $cnt = $stCounts[$c->id] ?? null;
                $out[] = ['kind' => 'staff', 'id' => (int) $c->id,
                    'title' => $c->title ?: implode(', ', array_slice(array_values($people), 0, 4)) ?: 'Staff conversation',
                    'subject' => null, 'centre' => null, 'channel' => $c->is_group ? 'group' : 'direct', 'people' => array_values($people),
                    'messages' => (int) ($cnt->n ?? 0), 'deleted' => 0,
                    'last_message_at' => $c->last_message_at, 'created_at' => $c->created_at, 'closed' => false];
            }
        }

        return response()->json(['conversations' => $out, 'total' => $total, 'timezone' => $tz]);
    }

    /** GET /admin/chat-audit/{kind}/{id} — the full transcript, and the read is audited. */
    public function show(Request $request, string $kind, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        if (! $agencyId) return response()->json(['message' => 'Chat conversations are for agency admins.'], 403);

        if ($kind === 'family') {
            $c = DB::table('conversations as c')->join('centres as ce', 'ce.id', '=', 'c.centre_id')
                ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
                ->where('c.id', $id)->where('ce.agency_id', $agencyId)
                ->select('c.*', 'ce.name as centre_name', 'f.family_name')->first();
            abort_unless($c, 404);
            $guardianIds = DB::table('guardians')->where('family_id', $c->family_id)->pluck('user_id')->map(fn ($x) => (int) $x)->all();
            $msgs = DB::table('messages as m')->leftJoin('users as u', 'u.id', '=', 'm.sender_id')->where('m.conversation_id', $id)
                ->orderBy('m.created_at')->orderBy('m.id')
                ->select('m.id', 'm.sender_id', 'm.body', 'm.via', 'm.attachments', 'm.created_at', 'm.deleted_at', 'm.edited_at', 'm.is_system',
                    'm.read_at', 'm.family_read_at', 'u.first_name', 'u.last_name', 'u.email')->get();
            $title = ($c->family_name ? $c->family_name . ' family' : 'Family') . ' — ' . $c->centre_name;
        } elseif ($kind === 'staff') {
            $c = DB::table('staff_threads')->where('id', $id)->where('agency_id', $agencyId)->first();
            abort_unless($c, 404);
            $guardianIds = [];
            $msgs = DB::table('staff_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.sender_id')->where('m.thread_id', $id)
                ->orderBy('m.created_at')->orderBy('m.id')
                ->select('m.id', 'm.sender_id', 'm.body', DB::raw('NULL as via'), 'm.attachments', 'm.created_at', DB::raw('NULL as deleted_at'),
                    DB::raw('NULL as edited_at'), 'm.is_system', DB::raw('NULL as read_at'), DB::raw('NULL as family_read_at'), 'u.first_name', 'u.last_name', 'u.email')->get();
            $people = DB::table('staff_thread_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')->where('p.thread_id', $id)
                ->select('u.first_name', 'u.last_name', 'u.email')->get()->map(fn ($u) => self::name($u))->all();
            $title = $c->title ?: ('Staff conversation — ' . implode(', ', $people));
        } else {
            abort(404);
        }

        $out = [];
        foreach ($msgs as $m) {
            $att = [];
            if ($m->attachments) {
                $a = json_decode((string) $m->attachments, true);
                if (is_array($a)) foreach ($a as $x) $att[] = is_array($x) ? ($x['name'] ?? $x['original_name'] ?? basename((string) ($x['url'] ?? 'file'))) : basename((string) $x);
            }
            $out[] = ['id' => (int) $m->id, 'sender' => $m->is_system ? 'System' : self::name($m->first_name !== null || $m->email !== null ? $m : null),
                'side' => $m->is_system ? 'system' : (in_array((int) $m->sender_id, $guardianIds ?? [], true) ? 'family' : 'staff'),
                'body' => (string) $m->body, 'via' => $m->via, 'attachments' => $att, 'created_at' => $m->created_at,
                'deleted_at' => $m->deleted_at, 'edited_at' => $m->edited_at];
        }

        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'chat.transcript_viewed',
                'entity_type' => $kind === 'family' ? 'conversation' : 'staff_thread', 'entity_id' => $id,
                'payload' => json_encode(['summary' => 'Viewed the chat transcript: ' . $title . ' (' . count($out) . ' messages)']),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['title' => $title, 'kind' => $kind, 'messages' => $out, 'timezone' => \App\Support\AgencyTime::tz($agencyId)]);
    }
}
