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

            /* STAFF WELCOME (2026-09-29). Anthony: "when a new educator/provider is onboarded
               ... send out a welcome email ... and cc director/admin", then "add the agency's
               values on how we care for children and explain some of the features of the
               kiddietrac portal and guide them to the help section".

               Sent once, when an educator, centre director or home visitor finishes
               onboarding, to them with their centre's director(s) and the agency admins
               CC'd (App\Services\StaffWelcome). The agency's care values are NOT a block
               here: they are the "Our care" text of the Provider welcome template, the
               same words parents read, so an agency writes its values once. The portal
               tour, the help link and the "who to ask" card are built in. */
            'staff-welcome' => [
                'label'       => 'Staff welcome (educators & providers)',
                'description' => "Sent once when an educator, director or home visitor finishes onboarding, with their centre's director and the agency admins copied. You control the heading, welcome, first steps and sign-off. Your care values come from the Provider welcome template's \"Our care\" text; the portal tour, help link and contacts are automatic.",
                'fields'      => [
                    ['k' => 'heading',     'label' => 'Heading',      'rich' => false, 'minH' => 0],
                    ['k' => 'intro',       'label' => 'Welcome',      'rich' => false, 'minH' => 120],
                    ['k' => 'first_steps', 'label' => 'First steps',  'rich' => false, 'minH' => 140],
                    ['k' => 'signoff',     'label' => 'Sign-off',     'rich' => false, 'minH' => 60],
                ],
                'defaults'    => [
                    'heading'     => "Welcome to {{agency_name}}, {{name}} 🎉",
                    'intro'       => "We're so glad you're joining us as {{role_label}} at {{centre_name}}. Your KiddieTrac account is set up and ready to go.

This email is a quick guide to how we care for children here, the tools you'll use every day, and where to find help.",
                    'first_steps' => "1. Add a clear profile photo, so families recognise you.
2. Install KiddieTrac on your phone: open the portal and add it to your home screen, or use the app.
3. Open Help & guides and read the short guide for your role.
4. Check your rooms and schedule, and say hello to your director.",
                    'signoff'     => "Welcome to the team. We can't wait to see the difference you'll make.

Warmly,
The {{agency_name}} team",
                ],
                'merge_tags'  => ['name', 'agency_name', 'centre_name', 'role_label', 'director_names', 'portal_url'],
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

            /* ── 2026-10-06: emails that used to be composed inline, now editable. Each is
               built by App\Support\TemplatedEmails, which the real sender and Preview/Test
               both call; a block left at its default sends the original wording exactly. */
            'payment-receipt' => [
                'label'       => 'Payment received',
                'group'       => 'billing',
                'description' => 'Sent to the payer the moment an online card or bank payment settles. The amount, method, date, reference and any balance still owing are filled in automatically.',
                'fields'      => [
                    ['k' => 'subject',      'label' => 'Subject line',   'rich' => false, 'minH' => 0],
                    ['k' => 'title',        'label' => 'Heading',        'rich' => false, 'minH' => 0],
                    ['k' => 'greeting',     'label' => 'Greeting',       'rich' => false, 'minH' => 0],
                    ['k' => 'intro',        'label' => 'Opening',        'rich' => false, 'minH' => 70],
                    ['k' => 'outro',        'label' => 'After the details', 'rich' => false, 'minH' => 60],
                    ['k' => 'button_label', 'label' => 'Button label',   'rich' => false, 'minH' => 0],
                    ['k' => 'signoff',      'label' => 'Sign-off',       'rich' => false, 'minH' => 60],
                ],
                'defaults'    => [
                    'subject'      => 'Payment received — {{amount}}',
                    'title'        => 'Thank you — payment received',
                    'greeting'     => 'Hello {{name}},',
                    'intro'        => 'Thank you — your payment has gone through. Here are the details for your records.',
                    'outro'        => 'You can see all your invoices and payments any time in your portal.',
                    'button_label' => 'View your billing',
                    'signoff'      => "Kind regards,\n**{{agency_name}} Team**",
                ],
                'merge_tags'  => ['name', 'amount', 'payment_method', 'invoice_number', 'agency_name'],
            ],

            'payment-failed' => [
                'label'       => 'Payment did not go through',
                'group'       => 'billing',
                'description' => 'Sent to the payer when a card or bank payment is declined or fails. Nothing has been taken; the invoice line is added automatically when there is one.',
                'fields'      => [
                    ['k' => 'subject',      'label' => 'Subject line', 'rich' => false, 'minH' => 0],
                    ['k' => 'title',        'label' => 'Heading',      'rich' => false, 'minH' => 0],
                    ['k' => 'greeting',     'label' => 'Greeting',     'rich' => false, 'minH' => 0],
                    ['k' => 'intro',        'label' => 'Opening',      'rich' => false, 'minH' => 70],
                    ['k' => 'advice',       'label' => 'What to do',   'rich' => false, 'minH' => 70],
                    ['k' => 'button_label', 'label' => 'Button label', 'rich' => false, 'minH' => 0],
                    ['k' => 'contact',      'label' => 'If it is a mistake', 'rich' => false, 'minH' => 60],
                    ['k' => 'signoff',      'label' => 'Sign-off',     'rich' => false, 'minH' => 60],
                ],
                'defaults'    => [
                    'subject'      => 'Your payment did not go through',
                    'title'        => 'That payment did not go through',
                    'greeting'     => 'Hello {{name}},',
                    'intro'        => 'Your payment of **{{amount}}** did not go through, so nothing has been taken from your {{payment_method}}.',
                    'advice'       => 'This usually means the card has expired, has been replaced, or the bank declined it. Adding a different card in your portal is the quickest fix.',
                    'button_label' => 'Update your payment method',
                    'contact'      => 'If you think this is a mistake, please contact {{agency_name}} — we would rather sort it out with you than have it sit unpaid.',
                    'signoff'      => "Kind regards,\n**{{agency_name}} Team**",
                ],
                'merge_tags'  => ['name', 'amount', 'payment_method', 'invoice_number', 'agency_name'],
            ],

            'invoice-cancelled' => [
                'label'       => 'Invoice cancelled',
                'group'       => 'billing',
                'description' => 'Sent to the family when an invoice is voided. The invoice number, amount and due date box is added automatically. (Staff get their own separate notice.)',
                'fields'      => [
                    ['k' => 'subject',       'label' => 'Subject line',     'rich' => false, 'minH' => 0],
                    ['k' => 'intro',         'label' => 'Opening',          'rich' => false, 'minH' => 60],
                    ['k' => 'nothing_to_do', 'label' => 'What it means',    'rich' => false, 'minH' => 90],
                    ['k' => 'already_paid',  'label' => 'If already paid',  'rich' => false, 'minH' => 90],
                ],
                'defaults'    => [
                    'subject'       => 'Invoice {{invoice_number}} has been cancelled - nothing to do',
                    'intro'         => 'We are writing to let you know that the invoice below has been **cancelled** by {{agency_name}}.',
                    'nothing_to_do' => '**There is nothing you need to do.** This amount is no longer owed and has been removed from your balance. If you have already had a reminder about it, please disregard that reminder, and if you were about to pay it, there is no longer any need.',
                    'already_paid'  => 'If you have already paid this invoice, the amount will be returned to you or applied to another invoice, and we will be in touch. If a replacement invoice is needed you will receive it separately. Any questions, just reply to this email.',
                ],
                'merge_tags'  => ['invoice_number', 'amount', 'agency_name'],
            ],

            'closure-notice' => [
                'label'       => 'Centre closure announcement',
                'group'       => 'parents',
                'description' => "Sent to every family AND staff member at the centre when a closure is added. The dates and reason box is added automatically. The wording depends on whether the closure is upcoming, happening now, or already over.",
                'fields'      => [
                    ['k' => 'subject',          'label' => 'Subject line',                  'rich' => false, 'minH' => 0],
                    ['k' => 'opening_upcoming', 'label' => 'Opening — upcoming closure',    'rich' => false, 'minH' => 60],
                    ['k' => 'closing_upcoming', 'label' => 'Closing — upcoming closure',    'rich' => false, 'minH' => 60],
                    ['k' => 'opening_current',  'label' => 'Opening — closed right now',    'rich' => false, 'minH' => 60],
                    ['k' => 'closing_current',  'label' => 'Closing — closed right now',    'rich' => false, 'minH' => 60],
                    ['k' => 'opening_past',     'label' => 'Opening — closure already over','rich' => false, 'minH' => 60],
                    ['k' => 'closing_past',     'label' => 'Closing — closure already over','rich' => false, 'minH' => 60],
                ],
                'defaults'    => [
                    'subject'          => '{{centre_name}} {{closed_verb}} {{dates}}',
                    'opening_upcoming' => 'We are letting you know that **{{centre_name}}** will be closed.',
                    'closing_upcoming' => 'There is nothing you need to do. Sign-in is switched off for those days, and we will see you when we reopen.',
                    'opening_current'  => '**{{centre_name}}** is closed at the moment. Apologies for the late notice — this has only just been added to the calendar.',
                    'closing_current'  => 'There is nothing you need to do. Sign-in is switched off for these days, and we will see you when we reopen.',
                    'opening_past'     => 'For your records: **{{centre_name}}** was closed on the dates below. This has just been added to the calendar, so it may be the first you have heard of it.',
                    'closing_past'     => 'Nothing is needed from you — this is a record of a closure that has already passed.',
                ],
                'merge_tags'  => ['centre_name', 'dates', 'reason', 'closed_verb'],
            ],
        ];
    }

    /** Which group each template is listed under in the editor. */
    public const GROUPS = ['parents' => 'Parents', 'billing' => 'Billing', 'staff' => 'Staff', 'accounts' => 'Accounts & invitations'];
    private const GROUP_OF = [
        'provider-welcome' => 'parents', 'parent-daily-summary' => 'parents', 'onboarding-welcome' => 'parents',
        'announcement' => 'parents', 'staff-welcome' => 'staff', 'invite' => 'accounts',
    ];

    /** Normalised for "is this still the default?" — whitespace and markup don't count. */
    private static function norm(string $v): string
    {
        $v = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $v)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $v));
    }

    /**
     * The block an agency (or the editor's unsaved draft) CHANGED, as HTML — or null when
     * it is still the default, so the caller keeps its original wording byte for byte.
     * Plain text: merge tags filled, escaped, **bold** kept, line breaks kept.
     */
    public static function piece(int $agencyId, string $key, string $block, array $data = [], ?array $draft = null): ?string
    {
        $raw = self::changed($agencyId, $key, $block, $draft);
        if ($raw === null) return null;
        $t = e(self::fill($raw, $data));
        $t = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $t);
        return nl2br($t, false);
    }

    /** Same, for a one-line plain value used as a subject or heading (no markup). */
    public static function subjectPiece(int $agencyId, string $key, string $block, array $data = [], ?array $draft = null): ?string
    {
        $raw = self::changed($agencyId, $key, $block, $draft);
        if ($raw === null) return null;
        return trim(preg_replace('/\s+/u', ' ', str_replace('**', '', self::fill($raw, $data))));
    }

    /** The changed raw value of one block, or null if it is absent or equal to the default. */
    private static function changed(int $agencyId, string $key, string $block, ?array $draft): ?string
    {
        $def = self::registry()[$key]['defaults'][$block] ?? null;
        if ($def === null) return null;
        if ($draft !== null && array_key_exists($block, $draft)) {
            $v = (string) $draft[$block];
        } else {
            $v = (string) (self::settings($agencyId)['email_templates'][$key][$block] ?? '');
        }
        if (trim(strip_tags($v)) === '' || self::norm($v) === self::norm((string) $def)) return null;
        return $v;
    }

    /** True when the agency has changed any block of this template from its default. */
    public static function isCustomised(int $agencyId, string $key): bool
    {
        if ($key === 'provider-welcome') {
            $pw = self::settings($agencyId)['provider_welcome'] ?? null;
            return is_array($pw) ? (bool) array_filter($pw, fn ($v) => trim(strip_tags((string) $v)) !== '') : ! empty($pw);
        }
        foreach (array_keys(self::registry()[$key]['defaults'] ?? []) as $b) {
            if (self::changed($agencyId, $key, $b, null) !== null) return true;
        }
        return false;
    }

    /** {{tags}} a block set uses, and the ones this template does not know. */
    public static function tagsIn(string $key, array $blocks): array
    {
        $known = self::registry()[$key]['merge_tags'] ?? [];
        $used = [];
        foreach ($blocks as $v) {
            if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', (string) $v, $m)) $used = array_merge($used, $m[1]);
        }
        $used = array_values(array_unique($used));
        return ['used' => $used, 'unknown' => array_values(array_diff($used, $known))];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::registry());
    }

    /** Lightweight list for the picker dropdown (includes provider-welcome first). */
    public static function list(?int $agencyId = null): array
    {
        $out = [[
            'key'         => 'provider-welcome',
            'label'       => 'Provider welcome',
            'description' => "Sent to parents when a provider is assigned to their child.",
            'group'       => 'parents',
        ]];
        foreach (self::registry() as $key => $def) {
            $out[] = ['key' => $key, 'label' => $def['label'], 'description' => $def['description'],
                'group' => $def['group'] ?? (self::GROUP_OF[$key] ?? 'parents')];
        }
        foreach ($out as &$t) {
            $t['group_label'] = self::GROUPS[$t['group']] ?? ucfirst($t['group']);
            $t['customised'] = $agencyId ? self::isCustomised($agencyId, $t['key']) : false;
        }
        unset($t);
        return $out;
    }

    /** Subject an email goes out with, for the preview header (the real one where known). */
    public static function subjectFor(int $agencyId, string $key, array $blocks, array $data): string
    {
        $built = self::built($agencyId, $key, $blocks, $data);
        if ($built) return $built['subject'];
        return [
            'parent-daily-summary' => ($data['child_name'] ?? 'Ava') . "'s day at " . ($data['centre_name'] ?? 'the centre'),
            'onboarding-welcome'   => 'Welcome to ' . ($data['agency_name'] ?? 'KiddieTrac'),
            'staff-welcome'        => 'Welcome to ' . ($data['agency_name'] ?? 'the team'),
            'invite'               => "You're invited to " . ($data['agency_name'] ?? 'KiddieTrac'),
            'announcement'         => self::fill((string) ($blocks['title'] ?? ''), $data) ?: 'A note from ' . ($data['agency_name'] ?? ''),
        ][$key] ?? (self::registry()[$key]['label'] ?? 'Email');
    }

    /** The real builder's output for the templates TemplatedEmails composes, else null. */
    private static function built(int $agencyId, string $key, array $blocks, array $data): ?array
    {
        $money = '$' . number_format(142.50, 2);
        $base = ['name' => $data['parent_first_name'] ?? 'Alex', 'agency_name' => $data['agency_name'] ?? 'Your agency'];
        switch ($key) {
            case 'payment-receipt':
                return TemplatedEmails::paymentReceipt($agencyId, $base + [
                    'amount' => $money, 'how' => 'Visa ending 4242', 'payment_method' => 'Visa ending 4242',
                    'date' => now()->format('j M Y, g:i A'), 'reference' => 'ZUM-1a2b3c4d',
                    'invoice_number' => 'INV-1042', 'balance_now' => 0.0, 'balance_text' => '$0.00',
                ], $blocks);
            case 'payment-failed':
                return TemplatedEmails::paymentFailed($agencyId, $base + [
                    'amount' => $money, 'how' => 'Visa ending 4242', 'payment_method' => 'Visa ending 4242',
                    'invoice_number' => 'INV-1042',
                ], $blocks);
            case 'invoice-cancelled':
                $due = now()->addDays(7)->format('j F Y');
                return TemplatedEmails::invoiceCancelled($agencyId, $base + [
                    'number' => 'INV-1042', 'invoice_number' => 'INV-1042', 'money' => $money, 'amount' => $money, 'due' => $due,
                ], TemplatedEmails::invoiceFacts('INV-1042', $money, $due), $blocks);
            case 'closure-notice':
                return TemplatedEmails::closureNotice($agencyId, (string) ($data['centre_name'] ?? 'Sunny Days'),
                    now()->addDays(10)->format('D j M') . ' – ' . now()->addDays(11)->format('D j M Y'),
                    'Professional development days', 'upcoming', $blocks);
        }
        return null;
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
            'role_label'        => $data['role_label'] ?? 'a member of our team',
            'director_names'    => $data['director_names'] ?? 'your director',
        ];
        // Anything else the caller supplies (amount, dates, invoice_number…) fills by name.
        foreach ($data as $k => $v) {
            if (is_string($k) && ! array_key_exists($k, $map) && (is_scalar($v) || $v === null)) {
                $map[$k] = (string) $v;
            }
        }
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

            case 'staff-welcome':
                return \App\Services\StaffWelcome::renderHtml($agencyId, $blocks, $data);

            case 'payment-receipt':
            case 'payment-failed':
            case 'invoice-cancelled':
            case 'closure-notice':
                return self::built($agencyId, $key, $blocks, $data)['html'];

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
            'role_label'        => 'an educator',
            'role'              => 'educator',
            'director_names'    => 'Jordan Lee',
            'directors'         => [['name' => 'Jordan Lee', 'email' => 'director@example.com', 'phone' => '']],
        ];
    }

    private static function settings(int $agencyId): array
    {
        $ag = DB::table('agencies')->where('id', $agencyId)->first();
        return ($ag && $ag->settings) ? (json_decode($ag->settings, true) ?: []) : [];
    }
}
