<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Jobs\SendRsvpMail;
use App\Support\AgencyTime;
use App\Support\RsvpMail;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Event RSVPs (2026-10-05).
 *
 * Anthony asked for "a RSVP function that allows me to send out a link to have people
 * RSVP with a customizable image ... address, email (mandatory), tel# (optional) and topic
 * to be covered at the event - these fields should be customizable at any given time using
 * a template format", emailed to a configurable address, with reports on who was sent,
 * signed up and opted out, with timestamps. Agency admins own it, each for their own agency.
 *
 * TEMPLATES hold the questions, the invite email, an image and the notify address.
 * EVENTS are made from a template and SNAPSHOT its fields; either can be edited at any
 * time, and a response keeps the label each answer was given under, so a later edit never
 * garbles an earlier answer. Email is always a field and always required.
 *
 * The notify address (per event, defaulted from the template, and REQUIRED — it is never
 * guessed) hears about every sign-up and opt-out, is the Reply-To on invites, and the
 * registrant gets their own confirmation copy.
 *
 * Opt-outs are per agency: a person who unsubscribes is never sent another invite by that
 * agency, whichever event it is for. Unsubscribe is a POST from a page, never a GET from
 * the email, because mail scanners follow links.
 *
 * Every admin read and write is scoped to the ACTIVE agency, and the caller must be an
 * agency_admin OF that agency (or a platform admin): the role middleware only says they are
 * an agency admin somewhere.
 */
class RsvpController extends Controller
{
    use ResolvesCentreContext;

    private const TYPES = ['text', 'email', 'tel', 'textarea', 'select', 'checkbox'];
    private const SOURCES = ['manual', 'csv', 'guardian', 'staff'];

    /* ═════════════ templates ═════════════ */

    public function templates(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $rows = DB::table('rsvp_templates')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('name')->get();
        $used = DB::table('rsvp_events')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->selectRaw('template_id, COUNT(*) c')->groupBy('template_id')->pluck('c', 'template_id');
        foreach ($rows as $r) {
            $r->fields = json_decode((string) $r->fields, true) ?: [];
            $r->events = (int) ($used[$r->id] ?? 0);
        }
        $out = ['templates' => $rows, 'is_platform' => $this->isPlatform($request)];
        if ($out['is_platform']) {
            $out['agencies'] = DB::table('agencies')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        }

        return response()->json($out);
    }

