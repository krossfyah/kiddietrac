<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the "meet your child's provider" welcome to a family.
 *
 * Lifted out of AdminController so the parent-invite path can send it too: when a
 * family is added and gets its credentials, the introduction to their provider
 * should follow, and duplicating sixty lines into a second controller is how the
 * two copies start disagreeing about who gets a copy.
 *
 * ── One email, not one per parent ───────────────────────────────────────────
 * It used to loop the guardians and send each their own copy, CC'ing the whole
 * care team every time — so a two-parent family sent the director and educators
 * the same email twice, and each parent could read the team's addresses off the
 * CC line. Now: every parent on file goes in To (they are one household and know
 * each other), the team goes in BCC, and it sends once.
 */
final class ProviderWelcomeMailer
{
    /**
     * @param  array<int,array{email?:string,first_name?:string}>  $guardians
     * @param  array<int,string>  $childFirstNames
     * @return int  how many addresses it went to (0 = nothing sent)
     */
    /**
     * @param string|null $previewTo  Send to this address ALONE — no parents, no
     *                                BCC to the care team. Used by the template
     *                                editor's test button so that a preview goes
     *                                through this exact code path instead of a
     *                                second, quietly diverging copy of it.
     * @param array $draftBlocks      Unsaved editor blocks to render with.
     */
    public static function sendToFamily(int $centreId, ?int $agencyId, array $guardians, array $childFirstNames, ?string $previewTo = null, array $draftBlocks = []): int
    {
        $parents = [];
        foreach ($guardians as $g) {
            $email = trim((string) ($g['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $parents[strtolower($email)] = [
                    'email' => $email,
                    'first_name' => trim((string) ($g['first_name'] ?? '')),
                ];
            }
        }
        if (! $parents) {
            return 0;
        }

        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) {
            return 0;
        }

        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
        $s = ($agency && $agency->settings) ? (json_decode($agency->settings, true) ?: []) : [];
        $brand = $s['branding'] ?? [];
        /* BUG (fixed 2026-09-10): this only ever prefixed the host, and `avatars` is a
           PROTECTED folder — so the provider's photo went out as
           https://api.kiddietrac.com/storage/avatars/….jpg, which answers 403 to
           anyone without a signature. Every mail client fetching it got 403 and drew a
           broken image, while the agency logo (an unprotected folder) rendered fine,
           which is why it looked like a problem with that one picture.

           `ProtectedMedia::signForEmail` is the existing answer — a 30-day signature
           that outlives the email — and it returns the URL untouched when the path is
           not protected. Emails built through EmailTemplate::wrap already sign this
           way; this mailer renders its own blade directly and so missed it.

           A signed URL is returned AS IS. Its host is part of what was signed, and
           rewriting it is what previously turned signed media into 404s
           ([[kiddietrac-signed-url-host-rewrite]]). */
        $abs = function ($u) {
            if (! $u) {
                return null;
            }
            $signed = \App\Support\ProtectedMedia::signForEmail($u);
            if ($signed && preg_match('#^https?://#', (string) $signed)) {
                return $signed;
            }

            return preg_match('#^https?://#', (string) $u) ? $u : ('https://api.kiddietrac.com' . $u);
        };
        $childName = $childFirstNames[0] ?? '';
        // Country included: the agency's own address names one, and a contact block
        // that gives it for the agency but not the provider reads like an oversight.
        $providerAddress = trim(implode("\n", array_filter([
            $centre->address_line1 ?? null,
            trim(($centre->city ?? '') . ' ' . self::province($centre->province ?? '') . ' ' . ($centre->postal_code ?? '')),
            $centre->country ?? null,
        ], fn ($x) => trim((string) $x) !== ''))) ?: null;

        /* The care team: agency admin, this centre's director(s) and educator(s).
           BCC rather than CC — the family should not be handed a list of staff
           addresses, and the team does not need to see each other's either. */
        $bcc = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->whereNotNull('u.email')
            ->where(function ($q) use ($agencyId, $centreId) {
                $q->where(function ($x) use ($agencyId) { $x->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId); })
                  ->orWhere(function ($x) use ($centreId) { $x->whereIn('ra.role', ['centre_director', 'educator'])->where('ra.centre_id', $centreId); });
            })->distinct()->pluck('u.email')->filter()->values()->all();

