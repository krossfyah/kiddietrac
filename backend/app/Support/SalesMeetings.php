<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * MEETINGS ON THE sales@ CALENDAR -> THE LEAD, AND A 30-MINUTE REMINDER (2026-11-04, Anthony: "with meetings
 * scheduled under sales@kiddietrac.com can you update the lead record with that info and can you send out reminder
 * from kiddietrac via email and SMS 30 minutes in advance letting user know that meeting is upcoming").
 *
 * Every 5 minutes (kt:sales-meetings) the sales@ calendar is read through Graph (the "KiddieTrac Mailer" app already
 * has Calendars.ReadWrite, BookingTeams). Each meeting is matched to leads by attendee email (lead email or the
 * owner/decision-maker email). A match is kept in sales_calendar_meetings, one row per meeting and lead:
 *  - the lead gets a 📅 meeting activity (due that day) and its follow-up date moves to the meeting day; a moved
 *    meeting rewrites the entry ("MOVED to ... was ..."), a cancelled one closes it. A website booking already has
 *    its own entry (BookingFollowUp), so it gets none here - but it still gets the reminder.
 *  - about 30 minutes before the start: one email (from sales@, Teams button) and one text from the KiddieTrac line
 *    (LineTexts::send: STOP is honoured, and the text lands on the lead and in Message Centre like any line text).
 *    Reminders are OFF until PlatformSettings sales.meeting_reminders = 1 (Anthony approves the test first).
 */
class SalesMeetings
{
    public const TZ = 'America/Toronto';
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    public static function remindersOn(): bool
    {
        return (string) PlatformSettings::get('sales.meeting_reminders', '0') === '1';
    }

    public static function whenEt($at): string
    {
        return Carbon::parse($at, 'UTC')->tz(self::TZ)->format('D, M j \a\t g:i A') . ' ET';
    }

    /** The sales@ calendar (default: an hour ago to 60 days ahead), or null when Graph could not be read in full. */
    public static function events(?Carbon $from = null, ?Carbon $to = null): ?array
    {
        $t = BookingTeams::appToken();
        if (! $t) return null;
        $url = self::GRAPH . '/users/' . rawurlencode(BookingTeams::MAILBOX) . '/calendarView?' . http_build_query([
            'startDateTime' => ($from ?? now()->subHour())->toIso8601String(), 'endDateTime' => ($to ?? now()->addDays(60))->toIso8601String(),
            '$select' => 'id,subject,start,end,attendees,isCancelled,onlineMeeting,location,organizer,webLink', '$top' => 100,
        ]);
        $out = [];
        for ($i = 0; $url && $i < 10; $i++) {
            $r = Http::withToken($t)->withHeaders(['Prefer' => 'outlook.timezone="UTC"'])->timeout(25)->get($url);
            if (! $r->successful()) { Log::warning('sales calendar read failed', ['status' => $r->status()]); return null; }
            foreach ($r->json('value') ?? [] as $e) $out[] = $e;
            $url = $r->json('@odata.nextLink');
        }
        return $url ? null : $out;
    }

    /** Leads by lower-case email (their own, or the owner / decision maker's). */
    public static function leadsByEmail(): array
    {
        $by = [];
        foreach (DB::table('sales_leads')->whereNull('deleted_at')->get(['id', 'email', 'owner_email']) as $l) {
            foreach ([$l->email, $l->owner_email] as $e) {
                $e = strtolower(trim((string) $e));
                if ($e !== '' && ! isset($by[$e])) $by[$e] = (int) $l->id;
            }
        }
        return $by;
    }

    /** Read the calendar and bring the leads up to date. [found, added, moved, cancelled] */
    public static function sync(bool $dry = false): array
    {
        $events = self::events();
        if ($events === null) return ['error' => 'calendar not readable'];
        $leads = self::leadsByEmail();
        $seen = []; $n = ['meetings' => 0, 'added' => 0, 'moved' => 0, 'cancelled' => 0];
        foreach ($events as $e) {
            if (! empty($e['isCancelled'])) continue;
            $start = Carbon::parse($e['start']['dateTime'], 'UTC'); $end = Carbon::parse($e['end']['dateTime'], 'UTC');
            foreach ($e['attendees'] ?? [] as $a) {
                $email = strtolower(trim((string) ($a['emailAddress']['address'] ?? '')));
                if ($email === '' || str_ends_with($email, '@kiddietrac.com') || ! isset($leads[$email])) continue;
                $leadId = $leads[$email];
                $hash = sha1($e['id']);
                $seen[$hash . ':' . $leadId] = true;
                $n['meetings']++;
                $row = DB::table('sales_calendar_meetings')->where('event_hash', $hash)->where('lead_id', $leadId)->first();
                $fields = [
                    'subject' => mb_substr((string) ($e['subject'] ?? 'Meeting'), 0, 250), 'email' => $email,
                    'join_url' => $e['onlineMeeting']['joinUrl'] ?? null, 'location' => mb_substr((string) ($e['location']['displayName'] ?? ''), 0, 250) ?: null,
                    'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'),
                ];
                if ($dry) continue;
                if (! $row) {
                    $bookingId = DB::table('marketing_bookings')->where('teams_event_id', $e['id'])->value('id');
                    $id = DB::table('sales_calendar_meetings')->insertGetId($fields + ['event_hash' => $hash, 'event_id' => $e['id'], 'lead_id' => $leadId,
                        'booking_id' => $bookingId, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
                    self::onLead(DB::table('sales_calendar_meetings')->find($id), 'added');
                    $n['added']++;
                } elseif ($row->starts_at !== $fields['starts_at'] || $row->status === 'cancelled') {
                    // A new time is a new meeting for the reminder: it goes again.
                    DB::table('sales_calendar_meetings')->where('id', $row->id)->update($fields + ['status' => 'scheduled', 'email_reminded_at' => null,
                        'sms_reminded_at' => null, 'sms_result' => null, 'updated_at' => now()]);
                    self::onLead(DB::table('sales_calendar_meetings')->find($row->id), 'moved', $row->status === 'cancelled' ? null : $row->starts_at);
                    $n['moved']++;
                } else {
                    DB::table('sales_calendar_meetings')->where('id', $row->id)->update($fields + ['updated_at' => now()]);
                }
            }
        }
        // Gone from the calendar (deleted or cancelled) while still ahead: cancelled on the lead too.
        if (! $dry) {
            foreach (DB::table('sales_calendar_meetings')->where('status', 'scheduled')->where('starts_at', '>', now()->addMinutes(2))->get() as $r) {
                if (isset($seen[$r->event_hash . ':' . $r->lead_id])) continue;
                DB::table('sales_calendar_meetings')->where('id', $r->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                self::onLead($r, 'cancelled');
                $n['cancelled']++;
            }
        }
        return $n;
    }

    /** The lead's 📅 entry and follow-up date. A website booking keeps the entry BookingFollowUp writes. */
    private static function onLead(object $m, string $kind, ?string $oldStart = null): void
    {
        try {
            $lead = DB::table('sales_leads')->where('id', $m->lead_id)->first();
            if (! $lead) return;
            $date = Carbon::parse($m->starts_at, 'UTC')->tz(self::TZ)->toDateString();
            $min = (int) round(Carbon::parse($m->starts_at, 'UTC')->diffInMinutes(Carbon::parse($m->ends_at, 'UTC')));
            $how = $m->join_url ? 'Teams video call' : ($m->location ?: 'see the invitation');
            $closed = $kind === 'cancelled';
            if (! $m->booking_id) {
                $body = '📅 ' . $m->subject . ' — ' . match ($kind) {
                    'added' => self::whenEt($m->starts_at) . ' (' . $min . ' min, ' . $how . ').',
                    'moved' => 'MOVED to ' . self::whenEt($m->starts_at) . ($oldStart ? ' (was ' . self::whenEt($oldStart) . ')' : '') . ' — ' . $min . ' min, ' . $how . '.',
                    default => 'CANCELLED — was ' . self::whenEt($m->starts_at) . '.',
                };
                if (! $closed && $m->join_url) $body .= "\nTeams link: " . $m->join_url;
                $body .= "\nFrom the sales@kiddietrac.com calendar.";
                $row = ['body' => $body, 'due_date' => $date, 'done' => $closed, 'done_at' => $closed ? now() : null, 'updated_at' => now()];
                if ($m->activity_id && DB::table('sales_activities')->where('id', $m->activity_id)->exists()) {
                    DB::table('sales_activities')->where('id', $m->activity_id)->update($row);
                } else {
                    $aid = DB::table('sales_activities')->insertGetId($row + ['lead_id' => $lead->id, 'type' => 'meeting', 'user_id' => $lead->owner_id, 'created_at' => now()]);
                    DB::table('sales_calendar_meetings')->where('id', $m->id)->update(['activity_id' => $aid]);
                }
            }
            $upd = ['last_activity_at' => now(), 'updated_at' => now()];
            $fu = $lead->follow_up_date ? substr((string) $lead->follow_up_date, 0, 10) : null;
            $today = now(self::TZ)->toDateString();
            if (! $closed) {
                // The meeting is the next touch - unless Sales already set an earlier one still ahead.
                if (! $fu || $fu < $today || $fu > $date) $upd['follow_up_date'] = $date;
            } elseif ($fu === $date) {
                $upd['follow_up_date'] = null;
            }
            DB::table('sales_leads')->where('id', $lead->id)->update($upd);
        } catch (Throwable $e) {
            Log::warning('sales meeting lead sync failed', ['meeting' => $m->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    /** Meetings starting in the next 31 minutes (and not within 5) that have not had their reminder. */
    public static function remindDue(): array
    {
        $n = ['email' => 0, 'sms' => 0];
        if (! self::remindersOn()) return $n + ['off' => true];
        $rows = DB::table('sales_calendar_meetings')->where('status', 'scheduled')
            ->whereBetween('starts_at', [now()->addMinutes(5), now()->addMinutes(31)])
            ->where(fn ($q) => $q->whereNull('email_reminded_at')->orWhereNull('sms_reminded_at'))->get();
        foreach ($rows as $m) {
            $lead = DB::table('sales_leads')->where('id', $m->lead_id)->whereNull('deleted_at')->first();
            if (! $lead) continue;
            if (! $m->email_reminded_at) {
                // Claim it first, so a slow send can never be repeated by the next run.
                if (DB::table('sales_calendar_meetings')->where('id', $m->id)->whereNull('email_reminded_at')->update(['email_reminded_at' => now()])) {
                    if (self::email($m, $lead)) $n['email']++;
                }
            }
            if (! $m->sms_reminded_at) {
                if (DB::table('sales_calendar_meetings')->where('id', $m->id)->whereNull('sms_reminded_at')->update(['sms_reminded_at' => now()])) {
                    $res = self::sms($m, $lead);
                    DB::table('sales_calendar_meetings')->where('id', $m->id)->update(['sms_result' => mb_substr($res, 0, 190)]);
                    if ($res === 'sent') $n['sms']++;
                }
            }
        }
        return $n;
    }

    private static function first(?string $name): string
    {
        return strtok(trim((string) $name), ' ') ?: '';
    }

    /** Who they are meeting: the lead's owner, first name only. */
    private static function host(object $lead): string
    {
        return $lead->owner_id ? (string) (DB::table('users')->where('id', $lead->owner_id)->value('first_name') ?: '') : '';
    }

    public static function smsText(object $m, object $lead): string
    {
        $host = self::host($lead);
        $t = 'KiddieTrac: Hi ' . (self::first($lead->name) ?: 'there') . ', a reminder that your meeting' . ($host ? ' with ' . $host : '')
            . ' starts in 30 minutes (' . Carbon::parse($m->starts_at, 'UTC')->tz(self::TZ)->format('g:i A') . ' ET).';
        if ($m->join_url) $t .= ' Join here: ' . $m->join_url;
        return $t . ' Reply STOP to opt out.';
    }

    /** One text from the KiddieTrac line. 'sent' or why not. */
    public static function sms(object $m, object $lead, ?string $testTo = null): string
    {
        $digits = LineTexts::digits($testTo ?: ($lead->phone ?: $lead->owner_phone ?? null));
        if (strlen($digits) !== 10) return 'no mobile number';
        try {
            $actor = (int) ($lead->owner_id ?: PlatformSettings::get('sales.booking.email_actor', 1));
            [$ok, $why] = LineTexts::send($digits, ($testTo ? '[TEST] ' : '') . self::smsText($m, $lead), $actor, (bool) $testTo);
            return $ok ? 'sent' : (string) $why;
        } catch (Throwable $e) {
            return 'failed: ' . $e->getMessage();
        }
    }

    /** One email from sales@, with the Teams button. */
    public static function email(object $m, object $lead, ?string $testTo = null): bool
    {
        $to = $testTo ?: ($lead->email ?: $m->email);
        if (! $to) return false;
        $d = [
            'first' => self::first($lead->name), 'host' => self::host($lead), 'subject' => $m->subject,
            'whenEt' => self::whenEt($m->starts_at), 'time' => Carbon::parse($m->starts_at, 'UTC')->tz(self::TZ)->format('g:i A') . ' ET',
            'minutes' => (int) round(Carbon::parse($m->starts_at, 'UTC')->diffInMinutes(Carbon::parse($m->ends_at, 'UTC'))),
            'joinUrl' => $m->join_url, 'location' => $m->location,
        ];
        try {
            $html = view('emails.meeting-soon', ['d' => $d])->render();
            $plain = 'Hi ' . ($d['first'] ?: 'there') . ",\n\nYour meeting" . ($d['host'] ? ' with ' . $d['host'] . ' from KiddieTrac' : ' with KiddieTrac')
                . ' starts in 30 minutes: ' . $d['whenEt'] . ".\n\n" . ($d['joinUrl'] ? "Join the Teams meeting: " . $d['joinUrl'] . "\n\n" : '')
                . "Running late or need another time? Reply to this email or call 1-855-400-9996.\n\nKiddieTrac";
            Mail::html($html, function ($msg) use ($to, $lead, $d, $testTo, $plain) {
                MailScope::platform($msg);
                $msg->text($plain);
                $msg->to($to, $testTo ? null : ($lead->name ?: null))->subject(($testTo ? '[TEST] ' : '') . 'Starting in 30 minutes: your KiddieTrac meeting at ' . $d['time'])
                    ->replyTo(BookingMail::SALES, 'KiddieTrac Sales');
                $msg->getHeaders()->addTextHeader('X-KT-Send-As', BookingMail::SALES);
            });
            if (! $testTo) {
                DB::table('sales_activities')->insert(['lead_id' => $lead->id, 'user_id' => null, 'type' => 'email', 'done' => 1,
                    'body' => '⏰ Reminder emailed to ' . $to . ': "' . $m->subject . '" starts in 30 minutes (' . $d['whenEt'] . ').', 'created_at' => now(), 'updated_at' => now()]);
            }
            return true;
        } catch (Throwable $e) {
            Log::warning('sales meeting reminder mail failed', ['meeting' => $m->id ?? null, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
