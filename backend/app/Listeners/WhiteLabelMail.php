<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Brand;
use Illuminate\Mail\Events\MessageSending;

/**
 * NO "KIDDIETRAC" IN A WHITE-LABEL AGENCY'S MAIL (2026-10-21).
 *
 * Email text is written in hundreds of places - controllers, digests, reminders, the
 * what's-new mailing - and many say "KiddieTrac" in a sentence. EmailTemplate already
 * puts the right header and footer on; this catches the words in between, once, for
 * every message: when EVERY "To" address belongs to the same white-label agency, the
 * subject, the HTML and the text part have "KiddieTrac" replaced with that agency's
 * brand name (App\Support\Brand::rename - never inside an address or a link), and a
 * From name that says KiddieTrac is renamed too.
 *
 * Mixed or unknown recipients (KiddieTrac's own inbox, a support ticket to HQ, a blast
 * across agencies) are left exactly as written. Never blocks a send.
 */
final class WhiteLabelMail
{
    public function handle(MessageSending $event): void
    {
        try {
            $msg = $event->message;
            /* A REVIEW COPY FOR THE PLATFORM OWNER (2026-11-27): a [TEST] / X-KT-Preview message that goes ONLY to
               TestAgency::PREVIEW_INBOXES and asks with X-KT-Keep-Brand-Words keeps its words exactly as written. Test
               sends must go through a test agency (6), which is white-label, and "[TEST] KiddieTrac payslip sample"
               arrived as "[TEST] Little Steps payslip sample". Nobody at a tenant ever receives such a message. */
            if ($msg->getHeaders()->has('X-KT-Keep-Brand-Words')) {
                $to = array_map(fn ($a) => $a->getAddress(), array_merge($msg->getTo(), $msg->getCc(), $msg->getBcc()));
                if (\App\Support\TestAgency::previewOnly($to, (string) $msg->getSubject(), $msg->getHeaders()->has('X-KT-Preview'))) {
                    return;
                }
            }
            $agency = self::agencyFor($msg);
            if (! $agency || ! Brand::whiteLabel($agency)) {
                return;
            }
            $subject = (string) $msg->getSubject();
            $renamed = Brand::rename($subject, $agency);
            if ($renamed !== $subject) {
                $msg->subject($renamed);
            }
            $html = $msg->getHtmlBody();
            if (is_string($html) && $html !== '') {
                $new = Brand::rename($html, $agency);
                if ($new !== $html) {
                    $msg->html($new);
                }
            }
            $text = $msg->getTextBody();
            if (is_string($text) && $text !== '') {
                $new = Brand::rename($text, $agency);
                if ($new !== $text) {
                    $msg->text($new);
                }
            }
            $from = $msg->getFrom();
            if ($from && preg_match('/Kiddie ?[Tt]rac/', (string) $from[0]->getName())) {
                $msg->from(new \Symfony\Component\Mime\Address($from[0]->getAddress(), Brand::rename($from[0]->getName(), $agency)));
            }
        } catch (\Throwable $e) {
            // The brand name must never be the reason an email does not go.
        }
    }

    /**
     * Whose mail this is, only when it is CERTAIN:
     *   1. the sender's own X-KT-Agency-Id header;
     *   2. the agency EmailTemplate rendered it for (its <!--kt-brand-agency:N--> marker);
     *   3. the recipients - each address must belong to exactly ONE agency across every
     *      account on it, and to no platform admin. Anthony's address is a platform admin,
     *      an iLearn admin and a Test Agency parent at once: guessing there would put the
     *      Test Agency's brand on iLearn's mail.
     */
    /* public since 2026-11-02: App\Listeners\SocialLinksMail asks the same question. */
    public static function agencyFor($msg): ?int
    {
        $h = $msg->getHeaders();
        if ($h->has('X-KT-Agency-Id')) {
            $v = (int) trim((string) $h->get('X-KT-Agency-Id')->getBodyAsString());
            if ($v > 0) return $v;
        }
        $html = (string) $msg->getHtmlBody();
        if ($html !== '' && preg_match('/<!--kt-brand-agency:(\d+)-->/', $html, $m)) {
            return (int) $m[1];
        }
        $agency = null;
        foreach ($msg->getTo() as $addr) {
            $a = self::strictAgencyOf($addr->getAddress());
            if (! $a || ($agency !== null && $a !== $agency)) return null;
            $agency = $a;
        }
        return $agency;
    }

    private static function strictAgencyOf(string $email): ?int
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') return null;
        $uids = \Illuminate\Support\Facades\DB::table('users')->whereRaw('LOWER(TRIM(email)) = ?', [$email])->pluck('id')->all();
        if (! $uids) return null;
        $roles = \Illuminate\Support\Facades\DB::table('role_assignments')->whereIn('user_id', $uids)->get(['role', 'agency_id', 'centre_id']);
        $set = [];
        foreach ($roles as $r) {
            if ($r->role === 'platform_admin' || $r->role === 'reseller_admin' || $r->role === 'sales_rep') return null;
            $a = $r->agency_id ?: ($r->centre_id ? \Illuminate\Support\Facades\DB::table('centres')->where('id', $r->centre_id)->value('agency_id') : null);
            if ($a) $set[(int) $a] = true;
        }
        foreach (\Illuminate\Support\Facades\DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')->whereIn('g.user_id', $uids)->pluck('c.agency_id') as $a) {
            if ($a) $set[(int) $a] = true;
        }
        return count($set) === 1 ? (int) array_key_first($set) : null;
    }
}
