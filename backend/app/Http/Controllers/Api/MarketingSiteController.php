<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Marketing-website control center, managed by platform_admin from the dashboard.
 *  - Settings (announcement, GA4, SEO, contact, social, hero stats) → storage/app/private/marketing-site.json
 *  - Lead/subscriber capture + admin add/delete                     → storage/app/private/marketing-leads.jsonl
 *  - First-party pageview analytics                                 → storage/app/private/marketing-analytics.json
 * Public endpoints are CORS-open and expose no secrets; admin endpoints are role:platform_admin.
 */
final class MarketingSiteController extends Controller
{
    private function defaults(): array
    {
        return [
            'ga_id'              => '',
            'announce_enabled'   => true,
            'announce_tag'       => 'NEW · 2026',
            'announce_text'      => 'AI Daily Recaps, QuickBooks sync & multi-currency billing are now live.',
            'announce_cta_text'  => 'Start your free 14-day trial →',
            'announce_cta_page'  => 'contact',
            'seo_title'          => '',
            'seo_description'    => '',
            'og_image'           => '',
            'contact_email'      => '',
            'contact_phone'      => '',
            'contact_address'    => '',
            'social_facebook'    => '',
            'social_instagram'   => '',
            'social_linkedin'    => '',
            'social_x'           => '',
            'hero_stat1_num'     => '',
            'hero_stat1_lbl'     => '',
            'hero_stat2_num'     => '',
            'hero_stat2_lbl'     => '',
            'hero_stat3_num'     => '',
            'hero_stat3_lbl'     => '',
            'recaptcha_enabled'  => false,
            'recaptcha_site_key' => '',
            'recaptcha_secret'   => '',
        ];
    }

    private function load(): array
    {
        try {
            if (Storage::disk('local')->exists('marketing-site.json')) {
                $j = json_decode((string) Storage::disk('local')->get('marketing-site.json'), true);
                if (is_array($j)) {
                    return array_merge($this->defaults(), $j);
                }
            }
        } catch (\Throwable $e) {
        }
        return $this->defaults();
    }

