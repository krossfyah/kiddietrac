<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\EmailTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Registry of agency-editable email templates (2026-08-07).
 *
 * One place defines each template's editable blocks, warm defaults, merge tags,
 * and how it renders. BOTH the admin editor (get/save/preview/test) and the real
 * send paths read from here, so what an admin edits is exactly what goes out.
 *
 * Custom blocks are stored per template under agencies.settings.email_templates.<key>.
 * The brand frame (logo header + footer) always comes from EmailTemplate::wrap, so
 * an admin only controls the words — the email can never render broken.
 *
 * NB: provider-welcome is NOT here — it keeps its own class/blade/storage
 * (ProviderWelcomeTemplate + agencies.settings.provider_welcome) and its own
 * endpoints; this registry covers the templates added for #77.
 */
final class EmailTemplates
{
    /** All templates in this registry, keyed by slug. */
    public static function registry(): array
    {
        return [
            'parent-daily-summary' => [
                'label'       => 'Parent daily summary',
                'description' => "The end-of-day email to parents. You control the greeting, intro and sign-off; photos, care logs, awards and the quote are added automatically.",
                'fields'      => [
                    ['k' => 'greeting', 'label' => 'Greeting line',       'rich' => true,  'minH' => 60],
                    ['k' => 'intro',    'label' => 'Intro sentence',       'rich' => true,  'minH' => 90],
                    ['k' => 'signoff',  'label' => 'Sign-off line',        'rich' => true,  'minH' => 60],
                ],
                'defaults'    => [
                    'greeting' => "Here's how {{child_name}}'s day went 💛",
                    'intro'    => "A little window into {{child_name}}'s day at {{centre_name}} — the moments, meals, naps and highlights, gathered up for you below.",
                    'signoff'  => "Thank you for sharing {{child_name}} with us today. See you tomorrow!",
                ],
                'merge_tags'  => ['child_name', 'centre_name', 'agency_name', 'parent_first_name', 'date'],
            ],

            'onboarding-welcome' => [
                'label'       => 'Onboarding welcome',
                'description' => "Sent once when a new user finishes onboarding. You control the heading, message and button label; the secure portal button and footer are automatic.",
                'fields'      => [
                    ['k' => 'heading',   'label' => 'Heading',            'rich' => false, 'minH' => 0],
                    // Plain text: this email sends via the AccountNotice mailer which
                    // renders the body as plain text (nl2br), so keep the editor plain
                    // too — what you type is exactly what sends.
                    ['k' => 'body',      'label' => 'Message',            'rich' => false, 'minH' => 160],
                    ['k' => 'cta_label', 'label' => 'Button label',       'rich' => false, 'minH' => 0],
                ],
                'defaults'    => [
                    'heading'   => "You're all set, {{name}} 🎉",
                    'body'      => "Your {{agency_name}} account is ready to use.\n\nSign in anytime to access your portal, stay up to date, and manage your details. We've saved your profile and preferences, so you can jump right in.\n\nNeed a hand? Reply to your administrator and we'll be glad to help.",
                    'cta_label' => "Go to your portal",
                ],
                'merge_tags'  => ['name', 'agency_name', 'portal_url'],
            ],

            'invite' => [
                'label'       => 'Invite / set-password',
                'description' => "The invite email with the secure 'set your password' link, sent to new staff and parents. You control the wording; the secure button and expiry note are automatic.",
                'fields'      => [
                    ['k' => 'intro',        'label' => 'Intro line',      'rich' => true,  'minH' => 90],
                    ['k' => 'instructions', 'label' => 'Instructions',    'rich' => true,  'minH' => 110],
                    ['k' => 'signoff',      'label' => 'Sign-off',        'rich' => true,  'minH' => 60],
                ],
                'defaults'    => [
                    'intro'        => "Hi {{name}}, you've been invited to join {{agency_name}} on KiddieTrac.",
                    'instructions' => "Click the button below to set your password and activate your account. Once you're in, you'll be guided through a quick setup.",
                    'signoff'      => "We're glad to have you — welcome aboard!",
                ],
                'merge_tags'  => ['name', 'agency_name', 'portal_url'],
            ],

            'announcement' => [
                'label'       => 'Announcement / notice',
                'description' => "A reusable branded notice you can preview and send as a test. Use it as the look for one-off emails to families or staff.",
                'fields'      => [
                    ['k' => 'title',        'label' => 'Title',           'rich' => false, 'minH' => 0],
                    ['k' => 'body',         'label' => 'Body',            'rich' => true,  'minH' => 200],
                    ['k' => 'button_label', 'label' => 'Button label (optional)', 'rich' => false, 'minH' => 0],
                    ['k' => 'button_url',   'label' => 'Button URL (optional)',   'rich' => false, 'minH' => 0],
                ],
                'defaults'    => [
                    'title'        => "A note from {{agency_name}}",
                    'body'         => "Dear families,\n\nWe wanted to share a quick update with you. [Write your message here.]\n\nThank you,\nThe {{agency_name}} team",
                    'button_label' => "",
                    'button_url'   => "",
                ],
                'merge_tags'  => ['agency_name', 'portal_url'],
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::registry());
    }

    /** Lightweight list for the picker dropdown (includes provider-welcome first). */
    public static function list(): array
    {
        $out = [[
            'key'         => 'provider-welcome',
            'label'       => 'Provider welcome',
            'description' => "Sent to parents when a provider is assigned to their child.",
        ]];
        foreach (self::registry() as $key => $def) {
            $out[] = ['key' => $key, 'label' => $def['label'], 'description' => $def['description']];
        }
        return $out;
    }

    /** Stored (agency-edited) blocks merged over the defaults, for editing/rendering. */
    public static function blocks(int $agencyId, string $key): array
    {
        $def = self::registry()[$key] ?? null;
        if (! $def) return [];
        $out = $def['defaults'];
        $s = self::settings($agencyId);
        $custom = $s['email_templates'][$key] ?? [];
        foreach ($out as $k => $v) {
            if (isset($custom[$k]) && trim((string) $custom[$k]) !== '') {
                $out[$k] = (string) $custom[$k];
            }
        }
        return $out;
    }

    /** One resolved block (agency-edited or default) with merge tags filled — for send paths. */
    public static function block(int $agencyId, string $key, string $blockKey, array $data = []): string
    {
        $blocks = self::blocks($agencyId, $key);
        return self::fill($blocks[$blockKey] ?? '', $data);
    }

    /** Replace {{merge_tags}} with context data. Unknown tags are left untouched. */
    public static function fill(string $text, array $data): string
    {
        $map = [
            'name'              => $data['name'] ?? 'there',
            'parent_first_name' => $data['parent_first_name'] ?? 'there',
            'child_name'        => $data['child_name'] ?? 'your little one',
            'centre_name'       => $data['centre_name'] ?? 'your centre',
            'agency_name'       => $data['agency_name'] ?? 'your agency',
            'portal_url'        => $data['portal_url'] ?? 'https://app.kiddietrac.com',
            'date'              => $data['date'] ?? '',
        ];
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($map) {
            return $map[$m[1]] ?? $m[0];
        }, $text) ?? $text;
    }

