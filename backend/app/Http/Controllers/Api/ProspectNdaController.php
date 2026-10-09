<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailTemplate;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * OUR NDA, SENT TO A PROSPECT (2026-10-09).
 *
 * Anthony: "wire something up in the portal to send our NDA manually to a prospect that is
 * not a customer today ... add this in forms manager as an option" — "our existing NDA but
 * request that their business name and full legal business address, role in the company is
 * added to the NDA and signed". Platform admins and the sales team send it; the signed copy
 * goes to the prospect and sales@kiddietrac.com and is logged on the lead.
 *
 * The prospect signs from a private link (nda.html?t=…). Nothing is created in any agency —
 * the Forms Manager's own "send to a new address" makes a placeholder account inside the
 * agency, which would put a prospect in iLearn's user list. The text is the same v2.0
 * agreement every user signs (AgreementController), with the business details and the
 * signature rendered into the PDF.
 */
class ProspectNdaController extends Controller
{
    public const LINK_DAYS = 30;
    private const SALES_COPY = 'sales@kiddietrac.com';

    private function assertSender(Request $request): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->whereIn('role', ['platform_admin', 'sales_rep'])->exists();
        abort_unless($ok, 403, 'Only the KiddieTrac team can send prospect NDAs.');
    }

    private function row(object $r): array
    {
        $by = $r->sent_by_id ? DB::table('users')->where('id', $r->sent_by_id)->first(['first_name', 'last_name']) : null;
        $lead = $r->lead_id ? DB::table('sales_leads')->where('id', $r->lead_id)->first(['name', 'company']) : null;

        return [
            'id' => (int) $r->id, 'name' => $r->name, 'email' => $r->email, 'company' => $r->company,
            'status' => $r->status === 'sent' && $r->expires_at && Carbon::parse($r->expires_at)->isPast() ? 'expired' : $r->status,
            'sent_at' => $r->sent_at, 'opened_at' => $r->opened_at, 'signed_at' => $r->signed_at, 'expires_at' => $r->expires_at,
            'signer_name' => $r->signer_name, 'role_title' => $r->role_title,
            'business_name' => $r->business_name, 'business_address' => $r->business_address,
            'sent_by' => $by ? trim($by->first_name . ' ' . $by->last_name) : null,
            'lead_id' => $r->lead_id ? (int) $r->lead_id : null,
            'lead' => $lead ? trim(($lead->company ?: '') . ($lead->company && $lead->name ? ' — ' : '') . ($lead->name ?: '')) : null,
            'has_pdf' => (bool) $r->pdf_path,
        ];
    }

    /** GET /admin/prospect-ndas — everything sent, newest first. */
    public function index(Request $request): JsonResponse
    {
        $this->assertSender($request);
        $leads = DB::table('sales_leads')->whereNull('deleted_at')->orderBy('company')->get(['id', 'name', 'company', 'email']);

        return response()->json([
            'ndas' => DB::table('prospect_ndas')->orderByDesc('id')->limit(300)->get()->map(fn ($r) => $this->row($r))->values(),
            'leads' => $leads,
            'version' => AgreementController::AGREEMENT_VERSION,
        ]);
    }

    /** POST /admin/prospect-ndas {name, email, company?, lead_id?} — create and email the link. */
    public function store(Request $request): JsonResponse
    {
        $this->assertSender($request);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'lead_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if (! empty($d['lead_id']) && ! DB::table('sales_leads')->where('id', $d['lead_id'])->whereNull('deleted_at')->exists()) {
            return response()->json(['message' => 'That lead was not found.'], 422);
        }
        $id = DB::table('prospect_ndas')->insertGetId([
            'token' => Str::random(48), 'sent_by_id' => (int) $request->user()->id, 'lead_id' => $d['lead_id'] ?? null,
            'name' => trim($d['name']), 'email' => mb_strtolower(trim($d['email'])), 'company' => $d['company'] ?? null,
            'status' => 'sent', 'agreement_version' => AgreementController::AGREEMENT_VERSION,
            'sent_at' => now(), 'expires_at' => now()->addDays(self::LINK_DAYS), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $r = DB::table('prospect_ndas')->find($id);
        $mail = $this->mailLink($r, $request->user(), $d['note'] ?? null);
        $this->logOnLead($r, (int) $request->user()->id, 'email', 'NDA sent to ' . $r->name . ' <' . $r->email . '> for signature.');
        $this->audit($request, 'prospect_nda.sent', $id, 'NDA sent to ' . $r->name . ' <' . $r->email . '>' . ($r->company ? ' (' . $r->company . ')' : '') . ': ' . $mail);

        return response()->json(['nda' => $this->row($r), 'email' => $mail,
            'message' => $mail === 'sent' ? 'NDA sent to ' . $r->email . '.' : 'Saved, but the email did not go: ' . $mail], $mail === 'sent' ? 201 : 202);
    }

    /** POST /admin/prospect-ndas/{id}/resend — a fresh link (the old one stops working). */
    public function resend(Request $request, int $id): JsonResponse
    {
        $this->assertSender($request);
        $r = DB::table('prospect_ndas')->find($id);
        abort_unless($r, 404);
        if ($r->status === 'signed') return response()->json(['message' => 'Already signed.'], 422);
        DB::table('prospect_ndas')->where('id', $id)->update(['token' => Str::random(48), 'status' => 'sent',
            'sent_at' => now(), 'expires_at' => now()->addDays(self::LINK_DAYS), 'updated_at' => now()]);
        $r = DB::table('prospect_ndas')->find($id);
        $mail = $this->mailLink($r, $request->user(), null);
        $this->audit($request, 'prospect_nda.resent', $id, 'NDA link re-sent to ' . $r->email . ': ' . $mail);

        return response()->json(['nda' => $this->row($r), 'email' => $mail]);
    }

    /** DELETE /admin/prospect-ndas/{id} — cancel an unsigned NDA (its link stops working). */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->assertSender($request);
        $r = DB::table('prospect_ndas')->find($id);
        abort_unless($r, 404);
        if ($r->status === 'signed') return response()->json(['message' => 'A signed NDA is a record and cannot be cancelled.'], 422);
        DB::table('prospect_ndas')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->audit($request, 'prospect_nda.cancelled', $id, 'NDA to ' . $r->email . ' cancelled');

        return response()->json(['ok' => true]);
    }

    /** GET /admin/prospect-ndas/{id}/pdf — the signed copy. */
    public function pdf(Request $request, int $id)
    {
        $this->assertSender($request);
        $r = DB::table('prospect_ndas')->find($id);
        abort_unless($r && $r->pdf_path && Storage::disk('local')->exists($r->pdf_path), 404);

        return response()->file(Storage::disk('local')->path($r->pdf_path), ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $this->fileName($r) . '"']);
    }

    /** GET /admin/prospect-ndas/{id}/preview — the NDA as sent, before it is signed. */
    public function preview(Request $request, int $id)
    {
        $this->assertSender($request);
        $r = DB::table('prospect_ndas')->find($id);
        abort_unless($r, 404);
        if ($r->status === 'signed' && $r->pdf_path && Storage::disk('local')->exists($r->pdf_path)) {
            return $this->pdf($request, $id);
        }
        $blank = '(to be completed by the prospect)';
        $pdf = $this->renderPdf($r, ['business_name' => $r->company ? $r->company . ' ' . $blank : $blank, 'business_address' => $blank,
            'signer_name' => $r->name, 'role_title' => $blank], 'not yet signed', null,
            hash('sha256', AgreementController::AGREEMENT_VERSION . '|' . AgreementController::agreementBodyHtml()));

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="KiddieTrac-NDA-preview.pdf"']);
    }

    // ── public: the prospect's side ───────────────────────────────────────────

    private function byToken(string $token): ?object
    {
        return DB::table('prospect_ndas')->where('token', $token)->first();
    }

    /** GET /public/nda/{token} — the agreement and what we already know. */
    public function publicShow(string $token): JsonResponse
    {
        $r = $this->byToken($token);
        if (! $r || $r->status === 'cancelled') return response()->json(['message' => 'This link is not valid. Please ask your KiddieTrac contact for a new one.'], 404);
        if ($r->status === 'signed') {
            return response()->json(['status' => 'signed', 'signed_at' => $r->signed_at, 'name' => $r->signer_name, 'business_name' => $r->business_name]);
        }
        if ($r->expires_at && Carbon::parse($r->expires_at)->isPast()) {
            return response()->json(['message' => 'This link has expired. Please ask your KiddieTrac contact for a new one.'], 410);
        }
        if (! $r->opened_at) DB::table('prospect_ndas')->where('id', $r->id)->update(['opened_at' => now()]);

        return response()->json([
            'status' => 'open', 'name' => $r->name, 'email' => $r->email, 'company' => $r->company,
            'version' => AgreementController::AGREEMENT_VERSION,
            'agreement_html' => AgreementController::agreementBodyHtml(),
        ]);
    }

    /** POST /public/nda/{token}/sign — business details + signature → signed PDF, emailed. */
    public function publicSign(Request $request, string $token): JsonResponse
    {
        $r = $this->byToken($token);
        if (! $r || $r->status !== 'sent') {
            return response()->json(['message' => $r && $r->status === 'signed' ? 'This NDA has already been signed.' : 'This link is not valid.'], 422);
        }
        if ($r->expires_at && Carbon::parse($r->expires_at)->isPast()) {
            return response()->json(['message' => 'This link has expired. Please ask your KiddieTrac contact for a new one.'], 410);
        }
        $d = $request->validate([
            'signer_name' => ['required', 'string', 'min:2', 'max:160'],
            'role_title' => ['required', 'string', 'min:2', 'max:160'],
            'business_name' => ['required', 'string', 'min:2', 'max:255'],
            'business_address' => ['required', 'string', 'min:8', 'max:600'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:600000'],
            'agree' => ['accepted'],
        ]);
        $png = base64_decode(substr($d['signature'], strlen('data:image/png;base64,')), true);
        if (! $png || strlen($png) < 200) {
            return response()->json(['message' => 'Please draw your signature.', 'errors' => ['signature' => ['Please draw your signature.']]], 422);
        }

        $signedAt = now();
        $tz = 'America/Toronto';
        $human = $signedAt->copy()->setTimezone($tz)->format('M j, Y g:i A T');
        $hash = hash('sha256', AgreementController::AGREEMENT_VERSION . '|' . AgreementController::agreementBodyHtml());
        $pdf = $this->renderPdf($r, $d, $human, $d['signature'], $hash);
        $path = 'prospect-ndas/' . $r->id . '-' . Str::random(10) . '.pdf';
        Storage::disk('local')->put($path, $pdf);

        DB::table('prospect_ndas')->where('id', $r->id)->where('status', 'sent')->update([
            'status' => 'signed', 'signed_at' => $signedAt, 'signer_name' => trim($d['signer_name']),
            'role_title' => trim($d['role_title']), 'business_name' => trim($d['business_name']),
            'business_address' => trim($d['business_address']), 'document_hash' => $hash, 'pdf_path' => $path,
            'ip_address' => substr((string) $request->ip(), 0, 45), 'user_agent' => substr((string) $request->userAgent(), 0, 300),
            'updated_at' => now(),
        ]);
        $r = DB::table('prospect_ndas')->find($r->id);

        $mail = $this->mailSigned($r, $human);
        $actId = $this->logOnLead($r, (int) ($r->sent_by_id ?: 0), 'note', 'NDA signed by ' . $r->signer_name . ' (' . $r->role_title . ') for '
            . $r->business_name . ', ' . preg_replace('/\s+/', ' ', (string) $r->business_address) . ' — ' . $human . '. Signed copy emailed to them and ' . self::SALES_COPY . '.');
        $this->fileOnLead($r, $actId);
        try {
            \App\Support\Audit::write(['user_id' => null, 'agency_id' => null, 'action' => 'prospect_nda.signed', 'entity_type' => 'prospect_nda', 'entity_id' => (int) $r->id,
                'payload' => json_encode(['summary' => 'NDA signed by ' . $r->signer_name . ' (' . $r->role_title . ', ' . $r->business_name . ') <' . $r->email . '>; copies: ' . $mail]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'signed_at' => $human, 'email' => $mail,
            'message' => 'Thank you — your signed copy has been emailed to ' . $r->email . '.']);
    }

    /** GET /public/nda/{token}/pdf — the signer's own copy, from their link. */
    public function publicPdf(string $token)
    {
        $r = $this->byToken($token);
        abort_unless($r && $r->status === 'signed' && $r->pdf_path && Storage::disk('local')->exists($r->pdf_path), 404);

        return response()->file(Storage::disk('local')->path($r->pdf_path), ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->fileName($r) . '"']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function fileName(object $r): string
    {
        $b = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($r->business_name ?: $r->company ?: $r->name)) ?: 'Prospect';

        return 'KiddieTrac-NDA-' . trim($b, '-') . '.pdf';
    }

    private function renderPdf(object $r, array $d, string $human, ?string $sig, string $hash): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;color:#0F172A;font-size:11px;line-height:1.55;}'
            . 'h1{font-size:17px;margin:0 0 2px;} h2{font-size:12.5px;margin:16px 0 4px;} h3{font-size:12px;margin:14px 0 3px;}'
            . 'p,li{font-size:11px;} ul{margin:4px 0 4px 14px;padding:0;} .muted{color:#64748B;font-size:10px;}'
            . '.box{border:1px solid #CBD5E1;border-radius:6px;padding:10px;margin-top:10px;} .sig{border-bottom:1px solid #94A3B8;max-width:280px;}'
            . 'td{padding:3px 8px 3px 0;vertical-align:top;font-size:11px;}'
            . '</style></head><body>'
            . '<h1>KiddieTrac — Terms of Use, Privacy Policy &amp; Non-Disclosure Agreement</h1>'
            . '<div class="muted">Version ' . $e(AgreementController::AGREEMENT_VERSION) . ' · Prospect agreement</div>'
            . '<div class="box"><h2 style="margin-top:0">The other party</h2><table>'
            . '<tr><td><strong>Business (legal name)</strong></td><td>' . $e($d['business_name']) . '</td></tr>'
            . '<tr><td><strong>Legal business address</strong></td><td>' . nl2br($e($d['business_address'])) . '</td></tr>'
            . '<tr><td><strong>Signed by</strong></td><td>' . $e($d['signer_name']) . '</td></tr>'
            . '<tr><td><strong>Role in the company</strong></td><td>' . $e($d['role_title']) . '</td></tr>'
            . '<tr><td><strong>Email</strong></td><td>' . $e($r->email) . '</td></tr></table></div>'
            . AgreementController::agreementBodyHtml()
            . '<div class="box"><h2 style="margin-top:0">Signature</h2>'
            . ($sig ? '<img class="sig" src="' . $sig . '" alt="Signature">' : '<div style="height:46px;border-bottom:1px solid #94A3B8;max-width:280px;"></div>')
            . '<p><strong>Signed by:</strong> ' . $e($d['signer_name']) . ', ' . $e($d['role_title']) . '<br>'
            . '<strong>For:</strong> ' . $e($d['business_name']) . '<br>'
            . '<strong>Date and time:</strong> ' . $e($human) . '<br>'
            . '<span class="muted">Document fingerprint (SHA-256 of the agreement text): ' . $e($hash) . '</span></p></div>'
            . '</body></html>';
        $o = new Options(); $o->set('isRemoteEnabled', true); $o->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($o); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('letter', 'portrait'); $pdf->render();

        return (string) $pdf->output();
    }

    /** Sends and reports what the mail log recorded ("sent", or the reason it did not go). */
    private function send(array $build, string $to): string
    {
        $before = (int) DB::table('email_logs')->max('id');
        try {
            Mail::html($build[0], function ($m) use ($build) {
                \App\Support\MailScope::platform($m);
                $m->from('noreply@kiddietrac.com', 'KiddieTrac')->replyTo(self::SALES_COPY, 'KiddieTrac Sales');
                ($build[1])($m);
            });
        } catch (\Throwable $e) {
            Log::warning('prospect NDA mail failed', ['to' => $to, 'error' => $e->getMessage()]);
            return 'failed: ' . mb_substr($e->getMessage(), 0, 160);
        }
        $row = DB::table('email_logs')->where('id', '>', $before)->where('to_email', 'like', '%' . strtolower($to) . '%')->orderByDesc('id')->first(['status', 'error']);

        return $row ? ((string) $row->status === 'sent' ? 'sent' : ((string) $row->status . ($row->error ? ': ' . mb_substr((string) $row->error, 0, 160) : ''))) : 'sent';
    }

    private function mailLink(object $r, $sender, ?string $note): string
    {
        $link = 'https://app.kiddietrac.com/nda.html?t=' . $r->token;
        $from = trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? '')) ?: 'The KiddieTrac team';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $body = '<p style="margin:0 0 14px;">Hi ' . $e($r->name) . ',</p>'
            . '<p style="margin:0 0 14px;line-height:1.6;">' . $e($from) . ' has sent you the KiddieTrac '
            . '<strong>Non-Disclosure Agreement</strong> to review and sign before we share confidential information with you.</p>'
            . ($note ? '<p style="margin:0 0 14px;line-height:1.6;color:#334155;">“' . nl2br($e($note)) . '”</p>' : '')
            . '<p style="margin:0 0 14px;line-height:1.6;">It takes about two minutes: you will be asked for your business’s legal name, its full legal address and your role, then to sign.</p>'
            . '<p style="margin:18px 0;"><a href="' . $e($link) . '" style="display:inline-block;padding:12px 20px;border-radius:10px;background:#1F6080;color:#fff;text-decoration:none;font-weight:700;">Review &amp; sign the NDA</a></p>'
            . '<p style="margin:0;font-size:12.5px;color:#64748B;line-height:1.6;">This link is personal to you and works for ' . self::LINK_DAYS . ' days. You will receive a signed copy by email.</p>';
        $html = EmailTemplate::wrap(null, $body, ['eyebrow' => 'PLEASE SIGN', 'title' => 'Non-Disclosure Agreement', 'subtitle' => 'From KiddieTrac',
            'preheader' => 'Please review and sign the KiddieTrac NDA.']);

        return $this->send([$html, function ($m) use ($r) { $m->to($r->email, $r->name)->subject('Please sign: KiddieTrac Non-Disclosure Agreement'); }], $r->email);
    }

    private function mailSigned(object $r, string $human): string
    {
        $abs = Storage::disk('local')->path($r->pdf_path);
        $name = $this->fileName($r);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $box = EmailTemplate::calloutBox('<strong>Signed by:</strong> ' . $e($r->signer_name) . ', ' . $e($r->role_title) . '<br>'
            . '<strong>Business:</strong> ' . $e($r->business_name) . '<br><strong>Address:</strong> ' . nl2br($e($r->business_address)) . '<br>'
            . '<strong>Date:</strong> ' . $e($human) . '<br><strong>Version:</strong> ' . $e($r->agreement_version), 'info');
        $mk = function (string $intro) use ($box) {
            return EmailTemplate::wrap(null, '<p style="margin:0 0 14px;line-height:1.6;">' . $intro . '</p>' . $box, [
                'eyebrow' => 'NDA SIGNED', 'title' => 'Non-Disclosure Agreement', 'subtitle' => 'Signed copy attached', 'preheader' => 'Signed copy of the KiddieTrac NDA.']);
        };
        $toThem = $this->send([$mk('Hi ' . $e($r->signer_name) . ', thank you — your signed copy of the KiddieTrac Non-Disclosure Agreement is attached for your records.'),
            function ($m) use ($r, $abs, $name) { $m->to($r->email, $r->signer_name)->subject('Your signed copy — KiddieTrac NDA'); if (is_file($abs)) $m->attach($abs, ['as' => $name, 'mime' => 'application/pdf']); }], $r->email);
        $toSales = $this->send([$mk($e($r->signer_name) . ' (' . $e($r->role_title) . ') has signed the NDA for <strong>' . $e($r->business_name) . '</strong>. The signed PDF is attached.'),
            function ($m) use ($r, $abs, $name) { $m->to(self::SALES_COPY, 'KiddieTrac Sales')->subject('NDA signed: ' . $r->business_name . ' — ' . $r->signer_name); if (is_file($abs)) $m->attach($abs, ['as' => $name, 'mime' => 'application/pdf']); }], self::SALES_COPY);

        return 'prospect ' . $toThem . ', sales ' . $toSales;
    }

    private function logOnLead(object $r, int $userId, string $type, string $body): ?int
    {
        if (! $r->lead_id) return null;
        try {
            $id = DB::table('sales_activities')->insertGetId(['lead_id' => (int) $r->lead_id, 'user_id' => $userId ?: null, 'type' => $type,
                'body' => $body, 'done' => 1, 'done_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sales_leads')->where('id', $r->lead_id)->update(['last_activity_at' => now()]);
            return (int) $id;
        } catch (\Throwable $e) { Log::warning('prospect NDA lead log failed', ['error' => $e->getMessage()]); return null; }
    }

    /* THE SIGNED NDA BELONGS ON THE LEAD (2026-10-09, Anthony: "make sure its also attached
       to the lead record and filed away in documents once signed"). A copy goes beside the
       lead's other files (sales-activity/{lead}), attached to the "NDA signed" entry, so it
       shows in Activity & history and on the lead's Documents tab. */
    private function fileOnLead(object $r, ?int $activityId): void
    {
        if (! $r->lead_id || ! $activityId || ! $r->pdf_path) return;
        try {
            $bytes = Storage::disk('local')->get($r->pdf_path);
            $rel = 'sales-activity/' . (int) $r->lead_id . '/' . Str::uuid() . '.pdf';
            Storage::disk('public')->put($rel, $bytes);
            DB::table('sales_activity_files')->insert(['activity_id' => $activityId, 'lead_id' => (int) $r->lead_id, 'kind' => 'document',
                'original_name' => $this->fileName($r), 'file_url' => '/storage/' . $rel, 'mime' => 'application/pdf', 'size' => strlen($bytes),
                'uploaded_by_id' => $r->sent_by_id ?: null, 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) { Log::warning('prospect NDA lead filing failed', ['error' => $e->getMessage()]); }
    }

    private function audit(Request $request, string $action, int $id, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => $action, 'entity_type' => 'prospect_nda',
                'entity_id' => $id, 'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }
}