    /** PUBLIC — consumed cross-origin by the marketing site. No auth, no secrets. */
    public function publicConfig(): JsonResponse
    {
        $c = $this->load();
        return response()->json([
            'ga_id'    => (string) ($c['ga_id'] ?? ''),
            'announce' => [
                'enabled'  => (bool) ($c['announce_enabled'] ?? false),
                'tag'      => (string) ($c['announce_tag'] ?? ''),
                'text'     => (string) ($c['announce_text'] ?? ''),
                'cta_text' => (string) ($c['announce_cta_text'] ?? ''),
                'cta_page' => (string) ($c['announce_cta_page'] ?? 'contact'),
            ],
            'seo' => [
                'title'       => (string) ($c['seo_title'] ?? ''),
                'description' => (string) ($c['seo_description'] ?? ''),
                'og_image'    => (string) ($c['og_image'] ?? ''),
            ],
            'contact' => [
                'email'   => (string) ($c['contact_email'] ?? ''),
                'phone'   => (string) ($c['contact_phone'] ?? ''),
                'address' => (string) ($c['contact_address'] ?? ''),
            ],
            'social' => [
                'facebook'  => (string) ($c['social_facebook'] ?? ''),
                'instagram' => (string) ($c['social_instagram'] ?? ''),
                'linkedin'  => (string) ($c['social_linkedin'] ?? ''),
                'x'         => (string) ($c['social_x'] ?? ''),
            ],
            'hero_stats' => [
                ['num' => (string) ($c['hero_stat1_num'] ?? ''), 'lbl' => (string) ($c['hero_stat1_lbl'] ?? '')],
                ['num' => (string) ($c['hero_stat2_num'] ?? ''), 'lbl' => (string) ($c['hero_stat2_lbl'] ?? '')],
                ['num' => (string) ($c['hero_stat3_num'] ?? ''), 'lbl' => (string) ($c['hero_stat3_lbl'] ?? '')],
            ],
            // reCAPTCHA: only the public site key + enabled flag are exposed (never the secret).
            'recaptcha' => [
                'enabled'  => (bool) ($c['recaptcha_enabled'] ?? false) && (string) ($c['recaptcha_site_key'] ?? '') !== '',
                'site_key' => (string) ($c['recaptcha_site_key'] ?? ''),
            ],
        ])->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'public, max-age=60');
    }

    /** platform_admin — full settings for the dashboard editor. */
    public function get(): JsonResponse
    {
        return response()->json($this->load());
    }

    /** platform_admin — save settings from the dashboard. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ga_id'             => 'nullable|string|max:40',
            'announce_enabled'  => 'nullable|boolean',
            'announce_tag'      => 'nullable|string|max:40',
            'announce_text'     => 'nullable|string|max:240',
            'announce_cta_text' => 'nullable|string|max:80',
            'announce_cta_page' => 'nullable|string|max:40',
            'seo_title'         => 'nullable|string|max:120',
            'seo_description'   => 'nullable|string|max:240',
            'og_image'          => 'nullable|string|max:300',
            'contact_email'     => 'nullable|string|max:160',
            'contact_phone'     => 'nullable|string|max:60',
            'contact_address'   => 'nullable|string|max:200',
            'social_facebook'   => 'nullable|string|max:200',
            'social_instagram'  => 'nullable|string|max:200',
            'social_linkedin'   => 'nullable|string|max:200',
            'social_x'          => 'nullable|string|max:200',
            'hero_stat1_num'    => 'nullable|string|max:20',
            'hero_stat1_lbl'    => 'nullable|string|max:40',
            'hero_stat2_num'    => 'nullable|string|max:20',
            'hero_stat2_lbl'    => 'nullable|string|max:40',
            'hero_stat3_num'    => 'nullable|string|max:20',
            'hero_stat3_lbl'    => 'nullable|string|max:40',
            'recaptcha_enabled' => 'nullable|boolean',
            'recaptcha_site_key' => 'nullable|string|max:120',
            'recaptcha_secret'  => 'nullable|string|max:120',
        ]);
        $merged = array_merge($this->load(), $data);
        Storage::disk('local')->put('marketing-site.json', json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return response()->json(['ok' => true, 'config' => $merged]);
    }

    /** PUBLIC — lead capture from the marketing site (lead magnet / newsletter). */
    public function submitLead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'  => 'required|email|max:160',
            'name'   => 'nullable|string|max:120',
            'agency' => 'nullable|string|max:160',
            'source' => 'nullable|string|max:60',
            'recaptcha_token' => 'nullable|string|max:4000',
        ]);
        $cfg = $this->load();
        if (!empty($cfg['recaptcha_enabled']) && !empty($cfg['recaptcha_secret'])) {
            if (!$this->verifyRecaptcha((string) ($data['recaptcha_token'] ?? ''), (string) $cfg['recaptcha_secret'], (string) $request->ip())) {
                return response()->json(['ok' => false, 'error' => 'recaptcha_failed'], 422)->header('Access-Control-Allow-Origin', '*');
            }
        }
        $email = strtolower(trim($data['email']));
        $name  = trim((string) ($data['name'] ?? ''));
        $agency = trim((string) ($data['agency'] ?? ''));
        $source = trim((string) ($data['source'] ?? 'marketing-site'));
        $this->appendLead([
            'email'  => $email,
            'name'   => $name,
            'agency' => $agency,
            'source' => $source,
            'ip'     => substr((string) $request->ip(), 0, 45),
        ]);

        // And as a subscriber, so the list can be managed and a date exists to show.
        // Someone re-submitting the form after unsubscribing is opting back in.
        try {
            DB::table('site_subscribers')->updateOrInsert(
                ['email' => $email],
                [
                    'name' => $name ?: null,
                    'agency_name' => $agency ?: null,
                    'source' => $source ?: 'marketing-site',
                    'ip' => substr((string) $request->ip(), 0, 45),
                    'subscribed_at' => now(),
                    'unsubscribed_at' => null,
                    'unsubscribed_by' => null,
                    'unsubscribe_note' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            self::unsuppressEmail($email);
        } catch (\Throwable $e) {
            // A signup must not fail because the subscriber list did.
            \Illuminate\Support\Facades\Log::warning('Subscriber upsert failed', ['email' => $email, 'error' => $e->getMessage()]);
        }
        // Capture into the sales CRM pipeline (dedupe by email while open). Guarded.
        try {
            if (! \App\Models\SalesLead::where('email', $email)->where('status', 'open')->exists()) {
                $lead = \App\Models\SalesLead::create([
                    'name' => $name ?: $email, 'company' => $agency ?: null, 'email' => $email,
                    'source' => 'marketing-site', 'stage' => 'new', 'status' => 'open', 'last_activity_at' => now(),
                    'notes' => 'Auto-captured from the marketing site' . ($source ? " (source: {$source})" : '') . '.',
                ]);
                \App\Models\SalesActivity::create(['lead_id' => $lead->id, 'type' => 'stage', 'body' => 'Captured from marketing site', 'done' => true]);
            }
        } catch (\Throwable $e) {}
        // v22p96: route every marketing-site contact/lead submission to the sales
        // inbox so the team can follow up. Reply-To is the prospect so a reply goes
        // straight back to them. Wrapped in try/catch — a mail hiccup must never
        // fail the public form submission.
        try {
            Mail::raw(
                "New enquiry from the KiddieTrac marketing site:\n\n"
                . "Name:   " . ($name ?: '(not provided)') . "\n"
                . "Email:  {$email}\n"
                . "Agency: " . ($agency ?: '(not provided)') . "\n"
                . "Source: {$source}\n"
                . "IP:     " . substr((string) $request->ip(), 0, 45) . "\n",
                function ($m) use ($email, $name) {
                    $m->to('sales@kiddietrac.com')
                      ->subject('New website enquiry: ' . ($name ?: $email))
                      ->replyTo($email, $name ?: $email);
                }
            );
        } catch (\Throwable $e) {
            // swallow — never block the submission on a sales-notify failure
        }
        // v22p47: subscribing (again) clears any prior unsubscribe, then sends a
        // branded confirmation email with unsubscribe + privacy + terms links.
        $this->removeSuppression($email);
        try {
            Mail::to($email)->send(new \App\Mail\SubscriberWelcome(
                $name ?: 'there',
                $this->subUnsubscribeUrl($email),
                'https://www.kiddietrac.com/privacy',
                'https://www.kiddietrac.com/terms'
            ));
        } catch (\Throwable $e) {
            // never fail the subscribe because email delivery hiccuped
        }
        return response()->json(['ok' => true])->header('Access-Control-Allow-Origin', '*');
    }

    /** platform_admin — manually add a subscriber/lead from the dashboard. */
    public function addLead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'  => 'required|email|max:160',
            'name'   => 'nullable|string|max:120',
            'agency' => 'nullable|string|max:160',
            'source' => 'nullable|string|max:60',
        ]);
        $row = $this->appendLead([
            'email'  => strtolower(trim($data['email'])),
            'name'   => trim((string) ($data['name'] ?? '')),
            'agency' => trim((string) ($data['agency'] ?? '')),
            'source' => trim((string) ($data['source'] ?? 'manual')),
            'ip'     => 'admin',
        ]);
        return response()->json(['ok' => true, 'lead' => $row]);
    }

    /** platform_admin — delete a subscriber/lead (matched by email + capture timestamp). */
    public function deleteLead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|string|max:160',
            'at'    => 'required|string|max:40',
        ]);
        $email = strtolower(trim($data['email']));
        $at    = trim($data['at']);
        $removed = 0;
        try {
            if (Storage::disk('local')->exists('marketing-leads.jsonl')) {
                $lines = array_filter(preg_split('/\r?\n/', trim((string) Storage::disk('local')->get('marketing-leads.jsonl'))));
                $keep = [];
                foreach ($lines as $ln) {
                    $r = json_decode($ln, true);
                    if (is_array($r) && strtolower((string) ($r['email'] ?? '')) === $email && (string) ($r['at'] ?? '') === $at) {
                        $removed++;
                        continue;
                    }
                    $keep[] = $ln;
                }
                Storage::disk('local')->put('marketing-leads.jsonl', $keep ? implode("\n", $keep) . "\n" : '');
            }
        } catch (\Throwable $e) {
        }
        return response()->json(['ok' => true, 'removed' => $removed]);
    }

    /** platform_admin — edit a subscriber/lead (identity = original email + at). */
    public function updateLead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'     => 'required|string|max:160',
            'at'        => 'required|string|max:40',
            'new_email' => 'nullable|email|max:160',
            'name'      => 'nullable|string|max:120',
            'agency'    => 'nullable|string|max:160',
        ]);
        $email = strtolower(trim($data['email']));
        $at    = trim($data['at']);
        $updated = 0;
        try {
            if (Storage::disk('local')->exists('marketing-leads.jsonl')) {
                $lines = array_filter(preg_split('/\r?\n/', trim((string) Storage::disk('local')->get('marketing-leads.jsonl'))));
                $out = [];
                foreach ($lines as $ln) {
                    $r = json_decode($ln, true);
                    if (is_array($r) && strtolower((string) ($r['email'] ?? '')) === $email && (string) ($r['at'] ?? '') === $at) {
                        if (!empty($data['new_email'])) $r['email'] = strtolower(trim($data['new_email']));
                        if (array_key_exists('name', $data))   $r['name']   = trim((string) ($data['name'] ?? ''));
                        if (array_key_exists('agency', $data)) $r['agency'] = trim((string) ($data['agency'] ?? ''));
                        $updated++;
                        $out[] = json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    } else {
                        $out[] = $ln;
                    }
                }
                Storage::disk('local')->put('marketing-leads.jsonl', $out ? implode("\n", $out) . "\n" : '');
            }
        } catch (\Throwable $e) {
        }
        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    private function verifyRecaptcha(string $token, string $secret, string $ip): bool
    {
        if ($token === '') return false;
        try {
            $resp = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => 'Content-type: application/x-www-form-urlencoded',
                    'content' => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]),
                    'timeout' => 5,
                ],
            ]));
            if ($resp === false) return true; // fail-open if Google is unreachable — don't block real users
            $j = json_decode($resp, true);
            return is_array($j) && !empty($j['success']);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** Compact timezone → ISO country map (dependency-free visitor geo). */
    private function tzToCountry(string $tz): string
    {
        static $map = [
            'America/Toronto' => 'CA', 'America/Vancouver' => 'CA', 'America/Edmonton' => 'CA', 'America/Winnipeg' => 'CA',
            'America/Halifax' => 'CA', 'America/St_Johns' => 'CA', 'America/Regina' => 'CA', 'America/Moncton' => 'CA',
            'America/New_York' => 'US', 'America/Chicago' => 'US', 'America/Denver' => 'US', 'America/Los_Angeles' => 'US',
            'America/Phoenix' => 'US', 'America/Anchorage' => 'US', 'America/Detroit' => 'US', 'Pacific/Honolulu' => 'US',
            'Europe/London' => 'GB', 'Europe/Dublin' => 'IE', 'Europe/Paris' => 'FR', 'Europe/Berlin' => 'DE',
            'Europe/Madrid' => 'ES', 'Europe/Rome' => 'IT', 'Europe/Amsterdam' => 'NL', 'Europe/Stockholm' => 'SE',
            'Australia/Sydney' => 'AU', 'Australia/Melbourne' => 'AU', 'Australia/Perth' => 'AU', 'Pacific/Auckland' => 'NZ',
            'Asia/Kolkata' => 'IN', 'Asia/Dubai' => 'AE', 'Asia/Singapore' => 'SG', 'Asia/Tokyo' => 'JP',
            'Asia/Manila' => 'PH', 'America/Mexico_City' => 'MX', 'America/Sao_Paulo' => 'BR',
        ];
        return $map[$tz] ?? 'Other';
    }

    private function appendLead(array $row): array
    {
        $row['at'] = now()->toIso8601String();
        try {
            Storage::disk('local')->append(
                'marketing-leads.jsonl',
                json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
        }
        return $row;
    }

    // ─── Subscriber unsubscribe + suppression list (v22p47) ───────────────

    /** Signed, stateless unsubscribe URL (HMAC of the email with the app key). */
    private function subUnsubscribeUrl(string $email): string
    {
        $e = strtolower(trim($email));
        $sig = hash_hmac('sha256', $e, (string) config('app.key'));
        return rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/')
            . '/api/v1/marketing-site/unsubscribe?e=' . urlencode($e) . '&t=' . $sig;
    }

    /** Public landing page hit from the unsubscribe link in the email. */
    public function unsubscribe(Request $request)
    {
        $email = strtolower(trim((string) $request->query('e', '')));
        $sig   = (string) $request->query('t', '');
        $expected = hash_hmac('sha256', $email, (string) config('app.key'));
        $valid = $email !== '' && hash_equals($expected, $sig);
        if ($valid) {
            $this->addSuppression($email);

            // Stamped, so "when did they unsubscribe" has an answer. A person who was
            // never on the list still gets a row: the request itself is the record.
            try {
                $existing = DB::table('site_subscribers')->where('email', $email)->first();
                if ($existing) {
                    if (! $existing->unsubscribed_at) {
                        DB::table('site_subscribers')->where('id', $existing->id)->update([
                            'unsubscribed_at' => now(), 'unsubscribed_by' => 'self', 'updated_at' => now(),
                        ]);
                    }
                } else {
                    DB::table('site_subscribers')->insert([
                        'email' => $email, 'source' => 'unsubscribe-link',
                        'unsubscribed_at' => now(), 'unsubscribed_by' => 'self',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Unsubscribe stamp failed', ['email' => $email, 'error' => $e->getMessage()]);
            }

            // A receipt for what they just did. Guarded: a failed confirmation must never
            // undo the unsubscribe, which is the part that actually matters.
            try {
                Mail::to($email)->send(new \App\Mail\SubscriberUnsubscribed($email, '', 'self'));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Unsubscribe confirmation failed', ['email' => $email, 'error' => $e->getMessage()]);
            }
        }
        $inner = $valid
            ? '<div class="ico">✓</div><h1>You&rsquo;re unsubscribed</h1><p><strong>' . e($email) . '</strong> will no longer receive KiddieTrac marketing emails. You can re-subscribe any time at kiddietrac.com.</p>'
            : '<div class="ico" style="color:#dc2626">!</div><h1>Invalid link</h1><p>This unsubscribe link is invalid or has expired. Email <a href="mailto:privacy@kiddietrac.com">privacy@kiddietrac.com</a> and we&rsquo;ll remove you right away.</p>';
        $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Unsubscribe · KiddieTrac</title>'
            . '<style>body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f1f5f9;color:#0f172a;display:flex;min-height:100vh;align-items:center;justify-content:center}'
            . '.card{background:#fff;max-width:460px;margin:20px;padding:40px 34px;border-radius:18px;box-shadow:0 10px 40px rgba(15,23,42,.12);text-align:center}'
            . '.ico{width:64px;height:64px;line-height:64px;margin:0 auto 14px;border-radius:50%;background:#e6f6f3;color:#16a34a;font-size:32px;font-weight:800}'
            . 'h1{font-size:22px;margin:6px 0 10px}p{color:#475569;font-size:15px;line-height:1.65}a{color:#2EA9AC}'
            . '.brand{margin-top:24px;font-size:13px;color:#94a3b8}.brand a{font-weight:700;text-decoration:none}</style></head>'
            . '<body><div class="card">' . $inner . '<div class="brand"><a href="https://www.kiddietrac.com">KiddieTrac</a> · Childcare management, simplified</div></div></body></html>';
        return response($page, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function loadSuppressions(): array
    {
        try {
            if (Storage::disk('local')->exists('marketing-unsubscribes.json')) {
                $arr = json_decode((string) Storage::disk('local')->get('marketing-unsubscribes.json'), true);
                return is_array($arr) ? $arr : [];
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    private function saveSuppressions(array $list): void
    {
        try {
            Storage::disk('local')->put(
                'marketing-unsubscribes.json',
                json_encode(array_values(array_unique($list)), JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable $e) {
        }
    }

    /** Add to the file list the mail layer reads. Public so the subscriber screen agrees with it. */
    public static function suppressEmail(string $email): void
    {
        $c = new self();
        $c->addSuppression(strtolower(trim($email)));
    }

    /** Remove from that list, for a genuine re-subscribe. */
    public static function unsuppressEmail(string $email): void
    {
        $email = strtolower(trim($email));
        $c = new self();
        $list = $c->loadSuppressions();
        $next = array_values(array_filter($list, fn ($e) => strtolower(trim((string) $e)) !== $email));
        if (count($next) !== count($list)) {
            $c->saveSuppressions($next);
        }
    }

    private function addSuppression(string $email): void
    {
        $email = strtolower(trim($email));
        $list = $this->loadSuppressions();
        if (!in_array($email, $list, true)) {
            $list[] = $email;
            $this->saveSuppressions($list);
        }
    }

    private function removeSuppression(string $email): void
    {
        $email = strtolower(trim($email));
        $list = array_values(array_filter($this->loadSuppressions(), fn ($x) => $x !== $email));
        $this->saveSuppressions($list);
    }

    public function isSuppressed(string $email): bool
    {
        return in_array(strtolower(trim($email)), $this->loadSuppressions(), true);
    }

    /** platform_admin — captured leads/subscribers for the dashboard (most recent first). */
    public function leads(): JsonResponse
    {
        $out = [];
        try {
            if (Storage::disk('local')->exists('marketing-leads.jsonl')) {
                $lines = array_filter(preg_split('/\r?\n/', trim((string) Storage::disk('local')->get('marketing-leads.jsonl'))));
                foreach (array_slice($lines, -2000) as $ln) {
                    $r = json_decode($ln, true);
                    if (is_array($r)) {
                        $out[] = $r;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return response()->json(['count' => count($out), 'leads' => array_reverse($out)]);
    }

    /** PUBLIC — first-party pageview beacon from the marketing site (no PII, no cookies). */
    public function recordHit(Request $request): JsonResponse
    {
        $path = (string) $request->input('path', '/');
        $path = preg_replace('#[^a-zA-Z0-9/_\-]#', '', $path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        if (strlen($path) > 60) {
            $path = substr($path, 0, 60);
        }
        $day = now()->toDateString();

        try {
            $a = [];
            if (Storage::disk('local')->exists('marketing-analytics.json')) {
                $a = json_decode((string) Storage::disk('local')->get('marketing-analytics.json'), true) ?: [];
            }
            if (!isset($a['days']) || !is_array($a['days'])) {
                $a['days'] = [];
            }
            if (!isset($a['days'][$day])) {
                $a['days'][$day] = ['views' => 0, 'paths' => []];
            }
            $a['days'][$day]['views']++;
            $a['days'][$day]['paths'][$path] = ($a['days'][$day]['paths'][$path] ?? 0) + 1;
            $a['total'] = ($a['total'] ?? 0) + 1;
            // Visitor country, derived from the timezone the client reports (no PII).
            $tz = substr(preg_replace('#[^a-zA-Z0-9/_+\-]#', '', (string) $request->input('tz', '')), 0, 40);
            $country = $tz !== '' ? $this->tzToCountry($tz) : 'Unknown';
            if (!isset($a['countries']) || !is_array($a['countries'])) $a['countries'] = [];
            $a['countries'][$country] = ($a['countries'][$country] ?? 0) + 1;
            if (count($a['days']) > 120) {
                ksort($a['days']);
                $a['days'] = array_slice($a['days'], -120, null, true);
            }
            Storage::disk('local')->put('marketing-analytics.json', json_encode($a, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true])->header('Access-Control-Allow-Origin', '*');
    }

    /** platform_admin — analytics summary (first-party views + lead conversion). */
    public function analytics(): JsonResponse
    {
        $a = ['days' => [], 'total' => 0];
        try {
            if (Storage::disk('local')->exists('marketing-analytics.json')) {
                $a = json_decode((string) Storage::disk('local')->get('marketing-analytics.json'), true) ?: $a;
            }
        } catch (\Throwable $e) {
        }
        $days = $a['days'] ?? [];

        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $series[] = ['date' => $d, 'views' => (int) ($days[$d]['views'] ?? 0)];
        }

        $paths = [];
        foreach ($days as $d) {
            foreach (($d['paths'] ?? []) as $pth => $n) {
                $paths[$pth] = ($paths[$pth] ?? 0) + (int) $n;
            }
        }
        arsort($paths);
        $top = [];
        foreach (array_slice($paths, 0, 12, true) as $pth => $n) {
            $top[] = ['path' => $pth, 'views' => $n];
        }

        $totalLeads = 0;
        $sources = [];
        try {
            if (Storage::disk('local')->exists('marketing-leads.jsonl')) {
                $lines = array_filter(preg_split('/\r?\n/', trim((string) Storage::disk('local')->get('marketing-leads.jsonl'))));
                foreach ($lines as $ln) {
                    $r = json_decode($ln, true);
                    if (is_array($r)) {
                        $totalLeads++;
                        $s = $r['source'] ?? 'unknown';
                        $sources[$s] = ($sources[$s] ?? 0) + 1;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $countries = $a['countries'] ?? [];
        arsort($countries);
        $topCountries = [];
        foreach (array_slice($countries, 0, 12, true) as $cc => $n) {
            $topCountries[] = ['country' => $cc, 'views' => (int) $n];
        }

        $views14 = array_sum(array_column($series, 'views'));
        return response()->json([
            'total_views'  => (int) ($a['total'] ?? 0),
            'views_14d'    => $views14,
            'series_14d'   => $series,
            'top_paths'    => $top,
            'top_countries' => $topCountries,
            'total_leads'  => $totalLeads,
            'lead_sources' => $sources,
            'conversion'   => $views14 > 0 ? round(($totalLeads / max($views14, 1)) * 100, 2) : 0,
        ]);
    }

    /** PUBLIC — log a marketing-site chat message (visitor or bot reply). */
    public function logChat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session' => 'required|string|max:60',
            'name'    => 'nullable|string|max:120',
            'email'   => 'nullable|string|max:160',
            'sender'  => 'required|string|in:visitor,bot',
            'message' => 'required|string|max:2000',
        ]);
        $row = [
            'session' => preg_replace('/[^a-zA-Z0-9_-]/', '', $data['session']),
            'name'    => trim((string) ($data['name'] ?? '')),
            'email'   => strtolower(trim((string) ($data['email'] ?? ''))),
            'sender'  => $data['sender'],
            'message' => trim($data['message']),
            'ip'      => substr((string) $request->ip(), 0, 45),
            'at'      => now()->toIso8601String(),
        ];
        try {
            Storage::disk('local')->append('marketing-chats.jsonl', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
        }
        return response()->json(['ok' => true])->header('Access-Control-Allow-Origin', '*');
    }

    /** platform_admin — chat conversations grouped by session, most recent first. */
    public function chats(): JsonResponse
    {
        $sessions = [];
        try {
            if (Storage::disk('local')->exists('marketing-chats.jsonl')) {
                $lines = array_filter(preg_split('/\r?\n/', trim((string) Storage::disk('local')->get('marketing-chats.jsonl'))));
                foreach (array_slice($lines, -5000) as $ln) {
                    $r = json_decode($ln, true);
                    if (!is_array($r)) continue;
                    $sid = (string) ($r['session'] ?? 'unknown');
                    if (!isset($sessions[$sid])) {
                        $sessions[$sid] = ['session' => $sid, 'name' => '', 'email' => '', 'started' => $r['at'] ?? '', 'last' => $r['at'] ?? '', 'count' => 0, 'messages' => []];
                    }
                    if (!empty($r['name']))  $sessions[$sid]['name']  = $r['name'];
                    if (!empty($r['email'])) $sessions[$sid]['email'] = $r['email'];
                    $sessions[$sid]['last'] = $r['at'] ?? $sessions[$sid]['last'];
                    $sessions[$sid]['count']++;
                    $sessions[$sid]['messages'][] = ['sender' => $r['sender'] ?? '', 'message' => $r['message'] ?? '', 'at' => $r['at'] ?? ''];
                }
            }
        } catch (\Throwable $e) {
        }
        $out = array_values($sessions);
        usort($out, function ($a, $b) { return strcmp((string) ($b['last'] ?? ''), (string) ($a['last'] ?? '')); });
        return response()->json(['count' => count($out), 'sessions' => array_slice($out, 0, 200)]);
    }
}
