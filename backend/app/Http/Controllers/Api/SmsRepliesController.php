<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Badges;
use App\Support\Dialer;
use App\Support\LineTexts;
use App\Support\SmsReplies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Text replies (2026-11-02): texts in to the agency's number, the conversation with each number, reading them and
 * answering them by text. Message Centre → 📱 Texts shows each person the replies to THEIR texts; SMS → Replies is
 * the agency overview. Both call these methods; App\Support\SmsReplies::visible() decides who sees what.
 *
 *   GET  /sms-replies                  (also /admin/sms/replies)              the list, newest first
 *   GET  /sms-replies/{id}/thread      (also /admin/sms/replies/{id}/thread)  the conversation + can we reply
 *   POST /sms-replies/{id}/read        (also /admin/sms/replies/{id}/read)    opening it reads that number's texts
 *   POST /sms-replies/{id}/reply       (also /admin/sms/replies/{id}/reply)   text back, through SmsController::sendOne
 */
final class SmsRepliesController extends Controller
{
    /** [agency, visible query, oversees, people]. Fails closed: no agency or no access = refused. */
    private function scope(Request $r): array
    {
        $agencyId = SmsReplies::agencyFor($r);
        abort_unless($agencyId, 400, 'Select an agency first.');
        $v = SmsReplies::visible($r, $agencyId);
        abort_unless($v, 403, 'Text replies are for staff of this agency.');

        return [$agencyId, $v[0], $v[1], $v[1] ? SmsReplies::people($r, $agencyId) : []];
    }

    /** One inbound row this caller may see, or 404 (a row outside the scope does not exist for them). */
    private function row($visible, int $id): object
    {
        $m = (clone $visible)->where('m.id', $id)->first(['m.*']);
        abort_unless($m, 404, 'That text is not one of your replies in this agency.');

        return $m;
    }

    /** Message Centre (not the SMS → Replies overview) also lists the KiddieTrac line's texts for the line's people. */
    private function withLine(Request $r): bool
    {
        return ! str_contains($r->path(), 'admin/');
    }

    /** A line text this caller may open (LineTexts::inboxRow), or null. */
    private function lineRow(Request $r, int $id): ?object
    {
        return LineTexts::inboxRow((int) $r->user()->id, $id);
    }

    public function index(Request $r): JsonResponse
    {
        [$lc, $lr, $lu] = $this->withLine($r) ? LineTexts::inbox((int) $r->user()->id) : [[], [], 0];
        $aid = SmsReplies::agencyFor($r);
        if ($lc && ! ($aid && SmsReplies::visible($r, $aid))) {
            // On the line's team but not staff of the agency they are in: just the line.
            return response()->json(['data' => $lr, 'conversations' => $lc, 'unread' => $lu, 'oversees' => true]);
        }
        $resp = $this->agencyIndex($r);
        if (! $lc) return $resp;
        $j = $resp->getData(true);
        $j['data'] = array_merge($j['data'] ?? [], json_decode(json_encode($lr), true));
        $all = array_merge($j['conversations'] ?? [], $lc);
        usort($all, fn ($a, $b) => strcmp((string) $b['last_at'], (string) $a['last_at']));
        $j['conversations'] = $all;
        $j['unread'] = (int) ($j['unread'] ?? 0) + $lu;

        return response()->json($j);
    }

    private function agencyIndex(Request $r): JsonResponse
    {
        [$agencyId, $visible, $oversees] = $this->scope($r);
        $me = (int) $r->user()->id;
        $unreadIds = SmsReplies::whereUnread(clone $visible)->pluck('m.id')->map(fn ($i) => (int) $i)->all();
        $rows = (clone $visible)
            ->leftJoin('users as u', 'u.id', '=', 'm.to_user_id')
            ->leftJoin('users as o', 'o.id', '=', 'm.owner_user_id')
            ->orderByDesc('m.id')->limit(300)
            ->get(['m.id', 'm.to_user_id', 'm.to_phone', 'm.body', 'm.category', 'm.created_at', 'm.read_at',
                'm.owner_user_id', 'm.announcement_id', 'm.reply_to_sms_id',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as person"),
                DB::raw("TRIM(COALESCE(o.first_name,'')) as owner_first")]);
        $titles = SmsReplies::announcementTitles($rows->pluck('announcement_id')->all());
        $rows = $rows->map(function ($m) use ($unreadIds, $titles, $me) {
            $m->kind = SmsReplies::kind($m->category);
            $m->unread = in_array((int) $m->id, $unreadIds, true);
            $m->mine = (int) $m->owner_user_id === $me;
            $m->announcement_title = $m->announcement_id ? ($titles[(int) $m->announcement_id] ?? null) : null;

            return $m;
        });

        return response()->json(['data' => $rows, 'conversations' => $this->conversations($agencyId, $rows, $oversees, $r),
            'unread' => count($unreadIds), 'oversees' => $oversees]);
    }

