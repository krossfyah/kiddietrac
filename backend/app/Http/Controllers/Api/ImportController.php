<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\XlsxExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * v22p54 — bulk import for: users, children, families, menu, inspection.
 * GET  /import/templates/{type}     → branded XLSX template
 * POST /import/{type}               → upload xlsx/xls/csv, parse, validate, insert
 */
final class ImportController extends Controller
{
    public function __construct(private XlsxExportService $xlsx) {}

    public function template(Request $request, string $type): Response
    {
        $agencyId = $this->resolveAgencyId($request);
        return match ($type) {
            'users' => $this->xlsx->template($agencyId, 'Users', 'users-import-template.xlsx',
                [
                    ['header' => 'First name *', 'key' => 'first_name', 'width' => 18],
                    ['header' => 'Last name *', 'key' => 'last_name', 'width' => 18],
                    ['header' => 'Email *', 'key' => 'email', 'width' => 30],
                    ['header' => 'Phone', 'key' => 'phone', 'width' => 18],
                    ['header' => 'Role *', 'key' => 'role', 'width' => 18],
                    ['header' => 'Centre ID', 'key' => 'centre_id', 'width' => 10],
                ],
                [
                    ['first_name' => 'Jane', 'last_name' => 'Smith', 'email' => 'jane@example.com', 'phone' => '+14165551234', 'role' => 'educator', 'centre_id' => 1],
                    ['first_name' => 'Mike', 'last_name' => 'Lee', 'email' => 'mike@example.com', 'phone' => '+14165555678', 'role' => 'centre_director', 'centre_id' => 1],
                ],
                [
                    'Required fields marked with * must be filled in.',
                    'Role must be one of: agency_admin, centre_director, educator, guardian.',
                    'Centre ID must match an existing centre in your agency (see Centres screen).',
                    'New users receive a welcome email with a password-set link.',
                    'Existing users (by email) are updated, not duplicated.',
                ]),
            'children' => $this->xlsx->template($agencyId, 'Children', 'children-import-template.xlsx',
                [
                    ['header' => 'First name *', 'key' => 'first_name', 'width' => 18],
                    ['header' => 'Last name *', 'key' => 'last_name', 'width' => 18],
                    ['header' => 'Date of birth *', 'key' => 'date_of_birth', 'width' => 14, 'format' => 'date'],
                    ['header' => 'Family ID *', 'key' => 'family_id', 'width' => 12],
                    ['header' => 'Status', 'key' => 'enrollment_status', 'width' => 14],
                    ['header' => 'Allergies', 'key' => 'allergies', 'width' => 30],
                    ['header' => 'Dietary', 'key' => 'dietary_restrictions', 'width' => 22],
                ],
                [
                    ['first_name' => 'Aria', 'last_name' => 'Patel', 'date_of_birth' => '2022-03-15', 'family_id' => 1, 'enrollment_status' => 'enrolled', 'allergies' => 'Peanuts (anaphylactic)', 'dietary_restrictions' => 'halal'],
                ],
                [
                    'date_of_birth must be in YYYY-MM-DD format.',
                    'family_id must match an existing family in your agency.',
                    'enrollment_status defaults to "waitlist" if blank. Other values: enrolled, withdrawn, prospect.',
                    'Allergies + dietary_restrictions are plain text. Severity goes in parentheses.',
                ]),
            'families' => $this->xlsx->template($agencyId, 'Families', 'families-import-template.xlsx',
                [
                    ['header' => 'Family name *', 'key' => 'family_name', 'width' => 24],
                    ['header' => 'Primary email *', 'key' => 'primary_email', 'width' => 30],
                    ['header' => 'Phone', 'key' => 'primary_phone', 'width' => 18],
                    ['header' => 'Address', 'key' => 'address_line1', 'width' => 30],
                    ['header' => 'City', 'key' => 'city', 'width' => 16],
                    ['header' => 'Province', 'key' => 'province', 'width' => 10],
                    ['header' => 'Postal', 'key' => 'postal_code', 'width' => 10],
                    ['header' => 'Centre ID *', 'key' => 'centre_id', 'width' => 10],
                ],
                [
                    ['family_name' => 'Smith Family', 'primary_email' => 'smith@example.com', 'primary_phone' => '+14165550101', 'address_line1' => '123 Main St', 'city' => 'Toronto', 'province' => 'ON', 'postal_code' => 'M1A 1A1', 'centre_id' => 1],
                ],
                [
                    'Duplicate emails are matched and updated, not duplicated.',
                    'Primary email becomes the login for the first guardian created.',
                    'centre_id must match an existing centre in your agency.',
                ]),
            'menu' => $this->xlsx->template($agencyId, 'Menu items', 'menu-import-template.xlsx',
                [
                    ['header' => 'Week start *', 'key' => 'week_start', 'width' => 14, 'format' => 'date'],
                    ['header' => 'Centre ID *', 'key' => 'centre_id', 'width' => 10],
                    ['header' => 'Day (1-7) *', 'key' => 'day_of_week', 'width' => 10],
                    ['header' => 'Meal type *', 'key' => 'meal_type', 'width' => 18],
                    ['header' => 'Name *', 'key' => 'name', 'width' => 30],
                    ['header' => 'Allergens', 'key' => 'allergens', 'width' => 28],
                ],
                [
                    ['week_start' => '2026-05-25', 'centre_id' => 1, 'day_of_week' => 1, 'meal_type' => 'lunch', 'name' => 'Pasta with marinara', 'allergens' => 'wheat'],
                    ['week_start' => '2026-05-25', 'centre_id' => 1, 'day_of_week' => 1, 'meal_type' => 'morning_snack', 'name' => 'Apple slices + sunbutter', 'allergens' => 'sunflower'],
                ],
                [
                    'Day of week: 1=Monday … 7=Sunday.',
                    'Meal type: breakfast, morning_snack, lunch, afternoon_snack, dinner.',
                    'Items get appended to the existing draft. To replace, save the menu first as draft via Weekly menu screen.',
                ]),
            'inspection' => $this->xlsx->template($agencyId, 'Inspection items', 'inspection-import-template.xlsx',
                [
                    ['header' => 'Category *', 'key' => 'category', 'width' => 22],
                    ['header' => 'Item text *', 'key' => 'item_text', 'width' => 60],
                    ['header' => 'Display order', 'key' => 'display_order', 'width' => 12],
                    ['header' => 'Centre ID (blank = agency-wide)', 'key' => 'centre_id', 'width' => 12],
                ],
                [
                    ['category' => 'Documentation', 'item_text' => 'License visibly posted', 'display_order' => 1, 'centre_id' => null],
                    ['category' => 'Health & safety', 'item_text' => 'EpiPens current + accessible', 'display_order' => 10, 'centre_id' => null],
                ],
                [
                    'Leave centre_id blank to make the item apply to all centres in the agency.',
                    'Categories used so far: Documentation, Staff, Health & safety, Food & nutrition, Indoor environment, Outdoor environment, Programming, Records.',
                    'Items append to your existing checklist; existing items are not affected.',
                ]),
            default => abort(404, 'Unknown template type'),
        };
    }

