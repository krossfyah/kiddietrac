<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A REPLY IS EVIDENCE, AND SOMEBODY HAS TO SEE IT (2026-09-24).
 *
 * Anthony: "add the reply logic and can replies be notified to admins/directors and
 * educators for those child under their care".
 *
 * Telnyx has been delivering replies all along; the webhook verified them, acted on
 * STOP, START and HELP, and stored nothing. A keyword worked and left no trace, and a
 * parent who typed anything else - "running late", "is Yahya ok?" - was read by a
 * handler and dropped on the floor. Two things follow from that: the agency cannot
 * prove a STOP it acted on, and a parent who answered a text about their child got
 * silence from a system that looked like it was listening.
 *
 * So every inbound message is filed as a row, and the people who would have been told
 * if it had arrived any other way are told.
 *
 * WHO GETS TOLD, AND WHY IT IS NOT "EVERYONE AT THE AGENCY":
 *
 *   - agency admins, because a reply is agency correspondence;
 *   - the director of each centre the sender's children attend;
 *   - the educators who hold those children's ROOMS.
 *
 * The room, not the centre, for educators - the same rule child records follow. At a
 * home-childcare agency each provider is their own centre, and telling all nine of
 * them that one family is running late is the leak the immunization reminders had.
 * Somebody with no connection to the sender's children hears nothing.
 *
 * Never throws. A webhook that 500s is a webhook the carrier retries, and a retried
 * STOP is a second confirmation text to somebody who just asked for none.
 */
