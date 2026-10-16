<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\AgencyTime;
use App\Support\Audience;
use App\Support\Audit;
use App\Support\CentreScope;
use App\Support\Live;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HAZARD REGISTER (2026-10-13; Ontario O. Reg. 137/15 s.24.3 as added by O. Reg. 197/26,
 * in force 2027-01-01): a licensee tells parents about any NON-ROUTINE hazard at the
 * premises and the steps taken to deal with it.
 *
 * Who: anyone on staff at the centre may REPORT a hazard (educators and, since 2026-10-15,
 * home visitors — they see it first). Directors (their own centres) and agency/platform
 * admins manage: edit, resolve, and send the parent notice and, later, the resolution notice.
 *
 * Who hears (2026-10-15, Anthony: "is this wired up to send out an email to parents a copy
 * for educators and admin/directors?"): a REPORT alerts the centre's directors AND the
 * agency admins (bell + email), not only the directors; every PARENT notice and
 * resolution notice is copied (bell + email, marked as a copy) to the centre's educators,
 * home visitors and directors and to the agency admins.
 *
 * Parent notice = bell + email (never SMS) to the guardians of the centre's CURRENT
 * families (Live::families with an enrolled child), each address separately, through
 * AgencyMailer so the agency/centre/person switches still apply. What actually went is
 * read back from email_logs: a suppressed message is not reported as sent.
 */
final class HazardController extends Controller
{
    public const SEVERITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

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

    /** Open centres this caller may see, [id => name]. */
    private function centres(Request $request, int $agencyId): array
    {
        $scope = CentreScope::ids($request, $agencyId);
        $q = DB::table('centres')->whereIn('id', Live::centreIds($agencyId) ?: [0]);
        if ($scope !== null) $q->whereIn('id', $scope ?: [0]);

        return $q->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Admins anywhere in the agency; a director only at a centre they direct. Educators never. */
    private function canManage(Request $request, int $agencyId, int $centreId): bool
    {
        foreach (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->get(['role', 'agency_id', 'centre_id']) as $r) {
            if ($r->role === 'platform_admin') return true;
            if ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId) return true;
            if ($r->role === 'centre_director' && (int) $r->centre_id === $centreId) return true;
        }

        return false;
    }

    /** The hazard, if it is in this agency and at a centre the caller can see; 404 otherwise. */
    private function find(Request $request, int $agencyId, int $id): object
    {
        $row = DB::table('centre_hazards')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row && isset($this->centres($request, $agencyId)[(int) $row->centre_id]), 404);

        return $row;
    }

    private function audit(Request $request, int $agencyId, string $action, int $id, string $summary): void
    {
        try {
            Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'centre_hazard', 'entity_id' => $id,
                'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private function name(?int $uid): string
    {
        if (! $uid) return '';
        $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);

        return $u ? trim($u->first_name . ' ' . $u->last_name) : '';
    }

    /** GET /hazards?status=open|resolved|all&centre_id= */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $centres = $this->centres($request, $agencyId);
        $tz = AgencyTime::tz($agencyId);
        $cid = (int) $request->input('centre_id');
        $ids = $cid && isset($centres[$cid]) ? [$cid] : array_keys($centres);
        $status = in_array($request->input('status'), ['open', 'resolved', 'all'], true) ? $request->input('status') : 'open';

        $q = DB::table('centre_hazards')->where('agency_id', $agencyId)->whereIn('centre_id', $ids ?: [0]);
        if ($status !== 'all') $q->where('status', $status);
        $fmt = fn ($v) => $v ? Carbon::parse($v, 'UTC')->setTimezone($tz)->format('Y-m-d H:i') : null;
        $manage = [];
        foreach (array_keys($centres) as $i) $manage[$i] = $this->canManage($request, $agencyId, (int) $i);
        $names = [];
        $rows = $q->orderByRaw("status = 'open' desc")->orderByDesc('identified_at')->limit(500)->get()->map(function ($r) use ($centres, $fmt, $manage, &$names) {
            foreach (['created_by', 'notified_by', 'resolution_notified_by'] as $k) {
                $u = (int) $r->$k;
                if ($u && ! isset($names[$u])) $names[$u] = $this->name($u);
            }

            return ['id' => (int) $r->id, 'centre_id' => (int) $r->centre_id, 'centre_name' => $centres[$r->centre_id] ?? '', 'title' => $r->title,
                'description' => $r->description, 'location' => $r->location, 'severity' => $r->severity, 'steps_taken' => $r->steps_taken,
                'status' => $r->status, 'resolution_note' => $r->resolution_note, 'notify_parents' => (bool) $r->notify_parents,
                'identified_local' => $fmt($r->identified_at), 'resolved_local' => $fmt($r->resolved_at),
                'notified_local' => $fmt($r->notified_at), 'notified_by_name' => $names[(int) $r->notified_by] ?? '', 'notified_families' => $r->notified_families !== null ? (int) $r->notified_families : null,
                'resolution_notified_local' => $fmt($r->resolution_notified_at), 'resolution_notified_by_name' => $names[(int) $r->resolution_notified_by] ?? '',
                'reported_by_name' => $names[(int) $r->created_by] ?? '', 'can_manage' => $manage[(int) $r->centre_id] ?? false];
        });

        return response()->json(['timezone' => $tz, 'status' => $status, 'severities' => self::SEVERITIES,
            'centres' => collect($centres)->map(fn ($n, $i) => ['id' => (int) $i, 'name' => $n, 'can_manage' => $manage[$i] ?? false])->values(),
            'can_manage_any' => (bool) array_filter($manage),
            'open_count' => DB::table('centre_hazards')->where('agency_id', $agencyId)->whereIn('centre_id', array_keys($centres) ?: [0])->where('status', 'open')->count(),
            'hazards' => $rows]);
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'centre_id' => [$create ? 'required' : 'prohibited', 'integer'],
            'title' => [$req, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'location' => ['nullable', 'string', 'max:255'],
            'identified_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'severity' => ['nullable', 'in:' . implode(',', array_keys(self::SEVERITIES))],
            'steps_taken' => ['nullable', 'string', 'max:4000'],
            'notify_parents' => ['nullable', 'boolean'],
        ];
    }

    private function when(int $agencyId, ?string $local): Carbon
    {
        $tz = AgencyTime::tz($agencyId);
        if (! $local) return now();
        $at = Carbon::createFromFormat('Y-m-d\TH:i', $local, $tz);
        abort_if($at->gt(Carbon::now($tz)->addMinutes(5)), 422, 'A hazard cannot be identified in the future.');

        return $at->copy()->utc();
    }

    /** POST /hazards — anyone on staff at that centre may report; directors/admins are told. */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $centres = $this->centres($request, $agencyId);
        $d = $request->validate($this->rules(true));
        $cid = (int) $d['centre_id'];
        abort_unless(isset($centres[$cid]), 403, 'That centre is not one of yours.');
        $manager = $this->canManage($request, $agencyId, $cid);
        $uid = (int) $request->user()->id;
        $id = DB::table('centre_hazards')->insertGetId([
            'agency_id' => $agencyId, 'centre_id' => $cid, 'title' => trim($d['title']), 'description' => $d['description'] ?? null,
            'location' => $d['location'] ?? null, 'identified_at' => $this->when($agencyId, $d['identified_at'] ?? null),
            'severity' => $d['severity'] ?? 'medium', 'steps_taken' => $d['steps_taken'] ?? null, 'status' => 'open',
            'notify_parents' => $manager && ! empty($d['notify_parents']), 'created_by' => $uid, 'updated_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($request, $agencyId, 'hazard.reported', $id, 'Hazard reported at ' . $centres[$cid] . ': ' . trim($d['title']));

        // Tell the people who manage it — the centre's directors AND the agency admins. Not the reporter.
        {
            $to = self::staffAt($agencyId, $cid, ['centre_director'], true)->where('id', '!=', $uid);
            $who = $this->name($uid);
            $sev = self::SEVERITIES[$d['severity'] ?? 'medium'];
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $html = EmailTemplate::wrap($agencyId, '<p style="margin:0 0 12px;font-size:15px;color:#334155"><b>' . $e($who) . '</b> reported a hazard at <b>' . $e($centres[$cid]) . '</b>.</p>'
                . EmailTemplate::calloutBox('<strong>Hazard:</strong> ' . $e($d['title']) . (! empty($d['location']) ? '<br><strong>Where:</strong> ' . $e($d['location']) : '')
                    . '<br><strong>Severity:</strong> ' . $e($sev) . (! empty($d['steps_taken']) ? '<br><strong>Steps taken so far:</strong> ' . nl2br($e($d['steps_taken'])) : ''), ($d['severity'] ?? '') === 'high' ? 'warning' : 'info')
                . (! empty($d['description']) ? '<p style="margin:12px 0 0;font-size:14px;color:#334155">' . nl2br($e($d['description'])) . '</p>' : '')
                . '<p style="margin:14px 0 0;font-size:14px;color:#334155">Open KiddieTrac → Hazards to record the steps taken and, if it is not routine, notify parents (Ontario s.24.3).</p>',
                ['eyebrow' => 'HAZARD REPORTED', 'title' => $d['title'], 'subtitle' => $centres[$cid]]);
            foreach ($to as $u) {
                Notify::write(['user_id' => (int) $u->id, 'type' => 'hazard', 'title' => 'Hazard reported — ' . $centres[$cid], 'body' => $d['title'] . (! empty($d['location']) ? ' (' . $d['location'] . ')' : '') . ' · reported by ' . $who,
                    'data' => json_encode(['link' => '#hazards', 'hazard_id' => $id])]);
                if (filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)) {
                    try { AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to((string) $u->email)->subject('Hazard reported at ' . $centres[$cid] . ': ' . $d['title'])); }
                    catch (\Throwable $ex) { report($ex); }
                }
            }
        }

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** PATCH /hazards/{id} — directors/admins. */
    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->find($request, $agencyId, $id);
        abort_unless($this->canManage($request, $agencyId, (int) $row->centre_id), 403, 'Only a director of this centre or an admin can change a hazard.');
        $d = $request->validate($this->rules(false) + ['resolution_note' => ['nullable', 'string', 'max:4000']]);
        $set = ['updated_by' => (int) $request->user()->id, 'updated_at' => now()];
        foreach (['title', 'description', 'location', 'severity', 'steps_taken', 'resolution_note'] as $k) if (array_key_exists($k, $d)) $set[$k] = $d[$k];
        if (isset($set['title'])) $set['title'] = trim((string) $set['title']);
        if (array_key_exists('notify_parents', $d)) $set['notify_parents'] = (bool) $d['notify_parents'];
        if (! empty($d['identified_at'])) $set['identified_at'] = $this->when($agencyId, $d['identified_at']);
        DB::table('centre_hazards')->where('id', $id)->update($set);
        $this->audit($request, $agencyId, 'hazard.updated', $id, 'Hazard updated: ' . ($set['title'] ?? $row->title));

        return response()->json(['ok' => true]);
    }

    /** POST /hazards/{id}/resolve { resolution_note?, steps_taken? } — directors/admins. */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->find($request, $agencyId, $id);
        abort_unless($this->canManage($request, $agencyId, (int) $row->centre_id), 403, 'Only a director of this centre or an admin can resolve a hazard.');
        abort_unless($row->status === 'open', 422, 'This hazard is already resolved.');
        $d = $request->validate(['resolution_note' => ['nullable', 'string', 'max:4000'], 'steps_taken' => ['nullable', 'string', 'max:4000']]);
        $set = ['status' => 'resolved', 'resolved_at' => now(), 'updated_by' => (int) $request->user()->id, 'updated_at' => now()];
        if (! empty($d['resolution_note'])) $set['resolution_note'] = $d['resolution_note'];
        if (! empty($d['steps_taken'])) $set['steps_taken'] = $d['steps_taken'];
        DB::table('centre_hazards')->where('id', $id)->update($set);
        $this->audit($request, $agencyId, 'hazard.resolved', $id, 'Hazard resolved: ' . $row->title);

        return response()->json(['ok' => true]);
    }

    /** POST /hazards/{id}/reopen — a resolution recorded by mistake. */
    public function reopen(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->find($request, $agencyId, $id);
        abort_unless($this->canManage($request, $agencyId, (int) $row->centre_id), 403);
        abort_unless($row->status === 'resolved', 422, 'This hazard is still open.');
        DB::table('centre_hazards')->where('id', $id)->update(['status' => 'open', 'resolved_at' => null, 'updated_by' => (int) $request->user()->id, 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'hazard.reopened', $id, 'Hazard reopened: ' . $row->title);

        return response()->json(['ok' => true]);
    }

    /**
     * The guardians who would receive a notice: current families of the centre (not
     * departed, with a child enrolled now), reachable accounts only.
     *
     * @return array{families:int, people:array<int,array{id:int,email:?string,family_id:int}>}
     */
    public static function audience(int $agencyId, int $centreId): array
    {
        if (! DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->exists() || ! Live::centreOpen($centreId)) return ['families' => 0, 'people' => []];
        $families = Live::families(DB::table('families'))->where('families.centre_id', $centreId)
            ->whereExists(function ($w) {
                Live::enrolledChildren($w->select(DB::raw(1))->from('children')->whereColumn('children.family_id', 'families.id'));
            })->pluck('families.id')->map(fn ($v) => (int) $v)->all();
        if (! $families) return ['families' => 0, 'people' => []];
        $people = Audience::excludeService(Audience::excludeOff(DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereIn('g.family_id', $families)->whereNull('u.deleted_at')))
            ->get(['u.id', 'u.email', 'g.family_id'])->unique('id')
            ->map(fn ($p) => ['id' => (int) $p->id, 'email' => $p->email, 'family_id' => (int) $p->family_id])->values()->all();

        return ['families' => count(array_unique(array_column($people, 'family_id'))), 'people' => $people];
    }

    /** GET /hazards/{id}/audience — how many families/parents a notice would reach. */
    public function audienceCount(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->find($request, $agencyId, $id);
        abort_unless($this->canManage($request, $agencyId, (int) $row->centre_id), 403);
        $a = self::audience($agencyId, (int) $row->centre_id);
        $emails = count(array_unique(array_filter(array_map(fn ($p) => filter_var((string) $p['email'], FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim((string) $p['email'])) : null, $a['people']))));

        return response()->json(['families' => $a['families'], 'parents' => count($a['people']), 'emails' => $emails]);
    }

    /**
     * Live staff to tell: the given roles AT this centre, plus (when $withAdmins) the
     * agency admins. One row per person, service and switched-off accounts excluded.
     */
    private static function staffAt(int $agencyId, int $centreId, array $centreRoles, bool $withAdmins)
    {
        $q = Audience::excludeService(Audience::excludeOff(DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->whereNull('u.deleted_at')
            ->where(function ($w) use ($agencyId, $centreId, $centreRoles, $withAdmins) {
                $w->where(fn ($x) => $x->where('ra.centre_id', $centreId)->whereIn('ra.role', $centreRoles));
                if ($withAdmins) $w->orWhere(fn ($x) => $x->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin'));
            })));

        return $q->get(['u.id', 'u.email'])->unique('id')->values();
    }

    /** POST /hazards/{id}/notify { kind: notice|resolution } — bell + email to the centre's current parents. Never SMS. */
    public function notify(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->find($request, $agencyId, $id);
        $cid = (int) $row->centre_id;
        abort_unless($this->canManage($request, $agencyId, $cid), 403, 'Only a director of this centre or an admin can notify parents.');
        $kind = $request->validate(['kind' => ['required', 'in:notice,resolution']])['kind'];
        if ($kind === 'notice') {
            abort_if(trim((string) $row->steps_taken) === '', 422, 'Record the steps taken first — the notice must tell parents what was done.');
        } else {
            abort_unless($row->status === 'resolved', 422, 'Resolve the hazard before sending the resolution notice.');
        }
        $a = self::audience($agencyId, $cid);
        abort_if(! $a['people'], 422, 'This centre has no current families with a parent account to notify.');

        $tz = AgencyTime::tz($agencyId);
        $centre = DB::table('centres')->where('id', $cid)->first(['name', 'phone', 'email']);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $idt = Carbon::parse($row->identified_at, 'UTC')->setTimezone($tz);
        $contact = trim(($centre->phone ? 'call ' . $e($centre->phone) : '') . ($centre->phone && $centre->email ? ' or ' : '') . ($centre->email ? 'email <a href="mailto:' . $e($centre->email) . '" style="color:#1F6FB2">' . $e($centre->email) . '</a>' : ''));
        $contactP = '<p style="margin:16px 0 0;font-size:14px;color:#334155;line-height:1.6">If you have any questions, please speak with the centre' . ($contact ? ' — ' . $contact : '') . '.</p>';

        if ($kind === 'notice') {
            $subject = 'Safety notice from ' . $centre->name . ': ' . $row->title;
            $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155">We want to let you know about a hazard at <b>' . $e($centre->name) . '</b> and the steps we have taken to keep the children safe.</p>'
                . EmailTemplate::calloutBox('<strong>What:</strong> ' . $e($row->title)
                    . ($row->location ? '<br><strong>Where:</strong> ' . $e($row->location) : '')
                    . '<br><strong>Identified:</strong> ' . $e($idt->format('l, F j, Y g:i A'))
                    . '<br><strong>Status:</strong> ' . ($row->status === 'resolved' ? 'Resolved' : 'Being dealt with'), $row->severity === 'high' ? 'warning' : 'info')
                . ($row->description ? '<p style="margin:14px 0 4px;font-size:13.5px;font-weight:700;color:#334155">About the hazard</p><p style="margin:0;font-size:14px;color:#334155;line-height:1.6">' . nl2br($e($row->description)) . '</p>' : '')
                . '<p style="margin:14px 0 4px;font-size:13.5px;font-weight:700;color:#334155">Steps we have taken</p><p style="margin:0;font-size:14px;color:#334155;line-height:1.6">' . nl2br($e($row->steps_taken)) . '</p>'
                . $contactP;
            $html = EmailTemplate::wrap($agencyId, $body, ['eyebrow' => 'SAFETY NOTICE', 'title' => $row->title, 'subtitle' => $centre->name,
                'preheader' => 'A hazard at ' . $centre->name . ' and the steps taken.']);
            $bellTitle = 'Safety notice — ' . $centre->name;
            $bellBody = $row->title . ($row->location ? ' (' . $row->location . ')' : '') . '. Steps taken: ' . $row->steps_taken;
        } else {
            $subject = 'Resolved at ' . $centre->name . ': ' . $row->title;
            $rt = $row->resolved_at ? Carbon::parse($row->resolved_at, 'UTC')->setTimezone($tz) : Carbon::now($tz);
            $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155">An update on the hazard at <b>' . $e($centre->name) . '</b>' . ($row->notified_at ? ' we told you about on ' . $e(Carbon::parse($row->notified_at, 'UTC')->setTimezone($tz)->format('F j')) : '') . ': it has been resolved.</p>'
                . EmailTemplate::calloutBox('<strong>What:</strong> ' . $e($row->title)
                    . ($row->location ? '<br><strong>Where:</strong> ' . $e($row->location) : '')
                    . '<br><strong>Resolved:</strong> ' . $e($rt->format('l, F j, Y')), 'success')
                . ($row->resolution_note ? '<p style="margin:14px 0 4px;font-size:13.5px;font-weight:700;color:#334155">What was done</p><p style="margin:0;font-size:14px;color:#334155;line-height:1.6">' . nl2br($e($row->resolution_note)) . '</p>'
                    : ($row->steps_taken ? '<p style="margin:14px 0 4px;font-size:13.5px;font-weight:700;color:#334155">What was done</p><p style="margin:0;font-size:14px;color:#334155;line-height:1.6">' . nl2br($e($row->steps_taken)) . '</p>' : ''))
                . $contactP;
            $html = EmailTemplate::wrap($agencyId, $body, ['eyebrow' => 'SAFETY UPDATE', 'title' => 'Resolved: ' . $row->title, 'subtitle' => $centre->name,
                'preheader' => 'The hazard at ' . $centre->name . ' has been resolved.']);
            $bellTitle = 'Hazard resolved — ' . $centre->name;
            $bellBody = $row->title . ($row->location ? ' (' . $row->location . ')' : '') . ' has been resolved.' . ($row->resolution_note ? ' ' . $row->resolution_note : '');
        }

        $start = now()->subSecond();
        Notify::write(array_map(fn ($p) => ['user_id' => $p['id'], 'type' => 'hazard_notice', 'title' => $bellTitle, 'body' => mb_substr($bellBody, 0, 1000),
            'data' => json_encode(['hazard_id' => $id, 'kind' => $kind])], $a['people']));
        $addresses = [];
        foreach ($a['people'] as $p) {
            $em = mb_strtolower(trim((string) $p['email']));
            if (filter_var($em, FILTER_VALIDATE_EMAIL)) $addresses[$em] = true;
        }
        $handed = 0; $failed = 0;
        foreach (array_keys($addresses) as $to) {   // one message per address: parents never see each other
            try { AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to($to)->subject($subject)); $handed++; }
            catch (\Throwable $ex) { $failed++; report($ex); }
        }
        // What really went: the suppression listener logs its decision during this request.
        $suppressed = $handed ? DB::table('email_logs')->where('created_at', '>=', $start)->where('subject', $subject)
            ->whereIn(DB::raw('LOWER(to_email)'), array_keys($addresses))->where('status', 'suppressed')->count() : 0;
        $sent = max(0, $handed - $suppressed);

        /* THE STAFF COPY: the same notice, marked as a copy, to the centre's educators,
           home visitors and directors and to the agency admins - so the people who will
           be asked about it at pick-up have read exactly what parents read. Never to a
           parent address (a staff member who is also a parent here already has it). */
        $staff = self::staffAt($agencyId, $cid, ['educator', 'home_visitor', 'centre_director'], true);
        $copyHtml = str_replace(
            '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155">',
            '<p style="margin:0 0 14px;padding:10px 12px;border-radius:8px;background:#F1F5F9;font-size:13px;color:#475569">Staff copy: this '
                . ($kind === 'notice' ? 'safety notice' : 'resolution notice') . ' was sent to ' . (int) $a['families'] . ' famil' . ((int) $a['families'] === 1 ? 'y' : 'ies')
                . ' at ' . $e($centre->name) . '.</p><p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155">', $html, $count);
        Notify::write($staff->map(fn ($u) => ['user_id' => (int) $u->id, 'type' => 'hazard', 'title' => 'Copy sent to parents — ' . $centre->name,
            'body' => mb_substr($bellBody, 0, 1000), 'data' => json_encode(['link' => '#hazards', 'hazard_id' => $id, 'kind' => $kind])])->all());
        $copied = 0;
        foreach ($staff as $u) {
            $em = mb_strtolower(trim((string) $u->email));
            if (! filter_var($em, FILTER_VALIDATE_EMAIL) || isset($addresses[$em])) continue;
            try { AgencyMailer::forAgency($agencyId)->html($copyHtml, fn ($m) => $m->to($em)->subject('Copy: ' . $subject)); $copied++; }
            catch (\Throwable $ex) { report($ex); }
        }

        $set = $kind === 'notice'
            ? ['notified_at' => now(), 'notified_by' => (int) $request->user()->id, 'notified_families' => $a['families'], 'notify_parents' => true]
            : ['resolution_notified_at' => now(), 'resolution_notified_by' => (int) $request->user()->id];
        DB::table('centre_hazards')->where('id', $id)->update($set + ['updated_at' => now()]);
        $this->audit($request, $agencyId, $kind === 'notice' ? 'hazard.parents_notified' : 'hazard.resolution_notified', $id,
            ($kind === 'notice' ? 'Parents notified of hazard: ' : 'Parents told hazard resolved: ') . $row->title . ' — ' . $a['families'] . ' families, '
            . count($a['people']) . ' in-app, ' . $sent . ' email(s) sent' . ($suppressed ? ', ' . $suppressed . ' held back by email settings' : '') . ($failed ? ', ' . $failed . ' failed' : '')
            . '; staff copy to ' . $staff->count() . ' (' . $copied . ' email(s))');

        return response()->json(['ok' => true, 'families' => $a['families'], 'in_app' => count($a['people']), 'emails_sent' => $sent, 'emails_suppressed' => $suppressed, 'emails_failed' => $failed,
            'staff_copies' => $staff->count(), 'staff_emails' => $copied]);
    }
}
