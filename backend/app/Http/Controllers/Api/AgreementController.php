<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 2026-07-13 — Onboarding: Privacy Policy & Non-Disclosure Agreement.
 *
 * Every new user (any role) must read and sign this once before using the app.
 * Signing records WHO signed, WHEN, from WHERE, and WHAT they signed:
 *
 *   signed_documents — the legal record (signer, hash of the exact text they saw,
 *                      signature image, timestamp, IP, user agent).
 *   documents        — the countersigned copy, filed against the user's record
 *                      (scope_type=user) so it shows up as an attachment.
 *   + an emailed copy to the user, for their own records.
 *
 * The hash matters: bump AGREEMENT_VERSION whenever the text below changes and
 * everyone is asked to re-sign, and the stored hash proves which wording each
 * person actually agreed to.
 */
class AgreementController extends Controller
{
    public const TYPE = 'privacy_nda';
    public const AGREEMENT_VERSION = '1.0';

    /** Has this user signed the current version? */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $row = DB::table('signed_documents')
            ->where('signer_user_id', $user->id)
            ->where('document_type', self::TYPE)
            ->orderByDesc('signed_at')
            ->first();

        $currentHash = $this->documentHash();
        $signed = $row && $row->document_hash === $currentHash;

        return response()->json([
            'required' => !$signed,
            'version' => self::AGREEMENT_VERSION,
            'signed_at' => $row->signed_at ?? null,
            'title' => 'Privacy Policy & Non-Disclosure Agreement',
            'body_html' => $this->agreementHtml(),
        ]);
    }

    public function sign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:120',
            // A PNG data URL from the signature pad. Capped so a huge canvas
            // can't be used to push megabytes into the database.
            'signature' => 'required|string|max:2000000',
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

        $signedAt = now();
        $agencyId = $this->agencyIdFor((int) $user->id);

        // Signature image + a human-readable copy of exactly what was signed.
        $dir = 'agreements/' . $user->id;
        $sigPath = $dir . '/signature-' . $signedAt->format('Ymd-His') . '-' . Str::random(6) . '.png';
        Storage::disk('public')->put($sigPath, $binary);

        $copyHtml = $this->signedCopyHtml($user, $data['full_name'], $signedAt->toDayDateTimeString(), $sigPath);
        $docPath = $dir . '/privacy-nda-' . $signedAt->format('Ymd-His') . '.html';
        Storage::disk('public')->put($docPath, $copyHtml);

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
            'created_at' => $signedAt,
        ]);

        // File it against the user's record so it appears as an attachment.
        if (\Illuminate\Support\Facades\Schema::hasTable('documents')) {
            DB::table('documents')->insert([
                'scope_type' => 'user',
                'scope_id' => $user->id,
                'category' => 'agreement',
                'title' => 'Privacy Policy & NDA (v' . self::AGREEMENT_VERSION . ')',
                'file_url' => '/storage/' . $docPath,
                'file_type' => 'text/html',
                'file_size' => strlen($copyHtml),
                'signed_at' => $signedAt,
                'signed_by_id' => $user->id,
                'signature_url' => '/storage/' . $sigPath,
                'uploaded_by_id' => $user->id,
                'created_at' => $signedAt,
            ]);
        }

        // Email the signer their copy (queued — never blocks the request).
        try {
            $this->emailCopy(
                (string) $user->email,
                trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $data['full_name'],
                $data['full_name'],
                $signedAt->toDayDateTimeString(),
                $sigPath
            );
        } catch (\Throwable $e) {
            // A failed email must not invalidate a signature that is already recorded.
        }

        return response()->json([
            'ok' => true,
            'signed_document_id' => $signedId,
            'signed_at' => $signedAt->toIso8601String(),
            'document_url' => '/storage/' . $docPath,
        ]);
    }

    /** Emails a copy of the signed agreement. Also used by the CLI test send. */
    public function emailCopy(string $email, string $toName, string $signerName, string $signedAtHuman, ?string $sigPath = null): void
    {
        $absSig = $sigPath ? rtrim(config('app.url', 'https://api.kiddietrac.com'), '/') . '/storage/' . $sigPath : null;

        $body = '<p style="margin:0 0 14px;">Hi ' . e($toName) . ',</p>'
            . '<p style="margin:0 0 14px;line-height:1.6;">Thank you — you have signed the KiddieTrac '
            . '<strong>Privacy Policy &amp; Non-Disclosure Agreement</strong>. This email is your copy for your records.</p>'
            . EmailTemplate::calloutBox(
                '<strong>Signed by:</strong> ' . e($signerName) . '<br>'
                . '<strong>Date:</strong> ' . e($signedAtHuman) . '<br>'
                . '<strong>Version:</strong> ' . self::AGREEMENT_VERSION,
                'info'
            )
            . ($absSig ? '<p style="margin:16px 0 6px;font-size:12px;color:#64748B;font-weight:700;">YOUR SIGNATURE</p>'
                . '<img src="' . e($absSig) . '" alt="Signature" style="max-width:280px;border-bottom:1px solid #CBD5E1;">' : '')
            . '<hr style="border:none;border-top:1px solid #E2E8F0;margin:22px 0;">'
            . '<div style="font-size:13px;line-height:1.6;color:#334155;">' . $this->agreementHtml() . '</div>';

        $html = EmailTemplate::wrap(null, $body, [
            'eyebrow' => 'AGREEMENT SIGNED',
            'title' => 'Privacy Policy & NDA',
            'subtitle' => 'Your signed copy',
            'preheader' => 'Your signed copy of the KiddieTrac Privacy Policy & Non-Disclosure Agreement.',
        ]);

        $subject = 'Your signed copy — KiddieTrac Privacy Policy & NDA';

        dispatch(function () use ($email, $toName, $html, $subject) {
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($email, $toName, $subject) {
                $m->to($email, $toName)
                  ->from('noreply@kiddietrac.com', 'Kiddietrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject);
                $m->getHeaders()->addTextHeader('X-KT-Logged', '1');
            });
        })->onQueue('mail');

        if (\Illuminate\Support\Facades\Schema::hasTable('email_logs')) {
            DB::table('email_logs')->insert([
                'to_email' => $email, 'to_name' => $toName,
                'from_email' => 'noreply@kiddietrac.com', 'subject' => $subject,
                'mailer' => config('mail.default'), 'status' => 'sent',
                'tracking_token' => Str::random(32), 'opens' => 0, 'created_at' => now(),
            ]);
        }
    }

    private function agencyIdFor(int $userId): ?int
    {
        return DB::table('role_assignments')
            ->where('user_id', $userId)->where('active', true)
            ->whereNotNull('agency_id')
            ->value('agency_id');
    }

    /** Hash of the exact wording, so we can prove what each person agreed to. */
    private function documentHash(): string
    {
        return hash('sha256', self::AGREEMENT_VERSION . '|' . $this->agreementHtml());
    }

    private function signedCopyHtml($user, string $signerName, string $signedAtHuman, string $sigPath): string
    {
        $name = e(trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
        return '<!doctype html><meta charset="utf-8"><title>Privacy Policy &amp; NDA — signed</title>'
            . '<div style="font-family:system-ui,sans-serif;max-width:720px;margin:24px auto;color:#0F172A;">'
            . '<h1 style="font-size:22px;">Privacy Policy &amp; Non-Disclosure Agreement</h1>'
            . '<p style="color:#64748B;font-size:13px;">Version ' . self::AGREEMENT_VERSION . '</p>'
            . '<div style="font-size:14px;line-height:1.6;">' . $this->agreementHtml() . '</div>'
            . '<hr style="margin:26px 0;border:none;border-top:1px solid #E2E8F0;">'
            . '<h2 style="font-size:15px;">Signature</h2>'
            . '<img src="/storage/' . e($sigPath) . '" alt="Signature" style="max-width:320px;border-bottom:1px solid #94A3B8;">'
            . '<p style="font-size:14px;margin-top:10px;"><strong>Signed by:</strong> ' . e($signerName)
            . '<br><strong>Account:</strong> ' . $name . ' (' . e((string) $user->email) . ')'
            . '<br><strong>Date:</strong> ' . e($signedAtHuman) . '</p>'
            . '</div>';
    }

    /**
     * The agreement text. Deliberately plain — it is shown in the app, stored in
     * the signed copy, and emailed, all from this one source, so the three can
     * never drift apart.
     */
    private function agreementHtml(): string
    {
        return <<<'HTML'
<h3>1. Purpose</h3>
<p>KiddieTrac is used to record and share information about children in care. Much of that
information is personal and sensitive. This agreement sets out how we handle it, and what we
ask of you as a user of the platform.</p>

<h3>2. Information we hold</h3>
<p>We hold information about children (names, dates of birth, photos, health and allergy
information, attendance and daily-care records), their families and guardians (contact details,
addresses, billing information), and staff (contact details, role, hours worked). We hold it to
operate the childcare service, to meet legal and licensing obligations, and to keep children safe.</p>

<h3>3. How information is used</h3>
<p>Information is only used to deliver and administer childcare, to communicate with families,
and to satisfy legal, licensing, funding and health-and-safety requirements. We do not sell
personal information. We do not use children's photographs for marketing without separate,
explicit consent.</p>

<h3>4. Your obligations — confidentiality</h3>
<p>In the course of using KiddieTrac you will see confidential information about children,
families and colleagues. You agree that you will:</p>
<ul>
  <li>access only the information you need to do your job;</li>
  <li>never share, copy, photograph, forward or discuss confidential information with anyone
      who is not authorised to receive it — including on social media or messaging apps;</li>
  <li>keep your account credentials private, and never let anyone else use your login;</li>
  <li>report any suspected loss, theft, or unauthorised disclosure of information immediately;</li>
  <li>return or delete any confidential information in your possession when you leave.</li>
</ul>
<p>These obligations continue after you stop using KiddieTrac or leave your role.</p>

<h3>5. Security</h3>
<p>Access is controlled by role, activity is logged, and information is transmitted over encrypted
connections. You must not attempt to access records outside your role, or to circumvent any
security control.</p>

<h3>6. Your rights</h3>
<p>You may ask what personal information we hold about you, ask for it to be corrected, and ask
for a copy. Where the law allows, you may ask for it to be deleted. Records we are legally
required to retain (for example, attendance and incident records) will be kept for the required
period.</p>

<h3>7. Breaches</h3>
<p>Any breach of confidentiality may lead to disciplinary action, termination, and — where the law
requires — notification to the affected families and to the relevant authorities.</p>

<h3>8. Agreement</h3>
<p>By signing below you confirm that you have read and understood this Privacy Policy and
Non-Disclosure Agreement, and that you agree to be bound by it.</p>
HTML;
    }
}
