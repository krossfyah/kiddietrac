<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\Jurisdictions;
use App\Support\Live;
use App\Support\PolicyLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * POLICY LIBRARY + STAFF SIGN-OFFS + TRAINING MATRIX (2026-10-13).
 *
 * O. Reg. 137/15 s.6.1 (as amended by O. Reg. 197/26): every required policy is implemented
 * and reviewed by staff before they work with children and whenever it changes; compliance
 * and contraventions are monitored in writing; records kept 3 years (nothing here deletes).
 * s.58(1.1) from 2027-01-01: training on every required policy. s.46/49: program statement
 * reviewed annually.
 *
 * Who: agency/platform admins manage every policy; centre directors see agency-wide policies
 * and manage their own centres' policies, matrix and contraventions. Every staff member
 * (educator, home visitor, director) reads and signs what applies to them. Fails closed.
 */
final class PolicyController extends Controller
{
    private function agencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true);
        if ((clone $roles)->where('role', 'platform_admin')->exists()) {
            abort_unless($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists(), 400, 'Select an agency first.');
            return $active;
        }
        if ($active && (clone $roles)->where(function ($w) use ($active) {
                $w->where('agency_id', $active)->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $active)->pluck('id'));
            })->exists()) return $active;
        $id = (int) ((clone $roles)->whereNotNull('agency_id')->value('agency_id')
            ?: DB::table('centres')->whereIn('id', (clone $roles)->whereNotNull('centre_id')->pluck('centre_id'))->value('agency_id'));
        abort_unless($id, 403);

        return $id;
    }

    /** null = not a manager; ['all'=>true] admins; ['all'=>false,'centres'=>[ids]] directors (open centres only). */
    private function scope(Request $request, int $agencyId): ?array
    {
        $roles = DB::table('role_assignments')->where('user_id', (int) $request->user()->id)->where('active', true)->get(['role', 'agency_id', 'centre_id']);
        foreach ($roles as $r) {
            if ($r->role === 'platform_admin' || ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId)) return ['all' => true, 'centres' => null];
        }
        $live = Live::centreIds($agencyId);
        $mine = $roles->where('role', 'centre_director')->pluck('centre_id')->filter()->map(fn ($i) => (int) $i)->intersect($live)->values()->all();

        return $mine ? ['all' => false, 'centres' => $mine] : null;
    }

    private function manager(Request $request, int $agencyId): array
    {
        $s = $this->scope($request, $agencyId);
        abort_unless($s, 403, 'Directors and admins only.');

        return $s;
    }

    /** The centres a manager may see, [id => name]. */
    private function centres(array $scope, int $agencyId): array
    {
        $q = DB::table('centres')->whereIn('id', Live::centreIds($agencyId) ?: [0]);
        if (! $scope['all']) $q->whereIn('id', $scope['centres'] ?: [0]);

        return $q->orderBy('name')->pluck('name', 'id')->all();
    }

    private function canRead(array $scope, object $p): bool
    {
        return $scope['all'] || $p->centre_id === null || in_array((int) $p->centre_id, $scope['centres'], true);
    }

    private function canWrite(array $scope, object $p): bool
    {
        return $scope['all'] || ($p->centre_id !== null && in_array((int) $p->centre_id, $scope['centres'], true));
    }

    private function policy(int $agencyId, int $id): object
    {
        $p = DB::table('policies')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($p, 404);

        return $p;
    }

    private function audit(Request $request, int $agencyId, string $action, int $id, string $summary, string $type = 'policy'): void
    {
        try {
            Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id,
                'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');

        return $raw ? (json_decode((string) $raw, true) ?: []) : [];
    }

    private function fmt($ts, string $tz): ?string
    {
        return $ts ? Carbon::parse($ts, 'UTC')->setTimezone($tz)->format('M j, Y g:i A') : null;
    }

    /* ─────────────────────────── library ─────────────────────────── */

    /** GET /policies?centre_id=&archived=0|1 */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $centres = $this->centres($scope, $agencyId);
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $today = PolicyLibrary::today($agencyId);
        $soon = Carbon::parse($today)->addDays(30)->toDateString();

        $q = DB::table('policies as p')->leftJoin('policy_versions as v', 'v.id', '=', 'p.current_version_id')
            ->leftJoin('users as o', 'o.id', '=', 'p.owner_user_id')
            ->where('p.agency_id', $agencyId)->where('p.archived', (bool) $request->boolean('archived'));
        if (! $scope['all']) $q->where(fn ($w) => $w->whereNull('p.centre_id')->orWhereIn('p.centre_id', $scope['centres'] ?: [0]));
        $rows = $q->orderByDesc('p.required')->orderBy('p.title')
            ->get(['p.*', 'v.version', 'v.published_at', 'v.change_summary', 'v.file_name', 'v.file_path', 'v.body_html',
                DB::raw("TRIM(CONCAT(COALESCE(o.first_name,''),' ',COALESCE(o.last_name,''))) as owner_name")]);

        $cid = (int) $request->input('centre_id');
        $staff = PolicyLibrary::staff($agencyId, $scope['all'] ? ($cid && isset($centres[$cid]) ? [$cid] : null) : ($cid && isset($centres[$cid]) ? [$cid] : $scope['centres']));
        $grid = PolicyLibrary::grid($rows->all(), array_keys($staff));
        $contra = DB::table('policy_contraventions')->where('agency_id', $agencyId)->whereIn('policy_id', $rows->pluck('id')->all() ?: [0])
            ->select('policy_id', DB::raw('COUNT(*) as n'))->groupBy('policy_id')->pluck('n', 'policy_id');

        $out = [];
        foreach ($rows as $p) {
            $applicable = array_filter($staff, fn ($s) => PolicyLibrary::applies($p, $s));
            $signed = 0;
            foreach ($applicable as $s) if (($grid[(int) $p->id][$s['id']]['state'] ?? '') === 'signed') $signed++;
            $status = ! $p->current_version_id ? 'no_text' : (($p->next_review_due && $p->next_review_due < $today) ? 'review_overdue'
                : (($p->next_review_due && $p->next_review_due <= $soon) ? 'review_soon' : 'current'));
            $out[] = ['id' => (int) $p->id, 'key' => $p->key, 'title' => $p->title, 'purpose' => $p->purpose, 'category' => $p->category,
                'required' => (bool) $p->required, 'reg_ref' => $p->reg_ref, 'required_from' => $p->required_from,
                'centre_id' => $p->centre_id ? (int) $p->centre_id : null, 'centre_name' => $p->centre_id ? ($centres[(int) $p->centre_id] ?? DB::table('centres')->where('id', $p->centre_id)->value('name')) : null,
                'review_frequency_months' => (int) $p->review_frequency_months, 'owner_user_id' => $p->owner_user_id ? (int) $p->owner_user_id : null, 'owner_name' => $p->owner_name ?: null,
                'current_version_id' => $p->current_version_id ? (int) $p->current_version_id : null, 'version' => $p->version ? (int) $p->version : null,
                'published_at' => $this->fmt($p->published_at, $tz), 'change_summary' => $p->change_summary,
                'has_file' => (bool) $p->file_path, 'file_name' => $p->file_name, 'has_text' => (bool) $p->body_html,
                'last_reviewed_at' => $p->last_reviewed_at, 'next_review_due' => $p->next_review_due, 'archived' => (bool) $p->archived,
                'status' => $status, 'staff_total' => count($applicable), 'staff_signed' => $signed,
                'pct_signed' => count($applicable) ? (int) floor($signed * 100 / count($applicable)) : null,
                'contraventions' => (int) ($contra[$p->id] ?? 0), 'can_edit' => $this->canWrite($scope, $p)];
        }
        $j = Jurisdictions::forAgency($agencyId);
        $s = $this->settings($agencyId);
        $owners = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.active', true)->whereNull('u.deleted_at')
            ->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->where(fn ($w) => $w->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', array_keys($centres) ?: [0]))
            ->orderBy('u.first_name')->get(['u.id', 'u.first_name', 'u.last_name', 'u.email'])
            ->reject(fn ($u) => \App\Support\ChatAudience::isServiceEmail($u->email))->unique('id')
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name)])->values();

        return response()->json(['policies' => $out, 'today' => $today, 'timezone' => $tz,
            'centres' => collect($centres)->map(fn ($n, $i) => ['id' => (int) $i, 'name' => $n])->values(),
            'is_admin' => $scope['all'], 'jurisdiction' => ['code' => $j['code'], 'label' => $j['label']],
            'can_seed' => $scope['all'] && $j['code'] === 'CA-ON',
            'seed_missing' => $scope['all'] && $j['code'] === 'CA-ON' ? $this->seedMissing($agencyId) : 0,
            'categories' => PolicyLibrary::CATEGORIES, 'owners' => $owners,
            'reminders' => (bool) ($s['policy_reminders']['enabled'] ?? true), 'staff_count' => count($staff)]);
    }

    private function seedMissing(int $agencyId): int
    {
        $have = DB::table('policies')->where('agency_id', $agencyId)->whereNull('centre_id')->whereNotNull('key')->pluck('key')->all();

        return count(array_filter(PolicyLibrary::ontarioCatalogue(), fn ($c) => ! in_array($c['key'], $have, true)));
    }

    /** POST /policies/seed-ontario — admins of an Ontario agency. Adds what is missing; never duplicates; no body text. */
    public function seedOntario(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        abort_unless($scope['all'], 403, 'Agency admins only.');
        abort_unless(Jurisdictions::codeFor($agencyId) === 'CA-ON', 422, 'The Ontario checklist is for agencies in Ontario.');
        $added = DB::transaction(function () use ($agencyId, $request) {
            DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('id');
            $have = DB::table('policies')->where('agency_id', $agencyId)->whereNull('centre_id')->whereNotNull('key')->pluck('key')->all();
            $n = 0;
            foreach (PolicyLibrary::ontarioCatalogue() as $c) {
                if (in_array($c['key'], $have, true)) continue;
                DB::table('policies')->insert(['agency_id' => $agencyId, 'centre_id' => null, 'key' => $c['key'], 'title' => $c['title'], 'purpose' => $c['purpose'],
                    'category' => $c['category'], 'required' => $c['required'], 'reg_ref' => $c['reg_ref'] ?: null, 'required_from' => $c['required_from'] ?? null,
                    'review_frequency_months' => 12, 'archived' => false, 'created_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                $n++;
            }

            return $n;
        });
        if ($added) $this->audit($request, $agencyId, 'policy.seeded', $agencyId, 'Ontario policy checklist added to the library: ' . $added . ' entries (titles only)', 'agency');

        return response()->json(['ok' => true, 'added' => $added]);
    }

    private function validated(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$req, 'string', 'max:190'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:500'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'required' => ['sometimes', 'boolean'],
            'centre_id' => ['sometimes', 'nullable', 'integer'],
            'review_frequency_months' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer'],
            'next_review_due' => ['sometimes', 'nullable', 'date'],
        ]);
    }

    private function checkOwner(int $agencyId, $owner): void
    {
        if (! $owner) return;
        abort_unless(DB::table('role_assignments')->where('user_id', (int) $owner)->where('active', true)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $agencyId)->pluck('id')))->exists(), 422, 'The owner must be a director or admin of this agency.');
    }

    /** POST /policies */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $d = $this->validated($request, true);
        $centreId = isset($d['centre_id']) && $d['centre_id'] ? (int) $d['centre_id'] : null;
        if (! $scope['all']) abort_unless($centreId && in_array($centreId, $scope['centres'], true), 403, 'Directors add policies for their own centre. Agency-wide policies are added by an admin.');
        if ($centreId) abort_unless(in_array($centreId, Live::centreIds($agencyId), true), 422, 'That centre is not open in this agency.');
        $this->checkOwner($agencyId, $d['owner_user_id'] ?? null);
        $id = DB::table('policies')->insertGetId(['agency_id' => $agencyId, 'centre_id' => $centreId, 'key' => null, 'title' => trim($d['title']),
            'purpose' => $d['purpose'] ?? null, 'category' => $d['category'] ?? null, 'required' => (bool) ($d['required'] ?? false),
            'review_frequency_months' => (int) ($d['review_frequency_months'] ?? 12), 'owner_user_id' => $d['owner_user_id'] ?? null,
            'archived' => false, 'created_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'policy.created', $id, 'Policy added to the library: ' . trim($d['title']));

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** PATCH /policies/{id} — details only; the text changes by publishing a new version. */
    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $p = $this->policy($agencyId, $id);
        abort_unless($this->canWrite($scope, $p), 403, 'Agency-wide policies are edited by an admin.');
        $d = $this->validated($request, false);
        if (array_key_exists('centre_id', $d)) {
            $c = $d['centre_id'] ? (int) $d['centre_id'] : null;
            if (! $scope['all']) abort_unless($c && in_array($c, $scope['centres'], true), 403);
            if ($c) abort_unless(in_array($c, Live::centreIds($agencyId), true), 422, 'That centre is not open in this agency.');
            $d['centre_id'] = $c;
        }
        if (array_key_exists('owner_user_id', $d)) $this->checkOwner($agencyId, $d['owner_user_id']);
        if (isset($d['title'])) $d['title'] = trim($d['title']);
        if (array_key_exists('required', $d)) $d['required'] = (bool) $d['required'];
        if ($d) DB::table('policies')->where('id', $id)->update($d + ['updated_at' => now()]);
        $this->audit($request, $agencyId, 'policy.updated', $id, 'Policy details changed: ' . ($d['title'] ?? $p->title) . ' — ' . implode(', ', array_keys($d)));

        return response()->json(['ok' => true]);
    }

    /** POST /policies/{id}/archive {archived} — kept, just out of the current list. */
    public function archive(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $p = $this->policy($agencyId, $id);
        abort_unless($this->canWrite($scope, $p), 403, 'Agency-wide policies are archived by an admin.');
        $on = (bool) $request->validate(['archived' => ['required', 'boolean']])['archived'];
        DB::table('policies')->where('id', $id)->update(['archived' => $on, 'updated_at' => now()]);
        $this->audit($request, $agencyId, $on ? 'policy.archived' : 'policy.restored', $id, ($on ? 'Policy archived (kept on file): ' : 'Policy restored: ') . $p->title);

        return response()->json(['ok' => true]);
    }

    /** GET /policies/{id} — versions, who has signed the current one, reviews, training, contraventions. */
    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $p = $this->policy($agencyId, $id);
        abort_unless($this->canRead($scope, $p), 404);
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $centres = $this->centres($scope, $agencyId);
        $name = "TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')))";

        $versions = DB::table('policy_versions as v')->leftJoin('users as u', 'u.id', '=', 'v.published_by')->where('v.policy_id', $id)->orderByDesc('v.version')
            ->get(['v.id', 'v.version', 'v.change_summary', 'v.file_name', 'v.file_path', 'v.body_text', 'v.published_at', DB::raw("$name as published_by_name")])
            ->map(function ($v) use ($tz) {
                $n = DB::table('policy_acknowledgements')->where('policy_version_id', $v->id)->count();
                return ['id' => (int) $v->id, 'version' => (int) $v->version, 'change_summary' => $v->change_summary, 'file_name' => $v->file_name,
                    'has_file' => (bool) $v->file_path, 'has_text' => $v->body_text !== null && $v->body_text !== '', 'published_at' => $this->fmt($v->published_at, $tz),
                    'published_by_name' => $v->published_by_name, 'signatures' => $n];
            })->values();
        $currentText = $p->current_version_id ? DB::table('policy_versions')->where('id', $p->current_version_id)->value('body_text') : null;

        $staff = array_filter(PolicyLibrary::staff($agencyId, $scope['all'] ? null : $scope['centres']), fn ($s) => PolicyLibrary::applies($p, $s));
        $grid = PolicyLibrary::grid([$p], array_keys($staff));
        $people = [];
        foreach ($staff as $s) {
            $g = $grid[(int) $p->id][$s['id']] ?? ['state' => 'outstanding', 'at' => null, 'version' => null, 'trained_on' => null];
            $people[] = ['user_id' => $s['id'], 'name' => $s['name'], 'state' => $p->current_version_id ? $g['state'] : 'no_text', 'signed_at' => $this->fmt($g['at'], $tz),
                'signed_version' => $g['version'], 'trained_on' => $g['trained_on'],
                'centres' => implode(', ', array_map(fn ($c) => $centres[$c] ?? '', array_intersect($s['centres'], array_keys($centres))))];
        }
        $reviews = DB::table('policy_reviews as r')->leftJoin('users as u', 'u.id', '=', 'r.reviewed_by')->where('r.policy_id', $id)->orderByDesc('r.reviewed_on')->orderByDesc('r.id')->limit(50)
            ->get(['r.reviewed_on', 'r.changed', 'r.notes', DB::raw("$name as by_name")]);
        $cq = DB::table('policy_contraventions as c')->leftJoin('users as u', 'u.id', '=', 'c.recorded_by')->where('c.policy_id', $id)->where('c.agency_id', $agencyId);
        if (! $scope['all']) $cq->whereIn('c.centre_id', $scope['centres'] ?: [0]);
        $contra = $cq->orderByDesc('c.occurred_at')->limit(200)
            ->get(['c.id', 'c.centre_id', 'c.occurred_at', 'c.description', 'c.action_taken', 'c.staff_involved', DB::raw("$name as by_name")])
            ->map(fn ($c) => ['id' => (int) $c->id, 'centre_name' => $centres[(int) $c->centre_id] ?? '', 'occurred_at' => $this->fmt($c->occurred_at, $tz),
                'description' => $c->description, 'action_taken' => $c->action_taken, 'staff_involved' => $c->staff_involved, 'by_name' => $c->by_name])->values();
        $trainings = DB::table('policy_trainings as t')->join('users as s', 's.id', '=', 't.user_id')->where('t.policy_id', $id)->whereIn('t.user_id', array_keys($staff) ?: [0])
            ->orderByDesc('t.trained_on')->limit(300)
            ->get(['t.trained_on', 't.delivered_by', 't.notes', DB::raw("TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) as staff_name")]);

        return response()->json(['policy' => ['id' => (int) $p->id, 'title' => $p->title, 'purpose' => $p->purpose, 'category' => $p->category, 'required' => (bool) $p->required,
                'reg_ref' => $p->reg_ref, 'required_from' => $p->required_from, 'centre_id' => $p->centre_id ? (int) $p->centre_id : null, 'review_frequency_months' => (int) $p->review_frequency_months,
                'owner_user_id' => $p->owner_user_id ? (int) $p->owner_user_id : null, 'current_version_id' => $p->current_version_id ? (int) $p->current_version_id : null,
                'last_reviewed_at' => $p->last_reviewed_at, 'next_review_due' => $p->next_review_due, 'archived' => (bool) $p->archived, 'key' => $p->key,
                'can_edit' => $this->canWrite($scope, $p), 'current_text' => $currentText],
            'versions' => $versions, 'people' => $people, 'reviews' => $reviews, 'contraventions' => $contra, 'trainings' => $trainings]);
    }

    /** POST /policies/{id}/versions (multipart) {change_summary, body_text? | file?} — publish a new version; everyone signs again. */
    public function publish(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $p = $this->policy($agencyId, $id);
        abort_unless($this->canWrite($scope, $p), 403, 'Agency-wide policies are published by an admin.');
        abort_if($p->archived, 422, 'Restore this policy before publishing a new version.');
        $d = $request->validate([
            'change_summary' => ['required', 'string', 'min:3', 'max:1000'],
            'body_text' => ['nullable', 'string', 'max:200000'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,odt,rtf,txt,png,jpg,jpeg'],
        ]);
        $text = trim((string) ($d['body_text'] ?? ''));
        abort_unless($text !== '' || $request->hasFile('file'), 422, 'Upload the policy document or paste its text.');
        $path = null; $fname = null; $mime = null;
        if ($request->hasFile('file')) {
            $f = $request->file('file');
            $fname = mb_substr($f->getClientOriginalName(), 0, 190);
            $mime = mb_substr((string) $f->getMimeType(), 0, 120);
            $path = $f->storeAs('policies/' . $agencyId, Str::uuid() . '.' . strtolower($f->getClientOriginalExtension() ?: 'bin'), 'local');
        }
        $today = PolicyLibrary::today($agencyId);
        $vid = DB::transaction(function () use ($id, $agencyId, $d, $text, $path, $fname, $mime, $request, $p, $today) {
            DB::table('policies')->where('id', $id)->lockForUpdate()->value('id');
            $n = (int) DB::table('policy_versions')->where('policy_id', $id)->max('version') + 1;
            $vid = DB::table('policy_versions')->insertGetId(['policy_id' => $id, 'agency_id' => $agencyId, 'version' => $n,
                'body_text' => $text !== '' ? $text : null, 'body_html' => $text !== '' ? PolicyLibrary::textToHtml($text) : null,
                'file_path' => $path, 'file_name' => $fname, 'file_mime' => $mime, 'change_summary' => trim($d['change_summary']),
                'published_by' => (int) $request->user()->id, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('policies')->where('id', $id)->update(['current_version_id' => $vid, 'last_reviewed_at' => $today,
                'next_review_due' => Carbon::parse($today)->addMonths(max(1, (int) $p->review_frequency_months))->toDateString(), 'updated_at' => now()]);
            DB::table('policy_reviews')->insert(['policy_id' => $id, 'agency_id' => $agencyId, 'policy_version_id' => $vid, 'reviewed_on' => $today, 'changed' => true,
                'notes' => 'Version ' . $n . ' published: ' . mb_substr(trim($d['change_summary']), 0, 900), 'reviewed_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

            return $vid;
        });
        $ver = (int) DB::table('policy_versions')->where('id', $vid)->value('version');
        $this->audit($request, $agencyId, 'policy.published', $id, 'Policy version ' . $ver . ' published: ' . $p->title . ' — ' . trim($d['change_summary']) . ($fname ? ' (file: ' . $fname . ')' : ''));

        // Tell the staff. In a web request, after the response so publishing is not slowed by email.
        if (app()->runningInConsole()) {
            PolicyLibrary::notifyPublished($vid);
        } else {
            dispatch(function () use ($vid) { PolicyLibrary::notifyPublished($vid); })->afterResponse();
        }

        return response()->json(['ok' => true, 'version_id' => $vid, 'version' => $ver]);
    }

    /** POST /policies/{id}/reviewed {notes?} — reviewed, no change: the review date moves on; sign-offs stand. */
    public function reviewed(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $p = $this->policy($agencyId, $id);
        abort_unless($this->canWrite($scope, $p), 403, 'Agency-wide policies are reviewed by an admin.');
        abort_unless($p->current_version_id, 422, 'Publish the policy first.');
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:1000']])['notes'] ?? null;
        $today = PolicyLibrary::today($agencyId);
        $next = Carbon::parse($today)->addMonths(max(1, (int) $p->review_frequency_months))->toDateString();
        DB::table('policies')->where('id', $id)->update(['last_reviewed_at' => $today, 'next_review_due' => $next, 'updated_at' => now()]);
        DB::table('policy_reviews')->insert(['policy_id' => $id, 'agency_id' => $agencyId, 'policy_version_id' => $p->current_version_id, 'reviewed_on' => $today,
            'changed' => false, 'notes' => $notes, 'reviewed_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'policy.reviewed', $id, 'Policy reviewed with no change: ' . $p->title . '. Next review ' . $next . ($notes ? ' — ' . $notes : ''));

        return response()->json(['ok' => true, 'next_review_due' => $next]);
    }

    /* ─────────────────────── contraventions (s.6.1 monitoring) ─────────────────────── */

    /** GET /policy-contraventions?centre_id= */
    public function contraventions(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $centres = $this->centres($scope, $agencyId);
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $cid = (int) $request->input('centre_id');
        $q = DB::table('policy_contraventions as c')->join('policies as p', 'p.id', '=', 'c.policy_id')->leftJoin('users as u', 'u.id', '=', 'c.recorded_by')
            ->where('c.agency_id', $agencyId);
        if (! $scope['all']) $q->whereIn('c.centre_id', $scope['centres'] ?: [0]);
        if ($cid) $q->where('c.centre_id', $cid);
        $rows = $q->orderByDesc('c.occurred_at')->limit(500)
            ->get(['c.id', 'c.policy_id', 'p.title', 'c.centre_id', 'c.occurred_at', 'c.description', 'c.action_taken', 'c.staff_involved',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as by_name")])
            ->map(fn ($c) => ['id' => (int) $c->id, 'policy_id' => (int) $c->policy_id, 'policy_title' => $c->title, 'centre_name' => $centres[(int) $c->centre_id] ?? (string) DB::table('centres')->where('id', $c->centre_id)->value('name'),
                'occurred_at' => $this->fmt($c->occurred_at, $tz), 'description' => $c->description, 'action_taken' => $c->action_taken, 'staff_involved' => $c->staff_involved, 'by_name' => $c->by_name])->values();

        return response()->json(['contraventions' => $rows]);
    }

    /** POST /policies/{id}/contraventions {centre_id, occurred_at (Y-m-d\TH:i agency time), description, action_taken?, staff_involved?} */
    public function recordContravention(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $p = $this->policy($agencyId, $id);
        abort_unless($this->canRead($scope, $p), 404);
        $d = $request->validate([
            'centre_id' => ['required', 'integer'],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'description' => ['required', 'string', 'min:3', 'max:5000'],
            'action_taken' => ['nullable', 'string', 'max:5000'],
            'staff_involved' => ['nullable', 'string', 'max:500'],
        ]);
        $centres = $this->centres($scope, $agencyId);
        abort_unless(isset($centres[(int) $d['centre_id']]), 403, 'That centre is not one of yours.');
        if ($p->centre_id) abort_unless((int) $p->centre_id === (int) $d['centre_id'], 422, 'This policy belongs to a different centre.');
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $at = Carbon::createFromFormat('Y-m-d\TH:i', $d['occurred_at'], $tz);
        abort_if($at->gt(Carbon::now($tz)->addMinutes(5)), 422, 'That time is in the future.');
        $cid = DB::table('policy_contraventions')->insertGetId(['agency_id' => $agencyId, 'policy_id' => $id, 'centre_id' => (int) $d['centre_id'],
            'occurred_at' => $at->copy()->utc(), 'description' => trim($d['description']), 'action_taken' => isset($d['action_taken']) ? trim((string) $d['action_taken']) : null,
            'staff_involved' => $d['staff_involved'] ?? null, 'recorded_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'policy.contravention_recorded', $id, 'Contravention of "' . $p->title . '" recorded at ' . $centres[(int) $d['centre_id']] . ', ' . $at->format('M j, Y g:i A'));

        return response()->json(['ok' => true, 'id' => $cid]);
    }

    /** POST /policies/{id}/training {user_ids[], trained_on, delivered_by?, notes?} — s.58(1.1) training given. */
    public function recordTraining(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $p = $this->policy($agencyId, $id);
        abort_unless($this->canRead($scope, $p), 404);
        $d = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:500'], 'user_ids.*' => ['integer'],
            'trained_on' => ['required', 'date', 'before_or_equal:today'],
            'delivered_by' => ['nullable', 'string', 'max:190'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $staff = array_filter(PolicyLibrary::staff($agencyId, $scope['all'] ? null : $scope['centres']), fn ($s) => PolicyLibrary::applies($p, $s));
        $ids = array_values(array_unique(array_map('intval', $d['user_ids'])));
        foreach ($ids as $u) abort_unless(isset($staff[$u]), 403, 'One of those people is not your staff, or this policy does not apply to them.');
        $rows = [];
        foreach ($ids as $u) $rows[] = ['policy_id' => $id, 'policy_version_id' => $p->current_version_id, 'agency_id' => $agencyId, 'user_id' => $u,
            'trained_on' => $d['trained_on'], 'delivered_by' => $d['delivered_by'] ?? null, 'notes' => $d['notes'] ?? null,
            'recorded_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('policy_trainings')->insert($rows);
        $this->audit($request, $agencyId, 'policy.training_recorded', $id, 'Training on "' . $p->title . '" recorded for ' . count($ids) . ' staff on ' . $d['trained_on']
            . ': ' . implode(', ', array_map(fn ($u) => $staff[$u]['name'], $ids)));

        return response()->json(['ok' => true, 'count' => count($ids)]);
    }

    /* ─────────────────────────── training matrix ─────────────────────────── */

    /** GET /policies/matrix?centre_id=&all=0|1&format=csv */
    public function matrix(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        $centres = $this->centres($scope, $agencyId);
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $cid = (int) $request->input('centre_id');
        $limit = $cid && isset($centres[$cid]) ? [$cid] : ($scope['all'] ? null : $scope['centres']);
        $staff = PolicyLibrary::staff($agencyId, $limit);
        $q = DB::table('policies')->where('agency_id', $agencyId)->where('archived', false)->whereNotNull('current_version_id');
        if (! $request->boolean('all')) $q->where('required', true);
        if (! $scope['all']) $q->where(fn ($w) => $w->whereNull('centre_id')->orWhereIn('centre_id', $scope['centres'] ?: [0]));
        if ($cid && isset($centres[$cid])) $q->where(fn ($w) => $w->whereNull('centre_id')->orWhere('centre_id', $cid));
        $policies = $q->orderBy('title')->get(['id', 'title', 'centre_id', 'current_version_id', 'required'])->all();
        $versions = DB::table('policy_versions')->whereIn('id', array_map(fn ($p) => $p->current_version_id, $policies) ?: [0])->pluck('version', 'id');
        $grid = PolicyLibrary::grid($policies, array_keys($staff));
        $rows = [];
        foreach ($staff as $s) {
            $cells = []; $out = 0;
            foreach ($policies as $p) {
                if (! PolicyLibrary::applies($p, $s)) { $cells[] = ['state' => 'na']; continue; }
                $g = $grid[(int) $p->id][$s['id']];
                if ($g['state'] !== 'signed') $out++;
                $cells[] = ['state' => $g['state'], 'at' => $g['at'] ? Carbon::parse($g['at'], 'UTC')->setTimezone($tz)->format('Y-m-d') : null, 'version' => $g['version'], 'trained_on' => $g['trained_on']];
            }
            $rows[] = ['user_id' => $s['id'], 'name' => $s['name'], 'roles' => $s['roles'],
                'centres' => implode(', ', array_map(fn ($c) => $centres[$c] ?? '', array_values(array_intersect($s['centres'], array_keys($centres))))) ?: ($s['agency_wide'] ? 'Agency-wide' : ''),
                'outstanding' => $out, 'cells' => $cells];
        }
        $cols = array_map(fn ($p) => ['id' => (int) $p->id, 'title' => $p->title, 'version' => (int) ($versions[$p->current_version_id] ?? 0), 'required' => (bool) $p->required], $policies);

        if ($request->input('format') === 'csv') {
            $label = ['signed' => 'Signed', 'outdated' => 'Out of date', 'outstanding' => 'Outstanding', 'na' => 'n/a'];
            $fh = fopen('php://temp', 'r+');
            $hdr = ['Staff member', 'Centre(s)', 'Outstanding'];
            foreach ($cols as $c) $hdr[] = $c['title'] . ' (v' . $c['version'] . ')';
            fputcsv($fh, $hdr);
            foreach ($rows as $r) {
                $line = [$r['name'], $r['centres'], $r['outstanding']];
                foreach ($r['cells'] as $c) {
                    $t = $label[$c['state']] ?? $c['state'];
                    if (! empty($c['at'])) $t .= ' ' . $c['at'] . ($c['state'] === 'outdated' ? ' (v' . $c['version'] . ')' : '');
                    if (! empty($c['trained_on'])) $t .= '; trained ' . $c['trained_on'];
                    $line[] = $t;
                }
                fputcsv($fh, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $line));
            }
            rewind($fh);
            $csv = stream_get_contents($fh);
            fclose($fh);
            $this->audit($request, $agencyId, 'policy.matrix_exported', $agencyId, 'Policy training matrix exported (' . count($rows) . ' staff × ' . count($cols) . ' policies)', 'agency');

            return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="policy-training-matrix-' . PolicyLibrary::today($agencyId) . '.csv"', 'Cache-Control' => 'private, no-store']);
        }

        return response()->json(['policies' => $cols, 'staff' => $rows, 'centres' => collect($centres)->map(fn ($n, $i) => ['id' => (int) $i, 'name' => $n])->values()]);
    }

    /** POST /policies/reminders {enabled} — admins. Weekly reminders are ON unless switched off. */
    public function reminders(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = $this->manager($request, $agencyId);
        abort_unless($scope['all'], 403, 'Agency admins only.');
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        DB::transaction(function () use ($agencyId, $on) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['policy_reminders'] = ['enabled' => $on];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        $this->audit($request, $agencyId, 'policy.reminders', $agencyId, 'Weekly policy reminders switched ' . ($on ? 'on' : 'off'), 'agency');

        return response()->json(['ok' => true, 'enabled' => $on]);
    }

    /* ─────────────────────────── staff side ─────────────────────────── */

    /** May this caller open this version? Staff it applies to, or a manager who can see the policy. */
    private function versionFor(Request $request, int $agencyId, int $versionId): array
    {
        $v = DB::table('policy_versions')->where('id', $versionId)->where('agency_id', $agencyId)->first();
        abort_unless($v, 404);
        $p = $this->policy($agencyId, (int) $v->policy_id);
        $uid = (int) $request->user()->id;
        $scope = $this->scope($request, $agencyId);
        $ok = $scope && $this->canRead($scope, $p);
        if (! $ok) {
            $s = PolicyLibrary::staffEntry($agencyId, $uid);
            $ok = $s && ! $p->archived && PolicyLibrary::applies($p, $s);
        }
        abort_unless($ok, 404);

        return [$v, $p];
    }

    private function recordView(int $versionId, int $userId): void
    {
        try {
            $n = DB::table('policy_version_views')->where('policy_version_id', $versionId)->where('user_id', $userId)->update(['last_viewed_at' => now()]);
            if (! $n) DB::table('policy_version_views')->insert(['policy_version_id' => $versionId, 'user_id' => $userId, 'first_viewed_at' => now(), 'last_viewed_at' => now()]);
        } catch (\Throwable $e) {}
    }

    /** GET /policy-versions/{id} — the text (as safe HTML) and file info; records that it was opened. */
    public function version(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        [$v, $p] = $this->versionFor($request, $agencyId, $id);
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        if (! $v->file_path) $this->recordView($id, (int) $request->user()->id);

        return response()->json(['id' => (int) $v->id, 'policy_id' => (int) $p->id, 'title' => $p->title, 'version' => (int) $v->version,
            'body_html' => $v->body_html, 'has_file' => (bool) $v->file_path, 'file_name' => $v->file_name, 'file_mime' => $v->file_mime,
            'change_summary' => $v->change_summary, 'published_at' => $this->fmt($v->published_at, $tz),
            'agency_name' => (string) DB::table('agencies')->where('id', $agencyId)->value('name')]);
    }

    /** GET /policy-versions/{id}/file — the uploaded document; records that it was opened. */
    public function file(Request $request, int $id)
    {
        $agencyId = $this->agencyId($request);
        [$v] = $this->versionFor($request, $agencyId, $id);
        abort_unless($v->file_path && Storage::disk('local')->exists($v->file_path), 404);
        $this->recordView($id, (int) $request->user()->id);

        return Storage::disk('local')->response($v->file_path, $v->file_name ?: 'policy', ['Cache-Control' => 'private, no-store']);
    }

    /** GET /my-policies — what I still have to sign, and what I have signed. */
    public function mine(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $uid = (int) $request->user()->id;
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $staff = PolicyLibrary::staffEntry($agencyId, $uid);
        if (! $staff) return response()->json(['is_staff' => false, 'outstanding' => [], 'signed' => []]);
        $out = array_map(function ($r) use ($tz, $uid, $agencyId) {
            $prev = DB::table('policy_acknowledgements as a')->join('policy_versions as v', 'v.id', '=', 'a.policy_version_id')
                ->where('a.policy_id', $r->id)->where('a.user_id', $uid)->orderByDesc('v.version')->value('v.version');
            $viewed = DB::table('policy_version_views')->where('policy_version_id', $r->version_id)->where('user_id', $uid)->exists();
            $hasFile = (bool) DB::table('policy_versions')->where('id', $r->version_id)->value('file_path');

            return ['policy_id' => (int) $r->id, 'title' => $r->title, 'required' => (bool) $r->required, 'category' => $r->category,
                'version_id' => (int) $r->version_id, 'version' => (int) $r->version, 'change_summary' => $r->change_summary,
                'published_at' => $this->fmt($r->published_at, $tz), 'previously_signed_version' => $prev ? (int) $prev : null,
                'viewed' => $viewed, 'has_file' => $hasFile];
        }, PolicyLibrary::outstandingFor($agencyId, $uid));
        $signed = DB::table('policy_acknowledgements as a')->join('policy_versions as v', 'v.id', '=', 'a.policy_version_id')->join('policies as p', 'p.id', '=', 'a.policy_id')
            ->where('a.user_id', $uid)->where('p.agency_id', $agencyId)->where('p.archived', false)->whereColumn('p.current_version_id', 'a.policy_version_id')
            ->orderBy('p.title')->get(['p.id', 'p.title', 'v.id as version_id', 'v.version', 'a.acknowledged_at', 'a.typed_name'])
            ->map(fn ($r) => ['policy_id' => (int) $r->id, 'title' => $r->title, 'version_id' => (int) $r->version_id, 'version' => (int) $r->version,
                'signed_at' => $this->fmt($r->acknowledged_at, $tz), 'typed_name' => $r->typed_name])->values();
        $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);

        return response()->json(['is_staff' => true, 'outstanding' => $out, 'signed' => $signed, 'full_name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))]);
    }

    /** POST /my-policies/{versionId}/acknowledge {typed_name, agree:true} — e-sign "I have read and understand". */
    public function acknowledge(Request $request, int $versionId): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $uid = (int) $request->user()->id;
        $d = $request->validate(['typed_name' => ['required', 'string', 'min:3', 'max:190'], 'agree' => ['required', 'accepted']]);
        $staff = PolicyLibrary::staffEntry($agencyId, $uid);
        abort_unless($staff, 403, 'Policy sign-offs are for staff.');
        $v = DB::table('policy_versions')->where('id', $versionId)->where('agency_id', $agencyId)->first();
        abort_unless($v, 404);
        $p = $this->policy($agencyId, (int) $v->policy_id);
        abort_unless(! $p->archived && PolicyLibrary::applies($p, $staff), 404);
        abort_unless((int) $p->current_version_id === $versionId, 409, 'This policy has been updated since you opened it. Please read the new version.');
        $view = DB::table('policy_version_views')->where('policy_version_id', $versionId)->where('user_id', $uid)->first();
        abort_unless($view, 422, 'Open and read the policy first.');
        $typed = preg_replace('/\s+/u', ' ', trim($d['typed_name']));
        if (DB::table('policy_acknowledgements')->where('policy_version_id', $versionId)->where('user_id', $uid)->exists()) {
            return response()->json(['ok' => true, 'already' => true]);
        }
        $statement = 'I have read and understand ' . mb_substr($p->title, 0, 150) . ' (version ' . $v->version . ').';
        try {
            DB::table('policy_acknowledgements')->insert(['policy_version_id' => $versionId, 'policy_id' => (int) $p->id, 'agency_id' => $agencyId, 'user_id' => $uid,
                'acknowledged_at' => now(), 'method' => 'e-sign', 'typed_name' => $typed, 'signature_data' => null, 'statement' => mb_substr($statement, 0, 255),
                'viewed_at' => $view->first_viewed_at, 'ip' => substr((string) $request->ip(), 0, 45), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(), 'updated_at' => now()]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['ok' => true, 'already' => true]);   // a double tap
        }
        $this->audit($request, $agencyId, 'policy.acknowledged', (int) $p->id, $staff['name'] . ' signed "' . $p->title . '" version ' . $v->version . ' as "' . $typed . '"');

        return response()->json(['ok' => true]);
    }
}
