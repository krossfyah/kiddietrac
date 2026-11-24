<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WAS THE PROPOSAL RECEIVED AND OPENED? (2026-10-23, Anthony: "with proposal sent add
 * something to track to see if they received and opened as well").
 *
 *  - Sent: Microsoft accepted the email for delivery (sales_quotes.email_sent_at /
 *    email_send_status). A bounce would only show in the sending mailbox, which the
 *    platform's Microsoft app may not read (403 without Mail.Read).
 *  - Opened: a 1×1 image in the proposal email and in each follow-up
 *    (GET /proposal-open/{id}/{sig}.gif). Counted per open; the first open after a send
 *    goes on the lead and in the proposal author's bell. A fetch within 20 seconds of
 *    sending is a mail scanner, not a person, and is kept but not counted.
 *  - Viewed online / PDF downloaded: from the private sign link (sign_viewed_at,
 *    pdf_downloaded_at).
 * Limits, said on screen: mail apps that block images never report an open; Apple Mail
 * may report one the person did not make; CC and BCC copies carry the same image.
 */
class ProposalTracking
{
    public static function sig(int $id): string
    {
        return substr(hash_hmac('sha256', 'kt-q-open|' . $id, (string) config('app.key')), 0, 24);
    }

    /** The image tag for an email. $kind: email | followup */
    public static function pixel(int $id, string $kind = 'email'): string
    {
        $url = rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/') . '/api/v1/proposal-open/' . $id . '/' . self::sig($id) . '.gif?k=' . ($kind === 'followup' ? 'f' : 'e');
        return '<img src="' . e($url) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;margin:0;padding:0">';
    }

    public static function sent(int $id, bool $ok, ?string $error = null): void
    {
        try {
            DB::table('sales_quotes')->where('id', $id)->update($ok
                ? ['email_sent_at' => now(), 'email_send_status' => 'sent', 'email_send_error' => null]
                : ['email_send_status' => 'failed', 'email_send_error' => mb_substr((string) $error, 0, 250)]);
        } catch (Throwable $e) {
        }
    }

    public static function open(int $id, string $kind, Request $r): void
    {
        try {
            $q = DB::table('sales_quotes')->where('id', $id)->first(['id', 'lead_id', 'number', 'created_by', 'email_sent_at', 'last_followup_at', 'email_last_opened_at']);
            if (! $q) return;
            $sentAt = max((string) $q->email_sent_at, (string) $q->last_followup_at);
            $scan = $sentAt !== '' && now()->diffInSeconds(\Illuminate\Support\Carbon::parse($sentAt), true) < 20;
            // Our own email log showing the message in the portal is not the prospect opening it.
            if (preg_match('~^https?://([a-z0-9-]+\.)*kiddietrac\.com~i', (string) $r->headers->get('referer'))) { $scan = true; $kind = 'preview'; }
            DB::table('sales_quote_opens')->insert(['quote_id' => $id, 'kind' => $kind, 'counted' => $scan ? 0 : 1,
                'ip' => substr((string) $r->ip(), 0, 45), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 250), 'created_at' => now()]);
            if ($scan) return;
            $first = ! $q->email_last_opened_at || ($sentAt !== '' && (string) $q->email_last_opened_at < $sentAt);
            DB::table('sales_quotes')->where('id', $id)->update([
                'email_open_count' => DB::raw('COALESCE(email_open_count,0)+1'),
                'email_opened_at' => DB::raw('COALESCE(email_opened_at, NOW())'),
                'email_last_opened_at' => now(),
            ]);
            if ($first) self::tell($q, $kind === 'followup' ? 'opened the follow-up email for' : 'opened the email with');
        } catch (Throwable $e) {
        }
    }

    public static function pdfDownloaded(int $id): void
    {
        try {
            $q = DB::table('sales_quotes')->where('id', $id)->first(['id', 'lead_id', 'number', 'created_by', 'pdf_downloaded_at']);
            if (! $q) return;
            DB::table('sales_quotes')->where('id', $id)->update(['pdf_downloaded_at' => now()]);
            if (! $q->pdf_downloaded_at) self::tell($q, 'downloaded the PDF of');
        } catch (Throwable $e) {
        }
    }

    /** On the lead's timeline, and in the author's bell. */
    private static function tell(object $q, string $what): void
    {
        $lead = DB::table('sales_leads')->where('id', $q->lead_id)->first(['id', 'name', 'company']);
        if (! $lead) return;
        $who = $lead->name ?: ($lead->company ?: 'The prospect');
        $line = $who . ' ' . $what . ' proposal ' . ($q->number ?: '');
        DB::table('sales_activities')->insert(['lead_id' => $lead->id, 'user_id' => null, 'type' => 'email', 'body' => '📖 ' . $line,
            'done' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_leads')->where('id', $lead->id)->update(['last_activity_at' => now()]);
        if ($q->created_by) {
            try {
                Notify::write([['user_id' => (int) $q->created_by, 'type' => 'proposal_opened', 'title' => '📖 ' . $line,
                    'body' => 'Tracked on the proposal in Sales → Leads.', 'data' => json_encode(['link' => '#sales-lead?id=' . $lead->id]), 'created_at' => now()]]);
            } catch (Throwable $e) {
            }
        }
    }
}
