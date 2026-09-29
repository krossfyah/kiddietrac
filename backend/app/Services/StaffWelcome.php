<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\EmailTemplates;
use App\Support\ProviderWelcomeTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Welcome email for new educators, centre directors and home visitors (2026-09-29).
 *
 * Anthony: "when a new educator/provider is onboarded - do we send out a welcome email
 * to the new provider/educator and cc director/admin?" (we did not: they got the same
 * two-line note as a parent, and nobody else was told), then "add the agency's values on
 * how we care for children and explain some of the features of the kiddietrac portal and
 * guide them to the help section".
 *
 * Sent ONCE, when onboarding completes (AuthController). To the new person, CC'd to the
 * director(s) of their centre and the agency's admins, so management knows they are in
 * and can say hello. The wording is the agency-editable "staff-welcome" template
 * (App\Support\EmailTemplates); the care values are the Provider welcome "Our care"
 * text, so an agency describes how it cares for children once and both parents and
 * staff read the same words.
 */
final class StaffWelcome
{
    public const ROLES = ['educator', 'centre_director', 'home_visitor'];

    private const ROLE_LABEL = [
        'educator' => 'an educator',
        'centre_director' => 'a centre director',
        'home_visitor' => 'a home visitor',
    ];

    /** What each role will use, in the order they will meet it. [icon, title, what it does] */
    private static function tour(string $role): array
    {
        $common = [
            ['💬', 'Messages', 'Talk with families and your team in one place, from the portal or your phone.'],
            ['📖', 'Help & guides', 'Step-by-step guides for everything below, written for your role.'],
        ];
        if ($role === 'home_visitor') {
            return array_merge([
                ['🏡', 'Home visits', 'Plan visits and file your home visit reports from your phone.'],
                ['📋', 'Inspection forms', 'Complete inspection and compliance forms, with photos, on the spot.'],
                ['📅', 'Your schedule', 'See which providers you are visiting and when.'],
            ], $common);
        }
        $core = [
            ['✅', 'Sign in & sign out', 'Record arrivals and departures by tap or QR code. Parents are told straight away.'],
            ['📝', 'Daily care logs', 'Meals, naps, activities, milestones and photos. Families get a summary every evening.'],
            ['🎨', 'Lesson plans & observations', 'Plan the week and capture learning moments as they happen.'],
            ['🩹', 'Incidents & medications', 'Record accidents, health notes and medication given, with a signature.'],
            ['⏱️', 'Clock in & timesheets', 'Clock in and out and see your hours and schedule.'],
        ];
        if ($role === 'centre_director') {
            $core[] = ['📊', 'Your centre at a glance', 'Attendance, ratios, staffing and families for your centre, live.'];
        }

        return array_merge($core, $common);
    }

