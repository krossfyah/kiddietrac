<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Jobs\SendMeetingMail;
use App\Support\Audit;
use App\Support\BookingMail;
use App\Support\CentreScope;
use App\Support\Meetings;
use App\Support\Notify;
use App\Support\UserRoles;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Meetings with attendees, like Outlook (2026-10-25). App\Support\Meetings does the invitation,
 * the .ics and Teams.
 *
 *   scope=platform: the KiddieTrac team (platform_admin, sales_rep) — Sales Calendar and Bookings.
 *   scope=agency:   the active agency's admins and directors — the agency Calendar. Directors are
 *                   held to their centres (CentreScope); agency-wide meetings they can read when
 *                   invited or when they organised them.
 */
class MeetingController extends Controller
{
    use ResolvesCentreContext;

    private function isPlatformTeam(Request $r): bool
    {
        return (bool) array_intersect(\App\Support\AgencyRole::names($r), ['platform_admin', 'sales_rep']);
    }

    private function isWide(Request $r): bool
    {
        return (bool) array_intersect(\App\Support\AgencyRole::names($r), ['platform_admin', 'agency_admin']);
    }

    /** [scope, agencyId] for this request, or a JsonResponse refusal. */
    private function context(Request $r, ?string $scope = null)
    {
        $scope = $scope ?: (string) $r->input('scope', $r->query('scope', 'agency'));
        if ($scope === 'platform') {
            return $this->isPlatformTeam($r) ? ['platform', null] : response()->json(['message' => 'Meetings here are for the KiddieTrac team.'], 403);
        }
        if ($scope !== 'agency') return response()->json(['message' => 'Unknown calendar.'], 422);
        if (! array_intersect(\App\Support\AgencyRole::names($r), ['platform_admin', 'agency_admin', 'centre_director'])) {
            return response()->json(['message' => 'Meetings are arranged by admins and directors.'], 403);
        }
        $aid = (int) ($this->resolveAgencyId($r) ?: 0);
        return $aid ? ['agency', $aid] : response()->json(['message' => 'Choose an agency first.'], 403);
    }

    /** The meeting, if this caller may see it. */
    private function find(Request $r, int $id): ?object
    {
        $m = DB::table('meetings')->where('id', $id)->first();
        if (! $m) return null;
        $uid = (int) $r->user()->id;
        if ($m->scope === 'platform') return $this->isPlatformTeam($r) ? $m : null;
        $aid = (int) ($this->resolveAgencyId($r) ?: 0);
        if ((int) $m->agency_id !== $aid) return null;
        $scope = CentreScope::ids($r, $aid);
        if (CentreScope::canRead($scope, $m->centre_id)) return $m;
        if ((int) $m->organizer_id === $uid) return $m;
        if (DB::table('meeting_attendees')->where('meeting_id', $id)->where('user_id', $uid)->exists()) return $m;
        return null;
    }

    private function canChange(Request $r, object $m): bool
    {
        if ((int) $m->organizer_id === (int) $r->user()->id) return true;
        $roles = \App\Support\AgencyRole::names($r);
        if (in_array('platform_admin', $roles, true)) return true;
        if ($m->scope === 'agency' && in_array('agency_admin', $roles, true)) return true;
        if ($m->scope === 'agency' && in_array('centre_director', $roles, true)) {
            return $m->centre_id !== null && CentreScope::canWrite(CentreScope::ids($r, (int) $m->agency_id), $m->centre_id);
        }
        return false;
    }

