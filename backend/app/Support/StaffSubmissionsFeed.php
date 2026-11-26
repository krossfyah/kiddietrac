<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WHAT THE TEAM SUBMITTED, IN THE ADMIN BELL (2026-11-26, Anthony: "notifications bell should also show if educators
 * submitted forms, fire drills etc etc..").
 *
 * An admin or director's bell is the agency activity feed (AgencyController::activityFeed), and most things staff
 * submit never reached it: a drill, a hazard, a medication dose, a signed form, a shared note, a report card sent for
 * approval, a policy acknowledged, a certificate uploaded, an HR task, course or self review done. Some of them wrote
 * a Notify row (which pushes), but an admin's bell does not list Notify rows by name, so they were only ever found in
 * the inbox or the audit log. This adds each of them to the SAME feed as one time-ordered item that opens its screen
 * (its own record where the screen can open one). Feed only: nothing here writes a Notify row, so no phone rings.
 *
 * WHO SEES WHAT (reader and guard together, fail closed):
 *   - an agency admin: their active agency; a centre director: only their own centres (centreScope), and for records that
 *     belong to a PERSON (forms, policies, certificates, HR) only the people placed at those centres;
 *   - open centres and children still with us only (App\Support\Live);
 *   - never the viewer's own submissions (they know), never another agency's;
 *   - a platform admin's "All agencies" view: every open centre;
 *   - the person's "Staff submissions" switch (StaffNotifyPrefs topic staff_submissions, default on) turns them off.
 * Staff are named by first name only.
 */
final class StaffSubmissionsFeed
{
    private const STAFF_ROLES = ['educator', 'home_visitor', 'centre_director', 'assistant_director', 'cook', 'staff', 'supervisor'];

    /**
     * The centres of one agency this viewer may see in the bell: null = the whole agency (a platform admin, or an
     * admin OF THAT agency); otherwise their own centres (Visibility::centreIds, what every record screen uses).
     * Decided per agency, not from the active agency's roles, so an admin elsewhere is not widened here.
     *
     * @return int[]|null
     */
    public static function centreScope(Request $request, int $agencyId): ?array
    {
        $u = $request->user();
        if (! $u || $agencyId <= 0) return [];
        $wide = DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)
            ->where(fn ($w) => $w->where('role', 'platform_admin')
                ->orWhere(fn ($a) => $a->whereIn('role', ['agency_admin', 'admin'])->where('agency_id', $agencyId)))->exists();
        return $wide ? null : array_values(array_map('intval', Visibility::centreIds($agencyId, $u)));
    }

    /**
     * @param  int[] $agencyIds  the agencies the feed is scoped to (empty when $unscoped)
     * @return array<int,array<string,mixed>>  feed events (type, icon, text, when, link, avatar, device, kind)
     */
    public static function events(Request $request, int $uid, bool $unscoped, array $agencyIds): array
    {
        $agencyIds = array_values(array_unique(array_map('intval', array_filter($agencyIds))));
        if (! $unscoped && ! $agencyIds) return [];
        if (! StaffNotifyPrefs::wants($uid, 'staff_submissions', 'push', count($agencyIds) === 1 ? $agencyIds[0] : null)) return [];

        // Scope: open centres the viewer may see, the agencies they see whole, and the people of their centres.
        $wide = [];        // agencies seen whole
        $centres = [];     // open centres in scope
        $scoped = [];      // centres of a director's scope (for person-owned records)
        if (! $unscoped) {
            foreach ($agencyIds as $aid) {
                $s = self::centreScope($request, $aid);
                if ($s === null) {
                    $wide[] = $aid;
                    $centres = array_merge($centres, Live::centreIds($aid));
                } else {
                    $open = array_values(array_intersect(array_map('intval', $s), Live::centreIds($aid)));
                    $centres = array_merge($centres, $open);
                    $scoped = array_merge($scoped, $open);
                }
            }
            $centres = array_values(array_unique($centres));
        }
        $people = $scoped ? CentreScope::peopleIds($scoped) : [];
        $openAll = $unscoped ? Live::centres(DB::table('centres'))->pluck('id')->map(fn ($v) => (int) $v)->all() : null;

        // Centre-owned rows: open centres in scope. Agency-wide rows (centre NULL): agencies seen whole only.
        $byCentre = function ($q, string $centreCol, ?string $agencyCol = null) use ($unscoped, $centres, $wide, $openAll) {
            if ($unscoped) {
                return $q->where(fn ($w) => $w->whereIn($centreCol, $openAll ?: [0])->orWhereNull($centreCol));
            }
            return $q->where(function ($w) use ($centreCol, $agencyCol, $centres, $wide) {
                $w->whereIn($centreCol, $centres ?: [0]);
                if ($agencyCol && $wide) $w->orWhere(fn ($n) => $n->whereNull($centreCol)->whereIn($agencyCol, $wide));
            });
        };
        // Person-owned rows: the agency's people for an agency seen whole; the people of a director's centres otherwise.
        $byPerson = function ($q, string $agencyCol, string $userCol) use ($unscoped, $wide, $agencyIds, $people) {
            if ($unscoped) return $q;
            return $q->where(function ($w) use ($agencyCol, $userCol, $wide, $agencyIds, $people) {
                $w->whereIn($agencyCol, $wide ?: [0]);
                if ($people) $w->orWhere(fn ($n) => $n->whereIn($agencyCol, $agencyIds)->whereIn($userCol, $people));
            });
        };
        // Signed by STAFF of that agency (forms are signed by parents too; those are not team submissions).
        $isStaff = function ($q, string $userCol, string $agencyCol) {
            return $q->whereExists(fn ($e) => $e->select(DB::raw(1))->from('role_assignments as sra')
                ->leftJoin('centres as sc', 'sc.id', '=', 'sra.centre_id')
                ->whereColumn('sra.user_id', $userCol)->where('sra.active', 1)->whereIn('sra.role', self::STAFF_ROLES)
                ->where(fn ($a) => $a->whereColumn('sra.agency_id', $agencyCol)->orWhereColumn('sc.agency_id', $agencyCol)));
        };

        $names = [];
        $centreName = function ($id) use (&$names) {
            $id = (int) $id;
            if (! $id) return '';
            if (! array_key_exists($id, $names)) $names[$id] = (string) (DB::table('centres')->where('id', $id)->value('name') ?? '');
            return $names[$id];
        };
        $first = fn ($r) => trim((string) ($r->preferred_name ?? '')) !== '' ? trim((string) $r->preferred_name)
            : (trim((string) ($r->first_name ?? '')) !== '' ? trim((string) $r->first_name) : 'A team member');
        $at = fn ($cid) => ($n = $centreName($cid)) !== '' ? ' — ' . $n : '';
        $child = fn ($r) => trim((string) (($r->child_pref ?? '') ?: ($r->child_first ?? ''))) ?: 'a child';

        $out = [];
        $push = function (string $kind, string $icon, string $text, $when, string $link, $avatar = null) use (&$out) {
            if (! $when) return;
            $out[] = ['type' => 'submission', 'kind' => $kind, 'icon' => $icon, 'text' => $text, 'when' => (string) $when,
                'link' => $link, 'avatar' => $avatar ?: null, 'device' => null];
        };
        $who = fn ($q, string $col) => $q->leftJoin('users as u', 'u.id', '=', $col)->where(fn ($w) => $w->whereNull($col)->orWhere($col, '!=', $uid));
        $sel = ['u.first_name', 'u.preferred_name', 'u.photo_url'];
        $run = function (string $what, callable $fn) {
            try { $fn(); } catch (\Throwable $e) { report($e); }   // one broken source never empties the bell
        };

        // 🚒 Drills (fire, evacuation, lockdown...).
        $run('drills', function () use ($byCentre, $who, $sel, $push, $first, $at) {
            $q = $who(DB::table('drills as d')->whereNull('d.deleted_at'), 'd.recorded_by');
            $byCentre($q, 'd.centre_id', 'd.agency_id');
            foreach ($q->orderByDesc('d.created_at')->limit(12)->get(array_merge($sel, ['d.id', 'd.type', 'd.centre_id', 'd.created_at'])) as $r) {
                $push('drill', "\u{1F692}", $first($r) . ' recorded a ' . str_replace('_', ' ', (string) ($r->type ?: 'safety')) . ' drill' . $at($r->centre_id),
                    $r->created_at, '#drills', $r->photo_url);
            }
        });

        // 🚧 Hazards reported.
        $run('hazards', function () use ($byCentre, $who, $sel, $push, $first, $at) {
            $q = $who(DB::table('centre_hazards as h'), 'h.created_by');
            $byCentre($q, 'h.centre_id', 'h.agency_id');
            foreach ($q->orderByDesc('h.created_at')->limit(12)->get(array_merge($sel, ['h.id', 'h.title', 'h.centre_id', 'h.created_at'])) as $r) {
                $push('hazard', "\u{1F6A7}", $first($r) . ' reported a hazard: ' . $r->title . $at($r->centre_id), $r->created_at, '#hazards?id=' . $r->id, $r->photo_url);
            }
        });

        // 💊 Medication given.
        $run('medication', function () use ($byCentre, $who, $sel, $push, $first, $at, $child) {
            $q = $who(DB::table('medication_logs as ml')->join('children as ch', 'ch.id', '=', 'ml.child_id'), 'ml.administered_by_id');
            Live::children($q, 'ch');
            $byCentre($q, 'ml.centre_id');
            foreach ($q->orderByDesc('ml.created_at')->limit(12)->get(array_merge($sel, ['ml.id', 'ml.centre_id', 'ml.created_at', 'ch.first_name as child_first', 'ch.preferred_name as child_pref'])) as $r) {
                $push('medication', "\u{1F48A}", $first($r) . ' logged a medication dose for ' . $child($r) . $at($r->centre_id), $r->created_at, '#medications', $r->photo_url);
            }
        });

        // 🚌 Field trips planned.
        $run('field_trips', function () use ($byCentre, $who, $sel, $push, $first, $at) {
            $q = $who(DB::table('field_trips as ft'), 'ft.created_by_id');
            $byCentre($q, 'ft.centre_id', 'ft.agency_id');
            foreach ($q->orderByDesc('ft.created_at')->limit(12)->get(array_merge($sel, ['ft.id', 'ft.title', 'ft.trip_date', 'ft.centre_id', 'ft.created_at'])) as $r) {
                $day = $r->trip_date ? ' (' . date('M j', strtotime((string) $r->trip_date)) . ')' : '';
                $push('field_trip', "\u{1F68C}", $first($r) . ' planned a field trip: ' . $r->title . $day . $at($r->centre_id), $r->created_at, '#field-trips', $r->photo_url);
            }
        });

        // 📝 Report cards sent for approval.
        $run('report_cards', function () use ($byCentre, $who, $sel, $push, $first, $at, $child) {
            $q = $who(DB::table('report_cards as rc')->join('children as ch', 'ch.id', '=', 'rc.child_id')->join('families as f', 'f.id', '=', 'ch.family_id')
                ->whereNotNull('rc.submitted_at'), 'rc.submitted_by_user_id');
            Live::children($q, 'ch');
            $byCentre($q, 'f.centre_id');
            foreach ($q->orderByDesc('rc.submitted_at')->limit(12)->get(array_merge($sel, ['rc.id', 'rc.term', 'f.centre_id', 'rc.submitted_at', 'ch.first_name as child_first', 'ch.preferred_name as child_pref'])) as $r) {
                $push('report_card', "\u{1F4DD}", $first($r) . ' sent ' . $child($r) . '\'s report card' . ($r->term ? ' (' . $r->term . ')' : '') . ' for approval' . $at($r->centre_id),
                    $r->submitted_at, '#report-cards', $r->photo_url);
            }
        });

        // 🗒️ Notes shared with the team (the notes' own reader decides who may see each one).
        $run('notes', function () use ($request, $uid, $push, $first, $at) {
            $ctx = QuickNotes::ctx($request);
            if (! $ctx['agency']) return;
            $q = QuickNotes::scope(DB::table('quick_notes as n'), $ctx, 'n.')->where('n.visibility', 'agency')->where('n.owner_user_id', '!=', $uid)
                ->leftJoin('users as u', 'u.id', '=', 'n.owner_user_id');
            foreach ($q->orderByDesc('n.created_at')->limit(12)->get(['u.first_name', 'u.preferred_name', 'u.photo_url', 'n.id', 'n.title', 'n.centre_id', 'n.created_at']) as $r) {
                $push('note', "\u{1F5D2}\u{FE0F}", $first($r) . ' shared a note' . (trim((string) $r->title) !== '' ? ': ' . $r->title : '') . $at($r->centre_id), $r->created_at, '#notes?id=' . $r->id, $r->photo_url);
            }
        });

        // ✍️ Forms signed by staff (Forms Manager), and staff answers to custom forms.
        $run('forms', function () use ($byPerson, $isStaff, $who, $sel, $push, $first) {
            $q = $who(DB::table('managed_form_signoffs as s')->join('managed_forms as mf', 'mf.id', '=', 's.managed_form_id')->whereNotNull('s.signed_at'), 's.user_id');
            $isStaff($q, 's.user_id', 'mf.agency_id');
            $byPerson($q, 'mf.agency_id', 's.user_id');
            foreach ($q->orderByDesc('s.signed_at')->limit(12)->get(array_merge($sel, ['s.id', 'mf.title', 's.signed_at'])) as $r) {
                $push('form', "\u{270D}\u{FE0F}", $first($r) . ' signed a form: ' . $r->title, $r->signed_at, '#forms-manager', $r->photo_url);
            }
            $q = $who(DB::table('custom_form_responses as cr')->join('custom_forms as cf', 'cf.id', '=', 'cr.form_id')->whereNull('cf.deleted_at'), 'cr.submitted_by_user_id');
            $isStaff($q, 'cr.submitted_by_user_id', 'cf.agency_id');
            $byPerson($q, 'cf.agency_id', 'cr.submitted_by_user_id');
            foreach ($q->orderByDesc('cr.submitted_at')->limit(12)->get(array_merge($sel, ['cr.id', 'cf.title', 'cr.submitted_at'])) as $r) {
                $push('form', "\u{1F4CB}", $first($r) . ' submitted a form: ' . $r->title, $r->submitted_at, '#admin-forms', $r->photo_url);
            }
        });

        // 📜 Policies acknowledged.
        $run('policies', function () use ($byPerson, $who, $sel, $push, $first) {
            $q = $who(DB::table('policy_acknowledgements as pa')->join('policies as p', 'p.id', '=', 'pa.policy_id')->whereNotNull('pa.acknowledged_at'), 'pa.user_id');
            $byPerson($q, 'pa.agency_id', 'pa.user_id');
            foreach ($q->orderByDesc('pa.acknowledged_at')->limit(12)->get(array_merge($sel, ['pa.id', 'p.title', 'pa.acknowledged_at'])) as $r) {
                $push('policy', "\u{1F4DC}", $first($r) . ' acknowledged a policy: ' . $r->title, $r->acknowledged_at, '#policies', $r->photo_url);
            }
        });

        // 🎓 Certificates a person uploaded for themselves.
        $run('certificates', function () use ($byPerson, $who, $sel, $push, $first) {
            $q = $who(DB::table('staff_certifications as sc')->whereColumn('sc.added_by', 'sc.user_id'), 'sc.user_id');
            $byPerson($q, 'sc.agency_id', 'sc.user_id');
            foreach ($q->orderByDesc('sc.created_at')->limit(12)->get(array_merge($sel, ['sc.id', 'sc.title', 'sc.cert_type', 'sc.created_at'])) as $r) {
                $push('certificate', "\u{1F393}", $first($r) . ' uploaded a certificate: ' . ($r->title ?: str_replace('_', ' ', (string) $r->cert_type)), $r->created_at, '#compliance', $r->photo_url);
            }
        });

        // KiddieTrac HR: onboarding tasks the person did, courses completed, self reviews submitted (their HR file opens).
        $run('hr', function () use ($byPerson, $who, $sel, $push, $first) {
            $q = $who(DB::table('hr_onboarding_tasks as t')->whereNotNull('t.done_at')->whereColumn('t.done_by_id', 't.user_id'), 't.user_id');
            $byPerson($q, 't.agency_id', 't.user_id');
            foreach ($q->orderByDesc('t.done_at')->limit(12)->get(array_merge($sel, ['t.id', 't.user_id', 't.title', 't.done_at'])) as $r) {
                $push('hr_onboarding', "\u{1F9E9}", $first($r) . ' finished an onboarding task: ' . $r->title, $r->done_at, '#hr?tab=people&person=' . $r->user_id, $r->photo_url);
            }
            $q = $who(DB::table('hr_course_assignments as a')->join('hr_courses as c', 'c.id', '=', 'a.course_id')->whereNotNull('a.completed_at'), 'a.user_id');
            $byPerson($q, 'a.agency_id', 'a.user_id');
            foreach ($q->orderByDesc('a.completed_at')->limit(12)->get(array_merge($sel, ['a.id', 'a.user_id', 'c.title', 'a.completed_at'])) as $r) {
                $push('hr_course', "\u{1F4DA}", $first($r) . ' completed a course: ' . $r->title, $r->completed_at, '#hr?tab=people&person=' . $r->user_id, $r->photo_url);
            }
            $q = $who(DB::table('hr_reviews as rv')->whereNotNull('rv.self_submitted_at'), 'rv.user_id');
            $byPerson($q, 'rv.agency_id', 'rv.user_id');
            foreach ($q->orderByDesc('rv.self_submitted_at')->limit(12)->get(array_merge($sel, ['rv.id', 'rv.user_id', 'rv.title', 'rv.self_submitted_at'])) as $r) {
                $push('hr_review', "\u{1F5C2}\u{FE0F}", $first($r) . ' submitted a self review' . (trim((string) $r->title) !== '' ? ': ' . $r->title : ''), $r->self_submitted_at, '#hr?tab=people&person=' . $r->user_id, $r->photo_url);
            }
        });

        return $out;
    }

    /* THE PHONE INBOX TOO (2026-11-26, Anthony: staff submissions in the phone Inbox as well, a list only, no push).
       On a phone the bell is the Inbox (#notifications), which lists Notify rows. READ-TIME MERGE, NOT NOTIFY ROWS:
       Notify::write queues a phone push for every row it writes (memory kiddietrac-bell-rings-phone), so writing these
       as notifications would ring every admin's phone for every drill and signed checklist. Instead:
         - GET /notifications?with_submissions=1 (only the Inbox screen asks) merges forViewer() into the list by time;
         - GET /badges adds unseenCount() to `notifications`, so the phone Inbox and the desktop bell count each item
           ONCE (the desktop feed no longer counts type "submission" itself);
         - POST /notifications/seen (opening the Inbox, or the bell, on any device) moves ONE server marker
           (user_ui_markers 'kt_subs_seen' = the newest submission time seen), so seen on one device is seen on all.
       The marker is a submission's own timestamp, never "now", so a row stamped in another zone cannot slip under it.
       Same scope, Live filter, topic switch and links as the desktop bell: the rows come from events(). */
    private const SEEN_KEY = 'kt_subs_seen';

    /** The submissions this person's bell shows, worked out from the request the way activityFeed does. */
    public static function forViewer(Request $request): array
    {
        $u = $request->user();
        if (! $u || $request->header('X-View-As-Role')) return [];   // previewing another role: exactly that role's list
        $uid = (int) $u->id;
        $hdr = strtolower(trim((string) $request->header('X-Active-Agency-Id')));
        return Cache::remember('kt_subs_v1_' . $uid . '_' . $hdr, 45, function () use ($request, $uid, $hdr) {
            $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', 1)->exists();
            if ($isPlatform) {
                // A platform admin inside one agency; their "All agencies" view (no agency) lists none in the Inbox.
                $aid = (int) $hdr;
                if ($aid <= 0 || ! DB::table('agencies')->where('id', $aid)->exists()) return [];
                return self::events($request, $uid, false, [$aid]);
            }
            $agencyIds = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
                ->whereIn('role', ['agency_admin', 'centre_director'])->whereNotNull('agency_id')->pluck('agency_id')->unique()->values()->all();
            if (! $agencyIds) {
                $agencyIds = DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
                    ->where('ra.user_id', $uid)->where('ra.active', 1)->whereIn('ra.role', ['agency_admin', 'centre_director'])
                    ->pluck('c.agency_id')->unique()->values()->all();
            }
            return $agencyIds ? self::events($request, $uid, false, array_map('intval', $agencyIds)) : [];
        });
    }

    /** The newest submission time this person has seen (null = never looked). */
    public static function seenAt(int $userId): ?string
    {
        $v = DB::table('user_ui_markers')->where('user_id', $userId)->where('marker_key', self::SEEN_KEY)->value('marker_value');
        return $v === null || $v === '' ? null : (string) $v;
    }

    private static function setSeen(int $userId, string $when): void
    {
        DB::table('user_ui_markers')->updateOrInsert(['user_id' => $userId, 'marker_key' => self::SEEN_KEY], ['marker_value' => $when, 'updated_at' => now()]);
    }

    private static function newest(array $events): ?string
    {
        $max = null;
        foreach ($events as $e) if (! empty($e['when']) && ($max === null || (string) $e['when'] > $max)) $max = (string) $e['when'];
        return $max;
    }

    /** New since the person last opened the Inbox or the bell. First time: today's list is the starting point, not "new". */
    public static function unseenCount(Request $request): int
    {
        $events = self::forViewer($request);
        if (! $events) return 0;
        $uid = (int) $request->user()->id;
        $seen = self::seenAt($uid);
        if ($seen === null) { if ($n = self::newest($events)) self::setSeen($uid, $n); return 0; }
        return count(array_filter($events, fn ($e) => (string) $e['when'] > $seen));
    }

    /** Opening the Inbox or the bell: everything listed now counts as seen, on every device. Only ever moves forward. */
    public static function markSeen(Request $request): void
    {
        $newest = self::newest(self::forViewer($request));
        if ($newest === null) return;
        $uid = (int) $request->user()->id;
        $seen = self::seenAt($uid);
        if ($seen === null || $newest > $seen) self::setSeen($uid, $newest);
    }

    /** The Inbox rows: one per submission, shaped like a notification and marked `virtual` (no read/delete, no id). */
    public static function inboxRows(Request $request): array
    {
        $seen = self::seenAt((int) $request->user()->id);
        $out = [];
        foreach (self::forViewer($request) as $e) {
            $w = (string) $e['when'];
            $out[] = (object) [
                'id' => 'sub-' . substr(md5($e['kind'] . '|' . $e['link'] . '|' . $w . '|' . $e['text']), 0, 16),
                'virtual' => true, 'type' => 'staff_submission', 'title' => (string) $e['text'], 'body' => null,
                'data' => json_encode(['link' => $e['link'], 'icon' => $e['icon'], 'kind' => $e['kind']]),
                'read_at' => ($seen !== null && $w <= $seen) ? $w : null, 'created_at' => $w,
            ];
        }
        return $out;
    }

    /** Incidents for the same feed: who logged it, for which child, at which centre; opens the incident. */
    public static function incidents(Request $request, int $uid, bool $unscoped, array $agencyIds): array
    {
        $agencyIds = array_values(array_unique(array_map('intval', array_filter($agencyIds))));
        if (! $unscoped && ! $agencyIds) return [];
        $centres = [];
        if ($unscoped) {
            $centres = Live::centres(DB::table('centres'))->pluck('id')->map(fn ($v) => (int) $v)->all();
        } else {
            foreach ($agencyIds as $aid) {
                $s = self::centreScope($request, $aid);
                $open = Live::centreIds($aid);
                $centres = array_merge($centres, $s === null ? $open : array_values(array_intersect(array_map('intval', $s), $open)));
            }
        }
        $q = DB::table('incidents as inc')->join('rooms as rm', 'rm.id', '=', 'inc.room_id')->join('children as ch', 'ch.id', '=', 'inc.child_id')
            ->leftJoin('users as u', 'u.id', '=', 'inc.recorded_by_id')->whereIn('rm.centre_id', $centres ?: [0]);
        Live::children($q, 'ch');
        $out = [];
        foreach ($q->orderByDesc('inc.created_at')->limit(12)
            ->get(['inc.id', 'inc.incident_type', 'inc.created_at', 'inc.recorded_by_id', 'rm.centre_id', 'u.first_name', 'u.preferred_name', 'u.photo_url', 'ch.first_name as child_first', 'ch.preferred_name as child_pref']) as $r) {
            $by = (int) $r->recorded_by_id === $uid ? 'You' : (trim((string) ($r->preferred_name ?: $r->first_name)) ?: 'A team member');
            $cn = (string) (DB::table('centres')->where('id', $r->centre_id)->value('name') ?? '');
            $kid = trim((string) ($r->child_pref ?: $r->child_first)) ?: 'a child';
            $out[] = ['type' => 'incident', 'icon' => "\u{1FA79}", 'text' => $by . ' logged an incident (' . str_replace('_', ' ', (string) $r->incident_type) . ') for ' . $kid . ($cn !== '' ? ' — ' . $cn : ''),
                'when' => (string) $r->created_at, 'link' => '#incident-detail?id=' . $r->id, 'avatar' => $r->photo_url ?: null, 'device' => null];
        }
        return $out;
    }
}
