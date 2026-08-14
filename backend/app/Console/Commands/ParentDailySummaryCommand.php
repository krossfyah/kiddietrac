<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\AiDigestService;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * End-of-day summary email to parents (2026-07-13).
 *
 * One email per child, to every guardian on that child's family: the AI story of
 * the day, the photos taken, sign-in/sign-out times, every care log, the day's
 * messages with the centre, and any announcements sent that day.
 *
 * "The day" is the AGENCY's day, not UTC. Everything is bucketed and displayed in
 * the agency's timezone — an entry logged at 8pm Toronto is stamped tomorrow in
 * UTC, and without this it would fall out of the day entirely.
 *
 * Usage:
 *   php artisan kiddietrac:parent-summary                      # today, every enrolled child
 *   php artisan kiddietrac:parent-summary --child=93           # one child
 *   php artisan kiddietrac:parent-summary --child=93 --to=me@x # test send to one address
 *   php artisan kiddietrac:parent-summary --date=2026-07-13 --dry-run
 */
class ParentDailySummaryCommand extends Command
{
    protected $signature = 'kiddietrac:parent-summary
        {--child= : Only this child id}
        {--date= : YYYY-MM-DD in the agency timezone (default: today)}
        {--to= : Send to this address instead of the guardians (for testing)}
        {--dry-run : Build it, print what would happen, send nothing}';

    protected $description = "Email each parent an end-of-day summary of their child's day";

