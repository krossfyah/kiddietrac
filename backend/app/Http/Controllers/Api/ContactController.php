<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\PrivateFiles;
use App\Support\ProtectedMedia;
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
            /* The scanned card is never a URL from the client (2026-11-28): scanCard files it on the private disk and
               answers an opaque `card_ref`, which is only accepted back for a file of THIS agency's that exists. */
            'card_ref' => 'nullable|string|max:300',
            /* A picture link the client may set - but never one of our own private or protected files: the response
               signs any /storage/<protected> path it carries, so a stored path to another agency's file was a read. */
            'photo_url' => ['nullable', 'string', 'max:500', function ($attr, $v, $fail) {
                $v = (string) $v;
                if (PrivateFiles::isRef($v) || ProtectedMedia::isProtected($v) || str_contains($v, '/media/')) {
                    $fail('That picture link cannot be used.');
                }
            }],
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
            /* Whether there is a scanned card, never the file: GET /contacts/{id}/card mints a short-lived link. */
            $r->has_card = PrivateFiles::path($r->card_image_url, 'card', (int) $r->agency_id) !== null;
            unset($r->card_image_url);

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
                /* STILL WITH US (2026-11-27, leak guard centre scope). The colleague list stays agency-wide on purpose
                   (KtLeakProbe::CENTRE_SCOPE_OK), but only people who work here today: Live::staffIds drops leavers,
                   staff placed only at closed centres, accounts that are off, service accounts, and KiddieTrac's own
                   platform/sales people, who are not this agency's staff. */
                ->whereIn('u.id', \App\Support\Live::staffIds($agencyId, null) ?: [0])
                ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'ra.role', 'ra.centre_id']);

            foreach ($staffRows as $r) {
                $people->push((object) [
                    'id' => 'u' . $r->id, 'user_id' => (int) $r->id,
                    'display_name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: '(no name)',
                    'first_name' => $r->first_name, 'last_name' => $r->last_name,
                    /* The ROLE is the category. It used to say "Educator" under the name
                       and "Staff" in the category column — two words for one fact,
                       arguing with each other. Said once, in the column built for it,
                       and the filter gains the distinction for nothing. */
                    'company' => null, 'job_title' => null,
                    'category' => ucfirst(str_replace('_', ' ', $r->role)),
                    'email' => $r->email, 'phone' => $r->phone, 'mobile' => null,
                    /* Staff addresses are not held on the user record, so this stays
                       empty rather than showing a half-address from somewhere else. */
                    'address_line1' => null, 'address_line2' => null,
                    'city' => null, 'province' => null, 'postal_code' => null,
                    'notes' => null, 'tags' => [],
                    'centre_id' => $r->centre_id, 'is_emergency' => false, 'card_image_url' => null,
                    'source' => 'staff', 'editable' => false,
                ]);
            }

            /* Parents: the families of OPEN centres only, and for a centre director only their own centres (CentreScope,
               the same rule as meetings/people and every director list of families). Colleagues stay agency-wide. */
            $pScope = \App\Support\CentreScope::ids($request, $agencyId);
            $parentCentres = array_values(array_intersect(\App\Support\Live::centreIds($agencyId),
                $pScope === null ? array_map('intval', $centreIds->all()) : array_map('intval', $pScope)));
            $famPhones = DB::table('guardians as g')
                ->join('users as u', 'u.id', '=', 'g.user_id')
                ->join('families as f', 'f.id', '=', 'g.family_id')
                ->whereIn('f.centre_id', $parentCentres ?: [0])
                ->whereNull('f.deleted_at')->whereNull('f.departure_applied_at')   // Live::families: a family that left is not a contact
                ->whereNull('u.deleted_at')
                ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone',
                       'f.primary_phone', 'f.family_name', 'f.centre_id', 'f.id as family_id',
                       // A household address lives on the family, not the person.
                       'f.address_line1', 'f.address_line2', 'f.city', 'f.province', 'f.postal_code']);

            foreach ($famPhones as $r) {
                $people->push((object) [
                    'id' => 'u' . $r->id, 'user_id' => (int) $r->id,
                    'display_name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: '(no name)',
                    'first_name' => $r->first_name, 'last_name' => $r->last_name,
                    'company' => $r->family_name, 'job_title' => null,
                    'category' => 'Parent', 'email' => $r->email,
                    'phone' => $r->phone ?: $r->primary_phone, 'mobile' => null,
                    'address_line1' => $r->address_line1, 'address_line2' => $r->address_line2,
                    'city' => $r->city, 'province' => $r->province, 'postal_code' => $r->postal_code,
                    'notes' => null, 'tags' => [],
                    'centre_id' => $r->centre_id, 'is_emergency' => false, 'card_image_url' => null,
                    'source' => 'parent', 'editable' => false, 'family_id' => (int) $r->family_id,
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
                /* The roles this agency actually has, plus Parent — so the filter offers
                   the same words the rows show. */
                ->concat(DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', 1)
                    ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'home_visitor', 'auditor', 'platform_admin', 'sales_rep'])
                    ->distinct()->pluck('role')->map(fn ($r) => ucfirst(str_replace('_', ' ', $r))))
                ->concat(['Parent'])->unique()->sort()->values(),
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
        $row = $this->withCard($row, $data, $agencyId);
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
        $row = $this->withCard($row, $data, $agencyId);
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
        /* The private disk (2026-11-28, App\Support\PrivateFiles): a card is a person's name, phone and email.
           contact-cards/<agency>/<Y/m>/ - never under the web root, never a link in the answer. */
        $cardRef = PrivateFiles::put($file, 'card', $agencyId, now()->format('Y/m'));

        $parsed = null;
        $reason = null;

        $key = (string) config('services.anthropic.key', '');
        if ($key === '') {
            $reason = 'Card reading is not configured on this server.';
        } elseif (\App\Support\AiConfig::offFor($agencyId)) {
            // Test agencies and agencies with AI switched off send nothing to the AI (2026-11-28; it never asked).
            $reason = 'Card reading is switched off for this agency.';
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
                    $text = (string) (\App\Support\AiConfig::text($res->json()) ?? '');
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
            'card_ref' => $cardRef,
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

    /**
     * The card a save names: `card_ref` from scanCard, accepted only for a file of this agency's that exists on the
     * private disk. Absent = leave the card as it is; null = take it off the contact.
     */
    private function withCard(array $row, array $data, int $agencyId): array
    {
        if (! array_key_exists('card_ref', $data)) {
            return $row;
        }
        $ref = $data['card_ref'];
        if ($ref === null || $ref === '') {
            $row['card_image_url'] = null;
            return $row;
        }
        $path = PrivateFiles::path((string) $ref, 'card', $agencyId);
        abort_unless($path !== null && PrivateFiles::disk()->exists($path), 422, 'That business card could not be found. Scan it again.');
        $row['card_image_url'] = (string) $ref;

        return $row;
    }

    /** GET /contacts/{id}/card - a link to the scanned card, valid PrivateFiles::TTL_MINUTES. */
    public function card(Request $request, int $id): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        $row = $this->findScoped($agencyId, $id);
        $path = PrivateFiles::path($row->card_image_url, 'card', $agencyId);
        abort_unless($path !== null && PrivateFiles::disk()->exists($path), 404, 'This contact has no business card.');

        return response()->json(PrivateFiles::openAnswer('card', (int) $row->id, $agencyId, (int) $request->user()->id, $path, 'business-card-' . $row->id));
    }

    /**
     * For PrivateFileController: the card's private path when this person, acting in this agency NOW, may still open
     * the contact book (the route's roles, in the ACTIVE agency) and the contact is this agency's. Null otherwise.
     */
    public function cardPathFor(Request $as, int $id, int $agencyId): ?string
    {
        if ((int) $this->resolveAgencyId($as) !== $agencyId) return null;
        if (! array_intersect(\App\Support\AgencyRole::names($as), ['agency_admin', 'centre_director', 'platform_admin'])) return null;
        $row = DB::table('contacts')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();

        return $row ? PrivateFiles::path($row->card_image_url, 'card', $agencyId) : null;
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