    /** Render a template to a full branded HTML email, using the given blocks + context. */
    public static function render(int $agencyId, string $key, array $blocks, array $data): string
    {
        $agencyName = $data['agency_name'] ?? (self::settings($agencyId)['name'] ?? 'Your agency');
        $portal = $data['portal_url'] ?? 'https://app.kiddietrac.com';
        $F = fn (string $k) => self::fill((string) ($blocks[$k] ?? ''), $data);
        // Rich blocks already contain HTML from the editor; plain-text blocks get nl2br.
        $rich = fn (string $s) => trim($s) !== '' ? $s : '';
        $para = fn (string $s) => nl2br(e($s));

        switch ($key) {
            case 'parent-daily-summary':
                $body = '<div style="font-size:19px;font-weight:800;color:#0B2545;margin:0 0 10px;">' . $F('greeting') . '</div>'
                    . '<div style="font-size:15px;line-height:1.6;color:#334155;margin-bottom:16px;">' . $F('intro') . '</div>'
                    . EmailTemplate::statRow(
                        EmailTemplate::statTile('Signed in', '8:32 AM', 'by an educator', '#16A34A'),
                        EmailTemplate::statTile('Signed out', '4:10 PM', 'by a parent', '#1F6080')
                    )
                    . '<div style="font-size:13px;color:#64748B;margin:14px 0 4px;font-weight:800;">📝 A few of today\'s moments</div>'
                    . '<div style="font-size:14px;color:#334155;line-height:1.7;">🍎 Snack · ate well &nbsp;·&nbsp; 😴 Nap · 1h 20m &nbsp;·&nbsp; 🎨 Art &amp; crafts</div>'
                    . '<div style="margin-top:18px;font-size:15px;color:#334155;line-height:1.6;">' . $F('signoff') . '</div>'
                    . EmailTemplate::dailyQuote(20260807);
                return EmailTemplate::wrap($agencyId, $body, [
                    'eyebrow' => 'DAILY SUMMARY', 'title' => 'Your child\'s day',
                    'subtitle' => 'A sample of the end-of-day email',
                    'preheader' => 'A window into your child\'s day.',
                ]);

            case 'onboarding-welcome':
                $body = '<div style="font-size:21px;font-weight:800;color:#0B2545;margin:0 0 12px;">' . e($F('heading')) . '</div>'
                    . '<div style="font-size:15px;line-height:1.65;color:#334155;">' . $para($F('body')) . '</div>'
                    . '<div style="margin:22px 0 6px;">' . EmailTemplate::button($F('cta_label') ?: 'Go to your portal', $portal) . '</div>';
                return EmailTemplate::wrap($agencyId, $body, [
                    'eyebrow' => 'WELCOME', 'title' => 'Your account is ready',
                    'preheader' => 'Your KiddieTrac account is ready to use.',
                ]);

            case 'invite':
                $body = '<div style="font-size:15px;line-height:1.65;color:#334155;margin-bottom:14px;">' . $F('intro') . '</div>'
                    . '<div style="font-size:14.5px;line-height:1.6;color:#334155;margin-bottom:6px;">' . $F('instructions') . '</div>'
                    . '<div style="margin:20px 0 8px;">' . EmailTemplate::button('Set your password', $portal . '/set-password') . '</div>'
                    . EmailTemplate::calloutBox('For your security this link expires in 7 days. If it lapses, ask your administrator to resend the invite.', 'info')
                    . '<div style="margin-top:16px;font-size:15px;color:#334155;line-height:1.6;">' . $F('signoff') . '</div>';
                return EmailTemplate::wrap($agencyId, $body, [
                    'eyebrow' => 'INVITATION', 'title' => 'You\'re invited to ' . e($agencyName),
                    'preheader' => 'Set your password to activate your account.',
                ]);

            case 'announcement':
                $btnLabel = trim((string) $F('button_label'));
                $btnUrl = trim((string) ($blocks['button_url'] ?? ''));
                $body = '<div style="font-size:15px;line-height:1.7;color:#334155;">' . $para($F('body')) . '</div>'
                    . ($btnLabel && $btnUrl ? '<div style="margin:20px 0 6px;">' . EmailTemplate::button($btnLabel, $btnUrl) . '</div>' : '');
                return EmailTemplate::wrap($agencyId, $body, [
                    'eyebrow' => 'NOTICE', 'title' => $F('title') ?: 'A note from ' . e($agencyName),
                    'preheader' => 'A message from ' . e($agencyName) . '.',
                ]);
        }
        return EmailTemplate::wrap($agencyId, '<p>Unknown template.</p>', ['title' => 'Template']);
    }

    /** Sample merge data for preview/test sends. */
    public static function sample(int $agencyId, ?string $recipientName = null): array
    {
        $s = self::settings($agencyId);
        $agencyName = $s['name'] ?? (DB::table('agencies')->where('id', $agencyId)->value('name') ?? 'Your agency');
        return [
            'name'              => $recipientName ?: 'Alex',
            'parent_first_name' => $recipientName ?: 'Alex',
            'child_name'        => 'Ava',
            'centre_name'       => DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->value('name') ?: 'Sunny Days',
            'agency_name'       => $agencyName,
            'portal_url'        => 'https://app.kiddietrac.com',
            'date'              => now()->format('l, j F Y'),
        ];
    }

    private static function settings(int $agencyId): array
    {
        $ag = DB::table('agencies')->where('id', $agencyId)->first();
        return ($ag && $ag->settings) ? (json_decode($ag->settings, true) ?: []) : [];
    }
}
