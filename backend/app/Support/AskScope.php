<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WHO IS ASKING, AND WHAT MAY THEY SEE - the whole scope of one Ask KiddieTrac question (2026-11-03).
 *
 * Built once per request from the signed-in person and the agency they are acting in, and handed to every tool.
 * Nothing the model sends can change it.
 *
 *  agencyId   the ACTIVE agency (ResolvesCentreContext's rule: the header only when the person belongs there; a
 *             platform admin must have chosen one). No agency = no scope (fromRequest returns null).
 *  centreIds  Visibility::centreIds (agency admin / platform admin = the whole agency; a director = the centres on
 *             their own assignments, intersected with the agency) narrowed to OPEN centres (Live). [] = nothing.
 *  roles      AgencyRole::names - roles held IN THIS AGENCY only (a director here who is an admin elsewhere is a
 *             director here).
 *  allows()   the permission matrix (Permissions::allows) for this person in this agency, per data area.
 */
final class AskScope
{
    public const ROLES = ['agency_admin', 'centre_director', 'platform_admin'];

    private ?array $names = null;

    public function __construct(
        public readonly int $agencyId,
        public readonly int $userId,
        /** @var int[] */
        public readonly array $centreIds,
        /** @var string[] */
        public readonly array $roles,
        public readonly string $tz,
        private readonly ?Request $request = null,
        /** @var array<string,bool>|null test harness only: fixed answers instead of the matrix */
        private readonly ?array $perms = null,
    ) {
    }

    /** null when the person may not use Ask KiddieTrac here (no agency, or not an admin/director in it). */
    public static function fromRequest(Request $request, ?int $agencyId): ?self
    {
        $user = $request->user();
        if (! $user || ! $agencyId) {
            return null;
        }
        $roles = AgencyRole::names($request);
        if (! array_intersect($roles, self::ROLES)) {
            return null;
        }
        $centres = Live::onlyOpenCentres(Visibility::centreIds($agencyId, $user));

        return new self($agencyId, (int) $user->id, $centres, array_values(array_intersect($roles, self::ROLES)),
            AgencyTime::tz($agencyId), $request);
    }

    public function isWide(): bool
    {
        return (bool) array_intersect($this->roles, ['agency_admin', 'platform_admin']);
    }

    public function allows(string $key): bool
    {
        if ($this->perms !== null) {
            return (bool) ($this->perms[$key] ?? false);
        }
        if (! $this->request) {
            return false;
        }
        try {
            return Permissions::allows($this->request, $key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Never empty in a whereIn: [] means NOTHING. */
    public function centres(): array
    {
        return $this->centreIds ?: [0];
    }

    /** Open centre names in scope, id => name. */
    public function centreNames(): array
    {
        // On the object, never a static: workers are long-lived and a static would outlive the request.
        if ($this->names === null) {
            $this->names = DB::table('centres')->whereIn('id', $this->centres())->orderBy('name')->pluck('name', 'id')
                ->map(fn ($v) => (string) $v)->all();
        }

        return $this->names;
    }

    /** One line of context for the model: today's date in the agency, and who is asking (by role only). */
    public function contextLine(): string
    {
        $now = \Carbon\Carbon::now($this->tz);
        $role = in_array('agency_admin', $this->roles, true) ? 'an agency administrator'
            : (in_array('platform_admin', $this->roles, true) ? 'a KiddieTrac administrator viewing this agency' : 'a centre director');
        $names = array_values($this->centreNames());

        return 'Today is ' . $now->format('l, F j, Y') . ' and the time is ' . $now->format('g:i A') . ' in the agency\'s time zone (' . $this->tz . '). '
            . 'The person asking is ' . $role . '. Centres they can see (' . count($names) . '): ' . (implode('; ', array_slice($names, 0, 40)) ?: 'none') . '.';
    }
}
