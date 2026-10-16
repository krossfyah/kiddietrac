<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audience;
use App\Support\CentreScope;
use App\Support\RefCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v22p51 — Background-check / VSS expiry tracking.
 * Mirrors the certifications pattern: rows expire, dashboard surfaces
 * red/amber rows, daily-digest email warns about upcoming expiries.
 *
 * WHO THE SCREEN SEES (2026-10-15). Anthony: "background checks section when adding new
 * record doesnt pull staff". The screen asked /admin/users for
 * `role=educator,centre_director,agency_admin`, which that endpoint matched as ONE role
 * name, and read `.data` where it answers `.users` — so the Staff dropdown was always
 * empty. The list now carries its own `staff` (the people a check can be recorded for,
 * scoped exactly like the rows), and every read and write is held to the same scope:
 * a centre director sees and edits the checks of their own centres' staff only; a check
 * is only ever recorded for someone who works at this agency; and an edit or delete by
 * id can only touch a row of the active agency.
 */
final class BackgroundCheckController extends Controller
{
    private const STAFF_ROLES = ['agency_admin', 'centre_director', 'educator', 'home_visitor'];

    public function listAgency(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);
        $staffIds = $this->staffIds($request, $agencyId);
        if ($request->query('format') === 'csv') return $this->csv($agencyId, $staffIds);

        $rows = DB::table('background_checks as bgc')
            ->join('users as u', 'u.id', '=', 'bgc.user_id')
            ->where('bgc.agency_id', $agencyId)
            ->whereIn('bgc.user_id', $staffIds ?: [0])
            ->orderBy('bgc.expires_at')
            ->select('bgc.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email', 'u.photo_url as user_photo')
            ->get();

        $ref = $rows->count() ? RefCode::users($rows->pluck('user_id')->unique()->all(), $agencyId, 'S') : [];
        $now = Carbon::now()->startOfDay();
        $rows->transform(function ($r) use ($now, $ref) {
            $exp = Carbon::parse($r->expires_at);
            $days = (int) $exp->diffInDays($now, false);
            $r->status_bucket = $exp->isPast()
                ? 'expired'
                : ($days >= -60 ? 'expiring' : 'valid');
            $r->days_until_expiry = $exp->isPast() ? -abs($days) : abs($days);
            $r->staff_ref = $ref[(int) $r->user_id] ?? null;
            return $r;
        });

        $people = DB::table('users as u')->whereIn('u.id', $staffIds ?: [0])
            ->orderBy('u.first_name')->orderBy('u.last_name')
            ->get(['u.id', 'u.first_name', 'u.last_name']);
        $pref = $people->count() ? RefCode::users($people->pluck('id')->all(), $agencyId, 'S') : [];
        $roles = DB::table('role_assignments')->where('active', true)->whereIn('user_id', $people->pluck('id')->all() ?: [0])
            ->whereIn('role', self::STAFF_ROLES)->get(['user_id', 'role'])->groupBy('user_id');
        $staff = $people->map(fn ($p) => [
            'id' => (int) $p->id,
            'name' => trim($p->first_name . ' ' . $p->last_name),
            'ref' => $pref[(int) $p->id] ?? null,
            'roles' => ($roles[$p->id] ?? collect())->pluck('role')->unique()->values()->all(),
        ])->values();

        return response()->json(['data' => $rows, 'staff' => $staff]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'           => 'nullable|integer',
            'user_id'      => 'required|integer',
            // From the jurisdiction catalogues (2026-10-09) + 'vulnerable_sector', which existing rows use.
            'check_type'   => 'required|string|in:' . implode(',', array_unique(array_merge(['vss', 'criminal', 'driver', 'reference', 'other', 'vulnerable_sector'], \App\Support\Jurisdictions::allBackgroundCheckCodes()))),
            'reference'    => 'nullable|string|max:120',
            'issued_at'    => 'nullable|date',
            'expires_at'   => 'required|date',
            'document_url' => 'nullable|string|max:500',
            'notes'        => 'nullable|string|max:2000',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);
        $staffIds = $this->staffIds($request, $agencyId);
        abort_unless(in_array((int) $data['user_id'], $staffIds, true), 422, 'Choose a staff member of this agency.');

