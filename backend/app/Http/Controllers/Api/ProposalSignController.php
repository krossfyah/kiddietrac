<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesQuote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * The prospect's side of online proposal signing (2026-10-16, Anthony: "can we make this where
 * it can be signed virtually and countersigned by kiddietrac virtually to complete the proposal
 * process"). Public, token-only: app.kiddietrac.com/proposal.html?t=… The token is the 40-char
 * sign_token on sales_quotes; it is only ever sent to the lead's email (or copied by a rep).
 *
 * Signing records name, title, organisation, the drawn signature, IP, user agent and a SHA-256
 * fingerprint of the proposal as printed at that moment, and locks the proposal against edits
 * (SalesController::quoteUpdate). KiddieTrac then countersigns in the CRM.
 */
class ProposalSignController extends Controller
{
    private const TZ = 'America/Toronto';

    private function byToken(string $token): ?SalesQuote
    {
        return SalesQuote::with('items', 'lead')->where('sign_token', $token)->first();
    }

    private function sales(): SalesController
    {
        return app(SalesController::class);
    }

    /** Past its valid-until date (end of that day, Toronto) and not yet signed. */
    private function expired(SalesQuote $q): bool
    {
        if (! $q->valid_until || $q->client_signed_at) return false;
        return now(self::TZ)->startOfDay()->gt($q->valid_until->copy()->shiftTimezone(self::TZ)->startOfDay());
    }

    private function state(SalesQuote $q): string
    {
        if ($q->countersigned_at) return 'complete';
        if ($q->client_signed_at) return 'awaiting_countersign';
        if ($q->status === 'declined') return 'declined';
        if ($this->expired($q)) return 'expired';
        return 'open';
    }

    private function human($d): string
    {
        return $d ? $d->copy()->setTimezone(self::TZ)->format('M j, Y g:i A T') : '';
    }

    /** GET /public/proposal/{token} */
    public function show(string $token): JsonResponse
    {
        $q = $this->byToken($token);
        if (! $q) {
            return response()->json(['message' => 'This link is not valid. Please use the button in your email, or contact sales@kiddietrac.com.'], 404);
        }
        if (! $q->sign_viewed_at) {
            $q->sign_viewed_at = now();
            $q->save();
            try { $this->sales()->logOnProposalLead($q, 'note', "👁 Proposal {$q->number} opened from the signing link"); } catch (\Throwable $e) {}
            // Opened by the customer: it has gone out, however the link reached them.
            $this->sales()->proposalWentOut($q, 'opened by the customer');
        }
        $pf = SalesController::preparedForOf($q);
        $pb = SalesController::preparedByOf($q);

        return response()->json([
            'number' => $q->number,
            'title' => $q->title,
            'state' => $this->state($q),
            'valid_until' => $q->valid_until ? $q->valid_until->format('F j, Y') : null,
            'prepared_for' => ['name' => $pf['name'], 'title' => $pf['title'], 'company' => $pf['company']],
            'prepared_by' => ['name' => $pb['name'], 'email' => $pb['email'], 'phone' => $pb['phone']],
            'client' => $q->client_signed_at ? ['name' => $q->client_signer_name, 'title' => $q->client_signer_title, 'org' => $q->client_signer_org, 'at' => $this->human($q->client_signed_at)] : null,
            'countersigned' => $q->countersigned_at ? ['name' => $q->countersigner_name, 'at' => $this->human($q->countersigned_at)] : null,
            'html' => $this->sales()->proposalHtmlFor($q),
        ]);
    }

