<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\GoogleMailbox;
use App\Support\MicrosoftMailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Email Client — live IMAP reader. Uses the connected accounts created by the
 * setup wizard (email_accounts) to list folders/messages and fetch bodies from
 * the user's real mailbox. Credentials are decrypted only for the request and
 * never leave the server. Read-only (list + read); send is a later phase.
 */
class EmailMailController extends Controller
{
    /** The caller's own account, or null. */
    private function account(Request $request, int $id)
    {
        $a = DB::table('email_accounts')->where('id', $id)
            ->where(\App\Support\SalesMailbox::owner($request))->whereNull('deleted_at')->first();
        if ($a) $this->tz = \App\Support\AgencyTime::tz($a->agency_id ? (int) $a->agency_id : null);
        return $a;
    }

    /** The account's agency timezone: mail times show in it, never the server's UTC. */
    private ?string $tz = null;

    /** IMAP connection-string prefix for a folder on this account. */
    private function ref($a): string
    {
        $enc = $a->imap_encryption === 'ssl' ? '/imap/ssl/novalidate-cert'
            : ($a->imap_encryption === 'tls' ? '/imap/tls/novalidate-cert' : '/imap/notls');
        return '{' . $a->imap_host . ':' . ((int) $a->imap_port ?: 143) . $enc . '}';
    }

    /** Open an IMAP stream to a folder (default INBOX). Returns [stream|false, error]. */
    private function open($a, string $folder = 'INBOX')
    {
        if (! $a->imap_host || ! $a->imap_port) return [false, 'IMAP server is not configured for this account.'];
        $secret = '';
        try { $secret = $a->secret ? Crypt::decryptString($a->secret) : ''; } catch (\Throwable $e) { return [false, 'Stored password could not be read.']; }
        if ($secret === '') return [false, 'No password saved — open Settings and re-enter it.'];

        // Guard against slow/hanging servers.
        imap_timeout(IMAP_OPENTIMEOUT, 10);
        imap_timeout(IMAP_READTIMEOUT, 12);
        imap_errors(); // clear
        $stream = @imap_open($this->ref($a) . $this->encodeMailbox($folder), $a->username ?: $a->email_address, $secret, 0, 1);
        if (! $stream) {
            $err = imap_last_error() ?: 'Could not connect. Check the server settings and password (an app password may be required).';
            /* DRAIN THE ERROR STACK (2026-10-20, support ticket #157). libc-client keeps every
               failure on its own stack and PHP raises whatever is left when the request ends -
               "PHP Request Shutdown: Server disables LOGIN ..." - which surfaced as a server
               error and auto-filed a ticket, on top of the 422 this already returned. */
            imap_errors();
            imap_alerts();
            $err = self::explain($err, $a);
            try {
                DB::table('email_accounts')->where('id', $a->id)
                    ->update(['status' => 'error', 'status_detail' => 'IMAP: ' . mb_substr($err, 0, 480), 'updated_at' => now()]);
            } catch (\Throwable $e) {}
            return [false, $err];
        }
        return [$stream, null];
    }

    /** The inbox's unread count over IMAP, or null when the mailbox cannot be reached (kiddietrac:mail-watch, the top bar). */
    public function inboxUnread(object $a): ?int
    {
        if (GoogleMailbox::uses($a)) {
            try { return (int) (GoogleMailbox::call($a, 'GET', '/labels/INBOX')->json('messagesUnread') ?? 0); } catch (\Throwable $e) { return null; }
        }
        [$stream] = $this->open($a);
        if (! $stream) return null;
        try {
            $st = @imap_status($stream, $this->ref($a) . 'INBOX', SA_UNSEEN);
            return $st ? (int) $st->unseen : null;
        } finally { @imap_close($stream); imap_errors(); imap_alerts(); }
    }

    /** Re-count the inbox for the top-bar badge once the answer has gone back (reading or changing mail moves it). */
    private function recount(object $a): void
    {
        app()->terminating(function () use ($a) {
            // ...and the new-mail bell rows for whatever is now read (2026-11-04).
            if (MicrosoftMailbox::uses($a)) { \App\Support\MailUnread::refresh($a); \App\Support\MailUnread::settleBell($a); return; }
            try { $n = $this->inboxUnread($a); if ($n !== null) \App\Support\MailUnread::store((int) $a->id, $n); } catch (\Throwable $e) {}
        });
    }

    /** A sign-in failure in words a person can act on. */
    public static function explain(string $err, $a): string
    {
        $ms = preg_match('/office365|outlook\.com|hotmail|live\.com|microsoft/i', (string) ($a->imap_host ?? '') . ' ' . (string) ($a->provider ?? ''));
        if ($ms && preg_match('/disables LOGIN|no recognized SASL|AUTHENTICATE failed|LOGIN failed|BasicAuthBlocked/i', $err)) {
            return 'Microsoft 365 no longer accepts a password for email apps (Microsoft switched password sign-in off for IMAP). Open Manage accounts and choose Sign in with Microsoft for this mailbox.';
        }
        return $err;
    }