        // A parent who is also on the care team would otherwise be in To and BCC.
        $bcc = array_values(array_filter($bcc, fn ($e) => ! isset($parents[strtolower((string) $e)])));

        $view = [
            'agencyName'      => $s['name'] ?? ($agency->name ?? 'Your childcare agency'),
            /* The banner is a dark gradient, so it wants the reverse logo.
               agency-2's file turned out to be RGBA with every pixel opaque and a
               solid white surround — the transparency was nominal, which is why
               dropping the white badge from the template changed nothing. The
               background is genuinely cut now, and the tagline (dark teal, 1.87:1
               against the banner) is lightened for dark. Kept as a SEPARATE asset:
               the original is still correct everywhere it sits on white. */
            'agencyLogoUrl'   => $abs($brand['logo_dark_url'] ?? $brand['logo_url'] ?? null),
            /* The banner logo above. This one is for the white panel that now sits
               above the gradient, so it wants the ORIGINAL upload — the artwork as
               the agency drew it, on the white it was drawn for — not the on-dark
               cut-out, whose whole purpose was surviving the gradient. Falls back
               to the cut-out only if an agency has no original on file. */
            'agencyLogoPanelUrl' => $abs($brand['logo_url'] ?? $brand['logo_dark_url'] ?? null),
            'agencyPhone'     => self::firstFilled([$s['phone'] ?? null, $agency->contact_phone ?? null]),
            /* "Who to contact" had no agency email on it at all. It read
               settings.data_contact_email — a key that does not exist on any agency —
               and then agencies.email, a column that does not exist either. Both
               resolved to null quietly, so the escalation contact was a name and a
               phone number with no address to write to. The real values live in
               agencies.contact_email / brand_support_email. */
            'agencyEmail'     => self::firstFilled([
                $s['data_contact_email'] ?? null,
                $agency->contact_email ?? null,
                $agency->brand_support_email ?? null,
                $agency->email_from_address ?? null,
            ]),
            'providerName'    => $centre->name,
            'providerPhotoUrl' => $abs(self::providerPhoto($centre, $centreId)),
            'providerBio'     => $centre->provider_bio ?: 'Your provider will share a little about themselves here soon.',
            'providerPhone'   => $centre->phone ?? null,
            'providerEmail'   => $centre->email ?? null,
            'parentFirstName' => self::greeting($parents),
            'childName'       => $childName,
            'portalUrl'       => 'https://app.kiddietrac.com',
            'primaryColor'    => $brand['primary_color'] ?? '#081C41',
            'accentColor'     => $brand['accent_color'] ?? '#2EA9AC',
            'privacyUrl'      => $s['brand_privacy_url'] ?? null,
            'termsUrl'        => $s['brand_terms_url'] ?? null,
            'agencyAddress'   => self::spellProvinces($s['brand_address'] ?? null),
            'agencyOwnerName' => $s['owner']['name'] ?? null,
            'providerAddress' => $providerAddress,
            'websiteUrl'      => self::firstFilled([$s['brand_website_url'] ?? null, $s['website'] ?? null, $agency->website ?? null]),
        ];
        // Unsaved blocks from the template editor win over the stored ones, so the
        // test shows what is on screen rather than what was last saved.
        if ($draftBlocks) {
            $s['provider_welcome'] = $draftBlocks;
        }
        $view = ProviderWelcomeTemplate::viewData($view, $s);

