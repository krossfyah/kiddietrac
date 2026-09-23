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

        /* A request that THREW never produced a response, so the failure has to be
           converted here rather than inspected below. Anything already a
           TransportException is re-thrown untouched so its message survives. */
        try {
            $resp = Http::withToken($token)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->acceptJson()
                ->timeout(20)
                ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($from).'/sendMail', [
                    'message' => $this->toGraphMessage($email, $from),
                    'saveToSentItems' => false,
                ]);
        } catch (TransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException('Graph sendMail could not reach Microsoft: '.$e->getMessage(), 0, $e);
        }

        if ($resp->status() !== 202) {
            throw new TransportException('Graph sendMail failed ('.$resp->status().'): '.substr((string) $resp->body(), 0, 300));
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
            'from'         => ['emailAddress' => ['address' => $from]],
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
