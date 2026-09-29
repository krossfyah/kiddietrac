<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StatHolidays;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Website meeting booking (2026-09-29).
 *
 * Anthony: "Make the book a meeting or webinar a wizard prompt and show a full months
 * calendar as well". The old popup posted to http://localhost:5000/api/booking and then
 * told the visitor "Booking Confirmed! Confirmation sent" -- every booking was lost.
 *
 * One sales calendar, Eastern Time, weekdays 9:00-17:00, no Canadian stat holidays,
 * from the next business day up to 60 days out. A slot is free when no booked meeting
 * overlaps it. Each booking lands in marketing_bookings, in the Sales pipeline (lead +
 * a "meeting" activity on that date), and emails sales@kiddietrac.com (Anthony's choice)
 * and the visitor (with an .ics). Mail results are recorded on the row: "sent" means the
 * mailer accepted it, "failed" is logged -- the booking itself never fails on mail.
 */
class MarketingBookingController extends Controller
{
    private const TZ = 'America/Toronto';
    private const SALES_TO = 'sales@kiddietrac.com';
    private const STARTS = ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00', '16:30'];
    private const DAY_END = '17:00';
    private const MAX_DAYS = 60;
    private const TYPES = [
        'demo'      => ['name' => 'Product demo',       'min' => 45],
        'sales'     => ['name' => 'Sales call',         'min' => 30],
        'onboard'   => ['name' => 'Onboarding session', 'min' => 90],
        'wl'        => ['name' => 'White label demo',   'min' => 45],
        'migration' => ['name' => 'Migration consult',  'min' => 30],
    ];

    /** GET /marketing-site/booking/availability?month=YYYY-MM&type=demo */
    public function availability(Request $request): JsonResponse
    {
        $type = array_key_exists((string) $request->query('type'), self::TYPES) ? (string) $request->query('type') : 'demo';
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? (string) $request->query('month') : null;
        $now = Carbon::now(self::TZ);
        $first = $month ? Carbon::createFromFormat('Y-m-d', $month . '-01', self::TZ)->startOfDay() : $now->copy()->startOfMonth();
        $last = $first->copy()->endOfMonth();
        [$minDay, $maxDay] = $this->window($now);

        $holidays = [];
        foreach (StatHolidays::between($first, $last, 'CA') as $h) {
            $holidays[$h['observed'] ?? $h['date']] = $h['name'];
        }
        $busy = $this->busy($first, $last);

        $days = [];
        for ($d = $first->copy(); $d->lte($last); $d->addDay()) {
            $ds = $d->toDateString();
            $why = null;
            if ($d->isWeekend()) $why = 'weekend';
            elseif (isset($holidays[$ds])) $why = 'holiday';
            elseif ($ds < $minDay) $why = 'past';
            elseif ($ds > $maxDay) $why = 'later';
            $slots = [];
            if (! $why) {
                foreach (self::STARTS as $t) {
                    $slots[] = ['time' => $t, 'at' => Carbon::createFromFormat('Y-m-d H:i', $ds . ' ' . $t, self::TZ)->toIso8601String(),
                        'free' => $this->slotFree($ds, $t, self::TYPES[$type]['min'], $busy)];
                }
                if (! array_filter($slots, fn ($s) => $s['free'])) $why = 'full';
            }
            $days[$ds] = ['closed' => $why, 'holiday' => $holidays[$ds] ?? null, 'slots' => $why ? [] : $slots];
        }

        return response()->json([
            'tz' => self::TZ, 'month' => $first->format('Y-m'), 'min_day' => $minDay, 'max_day' => $maxDay,
            'types' => collect(self::TYPES)->map(fn ($t, $k) => ['id' => $k, 'name' => $t['name'], 'min' => $t['min']])->values(),
            'days' => $days,
        ]);
    }

    /** POST /marketing-site/booking */
    public function store(Request $request): JsonResponse
    {
        if (trim((string) $request->input('website', '')) !== '') {        // honeypot: look successful, keep nothing
            return response()->json(['ok' => true]);
        }
        $data = $request->validate([
            'type'    => ['required', 'in:' . implode(',', array_keys(self::TYPES))],
            'date'    => ['required', 'date_format:Y-m-d'],
            'time'    => ['required', 'in:' . implode(',', self::STARTS)],
            'name'    => ['required', 'string', 'max:120'],
            'email'   => ['required', 'email', 'max:160'],
            'agency'  => ['nullable', 'string', 'max:160'],
            'phone'   => ['nullable', 'string', 'max:40'],
            'meet_via'=> ['required', 'in:video,phone'],
            'children'=> ['nullable', 'string', 'max:40'],
            'notes'   => ['nullable', 'string', 'max:1000'],
            'visitor_tz' => ['nullable', 'string', 'max:60'],
            'lang'    => ['nullable', 'in:en,fr,es,hi'],
        ]);
        if ($data['meet_via'] === 'phone' && trim((string) ($data['phone'] ?? '')) === '') {
            return response()->json(['message' => 'Please add a phone number so we can call you.', 'errors' => ['phone' => ['required']]], 422);
        }
        $now = Carbon::now(self::TZ);
        [$minDay, $maxDay] = $this->window($now);
        $day = Carbon::createFromFormat('Y-m-d', $data['date'], self::TZ);
        $isHoliday = collect(StatHolidays::between($day->copy()->startOfDay(), $day->copy()->endOfDay(), 'CA'))->isNotEmpty();
        if ($day->isWeekend() || $isHoliday || $data['date'] < $minDay || $data['date'] > $maxDay) {
            return response()->json(['message' => 'That day is not available. Please choose another date.', 'code' => 'day_unavailable'], 422);
        }
        $type = self::TYPES[$data['type']];
        $start = Carbon::createFromFormat('Y-m-d H:i', $data['date'] . ' ' . $data['time'], self::TZ);
        $end = $start->copy()->addMinutes($type['min']);

        $email = strtolower(trim($data['email']));
        $id = DB::transaction(function () use ($data, $start, $end, $type, $email, $request) {
            // Serialise bookings so two visitors cannot take the same time.
            DB::table('marketing_bookings')->lockForUpdate()->where('status', 'booked')
                ->where('starts_at', '<', $start->copy()->endOfDay()->utc())->where('ends_at', '>', $start->copy()->startOfDay()->utc())->get(['id']);
            if (! $this->slotFree($data['date'], $data['time'], $type['min'], $this->busy($start->copy()->startOfDay(), $start->copy()->endOfDay()))) {
                return null;
            }
            return DB::table('marketing_bookings')->insertGetId([
                'type' => $data['type'], 'duration_min' => $type['min'],
                'starts_at' => $start->copy()->utc(), 'ends_at' => $end->copy()->utc(),
                'local_date' => $data['date'], 'local_time' => $data['time'], 'tz' => self::TZ,
                'name' => trim($data['name']), 'email' => $email, 'agency' => trim((string) ($data['agency'] ?? '')) ?: null,
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null, 'meet_via' => $data['meet_via'],
                'children' => trim((string) ($data['children'] ?? '')) ?: null, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'visitor_tz' => $data['visitor_tz'] ?? null, 'lang' => $data['lang'] ?? 'en',
                'status' => 'booked', 'ip' => substr((string) $request->ip(), 0, 45),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        if (! $id) {
            return response()->json(['message' => 'Sorry, someone just booked that time. Please pick another.', 'code' => 'slot_taken'], 409);
        }
        $b = DB::table('marketing_bookings')->where('id', $id)->first();

        $leadId = $this->pipeline($b, $type['name']);
        $salesOk = $this->mailSales($b, $type['name']);
        $visitorOk = $this->mailVisitor($b, $type['name']);
        DB::table('marketing_bookings')->where('id', $id)->update([
            'sales_lead_id' => $leadId, 'sales_mail' => $salesOk ? 'sent' : 'failed',
            'visitor_mail' => $visitorOk ? 'sent' : 'failed', 'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true, 'id' => $id, 'type' => $type['name'], 'duration_min' => $type['min'],
            'starts_at' => $start->toIso8601String(), 'tz' => self::TZ, 'confirmation_sent' => $visitorOk,
        ], 201);
    }

    /* ── helpers ── */

    private function window(Carbon $now): array
    {
        $min = $now->copy()->addDay();
        while ($min->isWeekend()) $min->addDay();
        return [$min->toDateString(), $now->copy()->addDays(self::MAX_DAYS)->toDateString()];
    }

    /** Booked meetings overlapping the range, as [startUtcTs, endUtcTs]. */
    private function busy(Carbon $from, Carbon $to): array
    {
        return DB::table('marketing_bookings')->where('status', 'booked')
            ->where('starts_at', '<', $to->copy()->utc())->where('ends_at', '>', $from->copy()->utc())
            ->get(['starts_at', 'ends_at'])
            ->map(fn ($r) => [Carbon::parse($r->starts_at, 'UTC')->timestamp, Carbon::parse($r->ends_at, 'UTC')->timestamp])->all();
    }

    private function slotFree(string $date, string $time, int $min, array $busy): bool
    {
        $s = Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $time, self::TZ);
        $e = $s->copy()->addMinutes($min);
        if ($e->format('H:i') > self::DAY_END || $e->toDateString() !== $date) return false;
        foreach ($busy as [$bs, $be]) {
            if ($s->timestamp < $be && $e->timestamp > $bs) return false;
        }
        return true;
    }

    private function when(object $b): string
    {
        return Carbon::parse($b->starts_at, 'UTC')->tz(self::TZ)->format('l, F j, Y \a\t g:i A') . ' Eastern Time';
    }

    private function pipeline(object $b, string $typeName): ?int
    {
        try {
            $lead = \App\Models\SalesLead::where('email', $b->email)->where('status', 'open')->first();
            if (! $lead) {
                $lead = \App\Models\SalesLead::create([
                    'name' => $b->name, 'company' => $b->agency, 'email' => $b->email, 'phone' => $b->phone,
                    'source' => 'marketing-site', 'stage' => 'new', 'status' => 'open', 'last_activity_at' => now(),
                    'num_children' => is_numeric($b->children) ? (int) $b->children : null,
                    'notes' => 'Booked a ' . strtolower($typeName) . ' on the website.',
                ]);
            } else {
                $lead->update(['last_activity_at' => now()]);
            }
            \App\Models\SalesActivity::create([
                'lead_id' => $lead->id, 'type' => 'meeting', 'due_date' => $b->local_date, 'done' => false,
                'body' => $typeName . ' booked on the website — ' . $this->when($b) . ' (' . $b->duration_min . ' min, '
                    . ($b->meet_via === 'phone' ? 'phone call to ' . $b->phone : 'video call — send the link') . ')'
                    . ($b->notes ? "\nThey wrote: " . $b->notes : ''),
            ]);
            return (int) $lead->id;
        } catch (\Throwable $e) {
            Log::warning('booking pipeline failed', ['booking' => $b->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function ics(object $b, string $typeName): string
    {
        $fmt = fn ($t) => Carbon::parse($t, 'UTC')->format('Ymd\THis\Z');
        $esc = fn ($s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], (string) $s);
        $desc = $typeName . ' with the KiddieTrac team. '
            . ($b->meet_via === 'phone' ? 'We will call you at ' . $b->phone . '.' : 'We will email you the video call link before the meeting.')
            . ' Need to change the time? Reply to the confirmation email or call 1-855-400-9996.';
        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//KiddieTrac//Website booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT', 'UID:booking-' . $b->id . '@kiddietrac.com', 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $fmt($b->starts_at), 'DTEND:' . $fmt($b->ends_at),
            'SUMMARY:' . $esc('KiddieTrac ' . strtolower($typeName)), 'DESCRIPTION:' . $esc($desc),
            'LOCATION:' . $esc($b->meet_via === 'phone' ? 'Phone call' : 'Video call (link to follow)'),
            'END:VEVENT', 'END:VCALENDAR', '',
        ]);
    }

    private function mailSales(object $b, string $typeName): bool
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rows = [
            'When' => $this->when($b) . ' (' . $b->duration_min . ' min)', 'Type' => $typeName,
            'How' => $b->meet_via === 'phone' ? 'Phone call — we call them' : 'Video call — send them a link',
            'Name' => $b->name, 'Email' => $b->email, 'Phone' => $b->phone ?: '—', 'Agency' => $b->agency ?: '—',
            'Children' => $b->children ?: '—', 'Their time zone' => $b->visitor_tz ?: '—', 'Notes' => $b->notes ?: '—',
        ];
        $t = '';
        foreach ($rows as $k => $v) {
            $t .= '<tr><td style="padding:6px 14px 6px 0;color:#64748b;font-size:13px;vertical-align:top;white-space:nowrap">' . $e($k)
                . '</td><td style="padding:6px 0;color:#0f172a;font-size:14px;font-weight:600">' . nl2br($e($v)) . '</td></tr>';
        }
        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:640px">'
            . '<h2 style="margin:0 0 4px;color:#0f172a;font-size:19px">New meeting booked on the website</h2>'
            . '<p style="margin:0 0 16px;color:#64748b;font-size:13.5px">It is in Sales → Pipeline as a meeting on ' . $e($b->local_date)
            . '. The visitor was sent a confirmation with a calendar file.</p><table style="border-collapse:collapse">' . $t . '</table></div>';
        try {
            $ics = $this->ics($b, $typeName);
            Mail::html($html, function ($m) use ($b, $typeName, $ics) {
                $m->to(self::SALES_TO)->subject('Booked: ' . $typeName . ' with ' . $b->name . ' — ' . Carbon::parse($b->starts_at, 'UTC')->tz(self::TZ)->format('M j, g:i A'))
                  ->replyTo($b->email, $b->name)
                  ->attachData($ics, 'kiddietrac-booking.ics', ['mime' => 'text/calendar; charset=utf-8; method=PUBLISH']);
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
            return true;
        } catch (\Throwable $ex) {
            Log::warning('booking sales mail failed', ['booking' => $b->id, 'error' => $ex->getMessage()]);
            return false;
        }
    }

    private function mailVisitor(object $b, string $typeName): bool
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $how = $b->meet_via === 'phone'
            ? 'We’ll call you at <strong>' . $e($b->phone) . '</strong>.'
            : 'We’ll email you the video call link before the meeting.';
        $body = '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Hi ' . $e(strtok($b->name, ' ')) . ',</p>'
            . '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Thanks for booking time with us — we’re looking forward to it.</p>'
            . \App\Services\EmailTemplate::calloutBox('<strong>' . $e($typeName) . '</strong> · ' . $b->duration_min . ' minutes<br>' . $e($this->when($b)), 'info')
            . '<p style="margin:14px 0;font-size:15px;color:#334155;line-height:1.6">' . $how
            . ' A calendar file is attached so you can add it to your calendar.</p>'
            . '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Need a different time? Just reply to this email or call us at <strong>1-855-400-9996</strong>.</p>'
            . '<p style="margin:0;font-size:15px;color:#334155;line-height:1.6">See you soon,<br>The KiddieTrac team</p>';
        try {
            $html = \App\Services\EmailTemplate::wrap(null, $body, ['title' => 'You’re booked in', 'preheader' => $typeName . ' — ' . $this->when($b)]);
            $ics = $this->ics($b, $typeName);
            Mail::html($html, function ($m) use ($b, $typeName, $ics) {
                $m->to($b->email, $b->name)->subject('Confirmed: your KiddieTrac ' . strtolower($typeName))
                  ->replyTo(self::SALES_TO, 'KiddieTrac')
                  ->attachData($ics, 'kiddietrac-booking.ics', ['mime' => 'text/calendar; charset=utf-8; method=PUBLISH']);
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
            return true;
        } catch (\Throwable $ex) {
            Log::warning('booking visitor mail failed', ['booking' => $b->id, 'error' => $ex->getMessage()]);
            return false;
        }
    }
}
