<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * `php artisan tenant:check` — does any read endpoint hand one agency another's records?
 *
 * The standing rule has always ended with "probe it empirically with two agencies'
 * tokens — do not infer from the code that it is scoped". That was done by hand, once
 * per incident, which is why DigestStatusController sat unscoped through an audit that
 * hardened ~33 other controllers: nothing re-asked the question after the audit ended.
 *
 * This asks it on demand. It signs in as a real user from two agencies, calls every
 * parameter-free GET route with each, and looks in the response for names that exist in
 * the OTHER agency only. A hit is a row that crossed a tenant boundary.
 *
 *   php artisan tenant:check                 both agencies, quiet unless something leaks
 *   php artisan tenant:check --verbose       every endpoint and its status
 *   php artisan tenant:check --a=2 --b=6     choose the pair
 *
 * Exit code 1 on any leak, so it can gate a deploy.
 *
 * IT IS A READ-ONLY PROBE. GET routes only, tokens created and revoked in the same run,
 * named `tenant-check` so they are never confused with a person's session. It talks to
 * the app over HTTP on purpose: the thing being tested is what the API actually returns
 * to a bearer token, middleware and all, not what a query would have returned if called
 * directly. (2026-09-10)
 */
final class TenantIsolationCheck extends Command
{
    protected $signature = 'tenant:check
        {--a=2 : first agency id}
        {--b=6 : second agency id}
        {--base= : API base URL, default https://api.kiddietrac.com/api/v1}
        {--limit=0 : stop after N endpoints (0 = all)}';

    protected $description = 'Probe every read endpoint with two agencies\' tokens and report cross-tenant data';

    public function handle(): int
    {
        $a = (int) $this->option('a');
        $b = (int) $this->option('b');
        $base = rtrim((string) ($this->option('base') ?: 'https://api.kiddietrac.com/api/v1'), '/');

        $this->line("Tenant isolation probe — agency {$a} vs agency {$b}");

        $endpoints = $this->readEndpoints();
        if ($limit = (int) $this->option('limit')) {
            $endpoints = array_slice($endpoints, 0, $limit);
        }
        $this->line('  ' . count($endpoints) . ' parameter-free GET endpoints');

        $probes = [];
        foreach ([$a, $b] as $agency) {
            foreach (['admin', 'staff'] as $kind) {
                $user = $this->pickUser($agency, $kind);
                if (! $user) {
                    $this->warn("  no {$kind} user found for agency {$agency} — skipping that probe");
                    continue;
                }
                $probes[] = [
                    'label' => "agency {$agency} {$kind} (#{$user->id})",
                    'agency' => $agency,
                    'user' => $user,
                    'foreign' => $this->canaries($agency === $a ? $b : $a),
                ];
            }
        }
        if (! $probes) {
            $this->error('No probe users could be resolved — nothing was checked.');
            return self::FAILURE;
        }

        $leaks = [];
        foreach ($probes as $p) {
            /** @var \App\Models\User $u */
            $u = \App\Models\User::find($p['user']->id);
            $token = $u->createToken('tenant-check')->plainTextToken;

            $this->line("\n  {$p['label']} — must never see agency-"
                . ($p['agency'] === $a ? $b : $a) . ' data');

            foreach ($endpoints as $ep) {
                $body = $this->get($base . '/' . $ep, $token, $p['agency']);
                if ($body === null) {
                    continue;
                }
                $hits = $this->foreignHits($body, $p['foreign']);
                if ($hits) {
                    $leaks[] = ['probe' => $p['label'], 'endpoint' => $ep, 'hits' => $hits];
                    $this->error('    LEAK  ' . $ep . '  ->  ' . implode(', ', $hits));
                } elseif ($this->output->isVerbose()) {
                    $this->line('    ok    ' . $ep);
                }
            }

            DB::table('personal_access_tokens')
                ->where('tokenable_id', $u->id)->where('name', 'tenant-check')->delete();
        }

        $this->newLine();
        if ($leaks) {
            $this->error(count($leaks) . ' endpoint(s) returned another agency\'s data:');
            foreach ($leaks as $l) {
                $this->error("  {$l['probe']}  {$l['endpoint']}  ->  " . implode(', ', $l['hits']));
            }
            $this->newLine();
            $this->error('Scope the query by the ACTIVE agency and fail closed. See the standing rule.');

            return self::FAILURE;
        }

        $this->info('No cross-tenant data in any probed endpoint.');

        return self::SUCCESS;
    }

    /** Every GET route with no path parameter — the ones a probe can call blind. */
    private function readEndpoints(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (! in_array('GET', $r->methods(), true)) {
                continue;
            }
            $uri = $r->uri();
            if (! str_starts_with($uri, 'api/v1/') || str_contains($uri, '{')) {
                continue;
            }
            $out[] = substr($uri, strlen('api/v1/'));
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * Names that exist in ONE agency only, so a sighting is unambiguous.
     *
     * Children, families and centres — the three things whose appearance in another
     * tenant's response is a privacy breach rather than a display bug. Short or common
     * names are dropped: matching "Mia" inside an unrelated word would cry wolf, and a
     * check that cries wolf gets switched off.
     */
    private function canaries(int $agencyId): array
    {
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)
            ->whereNull('deleted_at')->pluck('id');

        $names = collect();
        $names = $names->merge(
            DB::table('centres')->whereIn('id', $centreIds)->pluck('name')
        );
        $names = $names->merge(
            DB::table('families')->whereIn('centre_id', $centreIds)
                ->whereNull('deleted_at')->limit(40)->pluck('family_name')
        );
        $names = $names->merge(
            DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
                ->whereIn('f.centre_id', $centreIds)->whereNull('ch.deleted_at')->limit(40)
                ->get(['ch.first_name', 'ch.last_name'])
                ->map(fn ($c) => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')))
        );

        // Only names distinctive enough to mean something, and not shared with the
        // other agency — a person really can appear in two agencies.
        $otherCentreIds = DB::table('centres')->where('agency_id', '!=', $agencyId)->pluck('id');
        $elsewhere = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', $otherCentreIds)
            ->get(['ch.first_name', 'ch.last_name'])
            ->map(fn ($c) => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')))
            ->merge(DB::table('centres')->whereIn('id', $otherCentreIds)->pluck('name'))
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->filter()->unique()->all();

        return $names
            ->map(fn ($n) => trim((string) $n))
            ->filter(fn ($n) => mb_strlen($n) >= 8 && str_contains($n, ' '))
            ->reject(fn ($n) => in_array(mb_strtolower($n), $elsewhere, true))
            ->unique()->values()->all();
    }

    /** @return string[] the foreign names actually present in the body */
    private function foreignHits(string $body, array $canaries): array
    {
        $hits = [];
        foreach ($canaries as $c) {
            if (stripos($body, $c) !== false) {
                $hits[] = $c;
                if (count($hits) >= 3) {
                    break;
                }
            }
        }

        return $hits;
    }

    private function pickUser(int $agencyId, string $kind): ?object
    {
        $roles = $kind === 'admin'
            ? ['agency_admin', 'centre_director']
            : ['educator', 'home_visitor'];

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');

        return DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereIn('ra.role', $roles)->where('ra.active', 1)
            ->where(fn ($q) => $q->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centreIds))
            ->whereNull('u.deleted_at')->where('u.status', 'active')
            /* Never a platform admin: they pass every role check by design, so they
               would report a leak on every endpoint and prove nothing. */
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('role_assignments as pa')
                ->whereColumn('pa.user_id', 'u.id')->where('pa.role', 'platform_admin')->where('pa.active', 1))
            ->first(['u.id']);
    }

    private function get(string $url, string $token, int $agencyId): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'X-Active-Agency-Id: ' . $agencyId,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Only a 200 can leak. A refusal is the guard working.
        return ($code === 200 && is_string($body)) ? $body : null;
    }
}
