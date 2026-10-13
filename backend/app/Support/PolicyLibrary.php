<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POLICY LIBRARY (2026-10-13) — who must sign which policy, and who has.
 *
 * O. Reg. 137/15 s.6.1 (as amended by O. Reg. 197/26): staff review every required policy
 * before working with children and whenever it changes; s.58(1.1) from 2027-01-01: training
 * on every required policy; s.46/49: the program statement is reviewed every year.
 *
 * "Staff" = active educator / home visitor / centre director role holders (the same people
 * StaffCompliance counts), at an open centre or held agency-wide. A policy with a centre_id
 * applies to staff at that centre; agency-wide policies apply to everyone.
 *
 * The Ontario catalogue below is titles and purposes only. KiddieTrac never writes the
 * policy text: the agency uploads or types its own.
 */
final class PolicyLibrary
{
    public const STAFF_ROLES = ['educator', 'home_visitor', 'centre_director'];

    public const CATEGORIES = ['Program', 'Health & safety', 'Emergencies', 'Families', 'Staffing', 'Operations', 'Other'];

    /** O. Reg. 137/15 (as amended by O. Reg. 197/26). required=false → recommended practice. */
    public static function ontarioCatalogue(): array
    {
        $y27 = '2027-01-01';

        return [
            ['key' => 'program_statement', 'title' => 'Program statement', 'category' => 'Program', 'required' => true, 'reg_ref' => 's.46, s.49',
                'purpose' => 'Your approach to programming and pedagogy, consistent with "How Does Learning Happen?". Reviewed every year.'],
            ['key' => 'supervision_counting', 'title' => 'Supervision policy (including counting children)', 'category' => 'Health & safety', 'required' => true, 'reg_ref' => 'O. Reg. 197/26', 'required_from' => $y27,
                'purpose' => 'How children are supervised and counted throughout the day, at transitions and outings.'],
            ['key' => 'missing_child', 'title' => 'Missing child policy', 'category' => 'Emergencies', 'required' => true, 'reg_ref' => 'O. Reg. 197/26', 'required_from' => $y27,
                'purpose' => 'What staff do the moment a child cannot be accounted for.'],
            ['key' => 'serious_occurrence', 'title' => 'Serious occurrence policy', 'category' => 'Emergencies', 'required' => true, 'reg_ref' => '',
                'purpose' => 'Identifying, responding to, reporting and posting serious occurrences.'],
            ['key' => 'anaphylaxis', 'title' => 'Anaphylactic policy', 'category' => 'Health & safety', 'required' => true, 'reg_ref' => '',
                'purpose' => 'Reducing exposure to allergens, individual plans and emergency response for anaphylaxis.'],
            ['key' => 'sleep', 'title' => 'Sleep policy', 'category' => 'Health & safety', 'required' => true, 'reg_ref' => 's.33.1',
                'purpose' => 'Safe sleep, direct visual checks and communicating sleep patterns to parents.'],
            ['key' => 'medication', 'title' => 'Medication administration policy', 'category' => 'Health & safety', 'required' => true, 'reg_ref' => '',
                'purpose' => 'Receiving, storing, giving and recording medication.'],
            ['key' => 'emergency_management', 'title' => 'Emergency management policy', 'category' => 'Emergencies', 'required' => true, 'reg_ref' => 's.68.1',
                'purpose' => 'Responding to emergencies: evacuation, shelter in place, lockdown, communication and recovery.'],
            ['key' => 'fire_safety', 'title' => 'Fire safety plan and evacuation procedures', 'category' => 'Emergencies', 'required' => true, 'reg_ref' => '',
                'purpose' => 'Fire safety plan approved under the Fire Code, evacuation procedures and drills.'],
            ['key' => 'playground_safety', 'title' => 'Playground safety policy', 'category' => 'Health & safety', 'required' => true, 'reg_ref' => 'CSA Z614',
                'purpose' => 'Daily, monthly and seasonal playground inspections and how hazards are fixed.'],
            ['key' => 'parent_concerns', 'title' => 'Parent issues and concerns policy', 'category' => 'Families', 'required' => true, 'reg_ref' => 's.45.1',
                'purpose' => 'How parents raise issues and concerns, and how and when the licensee responds.'],
            ['key' => 'waiting_list', 'title' => 'Waiting list policy', 'category' => 'Families', 'required' => true, 'reg_ref' => 's.75.1',
                'purpose' => 'How children are placed on and offered spaces from the waiting list; no fee to join.'],
            ['key' => 'staff_supervision', 'title' => 'Supervision of staff, students and volunteers', 'category' => 'Staffing', 'required' => true, 'reg_ref' => 's.11',
                'purpose' => 'How placement students and volunteers are supervised; never counted in ratios or left alone with children.'],
            ['key' => 'prohibited_practices', 'title' => 'Prohibited practices', 'category' => 'Program', 'required' => true, 'reg_ref' => 's.48',
                'purpose' => 'Practices that are never permitted, and what happens if one occurs.'],
            ['key' => 'safe_arrival', 'title' => 'Safe arrival and dismissal policy', 'category' => 'Health & safety', 'required' => true, 'reg_ref' => 's.50',
                'purpose' => 'Releasing children only to authorized people and following up when a child does not arrive.'],
            ['key' => 'individualized_plans', 'title' => 'Individualized plans for medical needs', 'category' => 'Health & safety', 'required' => true, 'reg_ref' => 's.39.1',
                'purpose' => 'Plans for children with medical needs, developed with parents and health professionals.'],
            ['key' => 'criminal_reference', 'title' => 'Criminal reference check / vulnerable sector check policy', 'category' => 'Staffing', 'required' => true, 'reg_ref' => 's.60',
                'purpose' => 'Vulnerable sector checks before starting, offence declarations and renewals.'],
            ['key' => 'hazard_unauthorized_entry', 'title' => 'Hazard notification and unauthorized entry', 'category' => 'Emergencies', 'required' => true, 'reg_ref' => 'O. Reg. 197/26', 'required_from' => $y27,
                'purpose' => 'Telling staff and parents about hazards, and responding to someone entering without authorization.'],
            ['key' => 'behaviour_guidance', 'title' => 'Behaviour guidance', 'category' => 'Program', 'required' => false, 'reg_ref' => '',
                'purpose' => 'Positive approaches to guiding children\'s behaviour.'],
            ['key' => 'health_illness', 'title' => 'Health, illness and infection control', 'category' => 'Health & safety', 'required' => false, 'reg_ref' => '',
                'purpose' => 'Daily health checks, exclusion of sick children, handwashing and sanitation, as your public health unit directs.'],
            ['key' => 'nutrition_allergies', 'title' => 'Nutrition and allergies', 'category' => 'Health & safety', 'required' => false, 'reg_ref' => '',
                'purpose' => 'Menus, food from home, special diets and posting allergy lists.'],
            ['key' => 'field_trips', 'title' => 'Field trips', 'category' => 'Operations', 'required' => false, 'reg_ref' => '',
                'purpose' => 'Permission, ratios, counting and emergency information on outings.'],
            ['key' => 'transportation', 'title' => 'Transportation (if you transport children)', 'category' => 'Operations', 'required' => false, 'reg_ref' => '',
                'purpose' => 'Vehicles, car seats, drivers and counting children on and off.'],
        ];
    }

