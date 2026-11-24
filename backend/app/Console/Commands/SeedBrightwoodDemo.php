<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * BRIGHTWOOD EARLY LEARNING — the white-label US demo agency (2026-10-28). Anthony: "create me a new agency that is
 * completely white labelled including a new landing page for the login - make this a reusable demo agency so I can
 * show customers and fill in with demo data (turn off the AI and email for now), use the growth agency for this one
 * ... create a director, admin, educator and parent login", then "the demo agency needs to be setup as a US agency",
 * "use a california address in longbeach" and "look for any US, california compliance related childcare items".
 *
 * CALIFORNIA (US-CA, App\Support\Jurisdictions): Title 22 ratios, small/large family child care homes, fire + disaster
 * drills every six months, Live Scan + CACI clearances, TB / SB 792 staff immunizations / mandated reporter training,
 * the CDPH child care immunization table with medical exemptions only. USD, America/Los_Angeles, en-US.
 *
 * SILENT BY CONSTRUCTION, like kt:seed-ohio-demo: the agency's master switch is off (settings.notifications_enabled =
 * false), SMS / voice / summaries off, every centre and room has email off, every address is on the non-routable
 * DOMAIN below, and everything is inserted directly so no mailer, listener or push runs. The AI is switched off for it
 * in Platform → AI usage (ai.off_agencies).
 *
 * WHITE LABEL: powered_by_visible = 0 with its own logo, colours and banner gradient (App\Support\Brand). Its sign-in
 * page is app.kiddietrac.com/?tenant=brightwood (short link: app.kiddietrac.com/brightwood/).
 *
 *   php artisan kt:seed-brightwood-demo            create it (does nothing if it already exists)
 *   php artisan kt:seed-brightwood-demo --rebuild  delete THIS agency's demo data and create it again
 *
 * Logins (random passwords) go to storage/app/private/brightwood-demo-logins.json (mode 600), never printed.
 * The three WEBSITE DEMO accounts (2026-10-30) are not this seeder's: App\Support\PublicDemo::ACCOUNTS gives them their
 * @brightwood.kiddietrac.com addresses and passwords (kt:reset-web-demo restores them nightly). A --rebuild makes new
 * ids, so PublicDemo::USERS/ACCOUNTS would need the new ids.
 * demo:seed-daily keeps its day fresh (scheduled in routes/console.php).
 */
final class SeedBrightwoodDemo extends Command
{
    protected $signature = 'kt:seed-brightwood-demo {--rebuild : Delete this demo agency first and rebuild it} {--content : (re)write the website demo content: photos, videos, menus, lesson plans, invoices}';

    protected $description = 'Create (or rebuild) Brightwood Early Learning — the silent, white-label California demo agency';

    public const SLUG = 'brightwood';
    public const DOMAIN = 'brightwood-demo.kiddietrac.invalid';
    private const TZ = 'America/Los_Angeles';
    private const LOGO = '/storage/branding/brightwood-wordmark.png';
    private const ICON = '/storage/branding/brightwood-icon-512.png';
    private const FAVICON = '/storage/branding/brightwood-favicon-64.png';

    private int $agencyId = 0;
    private Carbon $now;
    private Carbon $today;
    private array $logins = [];
    private int $phone = 0;

    public function handle(): int
    {
        if ($this->option('content')) {
            $id = (int) DB::table('agencies')->where('slug', self::SLUG)->value('id');
            if (! $id) { $this->error('The demo agency does not exist yet.'); return self::FAILURE; }
            $this->line(json_encode(\App\Support\WebDemoContent::seedAgency($id)));
            return self::SUCCESS;
        }
        $this->now = now();
        $this->today = Carbon::now(self::TZ)->startOfDay();
        $existing = DB::table('agencies')->where('slug', self::SLUG)->first();
        if ($existing && ! $this->option('rebuild')) {
            $this->info('Brightwood demo agency already exists: #' . $existing->id . ' (use --rebuild to recreate it).');
            return self::SUCCESS;
        }
        if ($existing) {
            $this->purge((int) $existing->id);
            $this->info('Removed the previous Brightwood demo agency #' . $existing->id . '.');
        }

        DB::transaction(function () {
            $this->agency();
            $this->schedule();
            [$center, $small, $large] = $this->centres();
            $rooms = $this->rooms($center, $small, $large);
            $staff = $this->staff($center, $small, $large, $rooms);
            $this->families($center, $small, $large, $rooms, $staff);
            $this->compliance($staff);
            $this->household($small, $large, $staff);
            $this->drills($center, $small, $large, $rooms, $staff);
            $this->attendance($center, $small, $large, $staff);
            $this->billing($staff);
        });
        $this->aiOff();

        $path = storage_path('app/private/brightwood-demo-logins.json');
        if (! is_dir(dirname($path))) @mkdir(dirname($path), 0700, true);
        file_put_contents($path, json_encode(['agency_id' => $this->agencyId, 'created_at' => (string) $this->now,
            'sign_in' => 'https://app.kiddietrac.com/?tenant=' . self::SLUG,
            'note' => 'Brightwood Early Learning demo accounts (fake people). Do not reuse these passwords anywhere.', 'logins' => $this->logins], JSON_PRETTY_PRINT));
        @chmod($path, 0600);

        $this->info('Brightwood demo agency #' . $this->agencyId . ' ready. Logins: ' . $path);
        return self::SUCCESS;
    }

    /* ───────────── helpers ───────────── */

    /** 555-0100 to 555-0199 are the numbers set aside for fiction; 562 is Long Beach. */
    private function phone(): string
    {
        return '562-555-01' . str_pad((string) ($this->phone++ % 100), 2, '0', STR_PAD_LEFT);
    }

