<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TEXTS ON THE KIDDIETRAC LINE (2026-10-23, Anthony: "text message came in from prospect but
 * how do i reply and see text messages, they do not appear in messages").
 *
 * The 1-855 number belongs to the agency-2 Telnyx account. A text from somebody with a portal
 * account is bridged into chat (SmsChatBridge); a text from anyone else - a prospect, a lead -
 * used to become a bell notice to agency 2's admins pointing at a screen that does not exist.
 * Now those conversations live on Phone → Texts: one thread per number, read and answered
 * there, filed on the lead with the same number, and heard about by the people the line is
 * for (platform admins and the call-menu departments' alert lists - InboundCalls::scope),
 * never by the agency's own staff.
 *
 * A thread is: texts in from a number with no account, our replies (category line_text),
 * and the automatic texts the line already sends that number (voicemail reply, STOP/HELP).
 */
class LineTexts
{
    public const AGENCY = 2;
    private const OURS = ['line_text', 'voicemail_reply', 'keyword_reply'];

    public static function digits(?string $n): string
    {
        $d = preg_replace('/\D/', '', (string) $n);
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
        return $d;
    }

    public static function allowed(int $uid): bool
    {
        return InboundCalls::allowed(InboundCalls::scope($uid));
    }

    /** Who hears about a new text: platform admins + the departments' alert lists. */
    public static function audience(): array
    {
        $ids = DB::table('role_assignments')->where('role', 'platform_admin')->where('active', 1)->pluck('user_id')->map(fn ($i) => (int) $i)->all();
        foreach (PhoneMenu::config()['departments'] as $d) foreach (($d['alert_user_ids'] ?? []) as $i) $ids[] = (int) $i;
        return array_values(array_unique($ids));
    }

    private static function base()
    {
        return DB::table('sms_messages')->where('agency_id', self::AGENCY)->where(function ($w) {
            $w->where(fn ($x) => $x->where('direction', 'in')->whereNull('to_user_id')->where('category', 'like', 'inbound%'))
              ->orWhere(fn ($x) => $x->where('direction', '!=', 'in')->whereIn('category', self::OURS));
        });
    }

    /** Numbers that have texted in, or that the line has texted (a conversation started from here). */
    private static function threadNumbers(): array
    {
        $out = [];
        foreach (DB::table('sms_messages')->where('agency_id', self::AGENCY)->where(function ($w) {
                $w->where(fn ($x) => $x->where('direction', 'in')->whereNull('to_user_id'))
                  ->orWhere(fn ($x) => $x->where('direction', 'out')->where('category', 'line_text'));
            })->orderByDesc('id')->limit(2000)->pluck('to_phone') as $p) {
            $d = self::digits($p);
            if (strlen($d) === 10) $out[$d] = true;
        }
        return array_map('strval', array_keys($out));   // PHP makes 10-digit keys integers
    }

    public static function unread(): int
    {
        return (int) DB::table('sms_messages')->where('agency_id', self::AGENCY)->where('direction', 'in')->whereNull('to_user_id')
            ->where('category', 'inbound')->whereNull('read_at')->count();
    }

    public static function stopped(string $digits): bool
    {
        $kw = DB::table('sms_messages')->where('agency_id', self::AGENCY)->where('to_phone', 'like', '%' . $digits)
            ->whereIn('category', ['inbound_stop', 'inbound_start'])->orderByDesc('id')->value('category');
        return $kw === 'inbound_stop';
    }

    private static function who(string $digits): array
    {
        $lead = CallRecords::leadFor($digits);
        if ($lead) return ['name' => $lead->name ?: $lead->company, 'company' => $lead->company, 'lead_id' => (int) $lead->id];
        return ['name' => null, 'company' => null, 'lead_id' => null];
    }

    public static function conversations(): array
    {
        $nums = self::threadNumbers();
        if (! $nums) return [];
        $rows = self::base()->orderByDesc('id')->limit(3000)->get(['id', 'direction', 'to_phone', 'body', 'category', 'read_at', 'created_at']);
        $by = [];
        foreach ($rows as $r) {
            $d = self::digits($r->to_phone);
            if (! in_array($d, $nums, true)) continue;
            if (! isset($by[$d])) $by[$d] = ['digits' => $d, 'pretty' => Dialer::pretty($d), 'last_body' => $r->body, 'last_at' => $r->created_at,
                'last_dir' => $r->direction === 'in' ? 'in' : 'out', 'unread' => 0, 'count' => 0];
            $by[$d]['count']++;
            if ($r->direction === 'in' && $r->category === 'inbound' && ! $r->read_at) $by[$d]['unread']++;
        }
        foreach ($by as $d => &$c) { $c += self::who($d); $c['stopped'] = self::stopped($d); }
        unset($c);
        return array_values($by);
    }

    public static function thread(string $digits, int $uid): array
    {
        $rows = self::base()->where('to_phone', 'like', '%' . $digits)->orderByDesc('id')->limit(300)
            ->get(['id', 'direction', 'body', 'category', 'status', 'error', 'created_at', 'sent_by_user_id']);
        $names = DB::table('users')->whereIn('id', $rows->pluck('sent_by_user_id')->filter()->unique())->pluck('first_name', 'id');
        DB::table('sms_messages')->where('agency_id', self::AGENCY)->where('direction', 'in')->whereNull('to_user_id')
            ->where('to_phone', 'like', '%' . $digits)->whereNull('read_at')->update(['read_at' => now()]);
        $msgs = $rows->reverse()->values()->map(fn ($r) => [
            'id' => (int) $r->id, 'dir' => $r->direction === 'in' ? 'in' : 'out', 'body' => (string) $r->body, 'at' => $r->created_at,
            'status' => $r->status, 'error' => $r->status === 'failed' ? $r->error : null,
            'by' => $r->sent_by_user_id ? ($names[$r->sent_by_user_id] ?? null) : ($r->direction === 'in' ? null : 'Automatic'),
            'auto' => in_array($r->category, ['voicemail_reply', 'keyword_reply'], true),
        ])->all();
        return ['digits' => $digits, 'pretty' => Dialer::pretty($digits), 'messages' => $msgs, 'stopped' => self::stopped($digits)] + self::who($digits);
    }

    public const NEEDS_PERMISSION = 'NEEDS_PERMISSION';

    /**
     * Why this number may be texted, or null. Someone who has texted the line, a sales lead or a
     * contact who gave the number (forms, bookings, the contacts book) has a relationship with us;
     * anyone else needs the sender to confirm they have permission (2026-10-24, Anthony's rule).
     */
    public static function consentBasis(string $digits): ?string
    {
        if (DB::table('sms_messages')->where('agency_id', self::AGENCY)->where('direction', 'in')->whereNull('to_user_id')
            ->where('to_phone', 'like', '%' . $digits)->exists()) return 'texted_us';
        if (CallRecords::leadFor($digits)) return 'lead';
        // The owner / decision maker on a lead gave their number too.
        if (DB::table('sales_leads')->whereNull('deleted_at')->whereNotNull('owner_phone')->get(['owner_phone'])
            ->first(fn ($l) => self::digits($l->owner_phone) === $digits)) return 'lead';
        $like = '%' . substr($digits, 0, 3) . '%' . substr($digits, 3, 3) . '%' . substr($digits, 6) . '%';
        $hit = DB::table('contacts')->where('agency_id', self::AGENCY)->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('phone', 'like', $like)->orWhere('mobile', 'like', $like))->get(['phone', 'mobile'])
            ->first(fn ($c) => self::digits($c->phone) === $digits || self::digits($c->mobile) === $digits);
        return $hit ? 'contact' : null;
    }

    /** Send from the line. [ok, message|null, row id|null, consent basis|null] */
    public static function send(string $digits, string $body, int $uid, bool $attested = false): array
    {
        if (strlen($digits) !== 10) return [false, 'That is not a phone number we can text.', null, null];
        if (self::stopped($digits)) return [false, 'This number texted STOP, so it cannot be texted until it sends START.', null, null];
        $basis = self::consentBasis($digits) ?: ($attested ? 'permission_confirmed' : null);
        if (! $basis) return [false, self::NEEDS_PERMISSION, null, null];
        $cfg = Telnyx::smsConfig(self::AGENCY);
        if (! $cfg) return [false, 'The KiddieTrac line has no texting number set up.', null, null];
        $res = Telnyx::sendSms($cfg, '+1' . $digits, $body);
        $id = (int) DB::table('sms_messages')->insertGetId([
            'agency_id' => self::AGENCY, 'direction' => 'out', 'to_user_id' => null, 'to_phone' => '+1' . $digits,
            'body' => mb_substr($body, 0, 1600), 'category' => 'line_text', 'provider' => 'telnyx',
            'provider_ref' => $res['ok'] ? ($res['id'] ?: null) : null, 'status' => $res['ok'] ? 'sent' : 'failed',
            'error' => $res['ok'] ? null : mb_substr((string) $res['error'], 0, 190), 'sent_at' => $res['ok'] ? now() : null,
            'created_at' => now(), 'sent_by_user_id' => $uid,
        ]);
        if ($res['ok']) self::fileOnLead($digits, 'out', $body, $uid);
        return [$res['ok'], $res['ok'] ? null : 'The text was not sent: ' . mb_substr((string) $res['error'], 0, 140), $id, $basis];
    }

    /** A text in or out goes on the lead with that number, as a 💬 Text entry (no team email). */
    public static function fileOnLead(string $digits, string $dir, string $body, ?int $uid): void
    {
        try {
            $lead = CallRecords::leadFor($digits);
            if (! $lead) return;
            $who = $dir === 'in' ? 'Text from ' . ($lead->name ?: Dialer::pretty($digits)) : 'Text sent to ' . Dialer::pretty($digits);
            DB::table('sales_activities')->insert(['lead_id' => $lead->id, 'user_id' => $uid, 'type' => 'text', 'body' => $who . ":\n" . $body,
                'done' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sales_leads')->where('id', $lead->id)->update(['last_activity_at' => now()]);
        } catch (Throwable $e) {
            // never lose the text over the lead entry
        }
    }

    /* MESSAGE CENTRE TOO (2026-11-04, Anthony: "when text messages show up I cannot seem to reply cleanly from the
       mobile app not showing up in the chat system as a text either. I have to goto dialpad and then text messages to
       see them"). The line's conversations are also listed in Message Centre → 📱 Texts and the chat dock, opened and
       answered there through /sms-replies (SmsRepliesController) - for the same people as Phone → Texts (allowed()).
       A conversation is addressed by the newest text in from that number, as the Texts tab addresses its own, so a new
       text brings back one that was archived. Phone → Texts is unchanged: one conversation, two doors. */

    /** A text in on the line from a number with no account that $uid may open there, or null. */
    public static function inboxRow(int $uid, int $id): ?object
    {
        if ($id <= 0 || ! self::allowed($uid)) return null;

        return DB::table('sms_messages')->where('id', $id)->where('agency_id', self::AGENCY)
            ->where('direction', 'in')->whereNull('to_user_id')->first() ?: null;
    }

    /** [conversations, rows, unread] for SmsRepliesController::index. Nothing for anyone the line is not for. */
    public static function inbox(int $uid): array
    {
        if (! self::allowed($uid)) return [[], [], 0];
        $ins = DB::table('sms_messages')->where('agency_id', self::AGENCY)->where('direction', 'in')->whereNull('to_user_id')
            ->orderByDesc('id')->limit(2000)->get(['id', 'to_phone', 'body', 'category', 'read_at', 'created_at']);
        $newest = []; $unread = [];
        foreach ($ins as $m) {
            $d = self::digits($m->to_phone);
            if (strlen($d) !== 10) continue;
            if (! isset($newest[$d])) $newest[$d] = $m;
            if (! $m->read_at && $m->category === 'inbound') $unread[(int) $m->id] = $m;
        }
        if (! $newest) return [[], [], 0];
        $conv = [];
        foreach (self::conversations() as $c) $conv[$c['digits']] = $c;
        $name = function (string $d) use ($conv) { $c = $conv[$d] ?? self::who($d); return (string) (($c['name'] ?? null) ?: (($c['company'] ?? null) ?: '')); };
        $row = function ($m, string $d) use ($name, $unread) {
            return (object) ['id' => (int) $m->id, 'to_user_id' => null, 'to_phone' => Dialer::pretty($d), 'body' => (string) $m->body,
                'category' => $m->category, 'created_at' => $m->created_at, 'read_at' => $m->read_at, 'owner_user_id' => null,
                'announcement_id' => null, 'reply_to_sms_id' => null, 'person' => $name($d), 'owner_first' => '',
                'kind' => SmsReplies::kind($m->category), 'unread' => isset($unread[(int) $m->id]), 'mine' => false,
                'announcement_title' => null, 'line' => true];
        };
        $convs = []; $rows = []; $have = [];
        foreach ($newest as $d => $m) {
            $c = $conv[$d] ?? [];
            $convs[] = ['id' => (int) $m->id, 'key' => (string) $d, 'to_user_id' => null, 'person' => $name($d), 'phone' => Dialer::pretty($d),
                'last' => (string) ($c['last_body'] ?? $m->body), 'last_kind' => 'message', 'last_dir' => $c['last_dir'] ?? 'in', 'last_by' => '',
                'last_at' => $c['last_at'] ?? $m->created_at, 'last_id' => (int) $m->id, 'unread' => (int) ($c['unread'] ?? 0),
                'texts' => (int) ($c['count'] ?? 1), 'announcement_title' => null, 'owner_first' => '', 'mine' => false, 'line' => true,
                'stopped' => (bool) ($c['stopped'] ?? false)];
            $rows[] = $row($m, (string) $d);
            $have[(int) $m->id] = true;
        }
        foreach ($unread as $id => $m) {
            if (! isset($have[$id])) $rows[] = $row($m, self::digits($m->to_phone));
        }

        return [$convs, $rows, count($unread)];
    }

    /** The conversation as GET /sms-replies/{id}/thread returns it (opening it reads it, as on Phone → Texts). */
    public static function inboxThread(int $uid, object $m): array
    {
        $d = self::digits($m->to_phone);
        $t = self::thread($d, $uid);

        return [
            'reply' => ['id' => (int) $m->id, 'to_user_id' => null, 'phone' => $t['pretty'], 'person' => (string) (($t['name'] ?? null) ?: (($t['company'] ?? null) ?: '')),
                'kind' => 'message', 'announcement_id' => null, 'announcement_title' => null, 'mine' => false, 'owner_first' => '', 'line' => true],
            'thread' => array_map(fn ($x) => ['id' => $x['id'], 'direction' => $x['dir'], 'body' => $x['body'], 'created_at' => $x['at'],
                'status' => $x['status'], 'kind' => $x['dir'] === 'in' ? 'message' : 'ours', 'error_plain' => $x['error'], 'sent_by' => (string) ($x['by'] ?? '')], $t['messages']),
            'can_reply' => $t['stopped'] ? ['ok' => false, 'reason' => 'This number texted STOP, so it cannot be texted until it sends START.']
                : ['ok' => true, 'basis' => 'texted_us'],
            'sender_first' => '',
        ];
    }

    /** From SmsInbound::record: a text in from a number with no account, on the line. $id = that text's row. */
    public static function incoming(string $from, string $body, ?int $id = null): void
    {
        $d = self::digits($from);
        self::fileOnLead($d, 'in', $body, null);
        $who = self::who($d);
        $title = '💬 Text from ' . ($who['name'] ?: Dialer::pretty($d));
        // Opens the conversation in Message Centre (where it can be answered), not the dial pad.
        $link = $id ? '#chat?sms=' . $id : '#phone?tab=texts&n=' . $d;
        $rows = [];
        foreach (self::audience() as $uid) {
            $rows[] = ['user_id' => $uid, 'type' => 'sms_reply', 'title' => $title, 'body' => mb_substr(trim($body), 0, 140),
                'data' => json_encode(['link' => $link, 'from' => $from, 'sms_id' => $id, 'line' => $d]), 'created_at' => now()];
        }
        try { if ($rows) Notify::write($rows); } catch (Throwable $e) {}
        foreach (self::audience() as $uid) {
            try { app(\App\Services\FcmService::class)->sendToUser($uid, $title, mb_substr(trim($body), 0, 140), $link); } catch (Throwable $e) {}
        }
    }
}
