<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\EducatorPoints;
use App\Support\HrLeave;
use App\Support\HrLeaving;
use App\Support\HrTalent;
use App\Support\Notify;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 6 (2026-11-27): STAFF DIRECTORY, ORG CHART AND RECOGNITION.
 *
 * WHO SEES WHOM (one rule, scope()): an agency admin the agency's staff; everyone else the staff of the centres they
 * work at (Visibility::centreIds — a director their own centres). Always the active agency, the agency's own people
 * only (HrLeave::staffIds drops super admins, integration and reseller accounts and closed accounts), leavers dropped.
 * No personal email or phone is shown unless that person chose to share it (hr_directory_shares), for every viewer.
 *
 * ORG CHART: drawn from hr_employee_files.manager_user_id (the HR file's manager), not from teams. A manager outside
 * the viewer's centres appears by name and job title only, so the line upward is not cut.
 *
 * RECOGNITION (hr_kudos): a colleague thanks a colleague with value tags and a message on a team feed. No leaderboard,
 * no prizes, nobody thanks themselves. Points are EducatorPoints' own (activity 'kudos', points and daily cap set in
 * Award settings, worked out from these rows like every other activity), so there is still one points system. An
 * optional monthly allowance (agencies.settings.hr_recognition.allowance, 0 = no limit) caps how many each person gives.
 */
final class HrPeopleController extends Controller
{
    use HrAccess;

    public const DEFAULT_TAGS = ['Teamwork', 'Care for children', 'Above and beyond', 'Safety first', 'Great communication', 'Learning together'];

    /** The staff this caller may see in the directory / feed. @return int[] */
    private function scope(Request $request, int $aid): array
    {
        $me = (int) $request->user()->id;
        $all = HrLeave::staffIds($aid);
        abort_unless($this->hrIsAdmin($request) || in_array($me, $all, true), 404, 'The staff directory is kept by your agency.');
        $ids = $this->hrIsAdmin($request) ? $all : HrLeave::staffIds($aid, Visibility::centreIds($aid, $request->user()));
        $gone = rescue(fn () => HrLeaving::leaverIds($aid), [], false);

        return array_values(array_diff(array_unique(array_merge($ids, in_array($me, $all, true) ? [$me] : [])), $gone));
    }

