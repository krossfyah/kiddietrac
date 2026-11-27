<?php

namespace App\Support;

use App\Services\EmailTemplate;

/**
 * THE ANNOUNCEMENT EMAIL IN THE HOUSE FRAME (2026-11-27, Anthony: "supplies needed email that gets sent out to the
 * parent isn't in the typical format - pls full sweep").
 *
 * AnnouncementController::deliver() used to send a bare <div> (eyebrow, title, the body, "— Full Name / centre"):
 * no header, no agency branding, no footer, no dark palette. Every family email is built in the house frame (the EmailTemplate wrapper), so
 * the announcement now is too. The agency's own words (the rich-text body) go in unchanged; around them: the
 * branded banner with the title, the due-date callout, the "tap Done" hint, the PDF button and a sign-off with the
 * author's FIRST name and who it is from (FamilyFacingName for families: a provider-named home is the agency).
 *
 * build() is pure (no mail, no writes).
 */
class AnnouncementMail
{
    /**
     * $d: title, body (sanitised HTML), plain (text version, for the preheader), due (formatted, agency time) | null,
     *     tracks (bool: 'pending' reminders), pdf ['name','url'] | null, sender_first, from (who it is from),
     *     reminder (bool), support_email (Reply-To inbox, optional).
     */
    public static function build(?int $agencyId, array $d): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $from = trim((string) ($d['from'] ?? ''));
        $first = trim((string) ($d['sender_first'] ?? ''));
        $colour = EmailTemplate::brandColours($agencyId)['primary'] ?? '#1F6080';

        $body = (! empty($d['due'])
                ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px;"><tr>'
                    . '<td class="kt-callout" style="background:#FEF3C7;border-radius:8px;padding:11px 14px;font-size:14.5px;line-height:1.45;color:#78350F;">'
                    . '⏰ Please do this by <strong>' . $e($d['due']) . '</strong></td></tr></table>'
                : '')
            . '<div style="font-size:15px;line-height:1.65;color:#1E293B;">' . (string) ($d['body'] ?? '') . '</div>'
            . (! empty($d['tracks'])
                ? '<p class="kt-muted" style="margin:16px 0 0;font-size:13.5px;line-height:1.55;color:#475569;">Once it is done, open <strong>Announcements</strong> in the app and tap <strong>✓ Done</strong>, so we stop reminding you.</p>'
                : '')
            . (! empty($d['pdf'])
                ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 4px;"><tr>'
                    . '<td bgcolor="' . $e($colour) . '" style="background:' . $e($colour) . ';border-radius:9px;">'
                    . '<a class="kt-btn" href="' . $e($d['pdf']['url']) . '" style="display:inline-block;padding:11px 18px;color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14.5px;border-radius:9px;">📄 Open the PDF: ' . $e($d['pdf']['name']) . '</a>'
                    . '</td></tr></table>'
                    . '<p class="kt-muted" style="margin:0;font-size:12px;color:#94A3B8;">The link works for 60 days.</p>'
                : '')
            . '<p style="margin:22px 0 0;font-size:15px;line-height:1.55;color:#475569;">'
            . ($first !== '' ? '— <strong>' . $e($first) . '</strong>' . ($from !== '' ? '<br><span style="font-size:13px;">' . $e($from) . '</span>' : '')
                : '— ' . $e($from !== '' ? $from : 'The team'))
            . '</p>';

        $plain = trim(preg_replace('/\s+/', ' ', (string) ($d['plain'] ?? '')));

        return EmailTemplate::wrap($agencyId, $body, array_filter([
            'eyebrow' => (! empty($d['reminder']) ? '⏰ REMINDER' : '📢 ANNOUNCEMENT') . ($from !== '' ? ' · ' . mb_strtoupper($from) : ''),
            'title' => (string) ($d['title'] ?? 'Announcement'),
            'subtitle' => ! empty($d['due']) ? 'Please do this by ' . $d['due'] : null,
            'preheader' => mb_substr($plain, 0, 140),
            'support_email' => $d['support_email'] ?? null,
        ]));
    }

    /** Who a family-facing announcement is from: the agency for a provider-named home, else the centre/room/agency. */
    public static function fromLabel(string $scopeType, int $scopeId): string
    {
        try {
            if ($scopeType === 'agency') return FamilyFacingName::agency($scopeId);
            if ($scopeType === 'centre') return FamilyFacingName::org($scopeId);
            if ($scopeType === 'family') return FamilyFacingName::org((int) \Illuminate\Support\Facades\DB::table('families')->where('id', $scopeId)->value('centre_id') ?: null);
            if ($scopeType === 'room') {
                $room = \Illuminate\Support\Facades\DB::table('rooms')->where('id', $scopeId)->first(['name', 'centre_id']);
                if (! $room) return '';
                $org = FamilyFacingName::org($room->centre_id ? (int) $room->centre_id : null);

                return trim(($room->name ?: '') . ($org !== '' ? ' · ' . $org : ''), ' ·');
            }
        } catch (\Throwable $e) {
        }

        return '';
    }
}
