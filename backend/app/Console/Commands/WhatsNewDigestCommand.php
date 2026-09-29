<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Weekly "What's new in KiddieTrac" email (2026-09-29).
 *
 * Anthony: "Send an email out to the agency admin/director for new features related to
 * the portal as a highlight and anything new features for educators and other staff
 * members as well (wire this up for a weekly update on new features, if there aren't any
 * do not send anything out)".
 *
 * The source is the same whats-new.json the portal's What's new panel reads, and the
 * same `roles` tags decide who hears about what:
 *   - agency admins and centre directors: the portal highlights relevant to them
 *     (an entry with no roles is admin-only, exactly as in the panel);
 *   - educators and home visitors: only the entries tagged for them.
 * Nobody gets an email with nothing in it, and a week with no new entries sends nothing.
 *
 * Per agency, whats_new_digests remembers the newest entry date already sent, so each
 * entry goes out once. Respects the agency's notification switch (Suppression).
 *
 *   php artisan kiddietrac:whats-new-digest [--agency=ID] [--since=YYYY-MM-DD] [--dry-run]
 *   php artisan kiddietrac:whats-new-digest --agency=2 --test-to=me@example.com [--as=educator]
 *     (a sample to one address; records nothing)
 */
class WhatsNewDigestCommand extends Command
{
    protected $signature = 'kiddietrac:whats-new-digest
        {--agency= : one agency only}
        {--since= : treat entries after this date as new (first run / override)}
        {--test-to= : send one sample to this address instead, record nothing}
        {--as=admin : sample audience for --test-to: admin or educator}
        {--dry-run : print who would get what, send nothing}';

    protected $description = "Weekly What's new email to agency staff (only when there is something new)";

    private const ADMIN_ROLES = ['agency_admin', 'centre_director'];
    private const STAFF_ROLES = ['agency_admin', 'centre_director', 'educator', 'home_visitor'];
    private const MAX_ITEMS = 10;

