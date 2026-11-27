<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * HOW A CENTRE IS NAMED TO A FAMILY (2026-11-27, Anthony: "the we missed (child) email with the safe arrival, one of
 * the events lists 'the Amna Ahsan Team' was alerted, we need to change that to reflect the agency name vs the
 * educator name - full sweep").
 *
 * A home child care agency (iLearn) names every centre after its provider: centre 14 is "Amna Ahsan". Put in front of
 * a family, that turns an educator's full name into an organisation ("The Amna Ahsan team", "a call from Amna Ahsan").
 * This is the ONE rule for family-facing text (email, text, push, voice, public pages):
 *
 *   org($c)    who is speaking / who did it. Provider-named home -> the agency ("iLearn Home Childcare").
 *              A real centre keeps its own name ("Sunny Meadows Childcare").
 *   team($c)   "the iLearn Home Childcare team" / "the Sunny Meadows Childcare team" (callers ucfirst at a sentence start).
 *   place($c)  where the child is. Provider-named home -> "Amna's home child care" (first name only, never the last).
 *              A real centre keeps its own name.
 *   agency($a) the agency's name without Inc / Ltd / LLC / Corp, for sign-offs ("iLearn Home Childcare").
 *
 * A centre is provider-named when its name IS a person's name: the centre's supervisor (first + last), or an active
 * staff member assigned to that centre. A home with a business name ("Sunridge Family Child Care") keeps its name.
 * Staff-facing text keeps centre names: staff know the homes.
 */
final class FamilyFacingName
{
    private const STAFF_ROLES = ['educator', 'centre_director', 'home_visitor'];

    /** @var array<int, array{name:string, agency_id:int, provider:bool, first:string}> */
    private static array $centres = [];

    /** @var array<int, string> */
    private static array $agencies = [];

    /** The queue worker lives for hours: what was read is kept for 5 minutes, so a renamed centre shows up. */
    private static int $readAt = 0;

    private static function fresh(): void
    {
        if (time() - self::$readAt > 300) {
            self::$centres = [];
            self::$agencies = [];
            self::$readAt = time();
        }
    }

    /** The agency's name for families: "iLearn Home Childcare Inc" -> "iLearn Home Childcare". */
    public static function agency(?int $agencyId): string
    {
        if (! $agencyId) {
            return '';
        }
        self::fresh();
        if (! isset(self::$agencies[$agencyId])) {
            self::$agencies[$agencyId] = self::clean((string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?? ''));
        }

        return self::$agencies[$agencyId];
    }

    /** "Acme Child Care Ltd." -> "Acme Child Care". */
    public static function clean(string $agencyName): string
    {
        return trim((string) preg_replace('/[\s,]+(inc|ltd|llc|corp|corporation|limited|incorporated)\.?$/i', '', trim($agencyName)));
    }

    /** True when the centre is named after a person (its provider), so the name must not stand for an organisation. */
    public static function isProviderNamed(?int $centreId): bool
    {
        $c = self::centre($centreId);

        return $c !== null && $c['provider'];
    }

    /** Who is speaking: the agency for a provider-named home, else the centre. Falls back to the agency, then $fallback. */
    public static function org(?int $centreId, ?int $agencyId = null, string $fallback = 'your child care centre'): string
    {
        $c = self::centre($centreId);
        $agency = self::agency($agencyId ?: ($c['agency_id'] ?? null));
        if ($c === null || $c['name'] === '') {
            return $agency !== '' ? $agency : $fallback;
        }
        if ($c['provider']) {
            return $agency !== '' ? $agency : ($c['first'] !== '' ? $c['first'] . "'s home child care" : $fallback);
        }

        return $c['name'];
    }

    /** "the iLearn Home Childcare team". */
    public static function team(?int $centreId, ?int $agencyId = null): string
    {
        $o = self::org($centreId, $agencyId, '');

        return $o !== '' ? 'the ' . $o . ' team' : 'the team';
    }

    /** Where the child is: "Amna's home child care" for a provider-named home, else the centre's own name. */
    public static function place(?int $centreId, string $fallback = 'the centre'): string
    {
        $c = self::centre($centreId);
        if ($c === null || $c['name'] === '') {
            return $fallback;
        }
        if ($c['provider']) {
            return $c['first'] !== '' ? $c['first'] . "'s home child care" : (self::agency($c['agency_id']) ?: $fallback);
        }

        return $c['name'];
    }

    /** Who to talk to in person: the provider's first name ("Amna") for a provider-named home, else the centre's name. */
    public static function who(?int $centreId, string $fallback = 'the centre'): string
    {
        $c = self::centre($centreId);
        if ($c === null || $c['name'] === '') {
            return $fallback;
        }

        return $c['provider'] ? ($c['first'] !== '' ? $c['first'] : self::place($centreId, $fallback)) : $c['name'];
    }

    /** @return array{name:string, agency_id:int, provider:bool, first:string}|null */
    private static function centre(?int $centreId): ?array
    {
        if (! $centreId) {
            return null;
        }
        self::fresh();
        if (array_key_exists($centreId, self::$centres)) {
            return self::$centres[$centreId];
        }
        $row = DB::table('centres')->where('id', $centreId)
            ->first(['id', 'agency_id', 'name', 'supervisor_first_name', 'supervisor_last_name']);
        if (! $row) {
            return self::$centres[$centreId] = null;
        }
        $name = trim((string) $row->name);
        $key = self::key($name);
        $first = '';
        $provider = false;

        $supFirst = trim((string) ($row->supervisor_first_name ?? ''));
        $sup = self::key($supFirst . ' ' . ($row->supervisor_last_name ?? ''));
        if ($key !== '' && $sup !== '' && $sup === $key && trim((string) ($row->supervisor_last_name ?? '')) !== '') {
            $provider = true;
            $first = $supFirst;
        }
        if (! $provider && $key !== '' && str_contains($key, ' ')) {
            // Not on the supervisor field: is it the name of someone who works at this centre?
            foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.centre_id', $centreId)->where('ra.active', 1)->whereIn('ra.role', self::STAFF_ROLES)
                ->whereNull('u.deleted_at')->distinct()->get(['u.first_name', 'u.last_name']) as $u) {
                if (trim((string) $u->last_name) !== '' && self::key($u->first_name . ' ' . $u->last_name) === $key) {
                    $provider = true;
                    $first = trim((string) $u->first_name);
                    break;
                }
            }
        }

        return self::$centres[$centreId] = ['name' => $name, 'agency_id' => (int) $row->agency_id, 'provider' => $provider, 'first' => $first];
    }

    private static function key(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }

    /** Tests and long-running workers: forget what was read. */
    public static function flush(): void
    {
        self::$centres = [];
        self::$agencies = [];
    }
}
