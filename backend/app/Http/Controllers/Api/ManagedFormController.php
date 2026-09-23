<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Forms Manager — agencies upload a fillable PDF, assign it to roles (parents /
 * educators / home-visitors), and each assigned user opens + e-signs it in the
 * app. Completed sign-offs are tracked in a table with view / download / email.
 *
 * Two tables: managed_forms (the uploaded form + audiences) and
 * managed_form_signoffs (one row per user who signed).
 */
class ManagedFormController extends Controller
{
    use ResolvesCentreContext;

    private const ROLES = ['guardian', 'educator', 'home_visitor', 'centre_director'];

    /**
     * The agency this request operates on.
     *
     * This used to return X-Active-Agency-Id verbatim, with no check that the caller
     * has anything to do with that agency. A stale header therefore filed uploads
     * into someone else's tenant: on 2026-08-12 an agency_admin of agency 2 uploaded
     * two forms while her browser still carried "6", so both landed in Test Agency,
     * invisible to her AND to her educators, with no error to explain it.
     *
     * resolveAgencyId() (ResolvesCentreContext, from the tenant-isolation audit)
     * already does this correctly: the header is honoured only for a platform_admin
     * or a user who actually holds a role in that agency, otherwise it falls back to
     * the caller's own agency. Everything here now goes through it.
     */
    private function agencyId(Request $request): int
    {
        return (int) ($this->resolveAgencyId($request) ?: 0);
    }

    private function roles(int $uid): array
    {
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->pluck('role')->all();
        if (DB::table('guardians')->where('user_id', $uid)->exists()) {
            $roles[] = 'guardian';
        }
        return array_values(array_unique($roles));
    }

