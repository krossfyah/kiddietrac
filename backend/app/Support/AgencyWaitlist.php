<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The agency waitlist as a HOLDING PLACE for families whose provider closed.
 *
 * A centre's own waitlist (children.enrollment_status = 'waitlist') belongs to that centre,
 * and a closed centre's waitlist is never shown anywhere. So when Bruni Meeser closed on
 * 2026-11-10, her two remaining families (#17, #80) had nowhere to wait: their children
 * were withdrawn, the families stayed attached to a closed home, and kt:integrity-check
 * refiled ticket #251 every night. Anthony, 2026-11-24: "add them to [the] waitlist so they
 * can be re-enrolled and send an email out to admins/directors", then "add to generic
 * waitlist and allow to be reassigned to a new educator/centre by admin/director".
 *
 * The agency-wide list already exists (external_waitlist, the Waitlist screen, shared with
 * a connected partner system). A row made here is LINKED to the real family and child
 * (family_id / child_id / from_centre_id), so placing it never creates a second family the
 * way "Enrol parent" does for a lead. Placing it (assign) moves the family to the chosen
 * centre and puts the child on THAT centre's waitlist, where the existing Enrol step picks
 * the room, start date and fee with all its guards.
 *
 * Statuses a linked row moves through: Waiting -> Assigned (placed with a centre).
 */
final class AgencyWaitlist
{
    /** Linked rows still waiting for a home. Readers (integrity check, still-open list) use the same set. */
    public const OPEN_STATUSES = ['Waiting', 'Contacted', 'Approved'];

    public const SOURCE_CLOSURE = 'Centre closure';

    /**
     * Put every child of a family on the agency waitlist, linked to the family.
     * Idempotent: a child with an open linked row is skipped, not added twice.
     *
     * @return array{agency_id:int, family_id:int, family_name:string, from_centre_id:int, from_centre:string, entries:list<array{id:int, child_id:int, first_name:string, age:?string}>, skipped:int}
     */
    public static function addFamily(int $familyId, string $reason, int $actorId, ?string $desiredStart = null): array
    {
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        if (! $family) {
            throw new \RuntimeException('Family #'.$familyId.' was not found.');
        }
        // The centre may already be archived (deleted_at set) — that is the whole point.
        $centre = DB::table('centres')->where('id', (int) $family->centre_id)->first(['id', 'agency_id', 'name']);
        $agencyId = (int) ($centre->agency_id ?? 0);
        if (! $agencyId) {
            throw new \RuntimeException('Family #'.$familyId.' has no agency.');
        }

        $email = trim((string) ($family->primary_email ?? ''));
        if ($email === '') {
            $email = (string) (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $familyId)->whereNull('u.deleted_at')->whereNotNull('u.email')
                ->orderByDesc('g.is_primary')->value('u.email') ?? '');
        }

        $children = DB::table('children')->where('family_id', $familyId)->whereNull('deleted_at')
            ->whereIn('enrollment_status', ['enrolled', 'withdrawn'])
            ->orderBy('date_of_birth')->get(['id', 'first_name', 'last_name', 'date_of_birth']);

        $out = ['agency_id' => $agencyId, 'family_id' => $familyId, 'family_name' => (string) $family->family_name,
            'from_centre_id' => (int) $family->centre_id, 'from_centre' => (string) ($centre->name ?? ''),
            'entries' => [], 'skipped' => 0];

        foreach ($children as $c) {
            $open = DB::table('external_waitlist')->where('child_id', $c->id)->whereNull('deleted_at')
                ->whereIn('status', self::OPEN_STATUSES)->exists();
            if ($open) {
                $out['skipped']++;
                continue;
            }
            $id = DB::table('external_waitlist')->insertGetId([
                'agency_id' => $agencyId,
                'origin' => 'kiddietrac',
                'external_source' => 'kiddietrac',
                'external_id' => 'ktwl-'.Str::lower(Str::random(14)),
                'family_id' => $familyId,
                'child_id' => (int) $c->id,
                'from_centre_id' => (int) $family->centre_id,
                'child_name' => trim($c->first_name.' '.$c->last_name),
                'child_dob' => $c->date_of_birth ?: null,
                'parent_name' => (string) $family->family_name,
                'email' => $email !== '' ? $email : null,
                'phone' => $family->primary_phone ?: null,
                'desired_start' => $desiredStart,
                'status' => 'Waiting',
                'priority' => 'Normal',
                'source' => self::SOURCE_CLOSURE,
                'notes' => mb_substr($reason, 0, 1000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $out['entries'][] = ['id' => (int) $id, 'child_id' => (int) $c->id,
                'first_name' => (string) $c->first_name, 'age' => self::age($c->date_of_birth)];
        }

        if ($out['entries']) {
            Audit::write([
                'user_id' => $actorId ?: null,
                'agency_id' => $agencyId,
                'action' => 'waitlist.added_from_family',
                'entity_type' => 'family',
                'entity_id' => $familyId,
                'payload' => json_encode([
                    'summary' => 'Put '.count($out['entries']).' child(ren) of '.$family->family_name
                        .' on the agency waitlist ('.$reason.')',
                    'entry_ids' => array_column($out['entries'], 'id'),
                    'from_centre_id' => (int) $family->centre_id,
                ]),
                'ip_address' => 'system',
                'created_at' => now(),
            ]);
        }

        return $out;
    }

    /**
     * Place a linked row with a centre. The FAMILY moves (siblings are never split, the same
     * rule transfers follow), so every open linked row of that family is placed together.
     * Authorisation is the caller's job; this checks only that the target is a real, open
     * centre of the same agency.
     *
     * @return array{centre:string, children:int, entry_ids:list<int>}
     */
    public static function assign(object $row, int $centreId, int $actorId): array
    {
        if (empty($row->family_id)) {
            throw new \InvalidArgumentException('This entry is not linked to a family.');
        }
        $agencyId = (int) $row->agency_id;
        $centre = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->first(['id', 'name']);
        if (! $centre || ! Live::centreOpen($centreId)) {
            throw new \InvalidArgumentException('That centre is closed or not part of this agency.');
        }
        $familyId = (int) $row->family_id;
        $fromName = (string) (DB::table('centres')->where('id', (int) $row->from_centre_id)->value('name') ?? '');

        return DB::transaction(function () use ($row, $agencyId, $centre, $centreId, $familyId, $fromName, $actorId) {
            $rows = DB::table('external_waitlist')->where('agency_id', $agencyId)->where('family_id', $familyId)
                ->whereNull('deleted_at')->whereIn('status', self::OPEN_STATUSES)->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw new \InvalidArgumentException('This family has already been placed.');
            }
            /* A closure with a FUTURE last day waitlists the family at once but withdraws the
               children only on that day (nightly kiddietrac:apply-withdrawals). Moving the family
               before then would let that withdrawal land at the NEW centre. */
            $stillEnrolled = DB::table('children')->whereIn('id', $rows->pluck('child_id')->filter()->all() ?: [0])
                ->where('enrollment_status', 'enrolled')->whereNull('deleted_at')->pluck('first_name')->all();
            if ($stillEnrolled) {
                $last = DB::table('withdrawal_requests')->where('family_id', $familyId)->where('status', 'approved')
                    ->max('effective_date');
                throw new \InvalidArgumentException(implode(', ', $stillEnrolled).' '.(count($stillEnrolled) === 1 ? 'is' : 'are')
                    .' still enrolled at the closing provider'.($last ? ' until '.$last : '').'. Assign them after that day.');
            }

            DB::table('families')->where('id', $familyId)->update(['centre_id' => $centreId, 'updated_at' => now()]);

            $next = (int) DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
                ->where('f.centre_id', $centreId)->where('ch.enrollment_status', 'waitlist')
                ->whereNull('ch.deleted_at')->max('ch.waitlist_position');

            $note = 'Placed from the agency waitlist'.($fromName !== '' ? ' (previously at '.$fromName.', which closed)' : '').'.';
            $moved = 0;
            foreach ($rows as $r) {
                if (! $r->child_id) {
                    continue;
                }
                $moved += DB::table('children')->where('id', (int) $r->child_id)->where('family_id', $familyId)
                    ->whereNull('deleted_at')->whereIn('enrollment_status', ['withdrawn', 'waitlist'])
                    ->update([
                        'enrollment_status' => 'waitlist',
                        // A waitlisted child carries no withdrawal date (every live row agrees);
                        // the withdrawal itself stays on record in withdrawal_requests.
                        'withdrawn_at' => null,
                        'applied_at' => DB::raw('COALESCE(applied_at, '.DB::getPdo()->quote((string) $r->created_at).')'),
                        'waitlist_position' => ++$next,
                        'waitlist_notes' => $note,
                        'updated_at' => now(),
                    ]);
            }

            $ids = $rows->pluck('id')->map(fn ($v) => (int) $v)->all();
            DB::table('external_waitlist')->whereIn('id', $ids)->update([
                'status' => 'Assigned', 'assigned_centre_id' => $centreId, 'assigned_at' => now(),
                'assigned_by_id' => $actorId ?: null, 'updated_at' => now(),
            ]);

            Audit::write([
                'user_id' => $actorId ?: null,
                'agency_id' => $agencyId,
                'action' => 'waitlist.assigned',
                'entity_type' => 'family',
                'entity_id' => $familyId,
                'payload' => json_encode([
                    'summary' => 'Placed '.($row->parent_name ?: 'family #'.$familyId).' with '.$centre->name
                        .' from the agency waitlist ('.$moved.' child(ren) now on its waitlist)',
                    'centre_id' => $centreId, 'from_centre_id' => (int) $row->from_centre_id, 'entry_ids' => $ids,
                ]),
                'ip_address' => substr((string) (request()?->ip() ?? 'system'), 0, 45),
                'created_at' => now(),
            ]);

            return ['centre' => (string) $centre->name, 'children' => $moved, 'entry_ids' => $ids];
        });
    }

    /**
     * Tell the agency's admins and the directors of its OPEN centres that families are
     * waiting for a home, with the steps to place them. Best-effort: never throws.
     *
     * @param  list<array>  $families  addFamily() results (only those with entries are listed)
     */
    public static function notify(int $agencyId, array $families, int $actorId, string $why): int
    {
        try {
            $n = self::buildNotice($agencyId, $families, $actorId, $why);
            if (! $n) {
                return 0;
            }
            [$to, $subject, $html] = [$n['to'], $n['subject'], $n['html']];

            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $subject) {
                $m->to($to[0])->subject($subject);
                if (count($to) > 1) {
                    $m->bcc(array_slice($to, 1));
                }
            });

            Audit::write([
                'user_id' => $actorId ?: null,
                'agency_id' => $agencyId,
                'action' => 'waitlist.closure_notice',
                'entity_type' => 'agency',
                'entity_id' => $agencyId,
                'payload' => json_encode([
                    'summary' => 'Emailed '.count($to).' admin(s)/director(s) about '.$n['kids'].' child(ren) on the agency waitlist',
                    'family_ids' => $n['family_ids'],
                ]),
                'ip_address' => 'system',
                'created_at' => now(),
            ]);

            return count($to);
        } catch (\Throwable $e) {
            Log::warning('Agency waitlist notice failed', ['agency' => $agencyId, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * The notice without sending it: recipients, subject and branded HTML, or null when there
     * is nothing to say or nobody to say it to. Pure (no mail, no writes), so it can be checked.
     *
     * @return array{to:list<string>, subject:string, html:string, kids:int, family_ids:list<int>}|null
     */
    public static function buildNotice(int $agencyId, array $families, int $actorId, string $why): ?array
    {
            $families = array_values(array_filter($families, fn ($f) => ! empty($f['entries'])));
            if (! $agencyId || ! $families) {
                return null;
            }
            $openCentres = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->whereNotIn('status', ['closed', 'archived', 'inactive', 'deleted'])->pluck('id')->all();

            $to = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', 1)
                ->where(function ($q) use ($agencyId, $openCentres) {
                    $q->where(fn ($x) => $x->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId));
                    if ($openCentres) {
                        $q->orWhere(fn ($x) => $x->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $openCentres));
                    }
                })
                ->where('u.id', '!=', $actorId)
                ->whereNull('u.deleted_at')->where('u.status', 'active')->whereNotNull('u.email')
                ->distinct()->pluck('u.email')->filter()->unique()->values()->all();
            if (! $to) {
                return null;
            }

            $kids = array_sum(array_map(fn ($f) => count($f['entries']), $families));
            $from = implode(', ', array_unique(array_filter(array_column($families, 'from_centre'))));
            $link = self::link($agencyId);

            $td = 'padding:8px 10px;border-top:1px solid #E2E8F0;font-size:13.5px;color:#0F172A;';
            $th = 'padding:8px 10px;text-align:left;font-size:11px;letter-spacing:.4px;text-transform:uppercase;color:#64748B;';
            $rows = '';
            foreach ($families as $f) {
                foreach ($f['entries'] as $c) {
                    $rows .= '<tr><td style="'.$td.'font-weight:600;">'.e($f['family_name'] ?: 'Family').'</td>'
                        .'<td style="'.$td.'">'.e($c['first_name']).'</td>'
                        .'<td style="'.$td.'">'.e($c['age'] ?? '—').'</td>'
                        .'<td style="'.$td.'">'.e($f['from_centre'] ?: '—').'</td></tr>';
                }
            }
            $step = fn (int $n, string $html) => '<tr><td style="padding:5px 10px 5px 0;vertical-align:top;font-weight:800;color:#0F766E;">'.$n.'.</td>'
                .'<td style="padding:5px 0;font-size:14px;color:#0F172A;line-height:1.55;">'.$html.'</td></tr>';

            $body = '<p style="margin:0 0 14px;">'.$kids.' child'.($kids === 1 ? ' is' : 'ren are')
                .' now on the <strong>agency waitlist</strong>, waiting for a new home. '.e($why).'</p>'
                .'<table role="presentation" style="border-collapse:collapse;width:100%;margin:0 0 18px;border:1px solid #E2E8F0;border-radius:10px;">'
                .'<tr><th style="'.$th.'">Family</th><th style="'.$th.'">Child</th><th style="'.$th.'">Age</th><th style="'.$th.'">Previously at</th></tr>'
                .$rows.'</table>'
                .'<p style="margin:0 0 6px;font-weight:800;color:#0F172A;">How to place a family with a new educator or centre</p>'
                .'<table role="presentation" style="border-collapse:collapse;margin:0 0 14px;">'
                .$step(1, 'Open <a href="'.e($link).'" style="color:#0F766E;font-weight:700;">Waitlist</a> in KiddieTrac (menu &rarr; Waitlist).')
                .$step(2, 'Find the family. Their row says <em>Previously at</em> the provider that closed.')
                .$step(3, 'Press <strong>🏠 Assign</strong> and choose the educator or centre who will take them. Brothers and sisters move together.')
                .$step(4, 'The child then appears on that centre&rsquo;s own waitlist. Open it there and press <strong>Enrol</strong> to choose the room, start date and fee.')
                .'</table>'
                .'<p style="margin:0;font-size:12.5px;color:#64748B;">Directors can assign families only to the centres they manage. Admins can assign to any open centre in the agency.</p>';

            $subject = 'Waitlist: '.$kids.' child'.($kids === 1 ? '' : 'ren').($from !== '' ? ' from '.$from : '').' need'.($kids === 1 ? 's' : '').' a new home';
            $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => 'Waitlist',
                'title' => 'Families waiting for a new home',
                'preheader' => $kids.' child'.($kids === 1 ? '' : 'ren').' on the agency waitlist',
            ]);

            return ['to' => $to, 'subject' => $subject, 'html' => $html, 'kids' => $kids,
                'family_ids' => array_map('intval', array_column($families, 'family_id'))];
    }

    /** The Waitlist screen, on the agency's own sign-in (white-label agencies keep their brand). */
    /**
     * Enrolling a child placed from a closed provider reopens the guardians the closure closed (2026-11-24).
     * Only when the child has an Assigned closure row, only accounts left 'deactivated', and only the guardian rows
     * the closure stamped (AccountStatus::reopenRoles) - a role an admin revoked on purpose stays revoked.
     * Each reopened guardian is emailed a welcome back (first names only). Returns how many were reopened.
     */
    public static function reopenGuardians(int $childId, int $actorId, string $startDate): int
    {
        try {
            $row = DB::table('external_waitlist')->where('child_id', $childId)->whereNull('deleted_at')
                ->where('status', 'Assigned')->whereNotNull('family_id')->orderByDesc('id')->first();
            if (! $row) {
                return 0;
            }
            $child = DB::table('children')->where('id', $childId)->first(['first_name', 'preferred_name', 'family_id']);
            $family = $child ? DB::table('families')->where('id', $child->family_id)->first(['id', 'centre_id']) : null;
            $centre = $family ? DB::table('centres')->where('id', $family->centre_id)->first(['id', 'name', 'agency_id']) : null;
            if (! $centre) {
                return 0;
            }
            $agencyId = (int) $centre->agency_id;
            $ids = DB::table('guardians')->where('family_id', $family->id)->whereNotNull('user_id')->pluck('user_id')
                ->map(fn ($v) => (int) $v)->all();
            if (! $ids) {
                return 0;
            }
            $closed = DB::table('role_assignments')->whereIn('user_id', $ids)->where('role', 'guardian')
                ->whereNotNull('closed_with_account_at')->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            if (! $closed) {
                return 0;
            }
            $woke = DB::table('users')->whereIn('id', $closed)->whereNull('deleted_at')->where('status', 'deactivated')
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            DB::table('users')->whereIn('id', $woke)->update(['status' => 'active', 'updated_at' => now()]);
            AccountStatus::reopenRoles($closed, $agencyId);

            $kid = trim((string) ($child->preferred_name ?: $child->first_name)) ?: 'your child';
            $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'KiddieTrac');
            $when = \Carbon\Carbon::parse($startDate)->format('l, F j, Y');
            $signIn = preg_replace('/(dashboard\.html)?#synced-waitlist$/', '', self::link($agencyId));
            foreach (DB::table('users')->whereIn('id', $closed)->whereNull('deleted_at')->whereNotNull('email')->get(['id', 'first_name', 'email']) as $u) {
                $first = trim((string) $u->first_name) ?: 'Hello';
                $body = '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;">Hi '.e($first).',</p>'
                    .'<p style="margin:0 0 12px;font-size:15px;line-height:1.6;"><strong>'.e($kid).'</strong> has a place at <strong>'
                    .e((string) $centre->name).'</strong>, starting <strong>'.e($when).'</strong>.</p>'
                    .'<p style="margin:0 0 12px;font-size:15px;line-height:1.6;">Your '.e($agencyName).' parent portal is open again. '
                    .'Sign in with <strong>'.e((string) $u->email).'</strong> to see daily updates, photos and messages.</p>'
                    .'<p style="margin:0 0 16px;"><a href="'.e($signIn).'" style="display:inline-block;background:#0D6E80;color:#ffffff;text-decoration:none;font-weight:700;padding:10px 18px;border-radius:9px;">Sign in</a></p>'
                    .'<p class="kt-muted" style="margin:0;font-size:13px;color:#64748B;">Forgotten your password? Use <strong>Forgot password</strong> on the sign-in page.</p>';
                $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                    'eyebrow' => 'WELCOME BACK', 'title' => $kid.' has a new place',
                    'preheader' => $kid.' starts at '.$centre->name.' on '.$when,
                ]);
                try {
                    \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($u, $kid) {
                        $m->to($u->email)->subject('Welcome back: '.$kid.' has a new place');
                    });
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('waitlist welcome-back mail failed', ['user' => $u->id, 'error' => $e->getMessage()]);
                }
            }
            Audit::write([
                'user_id' => $actorId, 'agency_id' => $agencyId, 'action' => 'waitlist.guardians_reopened',
                'entity_type' => 'child', 'entity_id' => $childId,
                'payload' => json_encode(['summary' => 'Reopened '.count($closed).' guardian login(s) on enrolment', 'users' => $closed,
                    'accounts_reactivated' => $woke], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            return count($closed);
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }

    /**
     * WAITLISTED FAMILIES ARE NOT DE-BOARDED (Anthony, 2026-11-24): "i don't find them in the users or family section
     * anymore (can you keep them there as they are not deboarded and should still have access to the system) and
     * their status should show as Waitlisted". A family held on the agency waitlist keeps its guardians' logins, stays
     * in Families and Users (agency admins; its old centre is closed), and shows Waitlisted until it is placed.
     *
     * @return int[] families of this agency with an open closure-waitlist row
     */
    public static function familyIds(?int $agencyId): array
    {
        if (! $agencyId) {
            return [];
        }
        try {
            return DB::table('external_waitlist')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->whereNotNull('family_id')->whereIn('status', self::OPEN_STATUSES)
                ->distinct()->pluck('family_id')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Is this child held on the agency waitlist (an open linked row)? */
    public static function holdsChild(int $childId): bool
    {
        try {
            return DB::table('external_waitlist')->where('child_id', $childId)->whereNull('deleted_at')
                ->whereIn('status', self::OPEN_STATUSES)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function link(int $agencyId): string
    {
        $base = 'https://app.kiddietrac.com/';
        try {
            if (BrandLock::keyForAgency($agencyId) !== 'kt') {
                $slug = (string) DB::table('agencies')->where('id', $agencyId)->value('slug');
                if ($slug !== '') {
                    return $base.'?tenant='.rawurlencode($slug).'#synced-waitlist';
                }
            }
        } catch (\Throwable $e) {
        }

        return $base.'dashboard.html#synced-waitlist';
    }

    private static function age(?string $dob): ?string
    {
        if (! $dob) {
            return null;
        }
        try {
            $d = \Illuminate\Support\Carbon::parse($dob);
            $m = (int) $d->diffInMonths(AgencyTime::todayDate());
            if ($m < 24) {
                return $m.' month'.($m === 1 ? '' : 's');
            }
            $y = intdiv($m, 12);

            return $y.' year'.($y === 1 ? '' : 's');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
