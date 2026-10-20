<?php

namespace App\Support;

use App\Services\AgencyMailer;
use Illuminate\Support\Facades\DB;

/**
 * "Send from" for marketing campaigns, a white-label licence feature (2026-10-20).
 *
 * Anthony: campaigns should be able to go out from another email set up in the system, as
 * part of the white-label program. The accounts are the agency's SENDER ACCOUNTS (the
 * rsvp_senders table, managed on Event RSVPs > Sender accounts): a Gmail / Google Workspace
 * mailbox connected by signing in with Google, or an SMTP account. A campaign stores its
 * choice in marketing_campaigns.sender_id; NULL means the agency's normal email delivery
 * (AgencyMailer: its own Microsoft 365, or the KiddieTrac default).
 *
 * WHITE-LABEL ONLY. The licence is agencies.powered_by_visible = 0. A campaign keeps its
 * sender_id if the licence later lapses, but it is not honoured: forCampaign() returns null
 * and the mail leaves through the agency default, as it would for any other agency.
 *
 * Sending through a sender account goes through RsvpSender::html(), so the agency's
 * suppression, the email log and failure auditing all still apply to every message.
 */
final class CampaignSender
{
    /** Gmail pacing: a personal mailbox sending a burst reads as spam (see RsvpController::queue). */
    public const PER_RUN = 60;          // recipients per 5-minute run of kiddietrac:campaigns-mail
    public const GAP_SECONDS = 4;       // between messages, within a run

    public static function whiteLabel(?object $agency): bool
    {
        return $agency !== null && isset($agency->powered_by_visible) && (int) $agency->powered_by_visible === 0;
    }

    public static function whiteLabelAgency(int $agencyId): bool
    {
        return self::whiteLabel(DB::table('agencies')->where('id', $agencyId)->first(['id', 'powered_by_visible']));
    }

    /** Usable right now: a Gmail account still connected, or an SMTP account whose last check passed. */
    public static function ready(object $s): bool
    {
        if (RsvpSender::isGmail($s)) {
            return ! empty($s->google_refresh_enc) && ($s->google_status ?? '') === 'connected';
        }

        return ($s->last_test_status ?? '') === 'connected';
    }

    public static function address(object $s): string
    {
        return (string) ($s->from_address ?: ($s->google_email ?? ''));
    }

    /** The choices for the campaign editor. Never secrets or tokens. */
    public static function options(int $agencyId): array
    {
        $rows = DB::table('rsvp_senders')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('label')->get();
        $out = [];
        foreach ($rows as $s) {
            $out[] = [
                'id' => (int) $s->id,
                'label' => $s->label,
                'address' => self::address($s),
                'from_name' => $s->from_name,
                'type' => RsvpSender::isGmail($s) ? 'gmail' : 'smtp',
                'ready' => self::ready($s),
            ];
        }

        return $out;
    }

    /** The agency's normal From, for the "Agency default" choice. */
    public static function defaultFrom(int $agencyId): array
    {
        try {
            $m = AgencyMailer::forAgency($agencyId);

            return ['address' => $m->fromAddress(), 'name' => $m->fromName()];
        } catch (\Throwable $e) {
            return ['address' => null, 'name' => null];
        }
    }

    /**
     * The sender account a campaign will actually use, or null for the agency default:
     * null when none was chosen, the agency is not white-label, or the account is gone or
     * no longer connected.
     */
    public static function forCampaign(object $campaign, ?object $agency): ?object
    {
        if (empty($campaign->sender_id) || ! self::whiteLabel($agency)) {
            return null;
        }
        $s = RsvpSender::find((int) $agency->id, (int) $campaign->sender_id);

        return ($s && self::ready($s)) ? $s : null;
    }

    /**
     * Validate a sender_id coming from the editor. Returns [ok, message]. Null/0 clears it.
     */
    public static function validateChoice(int $agencyId, $senderId): array
    {
        if (empty($senderId)) {
            return [true, null];
        }
        if (! self::whiteLabelAgency($agencyId)) {
            return [false, 'Sending campaigns from another mailbox is part of the white-label licence.'];
        }
        $s = RsvpSender::find($agencyId, (int) $senderId);
        if (! $s) {
            return [false, 'That sender account was not found for this agency.'];
        }

        return [true, null];
    }
}
