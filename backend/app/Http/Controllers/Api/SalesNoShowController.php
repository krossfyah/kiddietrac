<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BookingMail;
use App\Support\LineTexts;
use App\Support\NoShow;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales → a lead meeting that has happened (or not): held / no-show / undo, and the "pick a new time" email and
 * text (App\Support\NoShow, 2026-11-28). Routes sit in the sales group (sales_rep, platform_admin). The public
 * side is MarketingBookingController (the wizard's signed ?rebook= link).
 */
class SalesNoShowController extends Controller
{
    private function subject(string $kind, int $id): ?object
    {
        return in_array($kind, ['meeting', 'booking'], true) ? NoShow::subject($kind, $id) : null;
    }

    private function shape(object $s, int $uid): array
    {
        $ch = NoShow::channels($s->lead);
        return [
            'kind' => $s->kind, 'id' => $s->id, 'title' => $s->title, 'when' => $s->when, 'start' => Carbon::parse($s->starts_at, 'UTC')->toIso8601String(),
            'status' => $s->status, 'started' => $s->started, 'can' => NoShow::can($s), 'asked_at' => $s->row->noshow_asked_at ?? null,
            'rebooked_booking_id' => isset($s->row->rebooked_booking_id) && $s->row->rebooked_booking_id ? (int) $s->row->rebooked_booking_id : null,
            'lead' => ['id' => (int) $s->lead->id, 'name' => $s->lead->name, 'company' => $s->lead->company],
            'channels' => ['email' => $ch['email'], 'sms' => array_diff_key($ch['sms'], ['digits' => 1]), 'test' => $ch['test']],
        ];
    }

    /** GET /sales/no-show/{kind}/{id}?preview=1 — the meeting, its lead, the channels and (preview) the email + text. */
    public function show(Request $r, string $kind, int $id): JsonResponse
    {
        $s = $this->subject($kind, $id);
        if (! $s) return response()->json(['message' => 'That meeting is not with a sales lead.'], 404);
        $uid = (int) $r->user()->id;
        $out = $this->shape($s, $uid);
        if ($r->boolean('preview')) {
            [$subject, $html] = NoShow::email($s, $uid);
            $out['preview'] = ['subject' => $subject, 'from' => NoShow::senderName($uid) . ' <' . BookingMail::SALES . '>', 'reply_to' => BookingMail::SALES,
                'html' => $html, 'sms' => NoShow::sms($s, $uid)];
            $out['auto'] = NoShow::autoOn();
        }
        return response()->json(['meeting' => $out]);
    }

    /** POST /sales/no-show/{kind}/{id}/mark {status: no_show|held|undo} */
    public function mark(Request $r, string $kind, int $id): JsonResponse
    {
        $d = $r->validate(['status' => ['required', 'in:no_show,held,undo']]);
        $s = $this->subject($kind, $id);
        if (! $s) return response()->json(['message' => 'That meeting is not with a sales lead.'], 404);
        [$new, $err] = NoShow::mark($s, $d['status'], (int) $r->user()->id);
        if ($err) return response()->json(['message' => $err], 422);
        return response()->json(['ok' => true, 'status' => $new, 'meeting' => $this->shape($this->subject($kind, $id), (int) $r->user()->id)]);
    }

    /**
     * POST /sales/no-show/{kind}/{id}/send {email: bool, to_email?, sms: bool, to_phone?, mark?: bool}
     * Marks it no-show (unless it already is) and sends what was ticked. The recipient is REQUIRED for each channel and
     * must be the lead's own address / number as shown: nothing is ever sent to an address the request did not name.
     */
    public function send(Request $r, string $kind, int $id): JsonResponse
    {
        $d = $r->validate([
            'email' => ['required', 'boolean'], 'sms' => ['required', 'boolean'],
            'to_email' => ['required_if:email,true', 'nullable', 'email', 'max:190'],
            'to_phone' => ['required_if:sms,true', 'nullable', 'string', 'max:40'],
        ]);
        $s = $this->subject($kind, $id);
        if (! $s) return response()->json(['message' => 'That meeting is not with a sales lead.'], 404);
        $uid = (int) $r->user()->id;
        $ch = NoShow::channels($s->lead);
        if (! $d['email'] && ! $d['sms']) return response()->json(['message' => 'Tick email or text, or use "Mark only".'], 422);
        if ($d['email'] && strtolower(trim((string) $d['to_email'])) !== (string) $ch['email']['to']) {
            return response()->json(['message' => 'The email address does not match the lead. Refresh and try again.'], 422);
        }
        if ($d['sms'] && LineTexts::digits((string) $d['to_phone']) !== (string) ($ch['sms']['digits'] ?? '')) {
            return response()->json(['message' => 'The mobile number does not match the lead. Refresh and try again.'], 422);
        }
        if ($s->status !== 'no_show') {
            [$new, $err] = NoShow::mark($s, 'no_show', $uid);
            if ($err) return response()->json(['message' => $err], 422);
            $s->status = $new;
        }
        $res = NoShow::send($s, (bool) $d['email'], (bool) $d['sms'], $uid);
        return response()->json(['ok' => true, 'status' => $s->status, 'result' => $res, 'meeting' => $this->shape($this->subject($kind, $id), $uid)]);
    }

    /** GET /sales/leads/{id}/meetings — the lead's meetings and website bookings, newest first. */
    public function forLead(Request $r, int $id): JsonResponse
    {
        $lead = DB::table('sales_leads')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $lead) return response()->json(['message' => 'That lead is not available.'], 404);
        $emails = array_values(array_filter(array_unique([strtolower(trim((string) $lead->email)), strtolower(trim((string) $lead->owner_email))])));
        $mids = DB::table('meetings')->where('scope', 'platform')->where(function ($q) use ($id, $emails) {
            $q->where('lead_id', $id);
            if ($emails) $q->orWhereExists(fn ($x) => $x->from('meeting_attendees as a')->whereColumn('a.meeting_id', 'meetings.id')->where('a.kind', '!=', 'staff')->whereIn(DB::raw('LOWER(a.email)'), $emails));
        })->orderByDesc('starts_at')->limit(60)->pluck('id');
        $map = NoShow::leadIdsForMeetings($mids->map(fn ($x) => (int) $x)->all());
        $uid = (int) $r->user()->id;
        $rows = [];
        foreach ($mids as $mid) {
            if (($map[(int) $mid] ?? null) !== $id) continue;   // another lead holds that meeting
            $s = NoShow::subject('meeting', (int) $mid);
            if ($s) $rows[] = $this->shape($s, $uid);
        }
        foreach (DB::table('marketing_bookings')->where('sales_lead_id', $id)->orderByDesc('starts_at')->limit(60)->pluck('id') as $bid) {
            $s = NoShow::subject('booking', (int) $bid);
            if ($s) $rows[] = $this->shape($s, $uid);
        }
        usort($rows, fn ($a, $b) => strcmp($b['start'], $a['start']));
        return response()->json(['meetings' => $rows]);
    }
}
