<?php

namespace App\Support;

use App\Mail\FailureAuditingTransport;
use App\Services\AgencyMailer;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

/**
 * Per-event sender accounts for Event RSVPs (2026-10-06).
 *
 * Anthony chose "Per-event sender account": an agency saves a short list of mail accounts
 * (a Town of Mono address, the daycare's own) and each event picks one.
 *
 * WHAT MUST NOT CHANGE BY SENDING THROUGH ONE. The Mailer built here is constructed exactly
 * as AgencyMailer builds a white-label agency's: the same events dispatcher, so
 * SuppressAgencyMail (MessageSending) still decides whether the message may leave and the
 * MessageSent logger still writes email_logs; the transport wrapped in
 * FailureAuditingTransport, so a refused login or a bounce is filed as `failed`; and the
 * X-KT-Agency-Id stamp, so suppression and the log judge it as THIS agency's mail. A sender
 * account changes which mailbox the message leaves from, never whether the agency's rules
 * apply to it.
 *
 * The password is stored with Crypt and never returned to the browser (has_password only),
 * and every error message is scrubbed of it before it is stored, logged or shown.
 *
 * GMAIL (2026-10-06): this host blocks all outbound SMTP, so the usual sender is now
 * type 'gmail_oauth' — connected by signing in with Google (RsvpGmail) and sent through the
 * Gmail API over HTTPS (GmailApiTransport). Only the TRANSPORT differs; the Mailer is built
 * the same way, so suppression and logging are untouched. 'smtp' stays for a host that
 * permits it.
 */
final class RsvpSender
{
    public const ENCRYPTIONS = ['tls', 'ssl'];

    public static function find(int $agencyId, ?int $id): ?object
    {
        if (! $id) return null;

        return DB::table('rsvp_senders')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
    }

    public static function password(object $s): string
    {
        if (empty($s->smtp_pass_enc)) return '';
        try {
            return Crypt::decryptString($s->smtp_pass_enc);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** smtp://user:pass@host:587  or  smtps://user:pass@host:465 — as AgencyMailer::dsn(). */
    private static function dsn(object $s): string
    {
        $user = rawurlencode((string) ($s->smtp_user ?? ''));
        $pass = rawurlencode(self::password($s));
        $scheme = ($s->smtp_encryption ?? 'tls') === 'ssl' ? 'smtps' : 'smtp';
        $auth = ($user !== '' || $pass !== '') ? $user . ':' . $pass . '@' : '';

        return $scheme . '://' . $auth . $s->smtp_host . ':' . ((int) ($s->smtp_port ?: 587));
    }

    /** Remove the password (plain and URL-encoded) from anything about to be shown or stored. */
    public static function scrub(string $msg, object $s): string
    {
        $p = self::password($s);
        if ($p !== '') {
            $msg = str_replace([$p, rawurlencode($p)], '••••', $msg);
        }

        return mb_substr($msg, 0, 480);
    }

    /** The Laravel mailer for this account — built the way AgencyMailer builds an agency's. */
    public static function isGmail(object $s): bool
    {
        return ($s->type ?? 'smtp') === 'gmail_oauth';
    }

    public static function mailer(object $s): Mailer
    {
        // A Gmail sender that is not connected still gets a Mailer: its transport refuses at
        // send time, AFTER suppression has had its say, so a suppressed agency is never
        // reported as anything but suppressed.
        $inner = self::isGmail($s) ? new \App\Mail\GmailApiTransport($s) : Transport::fromDsn(self::dsn($s));
        $transport = FailureAuditingTransport::wrap($inner);
        $mailer = new Mailer('kt-rsvp-sender-' . $s->id, view(), $transport, app('events'));
        $mailer->alwaysFrom($s->from_address ?: ($s->google_email ?: 'not-connected@example.invalid'), $s->from_name ?: null);

        return $mailer;
    }

    /**
     * Send pre-built HTML through the account, stamped as the agency's mail. The caller's
     * closure runs first, as in AgencyMailer::html().
     */
    public static function html(object $s, int $agencyId, string $html, \Closure $build)
    {
        // Attribution for the log, exactly as AgencyMailer::forAgency() records it.
        AgencyMailer::$lastAgencyId = $agencyId;

        return self::mailer($s)->html($html, function ($m) use ($build, $agencyId) {
            $build($m);
            try {
                if (! $m->getHeaders()->has('X-KT-Agency-Id')) {
                    $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                }
            } catch (\Throwable $e) {
            }
        });
    }

    /**
     * Connect and log in WITHOUT sending anything, so the account can be checked even where
     * the agency's mail is switched off. @return array{ok:bool, error:?string}
     */
    public static function check(object $s): array
    {
        if (self::isGmail($s)) {
            // A fresh access token proves the grant still works — and sends nothing.
            try {
                RsvpGmail::accessToken($s, true);

                return ['ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 480)];
            }
        }
        try {
            $t = Transport::fromDsn(self::dsn($s));
            if (! $t instanceof SmtpTransport) {
                return ['ok' => false, 'error' => 'Only SMTP accounts are supported.'];
            }
            $t->start();
            $t->stop();

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => self::scrub($e->getMessage(), $s)];
        }
    }

    /** What the browser may see: never the password. */
    public static function present(object $s, string $tz): array
    {
        return [
            'id' => (int) $s->id, 'type' => $s->type ?? 'smtp', 'label' => $s->label, 'from_name' => $s->from_name, 'from_address' => $s->from_address,
            // Gmail: who it is connected as and whether it still works. Never the tokens.
            'google_email' => $s->google_email ?? null, 'google_status' => $s->google_status ?? null,
            'google_connected_at' => ! empty($s->google_connected_at) ? AgencyTime::fmt($s->google_connected_at, $tz, 'M j, Y g:i A') : null,
            'reply_to' => $s->reply_to, 'smtp_host' => $s->smtp_host, 'smtp_port' => (int) $s->smtp_port,
            'smtp_encryption' => $s->smtp_encryption, 'smtp_user' => $s->smtp_user, 'has_password' => ! empty($s->smtp_pass_enc),
            'verified_at' => $s->verified_at ? AgencyTime::fmt($s->verified_at, $tz, 'M j, Y g:i A') : null,
            'last_test_status' => $s->last_test_status, 'last_test_error' => $s->last_test_error,
            'last_test_at' => $s->last_test_at ? AgencyTime::fmt($s->last_test_at, $tz, 'M j, Y g:i A') : null,
        ];
    }
}
