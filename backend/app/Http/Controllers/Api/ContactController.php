<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The agency's contact book.
 *
 * Not emergency_contacts, which belongs to a CHILD — who to call about Aria. This is
 * the agency's own directory: the plumber, the food inspector, the insurance broker,
 * the landlord. People who currently live in one person's phone and leave with them.
 *
 * TENANT SCOPING. Every read and write is confined to the caller's active agency via
 * resolveAgencyId(), and a contact is re-checked against it on the way in rather than
 * trusted from the id that was sent — a client can ask for any id.
 */
final class ContactController extends Controller
{
    use ResolvesCentreContext;

    /** Columns a caller may set. Listed once so store() and update() cannot drift. */
    private const FIELDS = [
        'category', 'first_name', 'last_name', 'company', 'job_title',
        'email', 'phone', 'mobile', 'website', 'preferred_contact',
        'address_line1', 'address_line2', 'city', 'province', 'postal_code', 'country',
        'account_number', 'licence_number', 'hours', 'notes',
        'centre_id', 'is_emergency', 'is_favourite', 'last_contacted_on',
        'card_image_url', 'photo_url',
    ];

    private function rules(): array
    {
        return [
            'category' => 'nullable|string|max:60',
            'first_name' => 'nullable|string|max:80',
            'last_name' => 'nullable|string|max:80',
            'company' => 'nullable|string|max:160',
            'job_title' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'mobile' => 'nullable|string|max:40',
            'website' => 'nullable|string|max:190',
            'preferred_contact' => 'nullable|in:phone,mobile,email,website',
            'address_line1' => 'nullable|string|max:190',
            'address_line2' => 'nullable|string|max:190',
            'city' => 'nullable|string|max:90',
            'province' => 'nullable|string|max:90',
            'postal_code' => 'nullable|string|max:24',
            'country' => 'nullable|string|max:90',
            'account_number' => 'nullable|string|max:80',
            'licence_number' => 'nullable|string|max:80',
            'hours' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:5000',
            'tags' => 'nullable|array|max:20',
            'tags.*' => 'string|max:40',
            'centre_id' => 'nullable|integer',
            'is_emergency' => 'nullable|boolean',
            'is_favourite' => 'nullable|boolean',
            'last_contacted_on' => 'nullable|date_format:Y-m-d',
            'card_image_url' => 'nullable|string|max:500',
            'photo_url' => 'nullable|string|max:500',
        ];
    }