    /** GET /meetings?scope=&from=&to= */
    public function index(Request $r): JsonResponse
    {
        $ctx = $this->context($r);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$scope, $aid] = $ctx;
        $tz = $scope === 'agency' ? \App\Support\AgencyTime::tz($aid) : BookingMail::TZ;
        $from = Carbon::parse((string) $r->query('from', now($tz)->startOfMonth()->toDateString()), $tz)->startOfDay()->utc();
        $to = Carbon::parse((string) $r->query('to', now($tz)->endOfMonth()->toDateString()), $tz)->endOfDay()->utc();
        if ($to->diffInDays($from, true) > 93) $to = $from->copy()->addDays(93);
        $q = DB::table('meetings')->where('scope', $scope)->where('starts_at', '<', $to)->where('ends_at', '>', $from);
        if ($scope === 'agency') {
            $q->where('agency_id', $aid);
            $cs = CentreScope::ids($r, $aid);
            if ($cs !== null) {
                $uid = (int) $r->user()->id;
                $q->where(fn ($w) => $w->whereIn('centre_id', $cs ?: [0])->orWhere('organizer_id', $uid)
                    ->orWhereExists(fn ($x) => $x->from('meeting_attendees as a')->whereColumn('a.meeting_id', 'meetings.id')->where('a.user_id', $uid)));
            }
        }
        if (! $r->boolean('include_cancelled')) $q->where('status', 'scheduled');
        $uid = (int) $r->user()->id;
        return response()->json(['meetings' => $q->orderBy('starts_at')->limit(500)->get()->map(fn ($m) => Meetings::shape($m, $uid))->values()]);
    }

    /** GET /meetings/{id} */
    public function show(Request $r, int $id): JsonResponse
    {
        $m = $this->find($r, $id);
        if (! $m) return response()->json(['message' => 'That meeting is not available.'], 404);
        return response()->json(['meeting' => Meetings::shape($m, (int) $r->user()->id) + ['can_change' => $this->canChange($r, $m)]]);
    }

    /** The fields a create or a change carries. Returns [row, attendees, error]. */
    private function read(Request $r, string $scope, ?int $aid): array
    {
        $d = $r->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'all_day' => ['nullable', 'boolean'],
            'video' => ['nullable', 'in:teams,meet,link,none'],
            'join_url' => ['nullable', 'string', 'max:1000'],
            'centre_id' => ['nullable', 'integer'],
            'booking_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'attendees' => ['nullable', 'array', 'max:150'],
            'attendees.*.email' => ['required', 'email', 'max:190'],
            'attendees.*.name' => ['nullable', 'string', 'max:190'],
            'attendees.*.user_id' => ['nullable', 'integer'],
            'attendees.*.kind' => ['nullable', 'in:' . implode(',', Meetings::KINDS)],
            'attendees.*.optional' => ['nullable', 'boolean'],
        ]);
        $tz = $scope === 'agency' ? \App\Support\AgencyTime::tz($aid) : BookingMail::TZ;
        $allDay = (bool) ($d['all_day'] ?? false);
        if ($allDay) {
            $s = Carbon::parse($d['date'], $tz)->startOfDay();
            $e = Carbon::parse($d['end_date'] ?? $d['date'], $tz)->startOfDay()->addDay();
        } else {
            if (empty($d['start_time']) || empty($d['end_time'])) return [null, null, 'Choose a start and an end time.'];
            $s = Carbon::parse($d['date'] . ' ' . $d['start_time'], $tz);
            $e = Carbon::parse(($d['end_date'] ?? $d['date']) . ' ' . $d['end_time'], $tz);
        }
        if ($e->lte($s)) return [null, null, 'The meeting has to end after it starts.'];
        if ($e->diffInHours($s, true) > 24 * 14) return [null, null, 'A meeting can last up to two weeks.'];
        $video = $d['video'] ?? 'none';
        $url = trim((string) ($d['join_url'] ?? ''));
        if ($video === 'link') {
            if (! preg_match('~^https://[^\s]+$~i', $url)) return [null, null, 'Paste the full meeting link, starting with https://.'];
        }
        /* An agency meeting gets a Teams / Meet link from the organiser's own connected calendar (2026-11-25,
           ExternalCalendar): Teams on a Microsoft one, Meet on a Google one. Without one, they paste a link. */
        if ($video === 'teams' || $video === 'meet') {
            if ($scope === 'platform') {
                if ($video === 'meet') return [null, null, 'KiddieTrac team meetings use Teams.'];
            } else {
                $mine = \App\Support\ExternalCalendar::personal((int) $r->user()->id);
                $need = $video === 'teams' ? 'microsoft' : 'google';
                if (! $mine || $mine->provider !== $need || ! \App\Support\ExternalCalendar::account($mine)) {
                    return [null, null, $video === 'teams'
                        ? 'Connect your Microsoft 365 calendar (Settings, Calendar) for a Teams link, or paste your Teams link.'
                        : 'Connect your Google calendar (Settings, Calendar) for a Meet link, or paste your Meet link.'];
                }
            }
        }
        $centreId = $d['centre_id'] ?? null;
        if ($scope === 'agency') {
            $cs = CentreScope::ids($r, $aid);
            if ($centreId && ! DB::table('centres')->where('id', $centreId)->where('agency_id', $aid)->exists()) return [null, null, 'That centre is not in this agency.'];
            if ($cs !== null) {
                if (! $centreId) $centreId = $cs[0] ?? null;
                if (! $centreId || ! CentreScope::canWrite($cs, $centreId)) return [null, null, 'Directors arrange meetings for their own centres.'];
            }
        } else {
            $centreId = null;
        }
        $row = ['title' => trim($d['title']), 'description' => trim((string) ($d['description'] ?? '')) ?: null, 'location' => trim((string) ($d['location'] ?? '')) ?: null,
            'starts_at' => $s->copy()->utc(), 'ends_at' => $e->copy()->utc(), 'all_day' => $allDay, 'timezone' => $tz,
            'video' => $video, 'centre_id' => $centreId];
        if ($video === 'link') $row['join_url'] = $url;
        if ($video === 'none') { $row['join_url'] = null; }

        // People: unique by email; KiddieTrac users are matched to their account so they see it.
        $people = [];
        foreach ((array) ($d['attendees'] ?? []) as $a) {
            $email = strtolower(trim($a['email']));
            if (isset($people[$email])) continue;
            $uid = isset($a['user_id']) ? (int) $a['user_id'] : (int) DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->whereNull('deleted_at')->value('id');
            if ($uid && $scope === 'agency' && ! DB::table('role_assignments')->where('user_id', $uid)->where('agency_id', $aid)->where('active', 1)->exists()) $uid = 0;   // not this agency's person
            $people[$email] = ['email' => $email, 'name' => trim((string) ($a['name'] ?? '')) ?: null, 'user_id' => $uid ?: null,
                'kind' => $a['kind'] ?? ($uid ? 'staff' : 'guest'), 'optional' => (bool) ($a['optional'] ?? false)];
        }
        return [$row, array_values($people), null];
    }

    /** POST /meetings {scope, title, date, start_time, end_time, …, attendees[], send?} */
    public function store(Request $r): JsonResponse
    {
        $ctx = $this->context($r);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$scope, $aid] = $ctx;
        [$row, $people, $err] = $this->read($r, $scope, $aid);
        if ($err) return response()->json(['message' => $err], 422);
        $uid = (int) $r->user()->id;
        $bookingId = $scope === 'platform' ? ((int) $r->input('booking_id') ?: null) : null;
        $leadId = $scope === 'platform' ? ((int) $r->input('lead_id') ?: null) : null;

        $id = DB::transaction(function () use ($row, $people, $scope, $aid, $uid, $bookingId, $leadId) {
            $id = DB::table('meetings')->insertGetId($row + ['scope' => $scope, 'agency_id' => $aid, 'organizer_id' => $uid, 'status' => 'scheduled', 'sequence' => 0,
                'booking_id' => $bookingId, 'lead_id' => $leadId, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($people as $p) {
                DB::table('meeting_attendees')->insert($p + ['meeting_id' => $id, 'response' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            }
            return $id;
        });
        $m = DB::table('meetings')->where('id', $id)->first();

        /* THE TEAMS MEETING AND THE EMAILS GO FROM THE QUEUE (2026-11-13, portal speed sweep): they kept the save
           waiting 5-8 s on Microsoft and the mail server. SendMeetingMail does them within the minute; whatever
           fails (Teams, an email) goes on the organiser's bell. "queued" is who will be emailed - not "sent". */
        $send = $r->boolean('send', true);
        $queued = $send ? count(array_filter($people, fn ($p) => filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL))) : 0;
        SendMeetingMail::dispatch('create', $id, $send, $m->video === 'teams' && $scope === 'platform' ? 'create' : null)->onQueue('mail');
        $this->tellPeople($m, 'invite');
        Audit::write(['user_id' => $uid, 'agency_id' => $aid, 'action' => 'meeting.created', 'entity_type' => 'meeting', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Scheduled "' . $m->title . '" for ' . Meetings::whenText($m) . ' with ' . count($people) . ' attendee' . (count($people) === 1 ? '' : 's') . ($queued ? '; invitation going to ' . $queued : '')]),
            'created_at' => now()]);
        return response()->json(['meeting' => Meetings::shape($m, $uid), 'queued' => $queued, 'teams_pending' => in_array($m->video, ['teams', 'meet'], true)], 201);
    }

    /** PUT /meetings/{id} — change it; attendees hear about it (new ones get an invitation, removed ones a cancellation). */
    public function update(Request $r, int $id): JsonResponse
    {
        $m = $this->find($r, $id);
        if (! $m) return response()->json(['message' => 'That meeting is not available.'], 404);
        if (! $this->canChange($r, $m)) return response()->json(['message' => 'Only the organiser or an admin can change this meeting.'], 403);
        if ($m->status !== 'scheduled') return response()->json(['message' => 'This meeting was cancelled.'], 422);
        [$row, $people, $err] = $this->read($r, $m->scope, $m->agency_id ? (int) $m->agency_id : null);
        if ($err) return response()->json(['message' => $err], 422);
        if (in_array($m->video, ['teams', 'meet'], true) && $row['video'] === $m->video) unset($row['join_url']);   // keep the Teams / Meet link
        elseif (in_array($row['video'], ['teams', 'meet'], true)) $row['join_url'] = null;   // switched to Teams / Meet: a new link is made

        $before = Meetings::attendees($id);
        $byEmail = []; foreach ($before as $b) $byEmail[$b->email] = $b;
        $keep = []; $added = [];
        DB::transaction(function () use ($id, $row, $people, $byEmail, &$keep, &$added, $m) {
            DB::table('meetings')->where('id', $id)->update($row + ['sequence' => (int) $m->sequence + 1, 'updated_at' => now()]);
            foreach ($people as $p) {
                if (isset($byEmail[$p['email']])) {
                    DB::table('meeting_attendees')->where('id', $byEmail[$p['email']]->id)->update(['name' => $p['name'] ?: $byEmail[$p['email']]->name, 'optional' => $p['optional'], 'updated_at' => now()]);
                    $keep[] = (int) $byEmail[$p['email']]->id;
                } else {
                    $added[] = (int) DB::table('meeting_attendees')->insertGetId($p + ['meeting_id' => $id, 'response' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        $removed = array_values(array_filter($before, fn ($b) => ! in_array((int) $b->id, $keep, true)));
        $m2 = DB::table('meetings')->where('id', $id)->first();

        $teams = ($m2->video === 'teams' && $m->video !== 'teams' && $m2->scope === 'platform') ? 'create' : ($m2->video === 'teams' && $m2->scope === 'platform' ? 'move' : null);

        // Teams and the emails from the queue (see store). People taken off go now; their cancellation is
        // sent from the rows as they were.
        $send = $r->boolean('send', true);
        if ($removed) DB::table('meeting_attendees')->whereIn('id', array_map(fn ($x) => (int) $x->id, $removed))->delete();
        SendMeetingMail::dispatch('update', $id, $send, $teams, $keep, $added, $send ? array_map(fn ($x) => (array) $x, $removed) : [])->onQueue('mail');
        $queued = $send ? count($keep) + count($added) : 0;
        Audit::write(['user_id' => (int) $r->user()->id, 'agency_id' => $m->agency_id, 'action' => 'meeting.updated', 'entity_type' => 'meeting', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Changed "' . $m2->title . '" (' . Meetings::whenText($m2) . ')' . ($added ? ', added ' . count($added) : '') . ($removed ? ', removed ' . count($removed) : '')]), 'created_at' => now()]);
        return response()->json(['meeting' => Meetings::shape($m2, (int) $r->user()->id), 'queued' => $queued, 'teams_pending' => $teams === 'create']);
    }

    /** POST /meetings/{id}/cancel {reason?} */
    public function cancel(Request $r, int $id): JsonResponse
    {
        $m = $this->find($r, $id);
        if (! $m) return response()->json(['message' => 'That meeting is not available.'], 404);
        if (! $this->canChange($r, $m)) return response()->json(['message' => 'Only the organiser or an admin can cancel this meeting.'], 403);
        if ($m->status === 'cancelled') return response()->json(['ok' => true]);
        $reason = trim((string) $r->input('reason', '')) ?: null;
        DB::table('meetings')->where('id', $id)->update(['status' => 'cancelled', 'cancel_reason' => $reason ? mb_substr($reason, 0, 500) : null,
            'cancelled_at' => now(), 'sequence' => (int) $m->sequence + 1, 'updated_at' => now()]);
        $m2 = DB::table('meetings')->where('id', $id)->first();
        // Teams and the emails from the queue (see store): the cancellation goes to everyone sent the invitation.
        $send = $r->boolean('send', true);
        $queued = $send ? DB::table('meeting_attendees')->where('meeting_id', $id)->whereNotNull('invited_at')->count() : 0;
        SendMeetingMail::dispatch('cancel', $id, $send)->onQueue('mail');
        $this->tellPeople($m2, 'cancel');
        Audit::write(['user_id' => (int) $r->user()->id, 'agency_id' => $m->agency_id, 'action' => 'meeting.cancelled', 'entity_type' => 'meeting', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Cancelled "' . $m->title . '" (' . Meetings::whenText($m) . ')' . ($queued ? '; telling ' . $queued : '')]), 'created_at' => now()]);
        return response()->json(['ok' => true, 'queued' => $queued]);
    }

    /** POST /meetings/{id}/respond {response} — a signed-in attendee answers from the portal. */
    public function respond(Request $r, int $id): JsonResponse
    {
        $m = $this->find($r, $id);
        if (! $m) return response()->json(['message' => 'That meeting is not available.'], 404);
        $resp = (string) $r->input('response');
        if (! in_array($resp, ['accepted', 'tentative', 'declined'], true)) return response()->json(['message' => 'Choose accept, maybe or decline.'], 422);
        $n = DB::table('meeting_attendees')->where('meeting_id', $id)->where('user_id', (int) $r->user()->id)->update(['response' => $resp, 'responded_at' => now(), 'updated_at' => now()]);
        if (! $n) return response()->json(['message' => 'You are not on the invitation list.'], 422);
        $this->tellOrganizer($m, (int) $r->user()->id, $resp);
        return response()->json(['ok' => true]);
    }

    /**
     * GET /meetings/rsvp/{attendee}/{response}?s= — the Accept / Maybe / Decline buttons in the
     * email. No sign-in: the link is signed for this one attendee. Answers with a small page.
     */
    public function rsvp(Request $r, int $attendee, string $response)
    {
        $a = DB::table('meeting_attendees')->where('id', $attendee)->first();
        $ok = $a && Meetings::rsvpValid($attendee, (int) $a->meeting_id, (string) $r->query('s', '')) && in_array($response, ['accepted', 'tentative', 'declined'], true);
        $m = $ok ? DB::table('meetings')->where('id', $a->meeting_id)->first() : null;
        if (! $m) return response($this->page('This link is not valid', 'Ask the organiser to send the invitation again.'), 404)->header('Content-Type', 'text/html; charset=utf-8');
        if ($m->status === 'cancelled') return response($this->page('This meeting was cancelled', e($m->title) . ' &middot; ' . e(Meetings::whenText($m))))->header('Content-Type', 'text/html; charset=utf-8');
        DB::table('meeting_attendees')->where('id', $attendee)->update(['response' => $response, 'responded_at' => now(), 'updated_at' => now()]);
        $this->tellOrganizer($m, $a->user_id ? (int) $a->user_id : null, $response, $a->name ?: $a->email);
        $word = ['accepted' => 'You&rsquo;re going ✓', 'tentative' => 'Marked as maybe', 'declined' => 'You declined'][$response];
        $links = '';
        foreach (['accepted' => 'Accept', 'tentative' => 'Maybe', 'declined' => 'Decline'] as $k => $label) {
            if ($k !== $response) $links .= '<a href="' . e(Meetings::rsvpUrl($a, $k)) . '" style="margin:0 6px;color:#1F6080;font-weight:700">' . $label . ' instead</a>';
        }
        return response($this->page($word, '<strong>' . e($m->title) . '</strong><br>' . e(Meetings::whenText($m))
            . ($m->join_url ? '<br><br><a href="' . e($m->join_url) . '" style="color:#1F6080;font-weight:700">Join link</a>' : '')
            . '<br><br><span style="font-size:13px">' . $links . '</span>'))->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . strip_tags($title) . '</title>'
            . '<link rel="icon" href="https://app.kiddietrac.com/favicon.ico"></head>'
            . '<body style="margin:0;background:#EEF3F6;font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif;color:#0F172A">'
            . '<div style="max-width:460px;margin:48px auto;padding:0 16px"><div style="background:#fff;border-radius:16px;padding:26px 24px;text-align:center;box-shadow:0 10px 30px -18px rgba(15,23,42,.4)">'
            . '<div style="font-size:21px;font-weight:800;margin-bottom:10px">' . $title . '</div><div style="color:#475569">' . $body . '</div></div></div></body></html>';
    }

    /** The organiser's bell: someone answered. */
    private function tellOrganizer(object $m, ?int $whoId, string $resp, ?string $whoName = null): void
    {
        if ($whoId && $whoId === (int) $m->organizer_id) return;
        $name = $whoName ?: ($whoId ? trim((string) DB::table('users')->where('id', $whoId)->selectRaw("CONCAT(first_name,' ',last_name) n")->value('n')) : 'Someone');
        $word = ['accepted' => 'accepted', 'tentative' => 'might attend', 'declined' => 'declined'][$resp] ?? $resp;
        Notify::write([['user_id' => (int) $m->organizer_id, 'type' => 'meeting', 'title' => ($resp === 'declined' ? '✕ ' : ($resp === 'accepted' ? '✓ ' : '? ')) . $name . ' ' . $word,
            'body' => $m->title . ' · ' . Meetings::whenText($m), 'data' => json_encode(['link' => $m->scope === 'platform' ? '#sales-calendar' : '#staff-calendar']), 'created_at' => now()]]);
    }

    /** Attendees with an account also see it on their bell (the email is the invitation). */
    private function tellPeople(object $m, string $kind): void
    {
        $ids = DB::table('meeting_attendees')->where('meeting_id', $m->id)->whereNotNull('user_id')->pluck('user_id')->map(fn ($x) => (int) $x)->unique()
            ->reject(fn ($x) => $x === (int) $m->organizer_id)->values();
        if ($ids->isEmpty()) return;
        $title = ($kind === 'cancel' ? '🚫 Cancelled: ' : '📅 Meeting: ') . $m->title;
        Notify::write($ids->map(fn ($u) => ['user_id' => $u, 'type' => 'meeting', 'title' => $title, 'body' => Meetings::whenText($m),
            'data' => json_encode(['link' => $m->scope === 'platform' ? '#sales-calendar' : '#staff-calendar']), 'created_at' => now()])->all());
    }

    /**
     * GET /meetings/people?scope=&q= — who can be invited. Platform: the KiddieTrac team, sales
     * leads and line contacts. Agency: its staff and parents (a director: their centres' parents).
     * Anyone else is typed in as an email address.
     */
    public function people(Request $r): JsonResponse
    {
        $ctx = $this->context($r);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$scope, $aid] = $ctx;
        $q = trim((string) $r->query('q', ''));
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $out = [];
        $add = function ($uid, $name, $email, $kind, $sub) use (&$out) {
            $email = strtolower(trim((string) $email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($out[$email])) return;
            $out[$email] = ['user_id' => $uid ? (int) $uid : null, 'name' => trim((string) $name) ?: $email, 'email' => $email, 'kind' => $kind, 'sub' => $sub];
        };
        $nameMatch = function ($w, $a = 'u') use ($q, $like) {
            if ($q === '') return;
            $w->where(fn ($x) => $x->where($a . '.first_name', 'like', $like)->orWhere($a . '.last_name', 'like', $like)->orWhere($a . '.email', 'like', $like)
                ->orWhereRaw("CONCAT({$a}.first_name,' ',{$a}.last_name) LIKE ?", [$like]));
        };
        $ROLE = ['platform_admin' => 'KiddieTrac', 'sales_rep' => 'KiddieTrac sales', 'agency_admin' => 'Admin', 'centre_director' => 'Director', 'educator' => 'Educator', 'home_visitor' => 'Home visitor'];

        if ($scope === 'platform') {
            $u = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->whereIn('ra.role', ['platform_admin', 'sales_rep'])
                ->where('ra.active', 1)->whereNull('u.deleted_at')->whereNotNull('u.email');
            $nameMatch($u);
            foreach ($u->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'ra.role')->limit(40)->get() as $p) $add($p->id, $p->first_name . ' ' . $p->last_name, $p->email, 'staff', $ROLE[$p->role] ?? 'KiddieTrac');
            $l = DB::table('sales_leads')->whereNull('deleted_at')->whereNotNull('email');
            if ($q !== '') $l->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('company', 'like', $like)->orWhere('email', 'like', $like));
            foreach ($l->orderByDesc('last_activity_at')->limit(40)->get(['name', 'company', 'email']) as $p) $add(null, $p->name ?: $p->company, $p->email, 'lead', trim(($p->company ?: '') . ' · lead', ' ·'));
            $c = DB::table('contacts')->where('agency_id', \App\Support\LineTexts::AGENCY)->whereNull('deleted_at')->whereNotNull('email');
            if ($q !== '') $c->where(fn ($x) => $x->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('company', 'like', $like)->orWhere('email', 'like', $like));
            foreach ($c->limit(40)->get(['first_name', 'last_name', 'company', 'email']) as $p) $add(null, trim($p->first_name . ' ' . $p->last_name) ?: $p->company, $p->email, 'contact', $p->company ?: 'Contact');
        } else {
            $cs = CentreScope::ids($r, $aid);
            $s = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.agency_id', $aid)->where('ra.active', 1)
                ->whereIn('ra.role', ['agency_admin', 'centre_director', 'educator', 'home_visitor'])->whereNull('u.deleted_at')->whereNotNull('u.email');
            $nameMatch($s);
            foreach ($s->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'ra.role')->limit(60)->get() as $p) $add($p->id, $p->first_name . ' ' . $p->last_name, $p->email, 'staff', $ROLE[$p->role] ?? 'Staff');
            $g = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('c.agency_id', $aid)->whereNull('f.deleted_at')->whereNull('f.departure_applied_at')->whereNull('u.deleted_at')->whereNotNull('u.email');
            \App\Support\Live::centres($g, 'c');   // a closed centre's families are not invitees (2026-11-13, leak sweep ticket #252)
            if ($cs !== null) $g->whereIn('f.centre_id', $cs ?: [0]);
            $nameMatch($g);
            foreach ($g->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'f.family_name', 'c.name as centre')->limit(60)->get() as $p) {
                $add($p->id, $p->first_name . ' ' . $p->last_name, $p->email, 'parent', trim(($p->family_name ? $p->family_name . ' family' : 'Parent') . ' · ' . $p->centre, ' ·'));
            }
        }
        return response()->json(['people' => array_values(array_slice($out, 0, 80))]);
    }
}
