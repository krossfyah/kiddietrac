<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WHEN A CHILD'S WEEK CHANGES, THE FAMILY AND THE OFFICE HEAR ABOUT IT (2026-10-20).
 *
 * Anthony: "when add or changing weekly schedule for a child send out email to parent and
 * admin/director staff indicating this change and if a change is being made allow whoever
 * is making the change to add a note. since these changes could be temporary or permanent
 * you should introduce the educator to the parent some how".
 *
 * A child's week is two records saved one after the other by the schedule editor: WHO has
 * the child each day (enrollments, CareScheduleController) and WHICH PART of the day
 * (attendance_patterns, AttendancePatternController). So:
 *
 *   1. The first of those saves calls remember(): a snapshot of the week as it stood, held
 *      for a few minutes, taken server-side so the "before" is the record, not the browser.
 *   2. After both saves the editor posts the note, permanent/temporary and the end date to
 *      /admin/children/{child}/schedule-change, which compares that snapshot with the week
 *      now and, only if a day really changed, writes:
 *        - the FAMILY (every parent on the family; pick-up-only logins excluded): the week
 *          before and after, the note, and a "meet your provider" card for each provider
 *          the child is with on a changed day - photo, bio, phone, email, address - the
 *          same facts the provider welcome uses, so the two never disagree;
 *        - the STAFF: the agency admins, plus the directors and educators of every centre
 *          involved before or after (a provider gaining a child on Fridays has to know).
 *   Everything goes through AgencyMailer and Notify, so the agency's email switches, the
 *   suppression list and switched-off accounts still apply. A closed centre or a child who
 *   is no longer enrolled is never written about. A failure here never undoes the save.
 */
