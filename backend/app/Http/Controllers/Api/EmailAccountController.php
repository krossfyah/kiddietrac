<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MicrosoftMailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Email Client — connected mail accounts (the Outlook-style setup wizard).
 * Accounts are per-user; secrets are Crypt-encrypted and never returned.
 */
class EmailAccountController extends Controller
{
    /** Known providers → sensible IMAP/SMTP defaults so the wizard can prefill. */
    private function providerDefaults(string $provider): array
    {
        return [
            'microsoft' => ['imap_host' => 'outlook.office365.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls'],
            'google'    => ['imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls'],
        ][$provider] ?? [];
    }

    private function agencyId(Request $request): ?int
    {
        $uid = $request->user()->id;
        $h = (int) $request->header('X-Active-Agency-Id');
        // Honour the header only if the user actually belongs to it; else their own.
        if ($h && DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where('agency_id', $h)->exists()) {
            return $h;
        }
        return DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereNotNull('agency_id')->value('agency_id') ?: null;
    }

    /** Shape a row for the client — NEVER leak the secret. */
    private function present($a): array
    {
        return [
            'id' => $a->id,
            'display_name' => $a->display_name,
            'email_address' => $a->email_address,
            'provider' => $a->provider,
            'imap_host' => $a->imap_host, 'imap_port' => $a->imap_port, 'imap_encryption' => $a->imap_encryption,
            'smtp_host' => $a->smtp_host, 'smtp_port' => $a->smtp_port, 'smtp_encryption' => $a->smtp_encryption,
            'username' => $a->username,
            'has_secret' => ! empty($a->secret),
            'auth_type' => $a->auth_type ?? 'password',
            'oauth_identity' => $a->oauth_identity ?? null,
            'microsoft_signin' => $a->provider === 'microsoft' && MicrosoftMailbox::configured(),
            'google_signin' => $a->provider === 'google' && \App\Support\GoogleMailbox::configured(),
            'is_default' => (bool) $a->is_default,
            'status' => $a->status,
            'status_detail' => $a->status_detail,
            // Microsoft refused the saved sign-in: the screen shows Reconnect (MicrosoftMailbox::authFailed).
            'needs_reconnect' => ! empty($a->auth_failed_at ?? null) || ($a->provider === 'microsoft' && ($a->auth_type ?? 'password') !== 'microsoft')
                // A Google mailbox added for sign-in that never finished it (no app password either) cannot be read yet.
                || ($a->provider === 'google' && ($a->auth_type ?? 'password') !== 'google' && empty($a->secret)),
            // Emails can be linked to people (an agency mailbox, the agency's setting on - App\Support\PersonMail).
            'can_link' => \App\Support\PersonMail::linkable($a),
            /* Who can send (2026-11-09): a Microsoft mailbox signed in with Mail.Send (any organisation), a Gmail
               signed in with Google (gmail.modify covers sending), or a KiddieTrac sales mailbox (the platform app). */
            'can_send' => $send = empty($a->auth_failed_at ?? null) && (
                (\App\Support\SalesMailbox::SCOPE === ($a->shared_scope ?? null) && ($a->auth_type ?? '') === 'microsoft')
                || MicrosoftMailbox::canSend($a) || ($a->auth_type ?? '') === 'google'),
            // Connected before sending existed: one Reconnect adds the permission.
            'send_needs_reconnect' => ! $send && ($a->auth_type ?? '') === 'microsoft' && empty($a->auth_failed_at ?? null),
            'signature_html' => $a->signature_html,
            'signature_enabled' => (bool) $a->signature_enabled,
            'ooo_enabled' => (bool) $a->ooo_enabled,
            'ooo_subject' => $a->ooo_subject,
            'ooo_message' => $a->ooo_message,
            'ooo_start' => $a->ooo_start,
            'ooo_end' => $a->ooo_end,
        ];
    }