    /**
     * WHICH OF THESE ACCOUNTS BELONG TO THIS AGENCY.
     *
     * One email address can hold several accounts here -- that is deliberate, a person
     * can be an educator in one agency and a parent in another -- and those accounts are
     * NOT interchangeable. Resolving a typed address without asking this question sent
     * iLearn's paperwork to a Test Agency home-visitor account and to a stray account
     * with no agency at all, three copies to one inbox, and wrote an iLearn form
     * assignment onto another tenant's record. A recipient never establishes a tenant.
     *
     * Membership is the same shape the Users list uses (role_assignments scoped by
     * agency, or by a centre belonging to it) plus the guardian path, because a parent
     * is attached through their family's centre and may hold no role row at all.
     *
     * Fails CLOSED: an unknown id is simply not returned. (Anthony, 2026-09-09)
     *
     * @param  int[]  $candidateIds
     * @return int[]
     */
    /**
     * A place to hang a signature for somebody who is not on the system yet.
     *
     * Deliberately minimal and deliberately inert:
     *
     *  - THE PASSWORD IS UNUSABLE. A random 64-byte value is hashed in, so the column is
     *    satisfied (it is NOT NULL) and no password on earth matches it. This account
     *    cannot be signed into; it exists so a form can be assigned and signed through a
     *    signed link. Inviting them properly stays a separate, deliberate act
     *    ([[kiddietrac-mailed-password-is-a-key]]).
     *  - `status` is 'not_invited'. That is an ENUM
     *    (active, invited, not_invited, suspended, deactivated) and 'pending' is not in it
     *    - it would have failed with "Data truncated", the same trap as invoice_lines
     *    .line_type and payments.method. 'not_invited' is also the honest word: the
     *    account exists and nobody has been invited to it yet, which is precisely the
     *    state. AuthController::login already rejects anything outside 'active'.
     *  - `onboarded_at` stays null. They have not onboarded; pretending otherwise would
     *    skip the onboarding they will be asked for when they are properly invited.
     *  - The NAME is taken from the address, because there is nothing else to go on, and
     *    a blank name renders as an empty greeting in the form email. It is a first guess
     *    a human corrects later, not a claim about who they are.
     *  - A `role_assignments` row at THIS agency is what makes them findable next time -
     *    agencyMemberIds() reads exactly that - so the second send matches instead of
     *    creating a duplicate.
     *
     * Returns the new user id, or null if the address is unusable.
     */
    private function createPendingRecipient(int $agencyId, string $email, Request $request): ?int
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        /* A name from the local part: "anne.marie.smith@x.com" -> "Anne Marie Smith".
           Better than blank, and obviously provisional to anybody reading it. */
        $local = substr($email, 0, strpos($email, '@') ?: strlen($email));
        $words = array_values(array_filter(preg_split('/[._\-+]+/', $local) ?: []));
        $words = array_map(fn ($w) => ucfirst(preg_replace('/\d+/', '', $w)), $words);
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));
        $first = $words[0] ?? 'New';
        $last = count($words) > 1 ? implode(' ', array_slice($words, 1)) : 'Parent';

        $userId = DB::table('users')->insertGetId([
            'email' => $email,
            // Unusable by construction: nothing hashes to a random 64 bytes.
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'first_name' => mb_substr($first, 0, 80),
            'last_name' => mb_substr($last, 0, 80),
            'status' => 'not_invited',
            'must_change_password' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /* Guardian at this agency, and only this one. */
        /* No updated_at: role_assignments has created_at only. */
        DB::table('role_assignments')->insert([
            'user_id' => $userId,
            'role' => 'guardian',
            'agency_id' => $agencyId,
            'active' => 1,
            'created_at' => now(),
        ]);

        try {
            \App\Support\Audit::write([
                'agency_id' => $agencyId,
                'user_id' => $request->user()->id,
                'action' => 'user.created_for_forms',
                'entity_type' => 'user',
                'entity_id' => $userId,
                'payload' => json_encode([
                    'email' => $email,
                    'name' => trim($first . ' ' . $last),
                    'status' => 'not_invited',
                    'summary' => 'Created a not-yet-invited account for ' . $email
                        . ' so forms could be sent to a parent who is not on the system yet. '
                        . 'It has no usable password and cannot be signed into until they are invited.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* the account stands even if the audit row does not */ }

        return (int) $userId;
    }

    private function agencyMemberIds(int $agencyId, array $candidateIds): array
    {
        $candidateIds = array_values(array_unique(array_filter(array_map('intval', $candidateIds))));
        if (! $agencyId || ! $candidateIds) {
            return [];
        }

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();

        $viaRole = DB::table('role_assignments')
            ->whereIn('user_id', $candidateIds)
            ->where('active', 1)
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('agency_id', $agencyId);
                if ($centreIds) {
                    $q->orWhereIn('centre_id', $centreIds);
                }
            })
            ->pluck('user_id')->all();

        $viaGuardian = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('g.user_id', $candidateIds)
            ->whereNull('f.deleted_at')
            // `?: [0]` -- an agency with no centres must match nobody, never everybody.
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->pluck('g.user_id')->all();

        return array_values(array_unique(array_map('intval', array_merge($viaRole, $viaGuardian))));
    }

    /**
     * Addressed to this user — named individually, or by ROLE audience when nobody
     * is named. See App\Support\FormAudience: naming people NARROWS a form to them.
     *
     * This used to return true if the user was named OR their role matched, which
     * meant picking people in Forms Manager added recipients without ever removing
     * any. A parent's Infant Feeding Plan stayed open to all 99 guardians.
     */
    private function mayUseForm(object $form, int $uid): bool
    {
        return \App\Support\FormAudience::canUse($form, $uid, $this->roles($uid));
    }

    private function isAdmin(Request $request): bool
    {
        return (bool) array_intersect($this->roles((int) $request->user()->id), ['platform_admin', 'agency_admin', 'centre_director']);
    }

    /* ───────────── ADMIN: library ───────────── */

    /** GET /admin/managed-forms — the agency's uploaded forms + sign-off counts. */
    public function index(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['forms' => []], 403);
        }
        $agencyId = $this->agencyId($request);
        $forms = DB::table('managed_forms')->where('agency_id', $agencyId)->orderByDesc('id')->get();
        $counts = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->where('f.agency_id', $agencyId)
            ->select('s.managed_form_id', DB::raw('COUNT(*) as n'))
            ->groupBy('s.managed_form_id')->pluck('n', 's.managed_form_id');
        // Who uploaded each form. created_by_id was already stored but never
        // surfaced, so the library could not answer "who added this, and when".
        $uploaderIds = $forms->pluck('created_by_id')->filter()->unique()->all();
        $uploaders = empty($uploaderIds) ? collect() : DB::table('users')->whereIn('id', $uploaderIds)
            ->get(['id', 'first_name', 'last_name'])->keyBy('id');
        // How many people were named individually (vs reached by role audience).
        $named = DB::table('managed_form_recipients')
            ->whereIn('managed_form_id', $forms->pluck('id')->all())
            ->select('managed_form_id', DB::raw('COUNT(*) as n'))
            ->groupBy('managed_form_id')->pluck('n', 'managed_form_id');
        // The actual ids too, so the Edit dialog can pre-select the people already
        // named — editing was previously unable to show, let alone change, them.
        $namedIds = DB::table('managed_form_recipients')
            ->whereIn('managed_form_id', $forms->pluck('id')->all())
            ->get(['managed_form_id', 'user_id'])
            ->groupBy('managed_form_id')
            ->map(fn ($g) => $g->pluck('user_id')->map(fn ($v) => (int) $v)->values()->all());

        $out = $forms->map(function ($f) use ($counts, $uploaders, $named, $namedIds) {
            $f->audiences = $f->audiences ? (json_decode($f->audiences, true) ?: []) : [];
            $f->signoff_count = (int) ($counts[$f->id] ?? 0);
            $u = $uploaders[$f->created_by_id] ?? null;
            $f->uploaded_by = $u ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) : null;
            $f->named_count = (int) ($named[$f->id] ?? 0);
            $f->recipient_ids = $namedIds[$f->id] ?? [];
            $f->notify_email = $f->notify_email ?? null;
            $f->fillable = (bool) ($f->fillable ?? false);
            $f->reusable = (bool) ($f->reusable ?? false);
            return $f;
        });
        return response()->json(['forms' => $out]);
    }

    /** POST /admin/managed-forms — upload a PDF + assign to roles. */
    public function store(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No active agency.'], 422);
        }
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:190'],
            // Required: a library of bare titles ('test 8') tells an admin nothing about
            // what a form is for, and the Completed tab now shows this column. NOT enforced
            // in update(): the activate/deactivate button PATCHes {active} alone.
            'description' => ['required', 'string', 'max:2000'],
            'file'        => ['required', 'file', 'mimes:pdf', 'max:15360'], // 15 MB
            'audiences'   => ['nullable'],   // optional when specific people are named
            'recipient_ids' => ['nullable'],
            // Opt-in PER FORM, chosen by the admin at upload. Most uploads are
            // read-and-sign notices where typing into the page makes no sense.
            'fillable'    => ['nullable'],
            'reusable'    => ['nullable'],
            // Optional: where a completed copy should be sent. Blank = nobody.
            'notify_email' => ['nullable', 'email', 'max:190'],
        ]);

        $recipientIds = $request->input('recipient_ids');
        if (is_string($recipientIds)) $recipientIds = json_decode($recipientIds, true);
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', (array) $recipientIds))));

        $audiences = $data['audiences'] ?? [];
        if (is_string($audiences)) {
            $audiences = json_decode($audiences, true) ?: array_filter(array_map('trim', explode(',', $audiences)));
        }
        $audiences = array_values(array_intersect((array) $audiences, self::ROLES));
        // NOTE: no longer "audiences required" — a form may instead be addressed to
        // named people. The combined check below enforces that it reaches somebody.

        // A form has to reach SOMEBODY: either a role audience or named people.
        if (empty($audiences) && empty($recipientIds)) {
            return response()->json([
                'message' => 'Choose at least one audience or pick specific people.',
                'errors' => ['audiences' => ['Choose at least one audience or pick specific people.']],
            ], 422);
        }

        $path = $request->file('file')->store('managed-forms/' . $agencyId, 'public');
        $id = DB::table('managed_forms')->insertGetId([
            'agency_id'     => $agencyId,
            'title'         => $data['title'],
            'description'   => $data['description'] ?? null,
            'file_url'      => '/storage/' . $path,
            'fillable'      => filter_var($request->input('fillable', false), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'reusable'      => filter_var($request->input('reusable', false), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'notify_email'  => $data['notify_email'] ?? null,
            'file_type'     => 'application/pdf',
            'file_size'     => $request->file('file')->getSize(),
            'audiences'     => json_encode($audiences),
            'active'        => 1,
            'created_by_id' => (int) $request->user()->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        if (!empty($recipientIds)) {
            $now = now();
            foreach ($recipientIds as $rid) {
                DB::table('managed_form_recipients')->insertOrIgnore([
                    'managed_form_id' => $id, 'user_id' => $rid, 'created_at' => $now,
                ]);
            }
        }
        return response()->json(['id' => $id, 'message' => 'Form uploaded and assigned.']);
    }

    /**
     * POST /admin/managed-forms/bulk-assign — send several forms to several people, once.
     *
     * The library could only ever address ONE form at a time, so onboarding a family meant
     * opening a consent form, picking them, saving, then repeating for the photo permission,
     * the medical form, the trip waiver — and the parent received nothing to tell them any
     * of it had happened. They found out on their next sign-in, if they looked.
     *
     * This assigns the whole set in one go and sends ONE email naming every form in it, so
     * "here is your paperwork" arrives as one thing to work through rather than four
     * separate nudges (or none). Idempotent: assigning a form somebody already has is a
     * no-op, so re-sending a package to add a late arrival cannot duplicate anything.
     *
     * Suppression is deliberately NOT bypassed. A parent at a suppressed agency stays
     * quiet, exactly like every other family-facing email. (Anthony, 2026-09-09)
     */
    public function bulkAssign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'form_ids'   => ['required', 'array', 'min:1'],
            'form_ids.*' => ['integer'],
            /* Either is enough on its own — see the resolve step below. */
            'user_ids'   => ['sometimes', 'array'],
            'user_ids.*' => ['integer'],
            'emails'     => ['sometimes', 'array'],
            'emails.*'   => ['string', 'max:190'],
            'notify'     => ['sometimes', 'boolean'],
            'note'       => ['nullable', 'string', 'max:600'],
        ]);

        $agencyId = $this->agencyId($request);

        /* Scoped to THIS agency before anything is written. The ids arrive from a browser,
           and without this an admin could attach their family to another tenant's form. */
        $forms = DB::table('managed_forms')
            ->whereIn('id', $data['form_ids'])
            ->where('agency_id', $agencyId)
            ->where('active', 1)
            // `fillable` travels with them: the email says "Fill & sign" or "Read & sign"
            // so nobody opens a link expecting to type and finds they cannot.
            ->get(['id', 'title', 'description', 'fillable']);
        if ($forms->isEmpty()) {
            return response()->json(['message' => 'None of those forms are available.'], 422);
        }

        /* Picked people AND typed addresses resolve to the same thing: a user who can
           sign in and sign. A form is assigned to an ACCOUNT — there is nowhere to put a
           signature for an address with nobody behind it — so an unrecognised email is
           reported back rather than silently dropped. Matched case-insensitively on the
           trimmed address, because people type "  Anne@Example.com ". */
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['user_ids'] ?? [])))));
        $typed = array_values(array_filter(array_map(
            fn ($e) => mb_strtolower(trim((string) $e)),
            (array) ($data['emails'] ?? [])
        )));

        /* Ticked people are checked too, not just typed ones. The picker only ever offers
           this agency's people, but ids arrive from a browser and a server that trusts
           the form it sent is not enforcing anything. */
        $ids = $this->agencyMemberIds($agencyId, $ids);

        /* ONE ACCOUNT PER TYPED ADDRESS, and only accounts in THIS agency.

           A shared address is normal here -- a director who is also a parent, two people
           on one family inbox -- so an address can legitimately match several accounts.
           Sending to all of them puts several near-identical emails in one inbox, each
           with a different personal link, and no way to tell which is which. So a typed
           address contributes at most one account, and where the choice is genuinely
           ambiguous the admin is told to pick the person from the list instead.

           Explicitly ticked people are never collapsed this way: choosing two people who
           happen to share an inbox is a decision, and both of them still have to sign. */
        $shared = [];
        $matchedTyped = [];
        if ($typed) {
            $rows = DB::table('users')->whereNull('deleted_at')
                ->whereIn(DB::raw('LOWER(email)'), $typed)
                ->orderBy('id')
                ->get(['id', 'email']);

            $inAgency = $this->agencyMemberIds($agencyId, $rows->pluck('id')->all());

            $byAddress = [];
            foreach ($rows as $r) {
                if (! in_array((int) $r->id, $inAgency, true)) {
                    continue;                       // another tenant's account, or none
                }
                $byAddress[mb_strtolower((string) $r->email)][] = (int) $r->id;
            }

            foreach ($byAddress as $addr => $accountIds) {
                $matchedTyped[] = $addr;
                // Already chosen from the picker? Then that choice IS the answer.
                $already = array_values(array_intersect($accountIds, $ids));
                if ($already) {
                    continue;
                }
                if (count($accountIds) > 1) {
                    $shared[] = $addr;
                }
                $ids[] = $accountIds[0];            // ordered by id, so this is stable
            }
            $ids = array_values(array_unique($ids));
        }
        $unmatched = array_values(array_diff($typed, $matchedTyped));

        /* A PARENT WHO IS NOT ON THE SYSTEM YET IS THE NORMAL CASE (2026-09-17).

           Anthony: "we need to send forms to new parents that are not onboarded or on the
           system yet through this method."

           This used to refuse: "No account at this agency uses that address. A form has to
           be assigned to somebody who can sign in here." True of the data model - a form
           is assigned to a user id and a signature hangs off one - and exactly backwards
           as a product rule. Enrolment paperwork is the FIRST thing a new family is sent,
           before they have an account, which made the one workflow that most needs this
           the one it refused.

           So an unrecognised address gets a placeholder account and the forms go out. The
           signed fill link (/forms/fill/{form}/{user}) needs no login and no onboarding -
           SignedFormController::page() checks only that the form is assigned to that user
           - so the parent can fill and sign before they have ever seen a password.

           WHAT IS DELIBERATELY NOT DONE HERE:
           - No password is set, so the account cannot be signed into. It is a place to
             hang a signature, not an invitation; inviting them properly is a separate,
             explicit act ([[kiddietrac-mailed-password-is-a-key]]).
           - An account at ANOTHER agency with the same address is never reused. One
             address legitimately lives in two agencies ([[kiddietrac-shared-email-cross-agency]]),
             and attaching this agency's paperwork to a stranger's account is the leak that
             memory exists to prevent.
           - No existing account is ever modified. The family wizard once renamed a super
             admin by matching on email ([[kiddietrac-email-is-not-an-identity]]); this only
             ever INSERTs.

           Every creation is audited and named back to the caller, because an admin who
           mistypes an address should be able to see that a placeholder was made. */
        $created = [];
        if ($unmatched) {
            foreach ($unmatched as $addr) {
                try {
                    $newId = $this->createPendingRecipient($agencyId, $addr, $request);
                    if ($newId) {
                        $ids[] = $newId;
                        $created[] = $addr;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Could not create a pending form recipient', [
                        'email' => $addr, 'error' => $e->getMessage(),
                    ]);
                }
            }
            $ids = array_values(array_unique($ids));
            $unmatched = array_values(array_diff($unmatched, $created));
        }

        if (empty($ids)) {
            return response()->json([
                'message' => $unmatched
                    ? 'Could not send to ' . implode(', ', $unmatched) . '. Check the address and try again.'
                    : 'Choose at least one person to send it to.',
                'unmatched' => $unmatched,
            ], 422);
        }

        $users = DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')
            ->get(['id', 'first_name', 'last_name', 'email']);
        if ($users->isEmpty()) {
            return response()->json(['message' => 'None of those people could be found.'], 422);
        }

        $now = now();
        $assigned = 0;
        foreach ($forms as $f) {
            foreach ($users as $u) {
                $existed = DB::table('managed_form_recipients')
                    ->where('managed_form_id', $f->id)->where('user_id', $u->id)->exists();
                DB::table('managed_form_recipients')->insertOrIgnore([
                    'managed_form_id' => $f->id, 'user_id' => $u->id, 'created_at' => $now,
                ]);
                if (! $existed) { $assigned++; }
            }
        }

        $notify = ! array_key_exists('notify', $data) || (bool) $data['notify'];
        $emailed = 0;
        if ($notify) {
            foreach ($users as $u) {
                if (! $u->email) { continue; }
                try {
                    if ($this->emailFormPackage($agencyId, $u, $forms, (string) ($data['note'] ?? ''))) {
                        $emailed++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Form package email failed', ['user' => $u->id, 'error' => $e->getMessage()]);
                }
            }
        }

        /* The send itself, as a row somebody can look at later. The audit log records it
           too, but nobody opens the audit log to answer "did the Hoseins get their
           paperwork". Titles and recipients are SNAPSHOTS: renaming a form next month must
           not rewrite what this package said it contained. */
        try {
            $me = $request->user();
            DB::table('form_package_sends')->insert([
                'agency_id'   => (int) $agencyId,
                'sent_by_id'  => (int) $me->id,
                'sent_by_name' => trim(($me->first_name ?? '') . ' ' . ($me->last_name ?? '')) ?: ($me->email ?? null),
                'form_titles' => json_encode($forms->pluck('title')->values()->all()),
                /* Beside the snapshot, not instead of it. The titles are what the package
                   SAID; these are what it acted on, and what a re-send can act on again
                   without matching names back to records. */
                'form_ids'    => json_encode($forms->pluck('id')->map(fn ($v) => (int) $v)->values()->all()),
                'recipients'  => json_encode($users->map(fn ($u) => [
                    'name'  => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: null,
                    'email' => $u->email,
                ])->values()->all()),
                'recipient_ids' => json_encode($users->pluck('id')->map(fn ($v) => (int) $v)->values()->all()),
                'form_count'      => $forms->count(),
                'recipient_count' => $users->count(),
                'assigned'    => $assigned,
                'emailed'     => $emailed,
                'notified'    => $notify ? 1 : 0,
                'note'        => ($data['note'] ?? null) ?: null,
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Form package send not recorded', ['error' => $e->getMessage()]);
        }

        /* Named, not counted. "Assigned 8" tells an admin nothing they can check later;
           the audit has to say which forms went to which people. */
        try {
            \App\Support\Audit::write([
                'user_id' => (int) $request->user()->id,
                'agency_id' => $agencyId,
                'action'  => 'managed_form.bulk_assign',
                'entity_type' => 'managed_form',
                /* 'payload' — audit_logs has no 'input' column. Audit::write catches its
                   own insert failure and report()s it, so this did NOT break the send: it
                   filed a support ticket and wrote no audit row, quietly, every time a
                   package went out. A swallowed write is worse than a loud one — the
                   action looked audited and was not. (Anthony, 2026-09-09) */
                'payload' => json_encode([
                    'forms' => $forms->pluck('title')->all(),
                    'recipients' => $users->map(fn ($u) => trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: $u->email)->all(),
                    'new_assignments' => $assigned,
                    'emailed' => $emailed,
                    'notify' => $notify,
                ]),
            ]);
        } catch (\Throwable $e) { /* the assignment stands even if the audit row does not */ }

        return response()->json([
            'forms'      => $forms->count(),
            'recipients' => $users->count(),
            'assigned'   => $assigned,
            'emailed'    => $emailed,
            /* Said out loud, never swallowed: typing an address that has no account is the
               easiest way to believe somebody was sent something they were not. */
            'unmatched'  => $unmatched,
            /* Placeholder accounts made for addresses that had none. Named, not counted:
               an admin who mistypes an address needs to see WHICH one was created so they
               can correct it, and a number tells them nothing. */
            'created'    => $created,
            /* A typed address that several accounts here share. Reported rather than
               guessed at: the package went to one of them, and the admin is the only one
               who knows whether that was the person they meant. */
            'shared'     => $shared,
            /* Says what actually happened. "Sent" when nothing was emailed — because the
               agency is suppressed, or nobody had an address — is how a parent ends up
               never hearing from us and nobody knowing. */
            /* BOTH halves, always. The first version reported only the assignment count,
               so re-sending a package to chase people up said "nothing new to assign" while
               it had in fact just emailed every one of them — the one sentence a person
               reads, contradicting the thing that actually happened. Assignment and
               notification are separate outcomes and each has to be stated. */
            'message'    => trim(
                ($assigned === 0
                    ? 'Everyone already had these forms'
                    : $assigned . ' assignment(s) added')
                . ' · '
                . (! $notify
                    ? 'no email sent'
                    : ($emailed === 0
                        ? 'nobody could be emailed'
                        : $emailed . ' of ' . $users->count() . ' emailed'))
                . '.'
                . ($shared
                    ? ' ' . implode(', ', $shared) . ' is used by more than one account here'
                        . ' — it went to one of them. Pick the person from the list to choose.'
                    : '')
            ),
        ]);
    }

    /** GET /admin/managed-forms/packages — what has been sent, newest first. */
    public function packages(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $rows = DB::table('form_package_sends')->where('agency_id', $agencyId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(300)->get();

        /* HOW MUCH OF EACH PACKAGE HAS COME BACK.

           "Sent" is only half the question -- the one an admin actually arrives with is
           "who still owes me paperwork". A package is form_ids x recipient_ids
           signatures; this counts how many of those exist.

           ONE query for the whole page, not one per row. Three hundred sends would
           otherwise be three hundred round trips on a screen somebody opens every
           morning -- the per-row fan-out that saturates this host at drop-off time. */
        $allForms = [];
        $allUsers = [];
        foreach ($rows as $r) {
            foreach (array_map('intval', json_decode((string) $r->form_ids, true) ?: []) as $fid) { $allForms[$fid] = true; }
            foreach (array_map('intval', json_decode((string) $r->recipient_ids, true) ?: []) as $uid) { $allUsers[$uid] = true; }
        }
        $signedPairs = [];
        if ($allForms && $allUsers) {
            foreach (DB::table('managed_form_signoffs')
                ->whereIn('managed_form_id', array_keys($allForms))
                ->whereIn('user_id', array_keys($allUsers))
                ->whereNotNull('signed_at')
                ->get(['managed_form_id', 'user_id']) as $g) {
                $signedPairs[(int) $g->managed_form_id . ':' . (int) $g->user_id] = true;
            }
        }

        /* WAS IT EVEN OPENED (2026-09-17).

           Anthony: "add read receipt to the forms manager table for multiple forms."

           "Sent" and "signed" left a gap an admin kept falling into: a package that shows
           0 of 7 signed might be a family ignoring their paperwork, or an email that
           never arrived - and chasing the first when it is the second annoys a parent who
           never got anything. The open tracker already existed on email_logs
           (tracking_token / opened_at / opens); nothing read it here.

           MATCHED TO THE RIGHT SEND, not merely to the address. A family who is sent two
           packages has two emails, and attaching the newer open to the older package
           would report paperwork as read that nobody has seen. Each send therefore claims
           only the log rows between itself and the NEXT send to that same address.

           ONE query for the page, like the signature counts above: this screen is opened
           every morning and a per-row fan-out is what saturates this host at drop-off. */
        $sendWindows = [];          // email => ascending list of send timestamps
        foreach ($rows as $r) {
            foreach ((json_decode((string) $r->recipients, true) ?: []) as $person) {
                $addr = mb_strtolower(trim((string) ($person['email'] ?? '')));
                if ($addr !== '') { $sendWindows[$addr][] = strtotime((string) $r->created_at); }
            }
        }
        foreach ($sendWindows as $addr => $times) {
            $times = array_values(array_unique($times));
            sort($times);
            $sendWindows[$addr] = $times;
        }

        $opens = [];                // "email|sendTs" => ['opened_at' => ..., 'status' => ...]
        if ($sendWindows) {
            $logs = DB::table('email_logs')
                ->where('agency_id', $agencyId)
                ->whereIn(DB::raw('LOWER(TRIM(to_email))'), array_keys($sendWindows))
                ->where(function ($q) {
                    $q->where('subject', 'like', '%need your signature%')
                      ->orWhere('subject', 'like', '%forms need%');
                })
                ->orderBy('created_at')
                ->get(['to_email', 'subject', 'status', 'opened_at', 'opens', 'created_at']);

            foreach ($logs as $log) {
                $addr = mb_strtolower(trim((string) $log->to_email));
                $at = strtotime((string) $log->created_at);
                $times = $sendWindows[$addr] ?? [];

                /* The send this email belongs to: the latest one at or before it. 60s of
                   slack because the row is written a moment after the send is recorded. */
                $owner = null;
                foreach ($times as $t) {
                    if ($at >= $t - 60) { $owner = $t; } else { break; }
                }
                if ($owner === null) { continue; }

                $key = $addr . '|' . $owner;
                /* A resend supersedes the attempt before it: what matters is whether the
                   LAST attempt arrived and was opened, not that an earlier one failed. */
                $opens[$key] = [
                    'status' => (string) $log->status,
                    'opened_at' => $log->opened_at,
                    'opens' => (int) ($log->opens ?? 0),
                ];
            }
        }

        return response()->json([
            'sends' => $rows->map(function ($r) use ($signedPairs, $opens) {
                $titles = json_decode((string) $r->form_titles, true) ?: [];
                $people = json_decode((string) $r->recipients, true) ?: [];
                $formIds = array_map('intval', json_decode((string) $r->form_ids, true) ?: []);
                $userIds = array_map('intval', json_decode((string) $r->recipient_ids, true) ?: []);

                $slots = count($formIds) * count($userIds);
                $signed = 0;
                foreach ($formIds as $fid) {
                    foreach ($userIds as $uid) {
                        if (isset($signedPairs[$fid . ':' . $uid])) { $signed++; }
                    }
                }

                /* Per recipient, so a package to four people can say which two read it -
                   a single "opened" flag for the package would be true when one of four
                   opened it, which is exactly the family you would then fail to chase. */
                $sendTs = strtotime((string) $r->created_at);
                $read = 0;
                $delivered = 0;
                $people = array_map(function ($person) use ($opens, $sendTs, &$read, &$delivered) {
                    $addr = mb_strtolower(trim((string) ($person['email'] ?? '')));
                    $hit = $opens[$addr . '|' . $sendTs] ?? null;
                    $person['delivery'] = $hit['status'] ?? null;       // sent | suppressed | failed
                    $person['opened_at'] = $hit['opened_at'] ?? null;
                    $person['opens'] = $hit['opens'] ?? 0;
                    if (($hit['status'] ?? '') === 'sent') { $delivered++; }
                    if (! empty($hit['opened_at'])) { $read++; }

                    return $person;
                }, $people);

                return [
                    'id'          => (int) $r->id,
                    'sent_at'     => $r->created_at,
                    'sent_by'     => $r->sent_by_name,
                    /* Counted here so the table does not have to walk the recipients. */
                    'read_count'      => $read,
                    'delivered_count' => $delivered,
                    'forms'       => $titles,
                    'form_count'  => (int) $r->form_count,
                    'recipients'  => $people,
                    'recipient_count' => (int) $r->recipient_count,
                    'assigned'    => (int) $r->assigned,
                    'emailed'     => (int) $r->emailed,
                    'notified'    => (bool) $r->notified,
                    'note'        => $r->note,
                    /* Sends filed before the ids were kept cannot be re-sent faithfully,
                       so the control is absent rather than approximate. Told to the client
                       instead of discovered by pressing it. */
                    'can_resend'  => (bool) ($formIds && $userIds),
                    /* slots = forms x people, i.e. how many signatures this package asked
                       for. Null when the send predates the ids being kept, so the column
                       can say "—" rather than a confident zero. */
                    'slots'       => $slots ?: null,
                    'signed'      => $slots ? $signed : null,
                ];
            })->values(),
        ]);
    }

    /**
     * GET /admin/managed-forms/packages/{id} -- one send, and what came of it.
     *
     * The history row answers "did this go out?". This answers the question that follows:
     * has anybody actually signed? Which is the whole reason an admin sends paperwork,
     * and until now lived in a different tab with no connection to the send.
     *
     * Titles and names come from the SNAPSHOT -- what the package said that day -- while
     * the signing status is looked up live against the ids. The two are shown together
     * on purpose: a form renamed since should still be recognisable as the one that was
     * sent, and still report today's truth about who signed it.
     */
    public function packageDetail(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $agencyId = $this->agencyId($request);
        $r = DB::table('form_package_sends')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $r) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $titles   = json_decode((string) $r->form_titles, true) ?: [];
        $people   = json_decode((string) $r->recipients, true) ?: [];
        $formIds  = array_map('intval', json_decode((string) $r->form_ids, true) ?: []);
        $userIds  = array_map('intval', json_decode((string) $r->recipient_ids, true) ?: []);

        /* Scoped to the agency even though the send already is: a form can be moved or
           deleted between the send and this read, and this must not become a way to read
           a title that no longer belongs here. */
        $forms = $formIds
            ? DB::table('managed_forms')->whereIn('id', $formIds)->where('agency_id', $agencyId)
                ->get(['id', 'title', 'description', 'fillable', 'active', 'file_url', 'file_type'])
                ->keyBy('id')
            : collect();

        $signed = ($formIds && $userIds)
            ? DB::table('managed_form_signoffs')
                ->whereIn('managed_form_id', $formIds)->whereIn('user_id', $userIds)
                ->whereNotNull('signed_at')
                ->get(['managed_form_id', 'user_id', 'signed_at', 'filled_file_url'])
            : collect();
        $signedBy = [];
        foreach ($signed as $g) {
            $signedBy[(int) $g->managed_form_id][(int) $g->user_id] = $g;
        }

        /* The snapshot is the spine. Ids are matched onto it positionally because they
           were written from the same collections in the same order -- and where they are
           absent (an older send) the row still renders, just without live status. */
        $formRows = [];
        foreach ($titles as $i => $t) {
            $fid = $formIds[$i] ?? null;
            $live = $fid !== null ? ($forms[$fid] ?? null) : null;
            $formRows[] = [
                'id'          => $fid,
                'title'       => $t,
                'still_here'  => (bool) $live,
                'active'      => $live ? (bool) $live->active : null,
                'fillable'    => $live ? (bool) $live->fillable : null,
                'description' => $live ? $live->description : null,
                'file_url'    => $live ? $live->file_url : null,
                'signed'      => $fid !== null ? count($signedBy[$fid] ?? []) : 0,
            ];
        }

        $peopleRows = [];
        foreach ($people as $i => $p) {
            $uid = $userIds[$i] ?? null;
            $per = [];
            foreach ($titles as $j => $t) {
                $fid = $formIds[$j] ?? null;
                $hit = ($fid !== null && $uid !== null) ? ($signedBy[$fid][$uid] ?? null) : null;
                $per[] = [
                    'form'      => $t,
                    'signed_at' => $hit->signed_at ?? null,
                    'file_url'  => $hit->filled_file_url ?? null,
                ];
            }
            $peopleRows[] = [
                'id'    => $uid,
                'name'  => $p['name'] ?? null,
                'email' => $p['email'] ?? null,
                'forms' => $per,
            ];
        }

        return response()->json([
            'send' => [
                'id'         => (int) $r->id,
                'sent_at'    => $r->created_at,
                'sent_by'    => $r->sent_by_name,
                'note'       => $r->note,
                'notified'   => (bool) $r->notified,
                'emailed'    => (int) $r->emailed,
                'assigned'   => (int) $r->assigned,
                'can_resend' => (bool) ($formIds && $userIds),
                'forms'      => $formRows,
                'recipients' => $peopleRows,
            ],
        ]);
    }

    /**
     * POST /admin/managed-forms/packages/{id}/resend -- the same package, again.
     *
     * Re-checked, never replayed. The stored ids say what was sent; whether it may be
     * sent again is decided now: a form withdrawn since is dropped, a person who has left
     * the agency is dropped, and a form somebody has ALREADY SIGNED is dropped -- chasing
     * people up must not ask the ones who did it promptly to do it twice.
     *
     * Writes its own history row. A re-send is a thing that happened on the day it
     * happened; folding it into the original would erase both.
     */
    public function packageResend(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $agencyId = $this->agencyId($request);
        $r = DB::table('form_package_sends')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $r) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $formIds = array_map('intval', json_decode((string) $r->form_ids, true) ?: []);
        $userIds = array_map('intval', json_decode((string) $r->recipient_ids, true) ?: []);
        if (! $formIds || ! $userIds) {
            return response()->json([
                'message' => 'This send was recorded before re-sending was possible. Send the forms again from + Send multiple forms.',
            ], 422);
        }

        $forms = DB::table('managed_forms')->whereIn('id', $formIds)
            ->where('agency_id', $agencyId)->where('active', 1)
            ->get(['id', 'title', 'description', 'fillable']);
        if ($forms->isEmpty()) {
            return response()->json(['message' => 'None of those forms are still available.'], 422);
        }

        // Still ours, still here. The same check every send goes through.
        $userIds = $this->agencyMemberIds($agencyId, $userIds);
        $users = $userIds
            ? DB::table('users')->whereIn('id', $userIds)->whereNull('deleted_at')
                ->get(['id', 'first_name', 'last_name', 'email'])
            : collect();
        if ($users->isEmpty()) {
            return response()->json(['message' => 'None of those people are still at this agency.'], 422);
        }

        /* Whoever has signed EVERY form in the package is finished and is not written to
           again. Somebody with one still outstanding is chased, about that one. */
        $done = DB::table('managed_form_signoffs')
            ->whereIn('managed_form_id', $forms->pluck('id'))
            ->whereIn('user_id', $users->pluck('id'))
            ->whereNotNull('signed_at')
            ->get(['managed_form_id', 'user_id']);
        $doneBy = [];
        foreach ($done as $d) {
            $doneBy[(int) $d->user_id][(int) $d->managed_form_id] = true;
        }

        $emailed = 0;
        $skipped = [];
        $sentTo  = [];
        foreach ($users as $u) {
            $outstanding = $forms->reject(fn ($f) => isset($doneBy[(int) $u->id][(int) $f->id]))->values();
            if ($outstanding->isEmpty()) {
                $skipped[] = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: (string) $u->email;
                continue;
            }
            if (! $u->email) {
                continue;
            }
            $sentTo[] = $u;
            try {
                if ($this->emailFormPackage($agencyId, $u, $outstanding, (string) ($r->note ?? ''))) {
                    $emailed++;
                }
            } catch (\Throwable $e) {
                Log::warning('Form package resend failed', ['user' => $u->id, 'error' => $e->getMessage()]);
            }
        }

        if (! $sentTo) {
            return response()->json([
                'message' => 'Everybody on this package has already signed all of it. Nothing was sent.',
                'emailed' => 0, 'skipped' => $skipped,
            ]);
        }

        $me = $request->user();
        try {
            DB::table('form_package_sends')->insert([
                'agency_id'    => (int) $agencyId,
                'sent_by_id'   => (int) $me->id,
                'sent_by_name' => trim(($me->first_name ?? '') . ' ' . ($me->last_name ?? '')) ?: ($me->email ?? null),
                'form_titles'  => json_encode($forms->pluck('title')->values()->all()),
                'form_ids'     => json_encode($forms->pluck('id')->map(fn ($v) => (int) $v)->values()->all()),
                'recipients'   => json_encode(collect($sentTo)->map(fn ($u) => [
                    'name'  => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: null,
                    'email' => $u->email,
                ])->values()->all()),
                'recipient_ids' => json_encode(collect($sentTo)->pluck('id')->map(fn ($v) => (int) $v)->values()->all()),
                'form_count'      => $forms->count(),
                'recipient_count' => count($sentTo),
                'assigned'     => 0,      // nothing new to assign; this is a reminder
                'emailed'      => $emailed,
                'notified'     => 1,
                'note'         => ($r->note ?: null),
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Form package resend not recorded', ['error' => $e->getMessage()]);
        }

        try {
            \App\Support\Audit::write([
                'user_id'     => (int) $me->id,
                'agency_id'   => $agencyId,
                'action'      => 'managed_form.package_resent',
                'entity_type' => 'form_package_send',
                'entity_id'   => $id,
                'payload'     => json_encode([
                    'forms'      => $forms->pluck('title')->all(),
                    'recipients' => collect($sentTo)->map(fn ($u) => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email)->all(),
                    'skipped_already_signed' => $skipped,
                    'emailed'    => $emailed,
                ]),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'emailed' => $emailed,
            'skipped' => $skipped,
            'message' => $emailed . ' of ' . count($sentTo) . ' emailed again'
                . ($skipped ? ' · ' . count($skipped) . ' had already signed everything' : '') . '.',
        ]);
    }

    /**
     * DELETE /admin/managed-forms/signoffs/{id} -- withdraw a signature.
     *
     * This is the one destructive action in the Forms Manager that removes a RECORD rather
     * than a note about one, so it is deliberately narrow:
     *
     *   · admins and directors only, and only within their own agency
     *   · the completed PDF goes with it, because a flattened form carrying somebody's
     *     signature is not a file to leave lying on disk once the signature is withdrawn
     *   · the filed copy on the signer's record goes too, or their Documents screen would
     *     keep offering a form the agency no longer holds
     *   · and it is audited in FULL -- who signed, what, when, and who removed it. Deleting
     *     the evidence that something was signed is exactly the action a record has to keep.
     *
     * A non-reusable form becomes outstanding for that person again, which is the point:
     * this is what you reach for when the wrong person signed, or signed the wrong thing.
     * (Anthony, 2026-09-10)
     */
    public function deleteSignoff(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $agencyId = $this->agencyId($request);

        $row = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $id)
            ->where('f.agency_id', $agencyId)      // another tenant's signature is not yours to remove
            ->first([
                's.id', 's.user_id', 's.signer_name', 's.signed_at', 's.filled_file_url',
                'f.id as form_id', 'f.title', 'f.reusable',
                'u.first_name', 'u.last_name', 'u.email',
            ]);
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $who = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''))
            ?: ($row->signer_name ?: ($row->email ?: 'Someone'));

        // The filed copy on the signer's own record.
        $docs = DB::table('documents')
            ->where('source_type', \App\Support\SignedFormFiler::SOURCE)
            ->where('source_id', $id)
            ->get(['id', 'file_url']);
        foreach ($docs as $d) {
            DB::table('documents')->where('id', $d->id)->delete();
        }

        // The flattened PDF itself.
        $removedFile = false;
        if ($row->filled_file_url) {
            $rel = ltrim(str_replace('/storage/', '', (string) $row->filled_file_url), '/');
            try {
                if ($rel !== '' && Storage::disk('public')->exists($rel)) {
                    Storage::disk('public')->delete($rel);
                    $removedFile = true;
                }
            } catch (\Throwable $e) {
                // The row still goes. A file left behind is a tidiness problem, not a record one.
            }
        }

        DB::table('managed_form_signoffs')->where('id', $id)->delete();

        try {
            \App\Support\Audit::write([
                'user_id'     => (int) $request->user()->id,
                'agency_id'   => $agencyId,
                'action'      => 'managed_form.signoff_deleted',
                'entity_type' => 'managed_form',
                'entity_id'   => (int) $row->form_id,
                /* Everything the deleted record said. An audit line reading "a sign-off was
                   deleted" cannot answer the question anybody asks it afterwards. */
                'payload'     => json_encode([
                    'signoff_id'   => $id,
                    'form'         => $row->title,
                    'signed_by'    => $who,
                    'signer_email' => $row->email,
                    'signer_id'    => (int) $row->user_id,
                    'signed_at'    => $row->signed_at,
                    'pdf_removed'  => $removedFile,
                    'documents_removed' => $docs->count(),
                ]),
                'ip_address'  => $request->ip(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'ok' => true,
            'message' => 'Signature withdrawn. “' . $row->title . '” is outstanding for ' . $who . ' again.',
        ]);
    }

    /**
     * POST /admin/managed-forms/packages/{id}/redeliver -- send the COMPLETED copies again.
     *
     * Different from resend, and easy to confuse, so: resend chases people who have NOT
     * signed; this one deals with what HAS been signed, mailing the finished copies to the
     * agency and back to the person who signed them. That is the request an admin makes
     * when a completed form needs to reach a director who has just taken over, or when a
     * parent says they never kept their copy.
     *
     * Sends nothing when nothing has been signed yet -- and says so, rather than reporting
     * a cheerful zero.
     */
    public function packageRedeliver(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $agencyId = $this->agencyId($request);
        $r = DB::table('form_package_sends')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $r) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $formIds = array_map('intval', json_decode((string) $r->form_ids, true) ?: []);
        $userIds = array_map('intval', json_decode((string) $r->recipient_ids, true) ?: []);
        if (! $formIds || ! $userIds) {
            return response()->json([
                'message' => 'This send was recorded before its contents could be identified, so its copies cannot be re-sent.',
            ], 422);
        }

        // Still ours. A person who has left the agency does not get their file posted on.
        $userIds = $this->agencyMemberIds($agencyId, $userIds);
        $formIds = DB::table('managed_forms')->whereIn('id', $formIds)
            ->where('agency_id', $agencyId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (! $userIds || ! $formIds) {
            return response()->json(['message' => 'Nothing in this package is still available to send.'], 422);
        }

        $signoffs = DB::table('managed_form_signoffs')
            ->whereIn('managed_form_id', $formIds)->whereIn('user_id', $userIds)
            ->whereNotNull('signed_at')
            ->orderBy('id')
            ->pluck('id');

        if ($signoffs->isEmpty()) {
            return response()->json([
                'message' => 'None of these forms have been signed yet, so there are no completed copies to send. Use Send reminder instead.',
            ], 422);
        }

        /* The SAME pair of letters a fresh signature produces -- a receipt to the signer
           and a notice to the office -- rather than a second format that would drift from
           it. See App\Support\FormSubmissionNotice. */
        foreach ($signoffs as $sid) {
            \App\Support\FormSubmissionNotice::send((int) $sid);
        }

        try {
            \App\Support\Audit::write([
                'user_id'     => (int) $request->user()->id,
                'agency_id'   => $agencyId,
                'action'      => 'managed_form.package_copies_resent',
                'entity_type' => 'form_package_send',
                'entity_id'   => $id,
                'payload'     => json_encode([
                    'forms'     => json_decode((string) $r->form_titles, true) ?: [],
                    'signoffs'  => $signoffs->values()->all(),
                    'count'     => $signoffs->count(),
                ]),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'sent'    => $signoffs->count(),
            'message' => $signoffs->count() . ' completed form(s) sent again — to the agency and to whoever signed each one.',
        ]);
    }

    /**
     * POST /admin/managed-forms/packages/{id}/resend-one -- ONE form, ONE person.
     *
     * "Send reminder" chases everything outstanding for everybody, which is right when a
     * package is generally late and wrong when one person's one form came back wrong or
     * never arrived. Chasing four people about three forms because one of them muddled a
     * date is how a reminder becomes noise.
     *
     * Re-checked like every other send: the form must still be live and in this agency,
     * the person must still be here, and a form they have ALREADY signed is refused rather
     * than quietly re-sent -- if a signed form needs doing again, the signature has to be
     * withdrawn first, deliberately.
     */
    public function packageResendOne(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $agencyId = $this->agencyId($request);
        $r = DB::table('form_package_sends')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $r) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validate([
            'form_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
        ]);

        // It has to be part of THIS package, not any form the caller can name.
        $formIds = array_map('intval', json_decode((string) $r->form_ids, true) ?: []);
        $userIds = array_map('intval', json_decode((string) $r->recipient_ids, true) ?: []);
        if (! in_array((int) $data['form_id'], $formIds, true) || ! in_array((int) $data['user_id'], $userIds, true)) {
            return response()->json(['message' => 'That form and person are not part of this send.'], 422);
        }

        $form = DB::table('managed_forms')->where('id', $data['form_id'])
            ->where('agency_id', $agencyId)->where('active', 1)
            ->first(['id', 'title', 'description', 'fillable']);
        if (! $form) {
            return response()->json(['message' => 'That form is no longer available.'], 422);
        }

        if (! $this->agencyMemberIds($agencyId, [(int) $data['user_id']])) {
            return response()->json(['message' => 'That person is no longer at this agency.'], 422);
        }
        $user = DB::table('users')->where('id', $data['user_id'])->whereNull('deleted_at')
            ->first(['id', 'first_name', 'last_name', 'email']);
        if (! $user || ! $user->email) {
            return response()->json(['message' => 'That person has no email address on file.'], 422);
        }

        $signed = DB::table('managed_form_signoffs')
            ->where('managed_form_id', $form->id)->where('user_id', $user->id)
            ->whereNotNull('signed_at')->exists();
        if ($signed) {
            return response()->json([
                'message' => 'They have already signed that form. Use "Resend completed copies" to send it on, or remove the signature first if it needs doing again.',
            ], 422);
        }

        /* Named on the form as well as emailed. A link is a capability, but the assignment
           is what makes the form appear in their own Forms to sign -- and a re-send that
           only emails would leave those two disagreeing. */
        DB::table('managed_form_recipients')->insertOrIgnore([
            'managed_form_id' => $form->id, 'user_id' => $user->id, 'created_at' => now(),
        ]);

        $ok = false;
        try {
            $ok = $this->emailFormPackage($agencyId, $user, collect([$form]), (string) ($r->note ?? ''));
        } catch (\Throwable $e) {
            Log::warning('Single form resend failed', ['user' => $user->id, 'form' => $form->id, 'error' => $e->getMessage()]);
        }

        $who = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) $user->email;

        try {
            \App\Support\Audit::write([
                'user_id'     => (int) $request->user()->id,
                'agency_id'   => $agencyId,
                'action'      => 'managed_form.resent_single',
                'entity_type' => 'managed_form',
                'entity_id'   => (int) $form->id,
                'payload'     => json_encode([
                    'form' => $form->title, 'recipient' => $who,
                    'package_send' => $id, 'emailed' => $ok,
                ]),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'ok'      => $ok,
            'message' => $ok
                ? '“' . $form->title . '” sent again to ' . $who . '.'
                : 'Could not email ' . $who . ' — the form is still assigned to them.',
        ]);
    }

    /**
     * DELETE /admin/managed-forms/packages/{id} -- remove the history row.
     *
     * The row ONLY. Assignments stand, signatures stand, and the forms are untouched --
     * deleting a record of a send cannot un-send it, and pretending otherwise would be
     * the most dangerous button on the screen. Audited in full, because removing the
     * evidence that something was sent is exactly the action a record needs to keep.
     */
    public function packageDelete(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $agencyId = $this->agencyId($request);
        $r = DB::table('form_package_sends')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $r) {
            return response()->json(['message' => 'Not found'], 404);
        }

        DB::table('form_package_sends')->where('id', $id)->where('agency_id', $agencyId)->delete();

        try {
            \App\Support\Audit::write([
                'user_id'     => (int) $request->user()->id,
                'agency_id'   => $agencyId,
                'action'      => 'managed_form.package_send_deleted',
                'entity_type' => 'form_package_send',
                'entity_id'   => $id,
                /* Everything the deleted row said, not a count. An audit entry that
                   records only "a send was deleted" cannot answer the question anybody
                   would ask afterwards. */
                'payload'     => json_encode([
                    'sent_at'    => $r->created_at,
                    'sent_by'    => $r->sent_by_name,
                    'forms'      => json_decode((string) $r->form_titles, true) ?: [],
                    'recipients' => json_decode((string) $r->recipients, true) ?: [],
                    'emailed'    => (int) $r->emailed,
                    'note'       => $r->note,
                ]),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true, 'message' => 'Removed from the history. The forms and any signatures are untouched.']);
    }

    /** One branded email listing the whole package. Returns whether it was handed off. */
    private function emailFormPackage(?int $agencyId, $user, $forms, string $note): bool
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $count = $forms->count();

        /* A LINK PER FORM, not one link to a login screen.

           Each is a temporary signed URL that opens THAT form for THIS person, fillable and
           signable without a password — the people being onboarded are exactly the ones
           least likely to have a working one, which is where the paperwork used to stall.
           The signature covers the form and the recipient, so a link cannot be edited into
           somebody else's form, and it expires. See SignedFormController. */
        $rows = '';
        foreach ($forms as $f) {
            $desc = trim((string) ($f->description ?? ''));
            $link = \App\Http\Controllers\Api\SignedFormController::linkFor((int) $f->id, (int) $user->id);
            $rows .= '<tr><td style="padding:12px 0;border-bottom:1px solid #EEF2F7;">'
                . '<div style="font-size:14.5px;font-weight:700;color:#0F172A;">' . $e($f->title) . '</div>'
                . ($desc ? '<div style="font-size:13px;color:#64748B;margin-top:2px;line-height:1.5;">' . $e($desc) . '</div>' : '')
                . '<div style="margin-top:8px;"><a href="' . $e($link) . '" '
                . 'style="display:inline-block;background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;'
                . 'text-decoration:none;font-weight:700;font-size:13px;padding:8px 16px;border-radius:8px;">'
                . ($f->fillable ? 'Fill &amp; sign this form' : 'Read &amp; sign this form') . '</a></div>'
                . '</td></tr>';
        }

        $body = '<table width="100%" cellpadding="0" cellspacing="0" role="presentation">'
            . '<tr><td style="font-size:14px;line-height:1.6;color:#334155;padding:0 0 6px;">'
            . 'Hello' . ($name ? ' ' . $e($name) : '') . ',</td></tr>'
            . '<tr><td style="font-size:14px;line-height:1.6;color:#334155;padding:0 0 14px;">'
            . 'There ' . ($count === 1 ? 'is <strong>1 form</strong>' : 'are <strong>' . $count . ' forms</strong>')
            . ' waiting for you to review and sign.</td></tr>'
            . ($note !== '' ? '<tr><td style="padding:0 0 14px;font-size:14px;line-height:1.6;color:#334155;background:#F8FAFC;border-left:3px solid #CBD5E1;padding-left:12px;">' . $e($note) . '</td></tr>' : '')
            . '<tr><td><table width="100%" cellpadding="0" cellspacing="0" role="presentation">' . $rows . '</table></td></tr>'
            . '<tr><td style="padding:20px 0 0;">'
            . '<a href="https://app.kiddietrac.com/dashboard.html#my-forms" '
            . 'style="display:inline-block;background:#1F6080;color:#fff;text-decoration:none;font-weight:700;'
            . 'font-size:15px;padding:13px 26px;border-radius:10px;">Open them in the portal</a></td></tr>'
            . '<tr><td style="padding:16px 0 0;font-size:12.5px;color:#94A3B8;line-height:1.5;">'
            . 'You can sign them in any order, and come back to finish later. The links above are '
            . 'personal to you and expire in ' . \App\Http\Controllers\Api\SignedFormController::LINK_DAYS . ' days.</td></tr>'
            . '</table>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'    => 'FORMS TO SIGN',
            'title'      => $count === 1 ? 'A form needs your signature' : $count . ' forms need your signature',
            'subtitle'   => 'Review and sign them in the portal',
            'preheader'  => $count . ' form' . ($count === 1 ? '' : 's') . ' waiting for your signature',
        ]);

        $subject = $count === 1
            ? 'A form needs your signature'
            : $count . ' forms need your signature';

        AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($user, $name, $subject, $agencyId) {
            $m->to($user->email, $name ?: null)->subject($subject);
            /* The gate is agency-scoped and reads this header; without it one agency's
               OFF switch can silence another agency's mail to a shared address. */
            try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId); } catch (\Throwable $e2) {}
            /* TRANSACTIONAL, NOT ENGAGEMENT (2026-09-17).

               Without this, a form package to a brand-new parent is cancelled by the
               not-onboarded gate in SuppressAgencyMail - which is what happened to
               Safia's 7-form send: the forms were assigned and the email never left.

               A form package is the paperwork a family is asked to sign BEFORE they have
               an account, and its links are signed and need no login. The gate reads this
               header and lets it through; every other gate, and every other kind of mail
               to an unclaimed account, is unaffected. */
            try { $m->getHeaders()->addTextHeader('X-KT-Form-Package', '1'); } catch (\Throwable $e2) {}
        });

        return true;
    }

    /** PATCH /admin/managed-forms/{id} — toggle active / edit audiences. */
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);
        $form = DB::table('managed_forms')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $form) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $patch = ['updated_at' => now()];
        if ($request->has('active')) {
            $patch['active'] = $request->boolean('active') ? 1 : 0;
        }
        if ($request->has('title')) {
            $patch['title'] = (string) $request->input('title');
        }
        // description was not editable, so a typo in it meant re-uploading the PDF.
        if ($request->has('description')) {
            $patch['description'] = (string) $request->input('description');
        }
        if ($request->has('audiences')) {
            $aud = $request->input('audiences');
            if (is_string($aud)) $aud = json_decode($aud, true) ?: [];
            $patch['audiences'] = json_encode(array_values(array_intersect((array) $aud, self::ROLES)));
        }
        // Everything chosen at upload has to be changeable afterwards. These two were
        // upload-only, so getting a toggle wrong meant deleting the form and
        // re-uploading the PDF just to flip a boolean.
        if ($request->has('fillable')) {
            $patch['fillable'] = filter_var($request->input('fillable'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if ($request->has('reusable')) {
            $patch['reusable'] = filter_var($request->input('reusable'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if ($request->has('notify_email')) {
            $em = trim((string) $request->input('notify_email'));
            // Clearing the field switches the notification off again.
            $patch['notify_email'] = ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) ? $em : null;
        }
        // Named recipients, likewise editable. Sent as the COMPLETE list (the picker
        // shows the current selection), so this replaces rather than appends —
        // otherwise removing somebody would be impossible.
        $newRecipients = null;
        if ($request->has('recipient_ids')) {
            $ids = $request->input('recipient_ids');
            if (is_string($ids)) $ids = json_decode($ids, true);
            $newRecipients = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        }

        // Check BEFORE writing: an edit must not leave the form with nobody to sign
        // it. Validating afterwards would mean rejecting a change already applied.
        $audAfter = array_key_exists('audiences', $patch)
            ? (json_decode($patch['audiences'], true) ?: [])
            : ($form->audiences ? (json_decode($form->audiences, true) ?: []) : []);
        $namedAfter = $newRecipients !== null
            ? count($newRecipients)
            : DB::table('managed_form_recipients')->where('managed_form_id', $id)->count();
        if (empty($audAfter) && $namedAfter === 0) {
            return response()->json([
                'message' => 'That would leave the form with nobody to sign it — choose an audience or pick specific people.',
                'errors' => ['audiences' => ['Choose at least one audience or pick specific people.']],
            ], 422);
        }

        DB::table('managed_forms')->where('id', $id)->update($patch);

        if ($newRecipients !== null) {
            $existing = DB::table('managed_form_recipients')->where('managed_form_id', $id)
                ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            $remove = array_diff($existing, $newRecipients);
            $add    = array_diff($newRecipients, $existing);
            if ($remove) {
                DB::table('managed_form_recipients')->where('managed_form_id', $id)
                    ->whereIn('user_id', $remove)->delete();
            }
            foreach ($add as $rid) {
                DB::table('managed_form_recipients')->insertOrIgnore([
                    'managed_form_id' => $id, 'user_id' => $rid, 'created_at' => now(),
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /** DELETE /admin/managed-forms/{id}. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);
        $form = DB::table('managed_forms')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $form) {
            return response()->json(['message' => 'Not found'], 404);
        }
        DB::table('managed_form_signoffs')->where('managed_form_id', $id)->delete();
        DB::table('managed_forms')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /** GET /admin/managed-forms/signoffs — completed sign-offs for the agency. */
    public function signoffs(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['signoffs' => []], 403);
        }
        $agencyId = $this->agencyId($request);
        $rows = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            /* Did this actually reach the signer's record? Filing is best-effort at
               signature time, so "completed" and "filed" are genuinely different facts
               and the tab was only ever showing the first one. */
            ->leftJoin('documents as doc', function ($j) {
                $j->on('doc.source_id', '=', 's.id')
                  ->where('doc.source_type', '=', \App\Support\SignedFormFiler::SOURCE);
            })
            /* WHO THE DOCUMENT WAS ACTUALLY FILED TO.

               A documents row existing is not the same as somebody being able to reach
               it. The row is scoped to a user id, and if that account is gone the file
               is filed onto nothing - so the tab has to check the target, not just the
               row. */
            ->leftJoin('users as du', 'du.id', '=', 'doc.scope_id')
            ->where('f.agency_id', $agencyId)
            /* A DRAFT IS NOT A COMPLETED FORM.

               This list had no signed_at filter, so forms somebody opened and abandoned
               were listed under "Completed" with no signed date - 10 of them on iLearn
               alone. They also reported as "Not filed", which is true and meaningless: a
               draft is not supposed to be filed anywhere. */
            ->whereNotNull('s.signed_at')
            ->orderByDesc('s.signed_at')
            ->select([
                's.id', 's.managed_form_id', 's.signer_name', 's.signed_at',
                'f.title as form_title', 'f.description as form_description',
                /* `fillable` decides whether review can edit the form's own FIELDS or
                   only write on top of it: a completed fillable form is flattened, so
                   the fields live on the blank template, not on the copy the parent
                   returned. */
                'f.file_url', 'f.fillable', 's.filled_file_url',
                'f.notify_email as form_notify_email', 's.notified_at', 's.notified_to',
                /* The counter-signature state, so the Completed table can say whether a
                   form is still waiting on the agency rather than looking finished. */
                's.countersigned_at', 's.countersigner_name', 's.countersigner_role',
                's.countersigned_file_url', 's.returned_at', 's.returned_to',
                /* Sent back to the signer for correction, and whether they have since
                   resubmitted. Without this the Completed tab shows a form as finished
                   while everyone is waiting on the parent. */
                's.correction_requested_at', 's.correction_requested_by_name',
                's.correction_note', 's.correction_sent_to', 's.corrected_at', 's.correction_count',
                'u.first_name', 'u.last_name', 'u.email',
                'doc.id as document_id', 'doc.created_at as document_filed_at',
                'doc.scope_id as document_user_id', 'du.id as document_user_exists',
                DB::raw("TRIM(CONCAT(COALESCE(du.first_name,''),' ',COALESCE(du.last_name,''))) as document_user_name"),
            ])
            ->limit(500)
            ->get();
        return response()->json(['signoffs' => $rows]);
    }

    /**
     * POST /admin/managed-forms/signoffs/{id}/email — send this completed copy to the
     * address configured on its form.
     *
     * Setting an address only affects submissions signed AFTER it was set, so forms
     * already signed had no way to reach it; this also re-sends one that shows as
     * "Not sent". Scoped to the caller's agency like every other admin action here.
     */
    public function emailSignoff(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);
        $row = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $id)->where('f.agency_id', $agencyId)
            ->select(['s.id', 's.user_id', 's.signer_name', 's.filled_file_url', 's.signed_at',
                      'f.id as form_id', 'f.agency_id', 'f.title', 'f.description', 'f.notify_email',
                      'u.email as signer_email'])
            ->first();
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $row->signed_at) {
            return response()->json(['message' => 'That form has not been signed yet.'], 422);
        }
        if (! $row->notify_email) {
            return response()->json(['message' => 'No address is set on this form. Add one with Edit first.'], 422);
        }

        // emailCompletedForm() reads the form's own fields, so hand it a form-shaped object.
        $form = (object) [
            'id' => $row->form_id, 'agency_id' => $row->agency_id, 'title' => $row->title,
            'description' => $row->description, 'notify_email' => $row->notify_email,
        ];
        $this->emailCompletedForm($form, $row->signer_name, $row->filled_file_url,
            (string) ($row->signer_email ?? ''), (int) $row->user_id, (int) $row->id);

        return response()->json(['ok' => true, 'message' => 'Copy emailed to ' . $row->notify_email . '.']);
    }

    /**
     * POST /admin/managed-forms/signoffs/{id}/return
     *
     * Send a completed form back to the person who signed it, with a note saying what
     * needs fixing, and make it outstanding for them again so they can resubmit.
     *
     * WHY NOT JUST DELETE THE SIGN-OFF. That was the only existing way to make a form
     * outstanding again, and it destroys the record: the completed PDF, the answers, the
     * fact that they submitted at all. A form that was wrong and then corrected is not
     * the same thing as a form that was never submitted, and an inspector asking "when
     * did you first receive this" needs the difference. The original row stays exactly
     * as filed; the correction arrives as a NEW sign-off.
     *
     * THE NOTE IS REQUIRED. A form returned with no reason is a rejection the parent
     * cannot act on, and they will resubmit the same thing.
     */
    public function returnToSigner(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);

        $data = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $row = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $id)->where('f.agency_id', $agencyId)
            ->select([
                's.id', 's.user_id', 's.signer_name', 's.signed_at', 's.countersigned_at',
                's.correction_requested_at', 's.corrected_at', 's.correction_count',
                'f.id as form_id', 'f.title', 'f.agency_id',
                'u.email as signer_email', 'u.first_name', 'u.last_name',
            ])
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $row->signed_at) {
            return response()->json(['message' => 'That form has not been completed yet.'], 422);
        }
        /* A counter-signed form has already been certified and mailed to both sides.
           Sending it back would leave a signed certificate in circulation for a document
           the agency has just disowned. Withdraw the counter-signature first. */
        if ($row->countersigned_at) {
            return response()->json([
                'message' => 'That form has already been counter-signed, so it cannot be sent back.',
            ], 409);
        }
        if ($row->correction_requested_at && ! $row->corrected_at) {
            return response()->json([
                'message' => 'That form has already been sent back and is waiting on the signer.',
            ], 409);
        }

        $to = trim((string) ($row->signer_email ?? ''));
        $who = trim((string) ($row->signer_name
            ?: trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''))));
        $me = $request->user();
        $byName = trim(($me->first_name ?? '') . ' ' . ($me->last_name ?? '')) ?: 'The agency';
        $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?? 'Your childcare provider');

        DB::table('managed_form_signoffs')->where('id', $row->id)->update([
            'correction_requested_at' => now(),
            'correction_requested_by_id' => (int) $me->id,
            'correction_requested_by_name' => $byName,
            'correction_note' => $data['note'],
            'correction_sent_to' => $to ?: null,
            /* Cleared so a form returned a second time is outstanding again rather than
               still counting as corrected from last time. */
            'corrected_at' => null,
            'correction_count' => (int) $row->correction_count + 1,
            'updated_at' => now(),
        ]);

        /* "SENT" HAS TO MEAN DELIVERED, NOT HANDED TO THE MAILER.
 
           Mail::html returning without throwing says nothing: SuppressAgencyMail cancels
           at MessageSending, AFTER this call has returned, so a suppressed recipient
           looked identical to a delivered one and the audit line read "Emailed to ..."
           for mail that never left. Ask the same question the gate will ask, before
           claiming anything. */
        $sent = false;
        $blocked = false;
        if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $blocked = \App\Support\Suppression::isUser((int) $row->user_id);
            if ($blocked) {
                \App\Support\Suppression::note('email', (int) $row->user_id, 'form_correction_request');
            } else {
                $sent = $this->mailCorrectionRequest((int) $agencyId, $to, $who, (string) $row->title,
                    $data['note'], $byName, $agencyName);
            }
        }

        /* THE INBOX, NOT JUST THE EMAIL.

           Email is the one channel the agency does not control: it is suppressed for
           some agencies, bounces for others, and lands in spam for the rest. The person
           is being asked to do something, so it has to reach the place they actually
           look - the bell, and their phone. Same pattern as every other action notice on
           the platform. */
        try {
            \App\Support\Notify::write([
                'user_id' => (int) $row->user_id,
                'type' => 'form_correction',
                'title' => 'Please correct: ' . $row->title,
                'body' => mb_substr($data['note'], 0, 240),
                'data' => json_encode(['link' => '#my-forms', 'form_id' => (int) $row->form_id]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('managed form: correction notification failed', ['err' => $e->getMessage()]);
        }
        try {
            app(\App\Services\FcmService::class)->sendToUser(
                (int) $row->user_id,
                'Please correct: ' . $row->title,
                mb_substr($data['note'], 0, 160),
                '#my-forms',
                false
            );
        } catch (\Throwable $e) {
        }

        $this->audit($request, 'managed_form.returned_to_signer', (int) $row->id, [
            'summary' => $byName . ' sent "' . $row->title . '" back to ' . ($who ?: 'the signer')
                . ' for correction. Reason: "' . mb_substr($data['note'], 0, 300) . '".'
                . ($sent ? ' Emailed to ' . $to . '.'
                    : ' NO EMAIL WAS SENT'
                      . ($blocked ? ' — delivery is switched off for this agency or recipient.'
                        : ($to ? ' — the mailer refused it.' : ' — no address on their account.'))),
            'form_id' => (int) $row->form_id,
            'signoff_id' => (int) $row->id,
            'sent_to' => $sent ? $to : null,
        ]);

        return response()->json([
            'ok' => true,
            'sent' => $sent,
            'sent_to' => $sent ? $to : null,
            /* Said plainly rather than implied by a green toast: the form is outstanding
               for them either way, but if nothing was emailed somebody has to tell them. */
            'message' => $sent
                ? ('Sent back to ' . $who . ' at ' . $to . '. The form is waiting for them to resubmit.')
                : ($to
                    ? ('The form is outstanding for ' . ($who ?: 'them') . ' again, but NO email was sent — '
                       . ($blocked ? 'delivery is switched off for this agency or recipient.' : 'the mailer refused it.')
                       . ' You will need to tell them another way.')
                    : 'Recorded, but ' . ($who ?: 'the signer') . ' has no email address on their account, so nothing was sent.'),
        ]);
    }

    /** The "please correct this" email. Returns whether it actually went. */
    private function mailCorrectionRequest(int $agencyId, string $to, string $who, string $title,
        string $note, string $byName, string $agencyName): bool
    {
        try {
            $tz = \App\Support\AgencyTime::tz($agencyId);
            $now = now()->setTimezone($tz);

            $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
                . ($who ? 'Hi ' . e($who) . ',<br><br>' : '')
                . e($agencyName) . ' has looked at the form you submitted and needs a change before it '
                . 'can be accepted.</p>'
                . EmailTemplate::calloutBox(
                    '<strong>Form:</strong> ' . e($title)
                    . '<br><strong>Reviewed by:</strong> ' . e($byName)
                    . '<br><strong>On:</strong> ' . e($now->format('D, M j, Y') . ' at ' . $now->format('g:i A')),
                    'warning')
                . EmailTemplate::calloutBox(
                    '<strong>What needs changing</strong><br>' . nl2br(e($note)), 'info')
                . '<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
                . 'The form is waiting for you in the portal. Your previous answers are still there, '
                . 'so you only need to change what is mentioned above, then sign and submit it again.</p>'
                . '<p style="margin:18px 0 0;"><a href="https://app.kiddietrac.com/dashboard.html#my-forms" '
                . 'style="display:inline-block;background:#1F6080;color:#fff;text-decoration:none;font-weight:700;'
                . 'font-size:15px;padding:13px 26px;border-radius:10px;">Open the form</a></p>';

            $html = EmailTemplate::wrap($agencyId, $body, [
                'eyebrow'   => 'ACTION NEEDED',
                'title'     => 'Please correct and resubmit',
                'subtitle'  => $title,
                'preheader' => $agencyName . ' needs a change to ' . $title . '.',
            ]);

            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $who, $title, $agencyId) {
                $m->to($to, $who ?: null)->subject('Please correct and resubmit: ' . $title);
                try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId); } catch (\Throwable $e) {}
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('managed form: correction request email failed', [
                'to' => $to, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * POST /admin/managed-forms/signoffs/sync-documents
     *
     * File any of this agency's signed forms whose document never got written — the
     * button behind the "Synced" column. The nightly pass does the same thing; this is
     * for when somebody is looking at the gap right now and wants it closed, typically
     * straight after the missing account was finally created.
     */
    public function syncDocuments(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);

        $before = \App\Support\SignedFormFiler::unfiledCount($agencyId);
        \App\Support\SignedFormFiler::backfill($agencyId);
        $after = \App\Support\SignedFormFiler::unfiledCount($agencyId);

        $this->audit($request, 'managed_form.documents_synced', null, [
            'summary' => 'Synced signed forms to signer records: ' . ($before - $after)
                . ' filed' . ($after ? ', ' . $after . ' still unfiled.' : '.'),
            'filed' => $before - $after,
            'remaining' => $after,
        ]);

        return response()->json([
            'ok' => true,
            'filed' => $before - $after,
            'remaining' => $after,
            /* A remaining count is not a failure to hide: it means something else is
               wrong with those rows - no completed file, or a signer whose account is
               gone - and it needs a person, not another retry. */
            'message' => $before === 0
                ? 'Everything was already filed.'
                : (($after === 0)
                    ? ('Filed ' . ($before - $after) . ' form' . (($before - $after) === 1 ? '' : 's') . ' onto the signer records.')
                    : ('Filed ' . ($before - $after) . '; ' . $after . ' could not be filed — their signer account or completed file is missing.')),
        ]);
    }

    /** GET /admin/managed-forms/{id}/signoff/{signoffId} — one signed record (with signature). */
    public function signoffDetail(Request $request, int $id, int $signoffId): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);
        $row = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $signoffId)->where('f.agency_id', $agencyId)
            ->select(['s.*', 'f.title as form_title', 'f.file_url', 'f.fillable', 'u.first_name', 'u.last_name', 'u.email'])
            ->first();
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['signoff' => $row]);
    }

    /* ───────────── ROLE INBOX: assigned + sign ───────────── */

    /** GET /forms/assigned — forms the caller must e-sign (role match, not yet signed). */
    public function assigned(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agencyId = $this->agencyId($request);
        $roles = $this->roles($uid);
        if (empty($roles) || ! $agencyId) {
            return response()->json(['forms' => [], 'count' => 0]);
        }
        // Only a SIGNED form leaves the list. A draft keeps its place — with the
        // answers so far — so the user can come back and finish it.
        /* A SIGN-OFF SENT BACK FOR CORRECTION DOES NOT COUNT AS SIGNED.

           Otherwise returning a form would tell the parent to fix it while the form
           itself stayed hidden from them - the one thing that makes the whole feature
           useless. `corrected_at` closes it again when they resubmit, so a returned form
           does not follow them forever. */
        /* ONLY THE LATEST SIGN-OFF PER FORM DECIDES.

           Judging every sign-off breaks the moment a form goes round twice: after the
           first correction the OLD row is signed-and-corrected, so it still counts as
           done, and sending the NEW one back leaves the form hidden from the person
           being asked to fix it. The second round silently did nothing. Reduce to the
           most recent submission per form and ask only that one. */
        $history = DB::table('managed_form_signoffs')->where('user_id', $uid)
            ->whereNotNull('signed_at')
            ->orderByDesc('signed_at')->orderByDesc('id')
            ->get(['id', 'managed_form_id', 'field_values', 'correction_requested_at', 'correction_note', 'corrected_at']);

        $latest = [];
        foreach ($history as $h) {
            if (! isset($latest[(int) $h->managed_form_id])) {
                $latest[(int) $h->managed_form_id] = $h;
            }
        }

        $signed = [];
        $pending = [];          // form_id => the returned sign-off, for answers + reason
        foreach ($latest as $fid => $h) {
            if ($h->correction_requested_at && ! $h->corrected_at) {
                $pending[(int) $fid] = $h;
            } else {
                $signed[] = (int) $fid;
            }
        }
        // A REUSABLE form is never "done" — an educator fills it again for the next
        // child or the next week — so signing it must not remove it from the list.
        $reusableIds = DB::table('managed_forms')->where('reusable', 1)->pluck('id')->all();
        $signed = array_values(array_diff($signed, $reusableIds));
        $drafts = DB::table('managed_form_signoffs')->where('user_id', $uid)
            ->whereNull('signed_at')->pluck('field_values', 'managed_form_id')->all();

        /* The answers they already gave, carried over from the returned submission.
           The email tells them "your previous answers are still there, change only what
           is mentioned" - without this that is a lie and they retype the whole form. */
        foreach ($pending as $fid => $h) {
            if (! isset($drafts[$fid]) && $h->field_values) {
                $drafts[$fid] = $h->field_values;
            }
        }
        $forms = DB::table('managed_forms')->where('agency_id', $agencyId)->where('active', 1)
            ->when(! empty($signed), fn ($q) => $q->whereNotIn('id', $signed))
            ->orderByDesc('id')->get()
            /* One rule for the whole portal — App\Support\FormAudience. Named
               recipients NARROW the form to exactly those people; only a form that
               names nobody is opened to a role. The old reading here ("named OR
               role") is what let a form addressed to one parent stay visible to
               every guardian in the agency. */
            ->pipe(fn ($rows) => collect(\App\Support\FormAudience::filter($rows, $uid, $roles)))
            ->map(function ($f) use ($drafts, $pending) {
                $raw = $drafts[$f->id] ?? null;
                return [
                    'id' => $f->id, 'title' => $f->title, 'description' => $f->description,
                    'file_url' => $f->file_url, 'fillable' => (bool) ($f->fillable ?? false),
                    'reusable' => (bool) ($f->reusable ?? false),
                    'draft_values' => $raw ? (json_decode($raw, true) ?: null) : null,
                    /* A form that reappears with no explanation reads as a bug in the
                       portal. Carry the reviewer's words through to the person who has
                       to act on them. */
                    'correction_note' => isset($pending[$f->id]) ? $pending[$f->id]->correction_note : null,
                    'returned_at' => isset($pending[$f->id]) ? $pending[$f->id]->correction_requested_at : null,
                ];
            })
            ->values();
        return response()->json(['forms' => $forms, 'count' => $forms->count()]);
    }

    /**
     * POST /managed-forms/{id}/draft — save answers WITHOUT signing.
     * A draft is simply a signoff row with signed_at NULL: the form stays in the
     * user's list, and reopening it restores what they typed. Signing later fills
     * the same row in, so a person can never end up with two records for one form.
     */
    public function draft(Request $request, int $id): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agencyId = $this->agencyId($request);
        $form = DB::table('managed_forms')->where('id', $id)->where('agency_id', $agencyId)->where('active', 1)->first();
        if (! $form) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $this->mayUseForm($form, $uid)) {
            return response()->json(['message' => 'This form is not assigned to you.'], 403);
        }
        // Never let a draft overwrite a completed submission.
        // Work on the OPEN (unsigned) row. For a reusable form the user may already
        // have signed submissions on file; those must never be touched.
        $open = DB::table('managed_form_signoffs')->where('managed_form_id', $id)
            ->where('user_id', $uid)->whereNull('signed_at')->first();
        if (! $open && ! ($form->reusable ?? false)) {
            $done = DB::table('managed_form_signoffs')->where('managed_form_id', $id)
                ->where('user_id', $uid)->whereNotNull('signed_at')->exists();
            if ($done) {
                return response()->json(['message' => 'This form has already been signed.'], 409);
            }
        }
        $data = $request->validate(['field_values' => ['nullable', 'array']]);
        $payload = [
            'field_values' => !empty($data['field_values']) ? json_encode($data['field_values']) : null,
            'signed_at'    => null,
            'updated_at'   => now(),
        ];
        if ($open) {
            DB::table('managed_form_signoffs')->where('id', $open->id)->update($payload);
        } else {
            DB::table('managed_form_signoffs')->insert($payload + [
                'managed_form_id' => $id, 'user_id' => $uid, 'created_at' => now(),
            ]);
        }

        return response()->json(['ok' => true, 'message' => 'Draft saved.']);
    }

    /**
     * GET /managed-forms/mine - the caller's own drafts and submitted forms.
     * Feeds the Drafts / Submitted tabs in My Forms.
     */
    public function mine(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agencyId = $this->agencyId($request);
        if (! $agencyId) {
            return response()->json(['drafts' => [], 'submitted' => []]);
        }

        $rows = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->where('s.user_id', $uid)->where('f.agency_id', $agencyId)
            ->orderByDesc('s.updated_at')
            ->get(['s.id', 's.managed_form_id', 's.signed_at', 's.updated_at', 's.field_values',
                   's.filled_file_url', 'f.title', 'f.description', 'f.file_url', 'f.fillable', 'f.reusable']);

        $shape = function ($r) {
            $vals = $r->field_values ? (json_decode($r->field_values, true) ?: []) : [];
            return [
                'signoff_id'   => $r->id,
                'id'           => $r->managed_form_id,
                'title'        => $r->title,
                'description'  => $r->description,
                'file_url'     => $r->filled_file_url ?: $r->file_url,
                'original_url' => $r->file_url,
                'fillable'     => (bool) $r->fillable,
                'reusable'     => (bool) $r->reusable,
                'answers'      => count($vals),
                'signed_at'    => $r->signed_at,
                'updated_at'   => $r->updated_at,
            ];
        };

        return response()->json([
            'drafts'    => $rows->whereNull('signed_at')->map($shape)->values(),
            'submitted' => $rows->whereNotNull('signed_at')->map($shape)->values(),
        ]);
    }

    /** POST /forms/{id}/sign — record the caller's e-signature. */
    public function sign(Request $request, int $id): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agencyId = $this->agencyId($request);
        $form = DB::table('managed_forms')->where('id', $id)->where('agency_id', $agencyId)->where('active', 1)->first();
        if (! $form) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $this->mayUseForm($form, $uid)) {
            return response()->json(['message' => 'This form is not assigned to you.'], 403);
        }
        $data = $request->validate([
            'signature'    => ['required', 'string'],   // base64 PNG
            'name'         => ['nullable', 'string', 'max:190'],
            /* Multipart now, base64 still tolerated -- see storeFilledPdf(). Validated
               loosely here because the real checks are on the bytes, not the wrapper. */
            'filled_file'  => ['nullable'],
            'field_values' => ['nullable'],
            // Fill-and-sign only. field_values keeps the answers queryable without
            // parsing a PDF; filled_file is the completed PDF (the client writes the
            // values into the original's own AcroForm fields, embeds the signature
            // and flattens), which is the artefact a parent or regulator wants.
        ]);
        $u = DB::table('users')->where('id', $uid)->first();
        // validate() omits an absent nullable key entirely, and no client sends
        // `name` — so $data['name'] threw "Undefined array key" and every signature
        // submission 500'd, the plain read-and-sign flow included.
        $name = ($data['name'] ?? null) ?: trim((string) (($u->first_name ?? '') . ' ' . ($u->last_name ?? '')));

        // Store the completed PDF next to the original.
        $filledUrl = $this->storeFilledPdf($request, $id, $uid);
        $fieldValues = $this->filledFieldValues($request);

        // Sign the OPEN draft if one exists, otherwise start a new record. A reusable
        // form therefore accumulates one row per submission instead of overwriting.
        $open = DB::table('managed_form_signoffs')->where('managed_form_id', $id)
            ->where('user_id', $uid)->whereNull('signed_at')->first();
        $row = [
                'signer_name' => $name ?: null,
                'signature'   => mb_substr($data['signature'], 0, 400000),
                'field_values' => $fieldValues ? json_encode($fieldValues) : null,
                'filled_file_url' => $filledUrl,
                'signed_at'   => now(),
                'ip_address'  => substr((string) $request->ip(), 0, 45),
                'updated_at'  => now(),
        ];
        if ($open) {
            DB::table('managed_form_signoffs')->where('id', $open->id)->update($row);
            $signoffId = (int) $open->id;
        } else {
            /* Closing the loop. The new submission is a NEW row - the original stays
               as filed, with its own PDF - so the pending return has to be marked
               resolved on the OLD row or the form stays outstanding for ever. */
            DB::table('managed_form_signoffs')
                ->where('managed_form_id', $id)->where('user_id', $uid)
                ->whereNotNull('correction_requested_at')->whereNull('corrected_at')
                ->update(['corrected_at' => now(), 'updated_at' => now()]);

            $signoffId = (int) DB::table('managed_form_signoffs')->insertGetId($row + [
                'managed_form_id' => $id, 'user_id' => $uid, 'created_at' => now(),
            ]);
        }

        /* Onto the signer's record, where they and whoever manages them can find it
           again. See App\Support\SignedFormFiler -- one row, resolved onto the family
           record through `guardians` rather than copied there. */
        \App\Support\SignedFormFiler::file($signoffId);

        /* A receipt to the person who signed, and a notice to the office. Both name the
           agency, the family and the exact time, and list every form that person has been
           given with its status -- see App\Support\FormSubmissionNotice. Queued, so a slow
           mail server never holds up the submit. */
        \App\Support\FormSubmissionNotice::send($signoffId);

        // If the form names an address, send the completed copy there. Best-effort:
        // the signature is already saved, so a mail problem must not fail the submit.
        try {
            $this->emailCompletedForm($form, $name, $filledUrl, (string) ($u->email ?? ''), $uid, $signoffId);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true, 'message' => 'Signed. Thank you!']);
    }

    /**
     * Email the completed form to the address configured on it, with the filled PDF
     * attached. Requested so a signed form can land in a compliance inbox or with a
     * director instead of only living in the Completed tab.
     *
     * The recipient is chosen by an admin and is often outside the agency (a licensing
     * contact, a shared mailbox), so this carries X-KT-Bypass-Suppression: it is
     * operational mail the admin explicitly asked for, like a support ticket, not a
     * broadcast that the per-agency comms switch should silence.
     */
    /**
     * The signer's place, and what this agency calls it.
     *
     * Agencies are set up differently: settings.centre_term is 'centre', 'room' or
     * 'provider', and a home-provider agency's centre record IS the provider. The
     * lookup is therefore the same; only the label changes. Falls back to the room's
     * own centre for staff attached through educator_rooms rather than directly.
     *
     * @return array{0: ?string, 1: string} [name, word]
     */

    /**
     * The completed PDF, however it arrived.
     *
     * Two shapes, on purpose. Multipart is what the filler sends now -- a JSON body with
     * the PDF base64'd inside it is capped at ~1MB by this host, which is why a 2.2MB form
     * came back as a bare Apache 413. The base64 field is still accepted so an older
     * cached copy of kt-form-filler.js keeps working through a deploy; drop it once no
     * client sends it.
     *
     * The guards do not move: 20MB and a real %PDF header, checked on the BYTES, whichever
     * route they came in by. A file part is not more trustworthy than a string.
     *
     * @return string|null the stored /storage path, or null when nothing usable arrived
     */
    private function storeFilledPdf(Request $request, int $formId, int $userId): ?string
    {
        $bin = null;

        $up = $request->file('filled_file');
        if ($up && $up->isValid()) {
            $bin = @file_get_contents($up->getRealPath());
        } elseif (is_string($request->input('filled_file')) && $request->input('filled_file') !== '') {
            $bin = base64_decode(
                preg_replace('#^data:application/pdf;base64,#', '', (string) $request->input('filled_file')),
                true
            );
        }

        if ($bin === false || $bin === null || $bin === '' ) {
            return null;
        }
        if (strlen($bin) > 20971520 || ! str_starts_with($bin, '%PDF')) {
            return null;
        }

        $fp = 'managed-forms/filled/' . $formId . '/' . $userId . '-' . time() . '.pdf';
        Storage::disk('public')->put($fp, $bin);

        return '/storage/' . $fp;
    }

    /**
     * field_values, whether it came as JSON or as a form field.
     *
     * Form data has no nesting, so the filler sends it as a JSON string. A JSON request
     * still sends a real array. Both mean the same thing and neither should reach the
     * database as the literal text of the other.
     */
    private function filledFieldValues(Request $request): array
    {
        $raw = $request->input('field_values');
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function signerPlace(int $userId, int $agencyId): array
    {
        $word = 'centre';
        try {
            $settings = DB::table('agencies')->where('id', $agencyId)->value('settings');
            $arr = $settings ? (json_decode($settings, true) ?: []) : [];
            $t = $arr['centre_term'] ?? 'centre';
            if (in_array($t, ['centre', 'room', 'provider'], true)) $word = $t;
        } catch (\Throwable $e) {
        }
        if (! $userId) return [null, $word];

        try {
            $centreId = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
                ->whereNotNull('centre_id')->value('centre_id');
            if (! $centreId && \Illuminate\Support\Facades\Schema::hasTable('educator_rooms')) {
                // Attached to a room rather than to a centre directly.
                $centreId = DB::table('educator_rooms as er')->join('rooms as r', 'r.id', '=', 'er.room_id')
                    ->where('er.user_id', $userId)->value('r.centre_id');
            }
            if (! $centreId) return [null, $word];
            $name = DB::table('centres')->where('id', $centreId)->value('name');
            return [$name ?: null, $word];
        } catch (\Throwable $e) {
            return [null, $word];
        }
    }

    private function emailCompletedForm(object $form, ?string $signerName, ?string $filledUrl, string $signerEmail, int $signerId = 0, int $signoffId = 0): void
    {
        $to = trim((string) ($form->notify_email ?? ''));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) return;

        $who = $signerName ?: 'Someone';
        $when = \App\Support\AgencyTime::fmt(now(), \App\Support\AgencyTime::tz((int) $form->agency_id));

        // Name the place in the subject. Which KIND of place that is depends on the
        // agency: settings.centre_term is 'centre', 'room' or 'provider', and the
        // centre record IS that thing - a home-provider agency's centre is the
        // provider. Same lookup either way; only the word changes.
        [$placeName, $placeWord] = $this->signerPlace($signerId, (int) $form->agency_id);

        // Date-stamped in the AGENCY's timezone, in the subject AND the body. These
        // land in an inbox that collects many of them; without a date the only way to
        // tell two sign-offs of the same form apart is to open both and hunt.
        $tz = \App\Support\AgencyTime::tz((int) $form->agency_id);
        $signedAt = now()->setTimezone($tz);
        $stamp = $signedAt->format('D, M j, Y');
        $stampFull = $signedAt->format('D, M j, Y') . ' at ' . $signedAt->format('g:i A')
            . ' (' . $signedAt->format('T') . ')';

        $subject = 'Completed form: ' . $form->title . ($placeName ? " \u{2014} " . $placeName : '')
            . " \u{2014} " . $stamp;

        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
              . e($who) . ' has completed and signed <strong>' . e($form->title) . '</strong> on '
              . e($stampFull) . '.</p>'
              . \App\Services\EmailTemplate::calloutBox(
                    '<strong>Form:</strong> ' . e($form->title)
                    . ($form->description ? '<br><strong>About:</strong> ' . e($form->description) : '')
                    . '<br><strong>Signed by:</strong> ' . e($who) . ($signerEmail ? ' (' . e($signerEmail) . ')' : '')
                    . ($placeName ? '<br><strong>' . e(ucfirst($placeWord)) . ':</strong> ' . e($placeName) : '')
                    . '<br><strong>Signed at:</strong> ' . e($when),
                    'info'
                )
              . '<p style="margin:14px 0 0;font-size:13.5px;color:#64748B;line-height:1.6;">'
              . ($filledUrl
                    ? 'The completed PDF is attached, with the signature embedded.'
                    : 'This form was signed as a read-and-sign notice, so there is no filled PDF to attach.')
              . '</p>';

        $html = \App\Services\EmailTemplate::wrap((int) $form->agency_id, $body, [
            'eyebrow'   => 'FORM COMPLETED',
            'title'     => $form->title,
            'subtitle'  => 'Signed by ' . $who,
            'preheader' => $who . ' completed ' . $form->title . '.',
        ]);

        $absPdf = null;
        if ($filledUrl) {
            $candidate = Storage::disk('public')->path(preg_replace('#^/storage/#', '', $filledUrl));
            if (is_file($candidate)) $absPdf = $candidate;
        }
        $attachName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $form->title) . '.pdf';

        dispatch(function () use ($to, $subject, $html, $absPdf, $attachName, $signoffId) {
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($to, $subject, $absPdf, $attachName) {
                $m->to($to)
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject);
                if ($absPdf) $m->attach($absPdf, ['as' => $attachName, 'mime' => 'application/pdf']);
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
            // Stamped only once the send has actually returned, so the Completed tab
            // reports what happened rather than what was queued.
            if ($signoffId) {
                try {
                    DB::table('managed_form_signoffs')->where('id', $signoffId)
                        ->update(['notified_at' => now(), 'notified_to' => $to]);
                } catch (\Throwable $e) {}
            }
        })->onQueue('mail');
    }

    /**
     * POST /admin/managed-forms/signoffs/{id}/countersign
     *
     * The second half of an exchange that only ever had a first half: the parent filled
     * it in and signed, the agency received a copy, and nothing recorded that anybody had
     * read it — let alone agreed to it. This records the agency's signature as a separate
     * act by a separate party, appends it to the completed PDF, and sends the finished
     * document to BOTH sides.
     *
     * THE PARENT'S SIGNATURE IS NEVER TOUCHED. `signature` and `field_values` stay exactly
     * as they were submitted. A record that merged the two could not answer "what did the
     * parent actually sign", which is the only question that matters if it is ever
     * disputed.
     */
    public function countersign(Request $request, int $id): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $this->agencyId($request);

        $data = $request->validate([
            'signature' => ['required', 'string', 'max:400000'],   // base64 PNG data URL
            'name' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:2000'],
            /* Default ON. The whole point of the request is that both sides end up with
               the finished document; making that opt-in would leave the common case
               half-done. */
            'send' => ['nullable', 'boolean'],

            /* THE REVIEWED DOCUMENT, when the agency filled something in during review.
               Base64 of a PDF the browser produced with pdf-lib. 12MB of base64 is
               roughly a 9MB PDF, which is larger than anything this platform has ever
               stored for a form. */
            'annotated_pdf' => ['nullable', 'string', 'max:12000000'],
            'annotations' => ['nullable', 'array', 'max:80'],
            'annotations.*.page' => ['required_with:annotations', 'integer', 'min:1', 'max:500'],
            'annotations.*.text' => ['required_with:annotations', 'string', 'max:2000'],
        ]);

        if (! preg_match('#^data:image/(png|jpe?g);base64,#i', $data['signature'])) {
            return response()->json(['message' => 'That signature could not be read.'], 422);
        }

        $row = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $id)->where('f.agency_id', $agencyId)
            ->select([
                's.id', 's.user_id', 's.signer_name', 's.signed_at', 's.filled_file_url',
                's.countersigned_at', 's.correction_requested_at', 's.corrected_at',
                'f.id as form_id', 'f.title', 'f.description', 'f.notify_email', 'f.agency_id',
                'u.email as signer_email', 'u.first_name as signer_first', 'u.last_name as signer_last',
            ])
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $row->signed_at) {
            /* Counter-signing something nobody has signed would produce a document that
               asserts an agreement that never happened. */
            return response()->json(['message' => 'That form has not been completed yet.'], 422);
        }
        if ($row->countersigned_at) {
            return response()->json(['message' => 'That form has already been counter-signed.'], 409);
        }
        /* THE TWO ACTIONS CONTRADICT EACH OTHER.
 
           Sending a form back says "this submission is not acceptable"; counter-signing
           says "the agency accepts this submission". Allowing both would put a signed
           certificate on a document the agency has already disowned, and the parent is
           mid-way through replacing it. Whichever was done in error has to be undone
           first. */
        if ($row->correction_requested_at && ! $row->corrected_at) {
            return response()->json([
                'message' => 'That form was sent back for correction and is waiting on the signer, so it cannot be counter-signed yet.',
            ], 409);
        }

        $me = $request->user();
        $counterName = trim((string) ($data['name'] ?? ''))
            ?: trim(($me->first_name ?? '') . ' ' . ($me->last_name ?? ''))
            ?: 'Agency';
        $counterRole = (string) (DB::table('role_assignments')->where('user_id', $me->id)
            ->where('active', true)->orderByRaw("role = 'platform_admin' DESC")
            ->value('role') ?? '');
        $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?? 'The agency');
        $signerName = $row->signer_name
            ?: trim(($row->signer_first ?? '') . ' ' . ($row->signer_last ?? ''));

        /* ── stamp the PDF, if we can ────────────────────────────────────────
           A failure here is NOT a failure of the counter-signature. It is recorded
           either way and the original is sent instead, with the email saying plainly
           who counter-signed and when. A missing page beats a missing record. */
        $storedUrl = null;

        /* THE REVIEWED COPY IS A NEW FILE. THE PARENT'S IS NEVER OVERWRITTEN.

           When the agency adds information during review the browser hands back the
           document with those additions drawn on. That becomes the base for the
           counter-signature page, and it is written alongside the original - which
           `filled_file_url` still points at, unchanged. Merging the two would destroy
           the only record of what the parent actually signed. */
        $reviewedAbs = null;
        if (! empty($data['annotated_pdf'])) {
            $bin = base64_decode(preg_replace('#^data:application/pdf;base64,#i', '', (string) $data['annotated_pdf']), true);
            /* A truncated upload, or anything that is not a PDF, must not silently
               replace the document: fall through to the original instead. */
            if ($bin !== false && strncmp($bin, '%PDF', 4) === 0 && strlen($bin) > 500) {
                $relR = 'managed-forms/' . $agencyId . '/reviewed/' . \Illuminate\Support\Str::random(40) . '.pdf';
                Storage::disk('public')->put($relR, $bin);
                $reviewedAbs = Storage::disk('public')->path($relR);
            } else {
                \Illuminate\Support\Facades\Log::warning('countersign: reviewed PDF rejected', [
                    'signoff' => $row->id, 'bytes' => $bin === false ? 0 : strlen((string) $bin),
                ]);
            }
        }

        if ($reviewedAbs || $row->filled_file_url) {
            $abs = $reviewedAbs ?: Storage::disk('public')->path(preg_replace('#^/storage/#', '', (string) $row->filled_file_url));
            $made = \App\Support\ManagedFormCountersign::append(
                $abs, (string) $row->title, (string) $signerName, (string) $row->signed_at,
                $counterName, str_replace('_', ' ', $counterRole), (string) $data['signature'],
                $data['note'] ?? null, $agencyName, $data['annotations'] ?? null
            );
            if ($made) {
                $rel = 'managed-forms/' . $agencyId . '/countersigned/' . \Illuminate\Support\Str::random(40) . '.pdf';
                Storage::disk('public')->put($rel, (string) file_get_contents($made));
                @unlink($made);
                $storedUrl = '/storage/' . $rel;
            }
        }

        DB::table('managed_form_signoffs')->where('id', $row->id)->update([
            'countersigned_at' => now(),
            'countersigned_by_id' => (int) $me->id,
            'countersigner_name' => $counterName,
            'countersigner_role' => $counterRole ?: null,
            'countersignature' => mb_substr((string) $data['signature'], 0, 400000),
            'countersign_note' => $data['note'] ?? null,
            'countersigned_file_url' => $storedUrl,
            'updated_at' => now(),
        ]);

        /* APPROVED -> THE SIGNER'S OWN COPY BECOMES THE APPROVED ONE.

           SignedFormFiler already put a documents row on their record when they signed,
           so the form is in their Documents section - but pointing at what THEY sent,
           not at what the agency approved. Once it is counter-signed the copy they keep
           should be the counter-signed one, or the family's record and the agency's
           disagree about the same document. Same row, updated in place: two rows would
           drift the moment one is deleted. */
        if ($storedUrl) {
            try {
                $doc = DB::table('documents')
                    ->where('source_type', \App\Support\SignedFormFiler::SOURCE)
                    ->where('source_id', (int) $row->id)
                    ->first(['id', 'title']);
                if ($doc) {
                    DB::table('documents')->where('id', $doc->id)->update([
                        'file_url' => $storedUrl,
                        'file_size' => (int) (@filesize(Storage::disk('public')->path(preg_replace('#^/storage/#', '', $storedUrl))) ?: 0),
                        'signed_at' => now(),
                        'notes' => 'Counter-signed by ' . $counterName
                            . (($data['note'] ?? null) ? ' — ' . mb_substr((string) $data['note'], 0, 300) : ''),
                    ]);
                }
            } catch (\Throwable $e) {
                /* Never worth failing the counter-signature over: it is recorded, the
                   PDF exists, and the email still goes. */
                Log::warning('countersign: could not refresh the filed document', ['err' => $e->getMessage()]);
            }
        }

        $sent = [];
        if (($data['send'] ?? true)) {
            $sent = $this->returnCountersigned($row, $storedUrl, $counterName, $data['note'] ?? null, $agencyName);
        }

        $this->audit($request, 'managed_form.countersigned', (int) $row->id, [
            'summary' => $counterName . ' counter-signed "' . $row->title . '" completed by '
                . ($signerName ?: 'a parent') . '.'
                . ($storedUrl ? ' A counter-signed PDF was produced.' : ' The PDF could not be stamped, so the original stands with the signature recorded here.')
                . (! empty($data['annotations'])
                    ? ' ' . count($data['annotations']) . ' addition(s) were made to the form during review: '
                      . implode('; ', array_map(function ($a) {
                            return 'p' . ($a['page'] ?? '?') . ' "' . mb_substr((string) ($a['text'] ?? ''), 0, 120) . '"';
                        }, $data['annotations'])) . '.'
                    : '')
                . ($sent ? ' Sent to: ' . implode(', ', $sent) . '.' : ' Not sent.'),
            'form_id' => (int) $row->form_id,
            'signoff_id' => (int) $row->id,
            'stamped' => (bool) $storedUrl,
            'sent_to' => $sent,
            /* Named individually, not counted: "3 additions" cannot answer which line
               on the form the agency wrote. */
            'annotations' => array_map(function ($a) {
                return 'p' . ($a['page'] ?? '?') . ': ' . mb_substr((string) ($a['text'] ?? ''), 0, 160);
            }, $data['annotations'] ?? []),
        ]);

        return response()->json([
            'ok' => true,
            'stamped' => (bool) $storedUrl,
            'file_url' => $storedUrl,
            'sent_to' => $sent,
            /* Said out loud rather than implied by a silent success: if the stamp failed
               the reviewer should know the attachment is the original. */
            'message' => $storedUrl
                ? ('Counter-signed' . ($sent ? ' and sent to ' . implode(' and ', $sent) : '') . '.')
                : ('Counter-signature recorded. The PDF could not be stamped, so the original was '
                   . ($sent ? 'sent instead' : 'left as it is') . '.'),
        ]);
    }

    /**
     * Send the finished document to BOTH sides.
     *
     * SUPPRESSION IS RESPECTED FOR THE PARENT AND BYPASSED FOR THE AGENCY, deliberately.
     * The agency's notify_email is their own inbox and their own choice — the existing
     * completed-form mail already bypasses for it. A parent under do-not-contact is a
     * person somebody decided must not be emailed, and a counter-signature is not a
     * reason to overrule that.
     */
    private function returnCountersigned(object $row, ?string $storedUrl, string $counterName, ?string $note, string $agencyName): array
    {
        $agencyTo = trim((string) ($row->notify_email ?? ''));
        $parentTo = trim((string) ($row->signer_email ?? ''));

        $absPdf = null;
        $useUrl = $storedUrl ?: $row->filled_file_url;
        if ($useUrl) {
            $cand = Storage::disk('public')->path(preg_replace('#^/storage/#', '', (string) $useUrl));
            if (is_file($cand)) {
                $absPdf = $cand;
            }
        }
        $attachName = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $row->title)
            . ($storedUrl ? '-countersigned' : '') . '.pdf';

        /* BUILT THE SAME WAY AS THE COMPLETED-FORM MAIL, deliberately.

           This lands in the same inbox, often minutes apart from the "Completed form"
           notice for the same document. A second layout for the second half of one
           exchange reads as a different system talking, so it uses the same callout
           boxes, the same agency-timezone stamps and the same dated subject line. */
        $tz = \App\Support\AgencyTime::tz((int) $row->agency_id);
        $now = now()->setTimezone($tz);
        $stamp = $now->format('D, M j, Y');
        $stampFull = $now->format('D, M j, Y') . ' at ' . $now->format('g:i A')
            . ' (' . $now->format('T') . ')';

        $signedWhen = '';
        if ($row->signed_at) {
            try {
                $signedWhen = \Illuminate\Support\Carbon::parse($row->signed_at)
                    ->setTimezone($tz)->format('D, M j, Y') . ' at '
                    . \Illuminate\Support\Carbon::parse($row->signed_at)->setTimezone($tz)->format('g:i A');
            } catch (\Throwable $e) {
            }
        }
        $signerLabel = (string) ($row->signer_name ?: 'the parent');

        /* The date belongs in the subject: these accumulate in one inbox and without it
           two counter-signatures of the same form are indistinguishable unopened. */
        $subject = 'Counter-signed: ' . $row->title . " \u{2014} " . $stamp;

        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
            . e($agencyName) . ' has reviewed and counter-signed <strong>' . e((string) $row->title)
            . '</strong> on ' . e($stampFull) . '.</p>'
            . \App\Services\EmailTemplate::calloutBox(
                '<strong>Form:</strong> ' . e((string) $row->title)
                . (($row->description ?? '') ? '<br><strong>About:</strong> ' . e((string) $row->description) : '')
                . '<br><strong>Completed by:</strong> ' . e($signerLabel)
                . ($parentTo ? ' (' . e($parentTo) . ')' : '')
                . ($signedWhen ? '<br><strong>Signed at:</strong> ' . e($signedWhen) : '')
                . '<br><strong>Counter-signed by:</strong> ' . e($counterName)
                . '<br><strong>Counter-signed at:</strong> ' . e($stampFull),
                'info'
            )
            /* The reviewer's note gets its own box in green. It is the one part of this
               email somebody actually wrote, and burying it in a grey paragraph is how
               a condition attached to an approval goes unread. */
            . ($note !== null && trim($note) !== ''
                ? \App\Services\EmailTemplate::calloutBox(
                    '<strong>Note from the reviewer</strong><br>' . nl2br(e(trim($note))), 'success')
                : '')
            . '<p style="margin:14px 0 0;font-size:13.5px;color:#64748B;line-height:1.6;">'
            . ($storedUrl
                ? 'The completed form is attached, with the counter-signature on the final page.'
                : 'The completed form is attached. The counter-signature is recorded in KiddieTrac.')
            . ' Please keep this for your records.</p>';

        $html = \App\Services\EmailTemplate::wrap((int) $row->agency_id, $body, [
            'eyebrow' => 'COUNTER-SIGNED',
            'title' => (string) $row->title,
            'subtitle' => 'Reviewed by ' . $counterName,
            'preheader' => $agencyName . ' counter-signed ' . $row->title . '.',
        ]);

        $sent = [];

        /* The parent first: they are the side that has never been told anything. */
        if ($parentTo && filter_var($parentTo, FILTER_VALIDATE_EMAIL)) {
            if (\App\Support\Suppression::isUser((int) $row->user_id)) {
                \App\Support\Suppression::note('email', (int) $row->user_id, 'form_countersigned');
            } else {
                $this->queueCountersignMail((int) $row->agency_id, $parentTo, $subject, $html, $absPdf, $attachName, false);
                $sent[] = $parentTo;
            }
        }

        if ($agencyTo && filter_var($agencyTo, FILTER_VALIDATE_EMAIL) && strcasecmp($agencyTo, $parentTo) !== 0) {
            $this->queueCountersignMail((int) $row->agency_id, $agencyTo, $subject, $html, $absPdf, $attachName, true);
            $sent[] = $agencyTo;
        }

        if ($sent) {
            DB::table('managed_form_signoffs')->where('id', $row->id)->update([
                'returned_at' => now(),
                'returned_to' => mb_substr(implode(', ', $sent), 0, 400),
                'updated_at' => now(),
            ]);
        }

        return $sent;
    }

    /**
     * SENT THROUGH AgencyMailer, LIKE EVERY OTHER NOTICE ON THIS PLATFORM.
     *
     * This used to call Mail::html() directly, which skips the X-KT-Agency-Id stamp.
     * Without that header SuppressAgencyMail cannot tell whose mail this is and falls
     * back to judging it by every account sharing the recipient's address - and an
     * address that holds a role in a switched-off agency then cancels mail that a
     * completely different agency sent. That is not hypothetical: it silently killed 50
     * real iLearn notices on 2026-09-16, which is why AgencyMailer::html() exists and
     * why nothing should be composing mail without it.
     */
    private function queueCountersignMail(int $agencyId, string $to, string $subject, string $html, ?string $absPdf, string $attachName, bool $bypassSuppression): void
    {
        dispatch(function () use ($agencyId, $to, $subject, $html, $absPdf, $attachName, $bypassSuppression) {
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $subject, $absPdf, $attachName, $bypassSuppression) {
                $m->to($to)
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject);
                if ($absPdf) {
                    $m->attach($absPdf, ['as' => $attachName, 'mime' => 'application/pdf']);
                }
                if ($bypassSuppression) {
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                }
            });
        })->onQueue('mail');
    }

    private function audit(Request $request, string $action, ?int $entityId, array $payload): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => optional($request->user())->id,
                'agency_id' => $this->agencyId($request),
                'action' => $action,
                'entity_type' => 'managed_form_signoff',
                'entity_id' => $entityId,
                'payload' => json_encode($payload),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
