<?php

namespace App\Support;

use App\Http\Controllers\Api\BookingEmailActionController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * AFTER A MEETING DECISION (2026-10-23, Anthony: "once I booked the meeting make sure a
 * follow-up email is sent to sales@ confirming the details ... make sure the leads are all
 * updated with anything that is changed or updated with the meeting and details").
 *
 * SalesBookingController calls after() on every accept / modify / decline / cancel - from the
 * portal or the email buttons alike:
 *   - sales@ gets a record of what was decided, by whom, the meeting details, the Teams link,
 *     whether the visitor's email went, and where the intake form stands;
 *   - the lead's website-meeting activity is rewritten to the current truth (time, link,
 *     done when declined/cancelled) and the lead's own empty fields and follow-up date are
 *     filled from the booking. Never overwrites what Sales typed on the lead.
 * The intake form already copies sales@ (ProspectIntakeController::tellSales).
 */
class BookingFollowUp
{
    public static function after(object $b, string $kind, int $actorId, ?string $oldStart = null, ?bool $mailOk = null): void
    {
        self::lead($b, $kind, $actorId, $oldStart);
        self::sales($b, $kind, $actorId, $oldStart, $mailOk);
    }

    private static function intake(object $b): ?object
    {
        try {
            return DB::table('prospect_intakes')->where('booking_id', $b->id)->whereNotNull('submitted_at')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function label(string $kind): array
    {
        return match ($kind) {
            'confirmed' => ['Meeting confirmed', '✅', 'Confirmed'],
            'modified' => ['Meeting moved', '🔁', 'Moved'],
            'declined' => ['Request declined', '✕', 'Declined'],
            'cancelled' => ['Meeting cancelled', '🗓', 'Cancelled'],
            default => ['Meeting updated', '📅', 'Updated'],
        };
    }

    /** sales@ copy of the decision. */
    public static function sales(object $b, string $kind, int $actorId, ?string $oldStart = null, ?bool $mailOk = null, ?string $testTo = null): bool
    {
        [$title, $icon, $verb] = self::label($kind);
        $type = BookingMail::typeName($b);
        $actor = DB::table('users')->where('id', $actorId)->value('first_name') ?: 'Sales';
        $live = in_array($kind, ['confirmed', 'modified'], true);
        $in = self::intake($b);
        $video = $b->meet_via !== 'phone';
        try {
            $details = array_filter([
                'When' => BookingMail::whenEt($b->starts_at),
                'Their time' => BookingMail::whenLocal($b),
                'Was' => $kind === 'modified' && $oldStart ? BookingMail::whenEt($oldStart) : null,
                'Length' => (int) $b->duration_min . ' min',
                'How' => $video ? 'Microsoft Teams video call' : 'Phone call to ' . ($b->phone ?: '—'),
                'Teams link' => $live && $video ? ($b->teams_url ?: 'none yet') : null,
                'Calendar' => $live && $video ? ($b->teams_event_id ? 'On the sales@ Outlook calendar' : 'Not on the calendar (a pasted link was used)') : null,
                'Also invited' => \App\Support\BookingTeams::invitees($b) ? implode(', ', \App\Support\BookingTeams::invitees($b)) . ($b->teams_event_id ? ' (Outlook invite sent)' : '') : null,
            ], fn ($v) => $v !== null && $v !== '');
            $who = array_filter([
                'Name' => $b->name, 'Email' => $b->email, 'Phone' => $b->phone ?: '—', 'Agency' => $b->agency ?: '—',
                'Children' => $b->children ?: '—', 'They wrote' => $b->notes ?: '—',
            ], fn ($v) => $v !== null);
            $decision = array_filter([
                $verb . ' by' => $actor . ' · ' . Carbon::now(BookingMail::TZ)->format('M j, g:i A') . ' ET',
                'Your note to them' => $b->sales_note ?: '—',
                'Reason given' => in_array($kind, ['declined', 'cancelled'], true) ? ($b->decline_reason ?: '—') : null,
                'Email to them' => $mailOk === null ? 'Not sent (you chose not to email them)' : ($mailOk ? 'Sent to ' . $b->email : 'FAILED — check the email log'),
                'Intake form' => $live ? ($in ? 'Received ' . Carbon::parse($in->submitted_at, 'UTC')->tz(BookingMail::TZ)->format('M j, g:i A') . ' ET — copy sent to sales@' : 'Not yet — they are reminded daily at 9 AM ET until the meeting') : null,
            ], fn ($v) => $v !== null);

            $html = SalesMail::render([
                'preheader' => $verb . ': ' . $type . ' with ' . $b->name . ' — ' . BookingMail::whenEt($b->starts_at),
                'eyebrow' => $title, 'icon' => $icon,
                'title' => $b->name . ($b->agency ? ' · ' . $b->agency : ''),
                'subtitle' => $type . ' · ' . (int) $b->duration_min . ' min · ' . BookingMail::whenEt($b->starts_at),
                'intro' => $live
                    ? 'This is your record of the meeting. The lead in Sales has been updated with the same details.'
                    : 'Nothing is booked. The lead in Sales has been updated.',
                'sections' => [
                    ['heading' => 'Meeting', 'rows' => $details],
                    ['heading' => 'Decision', 'rows' => $decision],
                    ['heading' => 'Who', 'rows' => $who],
                ],
                'html_heading' => $live && ! empty($b->id) ? 'Need to change it?' : null,
                'html' => $live && ! empty($b->id) ? self::changeLinks($b) : null,
                'button' => $live && $video && $b->teams_url
                    ? ['label' => 'Join the Teams meeting', 'url' => $b->teams_url]
                    : ['label' => 'Open Sales → Bookings', 'url' => 'https://app.kiddietrac.com/dashboard.html#sales-bookings?id=' . (int) $b->id],
                'note' => $b->sales_lead_id ? 'Lead: https://app.kiddietrac.com/dashboard.html#sales-leads?id=' . (int) $b->sales_lead_id : null,
            ]);
            Mail::html($html, function ($m) use ($b, $type, $verb, $testTo) {
                MailScope::platform($m);
                $m->to($testTo ?: BookingMail::SALES)
                  ->subject(($testTo ? '[TEST] ' : '') . $verb . ': ' . $type . ' with ' . $b->name . ' — ' . Carbon::parse($b->starts_at, 'UTC')->tz(BookingMail::TZ)->format('M j, g:i A'))
                  ->replyTo($b->email, $b->name);
            });
            return true;
        } catch (\Throwable $e) {
            Log::warning('booking follow-up to sales failed', ['booking' => $b->id ?? null, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private static function changeLinks(object $b): string
    {
        $a = fn ($label, $url, $fg, $bd) => '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;margin:0 8px 8px 0;padding:10px 16px;border-radius:10px;font-size:14px;font-weight:bold;text-decoration:none;background:#ffffff;color:' . $fg . ' !important;border:1px solid ' . $bd . '">' . $label . '</a>';
        return '<div>' . $a('&#9998; Change time', BookingEmailActionController::link($b, 'modify'), '#0B1F3A', '#CBD5E1')
            . $a('&#10005; Cancel meeting', BookingEmailActionController::link($b, 'decline'), '#B91C1C', '#FCA5A5') . '</div>'
            . '<div style="font-size:12.5px;color:#64748B;margin-top:4px">No sign-in needed. Nothing happens until you confirm on the page.</div>';
    }

    /** The lead tells the same story as the booking. */
    public static function lead(object $b, string $kind, int $actorId, ?string $oldStart = null): void
    {
        if (empty($b->sales_lead_id)) return;
        try {
            $lead = DB::table('sales_leads')->where('id', $b->sales_lead_id)->first();
            if (! $lead) return;
            $type = BookingMail::typeName($b);
            $when = BookingMail::whenEt($b->starts_at);
            $date = Carbon::parse($b->starts_at, 'UTC')->tz(BookingMail::TZ)->toDateString();
            $how = $b->meet_via === 'phone' ? 'phone call to ' . ($b->phone ?: '—') : 'Teams video call';
            $actor = DB::table('users')->where('id', $actorId)->value('first_name') ?: 'Sales';
            $closed = in_array($kind, ['declined', 'cancelled'], true);

            $body = match ($kind) {
                'confirmed' => $type . ' CONFIRMED — ' . $when . ' (' . (int) $b->duration_min . ' min, ' . $how . ').',
                'modified' => $type . ' MOVED to ' . $when . ($oldStart ? ' (was ' . BookingMail::whenEt($oldStart) . ')' : '') . ' — ' . (int) $b->duration_min . ' min, ' . $how . '.',
                'declined' => $type . ' request DECLINED — was ' . $when . '.',
                default => $type . ' CANCELLED — was ' . $when . '.',
            };
            if (! $closed && $b->meet_via !== 'phone' && $b->teams_url) $body .= "\nTeams link: " . $b->teams_url;
            if (\App\Support\BookingTeams::invitees($b)) $body .= "\nAlso invited: " . implode(', ', \App\Support\BookingTeams::invitees($b));
            $body .= "\n" . ($closed ? ucfirst($kind) : 'Confirmed') . ' by ' . $actor . '.';
            if ($b->sales_note) $body .= "\nNote to them: " . $b->sales_note;
            if ($closed && $b->decline_reason) $body .= "\nReason: " . $b->decline_reason;
            if ($b->notes) $body .= "\nThey wrote: " . $b->notes;

            // The meeting activity created when they booked (MarketingBookingController::pipeline).
            $act = DB::table('sales_activities')->where('lead_id', $lead->id)->where('type', 'meeting')
                ->where(fn ($q) => $q->where('body', 'like', '%on the website%')->orWhere('body', 'like', '%CONFIRMED —%')->orWhere('body', 'like', '%MOVED to%'))
                ->orderByDesc('id')->first();
            $row = ['body' => $body, 'due_date' => $date, 'done' => $closed, 'done_at' => $closed ? now() : null, 'user_id' => $actorId, 'updated_at' => now()];
            if ($act) DB::table('sales_activities')->where('id', $act->id)->update($row);
            else DB::table('sales_activities')->insert($row + ['lead_id' => $lead->id, 'type' => 'meeting', 'created_at' => now()]);

            // The lead's own fields: fill what is empty, keep what Sales typed.
            $upd = ['last_activity_at' => now(), 'updated_at' => now()];
            if (empty($lead->company) && $b->agency) $upd['company'] = mb_substr($b->agency, 0, 190);
            if (empty($lead->phone) && $b->phone) $upd['phone'] = mb_substr($b->phone, 0, 60);
            if (! $closed) {
                $upd['follow_up_date'] = $date;                     // the meeting is the next touch
                if (empty($lead->owner_id)) $upd['owner_id'] = $actorId;
            } elseif ($lead->follow_up_date && substr((string) $lead->follow_up_date, 0, 10) === $date) {
                $upd['follow_up_date'] = null;                      // that touch is gone
            }
            DB::table('sales_leads')->where('id', $lead->id)->update($upd);
        } catch (\Throwable $e) {
            Log::warning('booking lead sync failed', ['booking' => $b->id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
