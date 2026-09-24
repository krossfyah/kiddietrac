<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * THE COPY OF WHAT THEY AGREED TO (2026-09-24).
 *
 * Anthony: "based on the opt'd in for the SMS document that was agreed to - can you
 * ensure that parents see's this in their documents."
 *
 * Consent was recorded on the user row - sms_opt_in, sms_opt_in_at, the source, and
 * the exact wording in sms_consent_text - and nowhere a parent could look at it. A
 * consent nobody can produce is the one a carrier, a regulator or an annoyed parent
 * asks to see, and "it is in a column" is not an answer to any of them.
 *
 * So opting in now files a PDF against the person, the same way AgreementController
 * files the signed NDA: scope_type 'user', which is what /auth/me/documents reads, so
 * it appears under My documents for the parent and on their record for the office.
 *
 * IDEMPOTENT PER VERSION. Opting out and back in, or an admin re-recording a paper
 * consent, must not leave three copies of the same agreement on one person. A NEW
 * version of the wording does file a new document, because that is a different
 * agreement and both need to be produceable.
 *
 * Never throws. A missing receipt is worth a log line; it is not worth failing the
 * opt-in that the parent actually asked for, which would leave them un-consented
 * because the paperwork broke.
 */
final class SmsConsentRecord
{
    public const CATEGORY = 'sms_consent';

    /**
     * @return int|null  the documents.id written, or null when nothing was
     */
    public static function file(
        int $userId,
        string $consentText,
        string $version,
        string $source,
        ?string $phone,
        ?Carbon $agreedAt = null,
        ?int $recordedBy = null
    ): ?int {
        try {
            $user = DB::table('users')->where('id', $userId)
                ->first(['id', 'first_name', 'last_name', 'email']);
            if (! $user) {
                return null;
            }

            $title = self::titleFor($version);

            // Already on file for this exact wording: nothing to add.
            $existing = DB::table('documents')
                ->where('scope_type', 'user')->where('scope_id', $userId)
                ->where('category', self::CATEGORY)
                ->where('title', $title)
                ->value('id');
            if ($existing) {
                return (int) $existing;
            }

            $agencyId = self::agencyOf($userId);
            $agency = $agencyId
                ? (string) DB::table('agencies')->where('id', $agencyId)->value('name')
                : 'KiddieTrac';

            $at = $agreedAt ?: now();
            $local = $at->copy()->timezone(AgencyTime::tz($agencyId ?: 0));

            $pdf = self::renderPdf($user, $agency, $consentText, $version, $source, $phone, $local);
            $path = 'sms-consent/' . $userId . '/sms-consent-' . $version . '-' . $at->format('Ymd-His') . '.pdf';
            Storage::disk('public')->put($path, $pdf);

            return (int) DB::table('documents')->insertGetId([
                'scope_type' => 'user',
                'scope_id' => $userId,
                'category' => self::CATEGORY,
                'title' => $title,
                'file_url' => '/storage/' . $path,
                'file_type' => 'application/pdf',
                'file_size' => strlen($pdf),
                // An agreement, so it carries the moment it was agreed rather than the
                // moment the file happened to be written.
                'signed_at' => $at,
                'signed_by_id' => $userId,
                'notes' => 'Agreed ' . $local->toDayDateTimeString() . ' - ' . self::sourceLabel($source),
                'uploaded_by_id' => $recordedBy ?: $userId,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('SMS consent receipt could not be filed', [
                'user' => $userId, 'e' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function titleFor(string $version): string
    {
        return 'SMS consent (' . $version . ')';
    }

    /** Has this person got their copy of the current wording? */
    public static function onFileFor(int $userId, string $version): bool
    {
        return DB::table('documents')
            ->where('scope_type', 'user')->where('scope_id', $userId)
            ->where('category', self::CATEGORY)
            ->where('title', self::titleFor($version))
            ->exists();
    }

    private static function sourceLabel(string $source): string
    {
        switch ($source) {
            // Same five source keys SmsConsentReceipt uses - one vocabulary, not two.
            case 'sms':        return 'replied to a text message from the handset';
            case 'app':        return 'agreed in the KiddieTrac app';
            case 'email_link': return 'agreed from the emailed consent link';
            case 'admin':      return 'recorded by a member of staff after being told';
            case 'onboarding': return 'agreed while setting up the account';
            default:         return 'source: ' . $source;
        }
    }

    private static function agencyOf(int $userId): ?int
    {
        $id = DB::table('role_assignments')->where('user_id', $userId)
            ->whereNotNull('agency_id')->orderByRaw("role = 'guardian' ASC")
            ->value('agency_id');

        return $id ? (int) $id : null;
    }

    private static function renderPdf(
        object $user,
        string $agency,
        string $consentText,
        string $version,
        string $source,
        ?string $phone,
        Carbon $local
    ): string {
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;color:#0F172A;font-size:11.5px;line-height:1.6;}'
            . 'h1{font-size:17px;margin:0 0 2px;} h2{font-size:12.5px;margin:16px 0 5px;}'
            . '.muted{color:#64748B;font-size:10px;}'
            . '.box{border:1px solid #CBD5E1;border-radius:6px;padding:11px;margin-top:10px;}'
            . '.quote{background:#F8FAFC;border-left:3px solid #1F6080;padding:10px 12px;margin:8px 0;}'
            . 'td{font-size:11.5px;padding:2px 0;vertical-align:top;}'
            . '</style></head><body>'
            . '<h1>Text message consent</h1>'
            . '<div class="muted">' . e($agency) . ' &middot; version ' . e($version) . '</div>'
            . '<h2>What was agreed</h2>'
            . '<div class="quote">' . e($consentText) . '</div>'
            . '<div class="box">'
            . '<h2>Record</h2>'
            . '<table>'
            . '<tr><td width="130"><strong>Agreed by</strong></td><td>' . e($name)
            .   ($user->email ? ' (' . e((string) $user->email) . ')' : '') . '</td></tr>'
            . '<tr><td><strong>Mobile number</strong></td><td>' . e($phone ?: 'not on file') . '</td></tr>'
            . '<tr><td><strong>Date and time</strong></td><td>' . e($local->toDayDateTimeString()) . '</td></tr>'
            . '<tr><td><strong>How</strong></td><td>' . e(self::sourceLabel($source)) . '</td></tr>'
            . '</table>'
            . '</div>'
            . '<h2>Stopping these messages</h2>'
            . '<p>Reply <strong>STOP</strong> to any message to stop them, or turn text alerts off '
            . 'under Settings in the KiddieTrac app. Reply <strong>HELP</strong> for help. '
            . 'Message and data rates may apply.</p>'
            . '<p class="muted">This copy was produced by KiddieTrac as the record of the consent '
            . 'given above. It is also held on the account it belongs to.</p>'
            . '</body></html>';

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