    private function user(string $first, string $last, string $local, array $extra = [], ?string $loginRole = null): int
    {
        $email = strtolower($local) . '@' . self::DOMAIN;
        $password = 'Bw-' . rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Ab'), '=');
        $id = DB::table('users')->insertGetId(array_merge([
            'email' => $email, 'first_name' => $first, 'last_name' => $last, 'phone' => $this->phone(),
            'password' => Hash::make($password), 'password_changed_at' => $this->now, 'must_change_password' => 0,
            'email_verified_at' => $this->now, 'status' => 'active', 'onboarded_at' => $this->now, 'locale' => 'en-US',
            'sms_opt_in' => 0, 'voice_opt_out' => 1, 'created_at' => $this->now, 'updated_at' => $this->now,
        ], $extra));
        if ($loginRole) $this->logins[] = ['role' => $loginRole, 'name' => $first . ' ' . $last, 'user_id' => $id, 'email' => $email, 'password' => $password];
        return $id;
    }

    private function role(int $userId, string $role, ?int $centreId): void
    {
        DB::table('role_assignments')->insert(['user_id' => $userId, 'role' => $role, 'agency_id' => $this->agencyId, 'centre_id' => $centreId, 'active' => 1, 'created_at' => $this->now]);
    }

    private function at(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $this->today->copy()->setTime($h, $m)->utc()->toDateTimeString();
    }

    private function dob(int $months, int $days = 0): string
    {
        return Carbon::now(self::TZ)->subMonths($months)->subDays($days)->toDateString();
    }

    /* ───────────── the build ───────────── */