    /**
     * ONE ROW PER NUMBER (2026-11-02, Anthony: "make this into a popup style chat like how we have the other chat with
     * the table view"). The list is conversations, not single texts: who, their number, the last message either way,
     * when, and how many of theirs are unread. Opening one opens its newest text (id) - the thread is the number's.
     * The last message we sent is shown only where this person could see it in the thread (SmsReplies::thread).
     */
    private function conversations(int $agencyId, $rows, bool $oversees, Request $r): array
    {
        $me = (int) $r->user()->id;
        $g = [];
        foreach ($rows as $m) {   // newest first
            $k = SmsReplies::last10((string) $m->to_phone);
            if (strlen($k) < 7) $k = 'row' . $m->id;
            if (! isset($g[$k])) {
                $g[$k] = ['id' => (int) $m->id, 'key' => $k, 'to_user_id' => $m->to_user_id ? (int) $m->to_user_id : null,
                    'person' => (string) $m->person, 'phone' => (string) $m->to_phone,
                    'last' => (string) $m->body, 'last_kind' => $m->kind, 'last_dir' => 'in', 'last_by' => '',
                    'last_at' => $m->created_at, 'last_id' => (int) $m->id, 'unread' => 0, 'texts' => 0,
                    'announcement_title' => $m->announcement_title, 'owner_first' => $m->owner_first, 'mine' => $m->mine];
            }
            $g[$k]['texts']++;
            if ($m->unread) $g[$k]['unread']++;
            if (! $g[$k]['announcement_title'] && $m->announcement_title) {
                $g[$k]['announcement_title'] = $m->announcement_title;
                $g[$k]['owner_first'] = $m->owner_first;
                $g[$k]['mine'] = $m->mine;
            }
        }
        $keys = array_values(array_filter(array_keys($g), fn ($k) => ! str_starts_with((string) $k, 'row')));
        if ($keys) {
            $people = $oversees ? SmsReplies::people($r, $agencyId) : [];
            $q = DB::table('sms_messages as m')->leftJoin('users as s', 's.id', '=', 'm.sent_by_user_id')
                ->where('m.agency_id', $agencyId)->where('m.direction', 'out')->where('m.status', '!=', 'skipped')
                ->where(fn ($w) => $w->whereNull('m.category')->orWhereNotIn('m.category', ['line_text', 'voicemail_reply', 'keyword_reply']))
                ->whereRaw("RIGHT(REGEXP_REPLACE(m.to_phone, '[^0-9]', ''), 10) IN (" . implode(',', array_fill(0, count($keys), '?')) . ')', $keys);
            if (! $oversees) {
                $q->where('m.sent_by_user_id', $me);
            } elseif ($people !== null) {
                $q->where(fn ($w) => $w->whereIn('m.to_user_id', $people ?: [0])->orWhere('m.sent_by_user_id', $me));
            }
            $outs = $q->orderByDesc('m.id')->limit(3000)->get(['m.id', 'm.to_phone', 'm.body', 'm.created_at', 'm.status',
                'm.sent_by_user_id', DB::raw("TRIM(COALESCE(s.first_name,'')) as by_first")]);
            $seen = [];
            foreach ($outs as $o) {
                $k = SmsReplies::last10((string) $o->to_phone);
                if (isset($seen[$k]) || ! isset($g[$k])) continue;
                $seen[$k] = true;
                if ((int) $o->id > $g[$k]['last_id']) {
                    $g[$k]['last'] = (string) $o->body;
                    $g[$k]['last_kind'] = 'message';
                    $g[$k]['last_dir'] = 'out';
                    $g[$k]['last_by'] = (int) $o->sent_by_user_id === $me ? 'You' : (string) $o->by_first;
                    $g[$k]['last_at'] = $o->created_at;
                    $g[$k]['last_id'] = (int) $o->id;
                    $g[$k]['last_status'] = (string) $o->status;
                }
            }
        }
        $list = array_values($g);
        usort($list, fn ($a, $b) => $b['last_id'] <=> $a['last_id']);

        return $list;
    }

