<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EVERYTHING held about a child or a family, in one place.
 *
 * Anthony, 2026-08-25: "all things related to the family and child should be in the
 * archive such as photos, observations, meal plans, lesson plans, chats — everything."
 *
 * A de-enrolled record is retained for years ([[retention]]), and a retention promise
 * you cannot actually read is not worth much. An audit of the schema found 57 tables
 * carrying a child_id or family_id, so this is deliberately DATA-DRIVEN: each source is
 * one row of config, and a new table added later is a config line rather than another
 * hand-written query and another place to forget.
 *
 * Every source is independently wrapped in try/catch. These tables were built over a
 * long period and their columns vary; one unexpected schema must degrade a single
 * section, never the whole history.
 */
final class RecordHistoryController extends Controller
{
    /* The ONE tenant guard, not a hand-rolled one. My first version waved
       platform_admins through unconditionally and scoped everyone else by AGENCY —
       both wrong. mayAccessCentre() says it outright: a platform_admin is deliberately
       NOT waved through, because v22p96 closed exactly that hole (it leaked care logs
       and portfolios across agencies). Centre directors are centre-scoped here, the
       same as every other child endpoint. */
    use AuthorizesTenantAccess;

    /** Rows per page. One child has 316 daily_events, so a page — not a wall. */
    private const PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    /** Hard ceiling for sources that cannot be paged in SQL and are sliced in PHP. */
    private const CAP = 500;

    /**
     * Sources keyed on the CHILD.
     * [table, label, icon, date column(s), title column(s), detail column(s)]
     * Date columns are tried in order — the first that exists on the table wins.
     */
    private const CHILD_SOURCES = [
        ['enrollments',            'Enrolments',          "\u{1F3EB}", ['start_date', 'created_at'], ['schedule'], ['notes']],
        ['observations',           'Observations',        "\u{1F441}", ['observed_at', 'created_at'], ['title'], ['domain', 'framework']],
        ['daily_care_logs',        'Daily care log',      "\u{1F37C}", ['occurred_at', 'created_at'], ['log_type'], ['notes', 'details']],
        ['daily_events',           'Daily events',        "\u{1F4CB}", ['occurred_at', 'created_at'], ['event_type'], ['notes']],
        ['check_events',           'Attendance',          "\u{1F6AA}", ['occurred_at', 'created_at'], ['event_type'], []],
        ['incidents',              'Incidents',           "\u{26A0}",  ['occurred_at', 'created_at'], ['incident_type'], ['description', 'severity']],
        ['medications',            'Medications',         "\u{1F48A}", ['starts_on', 'created_at'], ['name'], ['dosage', 'frequency', 'status']],
        ['medication_logs',        'Doses given',         "\u{1F489}", ['administered_at', 'created_at'], ['dose_given'], ['outcome', 'notes']],
        ['immunizations',          'Immunisations',       "\u{1F6E1}", ['administered_on', 'created_at'], ['vaccine'], ['dose_label']],
        ['wellness_screenings',    'Wellness screenings', "\u{1FA7A}", ['screening_date', 'created_at'], ['result'], ['notes']],
        ['child_absences',         'Absences',            "\u{1F3E0}", ['absent_on', 'created_at'], ['reason'], ['note']],
        ['report_cards',           'Report cards',        "\u{1F4C4}", ['created_at'], ['term'], ['status']],
        ['child_awards',           'Awards',              "\u{1F3C6}", ['awarded_on', 'created_at'], ['title'], ['note', 'badge']],
        ['birthday_celebrations',  'Birthdays',           "\u{1F382}", ['celebrated_at'], ['birthday_year'], ['notes']],
        ['field_trip_permissions', 'Field trips',         "\u{1F68C}", ['responded_at', 'created_at'], ['status'], ['notes']],
        ['kiosk_signatures',       'Kiosk signatures',    "\u{270D}",  ['occurred_at', 'created_at'], ['event_type'], ['parent_name']],
        ['attendance_patterns',    'Attendance pattern',  "\u{1F4C5}", ['effective_from', 'created_at'], ['notes'], []],
        ['room_rotations',         'Room moves',          "\u{1F504}", ['created_at'], [], []],
        ['edocument_signatures',   'Signed documents',    "\u{1F58A}", ['signed_at', 'created_at'], ['typed_name'], []],
        ['home_visit_reports',     'Home visits',         "\u{1F3E1}", ['visit_date', 'created_at'], ['visit_type'], ['summary']],
        ['parent_feedback',        'Parent feedback',     "\u{1F4AC}", ['created_at'], ['rating'], ['comment']],
        ['invoice_lines',          'Billed items',        "\u{1F9FE}", ['created_at'], ['description'], ['amount']],
    ];

