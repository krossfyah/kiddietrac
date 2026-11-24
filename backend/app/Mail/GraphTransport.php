<?php

namespace App\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through the Microsoft Graph API (app-only / client-credentials).
 *
 * Config (from PlatformSettings::applyMail): tenant, client_id, client_secret, from.
 * EVERY failure leaves here as a TransportException. That is the contract the
 * sendmail safety net in AgencyRouterTransport depends on, and until 2026-09-23
 * it was only half true: both calls below go out through the Laravel HTTP client,
 * which raises Illuminate\Http\Client\ConnectionException on a DNS or connect
 * fault. That is not a TransportException, so it sailed past the net and killed
 * the queued job with the mail still unsent. Four arrival notifications to real
 * parents were lost that way on 22 and 23 Sep 2026, while sendmail was up
 * the whole time.
 */
class GraphTransport extends AbstractTransport
{
    public function __construct(private array $config)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $token = $this->accessToken();
        if (! $token) {
            throw new TransportException('Graph: could not obtain an access token (check tenant/client id/secret — secret may have expired).');
        }

        $from = $this->config['from'] ?: (($f = $email->getFrom()) ? $f[0]->getAddress() : null);
        if (! $from) {
            throw new TransportException('Graph: no sender address configured.');
        }

        /* SEND AS A TEAM MAILBOX (2026-10-22). Anthony: the lead welcome should come from
           sales@kiddietrac.com, not noreply. Every platform email goes out as the one
           configured mailbox; a message may now ask for another of OUR mailboxes with
           X-KT-Send-As (allowlist below; the header itself is never sent). If Microsoft
           refuses that mailbox (not a mailbox in the tenant, or the app may not send as
           it), the email still goes, from the usual mailbox, with its Reply-To intact. */
        $sendAs = null;
        /* X-KT-Send-As-Strict (2026-11-09, sales campaigns "send from any mailbox that is setup on the portal"): the
           sender chose this mailbox, so a refusal fails the send with the reason instead of quietly going out from
           another address. Without it the old rule stands: the default mailbox, Reply-To intact. */
        $strict = false;
        try {
            $strict = $email->getHeaders()->has('X-KT-Send-As-Strict');
            $email->getHeaders()->remove('X-KT-Send-As-Strict');
            if ($email->getHeaders()->has('X-KT-Send-As')) {
                $want = strtolower(trim((string) $email->getHeaders()->get('X-KT-Send-As')->getBodyAsString()));
                $email->getHeaders()->remove('X-KT-Send-As');
                if ($want !== strtolower($from)) {
                    if (self::mayAsk($want)) {
                        $sendAs = $want;
                    } elseif ($strict) {
                        throw new TransportException('Not sent: '.$want.' is not a KiddieTrac mailbox set up on the portal.');
                    }
                }
            }
        } catch (TransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
        }
        if ($sendAs) {
            try {
                $r = $this->post($token, $sendAs, $email);
                if ($r->status() === 202) {
                    return;
                }
                if ($strict) {
                    throw new TransportException('Not sent: Microsoft would not send from '.$sendAs.' ('.$r->status().'). Check the mailbox in Sales > Email, or pick another sender.');
                }
                \Illuminate\Support\Facades\Log::warning('Graph send-as refused; sent from the default mailbox instead', [
                    'send_as' => $sendAs, 'status' => $r->status(), 'body' => substr((string) $r->body(), 0, 200),
                ]);
            } catch (TransportException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($strict) {
                    throw new TransportException('Not sent: could not reach Microsoft to send from '.$sendAs.'.', 0, $e);
                }
                \Illuminate\Support\Facades\Log::warning('Graph send-as failed; sent from the default mailbox instead', ['send_as' => $sendAs, 'error' => substr($e->getMessage(), 0, 200)]);
            }
        }

