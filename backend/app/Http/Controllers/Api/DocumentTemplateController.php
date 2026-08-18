<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DocumentTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Printable document templates — list, edit, preview, import, activate.
 *
 * Every template body is stored in the safe subset described on DocumentTemplate. Nothing
 * here evaluates a template: import translates, preview renders with sample values, and the
 * PDF routes render with real ones.
 */
class DocumentTemplateController extends Controller
{
    /** The active agency, honoured only where the caller actually holds a role. */
    private function agency(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->get(['role', 'agency_id']);
        $isPlatform = $roles->contains(fn ($r) => $r->role === 'platform_admin');
        $owned = $roles->pluck('agency_id')->filter()->map(fn ($v) => (int) $v)->all();

        if ($header && ($isPlatform || in_array($header, $owned, true))) {
            return $header;
        }
        if ($owned) {
            return (int) $owned[0];
        }
        abort(403, 'No agency access.');
    }

    private function assertAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])->exists();
        abort_unless($ok, 403, 'Administrator access required.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->agency($request);

        $rows = DB::table('document_templates')
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)->orWhereNull('agency_id');
            })
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->orderBy('kind')->orderByDesc('is_active')->orderByDesc('id')
            ->get(['id', 'agency_id', 'kind', 'name', 'is_active', 'source', 'imported_from',
                   'import_notes', 'version', 'updated_at']);

        return response()->json([
            'data' => $rows,
            // What a template of each kind may refer to, so the editor can list them
            // rather than leaving somebody to guess a field name.
            'kinds' => collect(DocumentTemplate::KINDS)->map(fn ($k, $key) => [
                'key' => $key, 'label' => $k['label'], 'vars' => $k['vars'],
            ])->values(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->agency($request);
        $row = DB::table('document_templates')->where('id', $id)
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)->orWhereNull('agency_id');
            })->first();
        abort_unless($row, 404);

        return response()->json(['data' => $row]);
    }

    /** Create a new template, or save a new version of one. */
    public function store(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->agency($request);

        $data = $request->validate([
            'id' => 'nullable|integer',
            'kind' => 'required|string|max:40',
            'name' => 'required|string|max:120',
            'body' => 'required|string|max:120000',
            'styles' => 'nullable|string|max:40000',
            'activate' => 'nullable|boolean',
        ]);
        abort_unless(array_key_exists($data['kind'], DocumentTemplate::KINDS), 422, 'Unknown document kind.');

        $row = [
            'agency_id' => $agencyId,
            'kind' => $data['kind'],
            'name' => $data['name'],
            'body' => $data['body'],
            'styles' => $data['styles'] ?? null,
            'source' => 'edited',
            'created_by_id' => $request->user()->id,
            'updated_at' => now(),
        ];

        if (! empty($data['id'])) {
            $existing = DB::table('document_templates')->where('id', $data['id'])
                ->where('agency_id', $agencyId)->first();
            // A platform default is never edited in place — an agency edit forks its own.
            if ($existing) {
                DB::table('document_templates')->where('id', $existing->id)
                    ->update($row + ['version' => (int) $existing->version + 1]);
                if (! empty($data['activate'])) {
                    $this->activateRow((int) $existing->id, $agencyId, $data['kind']);
                }

                return response()->json(['ok' => true, 'id' => $existing->id]);
            }
        }

        $row['created_at'] = now();
        $id = DB::table('document_templates')->insertGetId($row);
        if (! empty($data['activate'])) {
            $this->activateRow($id, $agencyId, $data['kind']);
        }

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->agency($request);
        $row = DB::table('document_templates')->where('id', $id)
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)->orWhereNull('agency_id');
            })->first();
        abort_unless($row, 404);

        // Activating a platform default for one agency copies it, so another agency's
        // choice is never changed as a side effect.
        if ($row->agency_id === null) {
            $id = DB::table('document_templates')->insertGetId([
                'agency_id' => $agencyId, 'kind' => $row->kind, 'name' => $row->name,
                'body' => $row->body, 'styles' => $row->styles, 'source' => $row->source,
                'imported_from' => $row->imported_from, 'import_notes' => $row->import_notes,
                'version' => 1, 'created_by_id' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->activateRow((int) $id, $agencyId, (string) $row->kind);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    private function activateRow(int $id, int $agencyId, string $kind): void
    {
        DB::table('document_templates')->where('agency_id', $agencyId)->where('kind', $kind)
            ->update(['is_active' => 0]);
        DB::table('document_templates')->where('id', $id)->update(['is_active' => 1, 'updated_at' => now()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->agency($request);
        // Only an agency's own templates can be removed, and never the active one —
        // deleting what a document is currently rendered from is how a payslip stops
        // being reproducible.
        $row = DB::table('document_templates')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404);
        abort_if((bool) $row->is_active, 422, 'That template is in use. Activate another one first.');
        DB::table('document_templates')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Render a body with representative values, so it can be judged before it is used. */
    public function preview(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->agency($request);

        $data = $request->validate([
            'kind' => 'required|string|max:40',
            'body' => 'required|string|max:120000',
            'styles' => 'nullable|string|max:40000',
        ]);
        abort_unless(array_key_exists($data['kind'], DocumentTemplate::KINDS), 422, 'Unknown document kind.');

        $html = DocumentTemplate::render($data['body'], self::sampleData($data['kind'], $agencyId));

        return response()->json(['html' => $html]);
    }

    /** Translate a pasted Blade/HTML template into the safe subset. */
    public function import(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $this->agency($request);

        $data = $request->validate([
            'kind' => 'required|string|max:40',
            'source_name' => 'nullable|string|max:190',
            'body' => 'required|string|max:200000',
            'map' => 'nullable|array',
        ]);
        abort_unless(array_key_exists($data['kind'], DocumentTemplate::KINDS), 422, 'Unknown document kind.');

        // A caller-supplied map is only ever used to rename expressions; it can never
        // introduce anything executable, because the output still goes through the same
        // subset renderer.
        $map = collect($data['map'] ?? [])->filter(fn ($v, $k) => is_string($k) && is_string($v))->all();
        if (! $map && str_contains($data['body'], '$p->')) {
            $map = self::ilearnPayslipMap();   // the shape we already know how to read
        }

        $res = DocumentTemplate::importFromBlade($data['body'], $map, self::derivedTotals());

        return response()->json([
            'ok' => true,
            'body' => $res['body'],
            'notes' => $res['notes'],
            'vars' => DocumentTemplate::KINDS[$data['kind']]['vars'],
        ]);
    }

    /**
     * Arithmetic a template cannot perform, mapped to the total we already supply.
     *
     * A payslip multiplies hours by rate and adds vacation to gross. The renderer does no
     * maths by design, so these are recognised as the named fields instead — which is why
     * regular_amount, ot_amount and gross_with_vacation exist on the payslip kind.
     */
    public static function derivedTotals(): array
    {
        return [
            '$p->hours * $p->rate' => 'regular_amount',
            '$p->ot_hours * $p->rate * $p->ot_mult' => 'ot_amount',
            '$p->gross + $p->vacation' => 'gross_with_vacation',
            'hours * rate' => 'regular_amount',
            'gross + vacation' => 'gross_with_vacation',
        ];
    }

    /** iLearn's payslip expressions → our field names. */
    public static function ilearnPayslipMap(): array
    {
        return [
            '$p->provider_name' => 'payee_name',
            '$p->recipient_type' => 'recipient_type',
            '$p->label' => 'period',
            '$p->status' => 'status',
            '$p->hours' => 'hours',
            '$p->rate' => 'rate',
            '$p->ot_hours' => 'ot_hours',
            '$p->ot_mult' => 'ot_mult',
            '$p->vacation' => 'vacation',
            '$p->gross' => 'gross',
            '$p->cpp' => 'cpp',
            '$p->ei' => 'ei',
            '$p->tax' => 'income_tax',
            '$p->other' => 'other_deductions',
            '$p->notes' => 'notes',
            '$net' => 'net',
            '$slipNo' => 'doc_number',
            '$period' => 'period',
        ];
    }

    /** Representative values for a preview — real agency name, invented figures. */
    public static function sampleData(string $kind, ?int $agencyId): array
    {
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->value('name') : null;
        $base = [
            'agency_name' => $agency ?: 'Your Agency',
            'agency_logo' => '',
            'generated_at' => now()->format('M j, Y g:i A'),
        ];

        if ($kind === 'invoice') {
            return $base + [
                'doc_title' => 'Invoice', 'doc_number' => 'INV-2026-0042',
                'payee_name' => 'Sample Payee', 'payee_role' => 'Provider',
                'issued_at' => 'Aug 1, 2026', 'due_at' => 'Aug 15, 2026',
                'period' => 'Aug 1 – Aug 15, 2026', 'status' => 'Issued',
                'subtotal' => '1,200.00', 'tax_label' => 'HST', 'tax_rate' => '13',
                'tax_amount' => '156.00', 'total' => '1,356.00',
                'notes' => 'Thank you.',
                'lines' => [
                    ['description' => 'Childcare services', 'qty' => '1', 'amount' => '1,000.00'],
                    ['description' => 'Late pickup fee', 'qty' => '2', 'amount' => '200.00'],
                ],
            ];
        }

        return $base + [
            'doc_title' => 'Payslip', 'doc_number' => 'PS-2026-W34-149',
            'payee_name' => 'Sample Educator', 'payee_role' => 'Educator',
            'recipient_type' => 'Provider', 'status' => 'Paid',
            'period' => 'Aug 10 – Aug 23, 2026', 'period_start' => 'Aug 10, 2026', 'period_end' => 'Aug 23, 2026',
            'hours' => '72.50', 'rate' => '24.00', 'regular_amount' => '1,740.00',
            'ot_hours' => '3.00', 'ot_mult' => '1.5', 'ot_amount' => '108.00',
            'vacation' => '74.00', 'gross' => '1,848.00', 'gross_with_vacation' => '1,922.00',
            'cpp' => '96.10', 'ei' => '31.20', 'income_tax' => '284.55',
            'other_deductions' => '0.00', 'net' => '1,510.15',
            'notes' => 'Includes one overtime shift.',
        ];
    }
}