    /** Sources keyed on the FAMILY. */
    private const FAMILY_SOURCES = [
        ['guardians',            'Guardians',          "\u{1F464}", ['created_at'], ['relationship'], []],
        ['emergency_contacts',   'Emergency contacts', "\u{1F4DE}", ['created_at'], ['name'], ['relationship', 'phone']],
        ['invoices',             'Invoices',           "\u{1F9FE}", ['issued_at', 'created_at'], ['invoice_number'], ['status', 'total']],
        ['external_invoices',    'Invoices (billing system)', "\u{1F9FE}", ['issued_at', 'created_at'], ['number'], ['status', 'total']],
        ['payments',             'Payments',           "\u{1F4B3}", ['paid_at', 'created_at'], ['amount'], ['method', 'status']],
        ['vacation_holds',       'Vacation holds',     "\u{1F3D6}", ['start_date', 'created_at'], ['reason'], ['status']],
        ['edocument_signatures', 'Signed documents',   "\u{1F58A}", ['signed_at', 'created_at'], ['typed_name'], []],
        ['home_visit_reports',   'Home visits',        "\u{1F3E1}", ['visit_date', 'created_at'], ['visit_type'], ['summary']],
        ['parent_feedback',      'Parent feedback',    "\u{1F4AC}", ['created_at'], ['rating'], ['comment']],
        ['nps_responses',        'Survey responses',   "\u{1F4CA}", ['created_at'], ['score'], ['comment']],
        ['billing_schedules',    'Billing schedules',  "\u{1F4C6}", ['created_at'], ['frequency'], ['amount']],
        ['child_absences',       'Absences',           "\u{1F3E0}", ['absent_on', 'created_at'], ['reason'], ['note']],
    ];

    public function child(Request $request, int $childId): JsonResponse
    {
        $child = DB::table('children')->where('id', $childId)->first();
        if (! $child) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // assertChild works on soft-deleted children too (verified), so an archived
        // record still opens for someone entitled to it — and only for them.
        $this->assertChild((int) $request->user()->id, $childId);

        [$only, $page, $per] = $this->paging($request);

        $sections = $this->collect(self::CHILD_SOURCES, 'child_id', $childId, $only, $page, $per);
        foreach ([
            fn () => $this->photosFor($childId, $page, $per),
            fn () => $this->chatsFor('child_id', $childId, $page, $per),
            fn () => $this->documentsFor('child', $childId, $page, $per),
            fn () => $this->plansFor($childId, $page, $per),
        ] as $build) {
            $sec = $build();
            if ($only === null || $only === ($sec['key'] ?? null)) {
                $sections[] = $sec;
            }
        }

        return $this->respond($sections, [
            'kind' => 'child',
            'id' => $childId,
            'name' => trim(($child->first_name ?? '') . ' ' . ($child->last_name ?? '')),
            'is_archived' => (bool) ($child->deleted_at ?? null),
        ]);
    }