        /* A request that THREW never produced a response, so the failure has to be
           converted here rather than inspected below. Anything already a
           TransportException is re-thrown untouched so its message survives. */
        try {
            $resp = $this->post($token, $from, $email);
        } catch (TransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException('Graph sendMail could not reach Microsoft: '.$e->getMessage(), 0, $e);
        }

        if ($resp->status() !== 202) {
            throw new TransportException('Graph sendMail failed ('.$resp->status().'): '.substr((string) $resp->body(), 0, 300));
        }
    }

    /** Our own mailboxes a message may ask to be sent as (X-KT-Send-As). */
    public const SEND_AS = ['sales@kiddietrac.com', 'info@kiddietrac.com', 'support@kiddietrac.com'];

    /** SEND_AS, or a platform mailbox connected on the portal (Sales > Email: no agency, Microsoft, not removed). */
    public static function mayAsk(string $address): bool
    {
        $address = strtolower(trim($address));
        if (in_array($address, self::SEND_AS, true)) {
            return true;
        }
        try {
            return \Illuminate\Support\Facades\DB::table('email_accounts')->whereNull('agency_id')->whereNull('deleted_at')
                ->where('provider', 'microsoft')->whereRaw('LOWER(email_address) = ?', [$address])->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* SENT AS A WHOLE MESSAGE (2026-10-23). Anthony: Safia's announcements "ended up in some
       peoples junk/spam". Authentication was never the problem (SPF, DKIM and DMARC all pass
       for kiddietrac.com through Microsoft 365). The JSON form of sendMail was: it carries
       ONE body, so every email went HTML-only with no plain-text part, it cannot carry
       standard headers (List-Unsubscribe, ...), and it dropped the sender's display name, so
       mail from "iLearn Home Child Care" arrived as "KiddieTrac No-Reply". Filters, Outlook's
       most of all, score all three. Graph also takes the complete MIME message, built by
       Symfony exactly as for SMTP, which keeps all of it. The JSON form stays as the fallback
       (a message too large for one request, or a MIME send Microsoft refuses). */
    private function post(string $token, string $mailbox, Email $email)
    {
        $mime = $this->mime($mailbox, $email);
        if ($mime !== null && strlen($mime) < 3_600_000) {
            try {
                $r = Http::withToken($token)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->timeout(30)
                    ->withBody($mime, 'text/plain')
                    ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/sendMail');
                if ($r->status() === 202 || $r->status() === 401) {
                    return $r;
                }
                \Illuminate\Support\Facades\Log::warning('Graph MIME send refused; sending the simple form instead', [
                    'status' => $r->status(), 'body' => substr((string) $r->body(), 0, 200),
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Graph MIME send failed; sending the simple form instead', ['error' => substr($e->getMessage(), 0, 200)]);
            }
        }

        return Http::withToken($token)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->acceptJson()
            ->timeout(20)
            ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/sendMail', [
                'message' => $this->toGraphMessage($email, $mailbox),
                'saveToSentItems' => false,
            ]);
    }

    /**
     * The message as base64 MIME for Graph: From is the mailbox it goes out through (with
     * the sender's display name kept), our internal X-KT-* headers are removed (they are
     * for this server's listeners and read as machine-sent), and Bcc is written back in,
     * since Symfony leaves it out of the rendered text; Exchange delivers to Bcc and
     * removes the line. A copy is changed, never the message the listeners still read.
     */
    private function mime(string $mailbox, Email $email): ?string
    {
        try {
            $m = clone $email;
            $f = $m->getFrom();
            $name = $f ? (string) $f[0]->getName() : '';
            $m->from(new Address($mailbox, $name));
            $h = $m->getHeaders();
            $internal = [];
            foreach ($h->all() as $header) {
                if (stripos($header->getName(), 'X-KT-') === 0) {
                    $internal[] = $header->getName();
                }
            }
            foreach (array_unique($internal) as $n) {
                $h->remove($n);
            }
            $raw = $m->toString();
            if ($bcc = $m->getBcc()) {
                $raw = 'Bcc: '.implode(', ', array_map(fn (Address $a) => $a->toString(), $bcc))."\r\n".$raw;
            }

            return base64_encode($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function accessToken(): ?string
    {
        $cfg = $this->config;
        if (empty($cfg['tenant']) || empty($cfg['client_id']) || empty($cfg['client_secret'])) {
            return null;
        }
        $key = 'graph.token.'.md5($cfg['tenant'].'|'.$cfg['client_id']);

        // Cache ~50 min (tokens live 60). A failed mint is NOT cached.
        $cached = Cache::get($key);
        if ($cached) {
            return $cached;
        }
        /* Minting the token reaches the network too, so it carries the same risk.
           Returning null here would be actively misleading: the caller reports that
           as an expired secret, which sends whoever reads the log into the Azure
           portal to fix something that is not broken. */
        try {
            $r = Http::asForm()
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->timeout(20)
                ->post('https://login.microsoftonline.com/'.rawurlencode($cfg['tenant']).'/oauth2/v2.0/token', [
                    'client_id'     => $cfg['client_id'],
                    'client_secret' => $cfg['client_secret'],
                    'scope'         => 'https://graph.microsoft.com/.default',
                    'grant_type'    => 'client_credentials',
                ]);
        } catch (\Throwable $e) {
            throw new TransportException('Graph: could not reach the token endpoint: '.$e->getMessage(), 0, $e);
        }
        $tok = $r->json('access_token');
        if ($tok) {
            Cache::put($key, $tok, 3000);
        }

        return $tok ?: null;
    }

    private function toGraphMessage(Email $email, string $from): array
    {
        $rc = fn ($addrs) => collect($addrs ?: [])
            ->map(fn (Address $a) => ['emailAddress' => array_filter(['address' => $a->getAddress(), 'name' => $a->getName() ?: null])])
            ->values()->all();

        $html = $email->getHtmlBody();
        $body = $html !== null
            ? ['contentType' => 'HTML', 'content' => (string) $html]
            : ['contentType' => 'Text', 'content' => (string) $email->getTextBody()];

        $msg = [
            'subject'      => (string) $email->getSubject(),
            'body'         => $body,
            'from'         => ['emailAddress' => array_filter(['address' => $from, 'name' => ($ef = $email->getFrom()) ? ($ef[0]->getName() ?: null) : null])],
            'toRecipients' => $rc($email->getTo()),
        ];
        if ($email->getCc()) {
            $msg['ccRecipients'] = $rc($email->getCc());
        }
        if ($email->getBcc()) {
            $msg['bccRecipients'] = $rc($email->getBcc());
        }
        if ($email->getReplyTo()) {
            $msg['replyTo'] = $rc($email->getReplyTo());
        }

        $attachments = [];
        foreach ($email->getAttachments() as $att) {
            $a = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $att->getFilename() ?: 'attachment',
                'contentType'  => $att->getMediaType().'/'.$att->getMediaSubtype(),
                'contentBytes' => base64_encode($att->getBody()),
            ];
            // Inline images (e.g. the embedded logo, src="cid:..."). Without
            // isInline + contentId Graph treats them as regular attachments and
            // the cid reference in the HTML never resolves → a broken image.
            try {
                $headers = $att->getPreparedHeaders();
                $disposition = $headers->getHeaderBody('Content-Disposition');
                $cid = method_exists($att, 'getContentId') ? $att->getContentId() : null;
                if ($disposition === 'inline' && $cid) {
                    $a['isInline']  = true;
                    $a['contentId'] = $cid;
                }
            } catch (\Throwable $e) {
                // fall back to a plain attachment
            }
            $attachments[] = $a;
        }
        if ($attachments) {
            $msg['attachments'] = $attachments;
        }

        return $msg;
    }

    public function __toString(): string
    {
        return 'graph';
    }
}
