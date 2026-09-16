<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p63 — Voice messages.
 * Stored as an entry in messages.attachments JSON: {type:"voice", url, duration_seconds}.
 * Body is set to a short label so existing chat UI can still render a row.
 */
final class VoiceMessageController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => 'required|integer',
            'audio' => 'required|file|mimes:webm,ogg,mp3,m4a,wav|max:5120',
            'duration_seconds' => 'nullable|integer|min:1|max:300',
        ]);
        $u = $request->user();
        // Verify sender is a participant — same rules as ChatV2Controller
        $conv = DB::table('conversations')->where('id', $data['conversation_id'])->first();
        abort_unless($conv, 404);
        $isGuardian = DB::table('guardians')->where('user_id', $u->id)
            ->where('family_id', $conv->family_id)->exists();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('centre_id', $conv->centre_id)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($isGuardian || $isStaff, 403);

        $file = $request->file('audio');
        $name = $u->id . '-' . time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '', $file->getClientOriginalName());
        $path = $file->storeAs('voice-messages/' . now()->format('Y/m'), $name, 'public');

        $attachments = [[
            'type' => 'voice',
            'url' => '/storage/' . $path,
            'duration_seconds' => (int) ($data['duration_seconds'] ?? 0),
            'mime' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]];

        $messageId = DB::table('messages')->insertGetId([
            'conversation_id' => $data['conversation_id'],
            'sender_id' => $u->id,
            'body' => '🎤 Voice message',
            'attachments' => json_encode($attachments),
            'delivered_at' => now(),
            'created_at' => now(),
        ]);

        DB::table('conversations')->where('id', $data['conversation_id'])
            ->update(['last_message_at' => now()]);

        $this->notifyVoice($conv, (int) $u->id, $isGuardian);

        return response()->json([
            'id' => $messageId,
            'attachments' => $attachments,
        ], 201);
    }

    /**
     * Tell the other side a voice message arrived.
     *
     * This controller wrote the message and returned — no notification row, no push,
     * nothing. Every other chat backend announces itself; a voice note was the one kind
     * of message that reached the recipient only if they happened to open the thread.
     * Found while auditing why parents were not being notified. (2026-08-26)
     *
     * FcmService now falls back to web push when a user has no handset, so this single
     * call reaches both transports.
     */
    private function notifyVoice(object $conv, int $senderId, bool $senderIsGuardian): void
    {
        try {
            $sender = DB::table('users')->where('id', $senderId)
                ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")
                ->value('n');
            $sender = trim((string) $sender) ?: 'Someone';

            $recipients = [];
            if ($senderIsGuardian) {
                /* A parent sent it: the room's educators, plus whoever is accountable for
                   the centre. Not every member of staff — a voice note about one child is
                   not an announcement. */
                $roomId = $conv->child_id
                    ? DB::table('children')->where('id', $conv->child_id)->value('primary_room_id')
                    : null;
                if ($roomId && \Illuminate\Support\Facades\Schema::hasTable('educator_rooms')) {
                    $recipients = DB::table('educator_rooms as er')
                        ->join('users as u', 'u.id', '=', 'er.user_id')
                        ->where('er.room_id', $roomId)
                        ->whereNull('u.deleted_at')->where('u.status', 'active')
                        ->pluck('u.id')->all();
                }
                $recipients = array_merge($recipients, DB::table('role_assignments')
                    ->whereIn('role', ['centre_director', 'agency_admin'])
                    ->where('centre_id', $conv->centre_id)->where('active', 1)
                    ->pluck('user_id')->all());
            } elseif ($conv->family_id) {
                // Staff sent it: the family.
                $recipients = DB::table('guardians')->where('family_id', $conv->family_id)
                    ->pluck('user_id')->all();
            }

            $title = '🎤 Voice message from ' . $sender;
            $body = 'Tap to listen.';

            foreach (array_unique(array_filter(array_map('intval', $recipients))) as $uid) {
                if ($uid === $senderId) {
                    continue;
                }
                \App\Support\Notify::write([
                    'user_id' => $uid,
                    'type' => 'message',
                    'title' => $title,
                    'body' => $body,
                    'data' => json_encode(['link' => '#chat', 'conversation_id' => $conv->id]),
                    'created_at' => now(),
                ]);
                try {
                    app(\App\Services\FcmService::class)
                        ->sendToUser($uid, $title, $body, '#chat', true, true);
                } catch (\Throwable $e) { /* the message itself is already saved */ }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Voice message notification failed', [
                'conversation' => $conv->id ?? null, 'error' => $e->getMessage(),
            ]);
        }
    }
}
