<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        return DB::table('email_accounts')->where('id', $id)
            ->where('user_id', $request->user()->id)->whereNull('deleted_at')->first();
    }

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
            return [false, $err];
        }
        return [$stream, null];
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

        return response()->json(['folders' => $out]);
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

    /** GET /email/messages/{uid}?account_id=&folder= — full body (HTML preferred). */
    public function message(Request $request, int $uid): JsonResponse
    {
        $a = $this->account($request, (int) $request->query('account_id'));
        if (! $a) return response()->json(['message' => 'Account not found'], 404);
        $folder = (string) ($request->query('folder') ?: 'INBOX');
        [$stream, $err] = $this->open($a, $folder);
        if (! $stream) return response()->json(['message' => $err], 422);

        try {
            $structure = @imap_fetchstructure($stream, $uid, FT_UID);
            if (! $structure) return response()->json(['message' => 'Message not found'], 404);
            [$html, $text, $attachments] = $this->extractBody($stream, $uid, $structure);
            $body = $html ?: nl2br(e($text));
            // Mark as read (best-effort).
            @imap_setflag_full($stream, (string) $uid, '\\Seen', ST_UID);
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
    public function action(Request $request, int $uid): JsonResponse
    {
        $a = $this->account($request, (int) $request->input('account_id'));
        if (! $a) return response()->json(['message' => 'Account not found'], 404);
        $folder = (string) ($request->input('folder') ?: 'INBOX');
        $action = (string) $request->input('action');
        if (! in_array($action, ['star', 'unstar', 'read', 'unread'], true)) {
            return response()->json(['message' => 'Unsupported action'], 422);
        }
        [$stream, $err] = $this->open($a, $folder);
        if (! $stream) return response()->json(['message' => $err], 422);
        try {
            $flag = in_array($action, ['star', 'unstar'], true) ? '\\Flagged' : '\\Seen';
            if (in_array($action, ['star', 'read'], true)) @imap_setflag_full($stream, (string) $uid, $flag, ST_UID);
            else @imap_clearflag_full($stream, (string) $uid, $flag, ST_UID);
            return response()->json(['ok' => true]);
        } finally { @imap_close($stream); }
    }

    /** GET /email/messages/{uid}/attachment?account_id=&folder=&section= — stream one
     *  attachment as a download. Served as octet-stream so browsers save (never run) it. */
    public function attachment(Request $request, int $uid)
    {
        $a = $this->account($request, (int) $request->query('account_id'));
        if (! $a) return response()->json(['message' => 'Account not found'], 404);
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
        // Strip scripts/iframes/event handlers; the reading pane renders this inline.
        $html = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\\1>#is', '', $html);
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
        $today = strtotime('today');
        if ($ts >= $today) return date('g:i A', $ts);
        if ($ts >= strtotime('-6 days')) return date('D', $ts);
        return date('M j', $ts);
    }
}