        $payload = [
            'agency_id'    => $agencyId,
            'user_id'      => $data['user_id'],
            'check_type'   => $data['check_type'],
            'reference'    => $data['reference'] ?? null,
            'issued_at'    => $data['issued_at'] ?? null,
            'expires_at'   => $data['expires_at'],
            'notes'        => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'updated_at'   => now(),
        ];
        // The form never sends the document (it is uploaded on its own), so an edit must not wipe it.
        if (array_key_exists('document_url', $data) && $data['document_url'] !== null) $payload['document_url'] = $data['document_url'];

        if (!empty($data['id'])) {
            $row = $this->ownedRow($agencyId, (int) $data['id'], $staffIds);
            DB::table('background_checks')->where('id', $row->id)->update($payload);
            return response()->json(['id' => (int) $row->id, 'status' => 'updated']);
        }
        $payload['created_at'] = now();
        $id = DB::table('background_checks')->insertGetId($payload);
        return response()->json(['id' => $id, 'status' => 'created'], 201);
    }

    /** Attach a file (scan/PDF) to a background-check record -> background_checks.document_url. */
    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);
        $this->ownedRow($agencyId, $id, $this->staffIds($request, $agencyId));
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:10240']]);
        $f = $request->file('file');
        $name = (string) \Illuminate\Support\Str::uuid() . '.' . strtolower($f->getClientOriginalExtension() ?: $f->extension());
        $f->storeAs('background-checks/' . $id, $name, 'public');
        $url = '/storage/background-checks/' . $id . '/' . $name;
        DB::table('background_checks')->where('id', $id)->update(['document_url' => $url, 'updated_at' => now()]);
        return response()->json(['document_url' => $url]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);
        $row = $this->ownedRow($agencyId, $id, $this->staffIds($request, $agencyId));
        DB::table('background_checks')->where('id', $row->id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    /**
     * The people a check may be recorded for and read by this caller: current staff of the
     * active agency (an off-boarded account is not listed), confined to a centre director's
     * own centres. Fails closed: an empty list means nothing.
     *
     * @return int[]
     */
    private function staffIds(Request $request, int $agencyId): array
    {
        $scope = CentreScope::ids($request, $agencyId);
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $ids = DB::table('role_assignments')->where('active', true)->whereIn('role', self::STAFF_ROLES)
            ->where(function ($w) use ($agencyId, $centres, $scope) {
                if ($scope === null) {
                    $w->where('agency_id', $agencyId)->orWhereIn('centre_id', $centres ?: [0]);
                } else {
                    $w->whereIn('centre_id', $scope ?: [0]);
                }
            })
            ->pluck('user_id')->unique()->all();
        if (! $ids) return [];
        return Audience::excludeOff(DB::table('users as u')->whereIn('u.id', $ids)->whereNull('u.deleted_at'))
            ->pluck('u.id')->map(fn ($v) => (int) $v)->all();
    }

    /** A row of the active agency whose person this caller may see — else 404 (not 403: no oracle). */
    private function ownedRow(int $agencyId, int $id, array $staffIds): object
    {
        $row = DB::table('background_checks')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row && in_array((int) $row->user_id, $staffIds, true), 404);
        return $row;
    }

    private function csv(int $agencyId, array $staffIds): StreamedResponse
    {
        $name = 'background-checks-' . now()->format('Y-m-d') . '.csv';
        return response()->streamDownload(function () use ($agencyId, $staffIds) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Staff name', 'Staff ID', 'Email', 'Check type', 'Reference', 'Issued', 'Expires', 'Status', 'Notes']);
            DB::table('background_checks as bgc')
                ->join('users as u', 'u.id', '=', 'bgc.user_id')
                ->where('bgc.agency_id', $agencyId)
                ->whereIn('bgc.user_id', $staffIds ?: [0])
                ->orderBy('bgc.expires_at')
                ->select('bgc.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email')
                ->chunk(200, function ($rows) use ($h, $agencyId) {
                    $ref = RefCode::users($rows->pluck('user_id')->all(), $agencyId, 'S');   // 2026-10-14
                    foreach ($rows as $r) {
                        $status = Carbon::parse($r->expires_at)->isPast() ? 'expired' : 'valid';
                        fputcsv($h, [
                            $r->user_name, $ref[(int) $r->user_id] ?? '', $r->user_email, $r->check_type, $r->reference,
                            $r->issued_at, $r->expires_at, $status, (string) ($r->notes ?? ''),
                        ]);
                    }
                });
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400, 'No agency context');
        return (int) $first;
    }

    private function assertAgencyAccess(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director'])
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereIn('centre_id', $centres ?: [0]))
            ->exists();
        abort_unless($hasRole, 403);
    }
}