    /* ── MICROSOFT 365 THROUGH GRAPH (2026-10-20, support ticket #157) ─────────────────
       An account signed in with Microsoft (auth_type 'microsoft') is read through Graph
       instead of IMAP; App\Support\MicrosoftMailbox owns the sign-in and tokens. Every
       method returns the same shapes as its IMAP twin, so the screen does not care which
       one served it. Message and attachment ids are Graph's own strings; they travel as
       ?mid= / &section= rather than in the path. */
    private function graph(callable $fn)
    {
        try {
            return $fn();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() === 404 ? 404 : 422);
        }
    }

    private function graphId(string $id): ?string
    {
        return preg_match('/^[A-Za-z0-9_\-=+\/]{4,600}$/', $id) ? $id : null;
    }

    private function graphFolder(string $folder): string
    {
        if ($folder === '' || strcasecmp($folder, 'INBOX') === 0) return 'inbox';
        if (! $this->graphId($folder)) throw new \RuntimeException('Unknown folder.', 404);
        return rawurlencode($folder);
    }

    private function graphFolders($a): JsonResponse
    {
        $inbox = (string) (MicrosoftMailbox::call($a, 'GET', '/mailFolders/inbox', ['$select' => 'id'])->json('id') ?? '');
        $rows = MicrosoftMailbox::call($a, 'GET', '/mailFolders', ['$top' => 100, '$select' => 'id,displayName,unreadItemCount,totalItemCount'])->json('value') ?? [];
        $out = [];
        foreach ($rows as $f) {
            $isInbox = ($f['id'] ?? '') === $inbox;
            $label = $isInbox ? 'Inbox' : (string) ($f['displayName'] ?? 'Folder');
            $out[] = [
                'key' => $isInbox ? 'INBOX' : (string) $f['id'],
                'label' => $label,
                'unread' => (int) ($f['unreadItemCount'] ?? 0),
                'total' => (int) ($f['totalItemCount'] ?? 0),
                'special' => $this->specialIcon($isInbox ? 'INBOX' : $label),
            ];
        }
        usort($out, fn ($x, $y) => (strcasecmp($x['label'], 'Inbox') === 0 ? -1 : (strcasecmp($y['label'], 'Inbox') === 0 ? 1 : ($x['label'] <=> $y['label']))));
        $this->storeInbox($a, $out);
        return response()->json(['folders' => $out]);
    }

    private function graphMessages($a, string $folder, int $limit, int $skip = 0, string $q = '', bool $unreadOnly = false): JsonResponse
    {
        $sel = 'id,subject,from,toRecipients,receivedDateTime,isRead,flag,hasAttachments,bodyPreview,isDraft';
        if ($q !== '') {
            // Graph's search cannot be combined with $orderby or $skip: newest first is sorted here.
            $query = ['$search' => '"' . str_replace(['"', '\\'], ' ', $q) . '"', '$top' => 50, '$select' => $sel];
        } else {
            $query = ['$top' => $limit + 1, '$skip' => $skip, '$orderby' => 'receivedDateTime desc', '$select' => $sel];
            if ($unreadOnly) $query['$filter'] = 'isRead eq false';
        }
        $rows = MicrosoftMailbox::call($a, 'GET', '/mailFolders/' . $this->graphFolder($folder) . '/messages', $query)->json('value') ?? [];
        $more = false;
        if ($q === '' && count($rows) > $limit) { $more = true; $rows = array_slice($rows, 0, $limit); }
        if ($q !== '') usort($rows, fn ($x, $y) => strcmp((string) ($y['receivedDateTime'] ?? ''), (string) ($x['receivedDateTime'] ?? '')));
        $msgs = [];
        foreach ($rows as $m) {
            $addr = (array) ($m['from']['emailAddress'] ?? []);
            // Sent Items and Drafts read better by who it went TO, as Outlook shows them.
            $toFirst = (array) ($m['toRecipients'][0]['emailAddress'] ?? []);
            $when = (string) ($m['receivedDateTime'] ?? '');
            $msgs[] = [
                'to' => (string) (($toFirst['name'] ?? '') ?: ($toFirst['address'] ?? '')),
                'draft' => ! empty($m['isDraft']),
                'uid' => (string) $m['id'],
                'from' => (string) (($addr['name'] ?? '') ?: ($addr['address'] ?? '')),
                'email' => (string) ($addr['address'] ?? ''),
                'subject' => (string) (($m['subject'] ?? '') ?: '(no subject)'),
                'preview' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($m['bodyPreview'] ?? ''))), 0, 160),
                'date' => $this->prettyDate($when),
                'ts' => $when ? (int) strtotime($when) : 0,
                'unread' => empty($m['isRead']),
                'starred' => (($m['flag']['flagStatus'] ?? '') === 'flagged'),
                'hasAttach' => ! empty($m['hasAttachments']),
            ];
        }
        return response()->json(['messages' => $msgs, 'more' => $more]);
    }

    private function people($list): array
    {
        $out = [];
        foreach ((array) $list as $r) {
            $e = (array) ($r['emailAddress'] ?? []);
            if (! empty($e['address'])) $out[] = ['name' => (string) ($e['name'] ?? ''), 'email' => (string) $e['address']];
        }
        return $out;
    }

    private function graphMessage($a, string $mid, bool $peek = false): JsonResponse
    {
        $p = '/messages/' . rawurlencode($mid);
        $m = MicrosoftMailbox::call($a, 'GET', $p, ['$select' => 'id,subject,from,toRecipients,ccRecipients,bccRecipients,replyTo,receivedDateTime,sentDateTime,body,hasAttachments,isRead,flag',
            '$expand' => "singleValueExtendedProperties(\$filter=id eq 'Integer 0x0E08')"])->json();   // 0x0E08 = message size
        $isHtml = strtolower((string) ($m['body']['contentType'] ?? '')) === 'html';
        $content = (string) ($m['body']['content'] ?? '');
        /* The email as it was sent, not Graph's sanitized copy, which strips CSS such as gradient backgrounds and left
           white text on white (2026-11-05, see App\Support\MimeHtml). Only for messages up to 3 MB: the MIME carries every
           attachment as well. Anything wrong keeps Graph's body. */
        $size = (int) ($m['singleValueExtendedProperties'][0]['value'] ?? 0);
        if ($isHtml && $size > 0 && $size <= 3000000) {
            try {
                $orig = \App\Support\MimeHtml::html((string) MicrosoftMailbox::call($a, 'GET', $p . '/$value')->body());
                if ($orig !== '') $content = $orig;
            } catch (\Throwable $e) {}
        }
        $attachments = [];
        if (! empty($m['hasAttachments']) || ($isHtml && stripos($content, 'cid:') !== false)) {
            $list = MicrosoftMailbox::call($a, 'GET', $p . '/attachments', ['$select' => 'id,name,size,contentType,isInline'])->json('value') ?? [];
            foreach ($list as $att) {
                $type = strtolower((string) ($att['contentType'] ?? ''));
                $name = (string) (($att['name'] ?? '') ?: 'attachment');
                // Pictures placed in the body (signatures, logos) are drawn in place.
                if (! empty($att['isInline']) && $isHtml && str_starts_with($type, 'image/') && (int) ($att['size'] ?? 0) < 3000000) {
                    try {
                        $full = MicrosoftMailbox::call($a, 'GET', $p . '/attachments/' . rawurlencode((string) $att['id']))->json();
                        $cid = (string) ($full['contentId'] ?? '');
                        if ($cid !== '' && ! empty($full['contentBytes']) && stripos($content, 'cid:' . $cid) !== false) {
                            $content = str_ireplace('cid:' . $cid, 'data:' . $type . ';base64,' . $full['contentBytes'], $content);
                            continue;
                        }
                    } catch (\Throwable $e) {}
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $attachments[] = [
                    'name' => $name,
                    'size' => (int) ($att['size'] ?? 0),
                    'type' => $ext ?: (string) substr((string) strrchr($type, '/'), 1),
                    'section' => (string) $att['id'],
                ];
            }
        }
        // peek (2026-11-03): the newest message opened by itself when the screen opens stays unread until it is picked.
        if (empty($m['isRead']) && ! $peek) {
            try { MicrosoftMailbox::call($a, 'PATCH', $p, [], ['isRead' => true]); } catch (\Throwable $e) {}
        }
        $when = (string) (($m['receivedDateTime'] ?? '') ?: ($m['sentDateTime'] ?? ''));
        $from = $this->people([$m['from'] ?? []]);
        return response()->json([
            'uid' => $mid,
            'html' => $isHtml ? $this->sanitize($content) : nl2br(e($content)),
            'is_html' => $isHtml,
            'attachments' => $attachments,
            // The headers a reply / reply all / forward is built from.
            'subject' => (string) ($m['subject'] ?? ''),
            'from' => $from[0] ?? null,
            'to' => $this->people($m['toRecipients'] ?? []),
            'cc' => $this->people($m['ccRecipients'] ?? []),
            'bcc' => $this->people($m['bccRecipients'] ?? []),
            'reply_to' => $this->people($m['replyTo'] ?? []),
            'when' => $when ? \Carbon\Carbon::parse($when)->setTimezone($this->tz ?: config('app.timezone'))->format('D M j, Y g:i A') : '',
            'starred' => (($m['flag']['flagStatus'] ?? '') === 'flagged'),
        ]);
    }

    private function graphAttachment($a, string $mid, string $aid)
    {
        $p = '/messages/' . rawurlencode($mid) . '/attachments/' . rawurlencode($aid);
        $name = (string) (MicrosoftMailbox::call($a, 'GET', $p, ['$select' => 'name'])->json('name') ?: 'attachment');
        $raw = MicrosoftMailbox::call($a, 'GET', $p . '/$value')->body();
        $name = preg_replace('/[\r\n"\\\\\/]+/', '_', $name); // header-injection safe
        return response($raw, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => (string) strlen($raw),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function graphAction($a, string $mid, string $action): JsonResponse
    {
        if ($action === 'delete') {
            // Outlook's Delete: to Deleted Items, never a hard delete (it can be put back from there).
            MicrosoftMailbox::call($a, 'POST', '/messages/' . rawurlencode($mid) . '/move', [], ['destinationId' => 'deleteditems']);
            return response()->json(['ok' => true]);
        }
        $patch = in_array($action, ['star', 'unstar'], true)
            ? ['flag' => ['flagStatus' => $action === 'star' ? 'flagged' : 'notFlagged']]
            : ['isRead' => $action === 'read'];
        MicrosoftMailbox::call($a, 'PATCH', '/messages/' . rawurlencode($mid), [], $patch);
        return response()->json(['ok' => true]);
    }

    /* ── GMAIL THROUGH THE GMAIL API (2026-11-04) ───────────────────────────────────────
       An account signed in with Google (auth_type 'google') is read through the Gmail API; App\Support\GoogleMailbox
       owns the sign-in and tokens. Same shapes as the Graph and IMAP twins. Gmail has labels, not folders: the
       system ones (Inbox, Starred, Sent, Drafts, Spam, Trash) and the person's own labels are the folder list, and a
       folder key is the label id ('INBOX' for the inbox). Message ids never change in Gmail, so a move keeps its id. */
    private const GMAIL_SYSTEM = ['INBOX' => 'Inbox', 'STARRED' => 'Starred', 'IMPORTANT' => 'Important', 'SENT' => 'Sent', 'DRAFT' => 'Drafts', 'SPAM' => 'Spam', 'TRASH' => 'Trash'];

    private function gmailLabel(string $folder): string
    {
        if ($folder === '' || strcasecmp($folder, 'INBOX') === 0) return 'INBOX';
        if (! preg_match('/^[A-Za-z0-9_\-]{1,120}$/', $folder)) throw new \RuntimeException('Unknown folder.', 404);
        return $folder;
    }

    private function gmailId(string $id): ?string
    {
        return preg_match('/^[A-Za-z0-9]{6,64}$/', $id) ? $id : null;
    }

    private function gmailFolders($a): JsonResponse
    {
        $labels = GoogleMailbox::call($a, 'GET', '/labels')->json('labels') ?? [];
        $want = [];
        foreach ($labels as $l) {
            $id = (string) ($l['id'] ?? '');
            if (isset(self::GMAIL_SYSTEM[$id]) || (($l['type'] ?? '') === 'user' && count($want) < 80)) $want[$id] = ['/labels/' . rawurlencode($id), []];
        }
        $full = GoogleMailbox::pool($a, $want);
        $sys = []; $own = [];
        foreach ($want as $id => $_) {
            $l = $full[$id] ?? null;
            if (! $l) continue;
            $label = self::GMAIL_SYSTEM[$id] ?? (string) ($l['name'] ?? 'Label');
            $row = [
                'key' => $id,
                'label' => $label,
                // Starred / Important count what is unread in them, but only the inbox rings the top bar.
                'unread' => (int) ($l['messagesUnread'] ?? 0),
                'total' => (int) ($l['messagesTotal'] ?? 0),
                'special' => $id === 'STARRED' ? '⭐' : ($id === 'IMPORTANT' ? '🏷️' : (isset(self::GMAIL_SYSTEM[$id]) ? $this->specialIcon($label) : '🏷️')),
            ];
            if (isset(self::GMAIL_SYSTEM[$id])) $sys[array_search($id, array_keys(self::GMAIL_SYSTEM), true)] = $row; else $own[] = $row;
        }
        ksort($sys);
        usort($own, fn ($x, $y) => strcasecmp($x['label'], $y['label']));
        $out = array_merge(array_values($sys), $own);
        $this->storeInbox($a, $out);
        return response()->json(['folders' => $out]);
    }

    /** Header lookup on a Gmail payload (names are case-insensitive). */
    private function gmailHeader(array $m, string $name): string
    {
        foreach ((array) ($m['payload']['headers'] ?? []) as $h) {
            if (strcasecmp((string) ($h['name'] ?? ''), $name) === 0) return (string) ($h['value'] ?? '');
        }
        return '';
    }

    /** "Ann <a@x.com>, b@y.com" as [{name, email}]. */
    private function gmailPeople(string $list): array
    {
        $out = [];
        if (trim($list) === '') return $out;
        if (function_exists('imap_rfc822_parse_adrlist')) {
            foreach (@imap_rfc822_parse_adrlist($list, 'localhost') ?: [] as $r) {
                if (empty($r->mailbox) || empty($r->host) || $r->host === '.SYNTAX-ERROR.') continue;
                $out[] = ['name' => isset($r->personal) ? $this->decodeMime((string) $r->personal) : '', 'email' => $r->mailbox . '@' . $r->host];
            }
            imap_errors();
            if ($out) return $out;
        }
        foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $list) as $one) {
            $e = $this->addressPart($one);
            if ($e) $out[] = ['name' => ($n = $this->displayName($one)) !== $e ? $n : '', 'email' => $e];
        }
        return $out;
    }

    private function gmailMessages($a, string $folder, int $limit, int $skip = 0, string $q = '', bool $unreadOnly = false): JsonResponse
    {
        $label = $this->gmailLabel($folder);
        $query = ['labelIds' => $label, 'maxResults' => min(500, $skip + $limit + 1)];
        $search = trim(($unreadOnly ? 'is:unread ' : '') . $q);
        if ($search !== '') $query['q'] = $search;
        if (in_array($label, ['SPAM', 'TRASH'], true)) $query['includeSpamTrash'] = 'true';
        $ids = array_column(GoogleMailbox::call($a, 'GET', '/messages', $query)->json('messages') ?? [], 'id');
        $ids = array_slice($ids, $skip, $limit + 1);
        $more = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);
        $reqs = [];
        foreach ($ids as $id) $reqs[$id] = ['/messages/' . $id, ['format' => 'metadata', 'metadataHeaders' => ['From', 'To', 'Subject', 'Date']]];
        $rows = GoogleMailbox::pool($a, $reqs);
        $msgs = [];
        foreach ($ids as $id) {
            $m = $rows[$id] ?? null;
            if (! $m) continue;
            $labels = (array) ($m['labelIds'] ?? []);
            $from = $this->gmailPeople($this->gmailHeader($m, 'From'))[0] ?? ['name' => '', 'email' => ''];
            $to = $this->gmailPeople($this->gmailHeader($m, 'To'))[0] ?? ['name' => '', 'email' => ''];
            $ts = (int) floor(((int) ($m['internalDate'] ?? 0)) / 1000);
            $msgs[] = [
                'to' => (string) ($to['name'] ?: $to['email']),
                'draft' => in_array('DRAFT', $labels, true),
                'uid' => (string) $m['id'],
                'from' => (string) ($from['name'] ?: $from['email']),
                'email' => (string) $from['email'],
                'subject' => (string) ($this->gmailHeader($m, 'Subject') ?: '(no subject)'),
                'preview' => mb_substr(trim(preg_replace('/\s+/', ' ', html_entity_decode((string) ($m['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 160),
                'date' => $ts ? $this->prettyDate('@' . $ts) : '',
                'ts' => $ts,
                'unread' => in_array('UNREAD', $labels, true),
                'starred' => in_array('STARRED', $labels, true),
                'hasAttach' => stripos((string) ($m['payload']['mimeType'] ?? ''), 'multipart/mixed') === 0,
            ];
        }
        return response()->json(['messages' => $msgs, 'more' => $more]);
    }

    /** Gmail's base64url body data. */
    private function gmailData(?string $d): string
    {
        return $d ? (string) base64_decode(strtr($d, '-_', '+/')) : '';
    }

    private function gmailMessage($a, string $mid, bool $peek = false): JsonResponse
    {
        $m = GoogleMailbox::call($a, 'GET', '/messages/' . $mid, ['format' => 'full'])->json();
        $html = ''; $text = ''; $attachments = []; $inline = [];
        $walk = function ($p) use (&$walk, &$html, &$text, &$attachments, &$inline) {
            $type = strtolower((string) ($p['mimeType'] ?? ''));
            $name = (string) ($p['filename'] ?? '');
            $cid = '';
            foreach ((array) ($p['headers'] ?? []) as $h) {
                if (strcasecmp((string) ($h['name'] ?? ''), 'Content-ID') === 0) $cid = trim((string) ($h['value'] ?? ''), " <>");
            }
            if (! empty($p['parts'])) { foreach ($p['parts'] as $c) $walk($c); return; }
            if ($name !== '' || ! empty($p['body']['attachmentId'])) {
                if ($cid !== '' && str_starts_with($type, 'image/')) $inline[] = ['cid' => $cid, 'type' => $type, 'part' => $p];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $attachments[] = ['name' => $name ?: 'attachment', 'size' => (int) ($p['body']['size'] ?? 0),
                    'type' => $ext ?: (string) substr((string) strrchr($type, '/'), 1), 'section' => (string) ($p['partId'] ?? ''), 'cid' => $cid];
                return;
            }
            if ($type === 'text/html' && $html === '') $html = $this->gmailData($p['body']['data'] ?? null);
            elseif ($type === 'text/plain' && $text === '') $text = $this->gmailData($p['body']['data'] ?? null);
        };
        $walk((array) ($m['payload'] ?? []));
        // Pictures placed in the body (signatures, logos) are drawn in place, and left out of the attachment list.
        foreach ($inline as $in) {
            if ($html === '' || stripos($html, 'cid:' . $in['cid']) === false || (int) ($in['part']['body']['size'] ?? 0) > 3000000) continue;
            try {
                $data = ! empty($in['part']['body']['attachmentId'])
                    ? (string) (GoogleMailbox::call($a, 'GET', '/messages/' . $mid . '/attachments/' . rawurlencode((string) $in['part']['body']['attachmentId']))->json('data') ?? '')
                    : (string) ($in['part']['body']['data'] ?? '');
                if ($data === '') continue;
                $html = str_ireplace('cid:' . $in['cid'], 'data:' . $in['type'] . ';base64,' . base64_encode($this->gmailData($data)), $html);
                $attachments = array_values(array_filter($attachments, fn ($x) => $x['cid'] !== $in['cid']));
            } catch (\Throwable $e) {}
        }
        foreach ($attachments as &$x) unset($x['cid']);
        unset($x);
        $labels = (array) ($m['labelIds'] ?? []);
        if (in_array('UNREAD', $labels, true) && ! $peek) {
            try { GoogleMailbox::call($a, 'POST', '/messages/' . $mid . '/modify', [], ['removeLabelIds' => ['UNREAD']]); } catch (\Throwable $e) {}
        }
        $ts = (int) floor(((int) ($m['internalDate'] ?? 0)) / 1000);
        $isHtml = $html !== '';
        return response()->json([
            'uid' => $mid,
            'html' => $isHtml ? $this->sanitize($html) : nl2br(e($text)),
            'is_html' => $isHtml,
            'attachments' => $attachments,
            'subject' => $this->gmailHeader($m, 'Subject'),
            'from' => $this->gmailPeople($this->gmailHeader($m, 'From'))[0] ?? null,
            'to' => $this->gmailPeople($this->gmailHeader($m, 'To')),
            'cc' => $this->gmailPeople($this->gmailHeader($m, 'Cc')),
            'bcc' => $this->gmailPeople($this->gmailHeader($m, 'Bcc')),
            'reply_to' => $this->gmailPeople($this->gmailHeader($m, 'Reply-To')),
            'when' => $ts ? \Carbon\Carbon::createFromTimestamp($ts, $this->tz ?: config('app.timezone'))->format('D M j, Y g:i A') : '',
            'starred' => in_array('STARRED', $labels, true),
        ]);
    }

    /** section = the part's partId: Gmail hands out a new attachmentId on every read, so it is looked up fresh here. */
    private function gmailAttachment($a, string $mid, string $partId)
    {
        $m = GoogleMailbox::call($a, 'GET', '/messages/' . $mid, ['format' => 'full'])->json();
        $found = null;
        $walk = function ($p) use (&$walk, &$found, $partId) {
            if ($found) return;
            if ((string) ($p['partId'] ?? '') === $partId && empty($p['parts'])) { $found = $p; return; }
            foreach ((array) ($p['parts'] ?? []) as $c) $walk($c);
        };
        $walk((array) ($m['payload'] ?? []));
        if (! $found) return response()->json(['message' => 'Attachment not found'], 404);
        $data = ! empty($found['body']['attachmentId'])
            ? (string) (GoogleMailbox::call($a, 'GET', '/messages/' . $mid . '/attachments/' . rawurlencode((string) $found['body']['attachmentId']))->json('data') ?? '')
            : (string) ($found['body']['data'] ?? '');
        $raw = $this->gmailData($data);
        $name = preg_replace('/[\r\n"\\\\\/]+/', '_', (string) (($found['filename'] ?? '') ?: 'attachment'));
        return response($raw, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => (string) strlen($raw),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function gmailAction($a, string $mid, string $action): JsonResponse
    {
        if ($action === 'delete') {
            // Gmail's Delete: to Trash, which Gmail empties by itself after 30 days.
            GoogleMailbox::call($a, 'POST', '/messages/' . $mid . '/trash');
            return response()->json(['ok' => true]);
        }
        $label = in_array($action, ['star', 'unstar'], true) ? 'STARRED' : 'UNREAD';
        $add = in_array($action, ['star', 'unread'], true);
        GoogleMailbox::call($a, 'POST', '/messages/' . $mid . '/modify', [], [$add ? 'addLabelIds' : 'removeLabelIds' => [$label]]);
        return response()->json(['ok' => true]);
    }

    /** bulk() for a Gmail mailbox: the same answer shape as Graph's. */
    private function gmailBulk(Request $request, $a, string $action, array $ids, string $folder, ?string $dest): JsonResponse
    {
        $done = []; $failed = [];
        $ok = [];
        foreach ($ids as $id) { if ($this->gmailId($id)) $ok[] = $id; else $failed[] = ['id' => $id, 'message' => 'Not a message id this mailbox knows.']; }
        if ($action === 'purge') {
            foreach ($ok as $id) $failed[] = ['id' => $id, 'message' => 'Gmail empties Trash by itself after 30 days.'];
            return response()->json(['ok' => false, 'done' => [], 'failed' => $failed]);
        }
        try {
            if (in_array($action, ['read', 'unread', 'star', 'unstar'], true)) {
                $label = in_array($action, ['star', 'unstar'], true) ? 'STARRED' : 'UNREAD';
                $add = in_array($action, ['star', 'unread'], true);
                foreach (array_chunk($ok, 1000) as $chunk) {
                    GoogleMailbox::call($a, 'POST', '/messages/batchModify', [], ['ids' => $chunk, $add ? 'addLabelIds' : 'removeLabelIds' => [$label]]);
                }
                foreach ($ok as $id) $done[] = ['id' => $id, 'new_id' => $id];
            } else {
                $from = $this->gmailLabel($folder);
                $to = $action === 'delete' ? 'TRASH' : $dest;
                $wk = ['inbox' => 'INBOX', 'archive' => '_ARCHIVE', 'junkemail' => 'SPAM', 'deleteditems' => 'TRASH', 'drafts' => 'DRAFT', 'sentitems' => 'SENT'];
                $to = $wk[strtolower((string) $to)] ?? (strcasecmp((string) $to, 'INBOX') === 0 ? 'INBOX' : $this->gmailLabel((string) $to));
                foreach ($ok as $id) {
                    try {
                        if ($from === 'TRASH' && $to !== 'TRASH') GoogleMailbox::call($a, 'POST', '/messages/' . $id . '/untrash');
                        if ($to === 'TRASH') {
                            GoogleMailbox::call($a, 'POST', '/messages/' . $id . '/trash');
                        } else {
                            $mod = ['addLabelIds' => [], 'removeLabelIds' => []];
                            if ($to !== '_ARCHIVE') $mod['addLabelIds'][] = $to;
                            // Leaving a folder = losing its label (Starred/Important are marks, not places, so they stay).
                            if (! in_array($from, ['TRASH', 'STARRED', 'IMPORTANT', 'SENT', 'DRAFT'], true) && $from !== $to) $mod['removeLabelIds'][] = $from;
                            if ($to === '_ARCHIVE' && ! in_array('INBOX', $mod['removeLabelIds'], true)) $mod['removeLabelIds'][] = 'INBOX';
                            if ($to === 'INBOX' && $from === 'SPAM') $mod['removeLabelIds'] = array_values(array_unique(array_merge($mod['removeLabelIds'], ['SPAM'])));
                            GoogleMailbox::call($a, 'POST', '/messages/' . $id . '/modify', [], $mod);
                        }
                        $done[] = ['id' => $id, 'new_id' => $id];
                    } catch (\RuntimeException $e) {
                        $failed[] = ['id' => $id, 'message' => $e->getMessage()];
                    }
                }
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Gmail could not make that change. Nothing was changed - please try again in a moment.'], 422);
        }
        if ($done) {
            $verb = ['delete' => 'Deleted', 'move' => 'Moved', 'read' => 'Marked read', 'unread' => 'Marked unread', 'star' => 'Starred', 'unstar' => 'Unstarred'][$action] ?? ucfirst($action);
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'email.bulk_' . $action, 'entity_type' => 'email_account', 'entity_id' => (int) $a->id,
                'payload' => json_encode(['summary' => $verb . ' ' . count($done) . ' message' . (count($done) === 1 ? '' : 's') . ' in ' . $a->email_address
                    . ($failed ? '; ' . count($failed) . ' could not be changed' : '')]), 'created_at' => now()]);
        }
        return response()->json(['ok' => ! $failed, 'done' => $done, 'failed' => $failed]);
    }

    /** The message id: Graph's string (?mid=) or the IMAP uid in the path. */
    private function mid(Request $request, string $uid): string
    {
        return (string) ($request->input('mid') ?: $uid);
    }

    private function encodeMailbox(string $folder): string
    {
        // IMAP mailbox names are modified-UTF7; imap functions accept UTF-8 via
        // imap_utf7_encode for ASCII-safe folders this is a no-op. Keep it simple.
        return $folder;
    }

    /** GET /email/folders?account_id= */
    public function folders(Request $request): JsonResponse
    {
        $a = $this->account($request, (int) $request->query('account_id'));
        if (! $a) return response()->json(['message' => 'Account not found'], 404);
        if (MicrosoftMailbox::uses($a)) return $this->graph(fn () => $this->graphFolders($a));
        if (GoogleMailbox::uses($a)) return $this->graph(fn () => $this->gmailFolders($a));
        [$stream, $err] = $this->open($a);
        if (! $stream) return response()->json(['message' => $err], 422);

        $out = [];
        try {
            $list = @imap_list($stream, $this->ref($a), '*') ?: [];
            $ref = $this->ref($a);
            foreach ($list as $box) {
                $name = str_replace($ref, '', $box);
                if ($name === '') continue;
                $short = preg_replace('/^INBOX[\.\/]/i', '', $name);
                $status = @imap_status($stream, $box, SA_UNSEEN | SA_MESSAGES);
                $out[] = [
                    'key' => $name,
                    'label' => $short === '' ? 'Inbox' : $short,
                    'unread' => $status ? (int) $status->unseen : 0,
                    'total' => $status ? (int) $status->messages : 0,
                    'special' => $this->specialIcon($short ?: 'INBOX'),
                ];
            }
            // Ensure INBOX leads.
            usort($out, fn ($x, $y) => (strcasecmp($x['label'], 'Inbox') === 0 ? -1 : ($x['label'] <=> $y['label'])));
        } finally { @imap_close($stream); }
        $this->storeInbox($a, $out);

        return response()->json(['folders' => $out]);
    }

    private function storeInbox(object $a, array $folders): void
    {
        foreach ($folders as $f) {
            if ($f['key'] === 'INBOX' || strcasecmp($f['label'], 'Inbox') === 0) {
                try { \App\Support\MailUnread::store((int) $a->id, (int) $f['unread']); } catch (\Throwable $e) {}
                return;
            }
        }
    }

    private function specialIcon(string $name): string
    {
        $n = strtolower($name);
        if (str_contains($n, 'inbox')) return '📥';
        if (str_contains($n, 'sent')) return '📤';
        if (str_contains($n, 'draft')) return '📝';
        if (str_contains($n, 'trash') || str_contains($n, 'deleted')) return '🗑️';
        if (str_contains($n, 'junk') || str_contains($n, 'spam')) return '🚫';
        if (str_contains($n, 'archive')) return '🗂️';
        return '📁';
    }

    /** GET /email/messages?account_id=&folder=&limit= — recent headers, newest first. */
    public function messages(Request $request): JsonResponse
    {
        $a = $this->account($request, (int) $request->query('account_id'));
        if (! $a) return response()->json(['message' => 'Account not found'], 404);
        $folder = (string) ($request->query('folder') ?: 'INBOX');
        $limit = min(50, max(5, (int) $request->query('limit', 30)));
        $skip = min(5000, max(0, (int) $request->query('skip', 0)));
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        if (MicrosoftMailbox::uses($a)) return $this->graph(fn () => $this->graphMessages($a, $folder, $limit, $skip, $q, $request->boolean('unread')));
        if (GoogleMailbox::uses($a)) return $this->graph(fn () => $this->gmailMessages($a, $folder, $limit, $skip, $q, $request->boolean('unread')));
        [$stream, $err] = $this->open($a, $folder);
        if (! $stream) return response()->json(['message' => $err], 422);

        $msgs = [];
        try {
            $total = imap_num_msg($stream);
            if ($total > 0) {
                $start = max(1, $total - $limit + 1);
                $overview = @imap_fetch_overview($stream, "$start:$total", 0) ?: [];
                // newest first
                usort($overview, fn ($x, $y) => ($y->uid ?? 0) <=> ($x->uid ?? 0));
                foreach ($overview as $o) {
                    $from = $this->decodeMime($o->from ?? '');
                    $msgs[] = [
                        'uid' => (int) ($o->uid ?? 0),
                        'from' => $this->displayName($from),
                        'email' => $this->addressPart($from),
                        'subject' => $this->decodeMime($o->subject ?? '(no subject)'),
                        'preview' => '',
                        'date' => $this->prettyDate($o->date ?? ''),
                        'ts' => isset($o->date) ? strtotime($o->date) : 0,
                        'unread' => empty($o->seen),
                        'starred' => ! empty($o->flagged),
                        'hasAttach' => false,
                    ];
                }
            }
        } finally { @imap_close($stream); }

        return response()->json(['messages' => $msgs]);
    }

    /**
     * The facts App\Support\PersonMail keeps about one message, read from the mailbox itself (never from the browser):
     * key, uid, direction, from, to, subject, preview, attachments, when. Null when the message cannot be read.
     * (2026-11-07, emails on a person's record.)
     */
    public function linkMeta(object $a, string $uid, string $folder = 'INBOX'): ?array
    {
        $this->tz = \App\Support\AgencyTime::tz($a->agency_id ? (int) $a->agency_id : null);
        $own = \App\Support\PersonMail::norm($a->email_address);
        $dirOf = fn (string $from) => ($from !== '' && ($from === $own || $from === \App\Support\PersonMail::norm($a->oauth_identity ?? ''))) ? 'out' : 'in';
        if (MicrosoftMailbox::uses($a)) {
            $mid = $this->graphId($uid);
            if (! $mid) return null;
            $m = MicrosoftMailbox::call($a, 'GET', '/messages/' . rawurlencode($mid), ['$select' => 'id,internetMessageId,from,toRecipients,ccRecipients,bccRecipients,subject,receivedDateTime,sentDateTime,bodyPreview,hasAttachments'])->json();
            if (empty($m['id'])) return null;
            $meta = \App\Support\PersonMail::graphMeta($m, 'in');
            $meta['direction'] = $dirOf($meta['from_addr']);
            return $meta;
        }
        if (GoogleMailbox::uses($a)) {
            $mid = $this->gmailId($uid);
            if (! $mid) return null;
            $m = GoogleMailbox::call($a, 'GET', '/messages/' . $mid, ['format' => 'metadata', 'metadataHeaders' => ['From', 'To', 'Cc', 'Bcc', 'Subject', 'Date', 'Message-ID']])->json();
            if (empty($m['id'])) return null;
            $from = $this->gmailPeople($this->gmailHeader($m, 'From'))[0] ?? ['name' => '', 'email' => ''];
            $to = [];
            foreach (['To', 'Cc', 'Bcc'] as $h) foreach ($this->gmailPeople($this->gmailHeader($m, $h)) as $p) { $x = \App\Support\PersonMail::norm($p['email']); if ($x !== '') $to[$x] = true; }
            $fa = \App\Support\PersonMail::norm($from['email']);
            $ms = (int) ($m['internalDate'] ?? 0);
            $hasAtt = false;
            foreach ((array) ($m['payload']['parts'] ?? []) as $p) if (! empty($p['filename'])) $hasAtt = true;
            return [
                'key' => \App\Support\PersonMail::key($this->gmailHeader($m, 'Message-ID') ?: null, 'gmail:' . $m['id']),
                'uid' => (string) $m['id'], 'direction' => $dirOf($fa), 'from_addr' => $fa, 'from_name' => mb_substr((string) $from['name'], 0, 190),
                'to' => array_keys($to), 'subject' => mb_substr(trim($this->decodeMime($this->gmailHeader($m, 'Subject'))), 0, 250),
                'preview' => mb_substr(trim(html_entity_decode((string) ($m['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5)), 0, 600),
                'has_attachments' => $hasAtt, 'at' => $ms ? \Illuminate\Support\Carbon::createFromTimestampMs($ms)->utc() : now(),
            ];
        }
        $n = (int) $uid;
        if ($n <= 0) return null;
        [$stream, $err] = $this->open($a, $folder ?: 'INBOX');
        if (! $stream) return null;
        try {
            $ov = @imap_fetch_overview($stream, (string) $n, FT_UID) ?: [];
            if (! $ov) return null;
            $o = $ov[0];
            $fromRaw = $this->decodeMime($o->from ?? '');
            $fa = \App\Support\PersonMail::norm($this->addressPart($fromRaw));
            $to = [];
            foreach ($this->gmailPeople($this->decodeMime($o->to ?? '')) as $p) { $x = \App\Support\PersonMail::norm($p['email']); if ($x !== '') $to[$x] = true; }
            $name = $this->displayName($fromRaw);
            return [
                'key' => \App\Support\PersonMail::key($o->message_id ?? null, 'imap:' . $a->id . ':' . $folder . ':' . $n),
                'uid' => ($folder ?: 'INBOX') . '|' . $n, 'direction' => $dirOf($fa), 'from_addr' => $fa,
                'from_name' => mb_substr($name !== $fa ? $name : '', 0, 190), 'to' => array_keys($to),
                'subject' => mb_substr(trim($this->decodeMime($o->subject ?? '')), 0, 250), 'preview' => '',
                'has_attachments' => false, 'at' => ! empty($o->date) && strtotime($o->date) ? \Illuminate\Support\Carbon::createFromTimestamp(strtotime($o->date))->utc() : now(),
            ];
        } finally { @imap_close($stream); }
    }

    /* LINKED EMAILS, OPENED FROM A RECORD (2026-11-09, Anthony: "have a kebab that can pop up the email in a popup to view
       the original contents of the email with all info and another option to download that email, print that email or
       ability to resend that email to someone"). The ✉️ Emails tab on a person or family lists emails that live in
       somebody's mailbox - often not the reader's own - so these read ANY mailbox of the agency. They are only called by
       EmailLinkController, after PersonMail::viewScope has admitted the reader to that link's person or family. Always a
       peek: opening it from a record never marks it read in the owner's inbox. */
    public function linkedMessage(object $a, string $uid): JsonResponse
    {
        $this->tz = \App\Support\AgencyTime::tz($a->agency_id ? (int) $a->agency_id : null);
        if (MicrosoftMailbox::uses($a)) {
            $mid = $this->graphId($uid);
            return $mid ? $this->graph(fn () => $this->graphMessage($a, $mid, true)) : response()->json(['message' => 'This email is no longer in the mailbox.'], 404);
        }
        if (GoogleMailbox::uses($a)) {
            $mid = $this->gmailId($uid);
            return $mid ? $this->graph(fn () => $this->gmailMessage($a, $mid, true)) : response()->json(['message' => 'This email is no longer in the mailbox.'], 404);
        }
        [$folder, $n] = $this->imapUid($uid);
        if (! $n) return response()->json(['message' => 'This email is no longer in the mailbox.'], 404);
        [$stream, $err] = $this->open($a, $folder);
        if (! $stream) return response()->json(['message' => $err], 422);
        try {
            $structure = @imap_fetchstructure($stream, $n, FT_UID);
            if (! $structure) return response()->json(['message' => 'This email is no longer in the mailbox.'], 404);
            $ov = @imap_fetch_overview($stream, (string) $n, FT_UID) ?: [];
            $wasSeen = ! empty($ov[0]->seen);
            [$html, $text, $attachments] = $this->extractBody($stream, $n, $structure);
            if (! $wasSeen) @imap_clearflag_full($stream, (string) $n, '\\Seen', ST_UID);
            return response()->json(['uid' => $uid, 'html' => $html ?: nl2br(e($text)), 'is_html' => (bool) $html, 'attachments' => $attachments,
                'when' => ! empty($ov[0]->date) ? $this->prettyDate((string) $ov[0]->date) : '']);
        } finally { @imap_close($stream); }
    }

    /** One attachment of a linked email, as a download (octet-stream, never run). */
    public function linkedAttachment(object $a, string $uid, string $section)
    {
        if (MicrosoftMailbox::uses($a)) {
            $mid = $this->graphId($uid); $aid = $this->graphId($section);
            return ($mid && $aid) ? $this->graph(fn () => $this->graphAttachment($a, $mid, $aid)) : response()->json(['message' => 'Attachment not found'], 404);
        }
        if (GoogleMailbox::uses($a)) {
            $mid = $this->gmailId($uid);
            return ($mid && preg_match('/^[0-9.]{1,40}$/', $section)) ? $this->graph(fn () => $this->gmailAttachment($a, $mid, $section)) : response()->json(['message' => 'Attachment not found'], 404);
        }
        [$folder, $n] = $this->imapUid($uid);
        if (! $n || ! preg_match('/^[0-9.]{1,40}$/', $section)) return response()->json(['message' => 'Attachment not found'], 404);
        [$stream, $err] = $this->open($a, $folder);
        if (! $stream) return response()->json(['message' => $err], 422);
        try {
            $ov = @imap_fetch_overview($stream, (string) $n, FT_UID) ?: [];
            $wasSeen = ! empty($ov[0]->seen);
            $structure = @imap_fetchstructure($stream, $n, FT_UID);
            $part = $structure ? $this->partAt($structure, $section) : null;
            $raw = $part ? @imap_fetchbody($stream, $n, $section, FT_UID | FT_PEEK) : false;
            if (! $wasSeen) @imap_clearflag_full($stream, (string) $n, '\\Seen', ST_UID);
            if ($raw === false) return response()->json(['message' => 'Attachment not found'], 404);
            $enc = $part->encoding ?? 0;
            if ($enc == 3) $raw = base64_decode($raw);
            elseif ($enc == 4) $raw = quoted_printable_decode($raw);
            $params = [];
            foreach (array_merge($part->parameters ?? [], $part->dparameters ?? []) as $p) $params[strtolower($p->attribute)] = $p->value;
            $name = preg_replace('/[\r\n"\\\\\/]+/', '_', $this->decodeMime($params['filename'] ?? $params['name'] ?? 'attachment'));
            return response($raw, 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="' . $name . '"',
                'Content-Length' => (string) strlen($raw), 'X-Content-Type-Options' => 'nosniff']);
        } finally { @imap_close($stream); }
    }

    /** The whole email exactly as it was sent or received (RFC 822 / .eml), or null when the mailbox will not give it. */
    public function linkedRaw(object $a, string $uid): ?string
    {
        if (MicrosoftMailbox::uses($a)) {
            $mid = $this->graphId($uid);
            return $mid ? (string) MicrosoftMailbox::call($a, 'GET', '/messages/' . rawurlencode($mid) . '/$value')->body() : null;
        }
        if (GoogleMailbox::uses($a)) {
            $mid = $this->gmailId($uid);
            if (! $mid) return null;
            $raw = (string) GoogleMailbox::call($a, 'GET', '/messages/' . $mid, ['format' => 'raw'])->json('raw');
            return $raw !== '' ? (string) base64_decode(strtr($raw, '-_', '+/')) : null;
        }
        [$folder, $n] = $this->imapUid($uid);
        if (! $n) return null;
        [$stream, $err] = $this->open($a, $folder);
        if (! $stream) throw new \RuntimeException($err);
        try {
            $head = @imap_fetchheader($stream, $n, FT_UID);
            $body = @imap_body($stream, $n, FT_UID | FT_PEEK);
            return ($head === false || $body === false) ? null : $head . $body;
        } finally { @imap_close($stream); }
    }

    /** A stored IMAP id is "folder|uid" (PersonMail links); a bare number is the inbox. */
    private function imapUid(string $uid): array
    {
        $i = strrpos($uid, '|');
        $folder = $i === false ? 'INBOX' : (substr($uid, 0, $i) ?: 'INBOX');
        $n = (int) ($i === false ? $uid : substr($uid, $i + 1));
        return [$folder, $n > 0 ? $n : 0];
    }

    /** GET /email/messages/{uid}?account_id=&folder= — full body (HTML preferred). */
    public function message(Request $request, string $uid): JsonResponse
    {
        $a = $this->account($request, (int) $request->query('account_id'));
        if (! $a) return response()->json(['message' => 'Account not found'], 404);
        if (MicrosoftMailbox::uses($a)) {
            $mid = $this->graphId($this->mid($request, $uid));
            $peek = $request->boolean('peek');
            if ($mid && ! $peek) $this->recount($a);
            return $mid ? $this->graph(fn () => $this->graphMessage($a, $mid, $peek)) : response()->json(['message' => 'Message not found'], 404);
        }
        if (GoogleMailbox::uses($a)) {
            $mid = $this->gmailId($this->mid($request, $uid));
            $peek = $request->boolean('peek');
            if ($mid && ! $peek) $this->recount($a);
            return $mid ? $this->graph(fn () => $this->gmailMessage($a, $mid, $peek)) : response()->json(['message' => 'Message not found'], 404);
        }
        $uid = (int) $uid;
        $folder = (string) ($request->query('folder') ?: 'INBOX');
        [$stream, $err] = $this->open($a, $folder);
        if (! $stream) return response()->json(['message' => $err], 422);

        try {
            $structure = @imap_fetchstructure($stream, $uid, FT_UID);
            if (! $structure) return response()->json(['message' => 'Message not found'], 404);
            $peek = $request->boolean('peek');
            $ov = $peek ? (@imap_fetch_overview($stream, (string) $uid, FT_UID) ?: []) : [];
            $wasSeen = ! empty($ov[0]->seen);
            [$html, $text, $attachments] = $this->extractBody($stream, $uid, $structure);
            $body = $html ?: nl2br(e($text));
            // Mark as read (best-effort) - or, for a peek, put "unread" back if reading the body set it.
            if (! $peek) { @imap_setflag_full($stream, (string) $uid, '\\Seen', ST_UID); $this->recount($a); }
            elseif (! $wasSeen) @imap_clearflag_full($stream, (string) $uid, '\\Seen', ST_UID);
            return response()->json([
                'uid' => $uid,
                'html' => $body,
                'is_html' => (bool) $html,
                'attachments' => $attachments,
            ]);
        } finally { @imap_close($stream); }
    }

    /** POST /email/messages/{uid}/action — non-destructive IMAP flag toggles only
     *  (star/read). Moves/deletes are intentionally NOT here — they can lose mail. */
    public function action(Request $request, string $uid): JsonResponse
    {
        $a = $this->account($request, (int) $request->input('account_id'));
        if (! $a) return response()->json(['message' => 'Account not found'], 404);
        $folder = (string) ($request->input('folder') ?: 'INBOX');
        $action = (string) $request->input('action');
        if (! in_array($action, ['star', 'unstar', 'read', 'unread', 'delete'], true)) {
            return response()->json(['message' => 'Unsupported action'], 422);
        }
        // Delete = move to Deleted Items, which only the Microsoft (Graph) mailboxes do; IMAP stays flags-only.
        if ($action === 'delete' && ! MicrosoftMailbox::uses($a) && ! GoogleMailbox::uses($a)) {
            return response()->json(['message' => 'Delete works for Microsoft 365 mailboxes. Delete this one in its own mail app.'], 422);
        }
        if (in_array($action, ['read', 'unread', 'delete'], true)) $this->recount($a);
        if (MicrosoftMailbox::uses($a)) {
            $mid = $this->graphId($this->mid($request, $uid));
            return $mid ? $this->graph(fn () => $this->graphAction($a, $mid, $action)) : response()->json(['message' => 'Message not found'], 404);
        }
        if (GoogleMailbox::uses($a)) {
            $mid = $this->gmailId($this->mid($request, $uid));
            return $mid ? $this->graph(fn () => $this->gmailAction($a, $mid, $action)) : response()->json(['message' => 'Message not found'], 404);
        }
        $uid = (int) $uid;
        [$stream, $err] = $this->open($a, $folder);
        if (! $stream) return response()->json(['message' => $err], 422);
        try {
            $flag = in_array($action, ['star', 'unstar'], true) ? '\\Flagged' : '\\Seen';
            if (in_array($action, ['star', 'read'], true)) @imap_setflag_full($stream, (string) $uid, $flag, ST_UID);
            else @imap_clearflag_full($stream, (string) $uid, $flag, ST_UID);
            return response()->json(['ok' => true]);
        } finally { @imap_close($stream); }
    }

    /**
     * POST /email/messages/bulk and /sales/email/messages/bulk (2026-11-03, Anthony: "deleting emails ... doesnt
     * appear to be working and multiple selections is not there"). One call for one message or many:
     *
     *   action  delete  - to Deleted Items (Outlook's Delete; it can be put back), or from Deleted Items for good
     *           purge   - delete for good (only offered inside Deleted Items, after a confirm)
     *           move    - to `dest`: INBOX, a folder id, or a well-known name (archive, junkemail, deleteditems, inbox)
     *           read / unread / star / unstar
     *   ids[]   the message ids (Graph ids; IMAP uids for IMAP accounts - flags only there)
     *
     * Microsoft mailboxes go through Graph $batch, 20 per round trip. The answer lists what worked and what did not,
     * in words: {ok, done: [{id, new_id}], failed: [{id, message}]}. new_id is the id after a move (Graph gives a moved
     * message a new id) - the Undo button moves those back.
     */
    public function bulk(Request $request): JsonResponse
    {
        $d = $request->validate([
            'account_id' => ['required', 'integer'],
            'folder'     => ['nullable', 'string', 'max:600'],
            'action'     => ['required', 'in:delete,purge,move,read,unread,star,unstar'],
            'dest'       => ['nullable', 'string', 'max:600'],
            'ids'        => ['required', 'array', 'min:1', 'max:500'],
            'ids.*'      => ['required', 'string', 'max:600'],
        ]);
        $a = $this->account($request, (int) $d['account_id']);
        if (! $a) return response()->json(['message' => 'That mailbox was not found.'], 404);
        $action = $d['action'];
        if (in_array($action, ['delete', 'purge', 'move', 'read', 'unread'], true)) $this->recount($a);
        $ids = array_values(array_unique(array_map('strval', $d['ids'])));
        if (GoogleMailbox::uses($a)) {
            if ($action === 'move' && trim((string) ($d['dest'] ?? '')) === '') return response()->json(['message' => 'Pick a folder to move to.'], 422);
            try {
                return $this->gmailBulk($request, $a, $action, $ids, (string) ($d['folder'] ?: 'INBOX'), $d['dest'] ?? null);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        if (! MicrosoftMailbox::uses($a)) {
            if (! in_array($action, ['read', 'unread', 'star', 'unstar'], true)) {
                return response()->json(['message' => 'Deleting and moving work for Microsoft 365 mailboxes. Do this one in its own mail app.'], 422);
            }
            [$stream, $err] = $this->open($a, (string) ($d['folder'] ?: 'INBOX'));
            if (! $stream) return response()->json(['message' => $err], 422);
            try {
                $flag = in_array($action, ['star', 'unstar'], true) ? '\\Flagged' : '\\Seen';
                $list = implode(',', array_map('intval', $ids));
                if (in_array($action, ['star', 'read'], true)) @imap_setflag_full($stream, $list, $flag, ST_UID);
                else @imap_clearflag_full($stream, $list, $flag, ST_UID);
            } finally { @imap_close($stream); }
            return response()->json(['ok' => true, 'done' => array_map(fn ($id) => ['id' => $id, 'new_id' => $id], $ids), 'failed' => []]);
        }

        $dest = null;
        if ($action === 'move') {
            $dest = (string) ($d['dest'] ?? '');
            $wk = ['inbox', 'archive', 'junkemail', 'deleteditems', 'drafts', 'sentitems'];
            if ($dest === '' ) return response()->json(['message' => 'Pick a folder to move to.'], 422);
            if (strcasecmp($dest, 'INBOX') === 0) $dest = 'inbox';
            elseif (! in_array(strtolower($dest), $wk, true) && ! $this->graphId($dest)) return response()->json(['message' => 'That folder was not found.'], 422);
        }
        $req = [];
        foreach ($ids as $id) {
            if (! $this->graphId($id)) continue;
            $path = '/messages/' . rawurlencode($id);
            if ($action === 'delete') $req[$id] = ['method' => 'POST', 'path' => $path . '/move', 'body' => ['destinationId' => 'deleteditems']];
            elseif ($action === 'move') $req[$id] = ['method' => 'POST', 'path' => $path . '/move', 'body' => ['destinationId' => $dest]];
            elseif ($action === 'purge') $req[$id] = ['method' => 'DELETE', 'path' => $path];
            elseif (in_array($action, ['star', 'unstar'], true)) $req[$id] = ['method' => 'PATCH', 'path' => $path, 'body' => ['flag' => ['flagStatus' => $action === 'star' ? 'flagged' : 'notFlagged']]];
            else $req[$id] = ['method' => 'PATCH', 'path' => $path, 'body' => ['isRead' => $action === 'read']];
        }
        $done = []; $failed = [];
        foreach ($ids as $id) if (! isset($req[$id])) $failed[] = ['id' => $id, 'message' => 'Not a message id this mailbox knows.'];
        try {
            $res = $req ? MicrosoftMailbox::batch($a, $req) : [];
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getCode() === 403
                ? 'Microsoft would not let KiddieTrac change ' . $a->email_address . '. Reconnect it from Manage accounts.'
                : 'Microsoft could not be reached. Nothing was changed - please try again in a moment.'], 422);
        }
        foreach ($res as $id => $r) {
            $st = (int) $r['status'];
            if ($st >= 200 && $st < 300) { $done[] = ['id' => $id, 'new_id' => (string) ($r['body']['id'] ?? $id)]; continue; }
            $failed[] = ['id' => $id, 'message' => $st === 404 ? 'It was already moved or deleted (open the folder again to refresh).'
                : ($st === 403 ? 'Microsoft did not allow this change.'
                : ($st === 429 ? 'Microsoft asked us to slow down - try those again in a minute.'
                : 'Microsoft could not change it right now.'))];
        }
        if ($done) {
            $verb = ['delete' => 'Deleted', 'purge' => 'Permanently deleted', 'move' => 'Moved', 'read' => 'Marked read', 'unread' => 'Marked unread', 'star' => 'Flagged', 'unstar' => 'Unflagged'][$action];
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'email.bulk_' . $action, 'entity_type' => 'email_account', 'entity_id' => (int) $a->id,
                'payload' => json_encode(['summary' => $verb . ' ' . count($done) . ' message' . (count($done) === 1 ? '' : 's') . ' in ' . $a->email_address
                    . ($action === 'move' ? ' (to ' . $dest . ')' : '') . ($failed ? '; ' . count($failed) . ' could not be changed' : '')]), 'created_at' => now()]);
        }
        return response()->json(['ok' => ! $failed, 'done' => $done, 'failed' => $failed]);
    }

    /**
     * POST /sales/email/send (multipart) - new message, reply, reply all or forward from a Sales -> Email mailbox
     * (2026-10-26; To / Cc / Bcc, rich text with the signature, attachments and a From picker 2026-11-02).
     *
     * account_id         the mailbox it goes out FROM (the From picker);
     * source_account_id  the mailbox holding the original, for a reply / forward (defaults to account_id);
     * html               the composer's rich text, signature included (the person saw and could edit it);
     * files[]            new attachments - a forward keeps the original's on its own.
     * Built and sent by App\Support\MailSend (a draft in the mailbox, then sent).
     */
    public function send(Request $request): JsonResponse
    {
        // Any mailbox the caller owns (2026-11-09): the sales team's from Sales -> Email, a person's own from Email.
        // $this->account() keeps each to its own screen (SalesMailbox::owner).
        $d = $request->validate([
            'account_id'        => ['required', 'integer'],
            'source_account_id' => ['nullable', 'integer'],
            'mode'              => ['required', 'in:reply,reply_all,forward,new'],
            'mid'               => ['nullable', 'string', 'max:600'],
            'to'                => ['nullable', 'array', 'max:100'], 'to.*' => ['email', 'max:190'],
            'cc'                => ['nullable', 'array', 'max:100'], 'cc.*' => ['email', 'max:190'],
            'bcc'               => ['nullable', 'array', 'max:100'], 'bcc.*' => ['email', 'max:190'],
            'subject'           => ['nullable', 'string', 'max:250'],
            'html'              => ['nullable', 'string', 'max:400000'],
            'body'              => ['nullable', 'string', 'max:100000'],
            'files'             => ['nullable', 'array', 'max:' . \App\Support\MailSend::MAX_FILES],
            'files.*'           => ['file', 'max:' . (int) (\App\Support\MailSend::MAX_TOTAL / 1024)],
        ]);
        $a = $this->account($request, (int) $d['account_id']);
        if (! $a) return response()->json(['message' => 'Mailbox not found'], 404);
        $gmail = GoogleMailbox::uses($a);
        if (! MicrosoftMailbox::uses($a) && ! $gmail) return response()->json(['message' => 'Sending works from mailboxes signed in with Microsoft or Google. This one can be read here; answer it from its own mail app.'], 422);
        if (! empty($a->auth_failed_at)) return response()->json(['message' => $a->email_address . ' needs to be reconnected before it can send.'], 422);
        $src = $a;
        if (! empty($d['source_account_id']) && (int) $d['source_account_id'] !== (int) $a->id) {
            $src = $this->account($request, (int) $d['source_account_id']);
            // A Gmail reply carries Gmail's own thread; answering another mailbox's email from Gmail (or into Microsoft
            // from Gmail) is not offered: reply from the mailbox it arrived in.
            if ($src && $d['mode'] !== 'new' && ($gmail || GoogleMailbox::uses($src))) {
                return response()->json(['message' => 'Reply from ' . $src->email_address . ', the mailbox this email arrived in.'], 422);
            }
            if (! $src || ! MicrosoftMailbox::uses($src)) return response()->json(['message' => 'The original message\'s mailbox was not found.'], 404);
        }

        // The body: the composer's rich text (cleaned), or the older plain-text box plus the account's signature.
        if (isset($d['html']) && trim((string) $d['html']) !== '') {
            $html = \App\Support\MailHtml::clean($d['html']);
        } else {
            $html = nl2br(e((string) ($d['body'] ?? '')));
            if (! empty($a->signature_enabled) && ! empty($a->signature_html)) $html .= '<br>' . \App\Support\MailHtml::clean($a->signature_html);
        }
        $files = [];
        $total = 0;
        foreach ((array) $request->file('files', []) as $f) {
            if (! $f || ! $f->isValid()) return response()->json(['message' => 'An attachment did not upload. Please attach it again.'], 422);
            $total += (int) $f->getSize();
            $name = preg_replace('/[\x00-\x1F\/\\\\:*?"<>|]+/', '_', (string) $f->getClientOriginalName()) ?: 'attachment';
            $files[] = ['name' => mb_substr($name, 0, 200), 'type' => (string) ($f->getClientMimeType() ?: 'application/octet-stream'), 'path' => $f->getRealPath(), 'size' => (int) $f->getSize()];
        }
        if ($total > \App\Support\MailSend::MAX_TOTAL) return response()->json(['message' => 'Attachments come to ' . round($total / 1048576, 1) . ' MB. The limit is 25 MB per email.'], 422);

        $to = $d['to'] ?? []; $cc = $d['cc'] ?? []; $bcc = $d['bcc'] ?? [];
        if (! $to && ! $cc && ! $bcc && in_array($d['mode'], ['new', 'forward'], true)) return response()->json(['message' => 'Add at least one recipient.'], 422);
        $subject = trim((string) ($d['subject'] ?? ''));
        if ($d['mode'] === 'new' && $subject === '') return response()->json(['message' => 'Add a subject.'], 422);
        if (\App\Support\MailHtml::text($html) === '' && ! $files && $d['mode'] !== 'forward') return response()->json(['message' => 'Write a message first.'], 422);
        $mid = null;
        if ($d['mode'] !== 'new') {
            $mid = $gmail ? $this->gmailId((string) ($d['mid'] ?? '')) : $this->graphId((string) ($d['mid'] ?? ''));
            if (! $mid) return response()->json(['message' => 'Message not found'], 404);
        }

        try {
            if ($gmail) {
                // The original as the reader saw it (cleaned, pictures in place), quoted under the reply.
                $quote = $mid ? (string) ($this->gmailMessage($a, $mid, true)->getData(true)['html'] ?? '') : '';
                \App\Support\GmailSend::send($a, $d['mode'], $mid, $quote, $to, $cc, $bcc, $subject, $html, $files);
            } else {
                \App\Support\MailSend::send($a, $d['mode'], $src, $mid, $to, $cc, $bcc, $subject, $html, $files);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $all = array_merge($to, $cc, $bcc);
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $a->agency_id ? (int) $a->agency_id : null,
            'action' => \App\Support\SalesMailbox::on($request) ? 'sales.email_sent' : 'email.sent', 'entity_type' => 'email_account', 'entity_id' => (int) $a->id,
            'payload' => json_encode(['summary' => ucfirst(str_replace('_', ' ', $d['mode'])) . ' sent from ' . $a->email_address
                . ' to ' . implode(', ', array_slice($all, 0, 6)) . (count($all) > 6 ? ' and ' . (count($all) - 6) . ' more' : '')
                . ($files ? ' with ' . count($files) . ' attachment' . (count($files) > 1 ? 's' : '') : '')]), 'created_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** GET /email/messages/{uid}/attachment?account_id=&folder=&section= — stream one
     *  attachment as a download. Served as octet-stream so browsers save (never run) it. */
    public function attachment(Request $request, string $uid)
    {
        $a = $this->account($request, (int) $request->query('account_id'));
        if (! $a) return response()->json(['message' => 'Account not found'], 404);
        if (MicrosoftMailbox::uses($a)) {
            $mid = $this->graphId($this->mid($request, $uid));
            $aid = $this->graphId((string) $request->query('section', ''));
            return ($mid && $aid) ? $this->graph(fn () => $this->graphAttachment($a, $mid, $aid)) : response()->json(['message' => 'Attachment not found'], 404);
        }
        if (GoogleMailbox::uses($a)) {
            $mid = $this->gmailId($this->mid($request, $uid));
            $part = (string) $request->query('section', '');
            return ($mid && preg_match('/^[0-9.]{1,40}$/', $part)) ? $this->graph(fn () => $this->gmailAttachment($a, $mid, $part)) : response()->json(['message' => 'Attachment not found'], 404);
        }
        $uid = (int) $uid;
        $folder = (string) ($request->query('folder') ?: 'INBOX');
        $section = (string) $request->query('section', '');
        if ($section === '' || ! preg_match('/^[0-9.]+$/', $section)) return response()->json(['message' => 'Bad section'], 422);
        [$stream, $err] = $this->open($a, $folder);
        if (! $stream) return response()->json(['message' => $err], 422);

        try {
            $structure = @imap_fetchstructure($stream, $uid, FT_UID);
            $part = $structure ? $this->partAt($structure, $section) : null;
            if (! $part) return response()->json(['message' => 'Attachment not found'], 404);
            $raw = @imap_fetchbody($stream, $uid, $section, FT_UID);
            if ($raw === false) return response()->json(['message' => 'Attachment not found'], 404);
            $enc = $part->encoding ?? 0;
            if ($enc == 3) $raw = base64_decode($raw);
            elseif ($enc == 4) $raw = quoted_printable_decode($raw);

            $params = [];
            foreach (array_merge($part->parameters ?? [], $part->dparameters ?? []) as $p) {
                $params[strtolower($p->attribute)] = $p->value;
            }
            $name = $this->decodeMime($params['filename'] ?? $params['name'] ?? 'attachment');
            $name = preg_replace('/[\r\n"\\\\\/]+/', '_', $name); // header-injection safe

            return response($raw, 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $name . '"',
                'Content-Length' => (string) strlen($raw),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } finally { @imap_close($stream); }
    }

    /** Navigate a MIME structure to a dotted section path (e.g. "2" or "1.2"). */
    private function partAt($structure, string $section)
    {
        if (empty($structure->parts)) {
            return $section === '1' ? $structure : null;
        }
        $cur = $structure;
        foreach (explode('.', $section) as $seg) {
            $i = (int) $seg - 1;
            if ($i < 0 || ! isset($cur->parts[$i])) return null;
            $cur = $cur->parts[$i];
        }
        return $cur;
    }

    /** Walk the MIME structure, returning [html, text, attachments[]]. */
    private function extractBody($stream, int $uid, $structure): array
    {
        $html = ''; $text = ''; $attachments = [];

        $collect = function ($struct, $prefix) use (&$collect, &$html, &$text, &$attachments, $stream, $uid) {
            if (isset($struct->parts) && $struct->parts) {
                foreach ($struct->parts as $i => $part) {
                    $section = $prefix === '' ? (string) ($i + 1) : $prefix . '.' . ($i + 1);
                    $collect($part, $section);
                }
                return;
            }
            $section = $prefix === '' ? '1' : $prefix;
            $raw = @imap_fetchbody($stream, $uid, $section, FT_UID);
            if ($raw === false) return;
            $encoding = $struct->encoding ?? 0;
            if ($encoding == 3) $raw = base64_decode($raw);
            elseif ($encoding == 4) $raw = quoted_printable_decode($raw);

            $params = [];
            foreach (array_merge($struct->parameters ?? [], $struct->dparameters ?? []) as $p) {
                $params[strtolower($p->attribute)] = $p->value;
            }
            $isAttachment = (isset($struct->disposition) && strtolower($struct->disposition) === 'attachment') || isset($params['filename']) || isset($params['name']);
            $subtype = strtolower($struct->subtype ?? '');

            if ($isAttachment) {
                $attachments[] = [
                    'name' => $this->decodeMime($params['filename'] ?? $params['name'] ?? 'attachment'),
                    'size' => (int) ($struct->bytes ?? 0),
                    'type' => $subtype,
                    'section' => $section, // MIME part path, for the download endpoint
                ];
                return;
            }
            $charset = $params['charset'] ?? 'UTF-8';
            $raw = $this->toUtf8($raw, $charset);
            if ($subtype === 'html') $html .= $raw;
            elseif ($subtype === 'plain') $text .= $raw;
        };
        $collect($structure, '');

        // Sanitise HTML defensively before it hits the reading pane.
        if ($html) $html = $this->sanitize($html);
        return [$html, $text, $attachments];
    }

    private function sanitize(string $html): string
    {
        /* Strip scripts/iframes/event handlers. <style> STAYS (2026-11-04, Anthony: "dark emails or backgrounds are not
           showing up correctly"): it carries a newsletter's background and colours, and the reading pane draws the
           message in a sandboxed frame with scripts off, so its styles cannot reach the portal. A refresh or <base>
           would steer that frame, so those go. */
        $html = preg_replace('#<(script|iframe|object|embed)[^>]*>.*?</\\1>#is', '', $html);
        $html = preg_replace('#<(base|meta)\b[^>]*>#i', '', $html);
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\\2#i', '$1="#"', $html);
        return $html;
    }

    private function toUtf8(string $s, string $charset): string
    {
        $charset = strtoupper(trim($charset));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') return $s;
        $conv = @iconv($charset, 'UTF-8//TRANSLIT', $s);
        return $conv !== false ? $conv : $s;
    }

    private function decodeMime(string $s): string
    {
        $out = '';
        foreach (imap_mime_header_decode($s) as $part) {
            $out .= $this->toUtf8($part->text, $part->charset === 'default' ? 'UTF-8' : $part->charset);
        }
        return trim($out) ?: $s;
    }

    private function displayName(string $from): string
    {
        if (preg_match('/^\s*"?([^"<]+?)"?\s*</', $from, $m)) return trim($m[1]);
        return $this->addressPart($from) ?: $from;
    }

    private function addressPart(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) return trim($m[1]);
        if (preg_match('/[^\s@]+@[^\s@]+/', $from, $m)) return trim($m[0]);
        return '';
    }

    private function prettyDate(string $date): string
    {
        $ts = $date ? strtotime($date) : 0;
        if (! $ts) return '';
        $at = \Carbon\Carbon::createFromTimestamp($ts, $this->tz ?: config('app.timezone'));
        $now = \Carbon\Carbon::now($at->getTimezone());
        if ($at->isSameDay($now)) return $at->format('g:i A');
        if ($at->gte($now->copy()->startOfDay()->subDays(6))) return $at->format('D');
        return $at->format('M j');
    }
}
