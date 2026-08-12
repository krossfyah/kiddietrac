<?php

namespace App\Mail;

use App\Support\AgencyMail;
use App\Support\PlatformSettings;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Per-agency (white-label) outbound router.
 *
 * Set as the default mailer. For each message it resolves which agency the
 * recipients belong to; if that agency has configured its OWN email system
 * (their Microsoft 365 via Graph, or their Google/Gmail via SMTP) the message
 * is sent from THAT system with THEIR from-address. Otherwise it falls through
 * to the platform default (KiddieTrac Graph → sendmail failover), so nothing
 * changes for the many agencies that don't white-label.
 *
 * Only routes to an agency's own system when EVERY recipient maps to that one
 * agency — a mixed-agency blast falls back to the platform sender rather than
 * leaking one agency's mail through another's tenant.
 */
class AgencyRouterTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();

        $cfg = $this->agencyConfigForMessage($email);

        if ($cfg && $cfg['provider'] === 'graph') {
            $email->from(new Address($cfg['from'], (string) ($cfg['from_name'] ?? '')));
            (new GraphTransport($cfg['graph']))->send($email, $envelope);
            return;
        }

        if ($cfg && $cfg['provider'] === 'google') {
            $email->from(new Address($cfg['from'], (string) ($cfg['from_name'] ?? '')));
            $this->googleTransport($cfg['google'])->send($email, $envelope);
            return;
        }

        // Platform default: Graph with sendmail as the automatic safety net —
        // mirrors the 'failover' mailer so email can't go down if Graph lapses.
        $this->platformDefault()->send($email, $envelope);
    }

    /** The agency config to use, or null for the platform default. */
    private function agencyConfigForMessage($email): ?array
    {
        $agencies = [];
        foreach ((array) $email->getTo() as $addr) {
            $a = method_exists($addr, 'getAddress') ? $addr->getAddress() : (string) $addr;
            $aid = $a ? AgencyMail::agencyOfEmail($a) : null;
            $agencies[$aid === null ? 'null' : (string) $aid] = $aid;
        }
        // Exactly one distinct, known agency across all To recipients.
        $distinct = array_values(array_filter($agencies, fn ($v) => $v !== null));
        if (count($agencies) !== 1 || count($distinct) !== 1) {
            return null;
        }

        return AgencyMail::configFor((int) $distinct[0]);
    }

    private function googleTransport(array $g): EsmtpTransport
    {
        // Port 587 → STARTTLS (Symfony default); 465 → implicit TLS.
        $t = new EsmtpTransport($g['host'], (int) $g['port'], (int) $g['port'] === 465);
        $t->setUsername($g['username']);
        $t->setPassword($g['password']);

        return $t;
    }

    /** Platform Graph transport with a sendmail fallback (best-effort resilient). */
    private function platformDefault()
    {
        $secret = PlatformSettings::getSecret('mail.graph.client_secret');
        $s = PlatformSettings::all();
        $graph = [
            'tenant'        => $s['mail.graph.tenant'] ?? null,
            'client_id'     => $s['mail.graph.client_id'] ?? null,
            'client_secret' => $secret,
            'from'          => $s['mail.graph.from'] ?? ($s['mail.from'] ?? null),
        ];
        if ($graph['tenant'] && $graph['client_id'] && $graph['client_secret'] && $graph['from']) {
            return new class($graph) extends AbstractTransport {
                public function __construct(private array $graph)
                {
                    parent::__construct();
                }

                protected function doSend(SentMessage $message): void
                {
                    try {
                        (new GraphTransport($this->graph))->send($message->getOriginalMessage(), $message->getEnvelope());
                    } catch (TransportException $e) {
                        (new SendmailTransport())->send($message->getOriginalMessage(), $message->getEnvelope());
                    }
                }

                public function __toString(): string
                {
                    return 'graph+sendmail';
                }
            };
        }

        return new SendmailTransport();
    }

    public function __toString(): string
    {
        return 'agency_router';
    }
}