    public function handle(): int
    {
        $path = base_path('../parent-portal/whats-new.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! isset($data['entries'])) {
            $this->error("whats-new.json not found or unreadable at {$path}");
            return self::FAILURE;
        }
        $all = array_values(array_filter($data['entries'], fn ($e) => ($e['type'] ?? '') === 'new' && ! empty($e['date'])));
        usort($all, fn ($a, $b) => strcmp($b['date'], $a['date']));
        $dry = (bool) $this->option('dry-run');
        $testTo = trim((string) $this->option('test-to'));

        $agencies = DB::table('agencies')
            ->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))
            ->get(['id', 'name']);

        foreach ($agencies as $ag) {
            $agencyId = (int) $ag->id;
            $today = \App\Support\AgencyTime::today($agencyId);
            $last = DB::table('whats_new_digests')->where('agency_id', $agencyId)->max('through_date');
            $since = $this->option('since') ?: ($last ?: Carbon::parse($today)->subDays(7)->toDateString());
            $new = array_values(array_filter($all, fn ($e) => $e['date'] > $since && $e['date'] <= $today));
            if (! $new) {
                $this->line("{$ag->name}: nothing new since {$since} - nothing sent");
                continue;
            }

            if ($testTo !== '') {
                $roles = $this->option('as') === 'educator' ? ['educator'] : ['agency_admin', 'centre_director'];
                $items = $this->itemsFor($new, $roles);
                $this->send($agencyId, $ag->name, $testTo, '', $roles, $items, $since);
                $this->info("sample ({$this->option('as')}) with " . count($items) . " item(s) -> {$testTo}");
                continue;
            }

            if (\App\Support\Suppression::isAgency($agencyId)) {
                $this->line("{$ag->name}: notifications are off for this agency - nothing sent");
                continue;
            }

            // Everyone on staff, one email per address, with every role they hold here.
            $people = [];
            foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.agency_id', $agencyId)->where('ra.active', 1)->whereIn('ra.role', self::STAFF_ROLES)
                ->whereNull('u.deleted_at')->where(function ($q) { $q->whereNull('u.status')->orWhere('u.status', 'active'); })
                ->whereNotNull('u.email')->where('u.email', '!=', '')
                ->get(['u.id', 'u.email', 'u.first_name', 'u.last_name', 'ra.role']) as $p) {
                $k = strtolower(trim($p->email));
                $people[$k] = $people[$k] ?? ['email' => $p->email, 'name' => trim($p->first_name . ' ' . $p->last_name), 'roles' => []];
                $people[$k]['roles'][] = $p->role;
            }

            $sent = 0; $skipped = 0; $byAudience = ['admin' => 0, 'staff' => 0];
            foreach ($people as $p) {
                $roles = array_values(array_unique($p['roles']));
                $items = $this->itemsFor($new, $roles);
                if (! $items) { $skipped++; continue; }
                $aud = array_intersect($roles, self::ADMIN_ROLES) ? 'admin' : 'staff';
                if ($dry) {
                    $this->line("[dry] {$p['email']} ({$aud}): " . count($items) . ' item(s)');
                } else {
                    $this->send($agencyId, $ag->name, $p['email'], $p['name'], $roles, $items, $since);
                }
                $sent++; $byAudience[$aud]++;
            }

            if (! $dry) {
                DB::table('whats_new_digests')->insert([
                    'agency_id' => $agencyId,
                    'since_date' => $since,
                    'through_date' => max(array_column($new, 'date')),
                    'entries' => count($new),
                    'recipients' => $sent,
                    'titles' => json_encode(array_column($new, 'title'), JSON_UNESCAPED_UNICODE),
                    'sent_at' => now(),
                    'created_at' => now(),
                ]);
            }
            $this->info(($dry ? '[dry] ' : '') . "{$ag->name}: " . count($new) . " new entr(ies); emailed {$sent} "
                . "({$byAudience['admin']} admins/directors, {$byAudience['staff']} staff); {$skipped} had nothing relevant");
        }

        return self::SUCCESS;
    }

    /** Entries for someone holding these roles. No roles on an entry = admins only (as the panel). */
    private function itemsFor(array $entries, array $roles): array
    {
        $isAdmin = (bool) array_intersect($roles, self::ADMIN_ROLES);

        return array_values(array_filter($entries, function ($e) use ($roles, $isAdmin) {
            $for = $e['roles'] ?? [];
            return $for ? (bool) array_intersect($for, $roles) : $isAdmin;
        }));
    }

    private function send(int $agencyId, string $agencyName, string $to, string $toName, array $roles, array $items, string $since): void
    {
        $isAdmin = (bool) array_intersect($roles, self::ADMIN_ROLES);
        $shown = array_slice($items, 0, self::MAX_ITEMS);
        $more = count($items) - count($shown);
        $portal = rtrim((string) (config('app.portal_url') ?: 'https://app.kiddietrac.com'), '/');

        $rows = '';
        foreach ($shown as $e) {
            $rows .= '<tr><td style="padding:12px 0;border-bottom:1px solid #EEF2F6;vertical-align:top;width:40px;font-size:22px;">' . e($e['icon'] ?? '✨') . '</td>'
                . '<td style="padding:12px 0 12px 8px;border-bottom:1px solid #EEF2F6;">'
                . '<div style="font-size:15px;font-weight:800;color:#0F172A;">' . e($e['title']) . '</div>'
                . '<div style="font-size:14px;line-height:1.55;color:#475569;margin-top:3px;">' . e($e['body']) . '</div></td></tr>';
        }
        $intro = $isAdmin
            ? "Here are the highlights added to KiddieTrac since " . Carbon::parse($since)->format('F j') . " — for you and your team."
            : "Here's what's new in KiddieTrac for you since " . Carbon::parse($since)->format('F j') . ".";
        $body = '<p style="margin:0 0 10px;font-size:15px;line-height:1.6;color:#334155;">' . e(($toName ? 'Hi ' . explode(' ', $toName)[0] . ', ' : '') . $intro) . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">' . $rows . '</table>'
            . ($more > 0 ? '<p style="margin:12px 0 0;font-size:13.5px;color:#475569;">…and ' . $more . ' more — open <strong>What\'s new</strong> in the portal.</p>' : '')
            . '<div style="margin:22px 0 6px;">' . EmailTemplate::button('Open KiddieTrac', $portal . '/dashboard.html') . '</div>'
            . '<p style="margin:10px 0 0;font-size:13px;color:#64748B;line-height:1.55;">Every feature has a short guide in <strong>Help &amp; guides</strong> inside the portal, with "Show me" buttons that take you to the right screen.</p>';

        $title = $isAdmin ? "What's new in KiddieTrac" : "What's new for you in KiddieTrac";
        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => "WHAT'S NEW",
            'title' => $title,
            'subtitle' => $agencyName,
            'preheader' => count($items) . ' new ' . (count($items) === 1 ? 'feature' : 'features') . ': ' . implode(', ', array_slice(array_column($items, 'title'), 0, 3)),
        ]);
        $subject = $title . ' — ' . count($items) . ' new ' . (count($items) === 1 ? 'feature' : 'features');

        dispatch(function () use ($agencyId, $to, $toName, $html, $subject) {
            try {
                AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $toName, $subject) {
                    $m->to($to, $toName ?: null)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($subject);
                });
            } catch (\Throwable $e) {
                Log::warning("What's new email failed", ['to' => $to, 'error' => $e->getMessage()]);
            }
        })->onQueue('mail');
    }
}
