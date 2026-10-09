<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Jobs\SendRsvpMail;
use App\Support\AgencyTime;
use App\Services\AgencyMailer;
use App\Support\RsvpMail;
use App\Support\RsvpGmail;
use App\Support\RsvpPresets;
use App\Support\RsvpReminders;
use App\Support\RsvpSender;
use Illuminate\Support\Facades\Crypt;
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
 * ROUND 2 (2026-10-06): built-in templates (RsvpPresets) so an event can be made without
 * first creating one; a preview of exactly what recipients get, rendered by the same code
 * that sends it and sending nothing; and per-event SENDER ACCOUNTS (RsvpSender).
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
        $tz = AgencyTime::tz($agencyId);
        $senders = DB::table('rsvp_senders')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('label')->get()
            ->map(fn ($x) => RsvpSender::present($x, $tz))->values();
        $am = AgencyMailer::forAgency($agencyId);
        $out = ['templates' => $rows, 'presets' => array_values(RsvpPresets::all()), 'senders' => $senders,
            'default_from' => ['name' => $am->fromName(), 'address' => $am->fromAddress()],
            'gmail' => $this->gmailInfo(),
            'is_platform' => $this->isPlatform($request)];
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
            'branding' => ['nullable', 'in:agency,none'],
        ]);
        $data['branding'] = $data['branding'] ?? 'agency';
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
            'confirmation_message' => $t->confirmation_message, 'branding' => $t->branding ?? 'agency',
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

    /** "Customize" on a built-in: an agency copy to edit. The built-in itself never changes. */
    public function copyPreset(Request $request, string $key): JsonResponse
    {
        $agencyId = $this->agency($request);
        $p = RsvpPresets::get($key);
        abort_unless($p, 404);
        $name = trim((string) $request->input('name', '')) ?: $p['name'];
        $id = DB::table('rsvp_templates')->insertGetId([
            'agency_id' => $agencyId, 'name' => mb_substr($name, 0, 160), 'description' => $p['description'],
            'fields' => json_encode($this->normaliseFields($p['fields']), JSON_UNESCAPED_UNICODE),
            'image_url' => $p['image_url'], 'email_subject' => $p['email_subject'], 'email_body' => $p['email_body'],
            'confirmation_message' => $p['confirmation_message'], 'notify_email' => null, 'branding' => $p['branding'] ?? 'agency',
            'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($request, $agencyId, 'rsvp.template_saved', 'rsvp_template', $id, 'Saved built-in RSVP template "' . $p['name'] . '" as "' . $name . '"');

        return response()->json(['id' => $id]);
    }

    /** "Save as my template" from an event: its questions, email and image. */
    public function saveEventAsTemplate(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);
        $tid = DB::table('rsvp_templates')->insertGetId([
            'agency_id' => $agencyId, 'name' => trim($data['name']), 'description' => null, 'fields' => $e->fields,
            'image_url' => $e->image_url, 'email_subject' => $e->email_subject, 'email_body' => $e->email_body,
            'confirmation_message' => $e->confirmation_message, 'notify_email' => $e->notify_email, 'branding' => $e->branding ?? 'agency',
            'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($request, $agencyId, 'rsvp.template_saved', 'rsvp_template', $tid, 'Saved the event "' . $e->title . '" as template "' . trim($data['name']) . '"');

        return response()->json(['id' => $tid]);
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
            'template_id' => ['nullable', 'integer'],
            'preset' => ['nullable', 'string', 'max:40'],
            'sender_id' => ['nullable', 'integer'],
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
            'branding' => ['nullable', 'in:agency,none'],
            'show_map' => ['nullable', 'boolean'],
            'map_pin' => ['nullable', 'string', 'max:500'],
            'attend_tips' => ['nullable', 'string', 'max:4000'],
        ], ['notify_email.required' => 'Enter the address that should receive sign-ups.']);
        if (! empty($data['map_pin']) && ! \App\Support\RsvpMap::parsePin($data['map_pin'])) {
            return response()->json(['message' => 'The map pin needs a Google Maps link to the place, or coordinates like 44.0175, -80.0711.'], 422);
        }
        $tz = AgencyTime::tz($agencyId);
        $toUtc = fn ($v) => $v ? Carbon::createFromFormat('Y-m-d\TH:i', $v, $tz)->utc()->format('Y-m-d H:i:s') : null;
        $row = [
            'title' => trim($data['title']), 'description' => $data['description'] ?? null,
            'starts_at' => $toUtc($data['starts_local'] ?? null), 'ends_at' => $toUtc($data['ends_local'] ?? null),
            'location' => $data['location'] ?? null, 'notify_email' => strtolower(trim($data['notify_email'])),
            'status' => $data['status'], 'capacity' => $data['capacity'] ?? null,
        ];
        if (! empty($data['branding'])) $row['branding'] = $data['branding'];
        foreach (['map_pin', 'attend_tips'] as $k) if (array_key_exists($k, $data)) $row[$k] = ($data[$k] ?? '') !== '' ? $data[$k] : null;
        if (array_key_exists('show_map', $data) && $data['show_map'] !== null) $row['show_map'] = (bool) $data['show_map'];
        // The sender must be one of THIS agency's accounts; empty means the agency's own email.
        if (array_key_exists('sender_id', $data)) {
            $sid = (int) ($data['sender_id'] ?? 0);
            if ($sid) abort_unless(RsvpSender::find($agencyId, $sid), 422, 'Choose one of your sender accounts.');
            $row['sender_id'] = $sid ?: null;
        }
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
            /* From one of the agency's templates, or straight from a built-in — no template
               has to exist first. Either way the event takes its own snapshot. */
            if (! empty($data['template_id'])) {
                $t = DB::table('rsvp_templates')->where('id', $data['template_id'])->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
                abort_unless($t, 422, 'Choose one of your templates.');
                $presetKey = null;
            } else {
                $p = RsvpPresets::get($data['preset'] ?? null);
                abort_unless($p, 422, 'Choose a template to start from.');
                $t = (object) ['id' => null, 'fields' => json_encode($p['fields']), 'image_url' => $p['image_url'], 'email_subject' => $p['email_subject'],
                    'email_body' => $p['email_body'], 'confirmation_message' => $p['confirmation_message'], 'branding' => $p['branding'] ?? 'agency'];
                $presetKey = $p['key'];
            }
            $fields = isset($data['fields']) ? $this->normaliseFields($data['fields']) : (json_decode((string) $t->fields, true) ?: []);
            $row['branding'] = $row['branding'] ?? ($t->branding ?? 'agency');
            $id = DB::table('rsvp_events')->insertGetId($row + [
                'agency_id' => $agencyId, 'template_id' => $t->id, 'preset_key' => $presetKey,
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

    /** Open, close or return to draft — without resending every other field of the event. */
    public function setEventStatus(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $data = $request->validate(['status' => ['required', 'in:draft,open,closed']]);
        DB::table('rsvp_events')->where('id', $id)->update(['status' => $data['status'], 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'rsvp.event_saved', 'rsvp_event', $id, 'RSVP event "' . $e->title . '" is now ' . $data['status']);

        return response()->json(['ok' => true, 'status' => $data['status']]);
    }

    /** A copy of the event — its questions, email, image and sender — as a new draft with no invitees. */
    public function duplicateEvent(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $row = (array) $e;
        unset($row['id'], $row['deleted_at']);
        $row['title'] = mb_substr($e->title . ' (copy)', 0, 160);
        $row['status'] = 'draft';
        $row['public_token'] = Str::random(32);
        $row['created_by_id'] = (int) $request->user()->id;
        $row['created_at'] = now();
        $row['updated_at'] = now();
        $new = DB::table('rsvp_events')->insertGetId($row);
        $this->audit($request, $agencyId, 'rsvp.event_saved', 'rsvp_event', $new, 'Duplicated RSVP event "' . $e->title . '" as a draft');

        return response()->json(['id' => $new]);
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

    /** Send one CONFIRMATION (what a registrant receives) to an address the admin names —
        required, never defaulted. Sample answers, marked [Test]; no RSVP is recorded. */
    public function testConfirmation(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $data = $request->validate(['to' => ['required', 'email', 'max:190']]);
        $answers = [];
        foreach (json_decode((string) $e->fields, true) ?: [] as $f) {
            $answers[$f['key']] = ['label' => $f['label'], 'value' => $f['type'] === 'email' ? $data['to'] : ($f['type'] === 'checkbox' ? 'Yes' : 'Sample answer')];
        }
        $resp = (object) ['name' => 'Test Registrant', 'answers' => json_encode($answers)];
        [$subject, $html] = RsvpMail::confirmation($e, $resp, null);
        $r = RsvpMail::send($agencyId, $data['to'], null, '[Test] ' . $subject, $html, ['reply_to' => $e->notify_email, 'sender_id' => $e->sender_id ?? null, 'from' => RsvpMail::fromFor($e)]);
        $this->audit($request, $agencyId, 'rsvp.test_sent', 'rsvp_event', $id, 'Test confirmation for "' . $e->title . '" to ' . $data['to'] . ': ' . $r['status']);

        return response()->json(['sent' => $r['status'] === 'sent', 'status' => $r['status'], 'reason' => $r['error']]);
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
        $r = RsvpMail::send($agencyId, $data['to'], null, '[Test] ' . $subject, $html, ['reply_to' => $e->notify_email, 'sender_id' => $e->sender_id ?? null, 'from' => RsvpMail::fromFor($e)]);
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

    /* ═════════════ preview (sends nothing) ═════════════ */

    /**
     * POST /admin/rsvp/preview — what recipients will get, rendered by the SAME RsvpMail
     * code that sends it, for a saved event, a template, a built-in, or the unsaved values
     * in an open dialog (which override whichever of those it started from). Writes nothing
     * and sends nothing; the links in it carry a token that matches no invite.
     */
    public function preview(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $in = $request->validate([
            'event_id' => ['nullable', 'integer'], 'template_id' => ['nullable', 'integer'], 'preset' => ['nullable', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:4000'],
            'starts_local' => ['nullable', 'date_format:Y-m-d\TH:i'], 'ends_local' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'location' => ['nullable', 'string', 'max:250'], 'image_url' => ['nullable', 'string', 'max:500'],
            'fields' => ['nullable', 'array', 'max:30'], 'email_subject' => ['nullable', 'string', 'max:200'],
            'email_body' => ['nullable', 'string', 'max:8000'], 'confirmation_message' => ['nullable', 'string', 'max:4000'],
            'notify_email' => ['nullable', 'string', 'max:160'], 'sender_id' => ['nullable', 'integer'],
            'branding' => ['nullable', 'in:agency,none'],
            'show_map' => ['nullable', 'boolean'], 'map_pin' => ['nullable', 'string', 'max:500'], 'attend_tips' => ['nullable', 'string', 'max:4000'],
        ]);
        $tz = AgencyTime::tz($agencyId);
        $base = null;
        if (! empty($in['event_id'])) {
            $base = $this->ownEvent($agencyId, (int) $in['event_id']);
        } elseif (! empty($in['template_id'])) {
            $base = DB::table('rsvp_templates')->where('id', $in['template_id'])->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
            abort_unless($base, 404);
        } elseif (! empty($in['preset'])) {
            $p = RsvpPresets::get($in['preset']);
            abort_unless($p, 404);
            $base = (object) $p;
        }
        $base = (array) ($base ?: []);
        // A template's or built-in's description is a note for the team, not the event's
        // public "About" text — only a real event's description belongs on the RSVP page.
        if (empty($in['event_id'])) unset($base['description']);
        $pick = fn ($k, $d = null) => (array_key_exists($k, $in) && $in[$k] !== null && $in[$k] !== '') ? $in[$k] : ($base[$k] ?? $d);
        $toUtc = fn ($v) => $v ? Carbon::createFromFormat('Y-m-d\TH:i', $v, $tz)->utc()->format('Y-m-d H:i:s') : null;
        $fields = $pick('fields', []);
        $fields = $this->normaliseFields(is_string($fields) ? (json_decode($fields, true) ?: []) : $fields);
        $image = (string) $pick('image_url', '');
        try { $image = $this->checkImage($image, $agencyId); } catch (\Throwable $ex) { $image = null; }
        $e = (object) [
            'id' => 0, 'agency_id' => $agencyId,
            'title' => (string) ($pick('title') ?: ($base['name'] ?? 'Your event')),
            'description' => $pick('description'), 'location' => $pick('location'),
            'starts_at' => ! empty($in['starts_local']) ? $toUtc($in['starts_local']) : ($base['starts_at'] ?? null),
            'ends_at' => ! empty($in['ends_local']) ? $toUtc($in['ends_local']) : ($base['ends_at'] ?? null),
            'image_url' => $image, 'fields' => json_encode($fields), 'status' => 'open', 'capacity' => null,
            'email_subject' => (string) ($pick('email_subject') ?: '{{event}}'),
            'email_body' => (string) ($pick('email_body') ?: ''), 'confirmation_message' => $pick('confirmation_message'),
            'notify_email' => $pick('notify_email'), 'public_token' => 'preview',
        ];
        $sid = array_key_exists('sender_id', $in) ? (int) ($in['sender_id'] ?? 0) : (int) ($base['sender_id'] ?? 0);
        $e->sender_id = $sid ?: null;
        $e->branding = $pick('branding', 'agency') === 'none' ? 'none' : 'agency';
        $e->show_map = array_key_exists('show_map', $in) && $in['show_map'] !== null ? (bool) $in['show_map'] : (bool) ($base['show_map'] ?? true);
        $e->map_pin = $pick('map_pin');
        $e->attend_tips = $pick('attend_tips');
        $sample = (object) ['name' => 'Alex Sample', 'email' => 'alex.sample@example.com', 'token' => 'preview'];
        [$subject, $html] = RsvpMail::invite($e, $sample);
        $answers = [];
        foreach ($fields as $f) {
            $answers[$f['key']] = ['label' => $f['label'], 'value' => match (true) {
                $f['key'] === 'email' => $sample->email,
                in_array($f['key'], ['full_name', 'name'], true) => $sample->name,
                $f['type'] === 'tel' => '519-555-0142',
                $f['type'] === 'checkbox' => 'Yes',
                $f['type'] === 'select' => (string) ($f['options'][0] ?? ''),
                $f['type'] === 'textarea' => 'An example answer, as a registrant might write it.',
                default => $f['default'] !== '' ? $f['default'] : 'Example answer',
            }];
        }
        [$cSubject, $cHtml] = RsvpMail::confirmation($e, (object) ['name' => $sample->name, 'answers' => json_encode($answers)], $sample);

        // Who it is from: the event's sender account, or the agency's own email; unbranded
        // names itself, exactly as RsvpMail::fromFor() will when it sends.
        $sender = $sid ? RsvpSender::find($agencyId, $sid) : null;
        $am = AgencyMailer::forAgency($agencyId);
        $from = $sender ? ['name' => $sender->from_name ?: '', 'address' => $sender->from_address ?: '(connect Gmail first)', 'account' => $sender->label]
            : ['name' => $am->fromName(), 'address' => $am->fromAddress(), 'account' => 'Agency default email'];
        if ($f = RsvpMail::fromFor($e)) { $from['name'] = $f[1]; $from['address'] = $f[0]; }

        $warnings = [];
        if (! filter_var((string) $e->notify_email, FILTER_VALIDATE_EMAIL)) $warnings[] = 'No address is set to receive sign-ups yet.';
        if (! $e->starts_at) $warnings[] = 'The event has no date yet, so the email shows no “When”.';
        if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $e->email_subject . ' ' . $e->email_body . ' ' . $e->confirmation_message, $m)) {
            $unknown = array_diff(array_unique(array_map('strtolower', $m[1])), ['name', 'first_name', 'event', 'date', 'location', 'agency']);
            if ($unknown) $warnings[] = 'Unknown placeholder(s) left as typed: {{' . implode('}}, {{', $unknown) . '}}.';
        }
        if ($sid && ! $sender) $warnings[] = 'The chosen sender account no longer exists.';
        if ($sender && RsvpSender::isGmail($sender) && empty($sender->google_refresh_enc)) $warnings[] = 'The Gmail sender “' . $sender->label . '” is not connected yet — connect it on the Sender accounts tab before sending.';
        if ($sender && RsvpSender::isGmail($sender) && ($sender->google_status ?? '') === 'needs_reconnect') $warnings[] = 'The Gmail sender “' . $sender->label . '” needs reconnecting before it can send.';
        if (RsvpMail::unbranded($e) && ! $sender) $warnings[] = 'Unbranded, but with no sender account the email still comes from the platform address ' . $am->fromAddress() . '. Choose a sender account to send from your own address.';
        if (RsvpMail::unbranded($e) && preg_match('/\{\{\s*agency\s*\}\}/i', $e->email_subject . ' ' . $e->email_body . ' ' . $e->confirmation_message)) $warnings[] = 'Unbranded: {{agency}} shows the sender account’s name, or the event title.';

        $out = [
            'invite' => ['subject' => $subject, 'html' => $html],
            'confirmation' => ['subject' => $cSubject, 'html' => $cHtml],
            'from' => $from, 'reply_to' => $e->notify_email, 'to' => $sample->name . ' <' . $sample->email . '>',
            'form' => $this->publicPayload($e), 'branding' => $e->branding,
            'warnings' => $warnings,
        ];
        if (! empty($in['event_id'])) {
            $out['pending'] = (int) DB::table('rsvp_invites')->where('event_id', (int) $in['event_id'])->where('agency_id', $agencyId)->where('send_status', 'pending')->count();
            $out['status'] = $base['status'] ?? null;
        }

        return response()->json($out);
    }

    /* ═════════════ sender accounts ═════════════ */

    public function senders(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $tz = AgencyTime::tz($agencyId);
        $rows = DB::table('rsvp_senders')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('label')->get();
        $used = DB::table('rsvp_events')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNotNull('sender_id')
            ->selectRaw('sender_id, COUNT(*) c')->groupBy('sender_id')->pluck('c', 'sender_id');

        return response()->json(['senders' => $rows->map(function ($x) use ($tz, $used) {
            return RsvpSender::present($x, $tz) + ['events' => (int) ($used[$x->id] ?? 0)];
        })->values(), 'gmail' => $this->gmailInfo()]);
    }

    private function gmailInfo(): array
    {
        return ['configured' => RsvpGmail::configured(), 'client' => RsvpGmail::clientKind(), 'redirect_uri' => RsvpGmail::redirectUri()];
    }

    /**
     * A sender is 'gmail_oauth' (connect by signing in — the only kind that can send from
     * this host, which blocks outbound SMTP) or 'smtp' (kept for a host that allows it).
     * A Gmail sender's address comes from Google when it is connected.
     */
    public function saveSender(Request $request, ?int $id = null): JsonResponse
    {
        $agencyId = $this->agency($request);
        $old = $id ? RsvpSender::find($agencyId, $id) : null;
        if ($id) abort_unless($old, 404);
        $type = $old ? (($old->type ?? 'smtp') === 'gmail_oauth' ? 'gmail_oauth' : 'smtp') : ($request->input('type') === 'smtp' ? 'smtp' : 'gmail_oauth');
        $rules = [
            'label' => ['required', 'string', 'max:120'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email', 'max:190'],
        ];
        if ($type === 'smtp') {
            $rules += [
                'from_address' => ['required', 'email', 'max:190'],
                'smtp_host' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-]+$/'],
                'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
                'smtp_encryption' => ['required', 'in:' . implode(',', RsvpSender::ENCRYPTIONS)],
                'smtp_user' => ['nullable', 'string', 'max:190'],
                'smtp_password' => ['nullable', 'string', 'max:500'],
            ];
        }
        $data = $request->validate($rules, ['smtp_host.regex' => 'Enter just the server name, e.g. smtp.gmail.com.']);
        $row = [
            'type' => $type, 'label' => trim($data['label']), 'from_name' => isset($data['from_name']) ? trim($data['from_name']) : null,
            'reply_to' => isset($data['reply_to']) ? strtolower(trim($data['reply_to'])) : null, 'updated_at' => now(),
        ];
        $pw = '';
        if ($type === 'smtp') {
            $row += [
                'from_address' => strtolower(trim($data['from_address'])), 'smtp_host' => strtolower(trim($data['smtp_host'])),
                'smtp_port' => (int) $data['smtp_port'], 'smtp_encryption' => $data['smtp_encryption'],
                'smtp_user' => isset($data['smtp_user']) ? trim($data['smtp_user']) : null,
            ];
            // Blank keeps the saved password; a new one replaces it. Never echoed back.
            $pw = (string) ($data['smtp_password'] ?? '');
            if ($pw !== '') $row['smtp_pass_enc'] = Crypt::encryptString($pw);
        }
        if ($old) {
            if ($type === 'smtp') {
                // A changed server, login or password has not been checked yet.
                foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user'] as $k) {
                    if ((string) $old->$k !== (string) $row[$k]) $row['verified_at'] = null;
                }
                if ($pw !== '') $row['verified_at'] = null;
            }
            DB::table('rsvp_senders')->where('id', $id)->update($row);
        } else {
            if ($type === 'gmail_oauth') { $row['smtp_port'] = 587; $row['smtp_encryption'] = 'tls'; $row['last_test_status'] = 'not_connected'; }
            $id = DB::table('rsvp_senders')->insertGetId($row + ['agency_id' => $agencyId, 'created_by_id' => (int) $request->user()->id, 'created_at' => now()]);
        }
        $this->audit($request, $agencyId, 'rsvp.sender_saved', 'agency', $agencyId, 'Saved RSVP sender account "' . $row['label'] . '" ('
            . ($type === 'gmail_oauth' ? 'Gmail' : (($row['from_address'] ?? '') . ' via ' . ($row['smtp_host'] ?? ''))) . ')' . ($pw !== '' ? ', password updated' : ''));

        return response()->json(['id' => $id, 'type' => $type]);
    }

    /** GET /admin/rsvp/senders/{id}/google/start — the Google sign-in URL for this sender. */
    public function googleStart(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $s = RsvpSender::find($agencyId, $id);
        abort_unless($s, 404);
        abort_unless(RsvpSender::isGmail($s), 422, 'This account is not a Gmail sender.');
        if (! RsvpGmail::configured()) {
            return response()->json(['message' => 'Gmail connection isn’t set up yet — the platform admin needs to add a Google client.', 'not_configured' => true], 422);
        }
        $this->audit($request, $agencyId, 'rsvp.sender_connect_started', 'agency', $agencyId, 'Started connecting Gmail for RSVP sender "' . $s->label . '"');

        return response()->json(['url' => RsvpGmail::authUrl($s, $agencyId, (int) $request->user()->id), 'redirect_uri' => RsvpGmail::redirectUri()]);
    }

    /**
     * GET /rsvp/google/callback (https://api.kiddietrac.com/api/v1/rsvp/google/callback, the
     * URI registered in Google Cloud) — where Google sends the browser back. No login here:
     * the signed, single-use state is the proof of who started it. Always ends by sending
     * the browser back to Event RSVPs with a result flag, never on a bare error page.
     */
    public function googleCallback(Request $request)
    {
        $back = fn (string $r, string $extra = '') => redirect()->away(RsvpMail::APP . '/dashboard.html?rsvp_gmail=' . $r . $extra . '#rsvp');
        $st = RsvpGmail::readState($request->query('state'));
        if (! $st) return $back('state');
        if ($request->query('error')) return $back($request->query('error') === 'access_denied' ? 'denied' : 'google');
        $code = (string) $request->query('code', '');
        if ($code === '' || strlen($code) > 2048) return $back('google');
        // The person who started it must still administer that agency, and the sender must still be theirs.
        $admin = DB::table('role_assignments')->where('user_id', $st['u'])->where('active', 1)
            ->where(function ($q) use ($st) {
                $q->where('role', 'platform_admin')->orWhere(function ($q2) use ($st) { $q2->where('role', 'agency_admin')->where('agency_id', $st['a']); });
            })->exists();
        if (! $admin) return $back('state');
        $s = RsvpSender::find($st['a'], $st['s']);
        if (! $s || ! RsvpSender::isGmail($s)) return $back('sender_gone');
        if (! RsvpGmail::configured()) return $back('not_configured');
        try {
            $r = RsvpGmail::connect($s, $code);
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'reason' => 'exchange'];
        }
        try {
            \App\Support\Audit::write(['user_id' => $st['u'], 'agency_id' => $st['a'], 'action' => 'rsvp.sender_connected', 'entity_type' => 'agency', 'entity_id' => $st['a'],
                'payload' => json_encode(['summary' => $r['ok'] ? 'Connected Gmail ' . $r['email'] . ' to RSVP sender "' . $s->label . '"' : 'Connecting Gmail to RSVP sender "' . $s->label . '" failed (' . $r['reason'] . ')']),
                'created_at' => now()]);
        } catch (\Throwable $e) {
        }
        if (! $r['ok']) return $back((string) $r['reason']);

        return $back('connected', '&email=' . rawurlencode($r['email']) . (! empty($r['changed_from']) ? '&was=' . rawurlencode($r['changed_from']) : ''));
    }

    public function deleteSender(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $s = RsvpSender::find($agencyId, $id);
        abort_unless($s, 404);
        $inUse = DB::table('rsvp_events')->where('agency_id', $agencyId)->where('sender_id', $id)->whereNull('deleted_at')->whereIn('status', ['open', 'draft'])->count();
        if ($inUse) {
            return response()->json(['message' => $inUse . ' open or draft event(s) send through this account. Switch them to another account first.'], 422);
        }
        RsvpGmail::revoke($s);
        DB::table('rsvp_senders')->where('id', $id)->update(['deleted_at' => now(), 'smtp_pass_enc' => null, 'google_refresh_enc' => null, 'google_access_enc' => null]);
        $this->audit($request, $agencyId, 'rsvp.sender_deleted', 'agency', $agencyId, 'Deleted RSVP sender account "' . $s->label . '"');

        return response()->json(['ok' => true]);
    }

    /** Connect and log in, sending nothing. */
    public function checkSender(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $s = RsvpSender::find($agencyId, $id);
        abort_unless($s, 404);
        $r = RsvpSender::check($s);
        DB::table('rsvp_senders')->where('id', $id)->update(['verified_at' => $r['ok'] ? now() : null,
            'last_test_status' => $r['ok'] ? 'connected' : 'connect_failed', 'last_test_error' => $r['error'], 'last_test_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'rsvp.sender_checked', 'agency', $agencyId, 'Checked RSVP sender account "' . $s->label . '": ' . ($r['ok'] ? 'connected' : 'failed'));

        return response()->json($r);
    }

    /** One real message to an address the admin types — required, never defaulted. */
    public function testSender(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $s = RsvpSender::find($agencyId, $id);
        abort_unless($s, 404);
        $data = $request->validate(['to' => ['required', 'email', 'max:190']]);
        $agency = (string) DB::table('agencies')->where('id', $agencyId)->value('name');
        $html = \App\Services\EmailTemplate::wrap($agencyId, '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">This is a test from the KiddieTrac sender account <strong>' . e($s->label) . '</strong> for ' . e($agency) . '.</p>'
            . '<p style="margin:0;font-size:15px;color:#334155;line-height:1.6">If you are reading this, event invitations sent through this account will arrive from <strong>' . e($s->from_address) . '</strong>.</p>',
            ['title' => 'Sender account test', 'preheader' => 'Test message']);
        $r = RsvpMail::send($agencyId, $data['to'], null, '[Test] KiddieTrac event sender: ' . $s->label, $html, ['reply_to' => $s->reply_to, 'sender_id' => $s->id]);
        DB::table('rsvp_senders')->where('id', $id)->update(['last_test_status' => $r['status'], 'last_test_error' => $r['error'], 'last_test_at' => now(),
            'verified_at' => $r['status'] === 'sent' ? now() : $s->verified_at, 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'rsvp.sender_test', 'agency', $agencyId, 'Test message through RSVP sender "' . $s->label . '" to ' . $data['to'] . ': ' . $r['status']);

        return response()->json(['sent' => $r['status'] === 'sent', 'status' => $r['status'], 'reason' => $r['error']]);
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
        $head = ['Name', 'Email', 'Source', 'Invitation', 'Queued (' . $tz . ')', 'Sent (' . $tz . ')', 'Email opened (' . $tz . ')', 'Times opened', 'Clicked RSVP link (' . $tz . ')', 'Last reminder (' . $tz . ')',
            'Signed up (' . $tz . ')', 'Opted out (' . $tz . ')', 'Note'];
        foreach ($cols as $c) $head[] = $c['label'];
        fputcsv($out, $head);
        foreach ($rows as $r) {
            $line = [$cell($r['name']), $cell($r['email']), $r['source'], $r['status_label'], $r['queued_at'], $r['sent_at'], $r['email_opened_at'], $r['email_opens'] ?: '', $r['opened_at'], $r['reminded_at'],
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
        if ($e && RsvpMail::unbranded($e)) {
            return response()->json(['email' => $this->mask($inv->email), 'event' => $e->title, 'branded' => false, 'agency' => null,
                'opted_out' => RsvpMail::optedOut((int) $inv->agency_id, $inv->email)]);
        }
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
        $branded = ! RsvpMail::unbranded($e);
        $agency = $branded ? DB::table('agencies')->where('id', $e->agency_id)->first(['name', 'brand_logo_url', 'logo_url', 'brand_primary_color']) : null;
        $count = (int) DB::table('rsvp_responses')->where('event_id', $e->id)->count();
        $fields = array_map(fn ($f) => array_intersect_key($f, array_flip(['key', 'label', 'type', 'required', 'options', 'placeholder', 'default', 'help'])),
            json_decode((string) $e->fields, true) ?: []);

        return [
            'title' => $e->title, 'description' => $e->description, 'when' => RsvpMail::whenLabel($e), 'location' => $e->location,
            'image_url' => $e->image_url, 'fields' => $fields, 'open' => $e->status === 'open',
            'full' => $e->capacity ? $count >= (int) $e->capacity : false,
            // Unbranded: no agency name, logo or colour reaches the page at all.
            'branded' => $branded,
            'agency' => $branded ? ['name' => $agency->name ?? '', 'logo' => ($agency->brand_logo_url ?? null) ?: ($agency->logo_url ?? null), 'colour' => $agency->brand_primary_color ?? null] : null,
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
        /* PACED when the event sends through a personal mailbox. Fifty identical messages
           leaving a new Gmail account in the same second is the pattern its filters treat
           as spam; one every few seconds is not. Agency mail is left as it was. */
        $paced = false;
        if (! empty($e->sender_id)) {
            $paced = DB::table('rsvp_senders')->where('id', $e->sender_id)->where('agency_id', $e->agency_id)->value('type') === 'gmail_oauth';
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('rsvp_invites')->whereIn('id', $chunk)->where('event_id', $e->id)->get(['id', 'email']);
            foreach ($rows as $r) {
                if (RsvpMail::optedOut((int) $e->agency_id, $r->email)) {
                    DB::table('rsvp_invites')->where('id', $r->id)->update(['send_status' => 'skipped', 'send_error' => 'This address has opted out of event invitations.', 'updated_at' => now()]);
                    continue;
                }
                DB::table('rsvp_invites')->where('id', $r->id)->update(['send_status' => 'queued', 'send_error' => null, 'queued_at' => now(), 'updated_at' => now()]);
                $job = SendRsvpMail::dispatch('invite', (int) $r->id)->onQueue('mail');
                if ($paced && $n > 0) { $job->delay(now()->addSeconds(min($n * 6, 6 * 3600))); }
                $n++;
            }
        }

        return $n;
    }

    private function emptyStats(): array
    {
        return ['invited' => 0, 'queued' => 0, 'sent' => 0, 'not_delivered' => 0, 'opened' => 0, 'email_opened' => 0, 'registered' => 0, 'opted_out' => 0, 'no_response' => 0, 'pending' => 0];
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
        $opened = DB::table('rsvp_invites as i')
            ->join('email_logs as el', function ($j) use ($agencyId) {
                $j->on('el.id', '=', 'i.email_log_id')->where('el.agency_id', '=', $agencyId);
            })
            ->where('i.agency_id', $agencyId)->whereIn('i.event_id', $ids)->whereNotNull('el.opened_at')
            ->groupBy('i.event_id')->selectRaw('i.event_id, COUNT(*) n')->pluck('n', 'event_id');
        foreach ($out as $eid => $v) {
            $out[$eid]['email_opened'] = (int) ($opened[$eid] ?? 0);
        }

        return $out;
    }

    private function reportRows(int $agencyId, object $e, string $view): array
    {
        $tz = AgencyTime::tz($agencyId);
        $fmt = fn ($ts) => $ts ? AgencyTime::fmt($ts, $tz, 'Y-m-d g:i A') : '';
        $q = DB::table('rsvp_invites as i')->leftJoin('rsvp_responses as r', 'r.invite_id', '=', 'i.id')
            /* "Email opened" is the open recorded on the invitation's own email_logs row (the
               tracking image); "Clicked RSVP link" is i.opened_at, set by the RSVP page. */
            ->leftJoin('email_logs as el', function ($j) use ($agencyId) {
                $j->on('el.id', '=', 'i.email_log_id')->where('el.agency_id', '=', $agencyId);
            })
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
            ->get(['i.*', 'r.answers', 'r.created_at as response_at', 'r.updated_at as response_updated_at', 'el.opened_at as email_opened_at', 'el.opens as email_opens']);
        $fields = json_decode((string) $e->fields, true) ?: [];
        $cols = array_map(fn ($f) => ['key' => $f['key'], 'label' => $f['label']], $fields);
        $known = array_flip(array_column($cols, 'key'));
        $reminded = DB::table('rsvp_reminder_sends')->where('agency_id', $agencyId)->where('event_id', $e->id)->where('status', 'sent')
            ->groupBy('invite_id')->selectRaw('invite_id, MAX(sent_at) last_at, COUNT(*) n')->get()->keyBy('invite_id');
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
                'email_opened_at' => $fmt($i->email_opened_at ?? null), 'email_opens' => (int) ($i->email_opens ?? 0),
                'reminded_at' => isset($reminded[$i->id]) ? $fmt($reminded[$i->id]->last_at) : '', 'reminders' => isset($reminded[$i->id]) ? (int) $reminded[$i->id]->n : 0,
                'registered_at' => $fmt($i->registered_at), 'opted_out_at' => $fmt($i->opted_out_at),
                'updated_response_at' => ($i->response_updated_at && $i->response_at && $i->response_updated_at > $i->response_at) ? $fmt($i->response_updated_at) : '',
                'note' => (string) ($i->send_error ?? ''), 'answers' => $ans, 'can_remove' => $i->send_status === 'pending' && ! $i->registered_at && ! $i->opted_out_at,
            ];
        }

        return [$cols, $rows];
    }

    /* ═════════════ reminders (2026-10-06) ═════════════ */

    /** The event's reminders, plus how many people each audience would reach right now. */
    public function reminders(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $tz = AgencyTime::tz($agencyId);
        $rows = DB::table('rsvp_reminders')->where('event_id', $e->id)->where('agency_id', $agencyId)->orderByRaw("FIELD(status,'scheduled','sending','sent','skipped','cancelled')")->orderBy('id')->get();
        $out = [];
        foreach ($rows as $r) {
            $due = RsvpReminders::dueAt($r, $e);
            $out[] = [
                'id' => (int) $r->id, 'audience' => $r->audience, 'field_key' => $r->field_key ?? null, 'offset_minutes' => $r->offset_minutes !== null ? (int) $r->offset_minutes : null,
                'offset_label' => RsvpReminders::offsetLabel($r->offset_minutes !== null ? (int) $r->offset_minutes : null),
                'send_local' => $r->send_at ? Carbon::parse($r->send_at, 'UTC')->setTimezone($tz)->format('Y-m-d\TH:i') : '',
                'due_label' => $due ? AgencyTime::fmt($due, $tz, 'D M j, g:i A') : '',
                'due_past' => $due ? $due->isPast() : false,
                'subject' => $r->subject, 'message' => $r->message, 'status' => $r->status, 'status_note' => $r->status_note, 'manual' => (bool) $r->manual,
                'recipient_count' => (int) $r->recipient_count, 'sent_count' => (int) $r->sent_count, 'failed_count' => (int) $r->failed_count,
                'sent_label' => $r->sent_at ? AgencyTime::fmt($r->sent_at, $tz, 'D M j, g:i A') : '',
                '_due' => $r->sent_at ? strtotime($r->sent_at . ' UTC') : ($due ? $due->getTimestamp() : PHP_INT_MAX),
            ];
        }
        // Upcoming first, soonest first; then what has gone out, newest first.
        $rank = ['scheduled' => 0, 'sending' => 1, 'sent' => 2, 'skipped' => 3, 'cancelled' => 4];
        usort($out, function ($a, $b) use ($rows, $e, $rank) {
            $ra = $rank[$a['status']] ?? 9; $rb = $rank[$b['status']] ?? 9;
            if ($ra !== $rb) return $ra <=> $rb;
            $ta = $a['_due'] ?? 0; $tb = $b['_due'] ?? 0;
            return $ra === 0 ? $ta <=> $tb : $tb <=> $ta;
        });
        foreach ($out as &$o) unset($o['_due']);
        unset($o);
        $reach = [];
        foreach (RsvpReminders::AUDIENCES as $a) $reach[$a] = count(RsvpReminders::recipients($e, $a));
        // Per question, for the "left a question blank" picker.
        $reach['incomplete_by_field'] = [];
        foreach (json_decode((string) ($e->fields ?? ''), true) ?: [] as $f) {
            if (! empty($f['key'])) $reach['incomplete_by_field'][$f['key']] = count(RsvpReminders::recipients($e, 'incomplete', (string) $f['key']));
        }

        return response()->json(['reminders' => $out, 'reach' => $reach, 'has_start' => ! empty($e->starts_at), 'tz' => $tz,
            'defaults' => ['subject' => RsvpMail::REMINDER_SUBJECT, 'registered' => RsvpMail::REMINDER_REGISTERED, 'no_response' => RsvpMail::REMINDER_NO_RESPONSE, 'incomplete' => RsvpMail::REMINDER_INCOMPLETE],
            'blocker' => RsvpReminders::blocker($e)]);
    }

    /** Validated reminder fields from a request; a schedule is an offset OR a local time. */
    private function reminderInput(Request $request, object $e, int $agencyId, bool $needSchedule): array
    {
        $d = $request->validate([
            'audience' => ['required', 'in:registered,no_response,both,incomplete'],
            'field_key' => ['nullable', 'string', 'max:60'],
            'offset_minutes' => ['nullable', 'integer', 'min:5', 'max:129600'],
            'send_local' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:4000'],
        ]);
        $row = ['audience' => $d['audience'], 'subject' => trim((string) ($d['subject'] ?? '')) ?: null, 'message' => trim((string) ($d['message'] ?? '')) ?: null];
        // Which question an "incomplete" reminder chases (null = any). Must be one of the event's.
        $fk = $d['audience'] === 'incomplete' ? (trim((string) ($d['field_key'] ?? '')) ?: null) : null;
        if ($fk) {
            $keys = array_column(json_decode((string) ($e->fields ?? ''), true) ?: [], 'key');
            abort_unless(in_array($fk, $keys, true), 422, 'That question is not on this event’s form.');
        }
        $row['field_key'] = $fk;
        if (! $needSchedule) return $row;
        if (! empty($d['offset_minutes'])) {
            abort_unless(! empty($e->starts_at), 422, 'Give the event a date and time first — a reminder "before the event" needs to know when it starts.');
            $row['offset_minutes'] = (int) $d['offset_minutes'];
            $row['send_at'] = null;
            abort_if(Carbon::parse($e->starts_at, 'UTC')->subMinutes($row['offset_minutes'])->isPast(), 422, 'That time has already passed. Pick a shorter time before the event, or use “Send a reminder now”.');
        } elseif (! empty($d['send_local'])) {
            $at = Carbon::createFromFormat('Y-m-d\TH:i', $d['send_local'], AgencyTime::tz($agencyId))->utc();
            abort_if($at->isPast(), 422, 'Pick a time in the future.');
            abort_if(! empty($e->starts_at) && $at->gte(Carbon::parse($e->starts_at, 'UTC')), 422, 'Pick a time before the event starts.');
            $row['send_at'] = $at->format('Y-m-d H:i:s');
            $row['offset_minutes'] = null;
        } else {
            abort(422, 'Choose when the reminder goes out.');
        }

        return $row;
    }

    public function saveReminder(Request $request, int $id, ?int $rid = null): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $row = $this->reminderInput($request, $e, $agencyId, true);
        if ($rid) {
            $r = DB::table('rsvp_reminders')->where('id', $rid)->where('event_id', $e->id)->where('agency_id', $agencyId)->first();
            abort_unless($r, 404);
            abort_unless($r->status === 'scheduled', 422, 'Only a reminder that has not gone out can be changed.');
            DB::table('rsvp_reminders')->where('id', $rid)->update($row + ['updated_at' => now()]);
        } else {
            abort_if(DB::table('rsvp_reminders')->where('event_id', $e->id)->where('status', 'scheduled')->count() >= 20, 422, 'An event can have up to 20 scheduled reminders.');
            $rid = DB::table('rsvp_reminders')->insertGetId($row + ['agency_id' => $agencyId, 'event_id' => $e->id, 'status' => 'scheduled',
                'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $r = DB::table('rsvp_reminders')->where('id', $rid)->first();
        $due = RsvpReminders::dueAt($r, $e);
        $this->audit($request, $agencyId, 'rsvp.reminder_saved', 'rsvp_event', $e->id, 'Reminder for "' . $e->title . '" scheduled for '
            . ($due ? AgencyTime::fmt($due, AgencyTime::tz($agencyId), 'D M j, g:i A') : '?') . ' (' . $r->audience . ')');

        return response()->json(['id' => (int) $rid]);
    }

    public function cancelReminder(Request $request, int $id, int $rid): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $n = DB::table('rsvp_reminders')->where('id', $rid)->where('event_id', $e->id)->where('agency_id', $agencyId)->where('status', 'scheduled')
            ->update(['status' => 'cancelled', 'status_note' => 'Cancelled by ' . trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? '')), 'updated_at' => now()]);
        abort_unless($n, 422, 'Only a reminder that has not gone out can be cancelled.');
        $this->audit($request, $agencyId, 'rsvp.reminder_cancelled', 'rsvp_event', $e->id, 'Cancelled a reminder for "' . $e->title . '"');

        return response()->json(['ok' => true]);
    }

    /** Send a reminder now — a saved one (rid) or a new one-off from the request. */
    public function sendReminderNow(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $why = RsvpReminders::blocker($e);
        abort_if($why, 422, $why . ' Reminders go out only for an open event that has not started.');
        $rid = (int) $request->input('reminder_id', 0);
        if ($rid) {
            $r = DB::table('rsvp_reminders')->where('id', $rid)->where('event_id', $e->id)->where('agency_id', $agencyId)->first();
            abort_unless($r && $r->status === 'scheduled', 422, 'That reminder has already gone out or was cancelled.');
            DB::table('rsvp_reminders')->where('id', $rid)->update(['manual' => true, 'updated_at' => now()]);
        } else {
            $row = $this->reminderInput($request, $e, $agencyId, false);
            $rid = DB::table('rsvp_reminders')->insertGetId($row + ['agency_id' => $agencyId, 'event_id' => $e->id, 'status' => 'scheduled', 'manual' => true,
                'send_at' => now()->format('Y-m-d H:i:s'), 'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $res = RsvpReminders::run($rid);
        $this->audit($request, $agencyId, 'rsvp.reminder_sent', 'rsvp_event', $e->id, 'Reminder for "' . $e->title . '" sent now: ' . $res['queued'] . ' queued' . ($res['note'] ? ' (' . $res['note'] . ')' : ''));

        return response()->json($res + ['id' => $rid]);
    }

    /** What a reminder looks like (nothing is sent) and how many it would reach now. */
    public function previewReminder(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $rem = (object) $this->reminderInput($request, $e, $agencyId, false);
        $inv = (object) ['name' => 'Alex Sample', 'email' => 'alex.sample@example.com', 'token' => 'preview'];
        $out = ['recipients' => count(RsvpReminders::recipients($e, $rem->audience, $rem->field_key ?? null))];
        if ($rem->audience !== 'no_response') {
            [$s, $h] = RsvpMail::reminder($e, $inv, $rem, $this->sampleRegistrant($e));
            $out['registered'] = ['subject' => $s, 'html' => $h];
        }
        if (! in_array($rem->audience, ['registered', 'incomplete'], true)) {   // "incomplete" people have signed up
            [$s, $h] = RsvpMail::reminder($e, $inv, $rem, null);
            $out['no_response'] = ['subject' => $s, 'html' => $h];
        }
        $out['from'] = RsvpMail::fromFor($e);

        return response()->json($out);
    }

    /** One copy to an address the admin names — required, never defaulted. Marked [Test]. */
    public function testReminder(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $e = $this->ownEvent($agencyId, $id);
        $to = (string) $request->validate(['to' => ['required', 'email', 'max:190']])['to'];
        $rem = (object) $this->reminderInput($request, $e, $agencyId, false);
        $inv = (object) ['name' => 'Test Registrant', 'email' => $to, 'token' => 'test'];
        [$subject, $html] = RsvpMail::reminder($e, $inv, $rem, $rem->audience === 'no_response' ? null : $this->sampleRegistrant($e, $to));
        // A test must not hand out a live link, so its buttons open the shareable page.
        $html = str_replace([RsvpMail::rsvpUrl('test'), RsvpMail::optOutUrl('test')], [RsvpMail::publicUrl($e->public_token), RsvpMail::publicUrl($e->public_token)], $html);
        $r = RsvpMail::send($agencyId, $to, null, '[Test] ' . $subject, $html, ['reply_to' => $e->notify_email, 'sender_id' => $e->sender_id ?? null, 'from' => RsvpMail::fromFor($e)]);
        $this->audit($request, $agencyId, 'rsvp.test_sent', 'rsvp_event', $id, 'Test reminder for "' . $e->title . '" to ' . $to . ': ' . $r['status']);

        return response()->json(['sent' => $r['status'] === 'sent', 'status' => $r['status'], 'reason' => $r['error']]);
    }

    /** A pretend RSVP shaped like this event's questions, for previews and tests. */
    private function sampleRegistrant(object $e, string $email = 'alex.sample@example.com'): object
    {
        $answers = [];
        foreach ((is_string($e->fields) ? json_decode($e->fields, true) : (array) $e->fields) ?: [] as $f) {
            $f = (array) $f;
            $hay = ($f['key'] ?? '') . ' ' . ($f['label'] ?? '');
            $v = match (true) {
                ($f['type'] ?? '') === 'email' => $email,
                (bool) preg_match('/names/i', $hay) => "Alex Sample\nJordan Sample",
                (bool) preg_match('/attend|guest|people/i', $hay) => in_array('2', (array) ($f['options'] ?? []), true) ? '2' : 'Sample answer',
                ($f['type'] ?? '') === 'checkbox' => 'Yes',
                default => 'Sample answer',
            };
            $answers[$f['key']] = ['label' => $f['label'] ?? $f['key'], 'value' => $v];
        }

        return (object) ['name' => 'Alex Sample', 'email' => $email, 'answers' => json_encode($answers)];
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
        if (RsvpPresets::isPresetImage($url)) return $url;
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