    public function handle(AiDigestService $ai): int
    {
        $onlyChild = $this->option('child') ? (int) $this->option('child') : null;
        $override = $this->option('to');
        $dry = (bool) $this->option('dry-run');

        $children = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->join('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->whereNull('c.deleted_at')
            ->when($onlyChild, fn ($q) => $q->where('c.id', $onlyChild))
            ->when(!$onlyChild, fn ($q) => $q->where('c.enrollment_status', 'enrolled'))
            ->select([
                'c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'c.photo_url', 'c.family_id',
                'ce.id as centre_id', 'ce.name as centre_name',
                'a.id as agency_id', 'a.name as agency_name', 'a.timezone as agency_tz',
            ])
            ->get();

        if ($children->isEmpty()) {
            $this->warn('No children matched.');
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($children as $child) {
            // A LIVE agency gets no summary while we are testing.
            if (\App\Support\Suppression::isAgency((int) $child->agency_id)) {
                continue;
            }

            $tz = $child->agency_tz ?: 'America/Toronto';
            $date = $this->option('date')
                ? Carbon::parse((string) $this->option('date'), $tz)
                : Carbon::now($tz);

            // Only email on the provider's OPERATING days — Mon–Fri by default (or the
            // centre's configured schedule), evaluated in the AGENCY's timezone. A
            // weekend / non-operating day gets NO email (parents shouldn't get a daily
            // summary for a day the centre was never open). EXCEPTION: if the centre
            // was CLOSED on a day it SHOULD have been open (a closure/holiday), we
            // still send — with a clear "closed today" banner — so nobody's left
            // wondering why there's no activity.
            $open = $this->openStatus((int) $child->centre_id, $date);
            if (!$open['is_open_day']) {
                $this->line("· {$child->first_name}: {$date->format('D')} is not an operating day, skipping");
                continue;
            }

            $day = $this->collect($child, $date, $tz, $ai);
            $day['closed_today'] = $open['is_closed'];
            $day['closure_reason'] = $open['closure_reason'] ?? null;

            // Empty operating day → don't email (noise). But a CLOSURE day we DO send
            // (with the banner) even when nothing was logged.
            if (!$override && !$open['is_closed'] && !$day['has_anything']) {
                $this->line("· {$child->first_name}: nothing logged, skipping");
                continue;
            }

            $html = $this->buildHtml($child, $day, $tz);
            // The year matters: these emails get filed, searched and looked back on
            // months later, and "Mon 13 Jul" alone is ambiguous in an inbox.
            $subject = ($child->preferred_name ?: $child->first_name) . "'s day — " . $date->format('D j M Y');

            $recipients = $override
                ? [(object) ['email' => $override, 'name' => 'Test recipient']]
                : $this->guardians((int) $child->family_id);

            if (!$recipients) {
                $this->line("· {$child->first_name}: no guardian email, skipping");
                continue;
            }

            foreach ($recipients as $g) {
                if ($dry) {
                    $this->info("[dry-run] would send \"{$subject}\" to {$g->email} ("
                        . count($day['photos']) . ' photos, ' . count($day['logs']) . ' logs, '
                        . count($day['messages']) . ' messages)');
                    continue;
                }
                $this->send((int) $child->agency_id, (string) $g->email, (string) $g->name, $subject, $html);
                $sent++;
                $this->info("✓ {$subject} → {$g->email}");
            }
        }

        $this->info("Done. {$sent} email(s) queued.");
        return self::SUCCESS;
    }

    /**
     * Is the centre supposed to be OPEN on this date, and was it closed anyway?
     * Operating days default to Mon–Fri; a centre can override via
     * settings.operating_days (iso day numbers 1–7 or day-name prefixes). A
     * centre_closures row covering the date marks it closed.
     *
     * @return array{is_open_day:bool,is_closed:bool,closure_reason:?string}
     */
    private function openStatus(int $centreId, Carbon $date): array
    {
        $dow = (int) $date->isoWeekday();   // 1=Mon … 7=Sun
        $isOpenDay = $dow >= 1 && $dow <= 5; // default: Monday–Friday
        try {
            $settings = DB::table('centres')->where('id', $centreId)->value('settings');
            if ($settings) {
                $st = json_decode((string) $settings, true);
                if (is_array($st) && !empty($st['operating_days']) && is_array($st['operating_days'])) {
                    $names = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
                    $days = array_filter(array_map(function ($d) use ($names) {
                        return is_numeric($d) ? (int) $d : ($names[strtolower(substr((string) $d, 0, 3))] ?? 0);
                    }, $st['operating_days']));
                    if ($days) $isOpenDay = in_array($dow, $days, true);
                }
            }
        } catch (\Throwable $e) {}

        $isClosed = false; $reason = null;
        if ($isOpenDay) {
            try {
                $d = $date->toDateString();
                $cl = DB::table('centre_closures')->where('centre_id', $centreId)
                    ->whereDate('closure_date', '<=', $d)
                    ->where(function ($q) use ($d) {
                        $q->whereNull('end_date')->orWhereDate('end_date', '>=', $d);
                    })
                    ->orderByDesc('closure_date')->first(['reason', 'closure_type']);
                if ($cl) { $isClosed = true; $reason = $cl->reason ?: ($cl->closure_type ?: 'Closed'); }
            } catch (\Throwable $e) {}
        }
        return ['is_open_day' => $isOpenDay, 'is_closed' => $isClosed, 'closure_reason' => $reason];
    }

    /** Everything that happened to this child on this day, in the agency's timezone. */
    private function collect($child, Carbon $date, string $tz, AiDigestService $ai): array
    {
        // The window is the agency's day, converted to the UTC the DB stores.
        $start = $date->copy()->startOfDay()->utc();
        $end = $date->copy()->endOfDay()->utc();
        $ymd = $date->format('Y-m-d');

        // Sign in / out
        // WHO signed the child in and out matters — for the parent's peace of mind
        // and for the centre's compliance record.
        $events = DB::table('check_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.by_user_id')
            ->where('e.child_id', $child->id)
            ->whereBetween('e.occurred_at', [$start, $end])
            ->orderBy('e.occurred_at')
            ->get([
                'e.event_type', 'e.occurred_at', 'e.notes',
                DB::raw("NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),'') as by_name"),
            ]);
        $checkIn = $events->firstWhere('event_type', 'check_in');
        $checkOut = $events->last(fn ($e) => $e->event_type === 'check_out');

        // Care logs — BOTH tables (the roster quick-log writes daily_events, the
        // "log a moment" screen writes daily_care_logs).
        $careLogs = DB::table('daily_care_logs')
            ->where('child_id', $child->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->get(['log_type', 'occurred_at', 'details', 'notes'])
            ->map(fn ($r) => (object) [
                'type' => $r->log_type, 'at' => $r->occurred_at,
                'detail' => $r->details, 'note' => $r->notes,
            ]);

        $eventLogs = DB::table('daily_events')
            ->where('child_id', $child->id)
            ->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$start, $end])
            ->whereIn('event_type', ['diaper', 'bathroom', 'nap', 'meal', 'snack', 'bottle', 'sunscreen', 'mood'])
            ->get(['event_type', 'occurred_at', 'payload', 'notes'])
            ->map(function ($r) {
                $detail = null;
                $p = json_decode((string) $r->payload, true);
                if (is_array($p)) {
                    $vals = array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', array_values($p)));
                    $detail = $vals ? implode(', ', $vals) : null;
                }
                return (object) ['type' => $r->event_type, 'at' => $r->occurred_at, 'detail' => $detail, 'note' => $r->notes];
            });

        $logs = $careLogs->concat($eventLogs)->sortBy('at')->values();

        // Photos — child_ids is a JSON array on the photo row.
        $photos = DB::table('photos')
            ->where('centre_id', $child->centre_id)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get(['url', 'thumbnail_url', 'media_type', 'caption', 'taken_at', 'created_at', 'child_ids'])
            ->filter(function ($p) use ($child) {
                $ids = json_decode((string) $p->child_ids, true);
                return is_array($ids) && in_array((int) $child->id, array_map('intval', $ids), true);
            })
            ->values();

        // The day's messages on this child's conversation.
        $messages = DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.sender_id')
            ->where('c.child_id', $child->id)
            ->whereNull('m.deleted_at')
            ->whereBetween('m.created_at', [$start, $end])
            ->orderBy('m.created_at')
            ->get([
                'm.body', 'm.created_at', 'm.is_system',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),''),'Centre') as sender"),
            ]);

        // Announcements sent to this child's centre (or agency) today.
        $announcements = DB::table('announcements')
            ->where(function ($q) use ($child) {
                $q->where(fn ($w) => $w->where('scope_type', 'centre')->where('scope_id', $child->centre_id))
                  ->orWhere(fn ($w) => $w->where('scope_type', 'agency')->where('scope_id', $child->agency_id));
            })
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get(['title', 'body', 'created_at']);

        // Awards this child earned today — a highlight parents love to see. Keyed on
        // awarded_on (a date), not created_at, so a daily award shows on its own day.
        $awards = DB::table('child_awards as aw')
            ->leftJoin('users as u', 'u.id', '=', 'aw.awarded_by_id')
            ->where('aw.child_id', $child->id)
            ->whereDate('aw.awarded_on', $ymd)
            ->orderBy('aw.created_at')
            ->get([
                'aw.title', 'aw.badge', 'aw.period', 'aw.note', 'aw.created_at',
                DB::raw("NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),'') as by_name"),
            ]);

        // A late pickup logged today (if any) — shown gently, with the fee if charged.
        $latePickup = DB::table('late_pickup_charges')
            ->where('child_id', $child->id)
            ->whereBetween('pickup_at', [$start, $end])
            ->orderByDesc('pickup_at')
            ->first(['pickup_at', 'close_time', 'minutes_late', 'fee_amount', 'notes']);

        // The note home: written by the AI from the WHOLE day (logs, photo
        // captions, messages, times), addressed to the parent. It sits at the top
        // of the email, above the detail — so it has to pull the day together,
        // not repeat the list underneath it.
        $digest = null;
        try {
            $anthropic = app(\App\Services\AnthropicService::class);
            if ($anthropic->isConfigured()) {
                $digest = $anthropic->generateParentSummary([
                    'child_name' => $child->preferred_name ?: $child->first_name,
                    'facts' => [
                        'date' => $date->format('l, j F Y'),
                        'centre' => $child->centre_name,
                        'signed_in' => $checkIn ? Carbon::parse($checkIn->occurred_at)->timezone($tz)->format('g:i A') : null,
                        'signed_out' => $checkOut ? Carbon::parse($checkOut->occurred_at)->timezone($tz)->format('g:i A') : null,
                        'care_logs' => $logs->map(fn ($l) => [
                            'what' => $l->type,
                            'detail' => $l->detail,
                            'note' => $l->note,
                            'at' => Carbon::parse($l->at)->timezone($tz)->format('g:i A'),
                        ])->values()->all(),
                        'photo_captions' => $photos->pluck('caption')->filter()->values()->all(),
                        'photo_count' => $photos->count(),
                        'messages_with_centre' => $messages->map(fn ($m) => [
                            'from' => $m->sender,
                            'said' => $m->body,
                        ])->values()->all(),
                        'centre_announcements' => $announcements->pluck('title')->values()->all(),
                        'awards' => $awards->map(fn ($a) => trim(($a->badge ? $a->badge . ' ' : '') . $a->title))->values()->all(),
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            // Fall through — a missing note must never stop the summary going out.
        }

        // Fall back to the digest generated overnight, if the live call failed.
        if (!$digest) {
            $digest = DB::table('ai_daily_digests')
                ->where('child_id', $child->id)
                ->whereDate('digest_date', $ymd)
                ->value('body');
        }

        // Last resort: write the summary ourselves from the same facts. The AI is
        // better, but it costs money and it can be down — and a parent should never
        // get an email with an empty space where their child's day should be.
        if (!$digest) {
            $digest = $this->writeSummary($child, $checkIn, $checkOut, $logs, $photos, $messages, $tz);
        }

        return [
            'date' => $date,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'logs' => $logs,
            'photos' => $photos,
            'messages' => $messages,
            'announcements' => $announcements,
            'awards' => $awards,
            'late_pickup' => $latePickup,
            'digest' => $digest,
            'has_anything' => (bool) ($checkIn || $logs->count() || $photos->count() || $messages->count() || $awards->count() || $latePickup || $digest),
        ];
    }

    /**
     * A written summary of the day, composed from the day's own facts.
     *
     * This is the fallback when the AI is unavailable. It is not an LLM — it
     * reads the logs and writes plain, specific sentences about what actually
     * happened: what they ate, how they slept, what the photos show, how the day
     * ran. It never claims anything the data doesn't say.
     */
    private function writeSummary($child, $checkIn, $checkOut, $logs, $photos, $messages, string $tz): string
    {
        $name = $child->preferred_name ?: $child->first_name;
        $t = fn ($ts) => Carbon::parse($ts)->timezone($tz)->format('g:i A');

        $of = fn (string $type) => $logs->filter(fn ($l) => $l->type === $type)->values();
        // ->values() is REQUIRED: Collection::filter() preserves the original keys, so
        // if the first row's detail was blank the array came back without index 0 and
        // the `$m[0]` / `$d[0]` reads below threw "Undefined array key 0" — which
        // aborted the whole command, meaning those parents silently got NO daily email.
        $detailsOf = fn ($rows) => $rows->pluck('detail')->filter()->map(fn ($d) => mb_strtolower((string) $d))->values()->all();

        $meals = $of('meal')->concat($of('snack'));
        $naps = $of('nap');
        $bottles = $of('bottle');
        $nappies = $of('diaper')->concat($of('bathroom'));
        $moods = $of('mood');

        // ── Opening: how the day ran ──
        $para1 = [];
        if ($checkIn) {
            $para1[] = "{$name} arrived at " . $t($checkIn->occurred_at)
                . ($checkOut ? ' and went home at ' . $t($checkOut->occurred_at) . '.' : ' and is still with us.');
        } else {
            $para1[] = "Here is how {$name}'s day went.";
        }
        if ($moods->count()) {
            $m = $detailsOf($moods);
            if ($m) {
                $para1[] = count($m) === 1
                    ? "She seemed " . $m[0] . " today."
                    : "Her mood moved through " . $this->list($m) . " across the day.";
            }
        }

        // ── Middle: eating and sleeping — what parents actually ask about ──
        $para2 = [];
        if ($meals->count()) {
            $d = $detailsOf($meals);
            $para2[] = $d
                ? "At mealtimes she " . $this->list(array_unique($d)) . " (" . $meals->count() . ' '
                    . ($meals->count() === 1 ? 'sitting' : 'sittings') . ")."
                : "She ate with the group " . $meals->count() . " times.";
            $notes = $meals->pluck('note')->filter()->values()->all();
            if ($notes) $para2[] = (string) $notes[0] . '.';
        }
        if ($bottles->count()) {
            $d = $detailsOf($bottles);
            $para2[] = $bottles->count() === 1
                ? "She had a bottle" . ($d ? " and " . $d[0] : '') . "."
                : "She had " . $bottles->count() . " bottles" . ($d ? " (" . $this->list(array_unique($d)) . ")" : '') . ".";
        }
        if ($naps->count()) {
            $d = $detailsOf($naps);
            $para2[] = $naps->count() === 1
                ? "She napped at " . $t($naps[0]->at) . ($d ? " and " . $d[0] : '') . "."
                : "She had " . $naps->count() . " naps" . ($d ? " (" . $this->list(array_unique($d)) . ")" : '') . ".";
            $notes = $naps->pluck('note')->filter()->values()->all();
            if ($notes) $para2[] = (string) $notes[0] . '.';
        } else {
            $para2[] = "She didn't settle for a nap today.";
        }
        if ($nappies->count()) {
            $para2[] = "We changed her " . $nappies->count() . ' '
                . ($nappies->count() === 1 ? 'time' : 'times') . '.';
        }

        // ── Close: photos, messages, and a friendly line ──
        $para3 = [];
        $captions = $photos->pluck('caption')->filter()->values()->all();
        if ($captions) {
            $para3[] = "There are photos below — " . mb_strtolower(rtrim((string) $captions[0], '.'))
                . (count($captions) > 1 ? ", and more from the rest of the day." : ".");
        } elseif ($photos->count()) {
            $para3[] = "There " . ($photos->count() === 1 ? 'is a photo' : 'are ' . $photos->count() . ' photos') . " below.";
        }
        if ($messages->count()) {
            $para3[] = "Thank you for chatting with us today.";
        }
        $para3[] = "See you tomorrow!";

        return implode(' ', $para1) . "\n\n" . implode(' ', $para2) . "\n\n" . implode(' ', $para3);
    }

    /** "a, b and c" — an Oxford-comma-free list that reads like a person wrote it. */
    private function list(array $items): string
    {
        $items = array_values(array_filter($items));
        if (!$items) return '';
        if (count($items) === 1) return (string) $items[0];
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    private function guardians(int $familyId): array
    {
        return DB::table('guardians as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)
            ->whereNull('u.deleted_at')
            ->whereNotNull('u.email')
            ->get([
                'u.email',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),''),'there') as name"),
            ])
            ->all();
    }

    private function send(int $agencyId, string $email, string $name, string $subject, string $html): void
    {
        dispatch(function () use ($agencyId, $email, $name, $subject, $html) {
            AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($email, $name, $subject) {
                $m->to($email, $name)
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject);
            });
        })->onQueue('mail');

    }

    // ── The email ───────────────────────────────────────────────────────

    private function buildHtml($child, array $day, string $tz): string
    {
        $name = e($child->preferred_name ?: $child->first_name);
        $t = fn ($ts) => $ts ? Carbon::parse($ts)->timezone($tz)->format('g:i A') : '—';

        // Opening greeting + intro come from the agency-editable "parent-daily-summary"
        // template (Settings → Email templates, #77). Falls back to the original line
        // if anything goes wrong — a nightly email must never break over wording.
        $greeting = '';
        $intro = 'Here is how <strong>' . $name . '</strong>\'s day went at ' . e($child->centre_name) . '.';
        $signoff = '';
        try {
            $tplData = [
                'child_name'  => $name,
                'centre_name' => $child->centre_name,
                'agency_name' => $child->agency_name ?? '',
            ];
            $g = trim(\App\Support\EmailTemplates::block((int) $child->agency_id, 'parent-daily-summary', 'greeting', $tplData));
            $i = trim(\App\Support\EmailTemplates::block((int) $child->agency_id, 'parent-daily-summary', 'intro', $tplData));
            $s = trim(\App\Support\EmailTemplates::block((int) $child->agency_id, 'parent-daily-summary', 'signoff', $tplData));
            if ($g !== '') $greeting = $g;
            if ($i !== '') $intro = $i;
            if ($s !== '') $signoff = $s;
        } catch (\Throwable $e) { /* keep the safe defaults */ }
        $day['_signoff'] = $signoff;   // rendered near the foot, before the quote
        $body = ($greeting !== '' ? '<div style="font-size:19px;font-weight:800;color:#0B2545;margin:0 0 8px;">' . $greeting . '</div>' : '')
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">' . $intro . '</p>';

        // Closed-today banner: the centre should have been open but was closed
        // (holiday / snow day / other closure). We send anyway so parents know why
        // there's no activity, rather than leaving them wondering.
        if (!empty($day['closed_today'])) {
            $reason = !empty($day['closure_reason']) ? (' — ' . e($day['closure_reason'])) : '';
            $body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;"><tr>'
                . '<td style="background:#FEF3C7;border:1px solid #FCD34D;border-left:5px solid #F59E0B;border-radius:12px;padding:14px 16px;">'
                . '<div style="font-size:14.5px;font-weight:800;color:#92400E;">🚪 ' . e($child->centre_name) . ' was closed today' . $reason . '</div>'
                . '<div style="font-size:13px;color:#92400E;margin-top:3px;line-height:1.5;">There were no activities to report. Your normal daily update resumes on the next open day.</div>'
                . '</td></tr></table>' . $body;
        }

        // DID THE CHILD ACTUALLY ATTEND? Everything below depends on the answer,
        // and until now nothing asked it. A child with no sign-in and no logged care
        // was still given sign-in/out cards, a story about their day and a thank-you
        // for sharing them — about a day spent at home.
        $attended = !empty($day['check_in'])
            || !empty($day['events'])
            || !empty($day['care_logs'])
            || !empty($day['photos']);

        if (! $attended) {
            // Say it once, clearly, at the top. If the family told us why, repeat it
            // back so they can see it was received.
            $why = '';
            if (!empty($day['absence'])) {
                $reason = trim((string) ($day['absence']->reason ?? ''));
                $why = $reason ? (' — reported as ' . e($reason)) : ' — reported absent';
            }
            $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;"><tr>'
                . '<td style="background:#F8FAFC;border:1px solid #E2E8F0;border-left:5px solid #94A3B8;border-radius:12px;padding:14px 16px;">'
                . '<div style="font-size:14.5px;font-weight:800;color:#334155;">' . e($name) . ' was not at '
                . e($child->centre_name) . ' today' . $why . '.</div>'
                . '<div style="font-size:13px;color:#475569;margin-top:3px;line-height:1.5;">'
                . 'There is nothing to report for today. If this is wrong, please let the centre know so we can correct the record.'
                . '</div></td></tr></table>';
        } else {
            // Sign in / out — with WHO did it, which is the part parents actually want
            // and the part a compliance audit asks for.
            $in = $day['check_in'] ? $t($day['check_in']->occurred_at) : 'Not signed in';
            // Only a child who ARRIVED can still be here. Without a sign-in this said
            // "Still at the centre" about a child who was never there.
            $out = $day['check_out']
                ? $t($day['check_out']->occurred_at)
                : ($day['check_in'] ? 'Still at the centre' : 'Not signed out');
            $inBy = $day['check_in']->by_name ?? null;
            $outBy = $day['check_out']->by_name ?? null;
            $body .= EmailTemplate::statRow(
                EmailTemplate::statTile('Signed in', $in, $inBy ? 'by ' . $inBy : '', '#16A34A'),
                EmailTemplate::statTile('Signed out', $out, $outBy ? 'by ' . $outBy : '', '#1F6080')
            );
        }

        // Late pickup — a factual, non-scolding note. Shows minutes over and the fee
        // if one was charged, so the line item on the invoice is never a surprise.
        if (!empty($day['late_pickup'])) {
            $lp = $day['late_pickup'];
            $mins = (int) ($lp->minutes_late ?? 0);
            $fee = (float) ($lp->fee_amount ?? 0);
            $body .= '<div style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:12px;padding:12px 14px;margin:14px 0;">'
                . '<div style="font-size:13.5px;font-weight:800;color:#9A3412;">🕒 Late pickup</div>'
                . '<div style="font-size:13px;color:#7C2D12;line-height:1.5;margin-top:3px;">'
                . 'Picked up at ' . $t($lp->pickup_at) . ($mins > 0 ? ' — ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . ' after close' : '') . '.'
                . ($fee > 0 ? ' A late fee of $' . number_format($fee, 2) . ' has been added to your invoice.' : '')
                . '</div>'
                . ($lp->notes ? '<div style="font-size:12px;color:#9A3412;margin-top:4px;">' . e((string) $lp->notes) . '</div>' : '')
                . '</div>';
        }

        // The AI story — only for a day that happened. A narrative assembled from
        // no events still produces confident prose, and prose about a child who was
        // at home is worse than sending nothing.
        if ($attended && !empty($day['digest'])) {
            $body .= '<div style="background:linear-gradient(135deg,#1F6080,#2c7894);color:#fff;border-radius:14px;padding:18px;margin:18px 0;">'
                . '<div style="font-size:11px;font-weight:800;letter-spacing:1.2px;color:#a3d977;margin-bottom:7px;">✨ TODAY\'S STORY</div>'
                . '<div style="font-size:14.5px;line-height:1.6;">' . nl2br(e($day['digest'])) . '</div>'
                . '</div>';
        }

        // Awards earned today — a celebratory highlight. A gold card per award with
        // its badge, title, the educator who gave it, and any note.
        if (!empty($day['awards']) && count($day['awards'])) {
            $body .= $this->section('🏆 ' . $name . '\'s awards today');
            foreach ($day['awards'] as $a) {
                $badge = trim((string) ($a->badge ?? '')) ?: '🏆';
                $period = $a->period ? ucfirst((string) $a->period) . ' award' : 'Award';
                $body .= '<div style="background:linear-gradient(135deg,#FFFBEB,#FEF3C7);border:1px solid #FDE68A;border-radius:12px;padding:13px 15px;margin-bottom:8px;display:flex;gap:12px;align-items:flex-start;">'
                    . '<div style="font-size:30px;line-height:1;">' . e($badge) . '</div>'
                    . '<div>'
                    . '<div style="font-size:11px;font-weight:800;letter-spacing:.6px;color:#B45309;text-transform:uppercase;">' . e($period) . '</div>'
                    . '<div style="font-size:15px;font-weight:800;color:#78350F;margin-top:1px;">' . e((string) $a->title) . '</div>'
                    . ($a->note ? '<div style="font-size:13px;color:#92400E;line-height:1.5;margin-top:3px;">' . e((string) $a->note) . '</div>' : '')
                    . ($a->by_name ? '<div style="font-size:11.5px;color:#A16207;margin-top:4px;">— ' . e((string) $a->by_name) . '</div>' : '')
                    . '</div>'
                    . '</div>';
            }
        }

        // Photos & video
        if (count($day['photos'])) {
            $hasVideo = false;
            foreach ($day['photos'] as $p) { if (($p->media_type ?? '') === 'video') { $hasVideo = true; break; } }
            $body .= $this->section($hasVideo ? '📸 Photos & video from today' : '📸 Photos from today');
            $body .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">';
            $i = 0;
            foreach ($day['photos'] as $p) {
                if ($i % 2 === 0) $body .= '<tr>';
                $isVideo = ($p->media_type ?? '') === 'video';
                if ($isVideo) {
                    // No email client plays an inline <video>, and a video row has no
                    // thumbnail to fall back on (PhotoFeedController stores NULL for
                    // clips, since there is no ffmpeg on the host to grab a frame).
                    // Rendering it as an <img> produced a broken image in the email,
                    // so a clip becomes a tappable card that opens it in the portal.
                    $body .= '<td style="width:50%;padding:4px;vertical-align:top;">'
                        . '<a href="' . e($this->portalPhotosUrl()) . '" style="text-decoration:none;">'
                        . '<div style="background:#0F172A;border-radius:10px;padding:26px 12px;text-align:center;color:#fff;">'
                        . '<div style="font-size:26px;line-height:1;">🎬</div>'
                        . '<div style="font-size:12.5px;font-weight:700;padding-top:7px;">Watch the video</div>'
                        . '<div style="font-size:11px;opacity:.75;padding-top:2px;">Opens in KiddieTrac</div>'
                        . '</div></a>'
                        . ($p->caption ? '<div style="font-size:11.5px;color:#64748B;padding:4px 2px;">' . e($p->caption) . '</div>' : '')
                        . '</td>';
                } else {
                    $src = $this->abs($p->thumbnail_url ?: $p->url);
                    $body .= '<td style="width:50%;padding:4px;vertical-align:top;">'
                        . '<a href="' . e($this->portalPhotosUrl()) . '" style="text-decoration:none;">'
                        . '<img src="' . e($src) . '" alt="Photo" style="width:100%;border-radius:10px;display:block;">'
                        . '</a>'
                        . ($p->caption ? '<div style="font-size:11.5px;color:#64748B;padding:4px 2px;">' . e($p->caption) . '</div>' : '')
                        . '</td>';
                }
                if ($i % 2 === 1) $body .= '</tr>';
                $i++;
            }
            if ($i % 2 === 1) $body .= '<td style="width:50%;"></td></tr>';
            $body .= '</table>';
            $body .= '<p style="margin:6px 2px 0;font-size:12.5px;color:#64748B;">'
                . '<a href="' . e($this->portalPhotosUrl()) . '" style="color:#0E7C90;font-weight:700;text-decoration:none;">'
                . 'See everything in Photos &amp; video &rarr;</a></p>';
        }

        // Care logs
        if (count($day['logs'])) {
            $icons = [
                'diaper' => '🧷', 'bathroom' => '🚽', 'nap' => '😴', 'meal' => '🍽️',
                'snack' => '🍎', 'bottle' => '🍼', 'sunscreen' => '☀️', 'mood' => '🙂',
            ];
            $body .= $this->section('📝 Moments and care');
            $body .= '<table role="presentation" style="width:100%;border-collapse:collapse;">';
            foreach ($day['logs'] as $l) {
                $body .= '<tr>'
                    . '<td style="padding:8px 6px 8px 0;font-size:17px;width:26px;">' . ($icons[$l->type] ?? '•') . '</td>'
                    . '<td style="padding:8px 0;border-bottom:1px solid #F1F5F9;">'
                    . '<div style="font-size:14px;font-weight:700;color:#0F172A;">' . e(ucfirst($l->type))
                    . ($l->detail ? ' <span style="font-weight:400;color:#475569;">· ' . e($l->detail) . '</span>' : '') . '</div>'
                    . ($l->note ? '<div style="font-size:12.5px;color:#64748B;margin-top:1px;">' . e($l->note) . '</div>' : '')
                    . '</td>'
                    . '<td style="padding:8px 0;text-align:right;font-size:12px;color:#94A3B8;white-space:nowrap;">' . $t($l->at) . '</td>'
                    . '</tr>';
            }
            $body .= '</table>';
        }

        // Messages
        if (count($day['messages'])) {
            $body .= $this->section('💬 Messages today');
            foreach ($day['messages'] as $m) {
                $body .= '<div style="background:#F8FAFC;border-left:3px solid #159FB4;border-radius:8px;padding:9px 11px;margin-bottom:7px;">'
                    . '<div style="font-size:11.5px;color:#64748B;font-weight:700;">' . e($m->sender) . ' · ' . $t($m->created_at) . '</div>'
                    . '<div style="font-size:13.5px;color:#0F172A;line-height:1.45;margin-top:2px;">' . e((string) $m->body) . '</div>'
                    . '</div>';
            }
        }

        // Announcements
        if (count($day['announcements'])) {
            $body .= $this->section('📢 From the centre');
            foreach ($day['announcements'] as $a) {
                $body .= '<div style="border:1px solid #E7EDF3;border-radius:10px;padding:11px 12px;margin-bottom:7px;">'
                    . '<div style="font-weight:800;font-size:14px;color:#0F172A;">' . e((string) $a->title) . '</div>'
                    . '<div style="font-size:13px;color:#475569;line-height:1.5;margin-top:3px;">'
                    . e(mb_substr(trim(strip_tags((string) $a->body)), 0, 300)) . '</div>'
                    . '<div style="font-size:11px;color:#94A3B8;margin-top:5px;">' . $t($a->created_at) . '</div>'
                    . '</div>';
            }
        }

        // Agency-editable sign-off line (from the parent-daily-summary template).
        if (! empty($day['_signoff'])) {
            $body .= '<div style="margin-top:18px;font-size:15px;color:#334155;line-height:1.6;">' . $day['_signoff'] . '</div>';
        }

        // A warm daily inspirational quote (same for everyone that day, rotates daily).
        $body .= EmailTemplate::dailyQuote((int) $day['date']->format('Ymd'));

        $body .= '<p style="margin:22px 0 0;font-size:12px;color:#94A3B8;line-height:1.5;">'
            . 'All times are ' . e($tz) . ' (your centre\'s local time). '
            . 'Open the KiddieTrac app to reply, see full-size photos, or view earlier days.</p>';

        return EmailTemplate::wrap((int) $child->agency_id, $body, [
            'eyebrow' => 'DAILY SUMMARY',
            'title' => $name . '\'s day',
            'subtitle' => $day['date']->format('l, j F Y'),
            'preheader' => $name . ': ' . (count($day['awards'] ?? []) ? '🏆 award earned · ' : '') . count($day['photos']) . ' photos, ' . count($day['logs']) . ' moments logged.',
        ]);
    }

    private function section(string $title): string
    {
        return '<div style="font-size:13px;font-weight:800;color:#0F172A;margin:20px 0 8px;letter-spacing:.2px;">' . $title . '</div>';
    }

    /** Photos are stored as /storage/... paths; email clients need absolute URLs. */
    /**
     * Deep link to the parent's Photos & video screen in the portal. Videos can't
     * play inside an email client, so the digest points there instead; photos link
     * there too so a parent can see them full size.
     */
    private function portalPhotosUrl(): string
    {
        $base = rtrim((string) (env('PORTAL_URL') ?: 'https://app.kiddietrac.com'), '/');
        return $base . '/dashboard.html#photos';
    }

    private function abs(?string $url): string
    {
        if (!$url) return '';
        if (preg_match('#^https?://#', $url)) return $url;
        $base = rtrim((string) (env('APP_PUBLIC_URL') ?: config('app.url') ?: 'https://api.kiddietrac.com'), '/');
        return $base . '/' . ltrim($url, '/');
    }
}