    /** Agency calendar today. */
    public static function today(int $agencyId): string
    {
        return Carbon::now(AgencyTime::tz($agencyId) ?: 'America/Toronto')->toDateString();
    }

    /**
     * Staff of the agency who must sign policies, as [user_id => ['name','email','centres'=>[ids],'agency_wide'=>bool,'roles'=>[]]].
     * $centreIds limits to staff at those centres (directors' view).
     */
    public static function staff(int $agencyId, ?array $centreIds = null): array
    {
        $live = Live::centreIds($agencyId);
        $rows = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->whereNull('u.deleted_at')->whereNotIn('u.status', Audience::OFF_STATUSES)
            ->whereIn('ra.role', self::STAFF_ROLES)
            ->where(function ($w) use ($agencyId, $live) {
                $w->whereIn('ra.centre_id', $live ?: [0])->orWhere(fn ($a) => $a->whereNull('ra.centre_id')->where('ra.agency_id', $agencyId));
            })
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.status', 'ra.role', 'ra.centre_id']);
        $out = [];
        foreach ($rows as $r) {
            if (ChatAudience::isServiceEmail($r->email)) continue;
            $id = (int) $r->id;
            if (! isset($out[$id])) {
                $out[$id] = ['id' => $id, 'name' => trim($r->first_name . ' ' . $r->last_name), 'email' => $r->email, 'status' => $r->status,
                    'centres' => [], 'agency_wide' => false, 'roles' => []];
            }
            if ($r->centre_id) $out[$id]['centres'][] = (int) $r->centre_id; else $out[$id]['agency_wide'] = true;
            $out[$id]['roles'][] = $r->role;
        }
        foreach ($out as &$s) { $s['centres'] = array_values(array_unique($s['centres'])); $s['roles'] = array_values(array_unique($s['roles'])); }
        unset($s);
        if ($centreIds !== null) {
            $out = array_filter($out, fn ($s) => (bool) array_intersect($s['centres'], $centreIds));
        }
        uasort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** Does this policy apply to this staff member? */
    public static function applies(object $policy, array $staff): bool
    {
        return $policy->centre_id === null || in_array((int) $policy->centre_id, $staff['centres'], true);
    }

    /** Is this person staff in this agency (one of STAFF_ROLES)? Returns their staff entry or null. */
    public static function staffEntry(int $agencyId, int $userId): ?array
    {
        $all = self::staff($agencyId);

        return $all[$userId] ?? null;
    }

    /**
     * Sign-off state for a set of users × policies: [policy_id][user_id] = ['state'=>signed|outdated|outstanding, 'at'=>..., 'version'=>n, 'trained_on'=>date|null].
     */
    public static function grid(array $policies, array $userIds): array
    {
        $pids = array_map(fn ($p) => (int) $p->id, $policies);
        if (! $pids || ! $userIds) return [];
        $acks = DB::table('policy_acknowledgements as a')->join('policy_versions as v', 'v.id', '=', 'a.policy_version_id')
            ->whereIn('a.policy_id', $pids)->whereIn('a.user_id', $userIds)
            ->get(['a.policy_id', 'a.user_id', 'a.policy_version_id', 'a.acknowledged_at', 'v.version']);
        $byPU = [];
        foreach ($acks as $a) $byPU[(int) $a->policy_id][(int) $a->user_id][] = $a;
        $tr = DB::table('policy_trainings')->whereIn('policy_id', $pids)->whereIn('user_id', $userIds)
            ->select('policy_id', 'user_id', DB::raw('MAX(trained_on) as trained_on'))->groupBy('policy_id', 'user_id')->get();
        $trained = [];
        foreach ($tr as $t) $trained[(int) $t->policy_id][(int) $t->user_id] = $t->trained_on;
        $out = [];
        foreach ($policies as $p) {
            $cur = (int) ($p->current_version_id ?? 0);
            foreach ($userIds as $u) {
                $list = $byPU[(int) $p->id][$u] ?? [];
                $state = 'outstanding'; $at = null; $ver = null;
                foreach ($list as $a) {
                    if ((int) $a->policy_version_id === $cur) { $state = 'signed'; $at = $a->acknowledged_at; $ver = (int) $a->version; break; }
                }
                if ($state !== 'signed' && $list) {
                    usort($list, fn ($x, $y) => $y->version <=> $x->version);
                    $state = 'outdated'; $at = $list[0]->acknowledged_at; $ver = (int) $list[0]->version;
                }
                $out[(int) $p->id][$u] = ['state' => $state, 'at' => $at, 'version' => $ver, 'trained_on' => $trained[(int) $p->id][$u] ?? null];
            }
        }

        return $out;
    }

    /** The current versions this person still has to sign: [ {policy, version} ]. */
    public static function outstandingFor(int $agencyId, int $userId): array
    {
        $staff = self::staffEntry($agencyId, $userId);
        if (! $staff) return [];
        $q = DB::table('policies as p')->join('policy_versions as v', 'v.id', '=', 'p.current_version_id')
            ->where('p.agency_id', $agencyId)->where('p.archived', false)
            ->where(fn ($w) => $w->whereNull('p.centre_id')->orWhereIn('p.centre_id', $staff['centres'] ?: [0]))
            ->whereNotExists(fn ($s) => $s->from('policy_acknowledgements as a')->whereColumn('a.policy_version_id', 'p.current_version_id')->where('a.user_id', $userId))
            ->orderByDesc('p.required')->orderBy('p.title');

        return $q->get(['p.id', 'p.title', 'p.required', 'p.category', 'v.id as version_id', 'v.version', 'v.change_summary', 'v.published_at'])->all();
    }

    /** Lay out typed/pasted text as safe HTML: blank-line paragraphs, "- " bullets, "# " headings. Everything escaped. */
    public static function textToHtml(string $text): string
    {
        $e = fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $html = '';
        foreach (preg_split('/\n{2,}/', $text) as $block) {
            $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), fn ($l) => trim($l) !== ''));
            if (! $lines) continue;
            $bullets = array_filter($lines, fn ($l) => preg_match('/^\s*([-*•]|\d+[.)])\s+/u', $l));
            if (count($bullets) === count($lines)) {
                $ordered = (bool) preg_match('/^\s*\d+[.)]\s+/', $lines[0]);
                $html .= $ordered ? '<ol>' : '<ul>';
                foreach ($lines as $l) $html .= '<li>' . $e(preg_replace('/^\s*([-*•]|\d+[.)])\s+/u', '', $l)) . '</li>';
                $html .= $ordered ? '</ol>' : '</ul>';
                continue;
            }
            if (count($lines) === 1 && preg_match('/^#{1,3}\s+(.*)$/', $lines[0], $m)) {
                $html .= '<h3>' . $e($m[1]) . '</h3>';
                continue;
            }
            $html .= '<p>' . implode('<br>', array_map($e, $lines)) . '</p>';
        }

        return $html;
    }

    /**
     * Tell the staff a new version is waiting: one bell each, and an email.
     * Only people the policy applies to, who can sign in, with a real address.
     * Returns [bells, emails_handed_to_mailer, failures].
     */
    public static function notifyPublished(int $versionId): array
    {
        $v = DB::table('policy_versions')->where('id', $versionId)->first();
        if (! $v) return [0, 0, 0];
        $p = DB::table('policies')->where('id', $v->policy_id)->first();
        if (! $p || $p->archived || (int) $p->current_version_id !== (int) $v->id) return [0, 0, 0];
        $agencyId = (int) $p->agency_id;
        $staff = array_filter(self::staff($agencyId), fn ($s) => self::applies($p, $s));
        $bells = [];
        foreach ($staff as $s) {
            $bells[] = ['user_id' => $s['id'], 'type' => 'policy', 'title' => '📘 Please review: ' . mb_substr($p->title, 0, 150),
                'body' => ((int) $v->version > 1 ? 'Updated (version ' . $v->version . '): ' : 'New policy: ') . mb_substr((string) $v->change_summary, 0, 300) . ' Read it and sign that you understand it.',
                'data' => json_encode(['link' => '#my-policies']), 'created_at' => now()];
        }
        if ($bells) Notify::write($bells);

        $e = fn ($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $agencyName = (string) DB::table('agencies')->where('id', $agencyId)->value('name');
        $sent = 0; $failed = 0;
        foreach ($staff as $s) {
            if (($s['status'] ?? '') !== 'active' || ! filter_var($s['email'], FILTER_VALIDATE_EMAIL)) continue;
            $html = EmailTemplate::wrap($agencyId,
                '<p style="margin:0 0 10px;font-size:15px;color:#334155">Hi ' . $e(explode(' ', $s['name'])[0] ?: 'there') . ',</p>'
                . '<p style="margin:0 0 10px;font-size:15px;color:#334155">' . ((int) $v->version > 1 ? 'A policy you follow has been updated' : 'A new policy has been added') . ' at ' . $e($agencyName) . ': <b>' . $e($p->title) . '</b>.</p>'
                . '<p style="margin:0 0 10px;font-size:14.5px;color:#0F172A;background:#F8FAFC;border-radius:8px;padding:10px 12px"><b>What changed:</b> ' . $e($v->change_summary) . '</p>'
                . '<p style="margin:0;font-size:14.5px;color:#334155">Please open KiddieTrac → <b>Policies to review</b>, read it, and sign that you have read and understand it before your next shift with children.</p>',
                ['eyebrow' => 'POLICIES', 'title' => 'Please review a policy', 'subtitle' => $p->title]);
            try {
                AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to($s['email'])->subject('Please review: ' . $p->title));
                $sent++;
            } catch (\Throwable $ex) {
                $failed++;
                report($ex);
            }
        }

        return [count($bells), $sent, $failed];
    }
}