    public function importUsers(Request $request): JsonResponse { return $this->doImport($request, 'users'); }
    public function importChildren(Request $request): JsonResponse { return $this->doImport($request, 'children'); }
    public function importFamilies(Request $request): JsonResponse { return $this->doImport($request, 'families'); }
    public function importMenu(Request $request): JsonResponse { return $this->doImport($request, 'menu'); }
    public function importInspection(Request $request): JsonResponse { return $this->doImport($request, 'inspection'); }

    private function doImport(Request $request, string $type): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $upload = $request->file('file');

        try {
            $reader = IOFactory::createReaderForFile($upload->getPathname());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($upload->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not read file: ' . $e->getMessage()], 422);
        }

        // Locate header row — find the row containing the first column header pattern
        $headerRow = null;
        $headerMap = [];
        foreach ($rows as $idx => $row) {
            $candidate = [];
            foreach ($row as $col => $cell) {
                if (!$cell) continue;
                $key = $this->normalizeHeader((string) $cell);
                if ($key) $candidate[$col] = $key;
            }
            if (count($candidate) >= 2) {
                $headerRow = $idx;
                $headerMap = $candidate;
                break;
            }
        }
        if (!$headerRow) return response()->json(['error' => 'No header row found'], 422);

        $result = ['inserted' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
        foreach ($rows as $idx => $row) {
            if ($idx <= $headerRow) continue;
            $data = [];
            foreach ($headerMap as $col => $key) {
                $data[$key] = is_string($row[$col] ?? null) ? trim($row[$col]) : ($row[$col] ?? null);
            }
            if (empty(array_filter($data))) continue;
            try {
                $r = $this->dispatchInsert($type, $data, $agencyId, $request->user()->id);
                $result[$r] = ($result[$r] ?? 0) + 1;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = ['row' => $idx, 'message' => $e->getMessage(), 'data' => $data];
            }
        }
        return response()->json($result);
    }

    private function dispatchInsert(string $type, array $row, int $agencyId, int $actorUserId): string
    {
        return match ($type) {
            'users' => $this->insertUser($row, $agencyId),
            'children' => $this->insertChild($row, $agencyId),
            'families' => $this->insertFamily($row, $agencyId),
            'menu' => $this->insertMenuItem($row, $agencyId),
            'inspection' => $this->insertInspection($row, $agencyId),
        };
    }

    private function insertUser(array $row, int $agencyId): string
    {
        if (empty($row['email']) || empty($row['first_name']) || empty($row['role'])) {
            throw new \RuntimeException('email, first_name, role required');
        }
        if (!in_array($row['role'], ['agency_admin', 'centre_director', 'educator', 'guardian'])) {
            throw new \RuntimeException('invalid role');
        }
        $existing = DB::table('users')->where('email', $row['email'])->whereNull('deleted_at')->first();
        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update([
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'] ?? '',
                'phone' => $row['phone'] ?? null,
                'updated_at' => now(),
            ]);
            DB::table('role_assignments')->updateOrInsert(
                ['user_id' => $existing->id, 'role' => $row['role'], 'agency_id' => $agencyId],
                ['centre_id' => $row['centre_id'] ?? null, 'active' => 1, 'created_at' => now()]
            );
            return 'updated';
        }
        $uid = DB::table('users')->insertGetId([
            'email' => $row['email'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'] ?? '',
            'phone' => $row['phone'] ?? null,
            'password' => Hash::make(Str::random(40)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_assignments')->insert([
            'user_id' => $uid, 'role' => $row['role'], 'agency_id' => $agencyId,
            'centre_id' => $row['centre_id'] ?? null, 'active' => 1, 'created_at' => now(),
        ]);
        return 'inserted';
    }

    private function insertChild(array $row, int $agencyId): string
    {
        if (empty($row['first_name']) || empty($row['family_id']) || empty($row['date_of_birth'])) {
            throw new \RuntimeException('first_name, family_id, date_of_birth required');
        }
        $family = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $row['family_id'])->where('c.agency_id', $agencyId)->first();
        if (!$family) throw new \RuntimeException('family_id not in your agency');
        $id = DB::table('children')->insertGetId([
            'family_id' => $row['family_id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'] ?? '',
            'date_of_birth' => $this->normDate($row['date_of_birth']),
            'enrollment_status' => $row['enrollment_status'] ?? 'waitlist',
            'allergies' => $row['allergies'] ?? null,
            'dietary_restrictions' => $row['dietary_restrictions'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return 'inserted';
    }

    private function insertFamily(array $row, int $agencyId): string
    {
        if (empty($row['family_name']) || empty($row['primary_email']) || empty($row['centre_id'])) {
            throw new \RuntimeException('family_name, primary_email, centre_id required');
        }
        $centre = DB::table('centres')->where('id', $row['centre_id'])->where('agency_id', $agencyId)->first();
        if (!$centre) throw new \RuntimeException('centre_id not in your agency');
        $existing = DB::table('families')->where('primary_email', $row['primary_email'])->whereNull('deleted_at')->first();
        if ($existing) {
            DB::table('families')->where('id', $existing->id)->update([
                'family_name' => $row['family_name'],
                'primary_phone' => $row['primary_phone'] ?? null,
                'address_line1' => $row['address_line1'] ?? null,
                'city' => $row['city'] ?? null,
                'province' => $row['province'] ?? null,
                'postal_code' => $row['postal_code'] ?? null,
                'centre_id' => $row['centre_id'],
                'updated_at' => now(),
            ]);
            return 'updated';
        }
        DB::table('families')->insert([
            'family_name' => $row['family_name'],
            'primary_email' => $row['primary_email'],
            'primary_phone' => $row['primary_phone'] ?? null,
            'address_line1' => $row['address_line1'] ?? null,
            'city' => $row['city'] ?? null,
            'province' => $row['province'] ?? null,
            'postal_code' => $row['postal_code'] ?? null,
            'centre_id' => $row['centre_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return 'inserted';
    }

    private function insertMenuItem(array $row, int $agencyId): string
    {
        $week = DB::table('menu_weeks')
            ->where('centre_id', $row['centre_id'])
            ->where('week_start', $this->normDate($row['week_start']))
            ->first();
        if (!$week) {
            $weekId = DB::table('menu_weeks')->insertGetId([
                'centre_id' => $row['centre_id'],
                'week_start' => $this->normDate($row['week_start']),
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else { $weekId = $week->id; }
        DB::table('menu_items')->insert([
            'menu_week_id' => $weekId,
            'day_of_week' => (int) $row['day_of_week'],
            'meal_type' => $row['meal_type'],
            'name' => $row['name'],
            'allergens' => $row['allergens'] ?? null,
        ]);
        return 'inserted';
    }

    private function insertInspection(array $row, int $agencyId): string
    {
        DB::table('inspection_checklist_items')->insert([
            'agency_id' => $agencyId,
            'centre_id' => !empty($row['centre_id']) ? (int) $row['centre_id'] : null,
            'category' => $row['category'],
            'item_text' => $row['item_text'],
            'display_order' => (int) ($row['display_order'] ?? 99),
            'status' => 'pending',
            'is_template' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return 'inserted';
    }

    private function normalizeHeader(string $h): ?string
    {
        $h = strtolower(trim(preg_replace('/[\* ()\.]/', ' ', $h)));
        $h = trim(preg_replace('/\s+/', ' ', $h));
        $map = [
            'first name' => 'first_name', 'last name' => 'last_name',
            'email' => 'email', 'primary email' => 'primary_email',
            'phone' => 'phone', 'primary phone' => 'primary_phone',
            'role' => 'role', 'centre id' => 'centre_id', 'center id' => 'centre_id',
            'family id' => 'family_id', 'family name' => 'family_name',
            'date of birth' => 'date_of_birth', 'dob' => 'date_of_birth',
            'status' => 'enrollment_status', 'enrollment status' => 'enrollment_status',
            'allergies' => 'allergies', 'dietary' => 'dietary_restrictions',
            'address' => 'address_line1', 'city' => 'city',
            'province' => 'province', 'postal' => 'postal_code', 'postal code' => 'postal_code',
            'week start' => 'week_start', 'day' => 'day_of_week', 'day 1 7' => 'day_of_week',
            'day of week' => 'day_of_week', 'meal type' => 'meal_type',
            'name' => 'name', 'allergens' => 'allergens',
            'category' => 'category', 'item text' => 'item_text',
            'display order' => 'display_order',
            'centre id blank agency wide' => 'centre_id',
            'centre id blank = agency-wide' => 'centre_id',
        ];
        return $map[$h] ?? null;
    }

    private function normDate(mixed $v): ?string
    {
        if (!$v) return null;
        if (is_numeric($v)) {
            return Carbon::createFromTimestamp(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float) $v))->toDateString();
        }
        try { return Carbon::parse((string) $v)->toDateString(); } catch (\Throwable $e) { return null; }
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId) return $activeId;
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    private function assertAgencyAdmin(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
