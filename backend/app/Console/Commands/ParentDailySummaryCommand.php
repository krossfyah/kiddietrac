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

            $day = $this->collect($child, $date, $tz, $ai);

            // Nothing happened → don't email. A summary of an empty day is noise.
            if (!$override && !$day['has_anything']) {
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
            ->get(['url', 'thumbnail_url', 'caption', 'taken_at', 'created_at', 'child_ids'])
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
            'digest' => $digest,
            'has_anything' => (bool) ($checkIn || $logs->count() || $photos->count() || $messages->count() || $digest),
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
        $detailsOf = fn ($rows) => $rows->pluck('detail')->filter()->map(fn ($d) => mb_strtolower((string) $d))->all();

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
            $notes = $meals->pluck('note')->filter()->all();
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
            $notes = $naps->pluck('note')->filter()->all();
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
                  ->from('noreply@kiddietrac.com', 'Kiddietrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject);
                $m->getHeaders()->addTextHeader('X-KT-Logged', '1');
            });
        })->onQueue('mail');

        if (\Illuminate\Support\Facades\Schema::hasTable('email_logs')) {
            DB::table('email_logs')->insert([
                'to_email' => $email, 'to_name' => $name,
                'from_email' => 'noreply@kiddietrac.com', 'subject' => $subject,
                'mailer' => config('mail.default'), 'status' => 'sent',
                'tracking_token' => \Illuminate\Support\Str::random(32), 'opens' => 0, 'created_at' => now(),
            ]);
        }
    }

    // ── The email ───────────────────────────────────────────────────────

    private function buildHtml($child, array $day, string $tz): string
    {
        $name = e($child->preferred_name ?: $child->first_name);
        $t = fn ($ts) => $ts ? Carbon::parse($ts)->timezone($tz)->format('g:i A') : '—';

        $body = '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Here is how <strong>' . $name . '</strong>\'s day went at '
            . e($child->centre_name) . '.</p>';

        // Sign in / out — with WHO did it, which is the part parents actually want
        // and the part a compliance audit asks for.
        $in = $day['check_in'] ? $t($day['check_in']->occurred_at) : 'Not signed in';
        $out = $day['check_out'] ? $t($day['check_out']->occurred_at) : 'Still at the centre';
        $inBy = $day['check_in']->by_name ?? null;
        $outBy = $day['check_out']->by_name ?? null;
        $body .= EmailTemplate::statRow(
            EmailTemplate::statTile('Signed in', $in, $inBy ? 'by ' . $inBy : '', '#16A34A'),
            EmailTemplate::statTile('Signed out', $out, $outBy ? 'by ' . $outBy : '', '#1F6080')
        );

        // The AI story
        if (!empty($day['digest'])) {
            $body .= '<div style="background:linear-gradient(135deg,#1F6080,#2c7894);color:#fff;border-radius:14px;padding:18px;margin:18px 0;">'
                . '<div style="font-size:11px;font-weight:800;letter-spacing:1.2px;color:#a3d977;margin-bottom:7px;">✨ TODAY\'S STORY</div>'
                . '<div style="font-size:14.5px;line-height:1.6;">' . nl2br(e($day['digest'])) . '</div>'
                . '</div>';
        }

        // Photos
        if (count($day['photos'])) {
            $body .= $this->section('📸 Photos from today');
            $body .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">';
            $i = 0;
            foreach ($day['photos'] as $p) {
                if ($i % 2 === 0) $body .= '<tr>';
                $src = $this->abs($p->thumbnail_url ?: $p->url);
                $body .= '<td style="width:50%;padding:4px;vertical-align:top;">'
                    . '<img src="' . e($src) . '" alt="Photo" style="width:100%;border-radius:10px;display:block;">'
                    . ($p->caption ? '<div style="font-size:11.5px;color:#64748B;padding:4px 2px;">' . e($p->caption) . '</div>' : '')
                    . '</td>';
                if ($i % 2 === 1) $body .= '</tr>';
                $i++;
            }
            if ($i % 2 === 1) $body .= '<td style="width:50%;"></td></tr>';
            $body .= '</table>';
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

        $body .= '<p style="margin:22px 0 0;font-size:12px;color:#94A3B8;line-height:1.5;">'
            . 'All times are ' . e($tz) . ' (your centre\'s local time). '
            . 'Open the KiddieTrac app to reply, see full-size photos, or view earlier days.</p>';

        return EmailTemplate::wrap((int) $child->agency_id, $body, [
            'eyebrow' => 'DAILY SUMMARY',
            'title' => $name . '\'s day',
            'subtitle' => $day['date']->format('l, j F Y'),
            'preheader' => $name . ': ' . count($day['photos']) . ' photos, ' . count($day['logs']) . ' moments logged.',
        ]);
    }

    private function section(string $title): string
    {
        return '<div style="font-size:13px;font-weight:800;color:#0F172A;margin:20px 0 8px;letter-spacing:.2px;">' . $title . '</div>';
    }

    /** Photos are stored as /storage/... paths; email clients need absolute URLs. */
    private function abs(?string $url): string
    {
        if (!$url) return '';
        if (preg_match('#^https?://#', $url)) return $url;
        $base = rtrim((string) (env('APP_PUBLIC_URL') ?: config('app.url') ?: 'https://api.kiddietrac.com'), '/');
        return $base . '/' . ltrim($url, '/');
    }
}