    public function saveTemplate(Request $request, ?int $id = null): JsonResponse
    {
        $agencyId = $this->agency($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'fields' => ['required', 'array', 'max:30'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'email_subject' => ['required', 'string', 'max:200'],
            'email_body' => ['required', 'string', 'max:8000'],
            'confirmation_message' => ['nullable', 'string', 'max:4000'],
            'notify_email' => ['nullable', 'email', 'max:160'],
        ]);
        $data['fields'] = json_encode($this->normaliseFields($data['fields']), JSON_UNESCAPED_UNICODE);
        $data['image_url'] = $this->checkImage($data['image_url'] ?? null, $agencyId);
        $data['notify_email'] = isset($data['notify_email']) ? strtolower(trim($data['notify_email'])) : null;
        if ($id) {
            abort_unless(DB::table('rsvp_templates')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->exists(), 404);
            DB::table('rsvp_templates')->where('id', $id)->update($data + ['updated_at' => now()]);
        } else {
            $id = DB::table('rsvp_templates')->insertGetId($data + ['agency_id' => $agencyId, 'created_by_id' => (int) $request->user()->id,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'rsvp.template_saved', 'rsvp_template', $id, 'Saved RSVP template "' . $data['name'] . '"');

        return response()->json(['id' => $id]);
    }

    public function deleteTemplate(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $t = DB::table('rsvp_templates')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($t, 404);
        // Events keep their own snapshot, so deleting the template changes none of them.
        DB::table('rsvp_templates')->where('id', $id)->update(['deleted_at' => now()]);
        $this->audit($request, $agencyId, 'rsvp.template_deleted', 'rsvp_template', $id, 'Deleted RSVP template "' . $t->name . '"');

        return response()->json(['ok' => true]);
    }

    /** Duplicate within this agency, or (platform admin) copy into another agency. */
    public function copyTemplate(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $t = DB::table('rsvp_templates')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($t, 404);
        $target = (int) ($request->input('agency_id') ?: $agencyId);
        if ($target !== $agencyId) {
            abort_unless($this->isPlatform($request), 403, 'Only a platform admin can copy a template to another agency.');
            abort_unless(DB::table('agencies')->where('id', $target)->whereNull('deleted_at')->exists(), 422, 'Unknown agency.');
        }
        $name = $target === $agencyId ? $t->name . ' (copy)' : $t->name;
        $new = DB::table('rsvp_templates')->insertGetId([
            'agency_id' => $target, 'name' => mb_substr($name, 0, 160), 'description' => $t->description, 'fields' => $t->fields,
            'image_url' => $t->image_url, 'email_subject' => $t->email_subject, 'email_body' => $t->email_body,
            'confirmation_message' => $t->confirmation_message,
            // The notify address belongs to the agency it was set for; the copy asks again.
            'notify_email' => $target === $agencyId ? $t->notify_email : null,
            'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($request, $agencyId, 'rsvp.template_copied', 'rsvp_template', $id,
            'Copied RSVP template "' . $t->name . '"' . ($target !== $agencyId ? ' to agency #' . $target : ''));
        if ($target !== $agencyId) {
            $this->audit($request, $target, 'rsvp.template_copied', 'rsvp_template', $new, 'RSVP template "' . $t->name . '" copied in from another agency');
        }

        return response()->json(['id' => $new, 'agency_id' => $target]);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120']]);
        $f = $request->file('image');
        $ext = strtolower($f->getClientOriginalExtension() ?: $f->extension() ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) $ext = 'jpg';
        $name = Str::random(32) . '.' . $ext;
        $f->storeAs('rsvp/' . $agencyId, $name, 'public');
        $url = rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/') . '/storage/rsvp/' . $agencyId . '/' . $name;
        $this->audit($request, $agencyId, 'rsvp.image_uploaded', 'agency', $agencyId, 'Uploaded an RSVP image');

        return response()->json(['url' => $url]);
    }

    /* ═════════════ events ═════════════ */

    public function events(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $rows = DB::table('rsvp_events')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderByDesc('id')->get();
        $stats = $this->statsFor($agencyId, $rows->pluck('id')->all());
        foreach ($rows as $r) {
            $this->decorate($r);
            $r->stats = $stats[$r->id] ?? $this->emptyStats();
        }

        return response()->json(['events' => $rows, 'tz' => AgencyTime::tz($agencyId)]);
    }

    public function event(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $this->decorate($e);
        $e->stats = $this->statsFor($agencyId, [$id])[$id] ?? $this->emptyStats();

        return response()->json(['event' => $e, 'tz' => AgencyTime::tz($agencyId)]);
    }

    public function saveEvent(Request $request, ?int $id = null): JsonResponse
    {
        $agencyId = $this->agency($request);
        $data = $request->validate([
            'template_id' => [$id ? 'nullable' : 'required', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'starts_local' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_local' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'location' => ['nullable', 'string', 'max:250'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'fields' => ['nullable', 'array', 'max:30'],
            'email_subject' => ['nullable', 'string', 'max:200'],
            'email_body' => ['nullable', 'string', 'max:8000'],
            'confirmation_message' => ['nullable', 'string', 'max:4000'],
            'notify_email' => ['required', 'email', 'max:160'],
            'status' => ['required', 'in:draft,open,closed'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ], ['notify_email.required' => 'Enter the address that should receive sign-ups.']);
        $tz = AgencyTime::tz($agencyId);
        $toUtc = fn ($v) => $v ? Carbon::createFromFormat('Y-m-d\TH:i', $v, $tz)->utc()->format('Y-m-d H:i:s') : null;
        $row = [
            'title' => trim($data['title']), 'description' => $data['description'] ?? null,
            'starts_at' => $toUtc($data['starts_local'] ?? null), 'ends_at' => $toUtc($data['ends_local'] ?? null),
            'location' => $data['location'] ?? null, 'notify_email' => strtolower(trim($data['notify_email'])),
            'status' => $data['status'], 'capacity' => $data['capacity'] ?? null,
        ];
        if ($row['starts_at'] && $row['ends_at'] && $row['ends_at'] < $row['starts_at']) {
            return response()->json(['message' => 'The event ends before it starts.'], 422);
        }
        if ($id) {
            $e = $this->ownEvent($agencyId, $id);
            if (array_key_exists('fields', $data) && $data['fields'] !== null) $row['fields'] = json_encode($this->normaliseFields($data['fields']), JSON_UNESCAPED_UNICODE);
            foreach (['email_subject', 'email_body'] as $k) {
                if (isset($data[$k]) && trim($data[$k]) !== '') $row[$k] = $data[$k];
            }
            if (array_key_exists('confirmation_message', $data)) $row['confirmation_message'] = $data['confirmation_message'];
            if (array_key_exists('image_url', $data)) $row['image_url'] = $this->checkImage($data['image_url'], $agencyId);
            DB::table('rsvp_events')->where('id', $id)->update($row + ['updated_at' => now()]);
        } else {
            $t = DB::table('rsvp_templates')->where('id', $data['template_id'])->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
            abort_unless($t, 422, 'Choose one of your templates.');
            $fields = isset($data['fields']) ? $this->normaliseFields($data['fields']) : (json_decode((string) $t->fields, true) ?: []);
            $id = DB::table('rsvp_events')->insertGetId($row + [
                'agency_id' => $agencyId, 'template_id' => $t->id,
                'fields' => json_encode($this->normaliseFields($fields), JSON_UNESCAPED_UNICODE),
                'image_url' => array_key_exists('image_url', $data) ? $this->checkImage($data['image_url'], $agencyId) : $t->image_url,
                'email_subject' => trim((string) ($data['email_subject'] ?? '')) !== '' ? $data['email_subject'] : $t->email_subject,
                'email_body' => trim((string) ($data['email_body'] ?? '')) !== '' ? $data['email_body'] : $t->email_body,
                'confirmation_message' => $data['confirmation_message'] ?? $t->confirmation_message,
                'public_token' => Str::random(32), 'created_by_id' => (int) $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->audit($request, $agencyId, 'rsvp.event_saved', 'rsvp_event', $id, 'Saved RSVP event "' . $row['title'] . '" (' . $row['status'] . ')');

        return response()->json(['id' => $id]);
    }

    public function deleteEvent(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        DB::table('rsvp_events')->where('id', $id)->update(['deleted_at' => now(), 'status' => 'closed']);
        // Anything still waiting in the queue must not go out for a deleted event.
        DB::table('rsvp_invites')->where('event_id', $id)->where('send_status', 'queued')->update(['send_status' => 'pending', 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'rsvp.event_deleted', 'rsvp_event', $id, 'Deleted RSVP event "' . $e->title . '"');

        return response()->json(['ok' => true]);
    }

    /* ═════════════ invitees ═════════════ */

    /** Families' guardians and staff in THIS agency, for the invite picker. */
    public function contacts(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);

        return response()->json(['contacts' => array_values($this->agencyContacts($agencyId))]);
    }

    public function addInvites(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $data = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:3000'],
            'recipients.*.email' => ['nullable', 'string', 'max:190'],
            'recipients.*.name' => ['nullable', 'string', 'max:160'],
            'recipients.*.source' => ['nullable', 'in:' . implode(',', self::SOURCES)],
            'recipients.*.user_id' => ['nullable', 'integer'],
            'send' => ['nullable', 'boolean'],
        ]);
        // A contact picked from the agency is resolved HERE from the agency's own list; the
        // client's copy of their address and name is not trusted.
        $contacts = null;
        $added = []; $existing = 0; $invalid = []; $optedOut = 0;
        foreach ($data['recipients'] as $r) {
            $src = $r['source'] ?? 'manual';
            $uid = null;
            if (in_array($src, ['guardian', 'staff'], true)) {
                $contacts ??= $this->agencyContacts($agencyId);
                $c = $contacts[$src . ':' . (int) ($r['user_id'] ?? 0)] ?? null;
                if (! $c) { $invalid[] = (string) ($r['email'] ?? ('user #' . ($r['user_id'] ?? '?'))); continue; }
                $email = $c['email']; $name = $c['name']; $uid = $c['user_id'];
            } else {
                $email = strtolower(trim((string) ($r['email'] ?? '')));
                $name = trim((string) ($r['name'] ?? '')) ?: null;
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalid[] = (string) ($r['email'] ?? ''); continue; }
            if (isset($added[$email]) || DB::table('rsvp_invites')->where('event_id', $id)->where('email', $email)->exists()) { $existing++; continue; }
            $out = RsvpMail::optedOut($agencyId, $email);
            if ($out) $optedOut++;
            $added[$email] = DB::table('rsvp_invites')->insertGetId([
                'agency_id' => $agencyId, 'event_id' => $id, 'name' => $name ? mb_substr($name, 0, 160) : null, 'email' => $email,
                'source' => $src, 'user_id' => $uid, 'token' => Str::random(40),
                'send_status' => $out ? 'skipped' : 'pending', 'send_error' => $out ? 'This address has opted out of event invitations.' : null,
                'invited_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->audit($request, $agencyId, 'rsvp.invitees_added', 'rsvp_event', $id,
            'Added ' . count($added) . ' invitee(s) to "' . $e->title . '"' . ($existing ? ' (' . $existing . ' already on the list)' : ''));
        $queued = 0;
        if (! empty($data['send']) && $added) {
            $queued = $this->queue($e, array_values($added));
        }

        return response()->json(['added' => count($added), 'already_invited' => $existing, 'opted_out' => $optedOut,
            'invalid' => array_slice($invalid, 0, 50), 'invalid_count' => count($invalid), 'queued' => $queued]);
    }

    public function sendInvites(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $data = $request->validate([
            'invite_ids' => ['nullable', 'array', 'max:3000'], 'invite_ids.*' => ['integer'],
            'retry_failed' => ['nullable', 'boolean'],
        ]);
        if ($e->status !== 'open') {
            return response()->json(['message' => 'Open the event before sending invitations (it is ' . $e->status . ').'], 422);
        }
        $q = DB::table('rsvp_invites')->where('event_id', $id)->where('agency_id', $agencyId);
        if (! empty($data['invite_ids'])) {
            // Named rows may be re-sent (a reminder), except someone who has already signed up or opted out.
            $q->whereIn('id', $data['invite_ids'])->whereNull('registered_at')->whereNull('opted_out_at')
              ->whereIn('send_status', ['pending', 'sent', 'failed', 'suppressed']);
        } else {
            $q->whereIn('send_status', ! empty($data['retry_failed']) ? ['pending', 'failed'] : ['pending']);
        }
        $ids = $q->pluck('id')->all();
        $n = $this->queue($e, $ids);
        $this->audit($request, $agencyId, 'rsvp.invites_queued', 'rsvp_event', $id, 'Queued ' . $n . ' invitation(s) for "' . $e->title . '"');

        return response()->json(['queued' => $n]);
    }

    /** Send one invite to an address the admin names — required, never defaulted. */
    public function testInvite(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $data = $request->validate(['to' => ['required', 'email', 'max:190']]);
        $fake = (object) ['name' => 'Test Recipient', 'token' => 'test', 'email' => $data['to']];
        [$subject, $html] = RsvpMail::invite($e, $fake);
        // A test must not hand out a live link that would sign "Test Recipient" up, so its
        // buttons point at the event's shareable page instead.
        $html = str_replace([RsvpMail::rsvpUrl('test'), RsvpMail::optOutUrl('test')], [RsvpMail::publicUrl($e->public_token), RsvpMail::publicUrl($e->public_token)], $html);
        $r = RsvpMail::send($agencyId, $data['to'], null, '[Test] ' . $subject, $html, ['reply_to' => $e->notify_email]);
        $this->audit($request, $agencyId, 'rsvp.test_sent', 'rsvp_event', $id, 'Test invitation for "' . $e->title . '" to ' . $data['to'] . ': ' . $r['status']);

        return response()->json(['sent' => $r['status'] === 'sent', 'status' => $r['status'], 'reason' => $r['error']]);
    }

    public function removeInvite(Request $request, int $inviteId): JsonResponse
    {
        $agencyId = $this->agency($request);
        $inv = DB::table('rsvp_invites')->where('id', $inviteId)->where('agency_id', $agencyId)->first();
        abort_unless($inv, 404);
        if ($inv->registered_at || in_array($inv->send_status, ['sent', 'queued', 'suppressed', 'failed'], true) || $inv->opted_out_at) {
            return response()->json(['message' => 'Only an invitee who has not been sent anything can be removed; the report keeps the rest.'], 422);
        }
        DB::table('rsvp_invites')->where('id', $inviteId)->delete();
        $this->audit($request, $agencyId, 'rsvp.invitee_removed', 'rsvp_event', (int) $inv->event_id, 'Removed ' . $inv->email . ' from the invite list');

        return response()->json(['ok' => true]);
    }

    /* ═════════════ reports ═════════════ */

    public function report(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        [$cols, $rows] = $this->reportRows($agencyId, $e, (string) $request->query('view', 'all'));
        $this->decorate($e);

        return response()->json(['event' => $e, 'columns' => $cols, 'rows' => $rows,
            'stats' => $this->statsFor($agencyId, [$id])[$id] ?? $this->emptyStats(), 'tz' => AgencyTime::tz($agencyId)]);
    }

    public function reportCsv(Request $request, int $id): Response
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $view = (string) $request->query('view', 'all');
        [$cols, $rows] = $this->reportRows($agencyId, $e, $view);
        $tz = AgencyTime::tz($agencyId);
        $cell = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v;   // no spreadsheet formulas from public input
        $out = fopen('php://temp', 'w+');
        $head = ['Name', 'Email', 'Source', 'Invitation', 'Queued (' . $tz . ')', 'Sent (' . $tz . ')', 'Opened link (' . $tz . ')',
            'Signed up (' . $tz . ')', 'Opted out (' . $tz . ')', 'Note'];
        foreach ($cols as $c) $head[] = $c['label'];
        fputcsv($out, $head);
        foreach ($rows as $r) {
            $line = [$cell($r['name']), $cell($r['email']), $r['source'], $r['status_label'], $r['queued_at'], $r['sent_at'], $r['opened_at'],
                $r['registered_at'], $r['opted_out_at'], $cell($r['note'])];
            foreach ($cols as $c) $line[] = $cell($r['answers'][$c['key']] ?? '');
            fputcsv($out, $line);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        $this->audit($request, $agencyId, 'rsvp.report_exported', 'rsvp_event', $id, 'Exported the "' . $view . '" RSVP report for "' . $e->title . '" (' . count($rows) . ' rows)');
        $slug = Str::slug($e->title) ?: 'event';

        return new Response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="rsvp-' . $slug . '-' . $view . '.csv"']);
    }

    public function optOuts(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $tz = AgencyTime::tz($agencyId);
        $rows = DB::table('rsvp_optouts as o')->leftJoin('rsvp_events as e', 'e.id', '=', 'o.event_id')
            ->where('o.agency_id', $agencyId)->orderByDesc('o.id')->limit(5000)->get(['o.id', 'o.email', 'o.created_at', 'e.title as event']);
        foreach ($rows as $r) $r->at = AgencyTime::fmt($r->created_at, $tz, 'M j, Y g:i A');

        return response()->json(['optouts' => $rows, 'tz' => $tz]);
    }

    public function removeOptOut(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $o = DB::table('rsvp_optouts')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($o, 404);
        DB::table('rsvp_optouts')->where('id', $id)->delete();
        $this->audit($request, $agencyId, 'rsvp.optout_removed', 'agency', $agencyId, 'Re-subscribed ' . $o->email . ' to event invitations');

        return response()->json(['ok' => true]);
    }

    /* ═════════════ public (no login) ═════════════ */

    /** GET /public/rsvp/event/{token} — the shareable link. */
    public function publicEvent(string $token): JsonResponse
    {
        $e = $this->eventByToken($token);
        if (! $e) return response()->json(['message' => 'This RSVP link is not available.'], 404);

        return response()->json($this->publicPayload($e));
    }

    /** GET /public/rsvp/invite/{token} — a personal link from an invite. */
    public function publicInvite(string $token): JsonResponse
    {
        [$inv, $e] = $this->inviteByToken($token);
        if (! $inv || ! $e) return response()->json(['message' => 'This RSVP link is not available.'], 404);
        if (! $inv->opened_at) {
            DB::table('rsvp_invites')->where('id', $inv->id)->update(['opened_at' => now(), 'updated_at' => now()]);
        }
        $p = $this->publicPayload($e);
        $p['invite'] = ['name' => $inv->name, 'email' => $inv->email, 'opted_out' => (bool) $inv->opted_out_at];
        $resp = DB::table('rsvp_responses')->where('event_id', $e->id)->where('email', $inv->email)->first();
        if ($resp) {
            $p['previous'] = array_map(fn ($a) => $a['value'] ?? '', json_decode((string) $resp->answers, true) ?: []);
        }

        return response()->json($p);
    }

    public function submitEvent(Request $request, string $token): JsonResponse
    {
        $e = $this->eventByToken($token);
        if (! $e) return response()->json(['message' => 'This RSVP link is not available.'], 404);

        return $this->submit($request, $e, null);
    }

    public function submitInvite(Request $request, string $token): JsonResponse
    {
        [$inv, $e] = $this->inviteByToken($token);
        if (! $inv || ! $e) return response()->json(['message' => 'This RSVP link is not available.'], 404);

        return $this->submit($request, $e, $inv);
    }

    /** GET /public/rsvp/optout/{token} — what the unsubscribe page shows before the click. */
    public function optOutInfo(string $token): JsonResponse
    {
        [$inv, $e] = $this->inviteByToken($token, true);
        if (! $inv) return response()->json(['message' => 'This link is not valid.'], 404);
        $agency = DB::table('agencies')->where('id', $inv->agency_id)->first(['name', 'brand_logo_url', 'logo_url', 'brand_primary_color']);

        return response()->json(['email' => $this->mask($inv->email), 'event' => $e->title ?? null,
            'opted_out' => RsvpMail::optedOut((int) $inv->agency_id, $inv->email),
            'agency' => ['name' => $agency->name ?? '', 'logo' => ($agency->brand_logo_url ?? null) ?: ($agency->logo_url ?? null), 'colour' => $agency->brand_primary_color ?? null]]);
    }

    /** POST /public/rsvp/optout/{token} — the person pressed Unsubscribe. */
    public function optOut(Request $request, string $token): JsonResponse
    {
        [$inv, $e] = $this->inviteByToken($token, true);
        if (! $inv) return response()->json(['message' => 'This link is not valid.'], 404);
        $agencyId = (int) $inv->agency_id;
        $already = RsvpMail::optedOut($agencyId, $inv->email);
        if (! $already) {
            DB::table('rsvp_optouts')->insertOrIgnore(['agency_id' => $agencyId, 'email' => $inv->email, 'event_id' => $inv->event_id,
                'invite_id' => $inv->id, 'ip' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        }
        if (! $inv->opted_out_at) {
            DB::table('rsvp_invites')->where('id', $inv->id)->update(['opted_out_at' => now(), 'updated_at' => now()]);
        }
        // Anything not yet sent to this address by this agency stays unsent.
        DB::table('rsvp_invites')->where('agency_id', $agencyId)->where('email', $inv->email)->whereIn('send_status', ['pending', 'queued'])
            ->update(['send_status' => 'skipped', 'send_error' => 'This address has opted out of event invitations.', 'updated_at' => now()]);
        if (! $already && $e) {
            SendRsvpMail::dispatch('optout', (int) $inv->id)->onQueue('mail');
        }
        try {
            \App\Support\Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'rsvp.opted_out', 'entity_type' => 'rsvp_event',
                'entity_id' => (int) $inv->event_id, 'payload' => json_encode(['summary' => $inv->email . ' unsubscribed from event invitations']), 'created_at' => now()]);
        } catch (\Throwable $ex) {
        }

        return response()->json(['ok' => true]);
    }

    /* ═════════════ helpers ═════════════ */

    private function submit(Request $request, object $e, ?object $inv): JsonResponse
    {
        if (trim((string) $request->input('website', '')) !== '') return response()->json(['ok' => true]);   // honeypot
        if ($e->status !== 'open') return response()->json(['message' => 'Registration for this event is closed.'], 422);
        $fields = json_decode((string) $e->fields, true) ?: [];
        $in = $request->input('answers');
        if (! is_array($in)) return response()->json(['message' => 'Please fill in the form.'], 422);
        if ($inv) $in['email'] = $inv->email;   // a personal link answers for the address it was sent to
        $answers = []; $errors = [];
        foreach ($fields as $f) {
            $k = $f['key'];
            $v = $in[$k] ?? null;
            if ($f['type'] === 'checkbox') {
                $v = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                if ($f['required'] && ! $v) $errors[$k] = 'Please tick “' . $f['label'] . '”.';
                $answers[$k] = ['label' => $f['label'], 'value' => $v ? 'Yes' : 'No'];
                continue;
            }
            $v = is_scalar($v) ? trim((string) $v) : '';
            $max = $f['type'] === 'textarea' ? 4000 : 500;
            if (mb_strlen($v) > $max) $errors[$k] = '“' . $f['label'] . '” is too long.';
            if ($v === '') {
                if ($f['required']) $errors[$k] = '“' . $f['label'] . '” is required.';
            } elseif ($f['type'] === 'email' && ! filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $errors[$k] = 'Please enter a valid email address.';
            } elseif ($f['type'] === 'tel' && ! preg_match('/^[0-9+().\-\s]{7,25}(\s*(x|ext\.?)\s*\d{1,6})?$/i', $v)) {
                $errors[$k] = 'Please enter a valid phone number.';
            } elseif ($f['type'] === 'select' && ! in_array($v, $f['options'] ?? [], true)) {
                $errors[$k] = 'Please choose an option for “' . $f['label'] . '”.';
            }
            $answers[$k] = ['label' => $f['label'], 'value' => $v];
        }
        if ($errors) return response()->json(['message' => reset($errors), 'errors' => array_map(fn ($m) => [$m], $errors)], 422);
        $email = strtolower($answers['email']['value']);
        $answers['email']['value'] = $email;
        $name = $this->nameFrom($answers) ?: ($inv->name ?? null);

        $existing = DB::table('rsvp_responses')->where('event_id', $e->id)->where('email', $email)->first();
        if (! $existing && $e->capacity && DB::table('rsvp_responses')->where('event_id', $e->id)->count() >= (int) $e->capacity) {
            return response()->json(['message' => 'Sorry — this event is full.'], 409);
        }
        // Every response hangs off an invite row: a registration through the shareable link
        // finds the invite for that address, or makes one, so the report and the
        // confirmation's unsubscribe link work the same way for everybody.
        if (! $inv) {
            $inv = DB::table('rsvp_invites')->where('event_id', $e->id)->where('email', $email)->first();
            if (! $inv) {
                $iid = DB::table('rsvp_invites')->insertGetId(['agency_id' => $e->agency_id, 'event_id' => $e->id, 'name' => $name, 'email' => $email,
                    'source' => 'public', 'token' => Str::random(40), 'send_status' => 'not_invited', 'created_at' => now(), 'updated_at' => now()]);
                $inv = DB::table('rsvp_invites')->where('id', $iid)->first();
            }
        }
        $row = ['name' => $name ? mb_substr($name, 0, 160) : null, 'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'invite_id' => $inv->id, 'ip' => substr((string) $request->ip(), 0, 45), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'confirm_status' => 'queued', 'notify_status' => 'queued', 'updated_at' => now()];
        if ($existing) {
            DB::table('rsvp_responses')->where('id', $existing->id)->update($row);
            $rid = (int) $existing->id;
        } else {
            $rid = DB::table('rsvp_responses')->insertGetId($row + ['agency_id' => $e->agency_id, 'event_id' => $e->id, 'email' => $email,
                'via' => $request->route('token') === $e->public_token ? 'link' : 'invite', 'created_at' => now()]);
        }
        DB::table('rsvp_invites')->where('id', $inv->id)->update(['registered_at' => $inv->registered_at ?: now(),
            'name' => $inv->name ?: $row['name'], 'updated_at' => now()]);
        SendRsvpMail::dispatch('confirmation', $rid)->onQueue('mail');
        SendRsvpMail::dispatch('notice', $rid, $existing ? 'updated' : 'new')->onQueue('mail');

        return response()->json(['ok' => true, 'updated' => (bool) $existing, 'email' => $email,
            'message' => (string) ($e->confirmation_message ? RsvpMail::fill($e->confirmation_message, $e, $name) : '')], $existing ? 200 : 201);
    }

    private function nameFrom(array $answers): ?string
    {
        foreach (['full_name', 'name'] as $k) {
            if (! empty($answers[$k]['value'])) return (string) $answers[$k]['value'];
        }
        $fn = $answers['first_name']['value'] ?? ''; $ln = $answers['last_name']['value'] ?? '';

        return trim($fn . ' ' . $ln) ?: null;
    }

    private function publicPayload(object $e): array
    {
        $agency = DB::table('agencies')->where('id', $e->agency_id)->first(['name', 'brand_logo_url', 'logo_url', 'brand_primary_color']);
        $count = (int) DB::table('rsvp_responses')->where('event_id', $e->id)->count();
        $fields = array_map(fn ($f) => array_intersect_key($f, array_flip(['key', 'label', 'type', 'required', 'options', 'placeholder', 'default', 'help'])),
            json_decode((string) $e->fields, true) ?: []);

        return [
            'title' => $e->title, 'description' => $e->description, 'when' => RsvpMail::whenLabel($e), 'location' => $e->location,
            'image_url' => $e->image_url, 'fields' => $fields, 'open' => $e->status === 'open',
            'full' => $e->capacity ? $count >= (int) $e->capacity : false,
            'agency' => ['name' => $agency->name ?? '', 'logo' => ($agency->brand_logo_url ?? null) ?: ($agency->logo_url ?? null), 'colour' => $agency->brand_primary_color ?? null],
        ];
    }

    private function eventByToken(string $token): ?object
    {
        if (! preg_match('/^[A-Za-z0-9]{20,48}$/', $token)) return null;
        $e = DB::table('rsvp_events')->where('public_token', $token)->whereNull('deleted_at')->first();

        return ($e && $e->status !== 'draft') ? $e : null;
    }

    /** @return array{0:?object,1:?object} */
    private function inviteByToken(string $token, bool $allowClosed = false): array
    {
        if (! preg_match('/^[A-Za-z0-9]{20,48}$/', $token)) return [null, null];
        $inv = DB::table('rsvp_invites')->where('token', $token)->first();
        if (! $inv) return [null, null];
        $e = DB::table('rsvp_events')->where('id', $inv->event_id)->where('agency_id', $inv->agency_id)->whereNull('deleted_at')->first();
        if (! $allowClosed && (! $e || $e->status === 'draft')) return [null, null];

        return [$inv, $e];
    }

    private function mask(string $email): string
    {
        [$u, $d] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($u, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($u) - 2))) . '@' . $d;
    }

    /** @param int[] $ids */
    private function queue(object $e, array $ids): int
    {
        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('rsvp_invites')->whereIn('id', $chunk)->where('event_id', $e->id)->get(['id', 'email']);
            foreach ($rows as $r) {
                if (RsvpMail::optedOut((int) $e->agency_id, $r->email)) {
                    DB::table('rsvp_invites')->where('id', $r->id)->update(['send_status' => 'skipped', 'send_error' => 'This address has opted out of event invitations.', 'updated_at' => now()]);
                    continue;
                }
                DB::table('rsvp_invites')->where('id', $r->id)->update(['send_status' => 'queued', 'send_error' => null, 'queued_at' => now(), 'updated_at' => now()]);
                SendRsvpMail::dispatch('invite', (int) $r->id)->onQueue('mail');
                $n++;
            }
        }

        return $n;
    }

    private function emptyStats(): array
    {
        return ['invited' => 0, 'queued' => 0, 'sent' => 0, 'not_delivered' => 0, 'opened' => 0, 'registered' => 0, 'opted_out' => 0, 'no_response' => 0, 'pending' => 0];
    }

    /** @param int[] $ids */
    private function statsFor(int $agencyId, array $ids): array
    {
        if (! $ids) return [];
        $rows = DB::table('rsvp_invites')->where('agency_id', $agencyId)->whereIn('event_id', $ids)
            ->selectRaw("event_id,
                SUM(CASE WHEN source <> 'public' THEN 1 ELSE 0 END) invited,
                SUM(CASE WHEN send_status = 'queued' THEN 1 ELSE 0 END) queued,
                SUM(CASE WHEN send_status = 'pending' THEN 1 ELSE 0 END) pending,
                SUM(CASE WHEN send_status = 'sent' THEN 1 ELSE 0 END) sent,
                SUM(CASE WHEN send_status IN ('failed','suppressed','skipped') THEN 1 ELSE 0 END) not_delivered,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) opened,
                SUM(CASE WHEN registered_at IS NOT NULL THEN 1 ELSE 0 END) registered,
                SUM(CASE WHEN opted_out_at IS NOT NULL THEN 1 ELSE 0 END) opted_out,
                SUM(CASE WHEN send_status = 'sent' AND registered_at IS NULL AND opted_out_at IS NULL THEN 1 ELSE 0 END) no_response")
            ->groupBy('event_id')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->event_id] = array_map('intval', array_diff_key((array) $r, ['event_id' => 1]));
        }

        return $out;
    }

    private function reportRows(int $agencyId, object $e, string $view): array
    {
        $tz = AgencyTime::tz($agencyId);
        $fmt = fn ($ts) => $ts ? AgencyTime::fmt($ts, $tz, 'Y-m-d g:i A') : '';
        $q = DB::table('rsvp_invites as i')->leftJoin('rsvp_responses as r', 'r.invite_id', '=', 'i.id')
            ->where('i.event_id', $e->id)->where('i.agency_id', $agencyId);
        match ($view) {
            'sent' => $q->where('i.send_status', 'sent'),
            'registered' => $q->whereNotNull('i.registered_at'),
            'opted_out' => $q->whereNotNull('i.opted_out_at'),
            'no_response' => $q->where('i.send_status', 'sent')->whereNull('i.registered_at')->whereNull('i.opted_out_at'),
            'not_sent' => $q->whereIn('i.send_status', ['pending', 'queued', 'failed', 'suppressed', 'skipped']),
            default => null,
        };
        $list = $q->orderByRaw('COALESCE(i.registered_at, i.sent_at, i.created_at) DESC')
            ->get(['i.*', 'r.answers', 'r.created_at as response_at', 'r.updated_at as response_updated_at']);
        $fields = json_decode((string) $e->fields, true) ?: [];
        $cols = array_map(fn ($f) => ['key' => $f['key'], 'label' => $f['label']], $fields);
        $known = array_flip(array_column($cols, 'key'));
        $label = ['pending' => 'Not sent yet', 'queued' => 'Sending', 'sent' => 'Sent', 'suppressed' => 'Held back', 'failed' => 'Failed',
            'skipped' => 'Not sent (opted out)', 'not_invited' => 'Not invited (used the shareable link)'];
        $rows = [];
        foreach ($list as $i) {
            $ans = [];
            foreach (json_decode((string) $i->answers, true) ?: [] as $k => $a) {
                $ans[$k] = is_array($a) ? (string) ($a['value'] ?? '') : (string) $a;
                if (! isset($known[$k])) { $cols[] = ['key' => $k, 'label' => (string) ($a['label'] ?? $k)]; $known[$k] = 1; }
            }
            $rows[] = [
                'id' => (int) $i->id, 'name' => (string) ($i->name ?? ''), 'email' => $i->email, 'source' => $i->source,
                'status' => $i->send_status, 'status_label' => $label[$i->send_status] ?? $i->send_status,
                'queued_at' => $fmt($i->queued_at), 'sent_at' => $fmt($i->sent_at), 'opened_at' => $fmt($i->opened_at),
                'registered_at' => $fmt($i->registered_at), 'opted_out_at' => $fmt($i->opted_out_at),
                'updated_response_at' => ($i->response_updated_at && $i->response_at && $i->response_updated_at > $i->response_at) ? $fmt($i->response_updated_at) : '',
                'note' => (string) ($i->send_error ?? ''), 'answers' => $ans, 'can_remove' => $i->send_status === 'pending' && ! $i->registered_at && ! $i->opted_out_at,
            ];
        }

        return [$cols, $rows];
    }

    private function decorate(object $e): void
    {
        $tz = AgencyTime::tz((int) $e->agency_id);
        $e->fields = is_string($e->fields) ? (json_decode($e->fields, true) ?: []) : $e->fields;
        $e->public_url = RsvpMail::publicUrl($e->public_token);
        $e->when = RsvpMail::whenLabel($e);
        $e->starts_local = $e->starts_at ? Carbon::parse($e->starts_at, 'UTC')->setTimezone($tz)->format('Y-m-d\TH:i') : '';
        $e->ends_local = $e->ends_at ? Carbon::parse($e->ends_at, 'UTC')->setTimezone($tz)->format('Y-m-d\TH:i') : '';
    }

    private function ownEvent(int $agencyId, int $id): object
    {
        $e = DB::table('rsvp_events')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($e, 404);

        return $e;
    }

    /**
     * The template/event question list, cleaned. Keys are stable slugs; the email field is
     * always present, always type email and always required.
     */
    private function normaliseFields(array $raw): array
    {
        $out = []; $seen = [];
        foreach ($raw as $f) {
            if (! is_array($f)) continue;
            $label = trim(mb_substr((string) ($f['label'] ?? ''), 0, 160));
            if ($label === '') continue;
            $type = in_array($f['type'] ?? '', self::TYPES, true) ? $f['type'] : 'text';
            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string) ($f['key'] ?? '')) ?: '');
            $key = trim($key, '_');
            if ($key === '') $key = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label)), '_') ?: 'field';
            $key = substr($key, 0, 40);
            if ($type === 'email' && ! isset($seen['email']) && ! array_filter($raw, fn ($x) => is_array($x) && ($x['key'] ?? '') === 'email')) $key = 'email';
            $base = $key; $n = 2;
            while (isset($seen[$key])) $key = substr($base, 0, 36) . '_' . $n++;
            $seen[$key] = true;
            $opts = [];
            if ($type === 'select') {
                $src = is_array($f['options'] ?? null) ? $f['options'] : preg_split('/\r?\n|,/', (string) ($f['options'] ?? ''));
                foreach ($src as $o) { $o = trim(mb_substr((string) $o, 0, 120)); if ($o !== '' && ! in_array($o, $opts, true)) $opts[] = $o; }
                if (! $opts) $type = 'text';
            }
            $out[] = ['key' => $key, 'label' => $label, 'type' => $type, 'required' => (bool) ($f['required'] ?? false),
                'options' => $opts, 'placeholder' => mb_substr(trim((string) ($f['placeholder'] ?? '')), 0, 160),
                'default' => mb_substr(trim((string) ($f['default'] ?? '')), 0, 160), 'help' => mb_substr(trim((string) ($f['help'] ?? '')), 0, 300)];
            if (count($out) >= 30) break;
        }
        $hasEmail = false;
        foreach ($out as &$f) {
            if ($f['key'] === 'email') { $f['type'] = 'email'; $f['required'] = true; $hasEmail = true; }
        }
        unset($f);
        if (! $hasEmail) {
            array_splice($out, min(1, count($out)), 0, [['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'options' => [], 'placeholder' => '', 'default' => '', 'help' => '']]);
        }

        return $out;
    }

    /** An image must be one this agency uploaded (or none). */
    private function checkImage(?string $url, int $agencyId): ?string
    {
        $url = trim((string) $url);
        if ($url === '') return null;
        $prefix = rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/') . '/storage/rsvp/' . $agencyId . '/';
        abort_unless(str_starts_with($url, $prefix) && preg_match('#^[A-Za-z0-9]{32}\.(jpe?g|png|gif|webp)$#', substr($url, strlen($prefix))), 422, 'Upload the image here rather than linking to one.');

        return $url;
    }

    /** "guardian:12" / "staff:34" => {user_id, name, email, kind, detail} for this agency. */
    private function agencyContacts(int $agencyId): array
    {
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $out = [];
        $g = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('users as u', 'u.id', '=', 'g.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'f.centre_id')
            ->whereIn('f.centre_id', $centres ?: [0])->whereNull('f.deleted_at')->whereNull('u.deleted_at')
            ->whereNotIn('u.status', ['suspended', 'deactivated'])->whereNotNull('u.email')->where('u.email', '!=', '')
            ->orderBy('u.last_name')->limit(5000)->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'f.family_name', 'c.name as centre']);
        foreach ($g as $r) {
            $out['guardian:' . $r->id] = ['key' => 'guardian:' . $r->id, 'user_id' => (int) $r->id, 'kind' => 'guardian',
                'name' => trim($r->first_name . ' ' . $r->last_name), 'email' => strtolower(trim($r->email)),
                'detail' => trim(($r->family_name ? $r->family_name . ' family' : '') . ($r->centre ? ' · ' . $r->centre : ''), ' ·')];
        }
        $s = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->whereIn('ra.role', ['agency_admin', 'centre_director', 'educator', 'home_visitor'])
            ->where(function ($q) use ($agencyId, $centres) {
                $q->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centres ?: [0]);
            })
            ->whereNull('u.deleted_at')->whereNotIn('u.status', ['suspended', 'deactivated'])->whereNotNull('u.email')->where('u.email', '!=', '')
            ->orderBy('u.last_name')->limit(5000)->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'ra.role']);
        $roleLabel = ['agency_admin' => 'Agency admin', 'centre_director' => 'Director', 'educator' => 'Educator', 'home_visitor' => 'Home visitor'];
        foreach ($s as $r) {
            $k = 'staff:' . $r->id;
            if (isset($out[$k])) continue;
            $out[$k] = ['key' => $k, 'user_id' => (int) $r->id, 'kind' => 'staff', 'name' => trim($r->first_name . ' ' . $r->last_name),
                'email' => strtolower(trim($r->email)), 'detail' => $roleLabel[$r->role] ?? $r->role];
        }

        return $out;
    }

    private function isPlatform(Request $request): bool
    {
        return DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', 1)->exists();
    }

    /**
     * The active agency, and proof the caller administers it. The route group admits any
     * agency_admin; resolveAgencyId honours the header for anybody with ANY role in that
     * agency — so an admin of one agency who is an educator in another must not reach the
     * second agency's RSVPs. Fails closed.
     */
    private function agency(Request $request): int
    {
        $id = (int) $this->resolveAgencyId($request);
        abort_unless($id > 0, 400, 'Select an agency first.');
        $ok = $this->isPlatform($request) || DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')->where('agency_id', $id)->where('active', 1)->exists();
        abort_unless($ok, 403, 'Event RSVPs are managed by this agency’s administrators.');

        return $id;
    }

    private function audit(Request $request, int $agencyId, string $action, string $type, int $id, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => $type, 'entity_id' => $id, 'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }
}