    /** POST /public/proposal/{token}/sign */
    public function sign(Request $request, string $token): JsonResponse
    {
        $q = $this->byToken($token);
        if (! $q) return response()->json(['message' => 'This link is not valid.'], 404);
        $state = $this->state($q);
        if ($state !== 'open') {
            $msg = ['awaiting_countersign' => 'This proposal has already been signed.', 'complete' => 'This proposal has already been signed by both parties.',
                'declined' => 'This proposal was declined. Please contact us if you would like a new one.',
                'expired' => 'This proposal has expired. Please contact us and we will send you an updated one.'][$state] ?? 'This proposal cannot be signed.';
            return response()->json(['message' => $msg], $state === 'expired' ? 410 : 422);
        }
        $d = $request->validate([
            'signer_name'  => ['required', 'string', 'min:2', 'max:160'],
            'signer_title' => ['required', 'string', 'min:2', 'max:160'],
            'organisation' => ['required', 'string', 'min:2', 'max:255'],
            'signature'    => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:600000'],
            'agree'        => ['accepted'],
        ]);
        $png = base64_decode(substr($d['signature'], strlen('data:image/png;base64,')), true);
        if (! $png || strlen($png) < 200 || @getimagesizefromstring($png) === false) {
            return response()->json(['message' => 'Please draw your signature.', 'errors' => ['signature' => ['Please draw your signature.']]], 422);
        }

        // Fingerprint of exactly what they are signing (before the signature is added).
        $hash = hash('sha256', $this->sales()->proposalHtmlFor($q, true));

        $updated = SalesQuote::where('id', $q->id)->whereNull('client_signed_at')->update([
            'client_signed_at' => now(), 'client_signer_name' => trim($d['signer_name']), 'client_signer_title' => trim($d['signer_title']),
            'client_signer_org' => trim($d['organisation']), 'client_signature' => $d['signature'],
            'client_sign_ip' => substr((string) $request->ip(), 0, 45), 'client_sign_ua' => substr((string) $request->userAgent(), 0, 300),
            'document_hash' => $hash, 'status' => 'signed', 'sent_at' => $q->sent_at ?: now(), 'updated_at' => now(),
        ]);
        if (! $updated) {
            return response()->json(['message' => 'This proposal has already been signed.'], 422);
        }
        $q = $this->byToken($token);
        $human = $this->human($q->client_signed_at);
        $this->sales()->proposalWentOut($q, 'signed by the customer');   // stage + the signed proposal is now the estimate

        $mail = $this->mailThanks($q, $human);
        try {
            $this->sales()->logOnProposalLead($q, 'note', "✍️ Proposal {$q->number} signed online by {$q->client_signer_name} ({$q->client_signer_title}) for {$q->client_signer_org} — {$human}. "
                . "Waiting for KiddieTrac to countersign (Proposals tab → ⋮ → Countersign). Confirmation to the customer: {$mail}.");
        } catch (\Throwable $e) {
            Log::warning('proposal signed: lead log failed', ['quote' => $q->id, 'error' => $e->getMessage()]);
        }
        try {
            \App\Support\Audit::write(['user_id' => null, 'agency_id' => null, 'action' => 'sales_quote.client_signed', 'entity_type' => 'sales_quote', 'entity_id' => (int) $q->id,
                'payload' => json_encode(['summary' => "Proposal {$q->number} signed by {$q->client_signer_name} ({$q->client_signer_title}, {$q->client_signer_org}); hash {$hash}"]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'signed_at' => $human,
            'message' => 'Thank you, ' . $q->client_signer_name . '. Your signature has been recorded. KiddieTrac will countersign and email you the fully signed copy.']);
    }

    /** POST /public/proposal/{token}/decline */
    public function decline(Request $request, string $token): JsonResponse
    {
        $q = $this->byToken($token);
        if (! $q) return response()->json(['message' => 'This link is not valid.'], 404);
        if ($this->state($q) !== 'open') {
            return response()->json(['message' => 'This proposal can no longer be declined here.'], 422);
        }
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $q->status = 'declined';
        $q->declined_at = now();
        $q->decline_reason = trim((string) ($d['reason'] ?? '')) ?: null;
        $q->save();
        $this->sales()->syncLeadValueForQuote($q);   // a declined proposal no longer sets the estimate
        try {
            $this->sales()->logOnProposalLead($q, 'note', "Proposal {$q->number} declined online" . ($q->decline_reason ? ': “' . $q->decline_reason . '”' : ' (no reason given)'));
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'message' => 'Thank you for letting us know. Your KiddieTrac contact may follow up to see if anything could work better for you.']);
    }

    /** GET /public/proposal/{token}/pdf — their copy: fully signed once complete, otherwise as it stands. */
    public function pdf(string $token)
    {
        $q = $this->byToken($token);
        abort_unless($q, 404);
        if ($q->signed_pdf_path && Storage::disk('local')->exists($q->signed_pdf_path)) {
            $bytes = Storage::disk('local')->get($q->signed_pdf_path);
            $name = ($q->number ?: 'proposal') . '-signed.pdf';
        } else {
            $bytes = $this->sales()->proposalPdfFor($q);
            $name = ($q->number ?: 'proposal') . '.pdf';
        }

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /** "Thank you — we will countersign": to the address the link was sent to. */
    private function mailThanks(SalesQuote $q, string $human): string
    {
        $lead = $q->lead;
        if (! $lead || ! $lead->email) return 'no email on the lead';
        $pb = SalesController::preparedByOf($q);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = \App\Services\EmailTemplate::wrap(null,
            '<p style="margin:0 0 14px;line-height:1.6">Hi ' . $e(strtok((string) $q->client_signer_name, ' ')) . ',</p>'
            . '<p style="margin:0 0 14px;line-height:1.6">Thank you for signing KiddieTrac proposal <strong>' . $e($q->number) . '</strong>. Your signature was recorded on ' . $e($human) . '.</p>'
            . '<p style="margin:0 0 14px;line-height:1.6">KiddieTrac will now countersign, and you will receive the fully signed copy by email. If you have any questions in the meantime, just reply to this email.</p>'
            . '<p style="margin:0;font-size:12.5px;color:#64748B;line-height:1.6">If you did not sign this proposal, please contact us right away at ' . $e($pb['email'] ?: 'sales@kiddietrac.com') . '.</p>',
            ['force_brand' => 'kt', 'eyebrow' => 'PROPOSAL SIGNED', 'title' => 'Thank you for signing', 'subtitle' => $q->number, 'preheader' => 'We have your signature. KiddieTrac will countersign next.']);
        $before = (int) DB::table('email_logs')->max('id');
        try {
            Mail::html($html, function ($m) use ($q, $lead, $pb) {
                \App\Support\MailScope::platform($m);
                $m->to($lead->email, $q->client_signer_name ?: null)->subject('Thank you for signing: KiddieTrac proposal ' . $q->number);
                if (! empty($pb['email']) && filter_var($pb['email'], FILTER_VALIDATE_EMAIL)) $m->replyTo($pb['email'], $pb['name'] ?: 'KiddieTrac Sales');
            });
        } catch (\Throwable $e) {
            return 'failed: ' . mb_substr($e->getMessage(), 0, 120);
        }
        $row = DB::table('email_logs')->where('id', '>', $before)->where('to_email', 'like', '%' . strtolower($lead->email) . '%')->orderByDesc('id')->first(['status']);
        return 'emailed to ' . $lead->email . ($row && $row->status !== 'sent' ? ' (' . $row->status . ')' : '');
    }
}
