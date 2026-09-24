<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\SmsController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A TEXT IS JUST A MESSAGE FROM A PARENT (2026-09-24).
 *
 * Anthony: "can this be wired up to the chat system and show up as a chat from the SMS
 * user and allow for a two way chat if required?"
 *
 * A reply arriving by text was being filed where only somebody who went looking for it
 * would find it. In chat it lands where staff already read messages from that family,
 * it counts towards their unread badge, and - the part that matters - it can be
 * answered, because answering is what the thread is for.
 *
 * ONE THREAD PER FAMILY, marked channel='sms'. Not one per child: a text is not about
 * a child, it is from a person, and filing "running late" under one of three siblings
 * makes the other two look like they were not mentioned. The thread carries a child
 * only so the existing chat screens, which expect one, have something to show.
 *
 * TWO WAY, AND ONLY HERE. A staff reply typed into this thread goes back out as a
 * text; a reply typed into any other conversation does not, which is why the channel
 * is a column rather than something guessed from the subject.
 *
 * NO LOOP. Relaying only happens for a message whose `via` is null - a text that
 * arrived from the handset is written with via='sms' and is already on that handset,
 * so it is never sent back.
 */
final class SmsChatBridge
{
    public const SUBJECT = 'Text messages';
    public const CHANNEL = 'sms';

    /**
     * Put an inbound text into the family's SMS thread.
     *
     * @return int|null  the conversation it landed in, or null when there was nowhere
     *                   to put it (the sender is not a guardian of any family)
     */
    public static function relayIn(int $senderUserId, string $body): ?int
    {
        try {
            $conv = self::threadFor($senderUserId);
            if (! $conv) {
                return null;
            }

            app(ChatController::class)->insertMessage($conv, $senderUserId, $body, [], self::CHANNEL);

            DB::table('conversations')->where('id', $conv)->update(['last_message_at' => now()]);

            return $conv;
        } catch (Throwable $e) {
            Log::warning('SMS could not be bridged into chat', [
                'user' => $senderUserId, 'e' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A staff reply in an SMS thread goes back to the handset.
     *
     * Silent for every other conversation, and for a message the parent sent
     * themselves.
     */
    public static function relayOut(int $conversationId, int $senderId, string $body, int $messageId): void
    {
        if (trim($body) === '') {
            return;
        }

        $conv = DB::table('conversations')->where('id', $conversationId)
            ->first(['id', 'family_id', 'centre_id', 'channel']);
        if (! $conv || ($conv->channel ?? null) !== self::CHANNEL) {
            return;
        }

        $guardianIds = DB::table('guardians')->where('family_id', $conv->family_id)
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        // The parent's own message is already on their phone.
        if (in_array($senderId, $guardianIds, true)) {
            return;
        }

        $agencyId = (int) DB::table('centres')->where('id', $conv->centre_id)->value('agency_id');
        $sender = DB::table('users')->where('id', $senderId)
            ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")->value('n');

        /* WHO IT IS FROM, in the text itself. A parent reading a bare line on their
           phone has no thread to look at and no sender to tap - without a name it is an
           anonymous message about their child, which is the opposite of reassuring. */
        $text = trim((string) $sender) !== ''
            ? (trim((string) $sender) . ': ' . $body)
            : $body;

        foreach ($guardianIds as $gid) {
            $phone = (string) DB::table('users')->where('id', $gid)->value('phone');
            if (trim($phone) === '') {
                continue;
            }
            try {
                /* Through the ordinary sender, so consent, suppression and the agency
                   switch all still decide. A parent who opted out gets nothing, and the
                   refusal is logged and audited like every other text. */
                app(SmsController::class)->sendOne($agencyId, $gid, $phone, $text, 'chat_reply');
            } catch (Throwable $e) {
                Log::warning('Chat reply could not be texted', ['user' => $gid, 'e' => $e->getMessage()]);
            }
        }

        unset($messageId);
    }

    /**
     * The family's SMS thread, created on first use.
     *
     * Reuses the thread rather than starting one per text, so a conversation reads as a
     * conversation instead of a column of one-line threads.
     */
    private static function threadFor(int $userId): ?int
    {
        $familyId = DB::table('guardians')->where('user_id', $userId)->value('family_id');
        if (! $familyId) {
            return null;
        }

        $existing = DB::table('conversations')
            ->where('family_id', $familyId)
            ->where('channel', self::CHANNEL)
            ->whereNull('deleted_at')
            ->value('id');
        if ($existing) {
            return (int) $existing;
        }

        /* Where the family's children actually are, so the thread reaches the staff who
           look after them. Read from the live enrollment rather than families.centre_id:
           a child placed in another provider's room is exactly where those two disagree.
           Falls back to the family's own centre when nobody is enrolled yet. */
        $placement = DB::table('children as ch')
            ->join('enrollments as e', 'e.child_id', '=', 'ch.id')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->where('ch.family_id', $familyId)
            ->whereNull('e.end_date')
            ->whereNull('ch.deleted_at')
            ->first(['ch.id as child_id', 'r.centre_id']);

        $centreId = $placement->centre_id
            ?? DB::table('families')->where('id', $familyId)->value('centre_id');
        if (! $centreId) {
            return null;
        }

        return (int) DB::table('conversations')->insertGetId([
            'centre_id' => (int) $centreId,
            'family_id' => (int) $familyId,
            // Only so the existing screens have a child to show; the thread is the
            // family's, not this child's.
            'child_id' => $placement->child_id ?? null,
            'subject' => self::SUBJECT,
            'channel' => self::CHANNEL,
            'last_message_at' => now(),
            'created_at' => now(),
        ]);
    }
}
