<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * HALF-CLOSED RECORDS (2026-10-09).
 *
 * Closed providers and withdrawn children reached emails and rosters because their
 * records were closed by one flag and still live by another: a withdrawn child with an
 * open enrolment (re-opened by a backfill), a family left live on a deleted centre, an
 * educator still assigned to a closed centre. Every reader that trusted the other flag
 * showed them.
 *
 * This finds that drift every night and tells the platform admins by email, so it is
 * caught in a day instead of when a parent gets a notice about a provider that closed a
 * month ago. Read-only: it reports, it never repairs — closing a record is a decision.
 *
 *   php artisan kt:integrity-check            (alerts when anything is found)
 *   php artisan kt:integrity-check --no-mail  (prints only; exit 1 when anything is found)
 */
class KtIntegrityCheck extends Command
{
    protected $signature = 'kt:integrity-check {--no-mail : print only, do not email the platform admins}';
    protected $description = 'Find closed/withdrawn records that another table still treats as live, and alert.';

    public const CHECKS = [
        'Withdrawn or deleted child with an OPEN enrolment' => "
            select ch.id, concat(ch.first_name,' ',ch.last_name) name, c.agency_id agency, r.centre_id centre,
                   concat('enrolment #', e.id, ', withdrawn ', coalesce(ch.withdrawn_at, 'n/a')) detail
            from children ch join enrollments e on e.child_id=ch.id join rooms r on r.id=e.room_id join centres c on c.id=r.centre_id
            where (e.end_date is null or e.end_date >= curdate())
              and (ch.enrollment_status='withdrawn' or ch.deleted_at is not null)",
        'Open enrolment in a room of a CLOSED centre' => "
            select ch.id, concat(ch.first_name,' ',ch.last_name) name, c.agency_id agency, r.centre_id centre,
                   concat('enrolment #', e.id, ' in ', c.name) detail
            from enrollments e join children ch on ch.id=e.child_id join rooms r on r.id=e.room_id join centres c on c.id=r.centre_id
            where (e.end_date is null or e.end_date >= curdate())
              and (c.deleted_at is not null or c.status in ('closed','archived','inactive','deleted'))",
        'Live family on a CLOSED centre' => "
            select f.id, f.family_name name, c.agency_id agency, f.centre_id centre, concat('centre ', c.name) detail
            from families f join centres c on c.id=f.centre_id
            where f.deleted_at is null and f.departure_applied_at is null
              and (c.deleted_at is not null or c.status in ('closed','archived','inactive','deleted'))",
        'ACTIVE staff assignment on a CLOSED centre' => "
            select u.id, concat(u.first_name,' ',u.last_name) name, ra.agency_id agency, ra.centre_id centre,
                   concat(ra.role, ' at ', c.name) detail
            from role_assignments ra join centres c on c.id=ra.centre_id join users u on u.id=ra.user_id
            where ra.active=1 and (c.deleted_at is not null or c.status in ('closed','archived','inactive','deleted'))",
        'ENROLLED child in a departed family' => "
            select ch.id, concat(ch.first_name,' ',ch.last_name) name, c.agency_id agency, f.centre_id centre,
                   concat('family #', f.id, ' left ', coalesce(f.departure_date, f.deleted_at)) detail
            from children ch join families f on f.id=ch.family_id left join centres c on c.id=f.centre_id
            where ch.enrollment_status='enrolled' and ch.deleted_at is null
              and (f.deleted_at is not null or f.departure_applied_at is not null)",
        'Future shift in a CLOSED centre' => "
            select s.id, concat(u.first_name,' ',u.last_name) name, c.agency_id agency, c.id centre,
                   concat('shift #', s.id, ' on ', date(s.starts_at), ' in ', c.name) detail
            from shifts s join rooms r on r.id=s.room_id join centres c on c.id=r.centre_id left join users u on u.id=s.user_id
            where s.starts_at >= curdate() /* wall-clock (agency) time: compare whole dates */
              and (c.deleted_at is not null or c.status in ('closed','archived','inactive','deleted'))",
        'Future shift for someone marked Not on the staff rota' => "
            select s.id, concat(u.first_name,' ',u.last_name) name, c.agency_id agency, c.id centre,
                   concat('shift #', s.id, ' on ', date(s.starts_at)) detail
            from shifts s join users u on u.id=s.user_id join rooms r on r.id=s.room_id join centres c on c.id=r.centre_id
            where s.starts_at >= curdate() /* wall-clock (agency) time: compare whole dates */ and u.rota_exempt = 1",
        'Active guardian login on a departed family only' => "
            select u.id, concat(u.first_name,' ',u.last_name) name, c.agency_id agency, f.centre_id centre,
                   concat('family #', f.id) detail
            from users u join guardians g on g.user_id=u.id join families f on f.id=g.family_id left join centres c on c.id=f.centre_id
            where u.status='active' and u.deleted_at is null and (f.deleted_at is not null or f.departure_applied_at is not null)
              and not exists (select 1 from guardians g2 join families f2 on f2.id=g2.family_id
                              where g2.user_id=u.id and f2.deleted_at is null and f2.departure_applied_at is null)
              and not exists (select 1 from role_assignments ra where ra.user_id=u.id and ra.active=1 and ra.role<>'guardian')",
    ];