    public function thread(Request $r, int $id): JsonResponse
    {
        if ($lm = $this->lineRow($r, $id)) return response()->json(LineTexts::inboxThread((int) $r->user()->id, $lm));
        [$agencyId, $visible, $oversees, $people] = $this->scope($r);
        $m = $this->row($visible, $id);
        $me = (int) $r->user()->id;
        $userId = $m->to_user_id ? (int) $m->to_user_id : null;

        $rows = SmsReplies::thread($agencyId, $m, $oversees, $people, $me)->map(function ($t) {
            $t->kind = $t->direction === 'in' ? SmsReplies::kind($t->category) : 'ours';
            $t->error_plain = $t->direction === 'in' ? null : SmsController::plainSmsError($t->status, $t->error);

            return $t;
        });
        $person = $userId ? DB::table('users')->where('id', $userId)
            ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")->value('n') : '';
        $titles = SmsReplies::announcementTitles([$m->announcement_id]);
        $owner = $m->owner_user_id ? trim((string) DB::table('users')->where('id', $m->owner_user_id)->value('first_name')) : '';

        return response()->json([
            'reply' => ['id' => (int) $m->id, 'to_user_id' => $userId, 'phone' => $m->to_phone,
                'person' => trim((string) $person), 'kind' => SmsReplies::kind($m->category),
                'announcement_id' => $m->announcement_id ? (int) $m->announcement_id : null,
                'announcement_title' => $m->announcement_id ? ($titles[(int) $m->announcement_id] ?? null) : null,
                'mine' => (int) $m->owner_user_id === $me, 'owner_first' => $owner],
            'thread' => $rows,
            'can_reply' => SmsReplies::canReply($agencyId, $userId, (string) $m->to_phone),
            'sender_first' => trim((string) ($r->user()->first_name ?? '')),
        ]);
    }

    public function markRead(Request $r, int $id): JsonResponse
    {
        if ($lm = $this->lineRow($r, $id)) {
            $d = LineTexts::digits($lm->to_phone);
            $ids = DB::table('sms_messages')->where('agency_id', LineTexts::AGENCY)->where('direction', 'in')->whereNull('to_user_id')
                ->whereNull('read_at')->where('to_phone', 'like', '%' . $d)->pluck('id')->all();
            $n = $ids ? DB::table('sms_messages')->whereIn('id', $ids)->update(['read_at' => now()]) : 0;
            $uid = (int) $r->user()->id;
            foreach (array_merge($ids, [(int) $lm->id]) as $rid) Badges::readAlerts($uid, 'sms_id', (int) $rid, ['sms_reply']);
            // Alerts written before 2026-11-04 carried only the dial-pad link.
            DB::table('notifications')->where('user_id', $uid)->whereNull('read_at')->where('type', 'sms_reply')
                ->where('data', 'like', '%tab=texts&n=' . $d . '%')->update(['read_at' => now()]);

            return response()->json(['marked' => (int) $n, 'unread' => SmsReplies::unread($r) + LineTexts::unread()]);
        }
        [$agencyId, $visible] = $this->scope($r);
        $m = $this->row($visible, $id);
        $d = SmsReplies::last10((string) $m->to_phone);

        // Opening a reply shows the whole conversation, so every unread text in it that this person may see is read.
        $ids = (clone $visible)->whereNull('m.read_at')
            ->where(function ($w) use ($m, $d) {
                $w->where('m.id', $m->id);
                if (strlen($d) >= 7) $w->orWhereRaw("RIGHT(REGEXP_REPLACE(m.to_phone, '[^0-9]', ''), 10) = ?", [$d]);
            })->pluck('m.id')->all();
        $n = $ids ? DB::table('sms_messages')->whereIn('id', $ids)->update(['read_at' => now()]) : 0;

        // One read state: the same texts bridged into a family chat thread are read there too,
        // and this person's "Text reply from …" alerts for them are read.
        $uid = (int) $r->user()->id;
        foreach ($ids as $rid) {
            Badges::readAlerts($uid, 'sms_id', (int) $rid, ['sms_reply']);
        }
        if ($m->to_user_id && $ids) {
            $bodies = DB::table('sms_messages')->whereIn('id', $ids)->pluck('body')->all();
            DB::table('messages')->where('sender_id', $m->to_user_id)->where('via', 'sms')->whereNull('read_at')
                ->whereIn('body', $bodies)->update(['read_at' => now()]);
        }

        return response()->json(['marked' => (int) $n, 'unread' => SmsReplies::unread($r)]);
    }

