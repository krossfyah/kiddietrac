<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BookingMail;
use App\Support\BookingTeams;
use App\Support\PlatformSettings;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales → Bookings (2026-10-23): website meeting requests are accepted, modified or
 * declined here before the visitor hears anything but "we received your request".
 * Accept / modify send the full confirmation (Teams link, agenda, how to prepare, .ics);
 * decline sends a courteous note with a link to choose another time. See BookingMail,
 * BookingTeams. Routes sit in the sales group (role sales_rep / platform_admin).
 */
class SalesBookingController extends Controller
{
    private const DEFAULT_LINK = 'sales.booking.teams_link';

    /** Bell + push to the sales team when a request comes in. */
    public static function alertSales(object $b): void
    {
        try {
            $ids = DB::table('role_assignments')->whereIn('role', ['platform_admin', 'sales_rep'])->where('active', true)
                ->distinct()->limit(25)->pluck('user_id');
            $title = '📅 New meeting request';
            $body = $b->name . ($b->agency ? ' (' . $b->agency . ')' : '') . ' — ' . BookingMail::typeName($b) . ', ' . BookingMail::whenEt($b->starts_at) . '. Accept, modify or decline.';
            foreach ($ids as $uid) {
                \App\Support\Notify::write(['user_id' => (int) $uid, 'type' => 'booking_request', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => '#sales-bookings?id=' . (int) $b->id]), 'created_at' => now()]);
                app(\App\Services\FcmService::class)->sendToUser((int) $uid, $title, $body, '#sales-bookings?id=' . (int) $b->id);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('booking sales alert failed', ['booking' => $b->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    private function present(object $b): array
    {
        $host = $b->host_user_id ? DB::table('users')->where('id', $b->host_user_id)->first(['first_name', 'last_name']) : null;
        return [
            'id' => (int) $b->id, 'status' => $b->status, 'type' => $b->type, 'type_name' => BookingMail::typeName($b), 'duration_min' => (int) $b->duration_min,
            'starts_at' => Carbon::parse($b->starts_at, 'UTC')->toIso8601String(), 'when_et' => BookingMail::whenEt($b->starts_at), 'when_local' => BookingMail::whenLocal($b),
            'local_date' => Carbon::parse($b->starts_at, 'UTC')->tz(BookingMail::TZ)->toDateString(), 'local_time' => Carbon::parse($b->starts_at, 'UTC')->tz(BookingMail::TZ)->format('H:i'),
            'requested_et' => $b->requested_starts_at && $b->requested_starts_at !== $b->starts_at ? BookingMail::whenEt($b->requested_starts_at) : null,
            'name' => $b->name, 'email' => $b->email, 'phone' => $b->phone, 'agency' => $b->agency, 'children' => $b->children, 'notes' => $b->notes,
            'meet_via' => $b->meet_via, 'teams_url' => $b->teams_url, 'invitees' => BookingTeams::invitees($b), 'sales_note' => $b->sales_note, 'decline_reason' => $b->decline_reason,
            'host' => $host ? trim($host->first_name . ' ' . $host->last_name) : null, 'decided_at' => $b->decided_at, 'created_at' => $b->created_at,
            'sales_lead_id' => $b->sales_lead_id, 'confirm_mail' => $b->confirm_mail,
            'intake' => (function () use ($b) {
                $i = DB::table('prospect_intakes')->where('booking_id', $b->id)->first();
                if (! $i) return null;
                $a = json_decode($i->answers, true) ?: [];
                $rows = [];
                foreach (ProspectIntakeController::labels() as $k => $l) if (isset($a[$k])) $rows[] = [$l, is_array($a[$k]) ? implode(', ', $a[$k]) : $a[$k]];
                return ['submitted_at' => $i->submitted_at, 'rows' => $rows];
            })(),
            'intake_url' => ProspectIntakeController::url($b),
        ];
    }

    /** GET /sales/bookings?view=pending|upcoming|past|all */
    public function index(Request $request): JsonResponse
    {
        $view = (string) $request->query('view', 'pending');
        $q = DB::table('marketing_bookings');
        if ($view === 'pending') $q->where('status', 'requested')->orderBy('starts_at');
        elseif ($view === 'upcoming') $q->where('status', 'booked')->where('ends_at', '>=', now())->orderBy('starts_at');
        elseif ($view === 'past') $q->where(fn ($w) => $w->where('ends_at', '<', now())->orWhereIn('status', ['declined', 'cancelled']))->orderByDesc('starts_at');
        else $q->orderByDesc('starts_at');
        $rows = $q->limit(200)->get()->map(fn ($b) => $this->present($b));
        return response()->json(['bookings' => $rows, 'counts' => [
            'pending' => DB::table('marketing_bookings')->where('status', 'requested')->count(),
            'upcoming' => DB::table('marketing_bookings')->where('status', 'booked')->where('ends_at', '>=', now())->count(),
        ], 'teams_auto' => BookingTeams::available(), 'default_link' => (string) PlatformSettings::get(self::DEFAULT_LINK, ''),
           'types' => collect(BookingMail::TYPES)->map(fn ($t, $k) => ['id' => $k, 'name' => $t['name'], 'min' => $t['min']])->values()]);
    }

    /**
     * GET /sales/bookings/calendar?view=upcoming|past|all (2026-11-04) - the meetings on the sales@ Outlook calendar that
     * did NOT come from the website (those are the cards above, by marketing_bookings.teams_event_id). Read-only: they
     * are changed in Outlook. Read live from Graph (SalesMeetings::events), held 3 minutes so tab switches stay quick.
     */
    public function calendar(Request $request): JsonResponse
    {
        $view = in_array($request->query('view'), ['upcoming', 'past', 'all'], true) ? $request->query('view') : 'upcoming';
        $key = 'sales.bookings.outlook.' . $view;
        $rows = \Illuminate\Support\Facades\Cache::get($key);
        if ($rows === null) {
            [$from, $to] = match ($view) {
                'upcoming' => [now()->subHour(), now()->addDays(60)],
                'past' => [now()->subDays(60), now()],
                default => [now()->subDays(60), now()->addDays(60)],
            };
            $events = \App\Support\SalesMeetings::events($from, $to);
            if ($events === null) return response()->json(['meetings' => [], 'readable' => false]);
            $website = DB::table('marketing_bookings')->whereNotNull('teams_event_id')->pluck('teams_event_id')->flip();
            $leads = \App\Support\SalesMeetings::leadsByEmail();
            $names = DB::table('sales_leads')->whereIn('id', array_unique(array_values($leads)) ?: [0])->pluck('name', 'id');
            $rows = [];
            foreach ($events as $e) {
                if (isset($website[$e['id']])) continue;
                $start = Carbon::parse($e['start']['dateTime'], 'UTC'); $end = Carbon::parse($e['end']['dateTime'], 'UTC');
                $cancelled = ! empty($e['isCancelled']);
                if ($view === 'upcoming' && ($cancelled || $end->isPast())) continue;
                if ($view === 'past' && ! $cancelled && ! $end->isPast()) continue;
                $people = []; $lead = null;
                foreach ($e['attendees'] ?? [] as $a) {
                    $email = strtolower(trim((string) ($a['emailAddress']['address'] ?? '')));
                    if ($email === '' || $email === strtolower(BookingTeams::MAILBOX)) continue;
                    $people[] = ['name' => (string) ($a['emailAddress']['name'] ?? ''), 'email' => $email];
                    if (! $lead && isset($leads[$email])) $lead = ['id' => $leads[$email], 'name' => (string) ($names[$leads[$email]] ?? '')];
                }
                $org = $e['organizer']['emailAddress'] ?? [];
                $rows[] = [
                    'key' => substr(sha1($e['id']), 0, 16), 'subject' => (string) ($e['subject'] ?? '') ?: 'Meeting',
                    'starts_at' => $start->toIso8601String(), 'when_et' => \App\Support\SalesMeetings::whenEt($start),
                    'duration_min' => (int) round($start->diffInMinutes($end)), 'cancelled' => $cancelled, 'ended' => $end->isPast(),
                    'join_url' => $e['onlineMeeting']['joinUrl'] ?? null, 'location' => (string) ($e['location']['displayName'] ?? '') ?: null,
                    'web_link' => $e['webLink'] ?? null,
                    'organizer' => strtolower((string) ($org['address'] ?? '')) === strtolower(BookingTeams::MAILBOX) ? null : ((string) ($org['name'] ?? '') ?: ($org['address'] ?? null)),
                    'attendees' => $people, 'lead' => $lead,
                ];
            }
            usort($rows, fn ($a, $b) => $view === 'past' ? strcmp($b['starts_at'], $a['starts_at']) : strcmp($a['starts_at'], $b['starts_at']));
            \Illuminate\Support\Facades\Cache::put($key, $rows, 180);
        }
        return response()->json(['meetings' => $rows, 'readable' => true, 'mailbox' => BookingTeams::MAILBOX]);
    }

    /** The Teams link for a confirmed video meeting: given, made in Microsoft 365, or the saved default. */
    private function teams(object $b, ?string $given): array
    {
        if ($b->meet_via === 'phone') return ['url' => null, 'event_id' => null];
        if ($given) {
            if ($b->teams_event_id) { BookingTeams::move($b, BookingMail::typeName($b)); return ['url' => $given, 'event_id' => $b->teams_event_id]; }
            // An Outlook meeting carrying the pasted link, with the visitor (and any extra people) invited.
            return ['url' => $given, 'event_id' => BookingTeams::createPlain($b, BookingMail::typeName($b), $given)];
        }
        if ($b->teams_url && $b->teams_event_id) {
            BookingTeams::move($b, BookingMail::typeName($b));
            return ['url' => $b->teams_url, 'event_id' => $b->teams_event_id];
        }
        $made = BookingTeams::create($b, BookingMail::typeName($b));
        if (! empty($made['url'])) return $made;
        $def = trim((string) PlatformSettings::get(self::DEFAULT_LINK, ''));
        if ($def !== '') return ['url' => $def, 'event_id' => BookingTeams::createPlain($b, BookingMail::typeName($b), $def)];
        if ($b->teams_url) return ['url' => $b->teams_url, 'event_id' => null];
        abort(response()->json(['message' => ($made['error'] ?? '') === 'permission'
            ? 'KiddieTrac can\'t create Teams meetings yet (the Microsoft 365 calendar permission is not granted). Paste a Teams link, or save a default link in Booking settings.'
            : 'The Teams meeting could not be created (' . ($made['detail'] ?? 'unknown') . '). Paste a Teams link to continue.', 'code' => 'teams_link_needed'], 422));
    }

    /** "Also invite": emails typed with commas/spaces/new lines (or a list). Absent = keep what is there. */
    private function invitees(Request $request, object $b): ?string
    {
        if (! $request->has('invitees')) return $b->invitees ?? null;
        $raw = $request->input('invitees');
        $list = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);
        $ok = []; $bad = [];
        foreach ($list as $e) {
            $e = strtolower(trim((string) $e, " <>\t\n\r\0\x0B\"'"));
            if ($e === '') continue;
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) $ok[$e] = true; else $bad[] = $e;
        }
        abort_if($bad, 422, 'Not an email address: ' . implode(', ', array_slice($bad, 0, 3)));
        abort_if(count($ok) > 15, 422, 'Invite up to 15 people.');
        return $ok ? json_encode(array_keys($ok)) : null;
    }

    private function load(int $id): object
    {
        $b = DB::table('marketing_bookings')->where('id', $id)->first();
        abort_unless($b, 404, 'That booking was not found.');
        return $b;
    }

    private function audit(Request $r, object $b, string $action, string $summary): void
    {
        \App\Support\Audit::write(['user_id' => (int) $r->user()->id, 'agency_id' => null, 'action' => $action, 'entity_type' => 'marketing_booking', 'entity_id' => (int) $b->id,
            'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        try {
            if ($b->sales_lead_id) \App\Models\SalesActivity::create(['lead_id' => $b->sales_lead_id, 'user_id' => (int) $r->user()->id, 'type' => 'note', 'done' => true, 'due_date' => now()->toDateString(), 'body' => $summary]);
        } catch (\Throwable $e) {
        }
    }

    /** POST /sales/bookings/{id}/accept {teams_url?, note?} */
    public function accept(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['teams_url' => ['nullable', 'url', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000'], 'invitees' => ['nullable', 'max:2000']]);
        $b = $this->load($id);
        abort_unless($b->status === 'requested', 422, 'Only a pending request can be accepted.');
        $b->host_user_id = (int) $request->user()->id;
        $b->invitees = $this->invitees($request, $b);
        $t = $this->teams($b, $data['teams_url'] ?? null);
        DB::table('marketing_bookings')->where('id', $id)->update(['status' => 'booked', 'teams_url' => $t['url'], 'teams_event_id' => $t['event_id'], 'invitees' => $b->invitees,
            'host_user_id' => $request->user()->id, 'decided_by' => $request->user()->id, 'decided_at' => now(), 'sales_note' => $data['note'] ?? null, 'updated_at' => now()]);
        $b = $this->load($id);
        $ok = BookingMail::visitor($b, 'confirmed');
        DB::table('marketing_bookings')->where('id', $id)->update(['confirm_mail' => $ok ? 'sent' : 'failed']);
        $this->audit($request, $b, 'booking.accepted', 'Accepted ' . BookingMail::typeName($b) . ' with ' . $b->name . ' — ' . BookingMail::whenEt($b->starts_at) . ($ok ? '; confirmation sent' : '; CONFIRMATION EMAIL FAILED'));
        \App\Support\BookingFollowUp::after($b, 'confirmed', (int) $request->user()->id, null, $ok);
        return response()->json(['ok' => true, 'mail' => $ok, 'booking' => $this->present($this->load($id))]);
    }

    /** POST /sales/bookings/{id}/modify {date, time, type?, duration_min?, teams_url?, note?} — confirms at the new time. */
    public function modify(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'type' => ['nullable', 'in:' . implode(',', array_keys(BookingMail::TYPES))], 'duration_min' => ['nullable', 'integer', 'min:15', 'max:240'],
            'teams_url' => ['nullable', 'url', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000'], 'invitees' => ['nullable', 'max:2000'],
        ]);
        $b = $this->load($id);
        abort_unless(in_array($b->status, ['requested', 'booked'], true), 422, 'A declined or cancelled meeting cannot be changed.');
        $type = $data['type'] ?? $b->type;
        $min = (int) ($data['duration_min'] ?? ($type !== $b->type ? BookingMail::TYPES[$type]['min'] : $b->duration_min));
        $start = Carbon::createFromFormat('Y-m-d H:i', $data['date'] . ' ' . $data['time'], BookingMail::TZ);
        abort_if($start->isPast(), 422, 'Choose a time in the future.');
        $end = $start->copy()->addMinutes($min);
        $clash = DB::table('marketing_bookings')->where('id', '!=', $id)->whereIn('status', ['booked', 'requested'])
            ->where('starts_at', '<', $end->copy()->utc())->where('ends_at', '>', $start->copy()->utc())->first(['name', 'starts_at']);
        if ($clash) {
            return response()->json(['message' => 'That overlaps the meeting with ' . $clash->name . ' (' . BookingMail::whenEt($clash->starts_at) . ').', 'code' => 'clash'], 422);
        }
        $old = $b->starts_at;
        $moved = Carbon::parse($old, 'UTC')->timestamp !== $start->copy()->utc()->timestamp || $min !== (int) $b->duration_min || $type !== $b->type;
        $b->starts_at = $start->copy()->utc()->format('Y-m-d H:i:s'); $b->ends_at = $end->copy()->utc()->format('Y-m-d H:i:s');
        $b->type = $type; $b->duration_min = $min; $b->host_user_id = (int) $request->user()->id;
        $b->invitees = $this->invitees($request, $b);
        $t = $this->teams($b, $data['teams_url'] ?? null);
        DB::table('marketing_bookings')->where('id', $id)->update([
            'status' => 'booked', 'type' => $type, 'duration_min' => $min, 'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at,
            'local_date' => $data['date'], 'local_time' => $data['time'], 'teams_url' => $t['url'], 'teams_event_id' => $t['event_id'], 'invitees' => $b->invitees,
            'sequence' => (int) $b->sequence + 1, 'host_user_id' => $request->user()->id, 'decided_by' => $request->user()->id, 'decided_at' => now(),
            'sales_note' => $data['note'] ?? null, 'updated_at' => now(),
        ]);
        $b = $this->load($id);
        $ok = BookingMail::visitor($b, $moved ? 'modified' : 'confirmed', null, $moved ? $old : null);
        DB::table('marketing_bookings')->where('id', $id)->update(['confirm_mail' => $ok ? 'sent' : 'failed']);
        $this->audit($request, $b, 'booking.modified', ($moved ? 'Moved ' : 'Confirmed ') . BookingMail::typeName($b) . ' with ' . $b->name . ' to ' . BookingMail::whenEt($b->starts_at)
            . ($moved ? ' (was ' . BookingMail::whenEt($old) . ')' : '') . ($ok ? '; email sent' : '; EMAIL FAILED'));
        \App\Support\BookingFollowUp::after($b, $moved ? 'modified' : 'confirmed', (int) $request->user()->id, $moved ? $old : null, $ok);
        return response()->json(['ok' => true, 'mail' => $ok, 'booking' => $this->present($b)]);
    }

    /** POST /sales/bookings/{id}/decline {reason?} — a request is declined; a confirmed meeting is cancelled. */
    public function decline(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500'], 'note' => ['nullable', 'string', 'max:1000'], 'notify' => ['nullable', 'boolean']]);
        $b = $this->load($id);
        abort_unless(in_array($b->status, ['requested', 'booked'], true), 422, 'That meeting is already declined or cancelled.');
        $kind = $b->status === 'booked' ? 'cancelled' : 'declined';
        if ($kind === 'cancelled') BookingTeams::cancel($b);
        DB::table('marketing_bookings')->where('id', $id)->update(['status' => $kind, 'decline_reason' => $data['reason'] ?? null, 'sales_note' => $data['note'] ?? null,
            'decided_by' => $request->user()->id, 'decided_at' => now(), 'host_user_id' => $b->host_user_id ?: $request->user()->id, 'updated_at' => now()]);
        $b = $this->load($id);
        $ok = ($data['notify'] ?? true) ? BookingMail::visitor($b, $kind) : null;
        $this->audit($request, $b, 'booking.' . $kind, ucfirst($kind) . ' ' . BookingMail::typeName($b) . ' with ' . $b->name . ' — ' . BookingMail::whenEt($b->starts_at)
            . ($ok === null ? ' (no email)' : ($ok ? '; email sent' : '; EMAIL FAILED')));
        \App\Support\BookingFollowUp::after($b, $kind, (int) $request->user()->id, null, $ok);
        return response()->json(['ok' => true, 'mail' => $ok, 'booking' => $this->present($b)]);
    }

    /** PUT /sales/bookings/settings {default_link} */
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['default_link' => ['nullable', 'url', 'max:1000']]);
        PlatformSettings::set(self::DEFAULT_LINK, trim((string) ($data['default_link'] ?? '')));
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'booking.settings', 'entity_type' => 'platform', 'entity_id' => null,
            'payload' => json_encode(['summary' => 'Default Teams link for meetings ' . (trim((string) ($data['default_link'] ?? '')) !== '' ? 'saved' : 'cleared')]), 'created_at' => now()]);
        return response()->json(['ok' => true, 'default_link' => (string) PlatformSettings::get(self::DEFAULT_LINK, '')]);
    }
}