    private function agency(): void
    {
        $address = "1250 Pacific Shore Blvd, Suite 200\nLong Beach, CA 90802\nUnited States";
        $this->agencyId = DB::table('agencies')->insertGetId([
            'name' => 'Brightwood Early Learning', 'slug' => self::SLUG, 'legal_name' => 'Brightwood Early Learning LLC (demo — not a real business)',
            'contact_email' => 'hello@' . self::DOMAIN, 'contact_phone' => '562-555-0100',
            'timezone' => self::TZ, 'locale' => 'en-US', 'default_locale' => 'en', 'currency' => 'USD', 'plan_currency' => 'USD',
            'jurisdiction' => 'US-CA', 'country' => 'US', 'province' => 'CA', 'city' => 'Long Beach', 'postal_code' => '90802',
            'address_line1' => '1250 Pacific Shore Blvd', 'address_line2' => 'Suite 200',
            'billing_status' => 'trial', 'plan_code' => 'growth', 'billing_interval' => 'monthly',
            // California does not tax child care tuition.
            'sms_enabled' => 0, 'voice_enabled' => 0, 'tax_rate_bps' => 0, 'tax_label' => 'Sales tax',
            // WHITE LABEL: no "Powered by KiddieTrac", its own identity everywhere (App\Support\Brand).
            // 2026-10-30 (Anthony): the website demo phone shows KiddieTrac's own brand; white label = kt:seed-starlight-demo.
            'powered_by_visible' => 1,
            'brand_logo_url' => self::LOGO, 'logo_url' => self::ICON, 'brand_primary_color' => '#1F7A4D', 'brand_support_email' => 'support@' . self::DOMAIN,
            'email_from_name' => 'Brightwood Early Learning', 'ref_prefix' => 'BW',
            'settings' => json_encode([
                // 'centre' + a US country = "Center" on screen (kt-term.js US spelling).
                'demo' => true, 'country' => 'US', 'centre_term' => 'centre',
                // Anthony 2026-10-28: the demo admin/director skip the forced 2FA setup (MfaController::demoExempt).
                'mfa_exempt' => true,
                // Silent: the agency's own master switch - nothing is sent for anyone attached to it.
                'notifications_enabled' => false, 'summaries_enabled' => false,
                'brand_address' => $address,
                'branding' => [
                    'product_name' => 'Brightwood Early Learning',
                    'tagline' => 'Growing bright minds, one day at a time',
                    'login_subtitle' => 'Sign in to Brightwood — your day, your children, all in one place.',
                    'logo_url' => self::LOGO, 'logo_square_url' => self::ICON, 'favicon_url' => self::FAVICON,
                    'splash_logo_url' => self::LOGO, 'primary_color' => '#1F7A4D', 'accent_color' => '#F59E0B',
                    'splash_bg' => '#ECF7EF', 'support_email' => 'support@' . self::DOMAIN, 'email_from_name' => 'Brightwood Early Learning',
                    'hero_from' => '#14532D', 'hero_via' => '#1F7A4D', 'hero_to' => '#3FA36C', 'hero_angle' => 135,
                    // Its own sign-in showcase (branding-loader.js applySigninCopy): no KiddieTrac / Canadian / AI claims.
                    'login_page' => self::loginPage(),
                ],
                'billing_setup' => ['default_billing_frequency' => 'monthly', 'accepted_payment_methods' => ['stripe_card', 'stripe_ach', 'cash', 'cheque'],
                    'invoice_issue_lead_days' => 5, 'due_window_days' => 14],
                'safe_arrival' => ['enabled' => false],
            ]),
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
    }

    /** The words on Brightwood's sign-in page (app.kiddietrac.com/?tenant=brightwood). */
    public static function loginPage(): array
    {
        return [
            'badge' => '✦ LONG BEACH, CA · INFANT TO SCHOOL-AGE',
            'headline' => ['Every moment,', 'every milestone —'], 'highlight' => 'shared with you.',
            'lede' => 'From morning drop-off to afternoon pick-up, Brightwood keeps you close to your child’s day — photos, meals, naps and notes from their teachers.',
            'features' => [
                ['icon' => '📸', 'title' => 'Photos & daily updates', 'text' => 'See what your child is doing, eating and learning — as it happens.'],
                ['icon' => '💬', 'title' => 'Message the classroom', 'text' => 'Reach your child’s teachers anytime, right from your phone.'],
                ['icon' => '💳', 'title' => 'Easy tuition payments', 'text' => 'Pay by card or bank account (ACH), see your statement and download receipts anytime.'],
            ],
            'trust' => ['Licensed child care', 'California Title 22 ready', 'Secure sign-in'],
        ];
    }

    /** The CDPH child care schedule (IMM-230) — what the jurisdiction profile names. */
    private function schedule(): void
    {
        $m = new \ReflectionMethod(\App\Http\Controllers\Api\ImmunizationScheduleController::class, 'presetRows');
        $m->setAccessible(true);
        $order = 0;
        foreach ($m->invoke(null, 'cdph_child_care') as [$vaccine, $dose, $months]) {
            DB::table('immunization_schedule')->insert(['agency_id' => $this->agencyId, 'vaccine' => $vaccine, 'dose_label' => $dose, 'due_at_age_months' => $months,
                'is_required' => 1, 'display_order' => $order++, 'active' => 1, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
    }

    private function centres(): array
    {
        $base = ['agency_id' => $this->agencyId, 'city' => 'Long Beach', 'province' => 'CA', 'country' => 'US', 'status' => 'active',
            'open_time' => '07:00:00', 'close_time' => '18:00:00', 'settings' => json_encode(['email_enabled' => false]), 'created_at' => $this->now, 'updated_at' => $this->now];
        $center = DB::table('centres')->insertGetId($base + ['name' => 'Brightwood Learning Center — Long Beach', 'slug' => 'brightwood-long-beach', 'license_type' => 'ca_center',
            'license_number' => 'DEMO-198000001', 'license_capacity' => 120, 'address_line1' => '1250 Pacific Shore Blvd', 'postal_code' => '90802',
            'phone' => $this->phone(), 'email' => 'longbeach@' . self::DOMAIN, 'tagline' => 'Infant, toddler, preschool and school-age care']);
        $small = DB::table('centres')->insertGetId($base + ['name' => 'Seabreeze Family Child Care (Small)', 'slug' => 'brightwood-seabreeze', 'license_type' => 'ca_small_fcch',
            'license_number' => 'DEMO-198000002', 'license_capacity' => 8, 'address_line1' => '3418 Seabreeze Ln', 'postal_code' => '90803',
            'phone' => $this->phone(), 'email' => 'grace.provider@' . self::DOMAIN, 'supervisor_first_name' => 'Grace', 'supervisor_last_name' => 'Holloway',
            'provider_bio' => 'Demo provider. Eight years running a licensed home near the beach: morning walks, a vegetable garden the children tend, and a cosy reading nook.']);
        $large = DB::table('centres')->insertGetId($base + ['name' => 'Harbor View Family Child Care (Large)', 'slug' => 'brightwood-harbor-view', 'license_type' => 'ca_large_fcch',
            'license_number' => 'DEMO-198000003', 'license_capacity' => 14, 'address_line1' => '5720 Harbor View Ave', 'postal_code' => '90808',
            'phone' => $this->phone(), 'email' => 'marcus.provider@' . self::DOMAIN, 'supervisor_first_name' => 'Marcus', 'supervisor_last_name' => 'Reid',
            'provider_bio' => 'Demo provider. Former kindergarten teacher; my home program mixes structured learning with lots of free play, with an assistant provider every day.']);
        return [$center, $small, $large];
    }

    /** Title 22 teacher ratios per room (infant 1:4, toddler program 1:6, preschool 1:12, school-age 1:14). */
    private function rooms(int $center, int $small, int $large): array
    {
        $mk = function (int $centre, string $name, string $group, int $min, int $max, int $cap, int $ratio, string $color) {
            return DB::table('rooms')->insertGetId(['centre_id' => $centre, 'name' => $name, 'age_group' => $group, 'age_min_months' => $min, 'age_max_months' => $max,
                'capacity' => $cap, 'ratio_educators' => 1, 'ratio_children' => $ratio, 'color_hex' => $color, 'active' => 1, 'email_enabled' => 0,
                'created_at' => $this->now, 'updated_at' => $this->now]);
        };
        return [
            'infant' => $mk($center, 'Seedlings (Infants)', 'infant', 0, 18, 12, 4, '#F59E0B'),
            'toddler' => $mk($center, 'Sprouts (Toddler program)', 'toddler', 18, 36, 12, 6, '#EC4899'),
            'pre3' => $mk($center, 'Acorns (Preschool 3s)', 'preschool', 36, 48, 24, 12, '#0EA5E9'),
            'pre4' => $mk($center, 'Oaks (Pre-K 4s & 5s)', 'preschool', 48, 72, 24, 12, '#10B981'),
            'school' => $mk($center, 'Explorers (School-Age)', 'school_age', 72, 144, 28, 14, '#6366F1'),
            'home_s' => $mk($small, 'Seabreeze Home', 'preschool', 0, 144, 8, 8, '#B45309'),
            'home_l' => $mk($large, 'Harbor View Home', 'preschool', 0, 144, 14, 7, '#0F766E'),
        ];
    }

    private function staff(int $center, int $small, int $large, array $rooms): array
    {
        $hire = fn (int $daysAgo) => Carbon::now(self::TZ)->subDays($daysAgo)->toDateString();
        $s = [];
        // The four test logins: admin@, director@, educator@, parent@ (parent is made in families()).
        $s['admin'] = $this->user('Harper', 'Lane', 'admin', ['hire_date' => $hire(900)], 'agency_admin');
        $this->role($s['admin'], 'agency_admin', null);
        $s['director'] = $this->user('Elena', 'Brooks', 'director', ['hire_date' => $hire(700)], 'centre_director');
        $this->role($s['director'], 'centre_director', $center);

        $eds = [
            ['Maya', 'Thompson', 'educator', 'infant', 400, 'educator'],
            ['Lucas', 'Bennett', 'lucas.educator', 'toddler', 45, null],
            ['Sofia', 'Ramirez', 'sofia.educator', 'pre3', 120, null],
            ['Noah', 'Kim', 'noah.educator', 'pre4', 800, null],
            ['Ethan', 'Clarke', 'ethan.educator', 'school', 1500, null],
            ['Ava', 'Mitchell', 'ava.educator', 'infant', 20, null],
        ];
        foreach ($eds as $k => [$f, $l, $local, $room, $days, $login]) {
            $id = $this->user($f, $l, $local, ['hire_date' => $hire($days)], $login);
            $this->role($id, 'educator', $center);
            DB::table('educator_rooms')->insert(['user_id' => $id, 'room_id' => $rooms[$room], 'assigned_by_id' => $s['director'], 'created_at' => $this->now]);
            $s['educators'][$k === 5 ? 'infant2' : $room] = $id;
        }

        // Home providers: the provider's email IS the home's email (that is how the platform knows they are the provider).
        $s['provider_s'] = $this->user('Grace', 'Holloway', 'grace.provider', ['hire_date' => $hire(2900)]);
        $this->role($s['provider_s'], 'educator', $small);
        DB::table('educator_rooms')->insert(['user_id' => $s['provider_s'], 'room_id' => $rooms['home_s'], 'assigned_by_id' => $s['admin'], 'created_at' => $this->now]);
        $s['provider_l'] = $this->user('Marcus', 'Reid', 'marcus.provider', ['hire_date' => $hire(1300)]);
        $this->role($s['provider_l'], 'educator', $large);
        DB::table('educator_rooms')->insert(['user_id' => $s['provider_l'], 'room_id' => $rooms['home_l'], 'assigned_by_id' => $s['admin'], 'created_at' => $this->now]);
        $s['assistant_l'] = $this->user('Nina', 'Patel', 'nina.assistant', ['hire_date' => $hire(70)]);
        $this->role($s['assistant_l'], 'educator', $large);
        DB::table('educator_rooms')->insert(['user_id' => $s['assistant_l'], 'room_id' => $rooms['home_l'], 'assigned_by_id' => $s['admin'], 'created_at' => $this->now]);
        return $s;
    }

    private function families(int $center, int $small, int $large, array $rooms, array &$staff): void
    {
        $famId = null; $surname = null;
        $surnames = ['Adams', 'Baker', 'Campbell', 'Dawson', 'Ellis', 'Foster', 'Gonzalez', 'Hughes', 'Irving', 'Johnson', 'Keller', 'Lawson',
            'Morgan', 'Nguyen', 'Owens', 'Parker', 'Quinn', 'Russell', 'Santos', 'Tucker'];
        $firsts = ['Olivia', 'Liam', 'Emma', 'Mason', 'Amelia', 'Elijah', 'Harper', 'James', 'Evelyn', 'Henry', 'Abigail', 'Leo', 'Ella', 'Jack', 'Scarlett', 'Levi',
            'Aria', 'Wyatt', 'Chloe', 'Owen', 'Lily', 'Caleb', 'Zoey', 'Isaac', 'Nora', 'Grayson', 'Hazel', 'Julian', 'Violet', 'Ezra', 'Aurora', 'Hudson', 'Stella', 'Miles', 'Lucy', 'Asher'];
        // [room, age in months, children]: the small home stays inside "6 with no more than 3 infants" with Rosie; the large home is 9 with 3 under 2.
        $plan = [
            ['infant', 6, 1], ['infant', 9, 1], ['infant', 4, 1], ['infant', 11, 1], ['infant', 15, 1],
            ['toddler', 20, 4], ['toddler', 28, 3], ['toddler', 33, 2],
            ['pre3', 38, 4], ['pre3', 44, 3],
            ['pre4', 50, 5], ['pre4', 60, 5],
            ['school', 80, 3], ['school', 100, 2],
            ['home_s', 8, 1], ['home_s', 14, 1], ['home_s', 40, 1], ['home_s', 75, 2],
            ['home_l', 10, 1], ['home_l', 16, 1], ['home_l', 20, 1], ['home_l', 36, 3], ['home_l', 55, 3],
        ];
        $centreOf = fn (string $room) => $room === 'home_s' ? $small : ($room === 'home_l' ? $large : $center);
        $fi = 0; $ci = 0; $loginGiven = false;
        $children = [];
        $streets = ['Ocean Blvd', 'Atlantic Ave', 'Redondo Ave', 'Broadway', 'Carson St', 'Los Coyotes Diagonal', 'Studebaker Rd', 'Pine Ave'];
        $zips = ['90802', '90803', '90804', '90805', '90806', '90807', '90808', '90815'];
        foreach ($plan as [$room, $months, $n]) {
            for ($i = 0; $i < $n; $i++) {
                if ($i % 2 === 1 && isset($famId, $surname)) {
                    $childId = $this->child($famId, $firsts[$ci % count($firsts)], $surname, $months, $rooms[$room], $ci);
                    $children[] = [$childId, $months, $ci];
                    $ci++;
                    continue;
                }
                $surname = $surnames[$fi % count($surnames)] . ($fi >= count($surnames) ? '-' . ['Reyes', 'Shaw', 'Cole'][intdiv($fi, count($surnames)) % 3] : '');
                $centreId = $centreOf($room);
                $famId = DB::table('families')->insertGetId(['centre_id' => $centreId, 'family_name' => $surname, 'primary_phone' => $this->phone(),
                    'primary_email' => 'family.' . strtolower(str_replace([' ', '-'], '', $surname)) . '@' . self::DOMAIN,
                    'address_line1' => (1100 + $fi * 37) . ' ' . $streets[$fi % count($streets)], 'city' => 'Long Beach',
                    'province' => 'CA', 'postal_code' => $zips[$fi % count($zips)], 'country' => 'US', 'preferred_lang' => 'en',
                    'created_at' => $this->now, 'updated_at' => $this->now]);
                $parentFirst = ['Rachel', 'Daniel', 'Jessica', 'Michael', 'Ashley', 'Chris', 'Megan', 'Ryan'][$fi % 8];
                // The first family's parent is the test login parent@.
                $local = $loginGiven ? 'parent.' . strtolower(str_replace([' ', '-'], '', $surname)) : 'parent';
                $uid = $this->user($parentFirst, $surname, $local, [], $loginGiven ? null : 'parent');
                $loginGiven = true;
                $this->role($uid, 'guardian', $centreId);
                DB::table('guardians')->insert(['family_id' => $famId, 'user_id' => $uid, 'relationship' => $fi % 2 ? 'father' : 'mother', 'is_primary' => 1,
                    'can_pickup' => 1, 'can_receive_billing' => 1, 'created_at' => $this->now]);
                $childId = $this->child($famId, $firsts[$ci % count($firsts)], $surname, $months, $rooms[$room], $ci);
                $children[] = [$childId, $months, $ci];
                $fi++; $ci++;
            }
        }
        // The provider's own child under 10 counts toward California home limits.
        $own = DB::table('families')->insertGetId(['centre_id' => $small, 'family_name' => 'Holloway', 'primary_email' => 'grace.provider@' . self::DOMAIN,
            'primary_phone' => $this->phone(), 'address_line1' => '3418 Seabreeze Ln', 'city' => 'Long Beach', 'province' => 'CA', 'postal_code' => '90803', 'country' => 'US',
            'notes' => "Provider's own child (under 10, counts toward the home's capacity)", 'created_at' => $this->now, 'updated_at' => $this->now]);
        DB::table('guardians')->insert(['family_id' => $own, 'user_id' => $staff['provider_s'], 'relationship' => 'mother', 'is_primary' => 1, 'can_pickup' => 1, 'can_receive_billing' => 0, 'created_at' => $this->now]);
        $staff['own_child'] = $this->child($own, 'Rosie', 'Holloway', 34, $rooms['home_s'], 99);
        $children[] = [$staff['own_child'], 34, 99];
        $this->immunizations($children, $staff['director']);
    }

    private function child(int $famId, string $first, string $last, int $months, int $roomId, int $i): int
    {
        $id = DB::table('children')->insertGetId(['family_id' => $famId, 'first_name' => $first, 'last_name' => $last, 'date_of_birth' => $this->dob($months, $i % 20),
            'gender' => ['female', 'male'][$i % 2], 'enrollment_status' => 'enrolled', 'enrolled_at' => $this->now, 'primary_room_id' => $roomId,
            'allergies' => $i % 7 === 3 ? json_encode([['allergen' => 'Peanuts', 'severity' => 'severe']]) : null, 'preferred_lang' => 'en', 'created_at' => $this->now, 'updated_at' => $this->now]);
        // Long Beach-ish monthly tuition (demo numbers).
        $fee = $months < 18 ? 2150 : ($months < 36 ? 1850 : ($months < 72 ? 1450 : 850));
        DB::table('enrollments')->insert(['child_id' => $id, 'room_id' => $roomId, 'start_date' => Carbon::now(self::TZ)->subMonths(3)->toDateString(),
            'schedule' => json_encode(['mon', 'tue', 'wed', 'thu', 'fri']), 'monthly_fee' => $fee, 'cwelcc_eligible' => 0, 'created_at' => $this->now]);
        return $id;
    }

    /** Doses per the CDPH table; every 6th child is behind (missing the latest checkpoint); two have a MEDICAL exemption (SB 277: no belief exemptions). */
    private function immunizations(array $children, int $recordedBy): void
    {
        $sched = DB::table('immunization_schedule')->where('agency_id', $this->agencyId)->where('active', 1)->get();
        foreach ($children as $n => [$childId, $months, $i]) {
            $behind = $n % 6 === 2;
            foreach ($sched as $row) {
                if ($row->due_at_age_months > $months) continue;
                if ($behind && $row->due_at_age_months >= $months - 6) continue;
                $exempt = ($n === 5 && $row->vaccine === 'MMR') || ($n === 11 && $row->vaccine === 'Varicella');
                DB::table('immunizations')->insert(['child_id' => $childId, 'vaccine' => $row->vaccine, 'dose_label' => $row->dose_label,
                    'administered_on' => $exempt ? null : Carbon::now(self::TZ)->subMonths(max(0, $months - $row->due_at_age_months))->toDateString(),
                    'clinic_name' => $exempt ? null : 'Harbor Pediatrics of Long Beach (fictional)', 'exempt' => $exempt ? 1 : 0,
                    'exemption_reason' => $exempt ? 'Medical exemption (signed by a physician, CAIR-ME) — demo' : null,
                    'recorded_by_id' => $recordedBy, 'created_at' => $this->now, 'updated_at' => $this->now]);
            }
        }
    }

    /** California staff file: Live Scan + CACI clearances, TB/health screening, SB 792 shots, mandated reporter, the 15-hour course. Gaps on purpose. */
    private function compliance(array $s): void
    {
        $d = fn (int $days) => Carbon::now(self::TZ)->addDays($days)->toDateString();
        $cert = function (int $uid, string $type, ?int $issuedDaysAgo, ?int $expiresInDays = null) use ($d) {
            DB::table('staff_certifications')->insert(['user_id' => $uid, 'cert_type' => $type, 'certifier' => 'Demo Training Co. (fictional)',
                'issued_at' => $issuedDaysAgo === null ? null : $d(-$issuedDaysAgo), 'expires_at' => $expiresInDays === null ? null : $d($expiresInDays), 'active' => 1, 'created_at' => $this->now]);
        };
        $check = function (int $uid, string $type, int $issuedDaysAgo) use ($d) {
            DB::table('background_checks')->insert(['agency_id' => $this->agencyId, 'user_id' => $uid, 'check_type' => $type, 'reference' => 'DEMO-' . strtoupper($type) . '-' . $uid,
                'issued_at' => $d(-$issuedDaysAgo), 'expires_at' => null, 'notes' => 'Demo record (fictional)',
                'created_at' => $this->now, 'updated_at' => $this->now]);
        };
        $full = function (int $uid, int $days) use ($cert, $check) {
            $check($uid, 'livescan', $days); $check($uid, 'caci', $days);
            $cert($uid, 'TB_Clearance', $days); $cert($uid, 'Staff_Immunizations', $days, 300);
            $cert($uid, 'Mandated_Reporter', 120, 610);
            $cert($uid, 'First_Aid', 200, 530); $cert($uid, 'CPR', 200, 530); $cert($uid, 'ECE_Units', $days);
        };
        $e = $s['educators'];
        foreach ([$s['director'], $e['infant'], $e['pre4'], $s['provider_s'], $s['provider_l']] as $uid) $full($uid, 300);
        // The 15-hour EMSA course: the director (center) and each home licensee.
        foreach ([$s['director'], $s['provider_s'], $s['provider_l']] as $uid) $cert($uid, 'Health_Safety_15', 400);
        $cert($s['director'], 'CD_Permit', 500, 1300);
        // Ethan: long-serving, mandated reporter training lapsed (2-year renewal), CPR expired.
        $check($e['school'], 'livescan', 1500); $check($e['school'], 'caci', 1500);
        $cert($e['school'], 'TB_Clearance', 1500); $cert($e['school'], 'Staff_Immunizations', 400, -35);
        $cert($e['school'], 'Mandated_Reporter', 800, -70); $cert($e['school'], 'CPR', 800, -20); $cert($e['school'], 'First_Aid', 600, 130);
        // Lucas: 45 days in, mandated reporter training still due (90 days).
        $check($e['toddler'], 'livescan', 50); $check($e['toddler'], 'caci', 50);
        $cert($e['toddler'], 'TB_Clearance', 46); $cert($e['toddler'], 'Staff_Immunizations', 46, 320);
        // Sofia: no CACI on file yet.
        $check($e['pre3'], 'livescan', 125);
        $cert($e['pre3'], 'TB_Clearance', 121); $cert($e['pre3'], 'Staff_Immunizations', 121, 240); $cert($e['pre3'], 'Mandated_Reporter', 100, 630);
        // Ava: 20 days in, TB clearance overdue (7 days), shots missing.
        $check($e['infant2'], 'livescan', 22); $check($e['infant2'], 'caci', 22);
        // Nina (assistant provider): cleared, training under way.
        $check($s['assistant_l'], 'livescan', 75); $check($s['assistant_l'], 'caci', 75);
        $cert($s['assistant_l'], 'TB_Clearance', 72); $cert($s['assistant_l'], 'Staff_Immunizations', 72, 290);

        $pd = function (int $uid, int $daysAgo, string $title, float $hours) {
            DB::table('pd_hours')->insert(['agency_id' => $this->agencyId, 'user_id' => $uid, 'taken_on' => Carbon::now(self::TZ)->subDays($daysAgo)->toDateString(),
                'title' => $title, 'provider' => 'Demo PD Online (fictional)', 'hours' => $hours, 'status' => 'approved', 'created_by' => $uid,
                'created_at' => $this->now, 'updated_at' => $this->now]);
        };
        $pd($e['infant'], 60, 'Infant/toddler responsive care (demo)', 3); $pd($e['infant'], 20, 'Safe sleep refresher (demo)', 3.5);
        $pd($e['pre4'], 40, 'Early literacy through play (demo)', 2);
        $pd($e['school'], 10, 'Positive behavior guidance (demo)', 1.5);
        $pd($s['provider_s'], 30, 'Family child care business basics (demo)', 6);
        $pd($s['director'], 15, 'Title 22 updates for directors (demo)', 4);
    }

    /** Every adult living in a family child care home is cleared (H&SC 1596.871). */
    private function household(int $small, int $large, array $s): void
    {
        $d = fn (int $days) => Carbon::now(self::TZ)->addDays($days)->toDateString();
        $add = function (int $centre, string $name, string $rel, int $ageYears, array $checks) use ($d, $s) {
            $id = DB::table('household_residents')->insertGetId(['agency_id' => $this->agencyId, 'centre_id' => $centre, 'full_name' => $name, 'relationship' => $rel,
                'date_of_birth' => Carbon::now(self::TZ)->subYears($ageYears)->toDateString(), 'moved_in_on' => $d(-2000), 'notes' => 'Demo person (fictional)',
                'created_by' => $s['admin'], 'created_at' => $this->now, 'updated_at' => $this->now]);
            foreach ($checks as [$type, $issuedDaysAgo]) {
                DB::table('household_resident_checks')->insert(['resident_id' => $id, 'check_type' => $type, 'reference' => 'DEMO-HH-' . strtoupper($type) . '-' . $id,
                    'issued_at' => $d(-$issuedDaysAgo), 'expires_at' => null, 'created_by' => $s['admin'], 'created_at' => $this->now, 'updated_at' => $this->now]);
            }
        };
        $add($small, 'Tom Holloway', 'Spouse', 43, [['livescan', 400], ['caci', 400]]);
        $add($small, 'Jake Holloway', 'Adult child (19)', 19, [['livescan', 30]]);   // CACI still outstanding
        $add($large, 'Denise Reid', 'Spouse', 40, [['livescan', 1820], ['caci', 1820]]);
    }

    /** Fire + disaster drills every six months (101174(d) / 102417). This half: the center is done, Harbor View still owes its disaster drill. */
    private function drills(int $center, int $small, int $large, array $rooms, array $s): void
    {
        $tz = self::TZ;
        $mk = function (int $centre, ?int $room, string $type, Carbon $when, int $secs, int $kids, string $staffPresent, int $by, string $notes) {
            DB::table('drills')->insert(['agency_id' => $this->agencyId, 'centre_id' => $centre, 'room_id' => $room, 'type' => $type, 'drilled_at' => $when->copy()->utc()->toDateTimeString(),
                'evacuation_seconds' => $secs, 'children_count' => $kids, 'staff_present' => $staffPresent, 'notes' => $notes, 'recorded_by' => $by,
                'created_at' => $this->now, 'updated_at' => $this->now]);
        };
        $now = Carbon::now($tz);
        $thisHalf = $now->copy()->month($now->month <= 6 ? 1 : 7)->startOfMonth();
        $lastHalf = $thisHalf->copy()->subMonths(6);
        $at = function (Carbon $half, int $plusMonths, int $day, int $h) use ($now) {
            $t = $half->copy()->addMonths($plusMonths)->day($day)->setTime($h, 30);
            return $t->greaterThan($now) ? $now->copy()->subDays(2)->setTime($h, 30) : $t;
        };
        foreach ([$lastHalf, $thisHalf] as $k => $half) {
            $mk($center, null, 'fire', $at($half, 1, 9, 10), 150 - $k * 12, 52, 'Elena Brooks + 6 teachers', $s['director'], 'Demo: everyone out by the north gate');
            $mk($center, null, 'disaster', $at($half, 2, 14, 11), 75, 50, 'All staff', $s['director'], 'Demo: earthquake drill — drop, cover and hold on, then evacuate to the field');
            $mk($small, $rooms['home_s'], 'fire', $at($half, 1, 12, 9), 70, 6, 'Grace Holloway', $s['provider_s'], 'Demo');
            $mk($small, $rooms['home_s'], 'disaster', $at($half, 3, 10, 9), 60, 6, 'Grace Holloway', $s['provider_s'], 'Demo: earthquake drill');
            $mk($large, $rooms['home_l'], 'fire', $at($half, 2, 7, 10), 85, 9, 'Marcus Reid, Nina Patel', $s['provider_l'], 'Demo');
        }
        $mk($large, $rooms['home_l'], 'disaster', $at($lastHalf, 4, 18, 10), 70, 9, 'Marcus Reid, Nina Patel', $s['provider_l'], 'Demo: earthquake drill');
    }

    private function attendance(int $center, int $small, int $large, array $s): void
    {
        $punch = function (int $uid, int $centre, string $in) {
            DB::table('time_punches')->insert(['user_id' => $uid, 'centre_id' => $centre, 'punched_in_at' => $this->at($in), 'punched_out_at' => null,
                'notes' => 'Demo', 'source' => 'web', 'created_at' => $this->now]);
        };
        foreach ($s['educators'] as $uid) $punch($uid, $center, '07:30');
        $punch($s['director'], $center, '07:15');
        $punch($s['provider_s'], $small, '07:00');
        $punch($s['provider_l'], $large, '07:00');
        $punch($s['assistant_l'], $large, '08:00');
        $kids = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->leftJoin('guardians as g', function ($j) { $j->on('g.family_id', '=', 'f.id')->where('g.is_primary', 1); })
            ->where('ce.agency_id', $this->agencyId)->orderBy('c.id')->get(['c.id', 'c.primary_room_id', 'g.user_id as parent_id']);
        foreach ($kids as $i => $k) {
            if ($i % 9 === 4) continue;   // a few children not in today, so absences have something to show
            DB::table('check_events')->insert(['child_id' => $k->id, 'room_id' => $k->primary_room_id, 'event_type' => 'check_in',
                'occurred_at' => $this->at(sprintf('08:%02d', $i % 50)), 'by_user_id' => (int) ($k->parent_id ?: $s['director']), 'recorded_by_id' => $s['director'], 'notes' => 'Demo', 'is_automatic' => 0,
                'backdated' => 0, 'created_at' => $this->now]);
        }
    }

    /**
     * USD tuition per family: the last three months paid (card / ACH / check), except every 7th family, whose
     * current month is overdue; next month's invoice is issued 5 days ahead, so "due now" has something in it.
     */
    private function billing(array $s): void
    {
        $fams = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')->where('c.agency_id', $this->agencyId)
            ->leftJoin('guardians as g', function ($j) { $j->on('g.family_id', '=', 'f.id')->where('g.is_primary', 1); })
            ->orderBy('f.id')->get(['f.id', 'f.centre_id', 'f.family_name', 'g.id as guardian_id']);
        $tz = self::TZ;
        foreach ($fams as $n => $f) {
            if ($f->family_name === 'Holloway') continue;   // the provider's own child
            $fee = (float) DB::table('enrollments as e')->join('children as ch', 'ch.id', '=', 'e.child_id')->where('ch.family_id', $f->id)->sum('e.monthly_fee');
            if ($fee <= 0) continue;
            $today = Carbon::now($tz)->startOfDay();
            for ($k = 2; $k >= -1; $k--) {
                $start = Carbon::now($tz)->startOfMonth()->subMonths($k);
                $issued = $start->copy()->subDays(5);
                if ($issued->gt($today)) continue;                       // next month's bill is not out yet
                $due = $start->copy()->addDays(9);
                $paid = $k >= 0 && $due->lte($today) && ! ($k === 0 && $n % 7 === 3);
                $status = $paid ? 'paid' : ($due->lt($today) ? 'overdue' : 'sent');
                $id = DB::table('invoices')->insertGetId(['family_id' => $f->id, 'centre_id' => $f->centre_id,
                    'invoice_number' => 'BW-' . $start->format('Ym') . '-' . str_pad((string) $f->id, 4, '0', STR_PAD_LEFT),
                    'period_start' => $start->toDateString(), 'period_end' => $start->copy()->endOfMonth()->toDateString(),
                    'subtotal' => $fee, 'subsidy_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => $fee,
                    'amount_paid' => $paid ? $fee : 0, 'balance_due' => $paid ? 0 : $fee, 'issued_at' => $issued->toDateString(), 'issued_by_user_id' => $s['admin'],
                    'due_at' => $due->toDateString(), 'status' => $status, 'notes' => 'Monthly tuition (demo)', 'created_at' => $this->now, 'updated_at' => $this->now]);
                if ($paid) {
                    DB::table('payments')->insert(['invoice_id' => $id, 'family_id' => $f->id, 'payer_guardian_id' => $f->guardian_id, 'amount' => $fee,
                        'method' => ['stripe_card', 'stripe_ach', 'cheque'][$n % 3], 'status' => 'succeeded', 'reference_number' => 'DEMO-' . $id,
                        'paid_at' => $due->copy()->subDays(2)->setTime(10, 15)->utc()->toDateTimeString(), 'notes' => 'Demo payment', 'recorded_by_id' => $s['admin'],
                        'created_at' => $this->now, 'updated_at' => $this->now]);
                }
            }
        }
    }

    /** No AI for the demo agency (Platform → AI usage), so a prospect clicking around spends no credits. */
    private function aiOff(): void
    {
        $off = json_decode((string) \App\Support\PlatformSettings::get(\App\Support\AiConfig::OFF, '[]'), true) ?: [];
        $live = DB::table('agencies')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $off = array_values(array_unique(array_filter(array_merge(array_map('intval', $off), [$this->agencyId]), fn ($i) => in_array($i, $live, true))));
        \App\Support\PlatformSettings::set(\App\Support\AiConfig::OFF, json_encode($off));
    }

    /* ───────────── rebuild ───────────── */

    private function purge(int $agencyId): void
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first();
        if (! $a || $a->slug !== self::SLUG || in_array((int) $agencyId, [2, 6, 18], true)) {
            throw new \RuntimeException('Refusing to purge agency #' . $agencyId);
        }
        DB::transaction(function () use ($agencyId) {
            $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
            $rooms = DB::table('rooms')->whereIn('centre_id', $centres ?: [0])->pluck('id')->all();
            $families = DB::table('families')->whereIn('centre_id', $centres ?: [0])->pluck('id')->all();
            $children = DB::table('children')->whereIn('family_id', $families ?: [0])->pluck('id')->all();
            $users = DB::table('users')->where('email', 'like', '%@' . self::DOMAIN)->pluck('id')->all();
            $residents = DB::table('household_residents')->where('agency_id', $agencyId)->pluck('id')->all();
            $invoices = DB::table('invoices')->whereIn('family_id', $families ?: [0])->pluck('id')->all();
            DB::table('payments')->whereIn('invoice_id', $invoices ?: [0])->delete();
            DB::table('invoices')->whereIn('id', $invoices ?: [0])->delete();
            foreach (['daily_events', 'observations', 'check_events', 'immunizations', 'enrollments'] as $t) {
                try { DB::table($t)->whereIn('child_id', $children ?: [0])->delete(); } catch (\Throwable $e) { /* table shape differs: leave it */ }
            }
            // demo:seed-daily's incidents (by child) and announcements (by their demo author).
            try { DB::table('incidents')->whereIn('child_id', $children ?: [0])->delete(); } catch (\Throwable $e) {}
            DB::table('announcements')->whereIn('created_by_id', $users ?: [0])->delete();
            DB::table('children')->whereIn('id', $children ?: [0])->delete();
            DB::table('guardians')->whereIn('family_id', $families ?: [0])->delete();
            DB::table('families')->whereIn('id', $families ?: [0])->delete();
            DB::table('educator_rooms')->whereIn('room_id', $rooms ?: [0])->delete();
            DB::table('drills')->where('agency_id', $agencyId)->delete();
            DB::table('rooms')->whereIn('id', $rooms ?: [0])->delete();
            DB::table('household_resident_checks')->whereIn('resident_id', $residents ?: [0])->delete();
            DB::table('household_residents')->where('agency_id', $agencyId)->delete();
            DB::table('pd_hours')->where('agency_id', $agencyId)->delete();
            DB::table('background_checks')->where('agency_id', $agencyId)->delete();
            DB::table('staff_certifications')->whereIn('user_id', $users ?: [0])->delete();
            DB::table('time_punches')->whereIn('user_id', $users ?: [0])->delete();
            DB::table('role_assignments')->where('agency_id', $agencyId)->delete();
            DB::table('role_assignments')->whereIn('user_id', $users ?: [0])->delete();
            try { DB::table('user_profiles')->whereIn('user_id', $users ?: [0])->delete(); } catch (\Throwable $e) {}
            // Reference numbers (RefCode) handed out to this agency's people.
            try { DB::table('user_refs')->whereIn('user_id', $users ?: [0])->delete(); } catch (\Throwable $e) {}
            try { DB::table('agency_ref_counters')->where('agency_id', $agencyId)->delete(); } catch (\Throwable $e) {}
            DB::table('personal_access_tokens')->where('tokenable_type', 'like', '%User')->whereIn('tokenable_id', $users ?: [0])->delete();
            DB::table('users')->whereIn('id', $users ?: [0])->delete();
            DB::table('immunization_schedule')->where('agency_id', $agencyId)->delete();
            DB::table('centres')->whereIn('id', $centres ?: [0])->delete();
            DB::table('agencies')->where('id', $agencyId)->delete();
        });
    }
}
