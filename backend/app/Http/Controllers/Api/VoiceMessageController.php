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

        return response()->json([
            'id' => $messageId,
            'attachments' => $attachments,
        ], 201);
    }
}
