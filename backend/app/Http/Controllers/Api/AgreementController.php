<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailTemplate;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Onboarding: Terms of Use, Privacy Policy & Non-Disclosure Agreement (2026-07-13).
 *
 * Every new user, in any role, must accept this once before they can use the
 * platform. They may also DECLINE — in which case they are not onboarded, their
 * account is flagged, and the agency (plus info@kiddietrac.com, bcc) is told.
 *
 * What signing records:
 *   signed_documents — the legal record: signer, timestamp, IP, user agent, and a
 *                      SHA-256 of the exact wording shown. Bump AGREEMENT_VERSION
 *                      when the text changes and everyone re-signs; the hash then
 *                      proves which wording each person actually agreed to.
 *   documents        — the countersigned copy as a PDF (signature + date inline),
 *                      filed against the user's record (scope_type=user).
 *   + an emailed copy to the signer, with the PDF attached.
 */
class AgreementController extends Controller
{
    public const TYPE = 'privacy_nda';
    public const AGREEMENT_VERSION = '2.0';   // 2.0 = + Terms of Use + IP protection

    /** Has this user signed (or declined) the current version? */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $row = DB::table('signed_documents')
            ->where('signer_user_id', $user->id)
            ->whereIn('document_type', [self::TYPE, self::TYPE . '_declined'])
            ->orderByDesc('signed_at')
            ->first();

        $currentHash = $this->documentHash();
        $signed = $row && $row->document_type === self::TYPE && $row->document_hash === $currentHash;
        $declined = $row && $row->document_type === self::TYPE . '_declined';

        // The date stamped on the agreement is the AGENCY's local time, not the
        // signer's device clock — the record has to match the agency's day.
        $tz = $this->agencyTz($this->agencyIdFor((int) $user->id));