        try {
            $html = view('emails.provider-welcome', $view)->render();
            $to = $previewTo ? [$previewTo] : array_column($parents, 'email');
            $bcc = $previewTo ? [] : $bcc;
            $subject = 'Welcome to ' . $centre->name . " \u{2014} meet your child's provider";
            if ($previewTo) {
                $subject = '[Test] ' . $subject;
            }
            Mail::html($html, function ($m) use ($to, $bcc, $subject, $previewTo, $agencyId) {
                $m->to($to)->subject($subject);
                if ($bcc) {
                    $m->bcc($bcc);
                }
                /* BUG (fixed 2026-09-09): without this the suppression gate cannot tell
                   which tenant is sending, so it judges the address against EVERY account
                   that carries it. One address held three accounts across two agencies;
                   Test Agency's master switch being OFF silently cancelled iLearn's real
                   welcome emails, which then showed as "sent" in the audit log. */
                if ($agencyId) {
                    $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                }
                if ($previewTo) {
                    // An admin asked for this one on purpose; agency suppression
                    // would otherwise swallow it and look like a broken button.
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                } else {
                    // Reaches parents who have not onboarded yet; agency suppression
                    // still applies on top of this.
                    $m->getHeaders()->addTextHeader('X-KT-Invite', '1');
                }
            });

            return count($to);
        } catch (\Throwable $e) {
            Log::warning('Provider welcome send failed for family at centre ' . $centreId . ': ' . $e->getMessage());

            return 0;
        }
    }

    /** First value that is actually set. */
    private static function firstFilled(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                return $c;
            }
        }

        return null;
    }

    /** Canadian province and territory codes, written out. */
    private const PROVINCES = [
        'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba',
        'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut',
        'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec',
        'SK' => 'Saskatchewan', 'YT' => 'Yukon',
    ];

    /** "ON" -> "Ontario"; anything already spelled out is left alone. */
    private static function province(?string $v): string
    {
        $v = trim((string) $v);

        return self::PROVINCES[strtoupper($v)] ?? $v;
    }

    /**
     * Expand province codes inside a free-text address block.
     *
     * The agency address is one editable string ("Mono, ON  L9V 1C9"), so this has
     * to work on a word in the middle of a line. Bounded to a standalone two-letter
     * token that is a real code — which is why it will not touch "ON" inside a word,
     * and why an address that already says Ontario passes through untouched.
     */
    private static function spellProvinces(?string $address): ?string
    {
        $address = (string) $address;
        if (trim($address) === '') {
            return null;
        }
        $codes = implode('|', array_keys(self::PROVINCES));

        return preg_replace_callback(
            '/(?<![A-Za-z])(' . $codes . ')(?![A-Za-z])/',
            fn ($m) => self::PROVINCES[strtoupper($m[1])],
            $address
        );
    }

    /**
     * The provider's actual face, falling back to the avatar only when there isn't one.
     *
     * This used to read centres.logo_url alone — which is empty for every home
     * provider on the system, so every one of these emails introduced the provider
     * with a generic silhouette. The photo is on the USER: home providers are people,
     * and the centre is named after them.
     *
     * Matching by name rather than taking the first educator is the part that
     * matters. Centre 14 has three educators attached; "first" would have put a
     * colleague's face under the provider's name, which is worse than the avatar —
     * a parent would be introduced to the wrong person entirely.
     */
    private static function providerPhoto(object $centre, int $centreId): ?string
    {
        // An explicitly set centre logo wins: a real multi-room centre is a place,
        // not a person, and somebody chose that image on purpose.
        if (! empty($centre->logo_url)) {
            return $centre->logo_url;
        }

        $staff = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)
            ->where('ra.centre_id', $centreId)
            ->whereIn('ra.role', ['centre_director', 'educator'])
            ->whereNotNull('u.photo_url')
            ->where('u.photo_url', '!=', '')
            ->distinct()
            ->get(['u.first_name', 'u.last_name', 'u.photo_url']);

        if ($staff->isEmpty()) {
            return null;
        }

        $norm = fn ($v) => preg_replace('/[^a-z]/', '', strtolower((string) $v));
        $centreKey = $norm($centre->name ?? '');

        foreach ($staff as $p) {
            if ($centreKey !== '' && $norm($p->first_name . $p->last_name) === $centreKey) {
                return $p->photo_url;
            }
        }
        // A partial match covers "Eisha Wright-Finikin" the centre vs "Eisha Wright"
        // the user — the same person, recorded twice with different care.
        foreach ($staff as $p) {
            $person = $norm($p->first_name . $p->last_name);
            if ($person !== '' && $centreKey !== '' && (str_starts_with($centreKey, $person) || str_starts_with($person, $centreKey))) {
                return $p->photo_url;
            }
        }
        // Nobody matched by name. One member of staff is unambiguous; more than one
        // is a guess, and the avatar is the honest answer to a guess.
        return $staff->count() === 1 ? $staff->first()->photo_url : null;
    }

    /**
     * "Anthony", "Anthony and Sarah", "Anthony, Sarah and Jo".
     *
     * One email now goes to both parents, so the greeting has to name both — the
     * per-parent send it replaced could just use whichever name it was looping on.
     */
    private static function greeting(array $parents): string
    {
        $names = array_values(array_filter(array_map(
            fn ($p) => trim((string) ($p['first_name'] ?? '')),
            $parents
        )));
        if (! $names) {
            return 'there';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }
}