    public static function recognition(int $aid): array
    {
        $raw = DB::table('agencies')->where('id', $aid)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $r = is_array($s['hr_recognition'] ?? null) ? $s['hr_recognition'] : [];
        $tags = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 40), (array) ($r['tags'] ?? self::DEFAULT_TAGS)), fn ($t) => $t !== ''));

        return ['enabled' => array_key_exists('enabled', $r) ? (bool) $r['enabled'] : true, 'tags' => $tags ?: self::DEFAULT_TAGS,
            'allowance' => max(0, min(100, (int) ($r['allowance'] ?? 0)))];
    }

    /** GET hr/directory — the directory and org chart for this caller. */
    public function directory(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $ids = $this->scope($request, $aid);
        $me = (int) $request->user()->id;
        $users = DB::table('users')->whereIn('id', $ids ?: [0])->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'preferred_name', 'photo_url', 'sex', 'email', 'phone']);
        $files = DB::table('hr_employee_files')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->get(['user_id', 'job_title', 'manager_user_id'])->keyBy('user_id');
        $shares = DB::table('hr_directory_shares')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->get()->keyBy('user_id');
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id');
        $order = array_flip(['agency_admin', 'centre_director', 'educator', 'home_visitor']);
        $labels = ['agency_admin' => 'Agency admin', 'centre_director' => 'Centre director', 'educator' => 'Educator', 'home_visitor' => 'Home visitor'];
        $roles = [];
        foreach (DB::table('role_assignments')->whereIn('user_id', $ids ?: [0])->where('active', 1)->whereIn('role', HrLeave::STAFF_ROLES)
            ->where(fn ($w) => $w->where('agency_id', $aid)->orWhereIn('centre_id', $centres->keys()->all() ?: [0]))->get(['user_id', 'role', 'centre_id']) as $r) {
            $u = (int) $r->user_id;
            $roles[$u]['roles'][$r->role] = true;
            if ($r->centre_id && isset($centres[$r->centre_id])) $roles[$u]['centres'][(int) $r->centre_id] = $centres[$r->centre_id];
        }
        $staff = HrLeave::staffIds($aid);
        $outside = [];
        $people = $users->map(function ($u) use ($files, $shares, $roles, $order, $labels, $staff, $ids, &$outside) {
            $id = (int) $u->id;
            $rs = array_keys($roles[$id]['roles'] ?? []);
            usort($rs, fn ($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
            $f = $files[$id] ?? null;
            $mgr = $f && $f->manager_user_id && (int) $f->manager_user_id !== $id && in_array((int) $f->manager_user_id, $staff, true) ? (int) $f->manager_user_id : null;
            if ($mgr && ! in_array($mgr, $ids, true)) $outside[$mgr] = true;
            $sh = $shares[$id] ?? null;

            return ['id' => $id, 'name' => trim(($u->preferred_name ?: $u->first_name) . ' ' . $u->last_name), 'photo_url' => $u->photo_url, 'sex' => $u->sex,
                'job_title' => $f && $f->job_title ? $f->job_title : null, 'role' => $rs[0] ?? null, 'role_label' => isset($rs[0]) ? $labels[$rs[0]] : '',
                'centres' => array_values($roles[$id]['centres'] ?? []), 'manager_id' => $mgr,
                'email' => $sh && $sh->share_email ? $u->email : null, 'phone' => $sh && $sh->share_phone && $u->phone ? $u->phone : null];
        })->values()->all();
        $out = [];
        if ($outside) {
            $ot = DB::table('hr_employee_files')->where('agency_id', $aid)->whereIn('user_id', array_keys($outside))->pluck('job_title', 'user_id');
            foreach (DB::table('users')->whereIn('id', array_keys($outside))->get(['id', 'first_name', 'last_name', 'preferred_name']) as $m) {
                $out[] = ['id' => (int) $m->id, 'name' => trim(($m->preferred_name ?: $m->first_name) . ' ' . $m->last_name), 'job_title' => $ot[$m->id] ?? null, 'outside' => true];
            }
        }
        $mine = $shares[$me] ?? null;

        return response()->json(['people' => $people, 'outside' => $out, 'me' => $me,
            'share' => ['email' => (bool) ($mine->share_email ?? false), 'phone' => (bool) ($mine->share_phone ?? false)],
            'is_manager' => $this->hrIsManager($request)]);
    }

    /** PUT hr/me/directory {share_email, share_phone} — what I show colleagues. */
    public function saveShare(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 404, 'The staff directory is kept by your agency.');
        $d = $request->validate(['share_email' => 'required|boolean', 'share_phone' => 'required|boolean']);
        DB::table('hr_directory_shares')->updateOrInsert(['agency_id' => $aid, 'user_id' => $me],
            ['share_email' => (bool) $d['share_email'], 'share_phone' => (bool) $d['share_phone'], 'updated_at' => now(), 'created_at' => now()]);
        $this->hrNote($aid, 'hr.directory_share_set', 'user', $me, ['email' => (bool) $d['share_email'], 'phone' => (bool) $d['share_phone']]);

        return response()->json(['ok' => true]);
    }

    // ── recognition ──────────────────────────────────────────────────────────────────────────────

    private function given(int $aid, int $me): int
    {
        [$from, $to] = EducatorPoints::window($aid, EducatorPoints::month($aid, null));

        return (int) DB::table('hr_kudos')->where('agency_id', $aid)->where('from_user_id', $me)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
    }

    /** GET hr/kudos?before=ID — the team feed (newest first, 40 at a time) and what I can give. */
    public function feed(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $ids = $this->scope($request, $aid);
        $me = (int) $request->user()->id;
        $cfg = self::recognition($aid);
        $before = (int) $request->query('before', 0);
        $rows = DB::table('hr_kudos')->where('agency_id', $aid)->whereNull('hidden_at')
            ->where(fn ($w) => $w->whereIn('to_user_id', $ids ?: [0])->orWhere('from_user_id', $me))
            ->when($before > 0, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit(40)->get();
        $names = DB::table('users')->whereIn('id', $rows->pluck('from_user_id')->merge($rows->pluck('to_user_id'))->unique()->all() ?: [0])
            ->get(['id', 'first_name', 'last_name', 'preferred_name', 'photo_url', 'sex'])->keyBy('id');
        $tz = AgencyTime::tz($aid);
        $person = function ($id) use ($names) {
            $u = $names[$id] ?? null;

            return ['id' => (int) $id, 'name' => $u ? trim(($u->preferred_name ?: $u->first_name) . ' ' . $u->last_name) : 'A colleague', 'photo_url' => $u->photo_url ?? null, 'sex' => $u->sex ?? null];
        };
        $manager = $this->hrIsManager($request);
        $managed = $manager ? $this->hrPeople($request, $aid) : [];
        $given = $this->given($aid, $me);

        return response()->json([
            'kudos' => $rows->map(fn ($k) => ['id' => (int) $k->id, 'from' => $person($k->from_user_id), 'to' => $person($k->to_user_id),
                'tags' => json_decode((string) $k->tags, true) ?: [], 'message' => $k->message,
                'at' => \Illuminate\Support\Carbon::parse($k->created_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i'),
                'can_remove' => (int) $k->from_user_id === $me || in_array((int) $k->to_user_id, $managed, true)])->values(),
            'more' => $rows->count() === 40, 'settings' => $cfg + ['given' => $given, 'left' => $cfg['allowance'] ? max(0, $cfg['allowance'] - $given) : null],
            'points' => ['per' => EducatorPoints::settings($aid)['points']['kudos'] ?? 0],
            'people' => DB::table('users')->whereIn('id', array_values(array_diff($ids, [$me])) ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'preferred_name'])
                ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim(($u->preferred_name ?: $u->first_name) . ' ' . $u->last_name)])->values(),
            'me' => $me, 'can_manage' => $this->hrIsAdmin($request),
        ]);
    }

    /** POST hr/kudos {to_user_id, tags[], message} — never to yourself, only to someone you can see, within the allowance. */
    public function give(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        $cfg = self::recognition($aid);
        abort_unless($cfg['enabled'], 422, 'Recognition is switched off for your agency.');
        $d = $request->validate(['to_user_id' => 'required|integer', 'tags' => 'nullable|array|max:4', 'tags.*' => 'string|max:40', 'message' => 'required|string|min:3|max:600']);
        $to = (int) $d['to_user_id'];
        abort_if($to === $me, 422, 'Kudos are for a colleague, not yourself.');
        abort_unless(in_array($to, $this->scope($request, $aid), true), 404, 'That person was not found.');
        $tags = array_values(array_intersect($cfg['tags'], array_map('strval', $d['tags'] ?? [])));
        if ($cfg['allowance']) abort_if($this->given($aid, $me) >= $cfg['allowance'], 422, 'You have given all ' . $cfg['allowance'] . ' kudos for this month. More next month.');
        $centre = DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $to)->value('primary_centre_id');
        $id = (int) DB::table('hr_kudos')->insertGetId(['agency_id' => $aid, 'from_user_id' => $me, 'to_user_id' => $to, 'centre_id' => $centre ?: null,
            'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE), 'message' => trim($d['message']), 'created_at' => now(), 'updated_at' => now()]);
        $from = $request->user()->preferred_name ?: $request->user()->first_name;
        try {
            Notify::write(['user_id' => $to, 'agency_id' => $aid, 'type' => 'hr', 'title' => '🙌 Kudos from ' . ($from ?: 'a colleague'),
                'body' => mb_substr(($tags ? implode(' · ', $tags) . ': ' : '') . trim($d['message']), 0, 160),
                'data' => json_encode(['link' => '#my-hr', 'kind' => 'own']), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
        $this->hrNote($aid, 'hr.kudos_given', 'user', $to, ['kudos_id' => $id, 'tags' => $tags]);

        return response()->json(['id' => $id], 201);
    }

    /** POST hr/kudos/{id}/remove — the sender, or a manager of the person thanked. Kept, hidden, no longer counts. */
    public function remove(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        $k = DB::table('hr_kudos')->where('id', $id)->where('agency_id', $aid)->whereNull('hidden_at')->first();
        abort_unless($k, 404, 'That was not found.');
        $ok = (int) $k->from_user_id === $me || ($this->hrIsManager($request) && in_array((int) $k->to_user_id, $this->hrPeople($request, $aid), true));
        abort_unless($ok, 403, 'Only the person who gave it or a manager can take it down.');
        DB::table('hr_kudos')->where('id', $id)->update(['hidden_at' => now(), 'hidden_by_id' => $me, 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.kudos_removed', 'user', (int) $k->to_user_id, ['kudos_id' => $id]);

        return response()->json(['ok' => true]);
    }

    /** GET hr/recognition/settings · PUT (agency admins) {enabled, tags[], allowance}. */
    public function settings(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $p = EducatorPoints::settings($aid);

        return response()->json(self::recognition($aid) + ['defaults' => self::DEFAULT_TAGS, 'can_edit' => $this->hrIsAdmin($request),
            'points' => (int) ($p['points']['kudos'] ?? 0), 'cap' => (int) ($p['caps']['kudos'] ?? 0)]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['enabled' => 'required|boolean', 'tags' => 'required|array|min:1|max:12', 'tags.*' => 'string|max:40', 'allowance' => 'nullable|integer|min:0|max:100']);
        $tags = array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 40), $d['tags']), fn ($t) => $t !== '')));
        abort_unless($tags, 422, 'Keep at least one value.');
        $next = ['enabled' => (bool) $d['enabled'], 'tags' => $tags, 'allowance' => (int) ($d['allowance'] ?? 0)];
        DB::transaction(function () use ($aid, $next) {
            $raw = DB::table('agencies')->where('id', $aid)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['hr_recognition'] = $next;
            DB::table('agencies')->where('id', $aid)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        $this->hrNote($aid, 'hr.recognition_settings_saved', 'agency', $aid, $next);

        return response()->json(self::recognition($aid));
    }
}
