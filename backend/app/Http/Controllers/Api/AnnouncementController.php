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
            // WHO, as opposed to where. Defaults to parents because that is who every
            // announcement written before this went to.
            'audience' => ['nullable', 'in:parents,educators,contractors,admins,all'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif', 'max:5120'],
            // A PDF to go with it (2026-10-20): a newsletter, a menu, a form, a policy.
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'send_email' => ['nullable', 'boolean'],
            'send_sms' => ['nullable', 'boolean'],
            'send_push' => ['nullable', 'boolean'],
            // A phone call that reads the announcement out (2026-09-29). The reason
            // decides who may be rung, exactly as on SMS broadcast -> Voice call.
            'send_voice' => ['nullable', 'boolean'],
            'voice_category' => ['nullable', 'string', 'max:40'],
            // Not after_or_equal:now -- that compared the agency's wall-clock time with
            // UTC, so for four hours of every evening a valid time was "in the past".
            // Converted and checked below instead.
            'scheduled_at' => ['nullable', 'date'],
            // REMINDERS (2026-10-22): an optional due date, up to two reminders, and who
            // they go to. All three are the agency's wall-clock time, converted below.
            'due_at' => ['nullable', 'date'],
            'reminders' => ['nullable', 'array', 'max:2'],
            'reminders.*' => ['nullable', 'date'],
            'remind_who' => ['nullable', 'in:pending,all'],
        ]);

        // SECURITY: sanitize the rich-text body to a safe allowlist BEFORE storing
        // and blasting it into broadcast EMAILS (an educator could otherwise inject
        // phishing links / arbitrary HTML delivered to every family as trusted mail).
        $data['body'] = $this->sanitizeAnnouncementHtml((string) $data['body']);
        /* A FILE MAKES IT TEXT (2026-10-20). With a picture or PDF attached the form goes
           as multipart, where every field arrives as a string, and canBroadcast(int) threw
           a TypeError: every announcement with a picture failed with a server error. */
        $data['scope_id'] = (int) $data['scope_id'];

        $user = $request->user();
        if (! $this->canBroadcast($user->id, $data['scope_type'], $data['scope_id'])) {
            return response()->json(['message' => 'You don\'t have permission to broadcast to that scope.'], 403);
        }

        $data['audience'] = $data['audience'] ?? 'parents';

        /* SCHEDULING (2026-09-29). The composer's date box is the agency's wall-clock
           time with no zone; the app stores UTC. Stored as typed, 09:00 went out at
           05:00 in Toronto -- except nothing sent scheduled announcements at all until
           announcements:send-scheduled. Converted here so the sender compares like with
           like. A time within the next minute just sends now. */
        if (! empty($data['scheduled_at'])) {
            $sAgency = $this->resolveAgencyForScope($data['scope_type'], (int) $data['scope_id']);
            try {
                $at = \Carbon\Carbon::parse((string) $data['scheduled_at'], \App\Support\AgencyTime::tz($sAgency))->utc();
            } catch (\Throwable $e) {
                return response()->json(['message' => 'That date and time could not be read.',
                    'errors' => ['scheduled_at' => ['Not a valid date and time.']]], 422);
            }
            if ($at->lt(now()->subMinute())) {
                return response()->json(['message' => 'That time has already passed. Pick a later time, or clear it to send now.',
                    'errors' => ['scheduled_at' => ['In the past.']]], 422);
            }
            $data['scheduled_at'] = $at->lte(now()->addMinute()) ? null : $at->format('Y-m-d H:i:s');
        }

        /* DUE DATE + REMINDERS (2026-10-22). Anthony: "add sent reminder function as
           optional if you are sending an announcement to ask for something". Typed in the
           agency's time, stored UTC, like the schedule above. A reminder must come after
           the announcement itself goes out. */
        $rTz = \App\Support\AgencyTime::tz($this->resolveAgencyForScope($data['scope_type'], (int) $data['scope_id']));
        $sendsAt = ! empty($data['scheduled_at']) ? \Carbon\Carbon::parse($data['scheduled_at'], 'UTC') : now();
        $dueAt = null;
        if (! empty($data['due_at'])) {
            try {
                $dueAt = \Carbon\Carbon::parse((string) $data['due_at'], $rTz)->utc();
            } catch (\Throwable $e) {
                return response()->json(['message' => 'That due date could not be read.',
                    'errors' => ['due_at' => ['Not a valid date.']]], 422);
            }
        }
        $remindAts = [];
        foreach (array_values(array_unique(array_filter(array_map('strval', (array) ($data['reminders'] ?? []))))) as $r) {
            try {
                $at = \Carbon\Carbon::parse($r, $rTz)->utc();
            } catch (\Throwable $e) {
                return response()->json(['message' => 'A reminder time could not be read.',
                    'errors' => ['reminders' => ['Not a valid date and time.']]], 422);
            }
            if ($at->lte($sendsAt->copy()->addMinutes(5))) {
                return response()->json(['message' => 'A reminder has to be later than the announcement itself. Pick a later reminder, or a later due date.',
                    'errors' => ['reminders' => ['Before the announcement goes out.']]], 422);
            }
            $remindAts[] = $at->format('Y-m-d H:i:s');
        }
        sort($remindAts);
        $remindAts = array_slice($remindAts, 0, 2);
        // Something is being asked for when there is a due date or a reminder; only then is
        // there a "who has done it" to keep.
        $data['remind_who'] = ($dueAt || $remindAts) ? ($data['remind_who'] ?? 'pending') : null;
        $data['due_at'] = $dueAt ? $dueAt->format('Y-m-d H:i:s') : null;

        /* VOICE (2026-09-29). Anthony: "announcements add option to send via voice as
           well". A call is neither silent nor undoable, so the same people who may place
           one from SMS broadcast may place one here -- directors and agency admins, not
           every educator who can post an announcement -- and only for a reason the
           agency allows. Every per-person rule is enforced later, call by call, in
           VoiceController::callOne. */
        $data['send_voice'] = ! empty($data['send_voice']);
        $data['voice_category'] = null;
        if ($data['send_voice']) {
            $vAgency = $this->resolveAgencyForScope($data['scope_type'], (int) $data['scope_id']);
            $mayCall = $vAgency && DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)
                ->where(function ($q) use ($vAgency) {
                    $q->where('role', 'platform_admin')
                      ->orWhere(function ($w) use ($vAgency) {
                          $w->where('agency_id', $vAgency)->whereIn('role', ['agency_admin', 'centre_director']);
                      });
                })->exists();
            if (! $mayCall) {
                return response()->json(['message' => 'Only a centre director or agency admin can send an announcement as a phone call.',
                    'errors' => ['send_voice' => ['Not allowed for your role.']]], 403);
            }
            $cat = strtolower(trim((string) ($data['voice_category'] ?? $request->input('voice_category', ''))));
            if (! isset(VoiceController::CATEGORIES[$cat])) {
                return response()->json(['message' => 'Choose the reason for the phone call.',
                    'errors' => ['voice_category' => ['Required for a voice call.']]], 422);
            }
            if (! in_array($cat, VoiceController::allowedCategories($vAgency), true)) {
                return response()->json(['message' => 'Calls for "' . VoiceController::CATEGORIES[$cat] . '" are switched off for this agency. '
                    . 'Choose which reasons may place calls in Carrier settings -> Voice calls.',
                    'errors' => ['voice_category' => ['Calls are switched off for this reason.']]], 422);
            }
            $data['voice_category'] = $cat;
        }

        // Re-encoded to something a carrier will actually forward — see
        // AnnouncementImage for why the original file is never sent as-is.
        $image = null;
        if ($request->hasFile('image')) {
            $image = \App\Support\AnnouncementImage::store($request->file('image'));
            if (! $image) {
                return response()->json([
                    'message' => 'That image could not be prepared for sending. Use a JPEG, PNG or GIF '
                        .'under 5 MB — an animated GIF also has to be under about 1 MB.',
                ], 422);
            }
        }
        $data['image'] = $image;

        /* THE PDF STAYS PRIVATE (2026-10-20). Unlike the picture (which carriers must be able
           to fetch for MMS) a PDF can carry family details, so it is kept out of the public
           folder and is only ever reached through a signed, expiring link: see pdf(). */
        $pdf = null;
        if ($request->hasFile('pdf')) {
            $f = $request->file('pdf');
            $head = @file_get_contents($f->getRealPath(), false, null, 0, 5);
            if ($head !== '%PDF-') {
                return response()->json(['message' => 'That file is not a PDF. Choose a .pdf file (up to 10 MB).',
                    'errors' => ['pdf' => ['Not a PDF.']]], 422);
            }
            $name = preg_replace('/[\x00-\x1F"\\\\\/]+/', '_', (string) $f->getClientOriginalName()) ?: 'attachment.pdf';
            if (! preg_match('/\.pdf$/i', $name)) $name .= '.pdf';
            $path = 'announcement-pdfs/' . date('Y/m') . '/' . \Illuminate\Support\Str::random(40) . '.pdf';
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, file_get_contents($f->getRealPath()));
            $pdf = ['path' => $path, 'name' => mb_substr($name, 0, 200), 'size' => (int) $f->getSize()];
        }

        $announcementId = DB::table('announcements')->insertGetId([
            'scope_type' => $data['scope_type'],
            'scope_id' => $data['scope_id'],
            'audience' => $data['audience'],
            'image_path' => $image['path'] ?? null,
            'pdf_path' => $pdf['path'] ?? null,
            'pdf_name' => $pdf['name'] ?? null,
            'pdf_size' => $pdf['size'] ?? null,
            'title' => $data['title'],
            'body' => $data['body'],
            'send_email' => $data['send_email'] ?? 1,
            'send_sms' => $data['send_sms'] ?? 0,
            'send_push' => $data['send_push'] ?? 1,
            'send_voice' => $data['send_voice'] ? 1 : 0,
            'voice_category' => $data['voice_category'],
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'due_at' => $data['due_at'],
            'remind_who' => $data['remind_who'],
            'sent_at' => empty($data['scheduled_at']) ? now() : null,
            'created_by_id' => $user->id,
            'created_at' => now(),
        ]);
        foreach ($remindAts as $at) {
            DB::table('announcement_reminders')->insert([
                'announcement_id' => $announcementId, 'remind_at' => $at, 'created_at' => now(),
            ]);
        }

        $data['pdf'] = $pdf ? ['name' => $pdf['name'], 'url' => self::pdfUrl((int) $announcementId, 60)] : null;

        $delivered = 0;
        if (empty($data['scheduled_at'])) {
            $delivered = $this->deliver((int) $announcementId, $data, $user);
        }

        \App\Support\Audit::write([
            'user_id' => $user->id,
            'action' => 'announcement.sent',
            'entity_type' => 'announcement',
            'entity_id' => $announcementId,
            'payload' => json_encode([
                'scope_type' => $data['scope_type'],
                'scope_id' => $data['scope_id'],
                'audience' => $data['audience'],
                'has_image' => (bool) $image,
                'pdf' => $pdf['name'] ?? null,
                'delivered_to' => $delivered,
                'scheduled' => !empty($data['scheduled_at']),
                'voice' => $this->voiceResult,
                'due_at' => $data['due_at'],
                'reminders' => $remindAts ?: null,
                'remind_who' => $data['remind_who'],
            ]),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'announcement_id' => $announcementId,
            'delivered_to' => $delivered,
            'scheduled' => !empty($data['scheduled_at']),
            'reminders' => count($remindAts),
            'voice' => $this->voiceResult ? ['calling' => $this->voiceResult['placed'],
                'skipped' => $this->voiceResult['skipped'], 'total' => $this->voiceResult['total']] : null,
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

        // Agency IDs the user can see (agency admins + agency-attached home
        // visitors, who read their agency's announcements but can't broadcast).
        $agencyIds = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->whereIn('role', ['agency_admin', 'home_visitor'])
            ->where('active', true)
            ->whereNotNull('agency_id')
            ->pluck('agency_id')
            ->all();

        // A platform_admin (super admin) is scoped to the agency they're currently
        // acting in (X-Active-Agency-Id), not just agencies where they hold an
        // agency_admin row. Without this, announcements a super admin sent in e.g.
        // the Test Agency never appeared in the list (indexProvider saw nothing).
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatformAdmin) {
            $activeAgency = (int) $request->header('X-Active-Agency-Id');
            if ($activeAgency > 0) {
                $agencyIds[] = $activeAgency;
                $centreIds = array_merge($centreIds, DB::table('centres')->where('agency_id', $activeAgency)->pluck('id')->all());
            }
        }
        $agencyIds = array_values(array_unique($agencyIds));
        $centreIds = array_values(array_unique($centreIds));

        $roomIds = !empty($centreIds)
            ? DB::table('rooms')->whereIn('centre_id', $centreIds)->pluck('id')->all()
            : [];

        $q = DB::table('announcements')
            ->join('users', 'users.id', '=', 'announcements.created_by_id')
            ->leftJoin('centres', function ($j) {
                $j->on('centres.id', '=', 'announcements.scope_id')
                  ->where('announcements.scope_type', 'centre');
            })
            // A room-wide announcement names its room in the list (it read "room").
            ->leftJoin('rooms as ann_rooms', function ($j) {
                $j->on('ann_rooms.id', '=', 'announcements.scope_id')
                  ->where('announcements.scope_type', 'room');
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
                'centres.name as centre_name',
                'ann_rooms.name as room_name'
            );

        $rows = $q->get();
        $reminders = $this->remindersFor($rows->pluck('id')->all());
        // Just for this person (an invoice when they are also a parent; payslips live on My pay, not here).
        $personal = \App\Support\PersonalAnnouncements::forUser((int) $user->id, 20, (int) $request->header('X-Active-Agency-Id') ?: (count(array_unique($agencyIds ?? [])) === 1 ? (int) array_values(array_unique($agencyIds))[0] : null));

        /* READ / ARCHIVED / MAY DELETE, PER PERSON (2026-11-07, Anthony: "no kebab in desktop view for announcements to
           archive, delete, mark as read"). The desktop ⋮ only had Delete, and showed it on rows the person could not
           delete. Read and archived are this person's own rows in announcement_user_state (the table parents' News
           already uses; same 30-day rule for unread). can_delete is the exact rule destroyMany applies. */
        $deletable = array_flip($this->deletableIds($request, $user, array_merge($rows->pluck('id')->all(), array_column($personal, 'id'))));
        $keyOf = fn ($id, $isPersonal) => ($isPersonal ? 'p:' : 'a:') . $id;
        $keys = array_merge($rows->map(fn ($a) => $keyOf($a->id, false))->all(), array_map(fn ($a) => $keyOf($a['id'], true), $personal));
        $state = DB::table('announcement_user_state')->where('user_id', $user->id)->whereIn('item_key', $keys ?: ['-'])
            ->get(['item_key', 'read_at', 'hidden_at'])->keyBy('item_key');
        $fresh = now()->subDays(30);
        $mine = function (array $a, string $key) use ($state, $fresh, $deletable) {
            $st = $state->get($key);
            $when = $a['sent_at'] ?? ($a['created_at'] ?? null);
            $a['key'] = $key;
            $a['read'] = ! empty(optional($st)->read_at) || (! empty($when) && \Illuminate\Support\Carbon::parse($when)->lt($fresh));
            $a['archived'] = ! empty(optional($st)->hidden_at);
            $a['can_delete'] = isset($deletable[(int) $a['id']]);
            return $a;
        };
        $canMemo = [];
        return response()->json([
            'personal' => array_map(fn ($a) => $mine($a, $keyOf($a['id'], true)), $personal),
            'announcements' => $rows->map(function ($a) use ($reminders, $user, $mine, $keyOf, &$canMemo) {
                $ask = $this->askState($a, (int) $user->id, true);
                $sk = $a->scope_type . ':' . $a->scope_id;
                if (! isset($canMemo[$sk])) $canMemo[$sk] = in_array($a->scope_type, ['agency', 'centre', 'room'], true) && $this->canBroadcast((int) $user->id, $a->scope_type, (int) $a->scope_id);
                return $mine([
                    'id' => $a->id,
                    'title' => $a->title,
                    'body' => $a->body,
                    'scope_type' => $a->scope_type,
                    'scope_id' => $a->scope_id,
                    'centre_name' => $a->centre_name,
                    'room_name' => $a->room_name ?? null,
                    'due_at' => $a->due_at ?? null,
                    'remind_who' => $a->remind_who ?? null,
                    'reminders' => $reminders[$a->id] ?? [],
                    'done_count' => $ask['done_count'],
                    'recipient_count' => $ask['recipient_count'],
                    'for_me' => $ask['for_me'],
                    'my_done' => $ask['my_done'],
                    'send_email' => (bool) $a->send_email,
                    'send_push' => (bool) $a->send_push,
                    'send_voice' => (bool) ($a->send_voice ?? false),
                    'pdf_name' => $a->pdf_name ?? null,
                    'pdf_url' => ! empty($a->pdf_path) ? self::pdfUrl((int) $a->id, 7) : null,
                    'sent_at' => $a->sent_at,
                    'scheduled_at' => $a->scheduled_at,
                    'sender' => trim(($a->sender_first ?? '') . ' ' . ($a->sender_last ?? '')),
                    'created_at' => $a->created_at,
                    'audience' => $a->audience ?: 'parents',
                    'send_sms' => (bool) $a->send_sms,
                    // May see who it reached and send it again (details / resend): whoever could have sent it.
                    'can_manage' => $canMemo[$sk],
                ], $keyOf($a->id, false));
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
                    $q->whereNull('end_date')->orWhere('end_date', '>=', \App\Support\AgencyTime::today());
                })
                ->pluck('room_id')->unique()->all()
            : [];

        $rows = DB::table('announcements')
            ->whereNotNull('sent_at')
            ->where(function ($q) use ($centreIds, $roomIds, $agencyIds, $familyIds) {
                $q->orWhere(function ($qq) use ($familyIds) {
                    $qq->where('scope_type', 'family')->whereIn('scope_id', $familyIds);
                });
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

        // Personal announcements (a new invoice for THIS parent): only ever their own (scope 'user' = them).
        $mine = collect(\App\Support\PersonalAnnouncements::forUser((int) $user->id, 50, (int) $request->header('X-Active-Agency-Id') ?: (count(array_unique($agencyIds ?? [])) === 1 ? (int) array_values(array_unique($agencyIds))[0] : null)));
        $list = $rows->map(function ($a) use ($user) {
                $ask = $this->askState($a, (int) $user->id, false);
                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'body' => $a->body,
                    'due_at' => $a->due_at ?? null,
                    'track_done' => ($a->remind_who ?? null) === 'pending',
                    'my_done' => $ask['my_done'],
                    'pdf_name' => $a->pdf_name ?? null,
                    'pdf_url' => ! empty($a->pdf_path) ? self::pdfUrl((int) $a->id, 7) : null,
                    'sent_at' => $a->sent_at,
                ];
            })->concat($mine)->sortByDesc('sent_at')->values();

        /* READ / REMOVED, PER PARENT (2026-11-07, parentState). Each item carries its key and whether this parent has
           read it; ones they removed are left out. Unread is only claimed for the last 30 days, so a parent opening
           the new menu for the first time is not shown a year of news in bold. */
        $keyOf = fn ($a) => (! empty($a['personal']) ? 'p:' : 'a:') . $a['id'];
        $state = DB::table('announcement_user_state')->where('user_id', $user->id)
            ->whereIn('item_key', $list->map($keyOf)->all())
            ->get(['item_key', 'read_at', 'hidden_at'])->keyBy('item_key');
        $fresh = now()->subDays(30);
        $list = $list->filter(fn ($a) => empty(optional($state->get($keyOf($a)))->hidden_at))
            ->map(function ($a) use ($state, $keyOf, $fresh) {
                $a['key'] = $keyOf($a);
                $a['read'] = ! empty(optional($state->get($a['key']))->read_at)
                    || (! empty($a['sent_at']) && \Illuminate\Support\Carbon::parse($a['sent_at'])->lt($fresh));
                return $a;
            })->values();
        return response()->json(['announcements' => $list]);
    }

    /**
     * POST /api/v1/parent/announcements/state  { keys: ["a:12","p:5"], action: read|unread|hide|unhide }
     * Mark items in this parent's News read or unread, remove them or bring them back. Only ever the caller's own
     * rows (announcement_user_state); the announcement itself and everyone else's News are untouched.
     * Staff use it too (POST /provider/announcements/state, 2026-11-07): there "hide" is Archive, and unhide brings
     * an archived one back.
     */
    public function parentState(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keys' => 'required|array|min:1|max:200',
            'keys.*' => ['string', 'regex:/^[ap]:\d{1,12}$/'],
            'action' => 'required|in:read,unread,hide,unhide',
        ]);
        $userId = (int) $request->user()->id;
        $col = in_array($data['action'], ['read', 'unread'], true) ? 'read_at' : 'hidden_at';
        $val = in_array($data['action'], ['read', 'hide'], true) ? now() : null;
        foreach (array_unique($data['keys']) as $key) {
            $hit = DB::table('announcement_user_state')->where('user_id', $userId)->where('item_key', $key)
                ->update([$col => $val, 'updated_at' => now()]);
            if (! $hit && $val !== null) {
                DB::table('announcement_user_state')->insertOrIgnore([
                    'user_id' => $userId, 'item_key' => $key, $col => $val, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        /* Reading it here reads its alert too (2026-11-09): the bell and the 📣 must not keep claiming an announcement
           the person has just read on this screen or in the sign-in popup. */
        if ($data['action'] === 'read') {
            $ids = array_values(array_filter(array_map(fn ($k) => str_starts_with($k, 'a:') ? (int) substr($k, 2) : 0, $data['keys'])));
            if ($ids) {
                try {
                    DB::table('notifications')->where('user_id', $userId)->where('type', 'announcement')->whereNull('read_at')
                        ->where(function ($q) use ($ids) { foreach ($ids as $id) $q->orWhereJsonContains('data->announcement_id', $id); })
                        ->update(['read_at' => now()]);
                } catch (\Throwable $e) { report($e); }
            }
        }
        \App\Support\AnnouncementInbox::forget($userId);
        return response()->json(['ok' => true]);
    }

    /**
     * GET /announcements/popup — the announcements to put in front of this person when they sign in (2026-11-09,
     * Anthony: "when announcements are sent out it should popup when logging into the portal or app and have button
     * that show I have read"). The unread ones that were SENT TO THEM (they have its alert), newest first, at most 10;
     * an admin is not stopped by every notice a director sent to parents, and invoice / payslip notices stay in News.
     * "I've read this" posts to the same state endpoint as the list, so the screen, the 📣 and the bell all agree.
     */
    public function popup(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $items = array_values(array_filter(\App\Support\AnnouncementInbox::unread($request), fn ($a) => empty($a['personal'])));
        $ids = array_map(fn ($a) => (int) $a['id'], $items);
        $mine = [];
        if ($ids) {
            $mine = DB::table('notifications')->where('user_id', $uid)->where('type', 'announcement')
                ->where(function ($q) use ($ids) { foreach ($ids as $id) $q->orWhereJsonContains('data->announcement_id', $id); })
                ->pluck('data')->map(fn ($d) => (int) (json_decode((string) $d, true)['announcement_id'] ?? 0))->flip()->all();
        }
        $out = [];
        foreach ($items as $a) {
            if (! isset($mine[(int) $a['id']])) continue;
            $out[] = ['key' => $a['key'] ?? ('a:' . $a['id']), 'id' => (int) $a['id'], 'title' => $a['title'] ?? '', 'body' => $a['body'] ?? '',
                'sent_at' => $a['sent_at'] ?? null, 'sender' => $a['sender'] ?? null, 'due_at' => $a['due_at'] ?? null,
                'pdf_name' => $a['pdf_name'] ?? null, 'pdf_url' => $a['pdf_url'] ?? null, 'audience' => $a['audience']];
            if (count($out) >= 10) break;
        }

        return response()->json(['items' => $out])->header('Cache-Control', 'no-store');
    }

    /** A signed link to an announcement's PDF, valid for $days. */
    public static function pdfUrl(int $id, int $days): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('announcements.pdf', now()->addDays($days), ['id' => $id]);
    }

    /**
     * GET /api/v1/announcements/{id}/pdf?expires=&signature= — the attached PDF.
     * No sign-in: the signature IS the permission, and it is only ever handed to the
     * people the announcement went to (its email, its text, their own inbox listing).
     */
    public function pdf(Request $request, int $id)
    {
        $a = DB::table('announcements')->where('id', $id)->first(['id', 'pdf_path', 'pdf_name', 'sent_at']);
        if (! $a || empty($a->pdf_path) || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($a->pdf_path)) {
            return response()->json(['message' => 'That PDF is no longer available.'], 404);
        }
        $name = preg_replace('/[\x00-\x1F"\\\\\/]+/', '_', (string) ($a->pdf_name ?: 'announcement.pdf'));
        return response()->file(\Illuminate\Support\Facades\Storage::disk('local')->path($a->pdf_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /* ─── DETAILS + RESEND (2026-11-09) ──────────────────────────────────
       Anthony: "announcements add a resend option in the kebab including a view option to show details of the
       announcement, who it was sent it, date and all other info". For someone who could have sent it (canBroadcast,
       the rule responses() uses):
         GET  /provider/announcements/{id}/details   everything about it, and every person it reached with read / done
         POST /provider/announcements/{id}/resend    send it again {who: all|unread, email, sms, push}
       "Who it went to" is who it actually reached - the bell rows deliver() wrote - not today's room list, which has
       changed since. A resend reaches the same people again (or only those who have not read it), shows as new for
       them, and never rings phones: a call was the announcement's own, once. */

    /** The send-ready shape of a stored announcement (deliverDue and resend). */
    private function deliveryData(object $a): array
    {
        $image = null;
        if (! empty($a->image_path)) {
            $file = public_path($a->image_path);
            $image = [
                'path' => $a->image_path,
                'url' => rtrim((string) config('app.url'), '/') . '/' . ltrim($a->image_path, '/'),
                'mms_ok' => is_file($file) && filesize($file) <= 600 * 1024,
            ];
        }
        return [
            'scope_type' => $a->scope_type, 'scope_id' => (int) $a->scope_id,
            'audience' => $a->audience ?: 'parents', 'title' => $a->title, 'body' => $a->body,
            'send_email' => (bool) $a->send_email, 'send_sms' => (bool) $a->send_sms,
            'send_push' => (bool) $a->send_push, 'send_voice' => (bool) ($a->send_voice ?? false),
            'voice_category' => $a->voice_category ?? null, 'image' => $image,
            'pdf' => ! empty($a->pdf_path) ? ['name' => $a->pdf_name ?: 'attachment.pdf', 'url' => self::pdfUrl((int) $a->id, 60)] : null,
            'due_at' => $a->due_at ?? null, 'remind_who' => $a->remind_who ?? null,
        ];
    }

    /** Everyone an announcement reached: user id => first delivery time and how many times it reached them. */
    private function reachedUsers(int $id): array
    {
        $out = [];
        foreach (DB::table('notifications')->where('type', 'announcement')->whereJsonContains('data->announcement_id', $id)
            ->orderBy('created_at')->get(['user_id', 'created_at']) as $n) {
            $u = (int) $n->user_id;
            if (! isset($out[$u])) $out[$u] = ['at' => $n->created_at, 'times' => 0];
            $out[$u]['times']++;
        }
        return $out;
    }

    private const AUDIENCE_LABEL = ['parents' => 'Parents', 'educators' => 'Educators', 'contractors' => 'Contractors',
        'admins' => 'Admins and directors', 'all' => 'Everyone'];

    public function details(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $a = DB::table('announcements')->where('id', $id)->first();
        if (! $a || ! in_array($a->scope_type, ['agency', 'centre', 'room'], true)
            || ! $this->canBroadcast((int) $user->id, $a->scope_type, (int) $a->scope_id)) {
            return response()->json(['message' => 'That announcement was not found.'], 404);
        }
        $agencyId = $this->resolveAgencyForScope($a->scope_type, (int) $a->scope_id);
        $tz = \App\Support\AgencyTime::tz($agencyId);
        $fmt = fn ($ts) => $ts ? \Carbon\Carbon::parse((string) $ts, 'UTC')->tz($tz)->format('D M j, Y g:i A') : null;

        $reached = $this->reachedUsers($id);
        $ids = array_keys($reached);
        $people = $ids ? DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'preferred_name', 'email', 'phone', 'deleted_at'])->keyBy('id') : collect();
        $read = $ids ? DB::table('announcement_user_state')->where('item_key', 'a:' . $id)->whereIn('user_id', $ids)->whereNotNull('read_at')
            ->pluck('read_at', 'user_id')->all() : [];
        // The bell row read also counts (a parent who opened it from the bell, before the News state existed).
        $bellRead = $ids ? DB::table('notifications')->where('type', 'announcement')->whereJsonContains('data->announcement_id', $id)
            ->whereIn('user_id', $ids)->whereNotNull('read_at')->groupBy('user_id')->selectRaw('user_id, min(read_at) as r')->pluck('r', 'user_id')->all() : [];
        $tracks = ($a->remind_who ?? null) === 'pending';
        $done = $tracks ? array_flip($this->doneUserIds($id)) : [];
        $fam = $ids ? DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->whereIn('g.user_id', $ids)
            ->get(['g.user_id', 'f.family_name'])->groupBy('user_id') : collect();
        $staff = $ids ? DB::table('role_assignments')->whereIn('user_id', $ids)->where('active', 1)
            ->whereIn('role', ['educator', 'home_visitor', 'centre_director', 'agency_admin'])->pluck('role', 'user_id')->all() : [];
        $roleWord = ['educator' => 'Educator', 'home_visitor' => 'Home visitor', 'centre_director' => 'Director', 'agency_admin' => 'Admin'];
        $fresh = now()->subDays(30);
        $rows = [];
        foreach ($reached as $uid => $r) {
            $p = $people->get($uid);
            $rAt = $read[$uid] ?? ($bellRead[$uid] ?? null);
            $rows[] = [
                'name' => $p ? (trim(($p->preferred_name ?: $p->first_name) . ' ' . $p->last_name) ?: (string) $p->email) : '(account removed)',
                'email' => $p->email ?? null,
                'kind' => isset($staff[$uid]) ? $roleWord[$staff[$uid]] : ($fam->has($uid) ? 'Parent' : 'Member'),
                'family' => $fam->has($uid) ? $fam->get($uid)->pluck('family_name')->filter()->unique()->implode(', ') : null,
                'delivered_at' => $fmt($r['at']),
                'times' => $r['times'],
                // Older than 30 days reads as read everywhere (the same rule as the lists).
                'read' => $rAt !== null || \Carbon\Carbon::parse((string) $r['at'], 'UTC')->lt($fresh),
                'read_at' => $rAt ? $fmt($rAt) : null,
                'done' => $tracks ? isset($done[$uid]) : null,
                'removed' => $p ? ! empty($p->deleted_at) : true,
            ];
        }
        usort($rows, fn ($x, $y) => [$x['read'], $x['name']] <=> [$y['read'], $y['name']]);

        $sender = DB::table('users')->where('id', $a->created_by_id)->first(['first_name', 'last_name', 'preferred_name']);
        $resends = DB::table('audit_logs')->where('action', 'announcement.resent')->where('entity_type', 'announcement')->where('entity_id', $id)
            ->orderByDesc('created_at')->limit(20)->get(['user_id', 'payload', 'created_at']);
        $byNames = DB::table('users')->whereIn('id', $resends->pluck('user_id')->filter()->unique())->get(['id', 'first_name', 'preferred_name'])->keyBy('id');

        return response()->json([
            'id' => (int) $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'where' => $this->scopeName($a->scope_type, (int) $a->scope_id),
            'scope_type' => $a->scope_type,
            'audience' => self::AUDIENCE_LABEL[$a->audience ?: 'parents'] ?? ucfirst((string) $a->audience),
            'sender' => $sender ? trim(($sender->preferred_name ?: $sender->first_name) . ' ' . $sender->last_name) : null,
            'created_at' => $fmt($a->created_at),
            'scheduled_at' => $fmt($a->scheduled_at ?? null),
            'sent_at' => $fmt($a->sent_at ?? null),
            'due_at' => $fmt($a->due_at ?? null),
            'tracks_done' => $tracks,
            'remind_who' => $a->remind_who ?? null,
            'reminders' => array_map(fn ($r) => ['at' => $fmt($r['remind_at']), 'sent' => ! empty($r['sent_at']), 'delivered' => $r['delivered']],
                $this->remindersFor([$id])[$id] ?? []),
            'channels' => ['in_app' => true, 'push' => (bool) $a->send_push, 'email' => (bool) $a->send_email,
                'sms' => (bool) $a->send_sms, 'voice' => (bool) ($a->send_voice ?? false)],
            'voice_category' => ! empty($a->send_voice) && ! empty($a->voice_category) ? (VoiceController::CATEGORIES[$a->voice_category] ?? $a->voice_category) : null,
            'pdf_name' => $a->pdf_name ?? null,
            'pdf_url' => ! empty($a->pdf_path) ? self::pdfUrl((int) $a->id, 7) : null,
            'image_url' => ! empty($a->image_path) ? rtrim((string) config('app.url'), '/') . '/' . ltrim($a->image_path, '/') : null,
            'recipients' => $rows,
            'counts' => ['total' => count($rows), 'read' => count(array_filter($rows, fn ($r) => $r['read'])),
                'done' => $tracks ? count(array_filter($rows, fn ($r) => $r['done'])) : null],
            'resends' => $resends->map(function ($r) use ($fmt, $byNames) {
                $p = json_decode((string) $r->payload, true) ?: [];
                $by = $byNames->get($r->user_id);
                return ['at' => $fmt($r->created_at), 'by' => $by ? ($by->preferred_name ?: $by->first_name) : null,
                    'to' => (int) ($p['delivered_to'] ?? 0), 'who' => $p['who'] ?? 'all', 'channels' => $p['channels'] ?? []];
            })->values(),
            'can_resend' => ! empty($a->sent_at),
        ]);
    }

    public function resend(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'who' => ['required', 'in:all,unread'],
            'email' => ['sometimes', 'boolean'], 'sms' => ['sometimes', 'boolean'], 'push' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $a = DB::table('announcements')->where('id', $id)->first();
        if (! $a || ! in_array($a->scope_type, ['agency', 'centre', 'room'], true)
            || ! $this->canBroadcast((int) $user->id, $a->scope_type, (int) $a->scope_id)) {
            return response()->json(['message' => 'That announcement was not found.'], 404);
        }
        if (empty($a->sent_at)) {
            return response()->json(['message' => 'This one has not gone out yet - it is scheduled. It can be sent again once it has.'], 422);
        }
        // One resend at a time: a double tap must not send everyone two more copies.
        if (DB::table('audit_logs')->where('action', 'announcement.resent')->where('entity_type', 'announcement')->where('entity_id', $id)
            ->where('created_at', '>=', now()->subMinutes(2))->exists()) {
            return response()->json(['message' => 'It was just sent again a moment ago. Give it a couple of minutes before sending another.'], 429);
        }

        $reached = array_keys($this->reachedUsers($id));
        $basis = 'reached';
        if (! $reached) {   // its bell rows are gone (very old): the people it is addressed to today
            $reached = array_map('intval', $this->recipientUserIds($a->scope_type, (int) $a->scope_id, $a->audience ?: 'parents'));
            $basis = 'addressed';
        }
        // Only accounts still open: a closed or suspended account gets nothing.
        $ids = $reached ? DB::table('users')->whereIn('id', $reached)->whereNull('deleted_at')
            ->whereNotIn('status', \App\Support\Audience::OFF_STATUSES)->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
        if ($d['who'] === 'unread' && $ids) {
            $read = DB::table('announcement_user_state')->where('item_key', 'a:' . $id)->whereIn('user_id', $ids)->whereNotNull('read_at')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            $ids = array_values(array_diff($ids, $read));
        }
        if (! $ids) {
            return response()->json(['message' => $d['who'] === 'unread' ? 'Everyone it went to has already read it.' : 'Nobody it went to still has an open account.'], 422);
        }

        $data = $this->deliveryData($a);
        $data['send_email'] = (bool) ($d['email'] ?? $a->send_email);
        $data['send_sms'] = (bool) ($d['sms'] ?? $a->send_sms);
        $data['send_push'] = (bool) ($d['push'] ?? $a->send_push);
        $data['send_voice'] = false;   // never rings again
        $data['notif_title'] = '📢 Announcement (sent again) · ' . $this->scopeName($a->scope_type, (int) $a->scope_id);
        // It shows as new for them again: back in the 📣 count and the sign-in popup.
        DB::table('announcement_user_state')->where('item_key', 'a:' . $id)->whereIn('user_id', $ids)->update(['read_at' => null, 'updated_at' => now()]);
        $this->voiceResult = null;
        $delivered = $this->deliver($id, $data, $user, $ids);

        $channels = array_keys(array_filter(['in-app' => true, 'push' => $data['send_push'], 'email' => $data['send_email'], 'text' => $data['send_sms']]));
        \App\Support\Audit::write([
            'user_id' => $user->id,
            'action' => 'announcement.resent',
            'entity_type' => 'announcement',
            'entity_id' => $id,
            'payload' => json_encode([
                'summary' => 'Sent "' . $a->title . '" again to ' . $delivered . ' ' . ($delivered === 1 ? 'person' : 'people')
                    . ($d['who'] === 'unread' ? ' who had not read it' : '') . ' (' . implode(', ', $channels) . ')',
                'who' => $d['who'], 'basis' => $basis, 'delivered_to' => $delivered, 'channels' => $channels,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true, 'delivered_to' => $delivered, 'who' => $d['who'], 'channels' => $channels]);
    }

    /* ─── HELPERS ────────────────────────────────────────────────── */

    private function canBroadcast(int $userId, string $scopeType, int $scopeId): bool
    {
        // A platform_admin (super admin) may broadcast to any scope — consistent
        // with the rest of the app (ResolvesCentreContext treats platform_admin as
        // able to target any agency). Without this, a super admin acting inside an
        // agency they don't directly administer (e.g. the Test Agency) hit 403 on
        // send even though the composer + centre list loaded fine.
        if (DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists()) {
            /* Only inside the agency they switched into (2026-11-05 leak sweep): a platform admin could name any
               scope_id and broadcast into an agency other than the one they chose. */
            $active = (int) request()->header('X-Active-Agency-Id');
            if ($active > 0 && $this->resolveAgencyForScope($scopeType, $scopeId) === $active) return true;
            // Otherwise their own agency roles below still count, as for anyone.
        }

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
            /* A director only to a centre they direct (2026-11-05 leak sweep): the agency_id branch let any director of
               the agency broadcast to every centre's families. Agency-wide stays with agency admins. */
            return DB::table('role_assignments')
                ->where('user_id', $userId)
                ->where('active', true)
                ->where(function ($q) use ($scopeId, $centre) {
                    $q->where(fn ($d) => $d->where('centre_id', $scopeId)->where('role', 'centre_director'))
                      ->orWhere(fn ($a) => $a->where('agency_id', $centre->agency_id)->where('role', 'agency_admin'));
                })
                ->exists();
        }
        if ($scopeType === 'room') {
            $room = DB::table('rooms')->where('id', $scopeId)->first();
            if (! $room) return false;
            $centre = DB::table('centres')->where('id', $room->centre_id)->first();
            if (! $centre) return false;
            /* Educators and directors only to rooms at their own centre (2026-11-05 leak sweep): the agency_id branch
               let any educator of the agency broadcast to any room's families. Agency-wide stays with agency admins. */
            return DB::table('role_assignments')
                ->where('user_id', $userId)
                ->where('active', true)
                ->where(function ($q) use ($room, $centre) {
                    $q->where(fn ($c) => $c->where('centre_id', $room->centre_id)->whereIn('role', ['centre_director', 'educator']))
                      ->orWhere(fn ($a) => $a->where('agency_id', $centre->agency_id)->where('role', 'agency_admin'));
                })
                ->exists();
        }
        return false;
    }

    /**
     * Send every scheduled announcement whose time has come (announcements:send-scheduled,
     * every minute). Each row is CLAIMED by setting sent_at only where it is still null,
     * so two overlapping runs cannot both send it; the claim happens before delivery
     * because a half-sent announcement retried would email and ring everyone twice.
     * Returns one line per announcement for the command's output.
     */
    public function deliverDue(int $limit = 25): array
    {
        $out = [];
        $due = DB::table('announcements')->whereNull('sent_at')->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit($limit)->get();
        foreach ($due as $a) {
            $claimed = DB::table('announcements')->where('id', $a->id)->whereNull('sent_at')->update(['sent_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $sender = DB::table('users')->where('id', $a->created_by_id)->first();
            $data = $this->deliveryData($a);
            $image = $data['image'];
            $this->voiceResult = null;
            $delivered = 0;
            try {
                $delivered = $this->deliver((int) $a->id, $data, $sender ?: (object) ['id' => null]);
            } catch (\Throwable $e) {
                Log::error('Scheduled announcement failed', ['id' => $a->id, 'error' => $e->getMessage()]);
            }
            $late = (int) max(0, round((now()->getTimestamp() - strtotime($a->scheduled_at . ' UTC')) / 60));
            \App\Support\Audit::write([
                'user_id' => $a->created_by_id,
                'action' => 'announcement.sent',
                'entity_type' => 'announcement',
                'entity_id' => $a->id,
                'payload' => json_encode([
                    'scope_type' => $a->scope_type, 'scope_id' => (int) $a->scope_id,
                    'audience' => $a->audience, 'has_image' => (bool) $image, 'pdf' => $a->pdf_name ?? null,
                    'delivered_to' => $delivered, 'scheduled' => true,
                    'scheduled_for' => $a->scheduled_at, 'minutes_late' => $late,
                    'voice' => $this->voiceResult,
                ]),
                'created_at' => now(),
            ]);
            $out[] = '#' . $a->id . ' "' . $a->title . '" -> ' . $delivered . ' recipient(s)'
                . ($this->voiceResult ? ', calling ' . $this->voiceResult['placed'] . ', skipped ' . $this->voiceResult['skipped'] : '')
                . ($late > 5 ? ' (' . $late . ' min late)' : '');
        }

        return array_merge($out, $this->deliverReminders($limit));
    }

    /* ─── REMINDERS (2026-10-22) ─────────────────────────────────────── */

    /**
     * Send every reminder whose time has come, for announcements that have gone out.
     * Claimed like a scheduled announcement (sent_at set where still null, before
     * delivery). 'pending' reminds only those who have not tapped Done — a parent counts
     * as done when anyone in their family has; 'all' reminds everyone again. Never a
     * phone call: a reminder is a nudge, and the call (if any) was the announcement.
     */
    private function deliverReminders(int $limit): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('announcement_reminders')) {
            return [];
        }
        $out = [];
        $due = DB::table('announcement_reminders as r')->join('announcements as a', 'a.id', '=', 'r.announcement_id')
            ->whereNull('r.sent_at')->where('r.remind_at', '<=', now())->whereNotNull('a.sent_at')
            ->orderBy('r.remind_at')->limit($limit)->get(['r.id as reminder_id', 'r.remind_at', 'a.*']);
        foreach ($due as $a) {
            $claimed = DB::table('announcement_reminders')->where('id', $a->reminder_id)->whereNull('sent_at')->update(['sent_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $ids = ($a->remind_who ?? 'all') === 'pending'
                ? $this->pendingUserIds($a)
                : $this->recipientUserIds($a->scope_type, (int) $a->scope_id, $a->audience ?: 'parents');
            $delivered = 0;
            if ($ids) {
                $sender = DB::table('users')->where('id', $a->created_by_id)->first();
                $data = [
                    'scope_type' => $a->scope_type, 'scope_id' => (int) $a->scope_id,
                    'audience' => $a->audience ?: 'parents', 'title' => $a->title, 'body' => $a->body,
                    'send_email' => (bool) $a->send_email, 'send_sms' => (bool) $a->send_sms,
                    'send_push' => (bool) $a->send_push, 'send_voice' => false, 'voice_category' => null,
                    'image' => null,
                    'pdf' => ! empty($a->pdf_path) ? ['name' => $a->pdf_name ?: 'attachment.pdf', 'url' => self::pdfUrl((int) $a->id, 60)] : null,
                    'due_at' => $a->due_at ?? null, 'remind_who' => $a->remind_who ?? null, 'is_reminder' => true,
                ];
                try {
                    $delivered = $this->deliver((int) $a->id, $data, $sender ?: (object) ['id' => null], $ids);
                } catch (\Throwable $e) {
                    Log::error('Announcement reminder failed', ['id' => $a->id, 'reminder' => $a->reminder_id, 'error' => $e->getMessage()]);
                }
            }
            DB::table('announcement_reminders')->where('id', $a->reminder_id)->update(['delivered' => $delivered]);
            \App\Support\Audit::write([
                'user_id' => $a->created_by_id,
                'action' => 'announcement.reminder_sent',
                'entity_type' => 'announcement',
                'entity_id' => $a->id,
                'payload' => json_encode([
                    'summary' => 'Reminder for "' . $a->title . '" went to ' . $delivered . ' '
                        . (($a->remind_who ?? 'all') === 'pending' ? 'people who had not marked it done' : 'people (everyone again)') . '.',
                    'reminder_at' => $a->remind_at, 'remind_who' => $a->remind_who, 'delivered_to' => $delivered,
                ]),
                'created_at' => now(),
            ]);
            $out[] = 'reminder #' . $a->reminder_id . ' for #' . $a->id . ' "' . $a->title . '" -> ' . $delivered . ' recipient(s)';
        }

        return $out;
    }

    /** Recipients who have not done it. A parent is done when anyone in their family is. */
    private function pendingUserIds(object $a): array
    {
        $all = $this->recipientUserIds($a->scope_type, (int) $a->scope_id, $a->audience ?: 'parents');

        return array_values(array_diff($all, $this->doneUserIds((int) $a->id)));
    }

    /** Everyone who counts as done: those who tapped it, plus their families' other guardians. */
    private function doneUserIds(int $announcementId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('announcement_done')) {
            return [];
        }
        $tapped = DB::table('announcement_done')->where('announcement_id', $announcementId)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        if (! $tapped) {
            return [];
        }
        $families = DB::table('guardians')->whereIn('user_id', $tapped)->pluck('family_id')->all();
        $kin = $families ? DB::table('guardians')->whereIn('family_id', $families)->pluck('user_id')->map(fn ($v) => (int) $v)->all() : [];

        return array_values(array_unique(array_merge($tapped, $kin)));
    }

    /** Reminders per announcement, for the lists. */
    private function remindersFor(array $ids): array
    {
        if (! $ids || ! \Illuminate\Support\Facades\Schema::hasTable('announcement_reminders')) {
            return [];
        }
        $out = [];
        foreach (DB::table('announcement_reminders')->whereIn('announcement_id', $ids)->orderBy('remind_at')
            ->get(['announcement_id', 'remind_at', 'sent_at', 'delivered']) as $r) {
            $out[$r->announcement_id][] = ['remind_at' => $r->remind_at, 'sent_at' => $r->sent_at, 'delivered' => $r->delivered];
        }

        return $out;
    }

    /**
     * For one announcement and one viewer: is this an ask, is it for them, have they done
     * it, and (for the sender's list) how many of how many. Only an announcement in
     * 'pending' mode has a Done to count; the recipient list is only worked out for those.
     */
    private function askState(object $a, int $userId, bool $withCounts): array
    {
        $none = ['done_count' => null, 'recipient_count' => null, 'for_me' => false, 'my_done' => false];
        if (($a->remind_who ?? null) !== 'pending' || empty($a->sent_at)) {
            return $none;
        }
        $done = $this->doneUserIds((int) $a->id);
        $out = $none;
        $out['my_done'] = in_array($userId, $done, true);
        if ($withCounts) {
            $all = $this->recipientUserIds($a->scope_type, (int) $a->scope_id, $a->audience ?: 'parents');
            $out['recipient_count'] = count($all);
            $out['done_count'] = count(array_intersect($all, $done));
            $out['for_me'] = in_array($userId, array_map('intval', $all), true);
        }

        return $out;
    }

    /**
     * POST /{provider|parent}/announcements/{id}/done  {done: true|false}
     * The person it was sent to says they have done what it asked (or takes it back).
     */
    public function markDone(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $a = DB::table('announcements')->where('id', $id)->first();
        if (! $a || empty($a->sent_at) || ($a->remind_who ?? null) !== 'pending') {
            return response()->json(['message' => 'That announcement is not asking for anything.'], 404);
        }
        $all = array_map('intval', $this->recipientUserIds($a->scope_type, (int) $a->scope_id, $a->audience ?: 'parents'));
        if (! in_array((int) $user->id, $all, true)) {
            return response()->json(['message' => 'That announcement was not sent to you.'], 403);
        }
        $done = $request->boolean('done', true);
        if ($done) {
            DB::table('announcement_done')->updateOrInsert(
                ['announcement_id' => $id, 'user_id' => $user->id],
                ['done_at' => now()]
            );
            if ($a->scope_type === 'family') {
                try { \App\Support\Supplies::doneByParent((int) $id, (int) $user->id); } catch (\Throwable $e) {}
            }
        } else {
            // Taking it back undoes it for the family: whoever in it tapped Done.
            $families = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->all();
            $kin = $families ? DB::table('guardians')->whereIn('family_id', $families)->pluck('user_id')->all() : [];
            DB::table('announcement_done')->where('announcement_id', $id)
                ->whereIn('user_id', array_values(array_unique(array_merge([(int) $user->id], array_map('intval', $kin)))))->delete();
        }
        \App\Support\Audit::write([
            'user_id' => $user->id,
            'action' => $done ? 'announcement.marked_done' : 'announcement.unmarked_done',
            'entity_type' => 'announcement',
            'entity_id' => $id,
            'payload' => json_encode(['summary' => ($done ? 'Marked "' : 'Took back "done" on "') . $a->title . '"' . ($done ? ' as done.' : '.')]),
            'created_at' => now(),
        ]);

        return response()->json(['done' => $done, 'my_done' => in_array((int) $user->id, $this->doneUserIds($id), true)]);
    }

    /**
     * GET /provider/announcements/{id}/responses — who has done it and who has not, for
     * someone who could have sent it.
     */
    public function responses(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $a = DB::table('announcements')->where('id', $id)->first();
        if (! $a || ! $this->canBroadcast((int) $user->id, $a->scope_type, (int) $a->scope_id)) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $all = array_map('intval', $this->recipientUserIds($a->scope_type, (int) $a->scope_id, $a->audience ?: 'parents'));
        $tapped = DB::table('announcement_done')->where('announcement_id', $id)->pluck('done_at', 'user_id')->all();
        $doneIds = $this->doneUserIds($id);
        $people = $all ? DB::table('users')->whereIn('id', $all)->get(['id', 'first_name', 'last_name', 'email']) : collect();
        $fam = $all ? DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('g.user_id', $all)->get(['g.user_id', 'g.family_id', 'f.family_name as name'])->groupBy('user_id') : collect();
        $names = $people->keyBy('id');
        $rows = $people->map(function ($p) use ($tapped, $doneIds, $fam, $names) {
            $f = $fam->get($p->id);
            $by = null;
            if (in_array((int) $p->id, $doneIds, true) && ! isset($tapped[$p->id]) && $f) {
                // Done through someone else in the family: say who.
                $kin = DB::table('guardians')->whereIn('family_id', $f->pluck('family_id'))->pluck('user_id')->all();
                foreach ($kin as $k) {
                    if (isset($tapped[$k])) {
                        $u = $names->get($k) ?: DB::table('users')->where('id', $k)->first(['first_name', 'last_name']);
                        $by = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
                        break;
                    }
                }
            }
            return [
                'name' => trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')) ?: $p->email,
                'family' => $f ? $f->pluck('name')->filter()->unique()->implode(', ') : null,
                'done' => in_array((int) $p->id, $doneIds, true),
                'done_at' => $tapped[$p->id] ?? null,
                'done_by' => $by,
            ];
        })->sortBy([['done', 'asc'], ['name', 'asc']])->values();

        return response()->json([
            'title' => $a->title, 'due_at' => $a->due_at, 'remind_who' => $a->remind_who,
            'total' => $rows->count(), 'done' => $rows->where('done', true)->count(), 'people' => $rows,
        ]);
    }

    /** Supply requests (App\Support\Supplies) send their family-scoped announcement through the same delivery. */
    public function sendFamilyNotice(int $announcementId, array $data, $sender): int
    {
        return $this->deliver($announcementId, $data, $sender);
    }

    /** Filled by deliver() when the announcement also went out as phone calls. */
    private ?array $voiceResult = null;

    private function deliver(int $announcementId, array $data, $sender, ?array $onlyUserIds = null): int
    {
        // A reminder passes exactly who it is for (2026-10-22); everything else works it out.
        $userIds = $onlyUserIds ?? $this->recipientUserIds($data['scope_type'], (int) $data['scope_id'],
            $data['audience'] ?? 'parents');
        // v22p88: body is now rich HTML. Keep a plain-text version for the
        // in-app notification and SMS.
        $plain = trim(html_entity_decode(strip_tags(str_replace(
            ['</p>', '<br>', '<br/>', '<br />', '</li>'], "\n", (string) $data['body']
        )), ENT_QUOTES));
        $senderName = trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? ''));
        $agencyId = $this->resolveAgencyForScope($data['scope_type'], (int) $data['scope_id']);

        // Notification framing (requested): "Announcement · <Centre/Room name>"
        // as the title, then the announcement itself as the body.
        $scopeName = $this->scopeName($data['scope_type'], (int) $data['scope_id']);
        $notifTitle = $data['notif_title'] ?? ('📢 Announcement · ' . $scopeName);
        $pushLink = $data['link'] ?? '#announcements';

        /* THE ASK (2026-10-22): a due date reads first, everywhere; a reminder says it is
           one; in 'pending' mode people are told how to stop the reminders. */
        $isReminder = ! empty($data['is_reminder']);
        $dueTxt = null;
        if (! empty($data['due_at'])) {
            try {
                $dueTxt = \Carbon\Carbon::parse((string) $data['due_at'], 'UTC')
                    ->tz(\App\Support\AgencyTime::tz($agencyId))->format('l, M j, g:i A');
            } catch (\Throwable $e) {}
        }
        $tracks = ($data['remind_who'] ?? null) === 'pending';
        if ($isReminder) {
            $notifTitle = '⏰ Reminder · ' . $scopeName;
            $data['title'] = 'Reminder: ' . ($data['title'] ?? '');
        }
        if ($dueTxt) {
            $plain = 'Please do this by ' . $dueTxt . '.' . ($plain !== '' ? "\n" . $plain : '');
        }
        $notifBody = trim(($data['title'] ?? '') . ($plain !== '' ? ' — ' . $plain : ''));

        $delivered = 0;
        foreach ($userIds as $uid) {
            \App\Support\Notify::write([
                'user_id' => $uid,
                'type' => 'announcement',
                'title' => $notifTitle,
                'body' => mb_substr($notifBody, 0, 1000),
                'data' => json_encode(['announcement_id' => $announcementId, 'link' => $pushLink]),   // where the push goes
                'created_at' => now(),
            ]);
            \App\Support\AnnouncementInbox::forget((int) $uid);   // their 📣 and sign-in popup see it now, not in 20 s
            $delivered++;
        }

        // Best-effort email — rich HTML, white-labelled via the agency's mailer.
        if (! empty($data['send_email']) || $data['send_email'] === null) {
            // Deactivated and suspended accounts get nothing — this path never asked.
            $emails = DB::table('users')->whereIn('id', $userIds)->whereNotNull('email')
                ->whereNull('deleted_at')
                ->whereNotIn('status', \App\Support\Audience::OFF_STATUSES)
                ->pluck('email')->all();
            // Subject mirrors the in-app notification: "📢 Announcement · <Centre> — <title>".
            /* OUT OF THE JUNK FOLDER (2026-10-23). Safia's announcements reached some
               families' junk. The subject led with an emoji and a generic word
               ("📢 Announcement · iLearn Home Child Care — …"), which reads as bulk
               promotion; it is now the announcement's own title and who it is from. The
               bell and the push keep the 📢 framing. */
            $emailSubject = trim((($data['title'] ?? '') !== '' ? $data['title'] : 'Announcement') . ' — ' . $scopeName);
            // Replies reach the person who wrote it, not a no-reply mailbox.
            $replyTo = (! empty($sender->email) && filter_var($sender->email, FILTER_VALIDATE_EMAIL)) ? (string) $sender->email : null;
            $replyName = $senderName ?: null;
            $eyebrow = '<div style="font-size:12px;font-weight:700;color:#8EC73C;letter-spacing:.06em;text-transform:uppercase;margin:0 0 6px;">' . e($data['notif_title'] ?? ('📢 Announcement · ' . $scopeName)) . '</div>';
            // Signature shows the full name of the sender AND which centre it is from.
            $sig = $senderName
                ? '<p style="color:#6B7280;margin-top:22px;line-height:1.5;">— <strong style="color:#374151;">' . e($senderName) . '</strong><br><span style="font-size:13px;">' . e($scopeName) . '</span></p>'
                : '<p style="color:#6B7280;margin-top:22px;font-size:13px;">— ' . e($scopeName) . '</p>';
            $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;color:#111827;">'
                . $eyebrow
                . '<h2 style="color:#1F6080;margin:0 0 12px;">' . e($data['title']) . '</h2>'
                . ($dueTxt ? '<div style="background:#FFFBEB;border:1px solid #FCD34D;border-radius:9px;padding:10px 14px;margin:0 0 14px;font-size:14.5px;color:#92400E;">⏰ Please do this by <strong>' . e($dueTxt) . '</strong></div>' : '')
                . '<div style="font-size:15px;line-height:1.6;">' . $data['body'] . '</div>'
                . ($tracks ? '<p style="margin:16px 0 0;font-size:13.5px;color:#475569;">Once it is done, open <strong>Announcements</strong> in the app and tap <strong>✓ Done</strong>, so we stop reminding you.</p>' : '')
                . (! empty($data['pdf']) ? '<p style="margin:18px 0 4px;"><a href="' . e($data['pdf']['url']) . '" style="display:inline-block;background:#1F6080;color:#fff;text-decoration:none;font-weight:700;padding:10px 16px;border-radius:9px;">📄 Open the PDF: ' . e($data['pdf']['name']) . '</a></p>'
                    . '<p style="margin:0;font-size:12px;color:#94A3B8;">The link works for 60 days.</p>' : '')
                . $sig
                . '</div>';
            // Queue each email on the background 'mail' queue instead of sending
            // inline. Sending is ~1.3–5.5s per email via sendmail; looping N
            // recipients synchronously made "Send" hang for many seconds. The
            // scheduled queue:work worker drains these right after. Capture only
            // serializable scalars ($agencyId, not the mailer object) and rebuild
            // the agency mailer inside the job.
            /* Paced (2026-10-23): five every ten seconds rather than the whole list in one
               burst. Dozens of identical messages in the same second from one mailbox is
               exactly what Outlook.com and Hotmail filter hardest, and that is where the
               lowest open rates were. */
            foreach (array_values($emails) as $i => $email) {
                dispatch(function () use ($email, $emailSubject, $html, $agencyId, $replyTo, $replyName) {
                    try {
                        $svc = $agencyId ? \App\Services\AgencyMailer::forAgency($agencyId) : null;
                        if ($svc) {
                            $m = $svc->mailer(); $from = $svc->fromAddress(); $fn = $svc->fromName();
                            $m->html($html, function ($msg) use ($email, $from, $fn, $emailSubject, $agencyId, $replyTo, $replyName) { \App\Support\MailScope::agency($msg, $agencyId); $msg->to($email)->from($from, $fn)->subject($emailSubject); if ($replyTo) { $msg->replyTo($replyTo, $replyName); } });
                        } else {
                            \Illuminate\Support\Facades\Mail::html($html, function ($msg) use ($email, $emailSubject, $agencyId, $replyTo, $replyName) { \App\Support\MailScope::agency($msg, $agencyId); $msg->to($email)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($emailSubject); if ($replyTo) { $msg->replyTo($replyTo, $replyName); } });
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Announcement email failed', ['email' => $email, 'error' => $e->getMessage()]);
                    }
                })->onQueue('mail')->delay(now()->addSeconds(intdiv($i, 5) * 10));
            }
        }

        // Best-effort SMS — plain text via Twilio, with the picture where it will go.
        if (! empty($data['send_sms']) && $agencyId) {
            $img = $data['image'] ?? null;
            $sms = ($data['title'] ? $data['title'] . ': ' : '') . $plain;

            // Room for the link, when there is one to add. Trimming after appending
            // would cut the link off, which is the one part that has to survive.
            $tail = ($img && ! $img['mms_ok']) ? "\n" . $img['url'] : '';
            if (! empty($data['pdf'])) $tail .= "\nPDF: " . $data['pdf']['url'];
            $room = 600 - mb_strlen($tail);
            if (mb_strlen($sms) > $room) $sms = mb_substr($sms, 0, max(1, $room - 1)) . '…';
            $sms .= $tail;

            $recips = DB::table('users')->whereIn('id', $userIds)->whereNotNull('phone')->where('phone', '!=', '')->get(['id', 'phone']);
            $smsCtl = app(SmsController::class);
            foreach ($recips as $r) {
                try {
                    // Attempted as a picture message first. Whether a given number takes
                    // MMS is not knowable in advance — you find out from the send — so
                    // sendOne falls back to the same text with the link on failure.
                    $smsCtl->sendOne($agencyId, (int) $r->id, (string) $r->phone, $sms, 'announcement',
                        ($img && $img['mms_ok']) ? $img['url'] : null);
                } catch (\Throwable $e) {
                    Log::warning('Announcement SMS failed', ['user' => $r->id, 'error' => $e->getMessage()]);
                }
            }
        }

        // A phone call that reads the announcement out. Every gate lives in callOne:
        // the agency's voice switch and reasons, each person's own choices, "don't
        // phone me", consent for a non-emergency, one call per handset.
        if (! empty($data['send_voice']) && $agencyId && ! empty($data['voice_category'])) {
            $script = trim(($data['title'] ? rtrim($data['title'], " .!?") . '. ' : '') . preg_replace('/\s+/', ' ', $plain));
            $script = 'Announcement from ' . $scopeName . '. ' . $script;
            if (mb_strlen($script) > 800) $script = mb_substr($script, 0, 799) . '…';

            $recips = DB::table('users')->whereIn('id', $userIds)->whereNotNull('phone')->where('phone', '!=', '')
                ->get(['id', 'phone', 'first_name', 'last_name']);
            $voiceCtl = app(VoiceController::class);
            $placed = 0; $skipped = 0; $called = [];
            foreach ($recips as $r) {
                try {
                    $ok = $voiceCtl->callOne($agencyId, (int) $r->id, (string) $r->phone, $script,
                        $data['voice_category'], ((int) ($sender->id ?? 0)) ?: null);
                } catch (\Throwable $e) {
                    $ok = false;
                    Log::warning('Announcement call failed', ['user' => $r->id, 'error' => $e->getMessage()]);
                }
                $ok ? $placed++ : $skipped++;
                if ($ok) $called[] = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) . ' <' . $r->phone . '>';
            }
            // People with no number were never dialled, but they are part of the answer
            // to "who did not get a call".
            $noPhone = count($userIds) - $recips->count();
            $this->voiceResult = ['category' => $data['voice_category'], 'placed' => $placed,
                'skipped' => $skipped + max(0, $noPhone), 'total' => count($userIds), 'called' => $called];
        }

        // v23: actually push an OS notification when "In-app notification" is on.
        // Previously deliver() only wrote the notifications row + email/SMS, so
        // announcements never reached the Android notification bar. Send BOTH the
        // native FCM push (Capacitor app) and web push (browser PWA). link
        // '#announcements' so tapping the notification deep-links to the section.
        if (! empty($data['send_push'])) {
            $pushBody = mb_substr($notifBody, 0, 180);
            try {
                $fcm = app(\App\Services\FcmService::class);
                foreach ($userIds as $uid) {
                    $fcm->sendToUser((int) $uid, $notifTitle, $pushBody, $pushLink, true);
                }
            } catch (\Throwable $e) { Log::warning('Announcement FCM push failed', ['error' => $e->getMessage()]); }
            try {
                app(\App\Services\WebPushService::class)->sendToUsers($userIds, [
                    'title' => $notifTitle,
                    'body'  => $pushBody,
                    'icon'  => '/icon-192.png',
                    'url'   => '/dashboard.html' . $pushLink,
                    'tag'   => 'announcement',
                ]);
            } catch (\Throwable $e) { Log::warning('Announcement web push failed', ['error' => $e->getMessage()]); }
        }

        return $delivered;
    }

    // Human name of the target scope, for the notification title
    // ("Announcement · <Centre/Room name>").
    private function scopeName(string $type, int $id): string
    {
        if ($type === 'centre') {
            return (string) (DB::table('centres')->where('id', $id)->value('name') ?: 'your centre');
        }
        if ($type === 'room') {
            $room = DB::table('rooms')->where('id', $id)->first(['name', 'centre_id']);
            if (! $room) return 'your room';
            $cn = $room->centre_id ? DB::table('centres')->where('id', $room->centre_id)->value('name') : null;
            return trim(($room->name ?: 'room') . ($cn ? ' · ' . $cn : ''));
        }
        if ($type === 'agency') {
            return (string) (DB::table('agencies')->where('id', $id)->value('name') ?: 'your agency');
        }
        if ($type === 'family') {
            $cid = DB::table('families')->where('id', $id)->value('centre_id');
            return (string) (($cid ? DB::table('centres')->where('id', $cid)->value('name') : null) ?: 'your centre');
        }
        return 'KiddieTrac';
    }

    private function resolveAgencyForScope(string $type, int $id): ?int
    {
        if ($type === 'agency') return $id;
        if ($type === 'centre') { $v = DB::table('centres')->where('id', $id)->value('agency_id'); return $v ? (int) $v : null; }
        if ($type === 'room') {
            $cid = DB::table('rooms')->where('id', $id)->value('centre_id');
            $v = $cid ? DB::table('centres')->where('id', $cid)->value('agency_id') : null;
            return $v ? (int) $v : null;
        }
        if ($type === 'family') {
            $cid = DB::table('families')->where('id', $id)->value('centre_id');
            $v = $cid ? DB::table('centres')->where('id', $cid)->value('agency_id') : null;
            return $v ? (int) $v : null;
        }
        return null;
    }

    /**
     * Which staff roles each audience means.
     *
     * 'contractors' is not a role — there was no such concept, and no role in use meant
     * it — so it is a per-person flag on the user instead of something guessed at.
     */
    private const AUDIENCE_ROLES = [
        'educators' => ['educator'],
        'admins' => ['agency_admin', 'centre_director'],
    ];

    /**
     * Staff in scope for an audience.
     *
     * Scoped to the agency the announcement belongs to; when the announcement is aimed at
     * one centre, staff attached to a DIFFERENT centre of the same agency are left out —
     * a notice about one centre's closure is not news at another.
     */
    private function staffUserIds(string $scopeType, int $scopeId, string $audience): array
    {
        $agencyId = $this->resolveAgencyForScope($scopeType, $scopeId);
        if (! $agencyId) {
            return [];
        }

        $q = DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', 1);

        // Contractors are a per-person flag, not a role — App\Support\Audience holds
        // the single definition now that Messenger needs the same answer.
        if ($audience === 'contractors') {
            return \App\Support\Audience::staff('contractors', $agencyId,
                $scopeType === 'centre' ? (int) $scopeId : null);
        }

        $roles = self::AUDIENCE_ROLES[$audience] ?? [];
        if (! $roles) {
            return [];
        }
        $q->whereIn('role', $roles);

        // A centre-scoped announcement reaches that centre's staff, plus agency-wide
        // staff who carry no centre of their own.
        if ($scopeType === 'centre') {
            $q->where(fn ($w) => $w->where('centre_id', $scopeId)->orWhereNull('centre_id'));
        }

        return $q->pluck('user_id')->unique()->all();
    }

    private function recipientUserIds(string $scopeType, int $scopeId, string $audience = 'parents'): array
    {
        if ($scopeType === 'user') return [(int) $scopeId];
        // Everything below this line resolves PARENTS. Staff are a different query
        // entirely, because they hang off role_assignments rather than off a family.
        if ($audience === 'all') {
            $ids = array_merge(
                $this->recipientUserIds($scopeType, $scopeId, 'parents'),
                $this->staffUserIds($scopeType, $scopeId, 'educators'),
                $this->staffUserIds($scopeType, $scopeId, 'admins'),
                $this->staffUserIds($scopeType, $scopeId, 'contractors'),
            );

            return array_values(array_unique(array_map('intval', $ids)));
        }
        if ($audience !== 'parents') {
            return array_values(array_unique(array_map('intval',
                $this->staffUserIds($scopeType, $scopeId, $audience))));
        }

        /* PARENTS WHO ARE STILL HERE (2026-10-09). A family counts only if it has not
           left AND still has a child with us (enrolled or waitlisted), at an OPEN centre.
           "Every family row on the centre" reached a parent whose only child had been
           withdrawn, on a provider that had closed. */
        $liveFamilies = function (array $familyIds): array {
            if (! $familyIds) return [];
            return \App\Support\Live::families(DB::table('families as f'), 'f')
                ->whereIn('f.id', $familyIds)
                ->whereExists(fn ($q) => \App\Support\Live::children($q->from('children as lc')->whereColumn('lc.family_id', 'f.id'), 'lc'))
                ->pluck('f.id')->all();
        };

        // Find all guardian user_ids in the scope
        if ($scopeType === 'centre') {
            if (! \App\Support\Live::centreOpen((int) $scopeId)) return [];
            $familyIds = $liveFamilies(DB::table('families')->where('centre_id', $scopeId)->pluck('id')->all());
            if (empty($familyIds)) return [];
            return DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id')->unique()->all();
        }
        if ($scopeType === 'room') {
            $childIds = DB::table('enrollments')
                ->where('room_id', $scopeId)
                ->where(function ($q) {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', \App\Support\AgencyTime::today());
                })
                ->pluck('child_id')->unique()->all();
            if (empty($childIds)) return [];
            $familyIds = $liveFamilies(\App\Support\Live::children(DB::table('children'))->whereIn('id', $childIds)->pluck('family_id')->unique()->all());
            return DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id')->unique()->all();
        }
        if ($scopeType === 'agency') {
            $centreIds = \App\Support\Live::centreIds((int) $scopeId);
            if (empty($centreIds)) return [];
            $familyIds = $liveFamilies(DB::table('families')->whereIn('centre_id', $centreIds)->pluck('id')->all());
            return DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id')->unique()->all();
        }
        // ONE FAMILY (2026-10-23): supply requests (App\Support\Supplies) are asked of a family.
        if ($scopeType === 'family') {
            $familyIds = $liveFamilies([(int) $scopeId]);
            return $familyIds ? DB::table('guardians')->whereIn('family_id', $familyIds)->whereNotNull('user_id')->pluck('user_id')->unique()->all() : [];
        }
        return [];
    }

    /**
     * Delete announcements the caller's own centres/agency own.
     *
     * Body: {ids: [1,2,3]} — bulk, or a single id via the route.
     *
     * An announcement is a centre-wide record, so deleting it removes it for
     * everyone it was sent to; we therefore also purge the inbox notifications
     * it generated (they carry data.announcement_id), otherwise recipients would
     * be left with a notification pointing at something that no longer exists.
     */
    public function destroyMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $allowed = $this->deletableIds($request, $request->user(), $data['ids']);

        if (!$allowed) {
            return response()->json(['message' => 'Nothing you can delete.'], 403);
        }
        return $this->deleteAnnouncements($allowed);
    }

    /** The ids in $ids this person may delete for everyone (their centre's or agency's, ones they sent, or a notice
     *  addressed to them alone). Used by destroyMany and by the staff list's can_delete. */
    private function deletableIds(Request $request, $user, array $ids): array
    {
        if (! $ids) return [];
        $centreIds = $this->myCentres($user->id);
        $agencyIds = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->whereIn('role', ['agency_admin', 'platform_admin'])
            ->where('active', true)
            ->whereNotNull('agency_id')
            ->pluck('agency_id')->all();

        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatformAdmin) {
            $active = (int) $request->header('X-Active-Agency-Id');
            if ($active > 0) {
                $agencyIds[] = $active;
                $centreIds = array_merge($centreIds, DB::table('centres')->where('agency_id', $active)->pluck('id')->all());
            }
        }

        // Only rows this user's scope owns — anything else is silently skipped
        // rather than deleted.
        return DB::table('announcements')
            ->whereIn('id', $ids)
            ->where(function ($q) use ($centreIds, $agencyIds, $user) {
                $q->where(function ($w) use ($centreIds) {
                    $w->where('scope_type', 'centre')->whereIn('scope_id', $centreIds ?: [0]);
                })->orWhere(function ($w) use ($agencyIds) {
                    $w->where('scope_type', 'agency')->whereIn('scope_id', $agencyIds ?: [0]);
                })->orWhere('created_by_id', $user->id)
                  // A notice addressed to this one person (payslip, invoice): theirs to clear (2026-11-03).
                  ->orWhere(function ($w) use ($user) {
                      $w->where('scope_type', 'user')->where('scope_id', $user->id);
                  });
            })
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function deleteAnnouncements(array $allowed): JsonResponse
    {
        foreach ($allowed as $aid) {
            DB::table('notifications')
                ->where('type', 'announcement')
                ->whereJsonContains('data->announcement_id', (int) $aid)
                ->delete();
        }
        // The private PDF goes with its announcement; its link stops working at once.
        $pdfs = DB::table('announcements')->whereIn('id', $allowed)->whereNotNull('pdf_path')->pluck('pdf_path')->all();
        foreach (['announcement_reminders', 'announcement_done'] as $tbl) {
            if (\Illuminate\Support\Facades\Schema::hasTable($tbl)) {
                DB::table($tbl)->whereIn('announcement_id', $allowed)->delete();
            }
        }
        $deleted = DB::table('announcements')->whereIn('id', $allowed)->delete();
        foreach ($pdfs as $pp) {
            try { \Illuminate\Support\Facades\Storage::disk('local')->delete($pp); } catch (\Throwable $e) {}
        }

        return response()->json(['deleted' => $deleted, 'ids' => $allowed]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->merge(['ids' => [$id]]);
        return $this->destroyMany($request);
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

    /**
     * Sanitize rich-text announcement HTML to a safe allowlist (tags + attributes)
     * via a DOMDocument re-parse — regex sanitizers get bypassed. Drops scripts/styles/
     * iframes/etc. and their contents, strips all attributes except safe http(s)/mailto
     * hrefs on <a>, and unwraps unknown tags (keeping their text). Runs on store so the
     * value is clean everywhere it is later used (in-app render AND broadcast email).
     */
    private function sanitizeAnnouncementHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $allowed = ['a', 'b', 'strong', 'i', 'em', 'u', 's', 'p', 'br', 'ul', 'ol', 'li', 'span', 'div', 'h2', 'h3', 'h4', 'blockquote'];
        $drop = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta', 'svg', 'math', 'base', 'noscript', 'template'];
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        $body = $doc->getElementsByTagName('body')->item(0);
        $wrap = $body ? $body->firstChild : null;
        if (! $wrap) {
            return '';
        }
        $this->cleanAnnouncementNode($wrap, $allowed, $drop);
        $out = '';
        foreach (iterator_to_array($wrap->childNodes) as $ch) {
            $out .= $doc->saveHTML($ch);
        }
        $out = trim($out);
        return mb_substr($out, 0, 8000);
    }

    private function cleanAnnouncementNode(\DOMNode $node, array $allowed, array $drop): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $child->parentNode->removeChild($child);
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue; // keep text nodes
            }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, $drop, true)) {
                $child->parentNode->removeChild($child); // drop element AND its contents
                continue;
            }
            if (! in_array($tag, $allowed, true)) {
                $this->cleanAnnouncementNode($child, $allowed, $drop);
                while ($child->firstChild) {
                    $child->parentNode->insertBefore($child->firstChild, $child); // unwrap
                }
                $child->parentNode->removeChild($child);
                continue;
            }
            if ($child->hasAttributes()) {
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $keep = ($tag === 'a' && strtolower($attr->name) === 'href'
                        && preg_match('#^\s*(https?:|mailto:)#i', (string) $attr->value));
                    if (! $keep) {
                        $child->removeAttribute($attr->name);
                    }
                }
            }
            if ($tag === 'a' && $child->getAttribute('href') !== '') {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer nofollow');
            }
            $this->cleanAnnouncementNode($child, $allowed, $drop);
        }
    }
}
