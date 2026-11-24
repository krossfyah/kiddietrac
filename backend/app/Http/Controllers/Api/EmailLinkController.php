<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\PersonMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Emails on a person's record (2026-11-07) - see App\Support\PersonMail.
 *
 *   GET    /email/messages/{uid}/links       the people an open email is linked to, and who its addresses match
 *   POST   /email/messages/{uid}/links       link it to a person by hand
 *   DELETE /email/links/{id}                 unlink (stamped, audited, never deleted)
 *   GET    /email/link-people?account_id=&q= the person picker (live people in the mailbox's agency)
 *   GET    /admin/email-links/person         a person's linked emails (?type=user|contact&id=)
 *   GET    /admin/email-links/family/{id}    a family's linked emails
 *   GET    /admin/email-link-settings        the agency's setting
 *   PUT    /admin/email-link-settings
 *
 * All behind role:agency_admin,platform_admin (the email client's own group). The mailbox must be the caller's own
 * (personal mode, as every /email route); the records are read in the caller's active agency only.
 */
class EmailLinkController extends Controller
{
    private function account(Request $request, int $id): ?object
    {
        return DB::table('email_accounts')->where('id', $id)->where(\App\Support\SalesMailbox::owner($request))->whereNull('deleted_at')->first() ?: null;
    }

    /** The caller's active agency, membership-checked (platform admins may act in any). */
    private function agencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $h = (int) $request->header('X-Active-Agency-Id');
        if ($h && DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where(fn ($q) => $q->where('role', 'platform_admin')->orWhere(fn ($w) => $w->where('agency_id', $h)->where('role', 'agency_admin')))->exists()) {
            return $h;
        }
        return (int) DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->where('role', 'agency_admin')->value('agency_id');
    }

    private function meta(Request $request, object $a, string $uid): ?array
    {
        $mid = (string) ($request->input('mid') ?: $uid);
        try {
            return app(EmailMailController::class)->linkMeta($a, $mid, (string) ($request->input('folder') ?: 'INBOX'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function show(Request $request, string $uid): JsonResponse
    {
        $a = $this->account($request, (int) $request->query('account_id'));
        if (! $a) return response()->json(['message' => 'Mailbox not found'], 404);
        if (! PersonMail::linkable($a)) return response()->json(['enabled' => false, 'linked' => [], 'suggest' => []]);
        $meta = $this->meta($request, $a, $uid);
        if (! $meta) return response()->json(['message' => 'This email could not be read.'], 422);
        $linked = DB::table('email_person_links')->where('agency_id', $a->agency_id)->where('message_key', $meta['key'])->whereNull('unlinked_at')->get();
        $out = [];
        foreach ($linked as $l) {
            $p = PersonMail::person((int) $a->agency_id, $l->person_type, (int) $l->person_id);
            $out[] = ['id' => (int) $l->id, 'type' => $l->person_type, 'person_id' => (int) $l->person_id, 'name' => $p['name'] ?? '(no longer active)',
                'as' => $l->matched_as, 'source' => $l->source, 'family_id' => $l->family_id ? (int) $l->family_id : null];
        }
        // Who the addresses on it belong to (any kind), not yet linked: one tap to link.
        $have = []; foreach ($out as $o) $have[$o['type'] . ':' . $o['person_id']] = true;
        $own = [PersonMail::norm($a->email_address) => true, PersonMail::norm($a->oauth_identity ?? '') => true];
        $sug = [];
        foreach (array_unique(array_merge([$meta['from_addr']], $meta['to'])) as $addr) {
            if ($addr === '' || isset($own[$addr])) continue;
            foreach (PersonMail::peopleFor((int) $a->agency_id, $addr, PersonMail::KINDS) as $p) {
                $k = $p['type'] . ':' . $p['id'];
                if (isset($have[$k]) || isset($sug[$k])) continue;
                $sug[$k] = $p + ['email' => $addr];
            }
        }
        return response()->json(['enabled' => true, 'linked' => $out, 'suggest' => array_values($sug)]);
    }

    public function store(Request $request, string $uid): JsonResponse
    {
        $d = $request->validate([
            'account_id' => ['required', 'integer'], 'mid' => ['nullable', 'string', 'max:600'], 'folder' => ['nullable', 'string', 'max:300'],
            'person_type' => ['required', 'in:user,contact'], 'person_id' => ['required', 'integer'],
        ]);
        $a = $this->account($request, (int) $d['account_id']);
        if (! $a) return response()->json(['message' => 'Mailbox not found'], 404);
        if (! PersonMail::linkable($a)) return response()->json(['message' => 'Linking emails to people is off for this agency. Turn it on in Email settings.'], 422);
        $p = PersonMail::person((int) $a->agency_id, $d['person_type'], (int) $d['person_id']);
        if (! $p) return response()->json(['message' => 'That person is not active in this agency.'], 422);
        $meta = $this->meta($request, $a, $uid);
        if (! $meta) return response()->json(['message' => 'This email could not be read.'], 422);
        $id = PersonMail::manualLink($a, $meta, $p, (int) $request->user()->id);
        return response()->json(['ok' => true, 'id' => $id, 'name' => $p['name'], 'already' => $id === null]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $l = DB::table('email_person_links')->where('id', $id)->first();
        if (! $l || $l->unlinked_at) return response()->json(['message' => 'Link not found'], 404);
        // The mailbox's owner, or an admin of the agency the link belongs to.
        $owner = (int) DB::table('email_accounts')->where('id', $l->account_id)->value('user_id');
        if ($owner !== (int) $request->user()->id && $this->agencyId($request) !== (int) $l->agency_id) {
            return response()->json(['message' => 'Link not found'], 404);
        }
        PersonMail::unlink($l, (int) $request->user()->id);
        return response()->json(['ok' => true]);
    }

    public function people(Request $request): JsonResponse
    {
        $a = $this->account($request, (int) $request->query('account_id'));
        if (! $a || ! PersonMail::linkable($a)) return response()->json(['people' => []]);
        return response()->json(['people' => PersonMail::search((int) $a->agency_id, mb_substr((string) $request->query('q', ''), 0, 80))]);
    }

    /* Reading a record's linked emails (2026-11-06): whoever the agency's "who can see" setting admits
       (PersonMail::viewScope) - admins, directors for their own centres, named people. Also reachable by
       directors (GET /email-links/...). A caller who may not see gets 'allowed' => false and an empty list. */
    /* THE AGENCY BEING VIEWED (2026-11-07, Anthony on iLearn: "Linking emails to people is off for this agency ...
       we have this enabled so no idea why"). AgencyRole::activeAgency answers "which agency does this person BELONG
       to here" and is deliberately null for a platform admin (they are never narrowed), so for one this was always 0:
       the ✉️ Emails tab never showed and records said linking was off, with 600+ emails linked. A platform admin
       works in the agency they picked (the header), as agencyId() above already allows. */
    private function viewer(Request $request): array
    {
        $ag = (int) \App\Support\AgencyRole::activeAgency($request);
        if (! $ag) {
            $h = (int) $request->header('X-Active-Agency-Id');
            if ($h > 0 && in_array('platform_admin', \App\Support\AgencyRole::names($request), true)) $ag = $h;
        }
        return [$ag, $ag ? PersonMail::viewScope($request, $ag) : false];
    }

    /* The record's Emails tab searches, filters and sorts in the page (2026-11-11), so it gets the newest LIST_MAX and
       the true total beside them. */
    private const LIST_MAX = 1000;

    private function emailsOut(Request $request, int $ag, array $emails, ?int $total = null): JsonResponse
    {
        return response()->json(['allowed' => true, 'enabled' => PersonMail::settings($ag)['enabled'], 'emails' => $emails,
            'total' => $total ?? count($emails),
            'me' => (int) $request->user()->id,
            // Unlinking stays with the agency's admins (and the mailbox's owner, per email).
            'can_unlink' => $this->agencyId($request) === $ag]);
    }

    /** GET /email-links/access - may this caller see linked emails at all (the record screens ask once). */
    public function access(Request $request): JsonResponse
    {
        [$ag, $scope] = $this->viewer($request);
        return response()->json(['view' => $scope !== false && (! is_array($scope) || $scope !== []), 'enabled' => $ag ? PersonMail::settings($ag)['enabled'] : false]);
    }

    public function person(Request $request): JsonResponse
    {
        [$ag, $scope] = $this->viewer($request);
        $type = (string) $request->query('type', 'user');
        $id = (int) $request->query('id');
        if (! $ag || ! in_array($type, ['user', 'contact'], true) || $id <= 0) return response()->json(['emails' => []]);
        if (! PersonMail::scopeHasPerson($scope, $type, $id)) return response()->json(['allowed' => false, 'emails' => []]);
        return $this->emailsOut($request, $ag, PersonMail::forPerson($ag, $type, $id, self::LIST_MAX), PersonMail::countForPerson($ag, $type, $id));
    }

    public function family(Request $request, int $id): JsonResponse
    {
        [$ag, $scope] = $this->viewer($request);
        if (! $ag) return response()->json(['emails' => []]);
        if (! PersonMail::scopeHasFamily($scope, $id)) return response()->json(['allowed' => false, 'emails' => []]);
        return $this->emailsOut($request, $ag, PersonMail::forFamily($ag, $id, self::LIST_MAX), PersonMail::countForFamily($ag, $id));
    }

    /* OPEN, DOWNLOAD, PRINT AND FORWARD A LINKED EMAIL (2026-11-09, Anthony: "have a kebab that can pop up the email in a
       popup to view the original contents of the email with all info and another option to download that email, print
       that email or ability to resend that email to someone").
         GET  /email-links/{id}/message           the whole email (headers, original body, attachments) - a peek, never marks it read
         GET  /email-links/{id}/attachment?part=  one attachment
         GET  /email-links/{id}/download          the email as a .eml file (opens in Outlook / Apple Mail with everything in it)
         POST /email-links/{id}/forward           send a copy to someone {to:[...], note}
       The email lives in somebody's mailbox, usually not the reader's, so the only gate is the record: the reader must be
       admitted to the link's person or family by PersonMail::viewScope, exactly as for the list. Anything else is a 404. */
    private function linked(Request $request, int $id): ?array
    {
        [$ag, $scope] = $this->viewer($request);
        if (! $ag || $scope === false) return null;
        $l = DB::table('email_person_links')->where('id', $id)->where('agency_id', $ag)->whereNull('unlinked_at')->first();
        if (! $l) return null;
        if (! PersonMail::scopeHasPerson($scope, (string) $l->person_type, (int) $l->person_id)
            && ! ($l->family_id && PersonMail::scopeHasFamily($scope, (int) $l->family_id))) {
            return null;
        }
        $a = DB::table('email_accounts')->where('id', $l->account_id)->whereNull('deleted_at')->first();
        if (! $a || (int) $a->agency_id !== (int) $l->agency_id || (string) $l->provider_uid === '') return null;
        return [$l, $a, $ag];
    }

    private function gone(): JsonResponse
    {
        return response()->json(['message' => 'That email is not on this record any more.'], 404);
    }

    /** A file name from the subject: safe for a header and for every operating system. */
    private function fileName(object $l, string $ext): string
    {
        $base = trim(preg_replace('/[\x00-\x1F\/\\\\:*?"<>|]+/', ' ', (string) ($l->subject ?: 'Email')));
        $base = mb_substr(preg_replace('/\s+/', ' ', $base) ?: 'Email', 0, 80);
        return $base . ($l->received_at ? ' - ' . \Illuminate\Support\Carbon::parse($l->received_at, 'UTC')->setTimezone(\App\Support\AgencyTime::tz((int) $l->agency_id) ?: config('app.timezone'))->format('Y-m-d') : '') . '.' . $ext;
    }

    private function auditLinked(Request $request, object $l, int $ag, string $action, string $summary): void
    {
        $p = PersonMail::person($ag, (string) $l->person_type, (int) $l->person_id);
        Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $ag, 'action' => $action, 'entity_type' => 'email_person_link', 'entity_id' => (int) $l->id,
            'payload' => json_encode(['summary' => $summary . ' "' . mb_substr((string) ($l->subject ?: '(no subject)'), 0, 120) . '"'
                . (! empty($p['name']) ? ' on ' . $p['name'] . '\'s record' : '')], JSON_UNESCAPED_UNICODE),
            'created_at' => now()]);
    }

    public function message(Request $request, int $id): JsonResponse
    {
        $x = $this->linked($request, $id);
        if (! $x) return $this->gone();
        [$l, $a, $ag] = $x;
        $r = app(EmailMailController::class)->linkedMessage($a, (string) $l->provider_uid);
        if ($r->getStatusCode() !== 200) {
            $m = (string) ($r->getData(true)['message'] ?? '');
            return response()->json(['message' => $r->getStatusCode() === 404
                ? 'This email is no longer in ' . $a->email_address . ' - it may have been deleted or moved there.'
                : ($m ?: 'The mailbox would not open this email just now. Please try again.')], $r->getStatusCode() === 404 ? 404 : 422);
        }
        $d = $r->getData(true);
        $this->auditLinked($request, $l, $ag, 'email.linked_opened', 'Opened the email');
        // What the link row knows fills anything the mailbox did not say (IMAP gives the body only).
        return response()->json($d + [
            'subject' => (string) ($l->subject ?: ''),
            'from' => ['name' => (string) ($l->from_name ?? ''), 'email' => (string) $l->from_addr],
            'to' => array_map(fn ($e) => ['name' => '', 'email' => $e], array_values(array_filter(array_map('trim', explode(',', (string) $l->to_addrs))))),
            'cc' => [], 'bcc' => [],
        ] + ['mailbox' => $a->email_address, 'direction' => $l->direction, 'can_forward' => true]);
    }

    public function attachment(Request $request, int $id)
    {
        $x = $this->linked($request, $id);
        if (! $x) return $this->gone();
        [$l, $a] = $x;
        $part = (string) $request->query('part', '');
        if ($part === '' || strlen($part) > 600) return response()->json(['message' => 'Attachment not found'], 404);
        return app(EmailMailController::class)->linkedAttachment($a, (string) $l->provider_uid, $part);
    }

    public function download(Request $request, int $id)
    {
        $x = $this->linked($request, $id);
        if (! $x) return $this->gone();
        [$l, $a, $ag] = $x;
        try {
            $raw = app(EmailMailController::class)->linkedRaw($a, (string) $l->provider_uid);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'The mailbox would not give this email just now. Please try again.'], 422);
        }
        if ($raw === null || $raw === '') return response()->json(['message' => 'This email is no longer in ' . $a->email_address . '.'], 404);
        $this->auditLinked($request, $l, $ag, 'email.linked_downloaded', 'Downloaded the email');
        $name = $this->fileName($l, 'eml');
        return response($raw, 200, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'Content-Length' => (string) strlen($raw),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /* Forward. From the reader's own Microsoft mailbox when they have one in this agency - it goes as a real forward with
       its attachments and sits in their Sent Items, so replies come back to them. Otherwise from the agency's own email,
       with the reader as reply-to and the original attached as a .eml, so nothing in it is lost. */
    public function forward(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:10'], 'to.*' => ['email', 'max:190'],
            'note' => ['nullable', 'string', 'max:4000'],
        ], ['to.required' => 'Add who it should go to.', 'to.*.email' => 'One of those is not an email address.']);
        $x = $this->linked($request, $id);
        if (! $x) return $this->gone();
        [$l, $a, $ag] = $x;
        $me = $request->user();
        $to = array_values(array_unique(array_map(fn ($e) => strtolower(trim($e)), $d['to'])));
        $note = trim((string) ($d['note'] ?? ''));
        $noteHtml = $note !== '' ? nl2br(e($note)) : '';
        $subject = (string) ($l->subject ?: '(no subject)');
        $fwSubject = preg_match('/^\s*(fw|fwd)\s*:/i', $subject) ? $subject : 'FW: ' . $subject;

        $via = null;
        if (\App\Support\MicrosoftMailbox::uses($a)) {
            $mine = DB::table('email_accounts')->where('user_id', (int) $me->id)->whereNull('shared_scope')->whereNull('deleted_at')
                ->whereNull('auth_failed_at')->where('agency_id', $ag)->where('auth_type', 'microsoft')
                ->orderByRaw('id = ? desc', [(int) $a->id])->first();
            if ($mine) {
                try {
                    \App\Support\MailSend::send($mine, 'forward', $a, (string) $l->provider_uid, $to, [], [],
                        (int) $mine->id === (int) $a->id ? '' : $fwSubject, $noteHtml, []);
                    $via = $mine->email_address;
                } catch (\RuntimeException $e) {
                    \Log::info('linked email forward via mailbox failed, using agency mail: ' . $e->getMessage());
                }
            }
        }
        if (! $via) {
            $mc = app(EmailMailController::class);
            try {
                $orig = $mc->linkedMessage($a, (string) $l->provider_uid);
                if ($orig->getStatusCode() !== 200) return response()->json(['message' => 'This email is no longer in ' . $a->email_address . ', so it cannot be forwarded.'], 404);
                $o = $orig->getData(true);
                $raw = null;
                try { $raw = $mc->linkedRaw($a, (string) $l->provider_uid); } catch (\Throwable $e) {}
            } catch (\Throwable $e) {
                return response()->json(['message' => 'The mailbox would not give this email just now. Please try again.'], 422);
            }
            $who = trim((string) (($me->preferred_name ?? '') ?: ($me->first_name ?? ''))) ?: 'Someone';
            $people = fn ($list) => implode(', ', array_map(fn ($p) => e(trim(($p['name'] ?? '') !== '' ? $p['name'] . ' <' . ($p['email'] ?? '') . '>' : (string) ($p['email'] ?? ''))), (array) $list));
            $from = $o['from'] ?? ['name' => (string) ($l->from_name ?? ''), 'email' => (string) $l->from_addr];
            $row = fn ($k, $v) => $v === '' ? '' : '<tr><td style="padding:2px 12px 2px 0;color:#64748B;white-space:nowrap;vertical-align:top;">' . $k . '</td><td style="padding:2px 0;">' . $v . '</td></tr>';
            $body = ($noteHtml !== '' ? '<p style="margin:0 0 14px;">' . $noteHtml . '</p>' : '')
                . '<p style="margin:0 0 10px;">' . e($who) . ' forwarded you this email.' . ($raw ? ' The original is attached as well.' : '') . '</p>'
                . '<table role="presentation" style="border-collapse:collapse;font-size:13px;margin:0 0 12px;">'
                . $row('From', $people([$from])) . $row('Sent', e((string) ($o['when'] ?? ''))) . $row('To', $people($o['to'] ?? []))
                . $row('Cc', $people($o['cc'] ?? [])) . $row('Subject', e($subject)) . '</table>'
                . '<div style="border-top:1px solid #CBD5E1;padding-top:12px;">' . (string) ($o['html'] ?? '') . '</div>';
            $html = \App\Services\EmailTemplate::wrap($ag, $body, ['eyebrow' => 'FORWARDED EMAIL', 'title' => mb_substr($subject, 0, 120), 'preheader' => $who . ' forwarded you an email']);
            $file = $this->fileName($l, 'eml');
            $replyTo = (string) ($me->email ?? '');
            try {
                \App\Services\AgencyMailer::forAgency($ag)->html($html, function ($m) use ($to, $fwSubject, $raw, $file, $replyTo, $who) {
                    $m->to($to)->subject($fwSubject);
                    if ($replyTo !== '') $m->replyTo($replyTo, $who);
                    if ($raw && strlen($raw) <= 20000000) $m->attachData($raw, $file, ['mime' => 'message/rfc822']);
                });
            } catch (\Throwable $e) {
                \Log::warning('linked email forward failed: ' . $e->getMessage(), ['link' => (int) $l->id]);
                return response()->json(['message' => 'The email could not be sent just now. Please try again.'], 422);
            }
            $via = 'agency';
        }
        $this->auditLinked($request, $l, $ag, 'email.linked_forwarded', 'Forwarded to ' . implode(', ', $to) . ($via !== 'agency' ? ' from ' . $via : '') . ':');
        return response()->json(['ok' => true, 'via' => $via, 'to' => $to]);
    }

    public function settings(Request $request): JsonResponse
    {
        $ag = $this->agencyId($request);
        if (! $ag) return response()->json(['message' => 'No agency selected'], 422);
        $boxes = DB::table('email_accounts')->whereNull('deleted_at')->where('agency_id', $ag)->whereNull('shared_scope')
            ->get(['email_address', 'auth_type'])->map(fn ($b) => ['email' => $b->email_address, 'auto' => $b->auth_type === 'microsoft'])->values();
        return response()->json(['settings' => PersonMail::settings($ag), 'mailboxes' => $boxes, 'viewers' => PersonMail::viewerCandidates($ag)]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'enabled' => ['sometimes', 'boolean'], 'auto' => ['sometimes', 'boolean'], 'sent' => ['sometimes', 'boolean'],
            'kinds' => ['sometimes', 'array'], 'kinds.*' => ['in:guardian,staff,contact'],
            'view_roles' => ['sometimes', 'array'], 'view_roles.*' => ['in:agency_admin,centre_director'],
            'view_users' => ['sometimes', 'array'], 'view_users.*' => ['integer'],
        ]);
        $ag = $this->agencyId($request);
        if (! $ag) return response()->json(['message' => 'No agency selected'], 422);
        $before = PersonMail::settings($ag);
        $after = PersonMail::saveSettings($ag, $d);
        $label = ['guardian' => 'parents', 'staff' => 'staff', 'contact' => 'contacts'];
        Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $ag, 'action' => 'email.link_settings_updated', 'entity_type' => 'agency', 'entity_id' => $ag,
            'payload' => json_encode(['summary' => 'Linking emails to people: ' . ($after['enabled'] ? 'on' : 'off')
                . ($after['enabled'] ? ' (' . ($after['auto'] ? 'matched by address' : 'by hand only') . ', ' . (implode(', ', array_map(fn ($k) => $label[$k], $after['kinds'])) ?: 'nobody')
                . ($after['sent'] ? ', sent mail too' : '') . ')' : '')
                . '; seen by ' . (implode(', ', array_merge(
                    array_map(fn ($r) => $r === 'agency_admin' ? 'admins' : 'directors (own centres)', $after['view_roles']),
                    array_map(fn ($v) => $v['name'], array_values(array_filter(PersonMail::viewerCandidates($ag), fn ($v) => in_array($v['id'], $after['view_users'], true))))
                )) ?: 'platform admins only'),
                'before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE),
            'created_at' => now()]);
        return response()->json(['settings' => $after]);
    }
}