    public function handle(): int
    {
        $found = [];
        foreach (self::CHECKS as $title => $sql) {
            try {
                $rows = DB::select($sql);
            } catch (Throwable $e) {
                $rows = [(object) ['id' => '-', 'name' => 'CHECK FAILED', 'agency' => '-', 'centre' => '-', 'detail' => $e->getMessage()]];
            }
            $this->line(sprintf('%-55s %d', $title, count($rows)));
            foreach ($rows as $r) {
                $this->line(sprintf('    #%s %s (agency %s, centre %s) — %s', $r->id, $r->name, $r->agency, $r->centre, $r->detail));
            }
            if ($rows) {
                $found[$title] = $rows;
            }
        }

        if (! $found) {
            $this->info('No half-closed records.');
            return self::SUCCESS;
        }

        Log::critical('[INTEGRITY] half-closed records found', array_map('count', $found));
        if (! $this->option('no-mail')) {
            self::alertPlatformAdmins(
                '[KiddieTrac] Data check — ' . array_sum(array_map('count', $found)) . ' half-closed record(s)',
                'These records are closed in one place and still live in another, so lists, rosters and emails can still show them. Nothing was changed; each needs closing properly.',
                $found
            );
        }

        return self::FAILURE;
    }

    /** Email every active platform admin. Shared with kt:leak-sweep. */
    public static function alertPlatformAdmins(string $subject, string $lead, array $sections): void
    {
        $to = [];
        try {
            $to = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.role', 'platform_admin')->where('ra.active', true)
                ->whereNull('u.deleted_at')->where('u.status', 'active')
                ->whereNotNull('u.email')->where('u.email', '!=', '')
                ->distinct()->pluck('u.email')->all();
        } catch (Throwable $e) {
        }
        $to = array_values(array_unique(array_filter($to, fn ($a) => ! str_starts_with(mb_strtolower($a), 'noreply@'))));
        if (! $to) {
            Log::critical('[GUARD] ' . $subject . ' — and no active platform admin to tell.');
            return;
        }

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $body = '<div style="font-family:Arial,sans-serif;max-width:760px;color:#0F172A;">'
            . '<h2 style="font-size:18px;margin:0 0 8px;">' . $e($subject) . '</h2>'
            . '<p style="font-size:14px;color:#334155;">' . $e($lead) . '</p>';
        foreach ($sections as $title => $rows) {
            $body .= '<h3 style="font-size:14px;margin:18px 0 6px;color:#B91C1C;">' . $e($title) . ' (' . count($rows) . ')</h3><ul style="font-size:13px;margin:0;padding-left:18px;">';
            foreach (array_slice($rows, 0, 50) as $r) {
                $r = (array) $r;
                $body .= '<li>' . $e(isset($r['line']) ? $r['line'] : ('#' . ($r['id'] ?? '') . ' ' . ($r['name'] ?? '') . ' — agency ' . ($r['agency'] ?? '') . ', centre ' . ($r['centre'] ?? '') . ' — ' . ($r['detail'] ?? ''))) . '</li>';
            }
            $body .= '</ul>';
        }
        $body .= '<p style="font-size:12px;color:#64748B;margin-top:18px;">Automated guard (kt:integrity-check / kt:leak-sweep). Sent to every active platform administrator.</p></div>';

        try {
            Mail::html($body, function ($m) use ($to, $subject) {
                \App\Support\MailScope::platform($m);
                $m->to($to)->subject($subject);
            });
        } catch (Throwable $ex) {
            Log::critical('[GUARD] alert could not be sent: ' . $ex->getMessage());
        }
    }
}