final class ScheduleChangeNotice
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const LONG = ['mon' => 'monday', 'tue' => 'tuesday', 'wed' => 'wednesday', 'thu' => 'thursday',
        'fri' => 'friday', 'sat' => 'saturday', 'sun' => 'sunday'];

    private const LABEL = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    private const SHORT = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri',
        'sat' => 'Sat', 'sun' => 'Sun'];

    /** Same words as AttendancePatternController::ROTATION_LABELS. */
    private const ROT = ['full' => 'Full day', 'am' => 'Morning only', 'pm' => 'Afternoon only',
        'before' => 'Before school', 'after' => 'After school', 'bna' => 'Before and after school'];

    private const TTL_MINUTES = 20;

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private static function key(int $childId, int $userId): string
    {
        return 'kt-sched-before:' . $childId . ':' . $userId;
    }

    /**
     * The week as it stands: day => [room_id, centre_id, provider, rot].
     * provider is the centre's name (a home provider IS the centre), with the room added
     * only where a centre has more than one, because "Little Explorers" alone would not
     * say which room.
     */
    public static function snapshot(int $childId): array
    {
        $week = CareSchedule::week($childId);
        $pattern = DB::table('attendance_patterns')->where('child_id', $childId)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', now()))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();

        $multi = [];
        $out = [];
        foreach (self::DAYS as $d) {
            $cell = $week[$d] ?? null;
            $centreId = $cell['centre_id'] ?? null;
            if ($centreId && ! isset($multi[$centreId])) {
                $multi[$centreId] = DB::table('rooms')->where('centre_id', $centreId)->where('active', 1)->count() > 1;
            }
            $rot = $pattern ? ($pattern->{self::LONG[$d]} ?? null) : null;
            if ($rot === '1' || $rot === 1 || $rot === true) {
                $rot = 'full';
            }
            $out[$d] = [
                'room_id' => $cell ? (int) $cell['room_id'] : null,
                'centre_id' => $centreId ? (int) $centreId : null,
                'provider' => $cell ? trim((string) ($cell['centre_name'] ?? '')
                    . (($centreId && $multi[$centreId]) ? ' (' . $cell['room_name'] . ')' : '')) : null,
                'rot' => $rot ? (string) $rot : null,
            ];
        }

        return $out;
    }

    /** Called by the FIRST save of an edit. A second save in the same edit keeps the first snapshot. */
    public static function remember(int $childId, int $userId): void
    {
        try {
            Cache::add(self::key($childId, $userId), self::snapshot($childId), now()->addMinutes(self::TTL_MINUTES));
        } catch (\Throwable $e) {
            Log::warning('Schedule snapshot failed', ['child' => $childId, 'error' => $e->getMessage()]);
        }
    }

    /** The remembered "before", consumed: the next edit starts a fresh one. */
    public static function takeBefore(int $childId, int $userId): ?array
    {
        $k = self::key($childId, $userId);
        $v = Cache::get($k);
        Cache::forget($k);

        return is_array($v) ? $v : null;
    }

    /** day => true where who or when changed. */
    public static function changedDays(array $before, array $after): array
    {
        $out = [];
        foreach (self::DAYS as $d) {
            $b = $before[$d] ?? [];
            $a = $after[$d] ?? [];
            if (($b['room_id'] ?? null) !== ($a['room_id'] ?? null) || ($b['rot'] ?? null) !== ($a['rot'] ?? null)) {
                $out[$d] = true;
            }
        }

        return $out;
    }

    /** "Full day with Sunny Meadows", "Not attending". */
    private static function cellText(?array $c): string
    {
        if (! $c || (! $c['room_id'] && ! $c['rot'])) {
            return 'Not attending';
        }
        $when = $c['rot'] ? (self::ROT[$c['rot']] ?? $c['rot']) : 'Attending';

        return $c['provider'] ? $when . ' with ' . $c['provider'] : $when;
    }

    /** Mon, Tue, Thu - or "Mon to Fri" for a run. */
    private static function dayList(array $days): string
    {
        $days = array_values(array_filter(self::DAYS, fn ($d) => in_array($d, $days, true)));
        if (count($days) >= 3) {
            $idx = array_map(fn ($d) => array_search($d, self::DAYS, true), $days);
            if ($idx === range($idx[0], $idx[0] + count($idx) - 1)) {
                return self::SHORT[$days[0]] . ' to ' . self::SHORT[end($days)];
            }
        }

        return implode(', ', array_map(fn ($d) => self::SHORT[$d], $days));
    }

    private static function weekTable(array $before, array $after, array $changed): string
    {
        $th = 'text-align:left;padding:6px 8px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E2E8F0';
        $rows = '';
        foreach (self::DAYS as $d) {
            $b = $before[$d] ?? null;
            $a = $after[$d] ?? null;
            $isChanged = isset($changed[$d]);
            $empty = self::cellText($b) === 'Not attending' && self::cellText($a) === 'Not attending';
            if ($empty) {
                continue;               // weekends nobody attends are not news
            }
            $bg = $isChanged ? 'background:#FFFBEB;' : '';
            $td = 'padding:8px;border-bottom:1px solid #F1F5F9;font-size:13.5px;vertical-align:top;' . $bg;
            $rows .= '<tr>'
                . '<td style="' . $td . 'font-weight:700;color:#0F172A;white-space:nowrap">' . self::e(self::LABEL[$d]) . '</td>'
                . '<td style="' . $td . 'color:' . ($isChanged ? '#94A3B8;text-decoration:line-through' : '#64748B') . '">' . self::e(self::cellText($b)) . '</td>'
                . '<td style="' . $td . 'color:#0F172A;font-weight:' . ($isChanged ? '800' : '500') . '">'
                . self::e(self::cellText($a)) . ($isChanged ? ' <span style="color:#B45309;font-size:11px;font-weight:800">CHANGED</span>' : '') . '</td>'
                . '</tr>';
        }

        return '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:6px 0 4px">'
            . '<tr><th style="' . $th . '">Day</th><th style="' . $th . '">Before</th><th style="' . $th . '">From now on</th></tr>'
            . $rows . '</table>';
    }

    private static function whenText(string $type, ?string $until, string $tz): array
    {
        if ($type === 'temporary') {
            if ($until) {
                $u = Carbon::parse($until, $tz)->format('l j F Y');

                return ['Temporary, until ' . $u, 'This is a temporary change, until <b>' . self::e($u) . '</b>.'];
            }

            return ['Temporary', 'This is a temporary change. We will let you know when the usual schedule resumes.'];
        }

        return ['Permanent', 'This is a permanent change to the usual week.'];
    }

    private static function mediaUrl(?string $u): ?string
    {
        if (! $u) {
            return null;
        }
        $signed = ProtectedMedia::signForEmail($u);
        if ($signed && preg_match('#^https?://#', (string) $signed)) {
            return $signed;
        }

        return preg_match('#^https?://#', $u) ? $u : 'https://api.kiddietrac.com' . $u;
    }

    /** One "meet your provider" card. Same facts as the provider welcome email. */
    private static function providerCard(int $centreId, string $childFirst, array $days, bool $isNew): string
    {
        $c = DB::table('centres')->where('id', $centreId)->first();
        if (! $c) {
            return '';
        }
        $photo = self::mediaUrl(ProviderWelcomeMailer::providerPhoto($c, $centreId));
        $bio = trim((string) ($c->provider_bio ?? ''));
        $addr = trim(implode(', ', array_filter([$c->address_line1 ?? null, $c->city ?? null], fn ($x) => trim((string) $x) !== '')));
        $img = $photo
            ? '<img src="' . self::e($photo) . '" width="64" height="64" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;display:block">'
            : '<div style="width:64px;height:64px;border-radius:50%;background:#E0F2FE;color:#0369A1;font:800 24px/64px Arial,sans-serif;text-align:center">'
                . self::e(mb_strtoupper(mb_substr((string) $c->name, 0, 1))) . '</div>';
        $contact = [];
        if (! empty($c->phone)) {
            $contact[] = '📞 <a href="tel:' . self::e(preg_replace('/[^0-9+]/', '', (string) $c->phone)) . '" style="color:#1F6FB2;text-decoration:none">' . self::e($c->phone) . '</a>';
        }
        if (! empty($c->email)) {
            $contact[] = '✉️ <a href="mailto:' . self::e($c->email) . '" style="color:#1F6FB2;text-decoration:none">' . self::e($c->email) . '</a>';
        }
        if ($addr) {
            $contact[] = '📍 ' . self::e($addr);
        }
        $eyebrow = $isNew ? 'MEET ' . mb_strtoupper($childFirst) . "'S NEW PROVIDER" : mb_strtoupper($childFirst) . "'S PROVIDER";

        return '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:separate;margin:12px 0;border:1px solid #E2E8F0;border-radius:14px;background:#FFFFFF">'
            . '<tr><td style="padding:14px 16px">'
            . '<div style="font-size:11px;font-weight:800;letter-spacing:.06em;color:' . ($isNew ? '#0F766E' : '#64748B') . ';margin-bottom:8px">' . self::e($eyebrow) . '</div>'
            . '<table cellpadding="0" cellspacing="0"><tr><td style="vertical-align:top;padding-right:14px">' . $img . '</td>'
            . '<td style="vertical-align:top"><div style="font-size:17px;font-weight:800;color:#0F172A">' . self::e($c->name) . '</div>'
            . '<div style="font-size:13px;color:#475569;margin-top:2px">Caring for ' . self::e($childFirst) . ' on <b>' . self::e(self::dayList($days)) . '</b></div></td></tr></table>'
            . '<p style="margin:10px 0 0;font-size:14px;color:#334155;line-height:1.6">'
            . ($bio !== '' ? nl2br(self::e(mb_substr($bio, 0, 900))) : self::e($c->name) . ' will share a little about themselves here soon.')
            . '</p>'
            . ($contact ? '<p style="margin:10px 0 0;font-size:13.5px;color:#334155;line-height:1.7">' . implode('<br>', $contact) . '</p>' : '')
            . '</td></tr></table>';
    }

    /**
     * Write to the family and the staff.
     *
     * @param array{note?:?string,change_type?:string,until?:?string} $meta
     * @param string|null $previewTo  send BOTH versions to this one address only, marked
     *                                [Test]; nobody else, no bells
     * @return array{parents:int,staff:int,changed:int}
     */
    public static function send(int $childId, int $actorId, array $before, array $after, array $meta, ?string $previewTo = null): array
    {
        $result = ['parents' => 0, 'staff' => 0, 'changed' => 0];
        $changed = self::changedDays($before, $after);
        $result['changed'] = count($changed);
        if (! $changed) {
            return $result;
        }

        $child = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('ch.id', $childId)
            ->first(['ch.id', 'ch.first_name', 'ch.preferred_name', 'ch.last_name', 'ch.enrollment_status', 'ch.deleted_at',
                'ch.family_id', 'f.family_name', 'ce.agency_id']);
        if (! $child || $child->deleted_at || $child->enrollment_status !== 'enrolled') {
            return $result;                     // closed records never show
        }
        $agencyId = (int) $child->agency_id;
        $first = (string) ($child->preferred_name ?: $child->first_name);
        $name = trim($first . ' ' . ($child->last_name ?? ''));
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';

        $actor = DB::table('users')->where('id', $actorId)->first(['first_name', 'last_name']);
        $actorName = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'Your agency';
        $actorRole = (string) (DB::table('role_assignments')->where('user_id', $actorId)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin', 'educator'])
            ->orderByRaw("FIELD(role,'centre_director','agency_admin','platform_admin','educator')")->value('role') ?? '');
        $roleWord = ['agency_admin' => 'Administrator', 'centre_director' => 'Director', 'platform_admin' => 'Administrator', 'educator' => 'Educator'][$actorRole] ?? '';

        $type = ($meta['change_type'] ?? 'permanent') === 'temporary' ? 'temporary' : 'permanent';
        [$typeShort, $typeLine] = self::whenText($type, $meta['until'] ?? null, $tz);
        $note = trim((string) ($meta['note'] ?? ''));
        $noteBox = $note !== ''
            ? '<div style="margin:14px 0;padding:12px 14px;border-left:4px solid #2EA9AC;background:#F0FDFA;border-radius:8px">'
                . '<div style="font-size:12px;font-weight:800;color:#0F766E;margin-bottom:4px">A note from ' . self::e($actorName) . ($roleWord ? ', ' . self::e($roleWord) : '') . '</div>'
                . '<div style="font-size:14px;color:#134E4A;line-height:1.6">' . nl2br(self::e(mb_substr($note, 0, 1000))) . '</div></div>'
            : '';
        $table = self::weekTable($before, $after, $changed);
        $today = Carbon::now($tz)->format('l j F');

        /* The providers this change is about: whoever has the child on a changed day. A
           provider the child has never been with before is NEW and introduced properly. */
        $beforeCentres = array_filter(array_map(fn ($c) => $c['centre_id'] ?? null, $before));
        $providers = [];
        foreach (array_keys($changed) as $d) {
            $cid = $after[$d]['centre_id'] ?? null;
            if ($cid) {
                $providers[$cid][] = $d;
            }
        }
        // Each card lists every day the child is with that provider, not just the changed ones.
        foreach (array_keys($providers) as $cid) {
            $providers[$cid] = array_keys(array_filter($after, fn ($c) => ($c['centre_id'] ?? null) === $cid));
        }
        $live = Live::centreIds($agencyId);
        $cards = '';
        foreach ($providers as $cid => $days) {
            if (in_array((int) $cid, $live, true)) {
                $cards .= self::providerCard((int) $cid, $first, $days, ! in_array($cid, $beforeCentres, true));
            }
        }
        $newNames = [];
        foreach ($providers as $cid => $days) {
            if (! in_array($cid, $beforeCentres, true)) {
                $newNames[] = (string) DB::table('centres')->where('id', $cid)->value('name');
            }
        }

        // ── the family ──
        $parents = Audience::excludeOff(DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $child->family_id)->where('g.billing_hidden', 0)->whereNull('u.deleted_at'))
            ->get(['u.id', 'u.email', 'u.first_name'])->unique('id')->values();
        $greet = $parents->pluck('first_name')->filter()->unique()->values()->all();
        $greeting = $greet ? (count($greet) > 1 ? implode(', ', array_slice($greet, 0, -1)) . ' and ' . end($greet) : $greet[0]) : 'there';

        $pBody = '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">Hi ' . self::e($greeting) . ',</p>'
            . '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6"><b>' . self::e($first) . "'s weekly schedule has been updated.</b> " . $typeLine . '</p>'
            . $table . $noteBox
            . ($cards ? '<p style="margin:18px 0 0;font-size:15px;font-weight:800;color:#0F172A">' . ($newNames ? 'Meet ' . self::e($first) . "'s provider" . (count($newNames) > 1 ? 's' : '') : 'Who ' . self::e($first) . ' is with') . '</p>' . $cards : '')
            . '<p style="margin:14px 0 0;font-size:13.5px;color:#64748B;line-height:1.6">If anything here does not look right, reply to this email or contact the office and we will sort it out. You can see '
            . self::e($first) . "'s week any time in the KiddieTrac app.</p>";
        $pHtml = EmailTemplate::wrap($agencyId, $pBody, [
            'eyebrow' => 'SCHEDULE UPDATE', 'title' => $first . "'s weekly schedule has changed", 'subtitle' => $typeShort,
            'preheader' => $newNames ? 'Meet ' . implode(' and ', $newNames) . ', and see the new week.' : 'See the updated week for ' . $first . '.',
        ]);
        $pSubject = $first . "'s weekly schedule has changed" . ($type === 'temporary' ? ' (temporary)' : '');

        // ── the staff ──
        $centreIds = array_values(array_unique(array_filter(array_merge(
            array_map(fn ($c) => $c['centre_id'] ?? null, $before),
            array_map(fn ($c) => $c['centre_id'] ?? null, $after)
        ))));
        $centreIds = array_values(array_intersect(array_map('intval', $centreIds), $live));
        $staffQ = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->whereNull('u.deleted_at')
            ->where(function ($w) use ($agencyId, $centreIds) {
                $w->where(fn ($x) => $x->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin'));
                if ($centreIds) {
                    $w->orWhere(fn ($x) => $x->whereIn('ra.centre_id', $centreIds)->whereIn('ra.role', ['centre_director', 'educator']));
                }
            });
        $staff = Audience::excludeService(Audience::excludeOff($staffQ))->get(['u.id', 'u.email', 'u.first_name'])->unique('id')->values();
        // A parent who is also on staff gets the family's version, not both.
        $parentIds = $parents->pluck('id')->all();
        $staff = $staff->reject(fn ($s) => in_array($s->id, $parentIds, true))->values();

        $sBody = '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6"><b>' . self::e($actorName) . '</b> changed <b>'
            . self::e($name) . "</b>'s weekly schedule" . ($child->family_name ? ' (' . self::e($child->family_name) . ' family)' : '') . ' on ' . self::e($today) . '.</p>'
            . EmailTemplate::calloutBox('<b>' . self::e($typeShort) . '</b> · ' . count($changed) . ' day' . (count($changed) === 1 ? '' : 's') . ' changed'
                . ($newNames ? ' · new provider: <b>' . self::e(implode(', ', $newNames)) . '</b>' : ''), $type === 'temporary' ? 'warning' : 'info')
            . $table . $noteBox
            . '<p style="margin:14px 0 0;font-size:14px;color:#334155;line-height:1.6">The family has been sent the same week'
            . ($newNames ? ' with an introduction to ' . self::e(implode(' and ', $newNames)) : '') . '.'
            . ($type === 'temporary' ? ' <b>The schedule will not switch back by itself</b> - set it back in the child\'s profile when the temporary arrangement ends.' : '') . '</p>';
        $sHtml = EmailTemplate::wrap($agencyId, $sBody, [
            'eyebrow' => 'SCHEDULE CHANGE', 'title' => 'Schedule change: ' . $name, 'subtitle' => $typeShort,
        ]);
        $sSubject = 'Schedule change: ' . $name . ' (' . $typeShort . ')';

        // ── deliver ──
        if ($previewTo) {
            foreach ([['[Test · family] ' . $pSubject, $pHtml], ['[Test · staff] ' . $sSubject, $sHtml]] as [$subj, $html]) {
                AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($previewTo, $subj) {
                    $m->to($previewTo)->subject($subj);
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                });
            }

            return ['parents' => 1, 'staff' => 1, 'changed' => count($changed)];
        }

        $result['parents'] = self::deliver($agencyId, $parents, $pSubject, $pHtml,
            $first . "'s schedule has changed", $typeShort . ' change. See the new week' . ($newNames ? ' and meet ' . implode(' and ', $newNames) : '') . ' in your email.', '#home');
        $result['staff'] = self::deliver($agencyId, $staff, $sSubject, $sHtml,
            'Schedule change — ' . $name, $actorName . ': ' . $typeShort . ', ' . count($changed) . ' day(s) changed' . ($newNames ? '; new provider ' . implode(', ', $newNames) : '') . '.', '#children');

        return $result;
    }

    private static function deliver(int $agencyId, $people, string $subject, string $html, string $bellTitle, string $bellBody, string $link): int
    {
        $rows = [];
        foreach ($people as $p) {
            $rows[] = ['user_id' => (int) $p->id, 'type' => 'schedule', 'title' => $bellTitle, 'body' => mb_substr($bellBody, 0, 1000),
                'data' => json_encode(['link' => $link])];
        }
        try {
            if ($rows) {
                Notify::write($rows);
            }
        } catch (\Throwable $e) {
            Log::warning('Schedule change bell failed', ['error' => $e->getMessage()]);
        }
        $done = [];
        foreach ($people as $p) {
            $em = mb_strtolower(trim((string) $p->email));
            if (! filter_var($em, FILTER_VALIDATE_EMAIL) || isset($done[$em])) {
                continue;
            }
            try {
                AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to($em)->subject($subject));
                $done[$em] = true;
            } catch (\Throwable $ex) {
                Log::warning('Schedule change email failed', ['to' => $em, 'error' => $ex->getMessage()]);
            }
        }

        return count($done);
    }
}
