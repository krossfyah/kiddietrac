<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The provider-welcome email is agency-editable. Agency admins can rewrite the
 * wording (subject + the narrative blocks) however they like, using merge tags
 * like {{child_name}}. The brand frame (logo banner, provider card, contacts,
 * footer) stays templated so the email always renders cleanly; only the words
 * are theirs. Custom blocks are stored in agencies.settings.provider_welcome.
 */
final class ProviderWelcomeTemplate
{
    /** The editable blocks (keys) and their warm defaults, with merge tags. */
    public static function defaults(): array
    {
        return [
            'subject' => "Welcome to {{provider_name}} \u{2014} meet your child's provider",
            'intro' => "Dear {{parent_first_name}}, on behalf of everyone at {{agency_name}}, we are absolutely delighted to welcome {{child_name}} into our care. Choosing a childcare provider is one of the most important decisions a family makes, and we're deeply honoured by your trust. Over the coming term you'll get to know your provider well \u{2014} so here's a warm introduction, along with everything you can look forward to.",
            'care_message' => "Every day with us is built around warmth, safety, and joyful, play-based learning. We follow each child's own pace and personality \u{2014} gently encouraging curiosity, kindness, independence, and confidence through hands-on activities, sensory play, outdoor adventures, stories, music, and lots of encouragement. Predictable, nurturing routines around meals, naps, and quiet time help little ones feel secure, settled, and ready to explore.\n\nWe believe the early years are precious, and that strong partnerships between families and providers make all the difference. We'll celebrate every milestone with you \u{2014} first words, new friendships, proud little achievements \u{2014} and we'll always keep you informed, involved, and reassured. Your child will be met each morning with a familiar, caring face and go home each day having learned, laughed, and grown just a little bit more.",
            'expect_intro' => "Through your KiddieTrac parent app you'll get a live window into {{child_name}}'s day \u{2014} updated as it happens:",
            'closing' => "We can't wait to get to know {{child_name}}. Thank you for trusting us with your most precious little one. \u{1F49B}",
        ];
    }

    /** Custom blocks (from agency settings) merged over the defaults. */
    public static function blocks(array $agencySettings): array
    {
        $custom = $agencySettings['provider_welcome'] ?? [];
        $out = self::defaults();
        foreach ($out as $k => $v) {
            if (isset($custom[$k]) && trim((string) $custom[$k]) !== '') {
                $out[$k] = (string) $custom[$k];
            }
        }
        return $out;
    }

    /** Replace {{merge_tags}} in a block with the recipient/context data. */
    public static function fill(string $text, array $data): string
    {
        $map = [
            'parent_first_name' => $data['parentFirstName'] ?? 'there',
            'child_name'        => $data['childName'] ?: 'your little one',
            'provider_name'     => $data['providerName'] ?? 'your provider',
            'provider_bio'      => $data['providerBio'] ?? '',
            'provider_phone'    => $data['providerPhone'] ?? '',
            'provider_email'    => $data['providerEmail'] ?? '',
            'provider_address'  => $data['providerAddress'] ?? '',
            'agency_name'       => $data['agencyName'] ?? '',
            'agency_phone'      => $data['agencyPhone'] ?? '',
            'agency_email'      => $data['agencyEmail'] ?? '',
            'agency_address'    => $data['agencyAddress'] ?? '',
            'agency_owner'      => $data['agencyOwnerName'] ?? '',
            'agency_website'    => $data['websiteUrl'] ?? '',
            'portal_url'        => $data['portalUrl'] ?? 'https://app.kiddietrac.com',
        ];
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($map) {
            return $map[$m[1]] ?? $m[0];
        }, $text);
    }

    /** Full blade view data: the base $data plus the filled, agency-edited blocks. */
    public static function viewData(array $data, array $agencySettings): array
    {
        $blocks = self::blocks($agencySettings);
        $data['subject']     = self::fill($blocks['subject'], $data);
        $data['intro']       = self::fill($blocks['intro'], $data);
        $data['careMessage'] = self::fill($blocks['care_message'], $data);
        $data['expectIntro'] = self::fill($blocks['expect_intro'], $data);
        $data['closing']     = self::fill($blocks['closing'], $data);
        return $data;
    }

    /** Merge tags exposed in the editor UI. */
    public static function mergeTags(): array
    {
        return [
            'parent_first_name', 'child_name', 'provider_name', 'provider_bio',
            'provider_phone', 'provider_email', 'provider_address',
            'agency_name', 'agency_phone', 'agency_email', 'agency_address',
            'agency_owner', 'agency_website', 'portal_url',
        ];
    }
}
