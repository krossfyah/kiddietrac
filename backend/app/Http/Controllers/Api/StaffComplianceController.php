<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\StaffCompliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Staff compliance (2026-10-10): hire date + registry number, deadlines counted from hire,
 * the professional-development hours ledger, and household residents of home daycares.
 *
 * Who: agency admins (and platform admins in the active agency) see every staff member;
 * centre directors see staff who hold a role at their centre(s); anyone sees and logs their
 * own. Household residents: directors/admins of that centre, and the provider whose home it
 * is (the centre's email is theirs). Everything here fails closed and is audited.
 */
final class StaffComplianceController extends Controller
{
    private function agencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        if ($active && DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
                ->where(fn ($w) => $w->where('role', 'platform_admin')->orWhere('agency_id', $active)->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $active)->pluck('id')))->exists()) {
            return $active;
        }
        $ra = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->whereNotNull('agency_id')->value('agency_id');
        if (! $ra) {
            $c = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->whereNotNull('centre_id')->value('centre_id');
            $ra = $c ? DB::table('centres')->where('id', $c)->value('agency_id') : null;
        }
        abort_unless($ra, 400, 'Select an agency first.');

        return (int) $ra;
    }

    /** null = not a manager; [] / [ids] = centres this person manages (null centres = all). */
    private function scope(Request $request, int $agencyId): ?array
    {
        $uid = (int) $request->user()->id;
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->get(['role', 'agency_id', 'centre_id']);
        foreach ($roles as $r) {
            if ($r->role === 'platform_admin' || ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId)) return ['all' => true, 'centres' => null];
        }
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $mine = $roles->where('role', 'centre_director')->pluck('centre_id')->filter()->map(fn ($i) => (int) $i)->intersect($centres)->values()->all();

        return $mine ? ['all' => false, 'centres' => $mine] : null;
    }

    private function canManageUser(Request $request, int $agencyId, int $userId): bool
    {
        $s = $this->scope($request, $agencyId);
        if (! $s) return false;
        return in_array($userId, StaffCompliance::staffIds($agencyId, $s['all'] ? null : $s['centres']), true);
    }

    private function audit(Request $request, int $agencyId, string $action, string $type, int $id, string $summary): void
    {
        try {
            Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id,
                'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    /* ───────────── deadlines ───────────── */

    /** GET /admin/staff-compliance — the roll-up for directors and admins. */
    public function rollup(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $s = $this->scope($request, $agencyId);
        abort_unless($s, 403, 'Directors and admins only.');
        $rules = StaffCompliance::rules($agencyId);
        if (! $rules['enabled']) return response()->json(['enabled' => false, 'jurisdiction' => $rules['label']]);
        $out = [];
        foreach (StaffCompliance::staffIds($agencyId, $s['all'] ? null : $s['centres']) as $uid) {
            $c = StaffCompliance::forUser($agencyId, $uid, $rules);
            $out[] = ['user_id' => $uid, 'name' => $c['name'], 'hire_date' => $c['hire_date'], 'counts' => $c['counts'], 'pd' => $c['pd'],
                'attention' => $c['counts']['overdue'] + $c['counts']['expired'] + $c['counts']['missing'] + $c['counts']['needs_hire_date']];
        }
        usort($out, fn ($a, $b) => [$b['attention'], $a['name']] <=> [$a['attention'], $b['name']]);

        return response()->json(['enabled' => true, 'jurisdiction' => $rules['label'], 'registry' => $rules['registry'], 'pd' => $rules['pd'], 'staff' => $out]);
    }

    /** GET /admin/staff-compliance/{user} */
    public function show(Request $request, int $user): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($this->canManageUser($request, $agencyId, $user) || (int) $request->user()->id === $user, 403);
        $rules = StaffCompliance::rules($agencyId);

        return response()->json(['enabled' => $rules['enabled'], 'jurisdiction' => $rules['label'], 'registry' => $rules['registry']]
            + StaffCompliance::forUser($agencyId, $user, $rules) + ['can_manage' => $this->canManageUser($request, $agencyId, $user)]);
    }

    /** GET /me/compliance — the signed-in staff member's own. */
    public function mine(Request $request): JsonResponse
    {
        return $this->show($request, (int) $request->user()->id);
    }

    /** PATCH /admin/staff-compliance/{user}/hr {hire_date?, registry_number?} — directors/admins. */
    public function updateHr(Request $request, int $user): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($this->canManageUser($request, $agencyId, $user), 403, 'Directors and admins only.');
        $d = $request->validate(['hire_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'], 'registry_number' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $before = DB::table('users')->where('id', $user)->first(['hire_date', 'registry_number']);
        $u = [];
        if (array_key_exists('hire_date', $d)) $u['hire_date'] = $d['hire_date'] ?: null;
        if (array_key_exists('registry_number', $d)) $u['registry_number'] = trim((string) $d['registry_number']) ?: null;
        if ($u) DB::table('users')->where('id', $user)->update($u + ['updated_at' => now()]);
        $this->audit($request, $agencyId, 'staff.hr_updated', 'user', $user, 'Hire date / registry number changed: '
            . json_encode(['hire_date' => [$before->hire_date ?? null, $u['hire_date'] ?? ($before->hire_date ?? null)], 'registry_number' => [$before->registry_number ?? null, $u['registry_number'] ?? ($before->registry_number ?? null)]]));

        return $this->show($request, $user);
    }

    /* ───────────── professional development hours ───────────── */

    /** GET /pd-hours?user_id= (own by default) */
    public function pdIndex(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $me = (int) $request->user()->id;
        $uid = (int) ($request->query('user_id') ?: $me);
        abort_unless($uid === $me || $this->canManageUser($request, $agencyId, $uid), 403);
        $rows = DB::table('pd_hours as p')->leftJoin('users as r', 'r.id', '=', 'p.reviewed_by')
            ->where('p.user_id', $uid)->where('p.agency_id', $agencyId)->whereNull('p.deleted_at')->orderByDesc('p.taken_on')->orderByDesc('p.id')->limit(300)
            ->get(['p.id', 'p.taken_on', 'p.title', 'p.provider', 'p.hours', 'p.status', 'p.certificate_name', 'p.reviewed_at', 'p.created_by',
                DB::raw("TRIM(CONCAT(COALESCE(r.first_name,''),' ',COALESCE(r.last_name,''))) as reviewer")]);

        return response()->json(['entries' => $rows, 'can_manage' => $this->canManageUser($request, $agencyId, $uid), 'pd' => StaffCompliance::forUser($agencyId, $uid)['pd']]);
    }

    /** POST /pd-hours (multipart) {user_id?, taken_on, title, provider?, hours, certificate?} */
    public function pdStore(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $me = (int) $request->user()->id;
        $d = $request->validate([
            'user_id' => ['nullable', 'integer'], 'taken_on' => ['required', 'date', 'before_or_equal:today'], 'title' => ['required', 'string', 'max:190'],
            'provider' => ['nullable', 'string', 'max:190'], 'hours' => ['required', 'numeric', 'min:0.25', 'max:100'],
            'certificate' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx'],
        ]);
        $uid = (int) ($d['user_id'] ?? $me);
        $manager = $this->canManageUser($request, $agencyId, $uid);
        abort_unless($uid === $me || $manager, 403);
        $path = null; $name = null;
        if ($request->hasFile('certificate')) {
            $f = $request->file('certificate');
            $name = mb_substr($f->getClientOriginalName(), 0, 190);
            $path = $f->storeAs('pd-certificates/' . $agencyId, Str::uuid() . '.' . strtolower($f->getClientOriginalExtension() ?: 'bin'), 'local');
        }
        /* Training hours count as soon as they are logged (2026-10-10, Anthony: staff-logged
           hours should not need approval — "count immediately"; directors can still remove
           anything wrong). A director/admin entering for someone else is still recorded as the
           reviewer. */
        $approved = true;
        $id = DB::table('pd_hours')->insertGetId(['agency_id' => $agencyId, 'user_id' => $uid, 'taken_on' => $d['taken_on'], 'title' => $d['title'],
            'provider' => $d['provider'] ?? null, 'hours' => round((float) $d['hours'], 2), 'certificate_path' => $path, 'certificate_name' => $name,
            'status' => $approved ? 'approved' : 'pending', 'reviewed_by' => ($manager && $uid !== $me) ? $me : null, 'reviewed_at' => now(),
            'created_by' => $me, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'staff.pd_hours_added', 'user', $uid, 'Training hours logged: ' . $d['hours'] . ' h — ' . $d['title'] . ($approved ? ' (approved)' : ' (waiting for review)'));
        if (! $approved) {
            // Tell the people who can approve it.
            try {
                $s = DB::table('role_assignments as ra')->where('ra.active', true)
                    ->where(fn ($w) => $w->where(fn ($a) => $a->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId))
                        ->orWhere(fn ($dd) => $dd->where('ra.role', 'centre_director')->whereIn('ra.centre_id', DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->whereNotNull('centre_id')->pluck('centre_id'))))
                    ->where('ra.user_id', '!=', $uid)->pluck('ra.user_id')->unique();
                $who = trim((string) DB::table('users')->where('id', $uid)->value(DB::raw("CONCAT_WS(' ', first_name, last_name)")));
                $rows = [];
                foreach ($s as $r) $rows[] = ['user_id' => (int) $r, 'type' => 'pd_hours', 'title' => '🎓 ' . $who . ' logged ' . $d['hours'] . ' training hours',
                    'body' => $d['title'] . ' — waiting for your approval.', 'data' => json_encode(['link' => '#compliance']), 'created_at' => now()];
                if ($rows) \App\Support\Notify::write($rows);
            } catch (\Throwable $e) {}
        }

        return response()->json(['id' => $id, 'status' => $approved ? 'approved' : 'pending']);
    }

    /** POST /pd-hours/{id}/review {approve: bool} — directors/admins. */
    public function pdReview(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = DB::table('pd_hours')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        abort_unless($this->canManageUser($request, $agencyId, (int) $row->user_id) && (int) $row->user_id !== (int) $request->user()->id, 403, 'A director or admin reviews training hours (not your own).');
        $approve = (bool) $request->validate(['approve' => ['required', 'boolean']])['approve'];
        DB::table('pd_hours')->where('id', $id)->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => (int) $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'staff.pd_hours_reviewed', 'user', (int) $row->user_id, 'Training hours ' . ($approve ? 'approved' : 'not approved') . ': ' . $row->hours . ' h — ' . $row->title);

        return response()->json(['ok' => true]);
    }

    /** DELETE /pd-hours/{id} — own pending entry, or a director/admin. */
    public function pdDelete(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = DB::table('pd_hours')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        $me = (int) $request->user()->id;
        abort_unless($row->user_id == $me || $this->canManageUser($request, $agencyId, (int) $row->user_id), 403);
        DB::table('pd_hours')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'staff.pd_hours_removed', 'user', (int) $row->user_id, 'Training hours removed: ' . $row->hours . ' h — ' . $row->title);

        return response()->json(['ok' => true]);
    }

    /** GET /pd-hours/{id}/certificate */
    public function pdCertificate(Request $request, int $id)
    {
        $agencyId = $this->agencyId($request);
        $row = DB::table('pd_hours')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($row && $row->certificate_path && Storage::disk('local')->exists($row->certificate_path), 404);
        abort_unless((int) $row->user_id === (int) $request->user()->id || $this->canManageUser($request, $agencyId, (int) $row->user_id), 403);
        $this->audit($request, $agencyId, 'staff.pd_certificate_viewed', 'user', (int) $row->user_id, 'Opened a training certificate: ' . $row->title);

        return Storage::disk('local')->response($row->certificate_path, $row->certificate_name ?: 'certificate', ['Cache-Control' => 'private, no-store']);
    }

    /* ───────────── certifications (CPR, first aid, RECE …) ─────────────

       2026-10-19, Anthony: "add ability to add CPR certificates with issue and expiry dates -
       full system and add ability for educators to upload using their view", then "staff
       certifications should also be linked to their user profile record as well".

       One set of endpoints for every door: the Certifications screen (directors/admins), the
       Certifications tab on the user record, and an educator's own "My certificates". Before
       this the add/edit/delete routes checked nothing — any director could change any row by
       id, and any educator could add a certificate to anyone. Now: your own rows, or rows of
       people you manage in the ACTIVE agency, and nothing else; the reader and every write
       use the same rule (certRow). */

    private const CERT_MIMES = 'pdf,jpg,jpeg,png,webp,heic,heif';

    /** The certificate types for the agency's jurisdiction: [code => label]. */
    private function certTypes(int $agencyId): array
    {
        $out = [];
        foreach ((array) (\App\Support\Jurisdictions::forAgency($agencyId)['certifications'] ?? []) as $c) $out[$c['code']] = $c['label'];
        if (! $out) $out = ['RECE' => 'RECE', 'First_Aid' => 'First Aid', 'CPR' => 'CPR', 'Other' => 'Other'];
        if (! isset($out['CPR'])) $out['CPR'] = 'CPR';
        if (! isset($out['Other'])) $out['Other'] = 'Other';

        return $out;
    }

    /** Every code the column accepts (other jurisdictions' types stay valid on old rows). */
    private function certCodes(): array
    {
        return array_values(array_unique(array_merge(['RECE', 'ECE_Assistant', 'First_Aid', 'CPR', 'Food_Handler', 'VSC', 'WHMIS', 'Vulnerable_Sector_Check', 'Health_Card', 'Other'],
            \App\Support\Jurisdictions::allCertificationCodes())));
    }

    /** A row this person may see: their own or a managed person's, entered under this agency (or legacy, unowned). */
    private function certRow(Request $request, int $agencyId, int $id): object
    {
        $row = DB::table('staff_certifications')->where('id', $id)
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereNull('agency_id'))->first();
        abort_unless($row, 404, 'Certificate not found.');
        $me = (int) $request->user()->id;
        abort_unless((int) $row->user_id === $me || $this->canManageUser($request, $agencyId, (int) $row->user_id), 404, 'Certificate not found.');

        return $row;
    }

    private function certOut(object $c, array $types, string $today, array $names = []): array
    {
        $status = 'no_expiry'; $days = null;
        if ($c->expires_at) {
            $days = (int) \Illuminate\Support\Carbon::parse($today)->diffInDays(\Illuminate\Support\Carbon::parse($c->expires_at), false);
            $status = $days < 0 ? 'expired' : ($days <= 30 ? 'expiring_soon' : ($days <= 90 ? 'warning' : 'ok'));
        }
        $code = (string) $c->cert_type;

        return [
            'id' => (int) $c->id, 'user_id' => (int) $c->user_id, 'staff_name' => $names[(int) $c->user_id] ?? null,
            'cert_type' => $code, 'label' => $c->title ?: ($types[$code] ?? str_replace('_', ' ', $code)), 'title' => $c->title,
            'certifier' => $c->certifier, 'issued_at' => $c->issued_at ? substr((string) $c->issued_at, 0, 10) : null,
            'expires_at' => $c->expires_at ? substr((string) $c->expires_at, 0, 10) : null, 'days_until_expiry' => $days, 'status' => $status,
            'has_document' => (bool) $c->document_path, 'document_name' => $c->document_name,
            'verified' => (bool) $c->verified_at, 'verified_at' => $c->verified_at, 'verified_by_name' => $names['v' . (int) $c->verified_by] ?? null,
            'self_added' => $c->added_by !== null && (int) $c->added_by === (int) $c->user_id,
        ];
    }

    private function namesFor(array $ids): array
    {
        $out = [];
        foreach (DB::table('users')->whereIn('id', array_values(array_unique(array_filter($ids))) ?: [0])->get(['id', 'first_name', 'last_name']) as $u) {
            $out[(int) $u->id] = trim($u->first_name . ' ' . $u->last_name);
        }

        return $out;
    }

    private function certToday(int $agencyId): string
    {
        return \Illuminate\Support\Carbon::now(\App\Support\AgencyTime::tz($agencyId) ?: 'America/Toronto')->toDateString();
    }

    /** GET /staff-certifications?user_id= — one person's certificates (yours by default). */
    public function certIndex(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $me = (int) $request->user()->id;
        $uid = (int) ($request->query('user_id') ?: $me);
        $manage = $this->canManageUser($request, $agencyId, $uid);
        abort_unless($uid === $me || $manage, 403);
        $rows = DB::table('staff_certifications')->where('user_id', $uid)->where('active', 1)
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereNull('agency_id'))
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderByDesc('id')->get();
        $types = $this->certTypes($agencyId); $today = $this->certToday($agencyId);
        $names = $this->namesFor(array_merge([$uid], $rows->pluck('verified_by')->all()));
        foreach ($rows as $r) if ($r->verified_by && isset($names[(int) $r->verified_by])) $names['v' . (int) $r->verified_by] = $names[(int) $r->verified_by];

        return response()->json([
            'user_id' => $uid, 'name' => $names[$uid] ?? null, 'can_manage' => $manage, 'is_self' => $uid === $me,
            'types' => collect($types)->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'certifications' => $rows->map(fn ($r) => $this->certOut($r, $types, $today, $names))->values(),
        ]);
    }

    /** GET /staff-certifications/roster?centre_id=&type= — everyone you manage (directors/admins). */
    public function certRoster(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $s = $this->scope($request, $agencyId);
        abort_unless($s, 403, 'Directors and admins only.');
        $centres = DB::table('centres')->whereIn('id', \App\Support\Live::centreIds($agencyId) ?: [0])
            ->when(! $s['all'], fn ($q) => $q->whereIn('id', $s['centres'] ?: [0]))->orderBy('name')->get(['id', 'name']);
        $centreId = (int) $request->query('centre_id');
        $filter = $s['all'] ? null : $s['centres'];
        if ($centreId) {
            abort_unless($centres->contains('id', $centreId), 403, 'That centre is not one of yours.');
            $filter = [$centreId];
        }
        $staff = StaffCompliance::staffIds($agencyId, $filter);
        $types = $this->certTypes($agencyId); $today = $this->certToday($agencyId);
        $type = (string) $request->query('type', '');
        $rows = DB::table('staff_certifications')->whereIn('user_id', $staff ?: [0])->where('active', 1)
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereNull('agency_id'))
            ->when($type !== '', fn ($q) => $q->where('cert_type', $type))
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->get();
        $names = $this->namesFor(array_merge($staff, $rows->pluck('verified_by')->all()));
        foreach ($rows as $r) if ($r->verified_by && isset($names[(int) $r->verified_by])) $names['v' . (int) $r->verified_by] = $names[(int) $r->verified_by];
        $out = $rows->map(fn ($r) => $this->certOut($r, $types, $today, $names))->values();

        // Who has no CURRENT certificate of the chosen type (CPR when none is chosen).
        $need = $type !== '' ? $type : 'CPR';
        $have = DB::table('staff_certifications')->whereIn('user_id', $staff ?: [0])->where('active', 1)->where('cert_type', $need)
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereNull('agency_id'))
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $today))->pluck('user_id')->map(fn ($i) => (int) $i)->all();
        $missing = [];
        foreach ($staff as $uid) if (! in_array($uid, $have, true)) $missing[] = ['user_id' => $uid, 'name' => $names[$uid] ?? ''];
        usort($missing, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $staffList = array_map(fn ($u) => ['id' => $u, 'name' => $names[$u] ?? ''], $staff);
        usort($staffList, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return response()->json([
            'centres' => $centres, 'centre_id' => $centreId ?: null, 'type' => $type ?: null,
            'types' => collect($types)->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'staff' => $staffList, 'certifications' => $out,
            'missing' => ['type' => $need, 'label' => $types[$need] ?? $need, 'staff' => $missing],
            'counts' => ['total' => $out->count(), 'expired' => $out->where('status', 'expired')->count(),
                'expiring_soon' => $out->where('status', 'expiring_soon')->count(), 'unverified' => $out->where('verified', false)->count()],
        ]);
    }

    private function certValidate(Request $request, bool $creating): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'integer'],
            'cert_type' => [$creating ? 'required' : 'sometimes', 'in:' . implode(',', $this->certCodes())],
            'title' => ['nullable', 'string', 'max:160'],
            'certifier' => ['nullable', 'string', 'max:160'],
            'issued_at' => [$creating ? 'required' : 'sometimes', 'date', 'before_or_equal:' . now()->addDay()->toDateString()],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'document' => ['nullable', 'file', 'max:10240', 'mimes:' . self::CERT_MIMES],
            'remove_document' => ['nullable', 'boolean'],
        ], [
            'expires_at.after_or_equal' => 'The expiry date cannot be before the issue date.',
            'document.mimes' => 'Upload a PDF or a photo (JPG, PNG, HEIC).',
            'document.max' => 'The file is over 10 MB.',
        ]);
    }

    private function certStoreFile(Request $request, int $agencyId): array
    {
        $f = $request->file('document');

        return [$f->storeAs('staff-certificates/' . $agencyId, Str::uuid() . '.' . strtolower($f->getClientOriginalExtension() ?: 'bin'), 'local'),
            mb_substr($f->getClientOriginalName(), 0, 190)];
    }

    /** POST /staff-certifications (multipart) {user_id?, cert_type, title?, certifier?, issued_at, expires_at?, document?} */
    public function certStore(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $me = (int) $request->user()->id;
        $d = $this->certValidate($request, true);
        $uid = (int) ($d['user_id'] ?? $me);
        $manager = $this->canManageUser($request, $agencyId, $uid);
        abort_unless($uid === $me || $manager, 403, 'You can add certificates for yourself, or for staff you manage.');
        [$path, $name] = $request->hasFile('document') ? $this->certStoreFile($request, $agencyId) : [null, null];
        // Entered by a director/admin for someone else = checked by them. Your own = waits for a check.
        $checked = $manager && $uid !== $me;
        $id = DB::table('staff_certifications')->insertGetId([
            'user_id' => $uid, 'agency_id' => $agencyId, 'cert_type' => $d['cert_type'],
            'title' => ($d['cert_type'] === 'Other' || ! empty($d['title'])) ? (trim((string) ($d['title'] ?? '')) ?: null) : null,
            'certifier' => trim((string) ($d['certifier'] ?? '')) ?: null, 'issued_at' => $d['issued_at'], 'expires_at' => $d['expires_at'] ?? null,
            'document_path' => $path, 'document_name' => $name, 'active' => 1, 'added_by' => $me,
            'verified_by' => $checked ? $me : null, 'verified_at' => $checked ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $label = $this->certTypes($agencyId)[$d['cert_type']] ?? $d['cert_type'];
        $this->audit($request, $agencyId, 'staff.certification_added', 'user', $uid, 'Certificate added: ' . $label
            . ' (issued ' . $d['issued_at'] . (! empty($d['expires_at']) ? ', expires ' . $d['expires_at'] : '') . ')' . ($path ? ' with the certificate attached' : ''));
        if ($uid === $me) $this->certTellManagers($agencyId, $uid, '🎓 %s added a certificate', $label . (! empty($d['expires_at']) ? ' — expires ' . $d['expires_at'] : '') . '. Open it to check and mark it verified.');

        return response()->json(['id' => $id, 'verified' => $checked], 201);
    }

    /** POST|PATCH /staff-certifications/{id} — change the details, replace or remove the file. */
    public function certUpdate(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->certRow($request, $agencyId, $id);
        $me = (int) $request->user()->id;
        $manager = $this->canManageUser($request, $agencyId, (int) $row->user_id);
        $d = $this->certValidate($request, false);
        $u = [];
        foreach (['cert_type', 'issued_at'] as $k) if (array_key_exists($k, $d)) $u[$k] = $d[$k];
        foreach (['title', 'certifier', 'expires_at'] as $k) if ($request->has($k)) $u[$k] = trim((string) ($d[$k] ?? '')) ?: null;
        $issued = $u['issued_at'] ?? substr((string) $row->issued_at, 0, 10);
        $exp = array_key_exists('expires_at', $u) ? $u['expires_at'] : ($row->expires_at ? substr((string) $row->expires_at, 0, 10) : null);
        abort_if($exp && $issued && $exp < $issued, 422, 'The expiry date cannot be before the issue date.');
        $old = null;
        if ($request->hasFile('document')) {
            [$u['document_path'], $u['document_name']] = $this->certStoreFile($request, $agencyId);
            $old = $row->document_path;
        } elseif (! empty($d['remove_document'])) {
            $u['document_path'] = null; $u['document_name'] = null; $old = $row->document_path;
        }
        // A change made by the holder needs checking again; one made by their manager is checked by them.
        if ($u) {
            if ($manager && (int) $row->user_id !== $me) { $u['verified_by'] = $me; $u['verified_at'] = now(); }
            else { $u['verified_by'] = null; $u['verified_at'] = null; }
            if ($row->agency_id === null) $u['agency_id'] = $agencyId;
            DB::table('staff_certifications')->where('id', $id)->update($u + ['updated_at' => now()]);
        }
        if ($old) { try { Storage::disk('local')->delete($old); } catch (\Throwable $e) {} }
        $this->audit($request, $agencyId, 'staff.certification_updated', 'user', (int) $row->user_id, 'Certificate changed: '
            . ($this->certTypes($agencyId)[$u['cert_type'] ?? $row->cert_type] ?? $row->cert_type) . ' ' . json_encode(array_intersect_key($u, array_flip(['cert_type', 'title', 'certifier', 'issued_at', 'expires_at', 'document_name']))));

        return response()->json(['ok' => true]);
    }

    /** POST /staff-certifications/{id}/verify {verified: bool} — a director/admin, not on their own. */
    public function certVerify(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->certRow($request, $agencyId, $id);
        $me = (int) $request->user()->id;
        abort_unless($this->canManageUser($request, $agencyId, (int) $row->user_id) && (int) $row->user_id !== $me, 403, 'A director or admin checks certificates (not their own).');
        $ok = (bool) ($request->validate(['verified' => ['nullable', 'boolean']])['verified'] ?? true);
        DB::table('staff_certifications')->where('id', $id)->update(['verified_by' => $ok ? $me : null, 'verified_at' => $ok ? now() : null, 'updated_at' => now()]
            + ($row->agency_id === null ? ['agency_id' => $agencyId] : []));
        $this->audit($request, $agencyId, $ok ? 'staff.certification_verified' : 'staff.certification_unverified', 'user', (int) $row->user_id,
            'Certificate ' . ($ok ? 'checked and verified' : 'marked not verified') . ': ' . ($this->certTypes($agencyId)[$row->cert_type] ?? $row->cert_type));

        return response()->json(['ok' => true, 'verified' => $ok]);
    }

    /** DELETE /staff-certifications/{id} — a director/admin, or the holder while it is not yet verified. */
    public function certDelete(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->certRow($request, $agencyId, $id);
        $me = (int) $request->user()->id;
        $manager = $this->canManageUser($request, $agencyId, (int) $row->user_id);
        abort_unless($manager || ((int) $row->user_id === $me && ! $row->verified_at), 403, 'This certificate has been verified — ask your director to remove it.');
        DB::table('staff_certifications')->where('id', $id)->delete();
        if ($row->document_path) { try { Storage::disk('local')->delete($row->document_path); } catch (\Throwable $e) {} }
        $this->audit($request, $agencyId, 'staff.certification_removed', 'user', (int) $row->user_id, 'Certificate removed: '
            . ($this->certTypes($agencyId)[$row->cert_type] ?? $row->cert_type) . ' (issued ' . substr((string) $row->issued_at, 0, 10) . ($row->expires_at ? ', expires ' . substr((string) $row->expires_at, 0, 10) : '') . ')');

        return response()->json(['ok' => true]);
    }

    /** GET /staff-certifications/{id}/document — the certificate file, to the holder or their manager. */
    public function certDocument(Request $request, int $id)
    {
        $agencyId = $this->agencyId($request);
        $row = $this->certRow($request, $agencyId, $id);
        abort_unless($row->document_path && Storage::disk('local')->exists($row->document_path), 404, 'No certificate file is attached.');
        $this->audit($request, $agencyId, 'staff.certification_viewed', 'user', (int) $row->user_id, 'Opened a certificate file: ' . ($this->certTypes($agencyId)[$row->cert_type] ?? $row->cert_type));

        return Storage::disk('local')->response($row->document_path, $row->document_name ?: 'certificate', ['Cache-Control' => 'private, no-store']);
    }

    /** Wrappers for the older /staff/{user}/certifications routes, now scoped the same way. */
    public function certIndexFor(Request $request, int $user): JsonResponse
    {
        $request->query->set('user_id', $user);

        return $this->certIndex($request);
    }

    public function certStoreFor(Request $request, int $user): JsonResponse
    {
        $request->merge(['user_id' => $user]);

        return $this->certStore($request);
    }

    /** A bell to the directors of the person's centres and the agency's admins. */
    private function certTellManagers(int $agencyId, int $uid, string $titleFmt, string $body): void
    {
        try {
            $centres = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->whereNotNull('centre_id')->pluck('centre_id');
            $to = DB::table('role_assignments as ra')->where('ra.active', true)
                ->where(fn ($w) => $w->where(fn ($a) => $a->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId))
                    ->orWhere(fn ($dd) => $dd->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $centres->all() ?: [0])))
                ->where('ra.user_id', '!=', $uid)->pluck('ra.user_id')->unique();
            $who = trim((string) DB::table('users')->where('id', $uid)->value(DB::raw("CONCAT_WS(' ', first_name, last_name)")));
            $rows = [];
            foreach ($to as $r) $rows[] = ['user_id' => (int) $r, 'type' => 'compliance', 'title' => sprintf($titleFmt, $who), 'body' => $body,
                'data' => json_encode(['hash' => 'certifications', 'link' => '#certifications']), 'created_at' => now()];
            if ($rows) \App\Support\Notify::write($rows);
        } catch (\Throwable $e) {}
    }

    /* ───────────── household residents ───────────── */

    /** Centres whose household this person may see: directors/admins' centres, and a provider's own home. */
    private function householdCentres(Request $request, int $agencyId): array
    {
        $s = $this->scope($request, $agencyId);
        $ids = $s ? ($s['all'] ? DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->pluck('id')->all() : $s['centres']) : [];
        $email = mb_strtolower(trim((string) $request->user()->email));
        if ($email !== '') {
            $own = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])->pluck('id')->all();
            $ids = array_merge($ids, $own);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** GET /household — the homes this person can see, each with its residents and their checks. */
    public function household(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $ids = $this->householdCentres($request, $agencyId);
        /* Staff with no home of their own (an educator at a centre) have nothing to see here:
           say so, rather than refuse. The menu check asks on every page load, and a 403 here
           filled the audit log with "household (failed)" for every such educator (2026-10-13). */
        if (! $ids) {
            return response()->json(['homes' => [], 'check_types' => [], 'required_checks' => [], 'note' => null]);
        }
        $p = \App\Support\Jurisdictions::forAgency($agencyId);
        $checkTypes = array_values(array_map(fn ($c) => ['code' => $c['code'], 'label' => $c['label'], 'renew_years' => $c['renew_years'] ?? null],
            (array) (($p['background_checks'] ?? null) ?: \App\Support\Jurisdictions::profile('CA-ON')['background_checks'])));
        $tz = \App\Support\AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $today = \Illuminate\Support\Carbon::now($tz)->toDateString();
        $centres = DB::table('centres')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'license_type']);
        $out = [];
        foreach ($centres as $c) {
            $res = DB::table('household_residents')->where('centre_id', $c->id)->whereNull('deleted_at')->orderBy('full_name')->get();
            $list = [];
            foreach ($res as $r) {
                $checks = DB::table('household_resident_checks')->where('resident_id', $r->id)->orderByDesc('issued_at')->get();
                $list[] = ['id' => (int) $r->id, 'full_name' => $r->full_name, 'relationship' => $r->relationship, 'date_of_birth' => $r->date_of_birth,
                    'moved_in_on' => $r->moved_in_on, 'moved_out_on' => $r->moved_out_on, 'notes' => $r->notes,
                    'checks' => $checks->map(fn ($k) => ['id' => (int) $k->id, 'check_type' => $k->check_type, 'reference' => $k->reference, 'issued_at' => $k->issued_at,
                        'expires_at' => $k->expires_at, 'status' => ($k->expires_at && $k->expires_at < $today) ? 'expired' : 'current'])->values()];
            }
            $out[] = ['centre_id' => (int) $c->id, 'name' => $c->name, 'residents' => $list];
        }

        return response()->json(['homes' => $out, 'check_types' => $checkTypes, 'required_checks' => $p['checks_required'] ?? [],
            'note' => ! empty($p['household_checks']) ? 'Every adult who lives in the home needs the same background checks as staff.' : null]);
    }

    private function residentFor(Request $request, int $agencyId, int $id): object
    {
        $r = DB::table('household_residents')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($r && in_array((int) $r->centre_id, $this->householdCentres($request, $agencyId), true), 404);

        return $r;
    }

    /** POST /household/{centre}/residents */
    public function residentStore(Request $request, int $centre): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless(in_array($centre, $this->householdCentres($request, $agencyId), true), 403);
        $d = $request->validate(['full_name' => ['required', 'string', 'max:190'], 'relationship' => ['nullable', 'string', 'max:80'], 'date_of_birth' => ['nullable', 'date'],
            'moved_in_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $id = DB::table('household_residents')->insertGetId($d + ['agency_id' => $agencyId, 'centre_id' => $centre, 'created_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'household.resident_added', 'centre', $centre, 'Household resident added: ' . $d['full_name']);

        return response()->json(['id' => $id]);
    }

    /** PATCH /household-residents/{id} */
    public function residentUpdate(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $r = $this->residentFor($request, $agencyId, $id);
        $d = $request->validate(['full_name' => ['sometimes', 'string', 'max:190'], 'relationship' => ['sometimes', 'nullable', 'string', 'max:80'], 'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'moved_in_on' => ['sometimes', 'nullable', 'date'], 'moved_out_on' => ['sometimes', 'nullable', 'date'], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        DB::table('household_residents')->where('id', $id)->update($d + ['updated_at' => now()]);
        $this->audit($request, $agencyId, 'household.resident_updated', 'centre', (int) $r->centre_id, 'Household resident updated: ' . ($d['full_name'] ?? $r->full_name));

        return response()->json(['ok' => true]);
    }

    /** DELETE /household-residents/{id} — kept, marked removed. */
    public function residentDelete(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $r = $this->residentFor($request, $agencyId, $id);
        DB::table('household_residents')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'household.resident_removed', 'centre', (int) $r->centre_id, 'Household resident removed: ' . $r->full_name);

        return response()->json(['ok' => true]);
    }

    /** POST /household-residents/{id}/checks */
    public function checkStore(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $r = $this->residentFor($request, $agencyId, $id);
        $d = $request->validate(['check_type' => ['required', 'string', 'in:' . implode(',', \App\Support\Jurisdictions::allBackgroundCheckCodes())],
            'reference' => ['nullable', 'string', 'max:120'], 'issued_at' => ['nullable', 'date', 'before_or_equal:today'], 'expires_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:1000']]);
        // Fill the renewal date from the jurisdiction when one is known and none was given.
        if (empty($d['expires_at']) && ! empty($d['issued_at'])) {
            foreach ((array) (\App\Support\Jurisdictions::forAgency($agencyId)['background_checks'] ?? []) as $c) {
                if ($c['code'] === $d['check_type'] && ! empty($c['renew_years'])) $d['expires_at'] = \Illuminate\Support\Carbon::parse($d['issued_at'])->addYears((int) $c['renew_years'])->toDateString();
            }
        }
        $cid = DB::table('household_resident_checks')->insertGetId($d + ['resident_id' => $id, 'created_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'household.check_added', 'centre', (int) $r->centre_id, 'Background check recorded for household resident ' . $r->full_name . ': ' . $d['check_type']);

        return response()->json(['id' => $cid]);
    }

    /** DELETE /household-resident-checks/{id} */
    public function checkDelete(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $k = DB::table('household_resident_checks')->where('id', $id)->first();
        abort_unless($k, 404);
        $r = $this->residentFor($request, $agencyId, (int) $k->resident_id);
        DB::table('household_resident_checks')->where('id', $id)->delete();
        $this->audit($request, $agencyId, 'household.check_removed', 'centre', (int) $r->centre_id, 'Background check removed for household resident ' . $r->full_name . ': ' . $k->check_type);

        return response()->json(['ok' => true]);
    }
}
