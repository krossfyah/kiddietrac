<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

/**
 * Support from the marketing site, and the toll-free line's voicemail (2026-09-29).
 *
 * Anthony, comparing the contact page with ChildCarePro's: a separate support path, and
 * voicemails "emailed to us". Both go to info@kiddietrac.com (his choice; the same inbox
 * the portal uses for bug reports).
 *
 * TICKETS land in support_tickets with no agency and no user -- a website visitor is not
 * signed in, and matching their email to an account would let anyone file a ticket into
 * an agency's queue under someone else's name. Platform admins already see agency-less
 * tickets in Tickets. The requester's details sit in requester_* columns.
 *
 * VOICEMAIL: the number's TeXML <Record> posts here when a message ends (action + status
 * callback, deduped by RecordingSid). The recording is downloaded (Telnyx links expire)
 * and emailed as an attachment with the caller's number. The URL carries a random token
 * kept in platform settings; nothing else authenticates a TeXML callback.
 */
class WebsiteSupportController extends Controller
{
    private const INBOX = 'info@kiddietrac.com';
    private const CATEGORIES = [
        'technical' => 'Something isn’t working',
        'billing' => 'Billing or my subscription',
        'enrollment' => 'Families, enrolment or records',
        'other' => 'Something else',
    ];

    /** POST /marketing-site/support (multipart; one optional attachment) */
    public function submitTicket(Request $request): JsonResponse
    {
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['ok' => true])->header('Access-Control-Allow-Origin', '*');
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:160',
            'phone' => 'nullable|string|max:40',
            'organization' => 'nullable|string|max:160',
            'category' => 'required|in:' . implode(',', array_keys(self::CATEGORIES)),
            'priority' => 'required|in:low,normal,high,urgent',
            'subject' => 'required|string|max:200',
            'description' => 'required|string|max:8000',
            'file' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,heic,pdf,txt,log,csv'],
        ]);
        $email = strtolower(trim($data['email']));
        $id = DB::table('support_tickets')->insertGetId([
            'agency_id' => null, 'centre_id' => null, 'raised_by_user_id' => null,
            'category' => $data['category'], 'priority' => $data['priority'],
            'subject' => trim($data['subject']),
            'body' => trim($data['description']),
            'status' => 'open', 'source' => 'website',
            'requester_name' => trim($data['name']), 'requester_email' => $email,
            'requester_phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'requester_org' => trim((string) ($data['organization'] ?? '')) ?: null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $attach = null;
        if ($request->hasFile('file')) {
            $f = $request->file('file');
            $name = (string) \Illuminate\Support\Str::uuid() . '.' . strtolower($f->getClientOriginalExtension() ?: 'bin');
            $path = $f->storeAs('ticket-files/' . $id, $name);          // private disk, like portal tickets
            DB::table('support_ticket_files')->insert(['ticket_id' => $id, 'path' => $path,
                'original_name' => mb_substr((string) $f->getClientOriginalName(), 0, 255), 'mime' => (string) $f->getClientMimeType(),
                'size_bytes' => (int) $f->getSize(), 'uploaded_by_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            $attach = ['path' => \Illuminate\Support\Facades\Storage::path($path), 'name' => (string) $f->getClientOriginalName(), 'mime' => (string) $f->getClientMimeType()];
        }

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rows = ['Ticket' => '#' . $id, 'From' => $data['name'] . ' <' . $email . '>', 'Phone' => $data['phone'] ?? '', 'Organization' => $data['organization'] ?? '',
            'Type' => self::CATEGORIES[$data['category']], 'Urgency' => ucfirst($data['priority'])];
        $t = '';
        foreach ($rows as $k => $v) {
            $t .= '<tr><td style="padding:4px 14px 4px 0;color:#64748b;font-size:13px">' . $e($k) . '</td><td style="padding:4px 0;font-size:14px;font-weight:600">' . $e($v ?: '—') . '</td></tr>';
        }
        $inner = '<table style="border-collapse:collapse;margin-bottom:12px">' . $t . '</table>'
            . '<div style="font-weight:700;margin:6px 0">' . $e($data['subject']) . '</div>'
            . '<div style="white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;font-size:14px">' . $e($data['description']) . '</div>'
            . '<p style="color:#64748b;font-size:12.5px;margin-top:12px">Also in the portal under Tickets (platform admin). Reply to this email to answer them directly.</p>';
        $sentTeam = false;
        try {
            $html = \App\Services\EmailTemplate::wrap(null, $inner, ['force_brand' => 'kt', 'title' => 'Website support request #' . $id]);
            Mail::html($html, function ($m) use ($id, $data, $email, $attach) {
                $m->to(self::INBOX)->replyTo($email, $data['name'])
                  ->subject('[Support #' . $id . ' · ' . ucfirst($data['priority']) . '] ' . $data['subject']);
                if ($attach && is_file($attach['path'])) $m->attach($attach['path'], ['as' => $attach['name'], 'mime' => $attach['mime']]);
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
            $sentTeam = true;
        } catch (\Throwable $ex) {
            Log::error('Website ticket email failed', ['ticket' => $id, 'error' => $ex->getMessage()]);
        }
        // The requester: proof it arrived, with their number.
        try {
            $body = '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Hi ' . $e(strtok($data['name'], ' ')) . ',</p>'
                . '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Thanks for getting in touch. We’ve received your support request <strong>#' . $id . '</strong> and will reply by email within one business day (Monday to Friday, 9 a.m. to 5 p.m. Eastern).</p>'
                . \App\Services\EmailTemplate::calloutBox('<strong>' . $e($data['subject']) . '</strong><br>' . nl2br($e(mb_strimwidth($data['description'], 0, 600, '…'))), 'info')
                . '<p style="margin:14px 0 0;font-size:14px;color:#64748B;line-height:1.6">To add anything, just reply to this email. If it’s urgent, call 1-855-400-9996.</p>';
            $html = \App\Services\EmailTemplate::wrap(null, $body, ['force_brand' => 'kt', 'title' => 'We’ve got your request', 'preheader' => 'Support request #' . $id]);
            Mail::html($html, function ($m) use ($id, $data, $email) {
                $m->to($email, $data['name'])->replyTo(self::INBOX, 'KiddieTrac Support')->subject('KiddieTrac support request #' . $id . ' received');
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
        } catch (\Throwable $ex) {
            Log::warning('Website ticket receipt failed', ['ticket' => $id, 'error' => $ex->getMessage()]);
        }

        return response()->json(['ok' => true, 'ticket' => $id, 'delivered' => $sentTeam], 201)->header('Access-Control-Allow-Origin', '*');
    }

    /** POST /voice/voicemail/{token} — TeXML <Record> action + recording status callback. */
    public function voicemail(Request $request, string $token): Response
    {
        $expected = (string) PlatformSettings::get('voice.voicemail_token', '');
        $bye = '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Thank you. We’ll be in touch soon. Goodbye.</Say><Hangup/></Response>';
        $xml = fn () => new Response($bye, 200, ['Content-Type' => 'text/xml']);
        if ($expected === '' || ! hash_equals($expected, $token)) {
            Log::warning('Voicemail callback with a bad token', ['ip' => $request->ip()]);
            return new Response('', 404);
        }
        $sid = (string) ($request->input('RecordingSid') ?: $request->input('recording_sid') ?: '');
        $url = (string) ($request->input('RecordingUrl') ?: $request->input('recording_url') ?: '');
        if ($sid === '' || $url === '') return $xml();
        $from = (string) ($request->input('From') ?: $request->input('from') ?: '');
        $duration = (int) ($request->input('RecordingDuration') ?: $request->input('recording_duration') ?: 0);

        // First callback wins; the action and the status callback both carry the recording.
        try {
            $id = DB::table('voicemails')->insertGetId(['recording_sid' => substr($sid, 0, 100), 'call_sid' => substr((string) $request->input('CallSid', ''), 0, 100) ?: null,
                'from_number' => substr($from, 0, 40) ?: null, 'to_number' => substr((string) $request->input('To', ''), 0, 40) ?: null,
                'duration' => $duration, 'recording_url' => substr($url, 0, 1000), 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Illuminate\Database\QueryException $ex) {
            $row = DB::table('voicemails')->where('recording_sid', $sid)->first();
            if ($row && ! $row->from_number && $from) DB::table('voicemails')->where('id', $row->id)->update(['from_number' => substr($from, 0, 40)]);
            return $xml();
        }

        $file = null;
        try {
            $res = Http::timeout(20)->get($url);
            if (! $res->successful()) {
                $res = Http::withToken(\App\Support\Telnyx::apiKey(2))->timeout(20)->get($url);
            }
            if ($res->successful() && strlen($res->body()) > 100) {
                $ext = str_contains((string) $res->header('Content-Type'), 'wav') ? 'wav' : 'mp3';
                $rel = 'voicemails/' . now()->format('Y/m') . '/vm-' . $id . '.' . $ext;
                \Illuminate\Support\Facades\Storage::put($rel, $res->body());
                $file = ['path' => \Illuminate\Support\Facades\Storage::path($rel), 'ext' => $ext];
                DB::table('voicemails')->where('id', $id)->update(['stored_path' => $rel]);
            }
        } catch (\Throwable $ex) {
            Log::warning('Voicemail download failed', ['voicemail' => $id, 'error' => $ex->getMessage()]);
        }

        $when = now('America/Toronto')->format('l, F j \a\t g:i A') . ' ET';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $inner = '<p style="font-size:15px;color:#334155;margin:0 0 10px">New voicemail on <strong>1-855-400-9996</strong>.</p>'
            . '<table style="border-collapse:collapse;margin-bottom:12px">'
            . '<tr><td style="padding:4px 14px 4px 0;color:#64748b">Caller</td><td style="font-weight:700">' . $e($from ?: 'Unknown / hidden') . '</td></tr>'
            . '<tr><td style="padding:4px 14px 4px 0;color:#64748b">When</td><td>' . $e($when) . '</td></tr>'
            . '<tr><td style="padding:4px 14px 4px 0;color:#64748b">Length</td><td>' . ($duration ? $duration . ' seconds' : '—') . '</td></tr></table>'
            . ($file ? '<p style="font-size:14px;color:#334155">The recording is attached.</p>'
                : '<p style="font-size:14px;color:#B45309">The recording could not be downloaded; <a href="' . $e($url) . '">this link</a> may still work for a few minutes, or find it in Telnyx → Call recordings.</p>');
        try {
            $html = \App\Services\EmailTemplate::wrap(null, $inner, ['force_brand' => 'kt', 'title' => 'New voicemail']);
            Mail::html($html, function ($m) use ($from, $file, $id) {
                $m->to(self::INBOX)->subject('Voicemail from ' . ($from ?: 'an unknown caller') . ' (1-855-400-9996)');
                if ($file && is_file($file['path'])) $m->attach($file['path'], ['as' => 'voicemail-' . $id . '.' . $file['ext'], 'mime' => $file['ext'] === 'wav' ? 'audio/wav' : 'audio/mpeg']);
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
            DB::table('voicemails')->where('id', $id)->update(['emailed_at' => now(), 'email_status' => 'sent']);
        } catch (\Throwable $ex) {
            DB::table('voicemails')->where('id', $id)->update(['email_status' => 'failed']);
            Log::error('Voicemail email failed', ['voicemail' => $id, 'error' => $ex->getMessage()]);
        }

        return $xml();
    }
}