    /** GET /contacts */
    public function index(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['contacts' => [], 'categories' => [], 'meta' => ['total' => 0, 'page' => 1, 'pages' => 1]]);
        }

        // The agency's centres — the guardian lookup below is scoped through them.
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');

        $q = DB::table('contacts')->where('agency_id', $agencyId)->whereNull('deleted_at');

        if ($cat = trim((string) $request->query('category', ''))) {
            $q->where('category', $cat);
        }
        if ($request->query('emergency') === '1') {
            $q->where('is_emergency', 1);
        }
        if ($centre = (int) $request->query('centre_id')) {
            $q->where('centre_id', $centre);
        }

        /* One box searches the whole card — a name, a company, a phone number half
           remembered, a word from the notes. Anything less and people go back to
           their own phone. */
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $q->where(function ($w) use ($like) {
                foreach (['first_name', 'last_name', 'company', 'job_title', 'email',
                          'phone', 'mobile', 'category', 'notes', 'city', 'tags'] as $c) {
                    $w->orWhere($c, 'like', $like);
                }
            });
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));

        $book = $q->get()->map(function ($r) {
            $r->tags = $r->tags ? (json_decode($r->tags, true) ?: []) : [];
            $r->display_name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
            if ($r->display_name === '') { $r->display_name = $r->company ?: '(unnamed contact)'; }
            $r->source = 'book';
            $r->editable = true;

            return $r;
        });

        /* THE PEOPLE ALREADY IN THE SYSTEM.

           A directory that only knows the plumber is half a directory. Staff hold role
           assignments here and parents are guardians of families at these centres —
           they were simply never gathered in one place, so finding an educator's number
           meant a different screen.

           READ-ONLY, and the rows say so. Their record lives on their own account, and
           editing a copy here would create two versions of a phone number with no way
           to tell which is current. */
        $people = collect();
        if ($request->query('only') !== 'book') {
            $staffRows = DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.agency_id', $agencyId)->where('ra.active', 1)
                /* STAFF roles only. A guardian holds a role_assignment too, so without
                   this every parent was listed as Staff — and then won the de-duplication
                   against their own parent row, which is how Amarachi Ihenekwe appeared
                   as an employee. */
                ->whereIn('ra.role', ['educator', 'centre_director', 'agency_admin', 'home_visitor', 'auditor', 'platform_admin', 'sales_rep'])
                ->whereNull('u.deleted_at')
                ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'ra.role', 'ra.centre_id']);

            foreach ($staffRows as $r) {
                $people->push((object) [
                    'id' => 'u' . $r->id, 'user_id' => (int) $r->id,
                    'display_name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: '(no name)',
                    'first_name' => $r->first_name, 'last_name' => $r->last_name,
                    'company' => null, 'job_title' => ucfirst(str_replace('_', ' ', $r->role)),
                    'category' => 'Staff', 'email' => $r->email, 'phone' => $r->phone, 'mobile' => null,
                    'city' => null, 'province' => null, 'notes' => null, 'tags' => [],
                    'centre_id' => $r->centre_id, 'is_emergency' => false, 'card_image_url' => null,
                    'source' => 'staff', 'editable' => false,
                ]);
            }

            $famPhones = DB::table('guardians as g')
                ->join('users as u', 'u.id', '=', 'g.user_id')
                ->join('families as f', 'f.id', '=', 'g.family_id')
                ->whereIn('f.centre_id', $centreIds)
                ->whereNull('u.deleted_at')
                ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone',
                       'f.primary_phone', 'f.family_name', 'f.city', 'f.province', 'f.centre_id']);

            foreach ($famPhones as $r) {
                $people->push((object) [
                    'id' => 'u' . $r->id, 'user_id' => (int) $r->id,
                    'display_name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: '(no name)',
                    'first_name' => $r->first_name, 'last_name' => $r->last_name,
                    'company' => $r->family_name, 'job_title' => null,
                    'category' => 'Parent', 'email' => $r->email,
                    'phone' => $r->phone ?: $r->primary_phone, 'mobile' => null,
                    'city' => $r->city, 'province' => $r->province, 'notes' => null, 'tags' => [],
                    'centre_id' => $r->centre_id, 'is_emergency' => false, 'card_image_url' => null,
                    'source' => 'parent', 'editable' => false,
                ]);
            }

            /* The same person can be a guardian AND an educator — a parent who also
               works here is one of this platform's recurring realities, and appearing
               twice is how somebody rings the wrong number. Staff wins: that role
               carries the work number. */
            $seen = [];
            $people = $people->sortBy(fn ($x) => $x->source === 'staff' ? 0 : 1)
                ->filter(function ($x) use (&$seen) {
                    $key = $x->user_id;
                    if (isset($seen[$key])) { return false; }
                    $seen[$key] = true;

                    return true;
                })->values();

            // The same filters the book obeys, applied to the people.
            if ($cat) { $people = $people->filter(fn ($x) => $x->category === $cat)->values(); }
            if ($request->query('emergency') === '1') { $people = collect(); }
            if ($centre) { $people = $people->filter(fn ($x) => (int) $x->centre_id === $centre)->values(); }
            if ($search) {
                $needle = mb_strtolower($search);
                $people = $people->filter(function ($x) use ($needle) {
                    foreach ([$x->display_name, $x->company, $x->job_title, $x->email, $x->phone, $x->category] as $v) {
                        if ($v !== null && str_contains(mb_strtolower((string) $v), $needle)) { return true; }
                    }

                    return false;
                })->values();
            }
        }

        /* Emergency first, then the book's favourites, then everybody by name — the
           order somebody needs them in, not the order they were typed. */
        $all = $book->concat($people)
            ->sortBy([
                fn ($a, $b) => ((int) ($b->is_emergency ?? 0)) <=> ((int) ($a->is_emergency ?? 0)),
                fn ($a, $b) => ((int) ($b->is_favourite ?? 0)) <=> ((int) ($a->is_favourite ?? 0)),
                fn ($a, $b) => strcasecmp((string) ($a->company ?: $a->display_name), (string) ($b->company ?: $b->display_name)),
            ])->values();

        $total = $all->count();
        $rows = $all->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'contacts' => $rows,
            /* What this agency actually uses, so the picker proposes their own words —
               plus the two the system supplies for the people it already knows. */
            'categories' => DB::table('contacts')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->whereNotNull('category')->where('category', '!=', '')
                ->distinct()->orderBy('category')->pluck('category')
                ->concat(['Staff', 'Parent'])->unique()->sort()->values(),
            'centres' => DB::table('centres')->where('agency_id', $agencyId)->orderBy('name')->get(['id', 'name']),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage,
                       'pages' => max(1, (int) ceil($total / $perPage))],
        ]);
    }

    /** POST /contacts */
    public function store(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);

        $data = $request->validate($this->rules());
        $this->assertCentreBelongs($agencyId, $data['centre_id'] ?? null);

        /* A contact with no name at all is a blank row somebody will delete later —
           but either a person OR a company is enough, because half a drawer of cards
           is a company with a switchboard number. */
        if (trim((string) ($data['first_name'] ?? '')) === ''
            && trim((string) ($data['last_name'] ?? '')) === ''
            && trim((string) ($data['company'] ?? '')) === '') {
            return response()->json(['message' => 'Give the contact a name or a company.'], 422);
        }

        $row = array_intersect_key($data, array_flip(self::FIELDS));
        $row['agency_id'] = $agencyId;
        $row['tags'] = isset($data['tags']) ? json_encode(array_values($data['tags'])) : null;
        $row['created_by_id'] = $request->user()->id;
        $row['created_at'] = now();
        $row['updated_at'] = now();

        $id = DB::table('contacts')->insertGetId($row);
        $this->audit($agencyId, $request, 'contact.created', $id, $row, 'Added');

        return response()->json(['id' => $id], 201);
    }

    /** PATCH /contacts/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        $existing = $this->findScoped($agencyId, $id);

        $data = $request->validate($this->rules());
        $this->assertCentreBelongs($agencyId, $data['centre_id'] ?? null);

        $row = array_intersect_key($data, array_flip(self::FIELDS));
        if (array_key_exists('tags', $data)) {
            $row['tags'] = $data['tags'] === null ? null : json_encode(array_values($data['tags']));
        }
        $row['updated_at'] = now();

        DB::table('contacts')->where('id', $id)->update($row);
        $this->audit($agencyId, $request, 'contact.updated', $id, $row, 'Updated', $existing);

        return response()->json(['ok' => true]);
    }

    /** DELETE /contacts/{id} — soft, because a removed contact is a number nobody has. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        $existing = $this->findScoped($agencyId, $id);

        DB::table('contacts')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->audit($agencyId, $request, 'contact.deleted', $id, [], 'Removed', $existing);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /contacts/scan-card — read a business card.
     *
     * THE CARD IS ALWAYS KEPT, whether or not it can be read. That is the part that
     * cannot fail: the image is stored and handed back so it can be attached to the
     * contact, and it is the evidence behind whatever ends up typed.
     *
     * The parse is best-effort and says WHY when it cannot run. Auto-fill needs the
     * vision model, and this platform's AI credit has been exhausted before — the
     * daily digests fell back to templates and nobody could tell from the screen. A
     * scan button that silently does nothing is worse than one that says "the card is
     * saved, type the details in", so the reason travels back to the UI.
     *
     * NOTHING IS EVER SAVED FROM THE PARSE DIRECTLY. The fields are returned as a
     * SUGGESTION for the form; a person confirms them. A misread phone number that
     * files itself is a wrong number nobody knows is wrong.
     */
    public function scanCard(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);

        $request->validate([
            'card' => 'required|file|mimetypes:image/jpeg,image/png,image/webp,image/heic|max:8192',
        ]);

        $file = $request->file('card');
        $path = $file->storeAs(
            'contact-cards/' . $agencyId . '/' . now()->format('Y/m'),
            uniqid('card-', true) . '.' . ($file->getClientOriginalExtension() ?: 'jpg'),
            'public'
        );
        $url = Storage::disk('public')->url($path);

        $parsed = null;
        $reason = null;

        $key = (string) config('services.anthropic.key', '');
        if ($key === '') {
            $reason = 'Card reading is not configured on this server.';
        } else {
            try {
                $mime = $file->getMimeType() ?: 'image/jpeg';
                $b64 = base64_encode(file_get_contents($file->getRealPath()));

                $res = Http::withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 700,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]],
                            ['type' => 'text', 'text' =>
                                "Read this business card and return ONLY a JSON object, no prose, with any of these keys "
                                . "you can see: first_name, last_name, company, job_title, email, phone, mobile, website, "
                                . "address_line1, city, province, postal_code, country. "
                                . "Omit a key entirely rather than guessing it. Copy text exactly as printed."],
                        ],
                    ]],
                ]);

                if (! $res->successful()) {
                    $body = json_decode((string) $res->body(), true);
                    $reason = 'The card reader refused: '
                        . ($body['error']['message'] ?? ('HTTP ' . $res->status()));
                } else {
                    $text = (string) ($res->json()['content'][0]['text'] ?? '');
                    // The model is asked for bare JSON; a fenced block is still tolerated.
                    if (preg_match('/\{.*\}/s', $text, $m)) {
                        $decoded = json_decode($m[0], true);
                        if (is_array($decoded)) {
                            // Only keys this form actually has — never trust the shape back.
                            $parsed = array_intersect_key($decoded, array_flip([
                                'first_name', 'last_name', 'company', 'job_title', 'email', 'phone',
                                'mobile', 'website', 'address_line1', 'city', 'province', 'postal_code', 'country',
                            ]));
                        }
                    }
                    if (! $parsed) { $reason = 'Nothing readable was found on the card.'; }
                }
            } catch (Throwable $e) {
                report($e);
                $reason = 'The card could not be read: ' . $e->getMessage();
            }
        }

        return response()->json([
            'card_image_url' => $url,
            'parsed' => $parsed,
            'reason' => $reason,
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────
    private function findScoped(int $agencyId, int $id): object
    {
        abort_unless($agencyId, 403);
        $row = DB::table('contacts')->where('id', $id)->whereNull('deleted_at')->first();
        // Re-checked against the active agency rather than trusted from the id sent.
        abort_unless($row && (int) $row->agency_id === $agencyId, 404, 'No such contact.');

        return $row;
    }

    /** A centre named on a contact must belong to the agency making the call. */
    private function assertCentreBelongs(int $agencyId, $centreId): void
    {
        if (! $centreId) { return; }
        $ok = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->exists();
        abort_unless($ok, 422, 'That centre is not in this agency.');
    }

    private function audit(int $agencyId, Request $request, string $action, int $id, array $row, string $verb, ?object $was = null): void
    {
        try {
            $who = trim(($row['first_name'] ?? $was->first_name ?? '') . ' ' . ($row['last_name'] ?? $was->last_name ?? ''));
            $label = $who !== '' ? $who : ($row['company'] ?? $was->company ?? ('contact #' . $id));
            Audit::write([
                'agency_id' => $agencyId,
                'user_id' => $request->user()->id,
                'action' => $action,
                'entity_type' => 'contact',
                'entity_id' => $id,
                'payload' => json_encode([
                    'name' => $label,
                    'company' => $row['company'] ?? $was->company ?? null,
                    'category' => $row['category'] ?? $was->category ?? null,
                    'summary' => $verb . ' contact "' . $label . '"'
                        . (($row['category'] ?? $was->category ?? null) ? ' (' . ($row['category'] ?? $was->category) . ')' : '') . '.',
                ]),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) { /* never fail a save over its own audit row */ }
    }
}
