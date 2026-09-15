<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * A queued carrier for HTML that has already been built.
 *
 * Most of this codebase's notices are composed into a finished HTML string by
 * the EmailTemplate wrap step (named without the :: here on purpose — email:catalogue
 * walks app/ for that literal to find every place an email is COMPOSED, and this class
 * composes nothing, it only carries) and then handed to
 * `AgencyMailer::forAgency($id)->mailer()->html($html, function ($m) { ... })`,
 * which SENDS SYNCHRONOUSLY — the caller waits for the transport. That is correct in a
 * console command, where nobody is waiting, and wrong in a request, where somebody is.
 *
 * Measured on this host 2026-09-14: one send costs ~540ms through sendmail, which forks
 * a process per message. Deleting a family sent six of them in the request and took
 * 3,140ms; deleting a different family four days earlier, with different data, took
 * 3,144ms. The cost is the fan-out, not the record.
 *
 * The reason those call sites were synchronous is the closure: a queued mailable is
 * serialized, and "Serialization of 'Closure' is not allowed" fails the send outright.
 * So everything the closure used to set travels here as a SCALAR instead — the same
 * decision AccountNotice documents, generalised to pre-built HTML.
 */
final class AgencyHtmlNotice extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string      $bodyHtml     the finished message body
     * @param string      $subjectLine  subject as the caller composed it
     * @param int|null    $agencyId     names the sending tenant for the suppression gate
     * @param string|null $bccList      single address, or a comma-separated list
     * @param bool        $accountNotice
     *   Carries X-KT-Account-Notice. The de-enrolment notice is sent moments before the
     *   family's logins are closed, and a closed account is exactly what the mail gate
     *   blocks — this header is the existing exemption for that. It matters MORE once
     *   the message is queued, because the send now certainly happens after the close
     *   rather than probably before it. SuppressAgencyMail strips it before the message
     *   leaves, so nothing about the delivered mail changes.
     * @param bool        $bypassSuppression  X-KT-Bypass-Suppression, for previews
     */
    public function __construct(
        /* NOT $html and NOT $bcc: Illuminate\Mail\Mailable already declares both as
           ordinary properties, and a promoted readonly property cannot redeclare a
           non-readonly parent one — PHP fatals at class load, before any send. */
        public readonly string $bodyHtml,
        public readonly string $subjectLine,
        public readonly ?int $agencyId = null,
        public readonly ?string $bccList = null,
        public readonly bool $accountNotice = false,
        public readonly bool $bypassSuppression = false,
    ) {}

    public function envelope(): Envelope
    {
        $bcc = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->bccList)
        )));

        return new Envelope(subject: $this->subjectLine, bcc: $bcc);
    }

    public function headers(): Headers
    {
        $text = [];

        /* Name the sending tenant so the suppression gate applies THAT agency's
           switches, rather than judging the address against every account carrying it.
           One address can hold accounts in several agencies and any one of them being
           switched off would otherwise cancel this. See App\Support\MailScope. */
        if ($this->agencyId) {
            $text['X-KT-Agency-Id'] = (string) $this->agencyId;
        }
        if ($this->accountNotice) {
            $text['X-KT-Account-Notice'] = '1';
        }
        if ($this->bypassSuppression) {
            $text['X-KT-Bypass-Suppression'] = '1';
        }

        return new Headers(text: $text);
    }

    public function content(): Content
    {
        // Already wrapped in the branded layout by the caller — passed straight through
        // rather than re-wrapped, which would nest one template inside another.
        return new Content(htmlString: $this->bodyHtml);
    }
}
