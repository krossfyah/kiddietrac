<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * v13: Broadcast announcements.
 *
 * Schema:
 *   announcements(id, scope_type enum('agency','centre','room'), scope_id,
 *                 title, body, send_email, send_sms, send_push,
 *                 scheduled_at?, sent_at?, created_by_id, created_at)
 *   notifications(user_id, type, title, body, data, read_at, created_at)
 */
final class AnnouncementController extends Controller
{
    /**
     * POST /api/v1/provider/announcements
     * Compose + send (or schedule) a broadcast.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => ['required', 'in:agency,centre,room'],
            'scope_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'send_email' => ['nullable', 'boolean'],
            'send_push' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
        ]);

        $user = $request->user();
        if (! $this->canBroadcast($user->id, $data['scope_type'], $data['scope_id'])) {
            return response()->json(['message' => 'You don\'t have permission to broadcast to that scope.'], 403);
        }

        $announcementId = DB::table('announcements')->insertGetId([
            'scope_type' => $data['scope_type'],
            'scope_id' => $data['scope_id'],
            'title' => $data['title'],
            'body' => $data['body'],
            'send_email' => $data['send_email'] ?? 1,
            'send_sms' => 0,
            'send_push' => $data['send_push'] ?? 1,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'sent_at' => empty($data['scheduled_at']) ? now() : null,
            'created_by_id' => $user->id,
            'created_at' => now(),
        ]);

        $delivered = 0;
        if (empty($data['scheduled_at'])) {
            $delivered = $this->deliver((int) $announcementId, $data, $user);
        }

        DB::table('audit_logs')->insert([
            'user_id' => $user->id,
            'action' => 'announcement.sent',
            'entity_type' => 'announcement',
            'entity_id' => $announcementId,
            'payload' => json_encode([
                'scope_type' => $data['scope_type'],
                'scope_id' => $data['scope_id'],
                'delivered_to' => $delivered,
                'scheduled' => !empty($data['scheduled_at']),
            ]),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'announcement_id' => $announcementId,
            'delivered_to' => $delivered,
            'scheduled' => !empty($data['scheduled_at']),
        ], 201);
    }

    /**
     * GET /api/v1/provider/announcements?centre_id=X
     * Director: list announcements they've sent or were sent to their centre.
     */
    public function indexProvider(Request $request): JsonResponse
    {
        $user = $request->user();
        $centreIds = $this->myCentres($user->id);

        // Agency IDs the user can see
        $agencyIds = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->whereIn('role', ['agency_admin'])
            ->where('active', true)
            ->whereNotNull('agency_id')
            ->pluck('agency_id')
            ->all();

        $roomIds = !empty($centreIds)
            ? DB::table('rooms')->whereIn('centre_id', $centreIds)->pluck('id')->all()
            : [];

        $q = DB::table('announcements')
            ->join('users', 'users.id', '=', 'announcements.created_by_id')
            ->leftJoin('centres', function ($j) {
                $j->on('centres.id', '=', 'announcements.scope_id')
                  ->where('announcements.scope_type', 'centre');
            })
            ->where(function ($q) use ($centreIds, $roomIds, $agencyIds) {
                if (!empty($centreIds)) {
                    $q->orWhere(function ($qq) use ($centreIds) {
                        $qq->where('announcements.scope_type', 'centre')->whereIn('announcements.scope_id', $centreIds);
                    });
                }
                if (!empty($roomIds)) {
                    $q->orWhere(function ($qq) use ($roomIds) {
                        $qq->where('announcements.scope_type', 'room')->whereIn('announcements.scope_id', $roomIds);
                    });
                }
                if (!empty($agencyIds)) {
                    $q->orWhere(function ($qq) use ($agencyIds) {
                        $qq->where('announcements.scope_type', 'agency')->whereIn('announcements.scope_id', $agencyIds);
                    });
                }
            })
            ->orderByDesc('announcements.created_at')
            ->limit(50)
            ->select(
                'announcements.*',
                'users.first_name as sender_first',
                'users.last_name as sender_last',
                'centres.name as centre_name'
            );

        return response()->json([
            'announcements' => $q->get()->map(function ($a) {
                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'body' => $a->body,
                    'scope_type' => $a->scope_type,
                    'scope_id' => $a->scope_id,
                    'centre_name' => $a->centre_name,
                    'send_email' => (bool) $a->send_email,
                    'send_push' => (bool) $a->send_push,
                    'sent_at' => $a->sent_at,
                    'scheduled_at' => $a->scheduled_at,
                    'sender' => trim(($a->sender_first ?? '') . ' ' . ($a->sender_last ?? '')),
                    'created_at' => $a->created_at,
                ];
            }),
        ]);
    }

    /**
     * GET /api/v1/parent/announcements
     * Parent's inbox: all announcements they would have received.
     */
    public function indexParent(Request $request): JsonResponse
    {
        $user = $request->user();

        // Find: family_ids → centre_ids; centres → agencies; children → rooms (via active enrollments)
        $familyIds = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->all();
        if (empty($familyIds)) return response()->json(['announcements' => []]);

        $centreIds = DB::table('families')->whereIn('id', $familyIds)->pluck('centre_id')->unique()->all();
        $agencyIds = !empty($centreIds)
            ? DB::table('centres')->whereIn('id', $centreIds)->pluck('agency_id')->unique()->all()
            : [];

        $childIds = DB::table('children')->whereIn('family_id', $familyIds)->whereNull('deleted_at')->pluck('id')->all();
        $roomIds = !empty($childIds)
            ? DB::table('enrollments')
                ->whereIn('child_id', $childIds)
                ->where(function ($q) {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
                })
                ->pluck('room_id')->unique()->all()
            : [];

        $rows = DB::table('announcements')
            ->whereNotNull('sent_at')
            ->where(function ($q) use ($centreIds, $roomIds, $agencyIds) {
                if (!empty($centreIds)) {
                    $q->orWhere(function ($qq) use ($centreIds) {
                        $qq->where('scope_type', 'centre')->whereIn('scope_id', $centreIds);
                    });
                }
                if (!empty($roomIds)) {
                    $q->orWhere(function ($qq) use ($roomIds) {
                        $qq->where('scope_type', 'room')->whereIn('scope_id', $roomIds);
                    });
                }
                if (!empty($agencyIds)) {
                    $q->orWhere(function ($qq) use ($agencyIds) {
                        $qq->where('scope_type', 'agency')->whereIn('scope_id', $agencyIds);
                    });
                }
            })
            ->orderByDesc('sent_at')
            ->limit(100)
            ->get();

        return response()->json([
            'announcements' => $rows->map(function ($a) {
                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'body' => $a->body,
                    'sent_at' => $a->sent_at,
                ];
            }),
        ]);
    }

    /* ─── HELPERS ────────────────────────────────────────────────── */

    private function canBroadcast(int $userId, string $scopeType, int $scopeId): bool
    {
        if ($scopeType === 'agency') {
            return DB::table('role_assignments')
                ->where('user_id', $userId)
                ->where('role', 'agency_admin')
                ->where('agency_id', $scopeId)
                ->where('active', true)
                ->exists();
        }
        if ($scopeType === 'centre') {
            $centre = DB::table('centres')->where('id', $scopeId)->first();
            if (! $centre) return false;
            return DB::table('role_assignments')
                ->where('user_id', $userId)
                ->whereIn('role', ['agency_admin', 'centre_director'])
                ->where('active', true)
                ->where(function ($q) use ($scopeId, $centre) {
                    $q->where('centre_id', $scopeId)
                      ->orWhere('agency_id', $centre->agency_id);
                })
                ->exists();
        }
        if ($scopeType === 'room') {
            $room = DB::table('rooms')->where('id', $scopeId)->first();
            if (! $room) return false;
            $centre = DB::table('centres')->where('id', $room->centre_id)->first();
            return DB::table('role_assignments')
                ->where('user_id', $userId)
                ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
                ->where('active', true)
                ->where(function ($q) use ($room, $centre) {
                    $q->where('centre_id', $room->centre_id)
                      ->orWhere('agency_id', $centre->agency_id);
                })
                ->exists();
        }
        return false;
    }

    private function deliver(int $announcementId, array $data, $sender): int
    {
        $userIds = $this->recipientUserIds($data['scope_type'], (int) $data['scope_id']);
        $delivered = 0;
        foreach ($userIds as $uid) {
            DB::table('notifications')->insert([
                'user_id' => $uid,
                'type' => 'announcement',
                'title' => $data['title'],
                'body' => $data['body'],
                'data' => json_encode(['announcement_id' => $announcementId]),
                'created_at' => now(),
            ]);
            $delivered++;
        }

        // Best-effort email
        if (! empty($data['send_email']) || $data['send_email'] === null) {
            $emails = DB::table('users')->whereIn('id', $userIds)->pluck('email')->all();
            foreach ($emails as $email) {
                try {
                    $body = $data['body'] . "\n\n— " . trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? ''));
                    Mail::raw($body, function ($m) use ($email, $data) {
                        $m->to($email)->from('noreply@kiddietrac.com', 'Kiddietrac')->subject($data['title']);
                    });
                } catch (\Throwable $e) {
                    Log::warning('Announcement email failed', ['email' => $email, 'error' => $e->getMessage()]);
                }
            }
        }

        return $delivered;
    }

    private function recipientUserIds(string $scopeType, int $scopeId): array
    {
        // Find all guardian user_ids in the scope
        if ($scopeType === 'centre') {
            $familyIds = DB::table('families')->where('centre_id', $scopeId)->whereNull('deleted_at')->pluck('id')->all();
            if (empty($familyIds)) return [];
            return DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id')->unique()->all();
        }
        if ($scopeType === 'room') {
            $childIds = DB::table('enrollments')
                ->where('room_id', $scopeId)
                ->where(function ($q) {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
                })
                ->pluck('child_id')->unique()->all();
            if (empty($childIds)) return [];
            $familyIds = DB::table('children')->whereIn('id', $childIds)->whereNull('deleted_at')->pluck('family_id')->unique()->all();
            return DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id')->unique()->all();
        }
        if ($scopeType === 'agency') {
            $centreIds = DB::table('centres')->where('agency_id', $scopeId)->whereNull('deleted_at')->pluck('id')->all();
            if (empty($centreIds)) return [];
            $familyIds = DB::table('families')->whereIn('centre_id', $centreIds)->whereNull('deleted_at')->pluck('id')->all();
            return DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id')->unique()->all();
        }
        return [];
    }

    private function myCentres(int $userId): array
    {
        $direct = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereIn('role', ['educator', 'centre_director'])
            ->where('active', true)
            ->whereNotNull('centre_id')
            ->pluck('centre_id')->all();

        $agencyIds = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->pluck('agency_id')->all();

        if (!empty($agencyIds)) {
            $agencyCentres = DB::table('centres')->whereIn('agency_id', $agencyIds)->pluck('id')->all();
            $direct = array_unique(array_merge($direct, $agencyCentres));
        }
        return $direct;
    }
}
