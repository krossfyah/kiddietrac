<?php

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * THE 30-MINUTE REMINDER FOR MEETINGS MADE ON THE MEETINGS SCREEN (2026-11-13, Anthony: "did the meeting for today
 * booked sent out the notification reminders?" - it had not).
 *
 * SalesMeetings reminds the people on a sales@ calendar event. A meeting made on the Meetings screen puts its Teams
 * event on that calendar WITHOUT attendees (Meetings::graphBody - Outlook would otherwise send its own invitation), so
 * that reminder never saw its guests. This one reads the meeting's own list (meeting_attendees):
 *  - every attendee except the organiser and anyone who declined, about 30 minutes before (5-31 minutes ahead,
 *    the same window as SalesMeetings, run by kt:sales-meetings every 5 minutes); not all-day meetings;
 *  - one email each: platform meetings from sales@ (MailScope::platform), agency meetings through the agency's own
 *    mailer and branding (AgencyMailer), as the invitation went;
 *  - platform meetings also text from the KiddieTrac line when a number is known (the person's account, or the sales
 *    lead with that email) - LineTexts::send honours STOP. Agency meetings email only: agencies text through their
 *    own SMS rules, which this does not decide;
 *  - reminded_for holds the start it went for, claimed BEFORE sending, so a slow send is never repeated and a moved
 *    meeting reminds again;
 *  - someone the sales@ reminder already has for the same start (sales_calendar_meetings) is skipped - never two.
 * A guest who is a sales lead gets the reminder on the lead's activity, as SalesMeetings does.
 * Off only if PlatformSettings meetings.reminders = 0.
 */
final class MeetingReminders
{
    public static function on(): bool
    {
        return (string) PlatformSettings::get('meetings.reminders', '1') !== '0';
    }

    /** Send what is due. $dry: list who would get one, send and change nothing. */
    public static function due(bool $dry = false): array
    {
        $n = ['email' => 0, 'sms' => 0, 'skipped' => 0];
        if (! self::on()) return $n + ['off' => true];
        $would = [];
        $meetings = DB::table('meetings')->where('status', 'scheduled')->where('all_day', 0)
            ->whereBetween('starts_at', [now()->addMinutes(5)->format('Y-m-d H:i:s'), now()->addMinutes(31)->format('Y-m-d H:i:s')])->get();
        foreach ($meetings as $m) {
            $org = DB::table('users')->where('id', $m->organizer_id)->first(['id', 'first_name', 'email']);
            foreach (Meetings::attendees((int) $m->id) as $p) {
                if ($p->response === 'declined') continue;
                if ($p->user_id && (int) $p->user_id === (int) $m->organizer_id) continue;
                if ($p->reminded_for && Carbon::parse($p->reminded_for)->format('Y-m-d H:i:s') === Carbon::parse($m->starts_at)->format('Y-m-d H:i:s')) continue;
                $email = strtolower(trim((string) $p->email));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                if (DB::table('sales_calendar_meetings')->where('status', 'scheduled')->where('email', $email)->where('starts_at', $m->starts_at)->exists()) { $n['skipped']++; continue; }
                if ($dry) { $would[] = ['meeting' => (int) $m->id, 'attendee' => (int) $p->id, 'starts_at' => $m->starts_at]; continue; }
                // Claim it first, so the next run can never send it again.
                $claimed = DB::table('meeting_attendees')->where('id', $p->id)
                    ->where(fn ($q) => $q->whereNull('reminded_for')->orWhere('reminded_for', '!=', $m->starts_at))
                    ->update(['reminded_for' => $m->starts_at, 'updated_at' => now()]);
                if (! $claimed) continue;
                $lead = $m->scope === 'agency' ? null : DB::table('sales_leads')->whereNull('deleted_at')
                    ->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email])->orWhereRaw('LOWER(owner_email) = ?', [$email]))->first();
                $mailed = self::email($m, $p, $org, $lead);
                if ($mailed) $n['email']++;
                $sms = $m->scope === 'agency' ? 'agency meeting: email only' : self::sms($m, $p, $org, $lead);
                if ($sms === 'sent') $n['sms']++;
                DB::table('meeting_attendees')->where('id', $p->id)->update(['reminder_result' => mb_substr('email ' . ($mailed ? 'sent' : 'failed') . '; text ' . $sms, 0, 190)]);
            }
        }
        return $dry ? $n + ['would' => $would] : $n;
    }

    private static function first(?string $name): string
    {
        return trim((string) strtok(trim((string) $name), ' '));
    }

    private static function start(object $m): Carbon
    {
        return Carbon::parse($m->starts_at, 'UTC')->tz(Meetings::tzFor($m));
    }

    /** One email: the time, the join button, who it is with. First names only. */
    public static function email(object $m, object $p, ?object $org, ?object $lead, ?string $testTo = null): bool
    {
        $agencyId = $m->scope === 'agency' ? (int) $m->agency_id : null;
        $host = self::first($org->first_name ?? '');
        $with = $host !== '' ? $host . ($agencyId ? '' : ' from KiddieTrac') : ($agencyId ? '' : 'KiddieTrac');
        $first = self::first($p->name ?: ($lead->name ?? ''));
        $time = self::start($m)->format('g:i A T');
        $navy = '#0B1F3A'; $muted = '#64748B';
        $body = '<p style="margin:0 0 10px;font-size:15px;color:#334155">' . ($first !== '' ? 'Hi ' . e($first) . ',' : 'Hello,') . '</p>'
            . '<p style="margin:0 0 16px;font-size:15px;color:#334155;line-height:1.6">A reminder that your meeting' . ($with !== '' ? ' with ' . e($with) : '')
            . ' starts in about 30 minutes, at <strong>' . e($time) . '</strong>.</p>'
            . '<div style="font-size:21px;font-weight:800;color:' . $navy . ';margin:0 0 6px">' . e($m->title) . '</div>'
            . '<p style="margin:0 0 14px;font-size:14px;color:' . $muted . '">' . e(Meetings::whenText($m)) . ($m->location ? ' · ' . e($m->location) : '') . '</p>';
        if ($m->join_url) {
            $body .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 14px"><tr><td bgcolor="#4F52B2" style="border-radius:8px;background:' . ($m->video === 'teams' ? '#4F52B2' : '#1F6080') . '">'
                . '<a href="' . e($m->join_url) . '" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px">'
                . ($m->video === 'teams' ? 'Join the Microsoft Teams meeting' : 'Join the meeting') . ' &rarr;</a></td></tr></table>';
        }
        $body .= '<p style="margin:14px 0 0;font-size:12.5px;color:' . $muted . ';line-height:1.55">Running late or need another time? Reply to this email.</p>';
        $html = EmailTemplate::wrap($agencyId, $body, ['title' => 'Meeting starting soon', 'preheader' => 'Starts at ' . $time]);
        $to = $testTo ?: $p->email;
        $subject = ($testTo ? '[TEST] ' : '') . 'Starting in 30 minutes: ' . $m->title . ' at ' . $time;
        $reply = Meetings::replyAddress($m, $org);
        try {
            $build = function ($msg) use ($to, $p, $subject, $reply, $with, $agencyId, $testTo) {
                if (! $agencyId) {
                    MailScope::platform($msg);
                    $msg->getHeaders()->addTextHeader('X-KT-Send-As', BookingMail::SALES);
                }
                $msg->to($to, $testTo ? null : ($p->name ?: null))->subject($subject);
                if ($reply) $msg->replyTo($reply, $with !== '' ? $with : null);
            };
            if ($agencyId) AgencyMailer::forAgency($agencyId)->html($html, $build);
            else Mail::html($html, $build);
            if ($lead && ! $testTo) {
                DB::table('sales_activities')->insert(['lead_id' => $lead->id, 'user_id' => null, 'type' => 'email', 'done' => 1,
                    'body' => '⏰ Reminder emailed to ' . $to . ': "' . $m->title . '" starts in 30 minutes (' . Meetings::whenText($m) . ').', 'created_at' => now(), 'updated_at' => now()]);
            }
            return true;
        } catch (Throwable $e) {
            Log::warning('meeting reminder mail failed', ['meeting' => $m->id, 'attendee' => $p->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** One text from the KiddieTrac line (platform meetings). 'sent' or why not. */
    public static function sms(object $m, object $p, ?object $org, ?object $lead, ?string $testTo = null): string
    {
        $phone = $testTo;
        if (! $phone && $p->user_id) $phone = DB::table('users')->where('id', $p->user_id)->value('phone');
        if (! $phone && $lead) $phone = $lead->phone ?: ($lead->owner_phone ?? null);
        $digits = LineTexts::digits($phone);
        if (strlen($digits) !== 10) return 'no mobile number';
        $host = self::first($org->first_name ?? '');
        $first = self::first($p->name ?: ($lead->name ?? ''));
        $t = 'KiddieTrac: Hi ' . ($first !== '' ? $first : 'there') . ', a reminder that your meeting' . ($host !== '' ? ' with ' . $host : '')
            . ' starts in 30 minutes (' . self::start($m)->format('g:i A T') . ').';
        if ($m->join_url) $t .= ' Join here: ' . $m->join_url;
        $t .= ' Reply STOP to opt out.';
        try {
            [$ok, $why] = LineTexts::send($digits, ($testTo ? '[TEST] ' : '') . $t, (int) ($m->organizer_id ?: PlatformSettings::get('sales.booking.email_actor', 1)), (bool) $testTo);
            return $ok ? 'sent' : (string) $why;
        } catch (Throwable $e) {
            return 'failed: ' . $e->getMessage();
        }
    }
}