    /** The full branded HTML for this template. Used by the send below AND the editor's preview/test. */
    public static function renderHtml(int $agencyId, array $blocks, array $data): string
    {
        $F = fn (string $k) => EmailTemplates::fill((string) ($blocks[$k] ?? ''), $data);
        $para = fn (string $t) => nl2br(e(trim($t)));
        $portal = $data['portal_url'] ?? 'https://app.kiddietrac.com';
        $help = rtrim($portal, '/') . '/dashboard.html#help';
        $agencyName = (string) ($data['agency_name'] ?? 'your agency');
        $h2 = fn (string $t) => '<div style="font-size:16px;font-weight:800;color:#0B2545;margin:26px 0 10px;">' . $t . '</div>';

        // The agency's own words on how it cares for children (Provider welcome → "Our care").
        // Light boxes carry class kt-panel: EmailTemplate::wrap turns their text light in
        // dark mode, and without the class the box stayed light too, pale on pale.
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $settings = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $care = trim((string) (ProviderWelcomeTemplate::blocks($settings)['care_message'] ?? ''));
        $care = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $care)));

        $tour = '';
        foreach (self::tour((string) ($data['role'] ?? 'educator')) as [$ic, $title, $what]) {
            $tour .= '<tr><td style="width:34px;vertical-align:top;padding:7px 0;font-size:19px;">' . $ic . '</td>'
                . '<td style="vertical-align:top;padding:7px 0;"><div style="font-size:14.5px;font-weight:800;color:#0F172A;">' . e($title) . '</div>'
                . '<div style="font-size:13.5px;color:#475569;line-height:1.5;">' . e($what) . '</div></td></tr>';
        }

        $who = '';
        foreach ((array) ($data['directors'] ?? []) as $d) {
            $who .= '<div style="margin:0 0 8px;"><div style="font-size:14px;font-weight:800;color:#0F172A;">' . e($d['name'] ?? '') . ' <span style="font-weight:600;color:#64748B;">· your director</span></div>'
                . (! empty($d['email']) ? '<div style="font-size:13px;color:#475569;">✉️ ' . e($d['email']) . '</div>' : '')
                . (! empty($d['phone']) ? '<div style="font-size:13px;color:#475569;">📞 ' . e($d['phone']) . '</div>' : '') . '</div>';
        }
        if (! empty($data['agency_email']) || ! empty($data['agency_phone'])) {
            $who .= '<div><div style="font-size:14px;font-weight:800;color:#0F172A;">' . e($agencyName) . ' <span style="font-weight:600;color:#64748B;">· the office</span></div>'
                . (! empty($data['agency_email']) ? '<div style="font-size:13px;color:#475569;">✉️ ' . e($data['agency_email']) . '</div>' : '')
                . (! empty($data['agency_phone']) ? '<div style="font-size:13px;color:#475569;">📞 ' . e($data['agency_phone']) . '</div>' : '') . '</div>';
        }

        $body = (! empty($data['test_note']) ? EmailTemplate::calloutBox((string) $data['test_note'], 'info') . '<div style="height:14px;"></div>' : '')
            . '<div style="font-size:21px;font-weight:800;color:#0B2545;margin:0 0 12px;">' . e($F('heading')) . '</div>'
            . '<div style="font-size:15px;line-height:1.65;color:#334155;">' . $para($F('intro')) . '</div>'
            . ($care !== ''
                ? $h2('🌱 How we care for children at ' . e($agencyName))
                  . '<div class="kt-panel" style="font-size:15px;line-height:1.7;color:#334155;background:#F0FDF4;border-left:4px solid #16A34A;border-radius:8px;padding:14px 16px;">' . $para($care) . '</div>'
                : '')
            . $h2('🧭 Your tour of KiddieTrac')
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">' . $tour . '</table>'
            . $h2('📖 Help is always one tap away')
            . '<div style="font-size:14.5px;line-height:1.65;color:#334155;">Every feature has a short, step-by-step guide in <b>Help &amp; guides</b>, in the menu of the portal and the app. Start with the guide for your role; it takes about ten minutes.</div>'
            . '<div style="margin:14px 0 4px;">' . EmailTemplate::button('Open Help & guides', $help) . '</div>'
            . $h2('✅ Your first steps')
            . '<div style="font-size:14.5px;line-height:1.75;color:#334155;">' . $para($F('first_steps')) . '</div>'
            . ($who !== '' ? $h2('🙋 Who to ask') . '<div class="kt-panel" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:14px 16px;">' . $who . '</div>' : '')
            . '<div style="margin:24px 0 8px;">' . EmailTemplate::button('Open KiddieTrac', $portal) . '</div>'
            . '<div style="font-size:15px;line-height:1.65;color:#334155;margin-top:18px;">' . $para($F('signoff')) . '</div>';

        return EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'WELCOME TO THE TEAM',
            'title' => 'Welcome to ' . e($agencyName),
            'preheader' => 'How we care for children, your tour of KiddieTrac, and where to find help.',
        ]);
    }

    /**
     * Send the welcome for this user. Returns false (sends nothing) when they are not one
     * of ROLES, so the caller can fall back to the general welcome.
     *
     * $testTo: send ONLY to that address, nobody CC'd, with a note at the top saying who
     * the real email would go to.
     */
    public static function send(int $userId, ?string $testTo = null): bool
    {
        $u = DB::table('users')->where('id', $userId)->first(['id', 'first_name', 'last_name', 'email']);
        if (! $u) {
            return false;
        }
        $ra = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->whereIn('role', self::ROLES)
            ->orderByRaw("FIELD(role,'centre_director','educator','home_visitor')")
            ->first(['role', 'agency_id', 'centre_id']);
        if (! $ra) {
            return false;
        }
        $centreId = $ra->centre_id ? (int) $ra->centre_id : null;
        $agencyId = (int) ($ra->agency_id ?: ($centreId ? DB::table('centres')->where('id', $centreId)->value('agency_id') : 0));
        if (! $agencyId) {
            return false;
        }
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['name', 'contact_email', 'contact_phone', 'brand_support_email']);
        $centreName = $centreId ? (string) DB::table('centres')->where('id', $centreId)->value('name') : '';

        $live = function ($q) {
            return $q->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.active', true)
                ->whereNull('u.deleted_at')->where('u.id', '!=', 0);
        };
        $directors = $centreId ? $live(DB::table('role_assignments as ra'))->where('ra.role', 'centre_director')
            ->where('ra.centre_id', $centreId)->where('u.id', '!=', $userId)
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone'])->unique('id')->values() : collect();
        $admins = $live(DB::table('role_assignments as ra'))->where('ra.role', 'agency_admin')
            ->where('ra.agency_id', $agencyId)->where('u.id', '!=', $userId)
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email'])->unique('id')->values();

        $cc = [];
        foreach ($directors->concat($admins) as $p) {
            $em = strtolower(trim((string) $p->email));
            if ($em === '' || $em === strtolower((string) $u->email) || isset($cc[$em])) {
                continue;
            }
            if (\App\Support\Suppression::accountOff((int) $p->id)) {
                continue;   // deactivated or suspended: not told about new colleagues
            }
            $cc[$em] = trim($p->first_name . ' ' . $p->last_name);
        }

        $name = trim($u->first_name . ' ' . $u->last_name);
        /* In a home-childcare agency the "centre" is the provider's own home and carries
           their name, which read as "joining us as an educator at Amna Ahsan". Said as
           what it is: they are the provider, joining the agency. */
        $ownHome = $centreName !== '' && strcasecmp(trim($centreName), $name) === 0;
        $dNames = $directors->map(fn ($d) => trim($d->first_name . ' ' . $d->last_name))->filter()->values()->all();
        $data = [
            'name' => $u->first_name ?: $name,
            'agency_name' => (string) $agency->name,
            'centre_name' => ($centreName !== '' && ! $ownHome) ? $centreName : (string) $agency->name,
            'role' => (string) $ra->role,
            'role_label' => $ownHome ? 'a home childcare provider' : (self::ROLE_LABEL[$ra->role] ?? 'a member of our team'),
            'director_names' => $dNames ? implode(' and ', $dNames) : 'your director',
            'directors' => $directors->map(fn ($d) => ['name' => trim($d->first_name . ' ' . $d->last_name),
                'email' => $d->email, 'phone' => $d->phone])->all(),
            'agency_email' => $agency->brand_support_email ?: $agency->contact_email,
            'agency_phone' => $agency->contact_phone,
            'portal_url' => rtrim((string) config('app.url', 'https://app.kiddietrac.com'), '/'),
        ];
        if ($testTo) {
            $list = fn (array $m) => implode(', ', array_map(fn ($e, $n) => ($n ?: $e) . ' <' . $e . '>', array_keys($m), $m)) ?: 'nobody';
            $data['test_note'] = 'TEST. The real email goes to ' . $name . ' <' . $u->email . '>, CC: '
                . $list($cc) . '. This copy went only to you.';
        }

        $html = EmailTemplates::render($agencyId, 'staff-welcome', EmailTemplates::blocks($agencyId, 'staff-welcome'), $data);
        $subject = ($testTo ? '[TEST] ' : '') . 'Welcome to ' . $agency->name . ', ' . ($u->first_name ?: $name) . '!';

        $to = $testTo ?: (string) $u->email;
        $toName = $testTo ? null : ($name ?: null);
        $ccList = $testTo ? [] : $cc;
        $send = function () use ($html, $subject, $to, $toName, $ccList, $agencyId, $testTo) {
            if ($testTo) {
                \App\Support\PlatformSettings::applyMail();
            }
            Mail::html($html, function ($m) use ($subject, $to, $toName, $ccList, $agencyId, $testTo) {
                $m->to($to, $toName)->subject($subject);
                foreach ($ccList as $em => $nm) {
                    $m->cc($em, $nm ?: null);
                }
                // Reaches someone who has only just finished onboarding (the not-onboarded gate).
                $m->getHeaders()->addTextHeader('X-KT-Invite', '1');
                $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                if ($testTo) {
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                }
            });
        };
        if ($testTo) {
            $send();
        } else {
            dispatch($send)->onQueue('mail');
        }

        try {
            \App\Support\Audit::write([
                'user_id' => null, 'agency_id' => $agencyId,
                'action' => $testTo ? 'email.staff_welcome_test' : 'email.staff_welcome',
                'entity_type' => 'user', 'entity_id' => $userId,
                'payload' => json_encode(['to' => $to, 'person' => $name, 'role' => $ra->role,
                    'centre' => $centreName, 'cc' => $testTo ? [] : array_values(array_map(
                        fn ($e, $n) => ($n ?: $e) . ' <' . $e . '>', array_keys($cc), $cc))]),
            ]);
        } catch (\Throwable $e) {
        }

        return true;
    }
}