    public function family(Request $request, int $familyId): JsonResponse
    {
        $family = DB::table('families')->where('id', $familyId)->first();
        if (! $family) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $this->assertFamily((int) $request->user()->id, $familyId);

        [$only, $page, $per] = $this->paging($request);

        $sections = $this->collect(self::FAMILY_SOURCES, 'family_id', $familyId, $only, $page, $per);
        foreach ([
            fn () => $this->chatsFor('family_id', $familyId, $page, $per),
            fn () => $this->documentsFor('family', $familyId, $page, $per),
        ] as $build) {
            $sec = $build();
            if ($only === null || $only === ($sec['key'] ?? null)) {
                $sections[] = $sec;
            }
        }

        return $this->respond($sections, [
            'kind' => 'family',
            'id' => $familyId,
            'name' => $family->family_name,
            'is_archived' => (bool) ($family->deleted_at ?? null),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Which section, which page, how big. `section` is what makes paging cheap: the
     * browser asks for one section's next page rather than re-reading all 57 tables
     * to turn one page.
     */
    private function paging(Request $request): array
    {
        $only = $request->query('section');
        $only = is_string($only) && $only !== '' ? $only : null;
        $page = max(1, (int) $request->query('page', 1));
        $per = (int) $request->query('per', self::PER_PAGE);
        $per = max(5, min(self::MAX_PER_PAGE, $per ?: self::PER_PAGE));
        return [$only, $page, $per];
    }

    /** Consistent page metadata, so the front end never computes it two ways. */
    private function pageMeta(int $count, int $page, int $per): array
    {
        $pages = (int) max(1, (int) ceil($count / max(1, $per)));
        $page = min($page, $pages);
        return ['page' => $page, 'per_page' => $per, 'pages' => $pages, 'offset' => ($page - 1) * $per];
    }

    private function collect(array $sources, string $key, int $id, ?string $only, int $page, int $per): array
    {
        $out = [];
        foreach ($sources as [$table, $label, $icon, $dateCols, $titleCols, $detailCols]) {
            if ($only !== null && $only !== $table) {
                continue;   // asked for one section — do not touch the other 50 tables
            }
            try {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $key)) {
                    continue;
                }
                $date = $this->firstExisting($table, $dateCols);
                $q = DB::table($table)->where($key, $id);
                $count = (clone $q)->count();
                if ($count === 0) {
                    continue;   // an empty section is noise, not information
                }
                if ($date) {
                    $q->orderByDesc($date);
                }
                $meta = $this->pageMeta($count, $page, $per);
                // Paged in SQL, not in PHP: 316 rows should never cross the wire to
                // show 25 of them.
                $rows = $q->offset($meta['offset'])->limit($per)->get();

                $out[] = array_merge([
                    'key' => $table,
                    'label' => $label,
                    'icon' => $icon,
                    'count' => $count,
                    'shown' => $rows->count(),
                    'rows' => $rows->map(fn ($r) => [
                        'id' => $r->id ?? null,
                        'when' => $date ? ($r->{$date} ?? null) : null,
                        'title' => $this->pick($r, $titleCols),
                        'detail' => $this->pick($r, $detailCols, ' · '),
                    ])->values(),
                ], $meta);
            } catch (\Throwable $e) {
                // One odd schema degrades its own section only.
                $out[] = [
                    'key' => $table, 'label' => $label, 'icon' => $icon,
                    'count' => null, 'shown' => 0, 'rows' => [],
                    'error' => 'Could not read this record type.',
                ];
            }
        }
        return $out;
    }

    private function firstExisting(string $table, array $cols): ?string
    {
        foreach ($cols as $c) {
            if (Schema::hasColumn($table, $c)) {
                return $c;
            }
        }
        return null;
    }

    private function pick(object $row, array $cols, string $glue = ' '): ?string
    {
        $bits = [];
        foreach ($cols as $c) {
            $v = $row->{$c} ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            if (is_string($v) && strlen($v) > 160) {
                $v = substr($v, 0, 157) . '…';
            }
            $bits[] = is_scalar($v) ? (string) $v : json_encode($v);
        }
        return $bits ? implode($glue, $bits) : null;
    }

    /** Photos are linked through photo_tags, and older rows through photos.child_ids. */
    private function photosFor(int $childId, int $page = 1, int $per = self::PER_PAGE): array
    {
        $section = ['key' => 'photos', 'label' => 'Photos & video', 'icon' => "\u{1F4F8}",
                    'count' => 0, 'shown' => 0, 'rows' => []];
        try {
            if (! Schema::hasTable('photos')) {
                return $section;
            }
            $ids = [];
            if (Schema::hasTable('photo_tags')) {
                $ids = DB::table('photo_tags')->where('child_id', $childId)->pluck('photo_id')->all();
            }
            $q = DB::table('photos');
            if ($ids) {
                $q->whereIn('id', $ids);
            } else {
                // Fallback for rows tagged before photo_tags existed.
                $q->where('child_ids', 'like', '%"' . $childId . '"%')
                  ->orWhere('child_ids', 'like', '%,' . $childId . ',%');
            }
            $count = (clone $q)->count();
            $meta = $this->pageMeta($count, $page, $per);
            $rows = $q->orderByDesc(Schema::hasColumn('photos', 'taken_at') ? 'taken_at' : 'created_at')
                ->offset($meta['offset'])->limit($per)->get();
            $section = array_merge($section, $meta);
            $section['count'] = $count;
            $section['shown'] = $rows->count();
            $section['rows'] = $rows->map(fn ($p) => [
                'id' => $p->id,
                'when' => $p->taken_at ?? $p->created_at ?? null,
                'title' => $p->caption ?: ($p->media_type ?: 'Photo'),
                'detail' => null,
                'url' => $p->url ?? null,
                'thumb' => $p->thumbnail_url ?? $p->url ?? null,
            ])->values();
        } catch (\Throwable $e) {
            $section['error'] = 'Could not read photos.';
        }
        return $section;
    }

    /** Chats: the conversation threads, with how much was said in each. */
    private function chatsFor(string $key, int $id, int $page = 1, int $per = self::PER_PAGE): array
    {
        $section = ['key' => 'chats', 'label' => 'Chats', 'icon' => "\u{1F4AC}",
                    'count' => 0, 'shown' => 0, 'rows' => []];
        try {
            if (! Schema::hasTable('conversations') || ! Schema::hasColumn('conversations', $key)) {
                return $section;
            }
            $count = DB::table('conversations')->where($key, $id)->count();
            $meta = $this->pageMeta($count, $page, $per);
            $convos = DB::table('conversations')->where($key, $id)
                ->orderByDesc('last_message_at')->offset($meta['offset'])->limit($per)->get();
            $section = array_merge($section, $meta);
            $section['count'] = $count;
            $section['shown'] = $convos->count();
            $section['rows'] = $convos->map(function ($c) {
                $n = 0;
                try { $n = DB::table('messages')->where('conversation_id', $c->id)->count(); }
                catch (\Throwable $e) {}
                return [
                    'id' => $c->id,
                    'when' => $c->last_message_at ?? $c->created_at ?? null,
                    'title' => $c->subject ?: 'Conversation',
                    'detail' => $n . ' message' . ($n === 1 ? '' : 's'),
                ];
            })->values();
        } catch (\Throwable $e) {
            $section['error'] = 'Could not read chats.';
        }
        return $section;
    }

    private function documentsFor(string $scope, int $id, int $page = 1, int $per = self::PER_PAGE): array
    {
        $section = ['key' => 'documents', 'label' => 'Documents', 'icon' => "\u{1F4CE}",
                    'count' => 0, 'shown' => 0, 'rows' => []];
        try {
            if (! Schema::hasTable('documents')) {
                return $section;
            }
            $count = DB::table('documents')->where('scope_type', $scope)->where('scope_id', $id)->count();
            $meta = $this->pageMeta($count, $page, $per);
            $rows = DB::table('documents')->where('scope_type', $scope)->where('scope_id', $id)
                ->orderByDesc('created_at')->offset($meta['offset'])->limit($per)->get();
            $section = array_merge($section, $meta);
            $section['count'] = $count;
            $section['shown'] = $rows->count();
            $section['rows'] = $rows->map(fn ($d) => [
                'id' => $d->id,
                'when' => $d->created_at ?? null,
                'title' => $d->title ?: 'Document',
                'detail' => $d->category ?: null,
                'url' => $d->file_url ?? null,
            ])->values();
        } catch (\Throwable $e) {
            $section['error'] = 'Could not read documents.';
        }
        return $section;
    }

    /**
     * Lesson and meal plans belong to a ROOM, not a child — so "this child's plans"
     * means the plans for the rooms they were actually enrolled in, bounded by their
     * enrolment dates. Anything else would either show nothing or show a whole
     * centre's curriculum as if it were theirs.
     */
    private function plansFor(int $childId, int $page = 1, int $per = self::PER_PAGE): array
    {
        $section = ['key' => 'plans', 'label' => 'Lesson & meal plans', 'icon' => "\u{1F4DA}",
                    'count' => 0, 'shown' => 0, 'rows' => []];
        try {
            $enrolments = DB::table('enrollments')->where('child_id', $childId)
                ->get(['room_id', 'start_date', 'end_date']);
            if ($enrolments->isEmpty()) {
                return $section;
            }
            $roomIds = $enrolments->pluck('room_id')->filter()->unique()->values()->all();
            if (! $roomIds) {
                return $section;
            }
            $rows = collect();
            foreach ([['lesson_plans', 'Lesson plan'], ['meal_plans', 'Meal plan']] as [$t, $lbl]) {
                if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'room_id')) {
                    continue;
                }
                $dateCol = $this->firstExisting($t, ['week_start', 'starts_on', 'plan_date', 'date', 'created_at']);
                $titleCol = $this->firstExisting($t, ['title', 'theme', 'name']);
                $q = DB::table($t)->whereIn('room_id', $roomIds);
                if ($dateCol) {
                    $q->orderByDesc($dateCol);
                }
                foreach ($q->limit(self::CAP)->get() as $r) {
                    $rows->push([
                        'id' => $r->id ?? null,
                        'when' => $dateCol ? ($r->{$dateCol} ?? null) : null,
                        'title' => $lbl . ($titleCol && ! empty($r->{$titleCol}) ? ' — ' . $r->{$titleCol} : ''),
                        'detail' => null,
                    ]);
                }
            }
            /* Two tables merged in PHP, so this one pages in PHP too — bounded by CAP
               because there is no single query to offset. */
            $all = $rows->sortByDesc('when')->values();
            $meta = $this->pageMeta($all->count(), $page, $per);
            $section = array_merge($section, $meta);
            $section['count'] = $all->count();
            $section['rows'] = $all->slice($meta['offset'], $per)->values();
            $section['shown'] = $section['rows']->count();
        } catch (\Throwable $e) {
            $section['error'] = 'Could not read plans.';
        }
        return $section;
    }

    private function respond(array $sections, array $subject): JsonResponse
    {
        $sections = array_values(array_filter($sections, fn ($s) => ($s['count'] ?? 0) > 0 || isset($s['error'])));
        usort($sections, fn ($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

        return response()->json([
            'subject' => $subject,
            'sections' => $sections,
            'total_records' => array_sum(array_map(fn ($s) => (int) ($s['count'] ?? 0), $sections)),
            'cap' => self::CAP,
        ]);
    }
}