    /** GET /email/accounts */
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('email_accounts')->where(\App\Support\SalesMailbox::owner($request))
            ->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('id')->get();
        $mine = DB::table('email_account_alerts')->where('user_id', (int) $request->user()->id)
            ->whereIn('account_id', $rows->pluck('id'))->get()->keyBy('account_id');
        return response()->json(['accounts' => $rows->map(fn ($a) => $this->present($a) + ['alerts' => $this->alertsOf($a, $mine[$a->id] ?? null)])->values()]);
    }

    /**
     * New-mail alerts for this mailbox, for the person asking (2026-11-05, email_account_alerts). Only mailboxes signed
     * in with Microsoft are watched for new mail (kiddietrac:mail-watch), so only they can ring at all.
     */
    private function alertsOf(object $a, ?object $p): array
    {
        $tone = $p && $p->tone && \App\Support\NotificationSounds::isTone((string) $p->tone) ? (string) $p->tone : null;
        return ['supported' => ($a->auth_type ?? '') === 'microsoft', 'on' => $p ? (bool) $p->alerts_on : true, 'tone' => $tone];
    }

    /** PUT /email/accounts/{id}/alerts {on, tone} - this person's alerts and new-mail sound for one mailbox. tone null = the Email tone. */
    public function saveAlerts(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where(\App\Support\SalesMailbox::owner($request))->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);
        $d = $request->validate(['on' => ['required', 'boolean'], 'tone' => ['nullable', 'string', 'max:40']]);
        $tone = trim((string) ($d['tone'] ?? '')) ?: null;
        if ($tone !== null && ! \App\Support\NotificationSounds::isTone($tone)) return response()->json(['message' => 'That sound is not available. Pick another one.'], 422);
        $uid = (int) $request->user()->id;
        DB::table('email_account_alerts')->updateOrInsert(['user_id' => $uid, 'account_id' => (int) $a->id],
            ['alerts_on' => (bool) $d['on'], 'tone' => $tone, 'updated_at' => now(), 'created_at' => now()]);
        $name = $tone ? (\App\Support\NotificationSounds::library()[$tone]['name'] ?? $tone) : 'the Email sound';
        \App\Support\Audit::write(['user_id' => $uid, 'action' => 'email.alerts', 'entity_type' => 'email_account', 'entity_id' => (int) $a->id,
            'payload' => json_encode(['summary' => ($d['on'] ? 'Turned on new-mail alerts (' . $name . ')' : 'Turned off new-mail alerts') . ' for ' . $a->email_address]), 'created_at' => now()]);
        $p = DB::table('email_account_alerts')->where('user_id', $uid)->where('account_id', $a->id)->first();
        return response()->json(['ok' => true, 'alerts' => $this->alertsOf($a, $p)]);
    }

    /** POST /email/accounts — the wizard's finish step. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name'    => ['nullable', 'string', 'max:120'],
            'email_address'   => ['required', 'email', 'max:190'],
            'provider'        => ['required', 'in:microsoft,google,imap,other'],
            'username'        => ['nullable', 'string', 'max:190'],
            'secret'          => ['nullable', 'string', 'max:2000'],
            'imap_host'       => ['nullable', 'string', 'max:190'],
            'imap_port'       => ['nullable', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['nullable', 'in:ssl,tls,none'],
            'smtp_host'       => ['nullable', 'string', 'max:190'],
            'smtp_port'       => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['nullable', 'in:ssl,tls,none'],
            'is_default'      => ['nullable', 'boolean'],
            'signature_html'  => ['nullable', 'string', 'max:400000'], // a pasted logo is an embedded picture (2026-11-03)
        ]);

        /* SEVERAL MAILBOXES (2026-11-02, Anthony: "i need ability to add other accounts such as info@kiddietrac.com and
           etc"). Sales -> Email holds sales@, info@ and any others; each address once, so "add" on one already here
           points at Reconnect instead of making a twin that splits the alerts. */
        $dup = DB::table('email_accounts')->where(\App\Support\SalesMailbox::owner($request))->whereNull('deleted_at')
            ->whereRaw('LOWER(email_address) = ?', [mb_strtolower(trim($data['email_address']))])->first();
        if ($dup) {
            return response()->json(['message' => $data['email_address'] . ' is already added. Use Reconnect on it instead.', 'account' => $this->present($dup)], 422);
        }
        $defaults = $this->providerDefaults($data['provider']);
        $uid = $request->user()->id;
        $first = ! DB::table('email_accounts')->where(\App\Support\SalesMailbox::owner($request))->whereNull('deleted_at')->exists();

        $row = [
            'user_id'         => $uid,
            'agency_id'       => \App\Support\SalesMailbox::on($request) ? null : $this->agencyId($request),
            'shared_scope'    => \App\Support\SalesMailbox::on($request) ? \App\Support\SalesMailbox::SCOPE : null,
            'display_name'    => $data['display_name'] ?? null,
            'email_address'   => $data['email_address'],
            'provider'        => $data['provider'],
            'imap_host'       => $data['imap_host'] ?? ($defaults['imap_host'] ?? null),
            'imap_port'       => $data['imap_port'] ?? ($defaults['imap_port'] ?? null),
            'imap_encryption' => $data['imap_encryption'] ?? ($defaults['imap_encryption'] ?? null),
            'smtp_host'       => $data['smtp_host'] ?? ($defaults['smtp_host'] ?? null),
            'smtp_port'       => $data['smtp_port'] ?? ($defaults['smtp_port'] ?? null),
            'smtp_encryption' => $data['smtp_encryption'] ?? ($defaults['smtp_encryption'] ?? null),
            'username'        => $data['username'] ?? $data['email_address'],
            'secret'          => ! empty($data['secret']) ? Crypt::encryptString($data['secret']) : null,
            'is_default'      => $data['is_default'] ?? $first,   // first account is default
            'status'          => 'pending',
            'signature_html'  => $data['signature_html'] ?? null,
            'created_at'      => now(), 'updated_at' => now(),
        ];
        $id = DB::table('email_accounts')->insertGetId($row);
        if ($row['is_default']) {
            DB::table('email_accounts')->where(\App\Support\SalesMailbox::owner($request))->where('id', '!=', $id)->update(['is_default' => false]);
        }
        $a = DB::table('email_accounts')->find($id);
        return response()->json(['account' => $this->present($a)], 201);
    }

    /** PATCH /email/accounts/{id} — settings, signature, OOO, default. */
    public function update(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where(\App\Support\SalesMailbox::owner($request))
            ->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate([
            'display_name'      => ['sometimes', 'nullable', 'string', 'max:120'],
            'username'          => ['sometimes', 'nullable', 'string', 'max:190'],
            'secret'            => ['sometimes', 'nullable', 'string', 'max:2000'],
            'imap_host'         => ['sometimes', 'nullable', 'string', 'max:190'],
            'imap_port'         => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'imap_encryption'   => ['sometimes', 'nullable', 'in:ssl,tls,none'],
            'smtp_host'         => ['sometimes', 'nullable', 'string', 'max:190'],
            'smtp_port'         => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption'   => ['sometimes', 'nullable', 'in:ssl,tls,none'],
            'is_default'        => ['sometimes', 'boolean'],
            'signature_html'    => ['sometimes', 'nullable', 'string', 'max:400000'], // as a message body: a pasted logo is an embedded picture
            'signature_enabled' => ['sometimes', 'boolean'],
            'ooo_enabled'       => ['sometimes', 'boolean'],
            'ooo_subject'       => ['sometimes', 'nullable', 'string', 'max:190'],
            'ooo_message'       => ['sometimes', 'nullable', 'string', 'max:20000'],
            'ooo_start'         => ['sometimes', 'nullable', 'date'],
            'ooo_end'           => ['sometimes', 'nullable', 'date'],
        ]);

        $upd = [];
        foreach (['display_name', 'username', 'imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption', 'is_default', 'signature_html', 'signature_enabled', 'ooo_enabled', 'ooo_subject', 'ooo_message', 'ooo_start', 'ooo_end'] as $k) {
            if (array_key_exists($k, $data)) $upd[$k] = $data[$k];
        }
        // The signature is rich text now (2026-11-02): saved through the same allowlist the composer's mail goes through.
        if (array_key_exists('signature_html', $upd)) $upd['signature_html'] = \App\Support\MailHtml::clean($upd['signature_html']) ?: null;
        // Only overwrite the secret when a new non-empty one is supplied.
        if (array_key_exists('secret', $data) && ! empty($data['secret'])) {
            $upd['secret'] = Crypt::encryptString($data['secret']);
        }
        $upd['updated_at'] = now();
        DB::table('email_accounts')->where('id', $id)->update($upd);

        if (($data['is_default'] ?? false) === true) {
            DB::table('email_accounts')->where(\App\Support\SalesMailbox::owner($request))->where('id', '!=', $id)->update(['is_default' => false]);
        }
        $a = DB::table('email_accounts')->find($id);
        return response()->json(['account' => $this->present($a)]);
    }

    /** DELETE /email/accounts/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where(\App\Support\SalesMailbox::owner($request))
            ->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);
        DB::table('email_accounts')->where('id', $id)->update(['deleted_at' => now(), 'is_default' => false]);
        // Promote another account to default if we removed the default one.
        if ($a->is_default) {
            $next = DB::table('email_accounts')->where(\App\Support\SalesMailbox::owner($request))->whereNull('deleted_at')->orderBy('id')->first();
            if ($next) DB::table('email_accounts')->where('id', $next->id)->update(['is_default' => true]);
        }
        return response()->json(['ok' => true]);
    }

    /** POST /email/accounts/{id}/microsoft — the Microsoft sign-in page for this mailbox. */
    public function microsoftStart(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where(\App\Support\SalesMailbox::owner($request))
            ->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);
        if ($a->provider !== 'microsoft') return response()->json(['message' => 'Only Microsoft 365 / Outlook mailboxes use Microsoft sign-in.'], 422);
        if (! MicrosoftMailbox::configured()) return response()->json(['message' => 'Microsoft sign-in is not set up on this server. Please contact KiddieTrac support.'], 422);
        return response()->json(['url' => MicrosoftMailbox::authorizeUrl($a, (int) $request->user()->id, \App\Support\SalesMailbox::on($request) ? 'sales-email' : 'email')]);
    }

    /**
     * GET /email/accounts/{id}/auto-replies (2026-11-03, Anthony: "can mail client read out of office settings already
     * set previously?"). The mailbox's REAL automatic replies, read from Microsoft 365 (mailboxSettings), so what was
     * set in Outlook shows here - the old out-of-office fields on this screen were only stored in KiddieTrac and never
     * reached the mailbox. Times are in the agency's timezone.
     */
    public function autoReplies(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where(\App\Support\SalesMailbox::owner($request))->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);
        return response()->json($this->autoState($a));
    }

    /** PUT /email/accounts/{id}/auto-replies — save them back to the mailbox (Microsoft 365 only). */
    public function saveAutoReplies(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where(\App\Support\SalesMailbox::owner($request))->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);
        $d = $request->validate([
            'status'           => ['required', 'in:disabled,alwaysEnabled,scheduled'],
            'audience'         => ['nullable', 'in:none,contactsOnly,all'],
            'start'            => ['nullable', 'date'],
            'end'              => ['nullable', 'date'],
            'internal_message' => ['nullable', 'string', 'max:20000'],
            'external_message' => ['nullable', 'string', 'max:20000'],
        ]);
        $st = $this->autoState($a);
        if (empty($st['can_edit'])) return response()->json(['message' => $st['message'] ?? 'Automatic replies cannot be changed for this mailbox here.'], 422);
        $tz = \App\Support\AgencyTime::tz();
        $set = ['status' => $d['status']];
        if (! empty($d['audience'])) $set['externalAudience'] = $d['audience'];
        if ($d['status'] === 'scheduled') {
            if (empty($d['start']) || empty($d['end'])) return response()->json(['message' => 'Pick when the automatic replies start and end.'], 422);
            $from = \Carbon\Carbon::parse($d['start'], $tz)->utc();
            $to = \Carbon\Carbon::parse($d['end'], $tz)->utc();
            if ($to->lte($from)) return response()->json(['message' => 'The end has to be after the start.'], 422);
            $set['scheduledStartDateTime'] = ['dateTime' => $from->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'];
            $set['scheduledEndDateTime'] = ['dateTime' => $to->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'];
        }
        // A message is only sent when it was changed here, so formatting set in Outlook survives a save that only
        // turns replies on or off. Typed text is sent as simple HTML, a line per line.
        foreach (['internal_message' => 'internalReplyMessage', 'external_message' => 'externalReplyMessage'] as $k => $g) {
            if ($request->has($k)) $set[$g] = $this->textToHtml((string) ($d[$k] ?? ''));
        }
        try {
            MicrosoftMailbox::call($a, 'PATCH', '/mailboxSettings', [], ['automaticRepliesSetting' => $set]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getCode() === 403
                ? 'Microsoft would not let KiddieTrac change the automatic replies for ' . $a->email_address . '. Reconnect it to allow this.'
                : 'Microsoft could not be reached, so nothing was changed. Please try again in a moment.'], 422);
        }
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'email.auto_replies', 'entity_type' => 'email_account', 'entity_id' => (int) $a->id,
            'payload' => json_encode(['summary' => ['disabled' => 'Turned off', 'alwaysEnabled' => 'Turned on', 'scheduled' => 'Scheduled'][$d['status']] . ' automatic replies for ' . $a->email_address]), 'created_at' => now()]);
        return response()->json($this->autoState($a));
    }

    /** The automatic replies as the screen shows them, plus whether they can be read / changed and why not. */
    private function autoState(object $a): array
    {
        $out = ['supported' => false, 'can_edit' => false, 'needs_consent' => false, 'tz' => \App\Support\AgencyTime::tz()];
        if (! MicrosoftMailbox::uses($a)) {
            return $out + ['message' => 'KiddieTrac can only read automatic replies from Microsoft 365 mailboxes. Set them for '
                . $a->email_address . ' in its own mail app (in Gmail: Settings, Vacation responder).'];
        }
        $out['supported'] = true;
        if (! empty($a->auth_failed_at ?? null)) return $out + ['message' => $a->email_address . ' needs to sign in again first (Reconnect).'];
        $consent = $a->email_address . ' was connected before KiddieTrac could read automatic replies. Choose Reconnect and sign in with Microsoft as '
            . ($a->oauth_identity ?: $a->email_address) . '; Microsoft will ask you to allow "Read and write user mailbox settings". Your mail and sign-in stay as they are.';
        try {
            if (MicrosoftMailbox::canSettings($a) === false) return array_merge($out, ['needs_consent' => true, 'message' => $consent]);
            $r = (array) (MicrosoftMailbox::call($a, 'GET', '/mailboxSettings/automaticRepliesSetting')->json() ?? []);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 403) return array_merge($out, ['needs_consent' => true, 'message' => $consent]);
            return $out + ['message' => 'Microsoft could not be reached to read the automatic replies. Please try again in a moment.'];
        }
        $tz = $out['tz'];
        $when = function ($x) use ($tz) {
            $dt = (string) ($x['dateTime'] ?? ''); if ($dt === '') return null;
            $zone = (string) ($x['timeZone'] ?? 'UTC');
            if (! in_array($zone, timezone_identifiers_list(), true) && $zone !== 'UTC') {
                $iana = class_exists('IntlTimeZone') ? \IntlTimeZone::getIDForWindowsID($zone) : null;
                $zone = $iana ?: 'UTC';
            }
            try { return \Carbon\Carbon::parse($dt, $zone)->setTimezone($tz); } catch (\Throwable $e) { return null; }
        };
        $from = $when($r['scheduledStartDateTime'] ?? []); $to = $when($r['scheduledEndDateTime'] ?? []);
        $status = (string) ($r['status'] ?? 'disabled');
        $now = now()->setTimezone($tz);
        $active = $status === 'alwaysEnabled' || ($status === 'scheduled' && $from && $to && $now->between($from, $to));
        // The account list's "Out of office" badge follows the mailbox.
        try { DB::table('email_accounts')->where('id', $a->id)->update(['ooo_enabled' => $active ? 1 : 0]); } catch (\Throwable $e) {}
        return array_merge($out, [
            'can_edit' => true,
            'status' => $status,
            'active' => $active,
            'audience' => (string) ($r['externalAudience'] ?? 'all'),
            'start' => $from ? $from->format('Y-m-d\TH:i') : null,
            'end' => $to ? $to->format('Y-m-d\TH:i') : null,
            'start_label' => $from ? $from->format('D M j, Y g:i A') : null,
            'end_label' => $to ? $to->format('D M j, Y g:i A') : null,
            'internal_message' => $this->htmlToText((string) ($r['internalReplyMessage'] ?? '')),
            'external_message' => $this->htmlToText((string) ($r['externalReplyMessage'] ?? '')),
        ]);
    }

    private function htmlToText(string $h): string
    {
        if ($h === '') return '';
        $h = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1>~is', '', $h);
        $h = preg_replace('~<br\s*/?>~i', "\n", $h);
        $h = preg_replace('~</(p|div|li|tr|h[1-6])>~i', "\n", $h);
        $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);
        $t = preg_replace("/[ \t]+\n/", "\n", $t);
        return trim(preg_replace("/\n{3,}/", "\n\n", $t));
    }

    private function textToHtml(string $t): string
    {
        $t = trim(str_replace("\r\n", "\n", $t));
        if ($t === '') return '';
        return '<html><body><div style="font-family:Calibri,Arial,sans-serif;font-size:11pt">' . nl2br(e($t), false) . '</div></body></html>';
    }

    /** POST /email/accounts/{id}/google — the Google sign-in page for this mailbox (2026-11-04). */
    public function googleStart(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where(\App\Support\SalesMailbox::owner($request))
            ->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);
        if ($a->provider !== 'google') return response()->json(['message' => 'Only Gmail / Google Workspace mailboxes use Google sign-in.'], 422);
        if (! \App\Support\GoogleMailbox::configured()) return response()->json(['message' => 'Google sign-in is not set up on this server. Please contact KiddieTrac support.'], 422);
        return response()->json(['url' => \App\Support\GoogleMailbox::authorizeUrl($a, (int) $request->user()->id, \App\Support\SalesMailbox::on($request) ? 'sales-email' : 'email')]);
    }

    /**
     * POST /email/discover {email} (2026-11-04, Anthony: "add mailboxes like how outlook does it without any secret
     * keys"). Outlook asks for the address and works the rest out; so does this:
     *   microsoft - outlook.com / hotmail / live, or the domain's mail is handled by Microsoft 365 (MX)
     *   google    - gmail.com, or the domain's mail is handled by Google Workspace (MX)
     *   imap      - anything else, with the server settings from the domain's own autoconfig or Thunderbird's
     *               provider list (what Outlook and Thunderbird use), else the usual imap.<domain> guess.
     * Read-only lookups; nothing is saved.
     */
    public function discover(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $email = mb_strtolower(trim($d['email']));
        $domain = substr(strrchr($email, '@'), 1);
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) return response()->json(['message' => 'That email address does not look right.'], 422);
        $kind = null;
        if (preg_match('/^(gmail|googlemail)\.com$/', $domain)) $kind = 'google';
        elseif (preg_match('/^(outlook|hotmail|live|msn)\.[a-z.]+$/', $domain)) $kind = 'microsoft';
        else {
            $mx = [];
            try { $mx = \Illuminate\Support\Facades\Cache::remember('kt_mx:' . $domain, 3600, fn () => array_map(fn ($r) => strtolower((string) ($r['target'] ?? '')), @dns_get_record($domain, DNS_MX) ?: [])); } catch (\Throwable $e) {}
            foreach ($mx as $t) {
                if (preg_match('/(^|\.)(google\.com|googlemail\.com)$/', $t)) { $kind = 'google'; break; }
                if (preg_match('/(\.mail\.protection\.outlook\.com|\.olc\.protection\.outlook\.com|(^|\.)outlook\.com)$/', $t)) { $kind = 'microsoft'; break; }
            }
        }
        if ($kind === 'microsoft') return response()->json(['provider' => 'microsoft', 'signin' => MicrosoftMailbox::configured(), 'label' => 'Microsoft 365 / Outlook']);
        if ($kind === 'google') return response()->json(['provider' => 'google', 'signin' => \App\Support\GoogleMailbox::configured(), 'label' => 'Google Workspace / Gmail']);
        return response()->json(['provider' => 'imap', 'signin' => false, 'label' => 'Other mail provider', 'imap' => $this->autoconfig($domain, $email)]);
    }

    /** IMAP server settings for a domain: its own autoconfig file, Thunderbird's provider list, then a guess. */
    private function autoconfig(string $domain, string $email): array
    {
        // Cached per DOMAIN, so the cached answer carries the username rule, never someone's address.
        $c = \Illuminate\Support\Facades\Cache::remember('kt_autoconf2:' . $domain, 3600, function () use ($domain, $email) {
            foreach (['https://autoconfig.' . $domain . '/mail/config-v1.1.xml?emailaddress=' . rawurlencode($email), 'https://autoconfig.thunderbird.net/v1.1/' . rawurlencode($domain)] as $url) {
                try {
                    $r = \Illuminate\Support\Facades\Http::timeout(5)->connectTimeout(4)->get($url);
                    if (! $r->successful() || stripos($r->body(), '<incomingServer') === false) continue;
                    $prev = libxml_use_internal_errors(true);
                    $x = simplexml_load_string($r->body(), 'SimpleXMLElement', LIBXML_NONET);
                    libxml_use_internal_errors($prev);
                    if (! $x) continue;
                    foreach ($x->xpath('//incomingServer[@type="imap"]') ?: [] as $in) {
                        $host = strtolower(trim((string) $in->hostname));
                        if (! preg_match('/^[a-z0-9.-]+$/', $host)) continue;
                        $sock = strtoupper(trim((string) $in->socketType));
                        $user = trim((string) $in->username);
                        return ['host' => $host, 'port' => (int) $in->port ?: 993, 'encryption' => $sock === 'SSL' ? 'ssl' : ($sock === 'STARTTLS' ? 'tls' : 'none'),
                            'local' => $user === '%EMAILLOCALPART%', 'found' => true];
                    }
                } catch (\Throwable $e) {}
            }
            return ['host' => 'imap.' . $domain, 'port' => 993, 'encryption' => 'ssl', 'local' => false, 'found' => false];
        });
        $c['username'] = ! empty($c['local']) ? strstr($email, '@', true) : $email;
        unset($c['local']);
        return $c;
    }

    /** POST /email/microsoft/finish {nonce} — save the sign-in, for the user who started it. Google's too ("g" nonces). */
    public function microsoftFinish(Request $request): JsonResponse
    {
        $nonce = (string) $request->input('nonce', '');
        if (! preg_match('/^g?[a-f0-9]{40}$/', $nonce)) return response()->json(['message' => 'This sign-in link is not valid.'], 422);
        [$ok, $msg, $accountId] = $nonce[0] === 'g'
            ? \App\Support\GoogleMailbox::finish((int) $request->user()->id, $nonce)
            : MicrosoftMailbox::finish((int) $request->user()->id, $nonce);
        if (! $ok) {
            if ($accountId) {
                DB::table('email_accounts')->where('id', $accountId)->update(['status' => 'error', 'status_detail' => mb_substr($msg, 0, 480), 'updated_at' => now()]);
            }
            return response()->json(['message' => $msg, 'account_id' => $accountId], 422);
        }
        $a = DB::table('email_accounts')->find($accountId);
        return response()->json(['ok' => true, 'account' => $this->present($a)]);
    }

    /**
     * POST /email/accounts/{id}/test — reachability check of the configured IMAP +
     * SMTP hosts (a real TCP connect, not a claim of authenticated success). Updates
     * status so the account list can show a live badge.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $a = DB::table('email_accounts')->where('id', $id)->where(\App\Support\SalesMailbox::owner($request))
            ->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Not found'], 404);

        $checks = [];

        // Signed in with Google: the test is opening the mailbox through the Gmail API.
        if (\App\Support\GoogleMailbox::uses($a)) {
            try {
                \App\Support\GoogleMailbox::call($a, 'GET', '/profile');
                $checks['Google'] = 'signed in as ' . ($a->oauth_identity ?: $a->email_address);
                $ok = true;
            } catch (\RuntimeException $e) {
                $checks['Google'] = $e->getMessage();
                $ok = false;
            }
            DB::table('email_accounts')->where('id', $id)->update([
                'status' => $ok ? 'connected' : 'error',
                'status_detail' => collect($checks)->map(fn ($v, $k) => "$k: $v")->implode(' · '),
                'updated_at' => now(),
            ]);
            return response()->json(['ok' => $ok, 'checks' => $checks]);
        }

        // Signed in with Microsoft: the test is opening the inbox through Graph.
        if (MicrosoftMailbox::uses($a)) {
            try {
                MicrosoftMailbox::call($a, 'GET', '/mailFolders/inbox', ['$select' => 'id']);
                $checks['Microsoft'] = 'signed in as ' . ($a->oauth_identity ?: $a->email_address);
                $ok = true;
            } catch (\RuntimeException $e) {
                $checks['Microsoft'] = $e->getMessage();
                $ok = false;
            }
            DB::table('email_accounts')->where('id', $id)->update([
                'status' => $ok ? 'connected' : 'error',
                'status_detail' => collect($checks)->map(fn ($v, $k) => "$k: $v")->implode(' · '),
                'updated_at' => now(),
            ]);
            return response()->json(['ok' => $ok, 'checks' => $checks]);
        }

        // IMAP: a REAL sign-in test (imap_open) when the extension is available —
        // this validates the saved password, not just server reachability.
        if ($a->imap_host && $a->imap_port && function_exists('imap_open')) {
            $secret = '';
            try { $secret = $a->secret ? Crypt::decryptString($a->secret) : ''; } catch (\Throwable $e) {}
            if ($secret === '') {
                $checks['IMAP'] = 'no password saved';
            } else {
                $enc = $a->imap_encryption === 'ssl' ? '/imap/ssl/novalidate-cert'
                    : ($a->imap_encryption === 'tls' ? '/imap/tls/novalidate-cert' : '/imap/notls');
                imap_timeout(IMAP_OPENTIMEOUT, 10);
                imap_errors();
                $s = @imap_open('{' . $a->imap_host . ':' . ((int) $a->imap_port) . $enc . '}INBOX', $a->username ?: $a->email_address, $secret, 0, 1);
                if ($s) { $checks['IMAP'] = 'signed in'; @imap_close($s); }
                else {
                    $why = imap_last_error() ?: 'check password / app password';
                    imap_errors(); imap_alerts();   // drain, or PHP raises them at shutdown (ticket #157)
                    $checks['IMAP'] = 'sign-in failed (' . \App\Http\Controllers\Api\EmailMailController::explain($why, $a) . ')';
                }
            }
        } else {
            // Fallback: plain TCP reachability.
            $errno = 0; $errstr = '';
            $conn = $a->imap_host ? @fsockopen($a->imap_encryption === 'ssl' ? "ssl://{$a->imap_host}" : $a->imap_host, (int) $a->imap_port ?: 143, $errno, $errstr, 6) : false;
            $checks['IMAP'] = $conn ? (fclose($conn) ? 'reachable' : 'reachable') : ('unreachable (' . ($errstr ?: 'no route') . ')');
        }

        // SMTP: TCP reachability (a full auth test needs a send attempt).
        if ($a->smtp_host && $a->smtp_port) {
            $errno = 0; $errstr = '';
            $conn = @fsockopen($a->smtp_host, (int) $a->smtp_port, $errno, $errstr, 6);
            $checks['SMTP'] = $conn ? (fclose($conn) ? 'reachable' : 'reachable') : ('unreachable (' . ($errstr ?: 'no route') . ')');
        } else {
            $checks['SMTP'] = 'not configured';
        }

        $imapOk = in_array($checks['IMAP'] ?? '', ['signed in', 'reachable'], true);
        $smtpOk = ! str_starts_with($checks['SMTP'] ?? '', 'unreachable');
        $ok = $imapOk && $smtpOk;
        DB::table('email_accounts')->where('id', $id)->update([
            'status' => $ok ? 'connected' : 'error',
            'status_detail' => collect($checks)->map(fn ($v, $k) => "$k: $v")->implode(' · '),
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => $ok, 'checks' => $checks]);
    }
}
