<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving a conversation, for one person.
 *
 * Per-user by design: archiving is "I am done with this thread", not "this thread is
 * over". Hiding it from the other party as well would silently take a conversation away
 * from somebody who never asked — and unlike delete, archive reads as a private,
 * reversible tidy-up. It has to behave like one.
 *
 * One table covers both kinds. A family conversation and a staff thread are stored
 * differently and have no shared participant table, but "user X archived thing Y" is the
 * same fact in both cases and does not need two mechanisms.
 */
final class ChatArchiveController extends Controller
{
    private const KINDS = ['family', 'staff'];

    private function ensureTable(): void
    {
        if (Schema::hasTable('chat_archives')) return;
        Schema::create('chat_archives', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('kind', 16);
            $t->unsignedBigInteger('ref_id');
            $t->timestamp('archived_at')->nullable();
            $t->unique(['user_id', 'kind', 'ref_id'], 'chat_archive_once');
        });
    }

    /** GET /provider/chat-archive — what this person has archived. */
    public function index(Request $request): JsonResponse
    {
        $this->ensureTable();
        $rows = DB::table('chat_archives')->where('user_id', $request->user()->id)
            ->get(['kind', 'ref_id']);

        return response()->json([
            'family' => $rows->where('kind', 'family')->pluck('ref_id')->values(),
            'staff' => $rows->where('kind', 'staff')->pluck('ref_id')->values(),
        ]);
    }

    /** POST /provider/chat-archive — archive or restore one thread. */
    public function store(Request $request): JsonResponse
    {
        $this->ensureTable();
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:' . implode(',', self::KINDS)],
            'id' => ['required', 'integer', 'min:1'],
            'archived' => ['required', 'boolean'],
        ]);

        $uid = (int) $request->user()->id;

        // You can only archive something you are actually part of. Without this the
        // endpoint would happily record an archive row against any id in the system —
        // harmless on its own, but it leaks which ids exist.
        $allowed = $data['kind'] === 'staff'
            ? DB::table('staff_thread_participants')->where('thread_id', $data['id'])->where('user_id', $uid)->exists()
            : $this->inFamilyConversation($uid, (int) $data['id']);
        abort_unless($allowed, 404, 'Not found');

        if ($data['archived']) {
            DB::table('chat_archives')->updateOrInsert(
                ['user_id' => $uid, 'kind' => $data['kind'], 'ref_id' => $data['id']],
                ['archived_at' => now()],
            );
        } else {
            DB::table('chat_archives')
                ->where(['user_id' => $uid, 'kind' => $data['kind'], 'ref_id' => $data['id']])
                ->delete();
        }

        return response()->json(['ok' => true, 'archived' => $data['archived']]);
    }

    /**
     * A family conversation is reachable if you are a guardian of its family, or staff at
     * its centre. Mirrors how the chat list decides what to show rather than inventing a
     * second, looser rule.
     */
    private function inFamilyConversation(int $uid, int $conversationId): bool
    {
        $c = DB::table('conversations')->where('id', $conversationId)->first(['family_id', 'centre_id']);
        if (! $c) return false;

        if (DB::table('guardians')->where('family_id', $c->family_id)->where('user_id', $uid)->exists()) {
            return true;
        }

        return DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where(function ($q) use ($c) {
                $q->where('centre_id', $c->centre_id)
                  ->orWhere(function ($inner) use ($c) {
                      $agencyId = DB::table('centres')->where('id', $c->centre_id)->value('agency_id');
                      $inner->where('agency_id', $agencyId)
                            ->whereIn('role', ['agency_admin', 'platform_admin']);
                  });
            })->exists();
    }
}