final class SmsInbound
{
    /**
     * File the reply and tell the right people.
     *
     * @param  string|null  $keyword  the matched keyword (stop/start/help), when it was one
     * @return int|null  the sms_messages row written
     */
    public static function record(
        int $agencyId,
        string $fromPhone,
        string $body,
        ?int $userId,
        ?string $keyword = null,
        ?string $providerRef = null,
        string $provider = 'telnyx',
        bool $quiet = false
    ): ?int {
        try {
            $id = (int) DB::table('sms_messages')->insertGetId([
                'agency_id' => $agencyId,
                'direction' => 'in',
                'to_user_id' => $userId,
                // The counterparty's number either way, so one column answers
                // "which handset is this conversation with".
                'to_phone' => $fromPhone,
                'body' => mb_substr($body, 0, 1600),
                /* The keyword IS the category when there was one, so the list can tell
                   an opt-out from a question at a glance. */
                'category' => $keyword ? ('inbound_' . $keyword) : 'inbound',
                'provider' => $provider,
                'provider_ref' => $providerRef,
                'status' => 'received',
                'sent_at' => now(),
                'created_at' => now(),
            ]);

            self::audit($id);

            /* WHOSE TEXT DOES IT ANSWER (2026-11-02): filed on the row now, so the reply reaches the Message
               Centre of whoever sent the text it answers (an announcement's author, a staff reply). */
            $attr = SmsReplies::file($id) ?: [];
            $ownerId = (int) ($attr['owner_user_id'] ?? 0);

            /* A REPLY-BY-NUMBER ANSWER (2026-11-25, SmsAttendance): "1" / "2" / a time answering the Safe Arrival or
               check-in reminder text. It was recorded as the parent's absence or late notice, which already told the
               centre - so it is filed (and audited) here, and never dropped into chat as a bare "2". */
            if ($quiet) {
                return $id;
            }

            /* INTO THE CHAT THREAD FIRST (2026-09-24).

               Anthony: "can this be wired up to the chat system and show up as a chat
               from the SMS user and allow for a two way chat if required?"

               A reply that lands in chat arrives where staff already read messages from
               that family, counts towards their unread badge, and can be ANSWERED -
               which is the part a log entry can never do. insertMessage() works out its
               own recipients and pushes, so bridging replaces the separate notice
               rather than adding to it; sending both would buzz the same phone twice
               for one text.

               A keyword is not conversation. "STOP" in a chat thread reads as a parent
               shouting at an educator, and there is nothing to reply to - so those keep
               the plain notification.

               The fallback matters: somebody who is not a guardian of any family - a
               staff member, a wrong number that happens to match an account - has no
               thread to bridge into, and their message must still reach somebody. */
            $bridged = null;
            if (! $keyword && $userId) {
                $bridged = SmsChatBridge::relayIn($userId, $body);
            }
            if ($bridged && $ownerId && $ownerId !== (int) $userId) {
                /* The family's chat thread tells the staff who follow that family. The person whose announcement
                   or text this answers may not be one of them, so they are told directly. */
                self::notify($agencyId, $userId, $fromPhone, $body, $keyword, $id, $ownerId, [$ownerId]);
            }
            if (! $bridged) {
                /* A stranger texting the KiddieTrac line (2026-10-23): a prospect, not agency
                   correspondence - it goes to Phone → Texts and the line's people, and is
                   filed on their lead. It used to reach agency 2's own admins instead. */
                if (! $userId && ! $keyword && $agencyId === LineTexts::AGENCY) {
                    LineTexts::incoming($fromPhone, $body, (int) $id);
                } else {
                    self::notify($agencyId, $userId, $fromPhone, $body, $keyword, $id, $ownerId);
                }
            }

            return $id;
        } catch (Throwable $e) {
            Log::warning('Inbound SMS could not be filed', [
                'agency' => $agencyId, 'from' => $fromPhone, 'e' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** The agency a person belongs to, for a webhook that does not carry one. */
    public static function agencyOfUser(?int $userId): int
    {
        if (! $userId) {
            return 0;
        }
        $id = DB::table('role_assignments')->where('user_id', $userId)
            ->whereNotNull('agency_id')->orderByRaw("role = 'guardian' ASC")
            ->value('agency_id');

        return $id ? (int) $id : 0;
    }

    /**
     * ONE AUDIT ROW PER MESSAGE, read back off the row itself.
     *
     * Anthony: "i need SMS texts to be logged in audit log as well."
     *
     * Every text - sent, failed, skipped by a gate, or received - lands in
     * sms_messages, so the audit is written FROM that row rather than from whichever
     * branch produced it. Six code paths write those rows; auditing each one
     * separately is six chances to miss the branch nobody tests.
     *
     * The body is NOT the entity name. email.sent once put a whole recipient list
     * there and blew the column out; the summary says what happened and the payload
     * carries a trimmed preview, which is what somebody reading the log actually
     * needs.
     */
    public static function audit(?int $rowId): void
    {
        if (! $rowId) {
            return;
        }
        try {
            $m = DB::table('sms_messages')->where('id', $rowId)->first();
            if (! $m) {
                return;
            }
            $in = (($m->direction ?? 'out') === 'in');
            $who = $m->to_user_id
                ? (string) DB::table('users')->where('id', $m->to_user_id)
                    ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")->value('n')
                : '';
            $who = trim($who) !== '' ? $who : (string) $m->to_phone;

            Audit::write([
                'user_id' => null,
                'agency_id' => $m->agency_id ?: null,
                'action' => $in ? 'sms.received' : ('sms.' . ($m->status ?: 'sent')),
                'entity_type' => 'sms',
                'entity_id' => (int) $m->id,
                'payload' => json_encode(array_filter([
                    'to' => $m->to_phone,
                    'person' => $who,
                    'category' => $m->category,
                    'status' => $m->status,
                    'provider' => $m->provider,
                    'error' => $m->error,
                    'preview' => mb_substr((string) $m->body, 0, 160),
                    'summary' => $in
                        ? ('Text received from ' . $who . ': ' . mb_substr((string) $m->body, 0, 90))
                        : ('Text to ' . $who . ' (' . $m->category . ') - ' . ($m->status ?: 'sent')
                            . ($m->error ? ': ' . $m->error : '')),
                ], fn ($v) => $v !== null && $v !== '')),
                'ip_address' => 'system',
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('SMS audit row failed', ['row' => $rowId, 'e' => $e->getMessage()]);
        }
    }

    /** Apply a carrier delivery receipt to the outbound row it belongs to. */
    public static function receipt(string $providerRef, string $status, ?string $error = null): bool
    {
        try {
            if ($providerRef === '') {
                return false;
            }

            return DB::table('sms_messages')
                ->where('provider_ref', $providerRef)
                ->where('direction', 'out')
                ->update(array_filter([
                    'status' => $status,
                    'error' => $error,
                ], fn ($v) => $v !== null)) > 0;
        } catch (Throwable $e) {
            Log::warning('SMS receipt could not be applied', ['ref' => $providerRef, 'e' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The staff who look after this sender's children, and the agency's admins.
     *
     * @return int[] user ids
     */
    public static function audienceFor(int $agencyId, ?int $senderId): array
    {
        $ids = [];

        // Agency admins: a reply is correspondence with the agency.
        foreach (DB::table('role_assignments')->where('agency_id', $agencyId)
            ->where('role', 'agency_admin')->where('active', 1)->pluck('user_id') as $id) {
            $ids[(int) $id] = true;
        }

        if ($senderId) {
            /* The sender's children, and where each one actually sits. Read from the
               live enrollment rather than families.centre_id: a child placed in another
               provider's room is exactly the case where those two disagree, and the
               person who should hear about it is the one the child is with. */
            $familyIds = DB::table('guardians')->where('user_id', $senderId)->pluck('family_id');
            if ($familyIds->isNotEmpty()) {
                $placements = DB::table('children as ch')
                    ->join('enrollments as e', 'e.child_id', '=', 'ch.id')
                    ->join('rooms as r', 'r.id', '=', 'e.room_id')
                    ->whereIn('ch.family_id', $familyIds)
                    ->whereNull('e.end_date')
                    ->whereNull('ch.deleted_at')
                    ->get(['r.id as room_id', 'r.centre_id']);

                $roomIds = $placements->pluck('room_id')->unique()->all();
                $centreIds = $placements->pluck('centre_id')->unique()->all();

                if ($centreIds) {
                    foreach (DB::table('role_assignments')->whereIn('centre_id', $centreIds)
                        ->where('role', 'centre_director')->where('active', 1)->pluck('user_id') as $id) {
                        $ids[(int) $id] = true;
                    }
                }
                if ($roomIds) {
                    // Educators by ROOM: an explicit assignment, which is what "in their
                    // care" means everywhere else in this codebase.
                    foreach (DB::table('educator_rooms')->whereIn('room_id', $roomIds)
                        ->pluck('user_id') as $id) {
                        $ids[(int) $id] = true;
                    }
                }
            }
            // Never tell the sender about their own message.
            unset($ids[$senderId]);
        }

        return array_map('intval', array_keys($ids));
    }

    private static function notify(int $agencyId, ?int $senderId, string $fromPhone, string $body, ?string $keyword, int $rowId = 0, int $ownerId = 0, ?array $only = null): void
    {
        /* THE LINK OPENS THE REPLY (2026-11-02). It was '#sms', the broadcast SEND screen, where a reply
           was one row at the bottom of a log - Anthony tapped "Text reply from Bruni Meeser" and could
           not find it. Now Message Centre -> Texts opens with this conversation open (#chat?sms=<row>). */
        $link = $rowId > 0 ? ('#chat?sms=' . $rowId) : '#chat';

        $audience = $only ?? self::audienceFor($agencyId, $senderId);
        // The person whose text this answers hears about it - if they are staff of this agency.
        if ($ownerId && $ownerId !== (int) $senderId && ! in_array($ownerId, $audience, true) && self::staffOf($agencyId, $ownerId)) {
            $audience[] = $ownerId;
        }
        if ($only !== null) {
            $audience = array_values(array_filter($audience, fn ($u) => self::staffOf($agencyId, (int) $u)));
        }
        if (! $audience) {
            return;
        }
        // Who can open it in Message Centre: its owner, and the agency's admins / directors (SmsReplies::visible).
        // An educator told because the sender's child is in their room gets the alert without a dead link.
        $overseers = self::overseersOf($agencyId);
        $annTitle = '';
        if ($ownerId && $rowId) {
            $aid = DB::table('sms_messages')->where('id', $rowId)->value('announcement_id');
            if ($aid) $annTitle = (string) DB::table('announcements')->where('id', $aid)->value('title');
        }

        $who = $senderId
            ? (string) DB::table('users')->where('id', $senderId)
                ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")->value('n')
            : '';
        $who = trim($who) !== '' ? $who : $fromPhone;

        /* A STOP is not a message to read, it is a decision to know about, so it says
           what happened rather than quoting the word back. */
        $title = $keyword === 'stop'
            ? ($who . ' opted out of text messages')
            : ($keyword === 'start'
                ? ($who . ' opted back in to text messages')
                : ('Text reply from ' . $who));

        $preview = mb_substr(trim($body), 0, 140);

        $rows = [];
        $linkFor = [];
        $titleFor = [];
        foreach ($audience as $uid) {
            $canOpen = ((int) $uid === $ownerId) || in_array((int) $uid, $overseers, true);
            $linkFor[$uid] = $canOpen ? $link : '#notifications';
            $titleFor[$uid] = ((int) $uid === $ownerId && ! $keyword && $annTitle !== '')
                ? ('Text reply from ' . $who . ' to your announcement')
                : $title;
            $rows[] = [
                'user_id' => $uid,
                'type' => 'sms_reply',
                'title' => $titleFor[$uid],
                'body' => $keyword ? ('Sent from ' . $fromPhone) : (((int) $uid === $ownerId && $annTitle !== '') ? ('Re: ' . mb_substr($annTitle, 0, 60) . ' - ' . $preview) : $preview),
                'data' => json_encode(['link' => $linkFor[$uid], 'from' => $fromPhone, 'user_id' => $senderId, 'sms_id' => $rowId ?: null]),
                'created_at' => now(),
            ];
        }

        try {
            Notify::write($rows);
        } catch (Throwable $e) {
            Log::warning('Inbound SMS notification failed', ['e' => $e->getMessage()]);
        }

        /* Push as well, but only for a message somebody has to READ. An opt-out is
           filed and visible in the bell; waking nine phones for it is how a team
           learns to swipe these away. */
        if (! $keyword) {
            foreach ($audience as $uid) {
                try {
                    app(\App\Services\FcmService::class)->sendToUser($uid, $titleFor[$uid] ?? $title, $preview, $linkFor[$uid] ?? $link);
                } catch (Throwable $e) {
                    // logged inside the service; a push is never worth failing a webhook
                }
            }
        }
    }

    /** Active staff of this agency (a role in it or in one of its centres). */
    private static function staffOf(int $agencyId, int $userId): bool
    {
        if ($userId <= 0) return false;
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();

        return DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator', 'home_visitor', 'auditor'])
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereIn('centre_id', $centres ?: [0]))->exists();
    }

    /** @return int[] agency admins and centre directors of this agency */
    private static function overseersOf(int $agencyId): array
    {
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();

        return DB::table('role_assignments')->where('active', 1)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('agency_id', $agencyId)->where('role', 'agency_admin'))
                ->orWhere(fn ($x) => $x->where('role', 'centre_director')->where(fn ($y) => $y->where('agency_id', $agencyId)->orWhereIn('centre_id', $centres ?: [0]))))
            ->pluck('user_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
    }
}