        return response()->json([
            'required' => !$signed,
            'declined' => $declined,
            'version' => self::AGREEMENT_VERSION,
            'signed_at' => $signed ? ($row->signed_at ?? null) : null,
            'title' => 'Terms of Use, Privacy Policy & Non-Disclosure Agreement',
            'body_html' => $this->agreementHtml(),
            'timezone' => $tz,
            'now_human' => now($tz)->format('F j, Y · g:i A') . ' (' . $tz . ')',
        ]);
    }

    public function sign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:120',
            'signature' => 'required|string|max:2000000',   // PNG data URL from the pad
            'agreed' => 'accepted',
        ]);

        $user = $request->user();

        if (!preg_match('#^data:image/png;base64,#', $data['signature'])) {
            return response()->json(['message' => 'The signature must be a PNG image.'], 422);
        }
        $binary = base64_decode(substr($data['signature'], strlen('data:image/png;base64,')), true);
        if ($binary === false || strlen($binary) < 100) {
            return response()->json(['message' => 'That signature looks empty — please sign again.'], 422);
        }

        $agencyId = $this->agencyIdFor((int) $user->id);
        $signedAt = now($this->agencyTz($agencyId));

        $dir = 'agreements/' . $user->id;
        $sigPath = $dir . '/signature-' . $signedAt->format('Ymd-His') . '-' . Str::random(6) . '.png';
        Storage::disk('public')->put($sigPath, $binary);

        // The filed copy is a PDF: the full agreement with the signature image and
        // the date rendered into it, so the record stands on its own.
        $pdf = $this->renderPdf($user, $data['full_name'], $signedAt->toDayDateTimeString(), $data['signature']);
        $pdfPath = $dir . '/privacy-nda-v' . self::AGREEMENT_VERSION . '-' . $signedAt->format('Ymd-His') . '.pdf';
        Storage::disk('public')->put($pdfPath, $pdf);

        $signedId = DB::table('signed_documents')->insertGetId([
            'agency_id' => $agencyId,
            'document_type' => self::TYPE,
            'source_table' => 'users',
            'source_id' => $user->id,
            'signer_user_id' => $user->id,
            'signer_name' => $data['full_name'],
            'signature_data' => '/storage/' . $sigPath,
            'document_hash' => $this->documentHash(),
            'signed_at' => $signedAt,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        if (Schema::hasTable('documents')) {
            DB::table('documents')->insert([
                'scope_type' => 'user',
                'scope_id' => $user->id,
                'category' => 'agreement',
                'title' => 'Terms, Privacy & NDA (v' . self::AGREEMENT_VERSION . ')',
                'file_url' => '/storage/' . $pdfPath,
                'file_type' => 'application/pdf',
                'file_size' => strlen($pdf),
                'signed_at' => $signedAt,
                'signed_by_id' => $user->id,
                'signature_url' => '/storage/' . $sigPath,
                'uploaded_by_id' => $user->id,
                'created_at' => now(),
            ]);
        }

        try {
            $this->emailSignedCopy(
                (string) $user->email,
                trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $data['full_name'],
                $data['full_name'],
                $signedAt->toDayDateTimeString(),
                $pdfPath
            );
        } catch (\Throwable $e) {
            // A failed email must never invalidate a signature we have already recorded.
        }

        return response()->json([
            'ok' => true,
            'signed_document_id' => $signedId,
            'signed_at' => $signedAt->toIso8601String(),
            'document_url' => '/storage/' . $pdfPath,
        ]);
    }

    /**
     * The user declined. They are not onboarded: the account is marked, and the
     * agency's admins plus info@kiddietrac.com (bcc) are told so somebody can
     * follow up with them.
     */
    public function decline(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        $user = $request->user();
        $agencyId = $this->agencyIdFor((int) $user->id);
        $at = now($this->agencyTz($agencyId));

        DB::table('signed_documents')->insert([
            'agency_id' => $agencyId,
            'document_type' => self::TYPE . '_declined',
            'source_table' => 'users',
            'source_id' => $user->id,
            'signer_user_id' => $user->id,
            'signer_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            // signature_data is NOT NULL on this table, and a decline has no
            // signature — record the fact rather than a picture.
            'signature_data' => 'DECLINED',
            'document_hash' => $this->documentHash(),
            'signed_at' => $at,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        // Block the account. users.status is an enum — 'suspended' is the existing
        // state meaning "cannot use the platform"; the signed_documents row above
        // records WHY. (An invented value would be silently rejected by MySQL.)
        try {
            DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);
        } catch (\Throwable $e) {
            // Never fail the request over this — the decline is already recorded.
        }

        try {
            $this->emailDeclineAlert($user, $agencyId, $at->toDayDateTimeString(), $data['reason'] ?? null);
        } catch (\Throwable $e) {
        }

        // Revoke the session — declining means no access.
        try { $user->tokens()->delete(); } catch (\Throwable $e) {}

        return response()->json([
            'ok' => true,
            'declined' => true,
            'message' => 'You have declined the agreement. Your centre has been notified.',
        ]);
    }

    // ── Emails ──────────────────────────────────────────────────────────

    /** Emails the signer their copy, with the PDF attached. */
    public function emailSignedCopy(string $email, string $toName, string $signerName, string $signedAtHuman, string $pdfPath): void
    {
        $body = '<p style="margin:0 0 14px;">Hi ' . e($toName) . ',</p>'
            . '<p style="margin:0 0 14px;line-height:1.6;">Thank you — you have accepted the KiddieTrac '
            . '<strong>Terms of Use, Privacy Policy &amp; Non-Disclosure Agreement</strong>. '
            . 'Your signed copy is attached as a PDF for your records.</p>'
            . EmailTemplate::calloutBox(
                '<strong>Signed by:</strong> ' . e($signerName) . '<br>'
                . '<strong>Date:</strong> ' . e($signedAtHuman) . '<br>'
                . '<strong>Version:</strong> ' . self::AGREEMENT_VERSION,
                'info'
            )
            . '<p style="margin:16px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
            . 'Please keep this for your records. If you did not sign this, contact your centre immediately.</p>';

        $html = EmailTemplate::wrap(null, $body, [
            'eyebrow' => 'AGREEMENT SIGNED',
            'title' => 'Terms, Privacy & NDA',
            'subtitle' => 'Your signed copy is attached',
            'preheader' => 'Your signed copy of the KiddieTrac Terms of Use, Privacy Policy & NDA.',
        ]);

        $subject = 'Your signed copy — KiddieTrac Terms, Privacy & NDA';
        $absPdf = Storage::disk('public')->path($pdfPath);
        $attachName = 'KiddieTrac-Agreement-' . self::AGREEMENT_VERSION . '.pdf';

        dispatch(function () use ($email, $toName, $html, $subject, $absPdf, $attachName) {
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($email, $toName, $subject, $absPdf, $attachName) {
                $m->to($email, $toName)
                  ->from('noreply@kiddietrac.com', 'Kiddietrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject);
                if (is_file($absPdf)) {
                    $m->attach($absPdf, ['as' => $attachName, 'mime' => 'application/pdf']);
                }
                $m->getHeaders()->addTextHeader('X-KT-Logged', '1');
            });
        })->onQueue('mail');

        $this->logEmail($email, $toName, $subject);
    }

    /** Tells the agency (and info@kiddietrac.com, bcc) that someone declined. */
    private function emailDeclineAlert($user, ?int $agencyId, string $atHuman, ?string $reason): void
    {
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) $user->email;
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;

        // Agency admins + directors of that agency get told.
        $recipients = [];
        if ($agencyId) {
            $recipients = DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.agency_id', $agencyId)
                ->whereIn('ra.role', ['agency_admin', 'centre_director'])
                ->where('ra.active', true)
                ->whereNull('u.deleted_at')
                ->pluck('u.email')->unique()->values()->all();
        }
        if (!$recipients && $agency && !empty($agency->contact_email)) {
            $recipients = [$agency->contact_email];
        }

        $body = '<p style="margin:0 0 14px;line-height:1.6;">'
            . '<strong>' . e($name) . '</strong> (' . e((string) $user->email) . ') has <strong>declined</strong> the '
            . 'Terms of Use, Privacy Policy &amp; Non-Disclosure Agreement, and has <strong>not</strong> been onboarded onto KiddieTrac.'
            . '</p>'
            . EmailTemplate::calloutBox(
                '<strong>User:</strong> ' . e($name) . '<br>'
                . '<strong>Email:</strong> ' . e((string) $user->email) . '<br>'
                . '<strong>Agency:</strong> ' . e($agency->name ?? '—') . '<br>'
                . '<strong>Declined at:</strong> ' . e($atHuman) . '<br>'
                . '<strong>Version:</strong> ' . self::AGREEMENT_VERSION
                . ($reason ? '<br><strong>Reason given:</strong> ' . e($reason) : ''),
                'warning'
            )
            . '<p style="margin:16px 0 0;line-height:1.6;">Their account is blocked from the platform until the agreement is accepted. '
            . 'Someone should follow up with them.</p>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'ACTION NEEDED',
            'title' => 'Agreement declined',
            'subtitle' => $name . ' has not been onboarded',
            'preheader' => $name . ' declined the KiddieTrac agreement and was not onboarded.',
        ]);

        $subject = 'Agreement declined — ' . $name . ' was not onboarded';
        $to = $recipients;

        dispatch(function () use ($to, $html, $subject) {
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($to, $subject) {
                if ($to) $m->to($to);
                else $m->to('info@kiddietrac.com', 'Kiddietrac');
                // KiddieTrac always gets a copy, quietly.
                $m->bcc('info@kiddietrac.com', 'Kiddietrac');
                $m->from('noreply@kiddietrac.com', 'Kiddietrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject);
                $m->getHeaders()->addTextHeader('X-KT-Logged', '1');
            });
        })->onQueue('mail');

        $this->logEmail($to[0] ?? 'info@kiddietrac.com', 'Agency admin', $subject);
    }

    private function logEmail(string $email, string $toName, string $subject): void
    {
        if (!Schema::hasTable('email_logs')) return;
        DB::table('email_logs')->insert([
            'to_email' => $email, 'to_name' => $toName,
            'from_email' => 'noreply@kiddietrac.com', 'subject' => $subject,
            'mailer' => config('mail.default'), 'status' => 'sent',
            'tracking_token' => Str::random(32), 'opens' => 0, 'created_at' => now(),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function agencyIdFor(int $userId): ?int
    {
        $id = DB::table('role_assignments')
            ->where('user_id', $userId)->where('active', true)
            ->whereNotNull('agency_id')
            ->value('agency_id');
        if ($id) return (int) $id;

        // Guardians hold no agency role — reach it through their family's centre.
        return DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('g.user_id', $userId)
            ->value('c.agency_id');
    }

    /** Times are shown in the AGENCY's timezone, not the server's UTC. */
    private function agencyTz(?int $agencyId): string
    {
        $tz = $agencyId ? DB::table('agencies')->where('id', $agencyId)->value('timezone') : null;
        return $tz ?: (config('app.display_timezone') ?: 'America/Toronto');
    }

    private function documentHash(): string
    {
        return hash('sha256', self::AGREEMENT_VERSION . '|' . $this->agreementHtml());
    }

    /** The signed PDF: the full agreement, with the signature and date rendered in. */
    private function renderPdf($user, string $signerName, string $signedAtHuman, string $signatureDataUrl): string
    {
        $name = e(trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;color:#0F172A;font-size:11px;line-height:1.55;}'
            . 'h1{font-size:17px;margin:0 0 2px;} h2{font-size:12.5px;margin:16px 0 4px;}'
            . 'h3{font-size:12px;margin:14px 0 3px;} p,li{font-size:11px;} ul{margin:4px 0 4px 14px;padding:0;}'
            . '.muted{color:#64748B;font-size:10px;} .sig{border-bottom:1px solid #94A3B8;max-width:280px;}'
            . '.box{border:1px solid #CBD5E1;border-radius:6px;padding:10px;margin-top:10px;}'
            . '</style></head><body>'
            . '<h1>KiddieTrac — Terms of Use, Privacy Policy &amp; Non-Disclosure Agreement</h1>'
            . '<div class="muted">Version ' . self::AGREEMENT_VERSION . '</div>'
            . $this->agreementHtml()
            . '<div class="box">'
            . '<h2>Signature</h2>'
            . '<img class="sig" src="' . $signatureDataUrl . '" alt="Signature">'
            . '<p><strong>Signed by:</strong> ' . e($signerName) . '<br>'
            . '<strong>Account:</strong> ' . $name . ' (' . e((string) $user->email) . ')<br>'
            . '<strong>Date and time:</strong> ' . e($signedAtHuman) . '</p>'
            . '</div>'
            . '</body></html>';

        $opts = new Options();
        $opts->set('isRemoteEnabled', true);
        $opts->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The agreement. One source of truth: shown in the app, rendered into the
     * signed PDF, and emailed — so the three can never drift apart.
     *
     * NOTE: this is a solid general-purpose draft, not legal advice. Have counsel
     * review it, and bump AGREEMENT_VERSION whenever a word of it changes.
     */
    private function agreementHtml(): string
    {
        return <<<'HTML'
<h2>Part A — Terms of Use</h2>

<h3>A1. Acceptance</h3>
<p>KiddieTrac ("the Platform") is provided by Kiddietrac ("we", "us"). By accessing or using the
Platform you accept these Terms of Use, the Privacy Policy in Part B, and the Confidentiality and
Intellectual Property obligations in Part C. If you do not accept them, you may not use the Platform.</p>

<h3>A2. Your account</h3>
<p>Your account is personal to you. You are responsible for everything done under it. You will keep
your credentials secret, never share your login, and tell us immediately if you suspect your account
has been used by anyone else. Access is granted by role: you may only use the access you have been
given, for the purpose it was given.</p>

<h3>A3. Acceptable use</h3>
<p>You agree that you will not, and will not attempt to:</p>
<ul>
  <li>access data, accounts, centres or agencies you are not authorised to access;</li>
  <li>copy, scrape, export or bulk-extract data from the Platform except through features provided
      to you for that purpose;</li>
  <li>probe, scan, penetration-test, reverse engineer, decompile or disassemble any part of the
      Platform, or circumvent any security or access control;</li>
  <li>upload malicious code, or anything unlawful, abusive, or harmful to a child;</li>
  <li>interfere with the operation of the Platform, or with any other user's use of it;</li>
  <li>use the Platform to build, train, benchmark or specify a competing product or service.</li>
</ul>

<h3>A4. Availability, changes and suspension</h3>
<p>We work to keep the Platform available, but we do not guarantee uninterrupted service. We may
change, add or remove features. We may suspend or terminate access — immediately, and without
refund — if you breach this agreement, or where we reasonably believe access presents a risk to
children, to other users, or to the Platform.</p>

<h3>A5. Content you submit</h3>
<p>You keep ownership of the content you submit (records, notes, photographs). You grant us the
licence we need to host, process, back up and display that content in order to operate the service
for your centre. You confirm that you have the right and any necessary consent to submit it —
particularly photographs of children.</p>

<h3>A6. Liability</h3>
<p>The Platform supports childcare operations; it does not replace professional judgement,
supervision, or your legal obligations. To the fullest extent permitted by law, we are not liable
for indirect or consequential loss, and our total liability is limited to the fees paid for the
service in the twelve months before the claim.</p>

<h2>Part B — Privacy Policy</h2>

<h3>B1. Information we hold</h3>
<p>We hold information about children (names, dates of birth, photographs, health, allergy and
medical information, attendance, and daily-care records), their families and guardians (contact
details, addresses, billing information), and staff (contact details, role, qualifications, hours
worked). We hold it to operate the childcare service, to meet legal and licensing obligations, and
to keep children safe.</p>

<h3>B2. How information is used</h3>
<p>Information is used only to deliver and administer childcare, to communicate with families, and
to satisfy legal, licensing, funding, and health-and-safety requirements. <strong>We do not sell
personal information.</strong> We do not use children's photographs for marketing without separate,
explicit, written consent.</p>

<h3>B3. Sharing</h3>
<p>Information is shared only with: the staff at the child's centre who need it to provide care;
the child's authorised guardians; service providers who host or process data on our behalf under
contract; and authorities where the law or a licensing obligation requires it.</p>

<h3>B4. Security and retention</h3>
<p>Access is controlled by role, activity is logged, and information is transmitted over encrypted
connections. Records are kept for as long as the service and the law require, and are then deleted
or anonymised in line with the agency's retention policy.</p>

<h3>B5. Your rights</h3>
<p>You may ask what personal information we hold about you, ask for it to be corrected, and ask for
a copy. Where the law allows, you may ask for it to be deleted. Records we are legally required to
retain (for example attendance and incident records) will be kept for the required period.</p>

<h2>Part C — Confidentiality, Non-Disclosure &amp; Intellectual Property</h2>

<h3>C1. Confidential Information</h3>
<p>"Confidential Information" means anything you learn through the Platform that is not public,
including: information about children, families, guardians and colleagues; and information about
KiddieTrac itself — its <strong>design, screens, layouts, workflows, features, functionality,
roadmap, algorithms, prompts, data models, source code, pricing, and any non-public documentation
or demonstration</strong>.</p>

<h3>C2. Your confidentiality obligations</h3>
<p>You agree that you will:</p>
<ul>
  <li>access only the Confidential Information you need to do your job;</li>
  <li>never share, copy, photograph, screen-record, forward, publish or discuss Confidential
      Information with anyone not authorised to receive it — including on social media, in
      messaging apps, or in public forums;</li>
  <li><strong>not disclose or demonstrate the Platform, or any part of its design, features or
      functionality, to any competitor of KiddieTrac, or to anyone who is developing, or intends to
      develop, a competing product or service;</strong></li>
  <li>report any suspected loss, theft or unauthorised disclosure immediately;</li>
  <li>return or securely delete any Confidential Information in your possession when your access
      ends.</li>
</ul>
<p>These obligations survive the end of your access and the end of your role, and continue for as
long as the information remains confidential.</p>

<h3>C3. Our intellectual property</h3>
<p>The Platform — including its software, source code, design, user interface, screen layouts,
visual style, workflows, feature set, functionality, documentation, branding, trade marks and all
related intellectual property — is and remains the exclusive property of Kiddietrac. Nothing in
this agreement transfers any ownership to you. You are granted only a limited, revocable,
non-exclusive, non-transferable right to use the Platform for its intended purpose, for as long as
your access is authorised.</p>

<h3>C4. No copying or imitation</h3>
<p>You will not copy, reproduce, adapt, translate, or create derivative works from any part of the
Platform, and you will not <strong>replicate or imitate its design, screens, workflows or feature
set</strong> in another product or service. You will not remove or obscure any proprietary notice.
Any feedback or suggestion you give us may be used by us freely, without obligation or payment.</p>

<h3>C5. Breach</h3>
<p>Unauthorised disclosure or use of Confidential Information, or infringement of our intellectual
property, may cause harm for which money is not an adequate remedy. We may therefore seek an
injunction and any other relief available to us, in addition to any damages. A breach may also lead
to disciplinary action, termination of access and of employment, and — where the law requires —
notification of affected families and of the relevant authorities.</p>

<h2>Part D — Agreement</h2>
<p>By signing below you confirm that you have read and understood these Terms of Use, this Privacy
Policy, and these Confidentiality and Intellectual Property obligations, and that you agree to be
bound by them. If you decline, you will not be onboarded onto the Platform, and your centre will be
notified.</p>
HTML;
    }
}