    public function reply(Request $r, int $id): JsonResponse
    {
        if ($lm = $this->lineRow($r, $id)) {
            // From the KiddieTrac line, exactly as Phone → Texts sends it (DialerController::textSend).
            $data = $r->validate(['body' => 'required|string|max:1000']);
            $uid = (int) $r->user()->id;
            $d = LineTexts::digits($lm->to_phone);
            [$ok, $err, $oid, $basis] = LineTexts::send($d, trim($data['body']), $uid);
            Audit::write(['user_id' => $uid, 'agency_id' => null, 'action' => 'line.text_sent', 'entity_type' => 'sms_message', 'entity_id' => $oid,
                'payload' => json_encode(['summary' => ($ok ? 'Texted ' : 'Text failed to ') . Dialer::pretty($d) . ' from the KiddieTrac line (Message Centre; they texted the line).',
                    'to' => $d, 'ok' => $ok, 'consent' => $basis]), 'ip_address' => $r->ip(), 'created_at' => now()]);
            if (! $ok) {
                $msg = $err === LineTexts::NEEDS_PERMISSION ? 'This number has not texted the line.' : (string) $err;

                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return response()->json(['ok' => true, 'id' => $oid, 'status' => 'sent']);
        }
        [$agencyId, $visible] = $this->scope($r);
        $data = $r->validate(['body' => 'required|string|max:300']);
        $m = $this->row($visible, $id);
        $userId = $m->to_user_id ? (int) $m->to_user_id : null;
        $phone = (string) $m->to_phone;

        // Asked first so the answer is in words. sendOne runs every gate again regardless.
        $can = SmsReplies::canReply($agencyId, $userId, $phone);
        if (! $can['ok']) {
            Audit::write([
                'user_id' => $r->user()->id,
                'agency_id' => $agencyId,
                'action' => 'sms.reply_refused',
                'entity_type' => 'sms',
                'entity_id' => (int) $m->id,
                'payload' => json_encode(['summary' => 'A text reply was not sent: ' . $can['reason'],
                    'to' => $phone, 'reason' => $can['reason']]),
                'ip_address' => $r->ip(),
                'created_at' => now(),
            ]);

            return response()->json(['ok' => false, 'message' => $can['reason']], 422);
        }

        // From a person, not a system: their FIRST name (never a staff surname in what families receive).
        $first = trim((string) ($r->user()->first_name ?? ''));
        $body = trim($data['body']);
        $text = $first !== '' ? ($first . ': ' . $body) : $body;

        $basis = ($can['basis'] ?? 'consent') === 'texted_us' ? 'texted_us' : null;
        $before = (int) DB::table('sms_messages')->max('id');
        $ok = app(SmsController::class)->sendOne($agencyId, $userId, $phone, $text, 'chat_reply', null, $basis);
        $out = DB::table('sms_messages')->where('id', '>', $before)->where('agency_id', $agencyId)
            ->where('to_user_id', $userId)->where('direction', 'out')->where('category', 'chat_reply')
            ->orderByDesc('id')->first(['id', 'status', 'error']);
        if ($out) {
            // Their next text answers this one, so it comes back to whoever sent it.
            DB::table('sms_messages')->where('id', $out->id)->update(['sent_by_user_id' => $r->user()->id]);
        }
        // Answering a text reads it.
        DB::table('sms_messages')->where('id', $m->id)->whereNull('read_at')->update(['read_at' => now()]);

        // On what basis it was texted, in the audit - as the KiddieTrac line records it (DialerController::textSend).
        $who = $userId ? trim((string) DB::table('users')->where('id', $userId)->value('first_name')) : '';
        $why = $basis === 'texted_us' ? 'they texted the agency\'s number' : 'they agreed to texts';
        Audit::write([
            'user_id' => $r->user()->id,
            'agency_id' => $agencyId,
            'action' => 'sms.reply_sent',
            'entity_type' => 'sms_message',
            'entity_id' => $out->id ?? (int) $m->id,
            'payload' => json_encode(['summary' => ($ok ? 'Replied by text to ' : 'A text reply did not go to ') . ($who !== '' ? $who : $phone)
                . ' (' . $why . ')' . ($ok ? '.' : ': ' . (string) ($out->status ?? 'not sent') . '.'),
                'to' => $phone, 'ok' => (bool) $ok, 'consent' => $basis ?? 'consent', 'answering' => (int) $m->id]),
            'ip_address' => $r->ip(),
            'created_at' => now(),
        ]);

        if (! $ok) {
            $why = $out ? (SmsController::plainSmsError($out->status, $out->error) ?: (string) $out->error) : '';

            return response()->json(['ok' => false, 'id' => $out->id ?? null, 'status' => $out->status ?? 'skipped',
                'message' => $why !== '' ? $why : 'The reply was not sent.'], 422);
        }

        return response()->json(['ok' => true, 'id' => $out->id ?? null, 'status' => $out->status ?? 'sent']);
    }
}
