<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\EmailTemplate;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "My child isn't coming in today" (2026-07-13).
 *
 * A parent can report an absence from their own app. When they do, the whole
 * centre is told — the educators in the child's room, the centre director, and
 * the agency admin — because an unexplained empty chair is exactly the thing a
 * childcare centre must chase, and a phone call at 9am is how it gets chased
 * today.
 *
 * Reporting an absence also stops the "you haven't signed your child in"
 * reminders for that day (see CheckinReminderCommand): nagging a parent who has
 * already told you their child is sick is how an app gets muted.
 */
class AbsenceController extends Controller
{
    use ResolvesCentreContext;

    /** POST /parent/absences {child_id, reason?, note?, date?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'reason' => 'nullable|string|in:sick,appointment,holiday,family,other',
            'note' => 'nullable|string|max:500',
            'date' => 'nullable|date',
        ]);

        if (! $this->canAccessChildScoped($request, (int) $data['child_id'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $child = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->join('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->where('c.id', $data['child_id'])
            ->select([
                'c.id', 'c.first_name', 'c.preferred_name', 'c.primary_room_id', 'c.family_id',
                'ce.id as centre_id', 'ce.name as centre_name',
                'a.id as agency_id', 'a.timezone as tz',
            ])
            ->first();

        if (! $child) return response()->json(['message' => 'Not found'], 404);

        // A nullable rule doesn't put the key in $data when it wasn't sent at all.
        $tz = $child->tz ?: 'America/Toronto';
        $date = !empty($data['date'])
            ? Carbon::parse($data['date'], $tz)->toDateString()
            : Carbon::now($tz)->toDateString();

        DB::table('child_absences')->updateOrInsert(
            ['child_id' => (int) $data['child_id'], 'absent_on' => $date],
            [
                'reason' => $data['reason'] ?? null,
                'note' => $data['note'] ?? null,
                'reported_by_id' => $request->user()->id,
                'created_at' => now(),
            ]
        );

        $reporter = trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? ''));
        $this->tellTheCentre($child, $date, $data['reason'] ?? null, $data['note'] ?? null, $reporter, $tz);
        $this->tellTheFamily($child, $date, $data['reason'] ?? null, $data['note'] ?? null,
            $reporter, (int) $request->user()->id, $tz);

        return response()->json(['ok' => true, 'date' => $date]);
    }

    /** DELETE /parent/absences/{child}/{date} — "actually, they are coming in". */
    public function destroy(Request $request, int $child, string $date): JsonResponse
    {
        if (! $this->canAccessChildScoped($request, $child)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $deleted = DB::table('child_absences')
            ->where('child_id', $child)
            ->whereDate('absent_on', $date)
            ->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    /** GET /parent/absences?child_id= — what has already been reported. */
    public function index(Request $request): JsonResponse
    {
        $childId = (int) $request->query('child_id');
        if (! $childId || ! $this->canAccessChildScoped($request, $childId)) {
            return response()->json(['absences' => []]);
        }

        $rows = DB::table('child_absences')
            ->where('child_id', $childId)
            ->orderByDesc('absent_on')
            ->limit(30)
            ->get();

        return response()->json(['absences' => $rows]);
    }

    /**
     * Tell the family, on the same three channels the centre gets.
     *
     * This existed for staff only, which left the case that actually matters
     * unhandled: an EDUCATOR marks a child absent and the parent never learns that
     * it was recorded against their child. If it was a mistake — wrong child on a
     * list of similar names — nobody who could correct it was told.
     *
     * Every guardian is notified, including the one who reported it. For them the
     * wording is a confirmation rather than news, because being told your own action
     * as though it were information is how a parent stops trusting the notification.
     */
    private function tellTheFamily($child, string $date, ?string $reason, ?string $note,
                                   string $reporter, int $reporterId, string $tz): void
    {
        try {
            $name = $child->preferred_name ?: $child->first_name;
            $d = Carbon::parse($date, $tz);
            $when = $d->isToday() ? 'today' : ($d->isTomorrow() ? 'tomorrow' : 'on ' . $d->format('D j M'));

            $guardians = DB::table('guardians as g')
                ->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $child->family_id)
                ->whereNull('u.deleted_at')
                ->get([
                    'u.id', 'u.email', 'u.status',
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),''),'there') as name"),
                ]);

            foreach ($guardians as $g) {
                $isReporter = ((int) $g->id === $reporterId);

                $title = $isReporter
                    ? "✅ Absence recorded for {$name}"
                    : "🚫 {$name} has been marked absent {$when}";

                $line = $isReporter
                    ? "You told {$child->centre_name} that {$name} will not be in {$when}."
                    : "{$child->centre_name} has recorded {$name} as absent {$when}. Reported by {$reporter}.";
                $line = trim($line
                    . ($reason ? ' Reason: ' . ucfirst($reason) . '.' : '')
                    . ($note ? ' Note: “' . $note . '”' : ''));

                // In-app + push.
                try {
                    DB::table('notifications')->insert([
                        'user_id' => (int) $g->id,
                        'type' => 'absence',
                        'title' => $title,
                        'body' => $line,
                        'data' => json_encode(['link' => '#today', 'child_id' => $child->id]),
                        'created_at' => now(),
                    ]);
                    app(FcmService::class)->sendToUser((int) $g->id, $title, $line, '#today', true);
                } catch (\Throwable $e) {
                }

                // Email — but not to somebody who has never accepted their invite.
                // The mail layer would refuse them anyway; stopping here saves
                // building the message at all.
                if (empty($g->email) || in_array((string) $g->status, ['invited', 'not_invited', 'deactivated', 'suspended'], true)) {
                    continue;
                }

                $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($line) . '</p>'
                    . EmailTemplate::calloutBox(
                        '<strong>Child:</strong> ' . e($name) . '<br>'
                        . '<strong>Date:</strong> ' . e($d->format('l, j F Y')) . '<br>'
                        . '<strong>Reason:</strong> ' . e($reason ? ucfirst($reason) : 'Not given')
                        . ($note ? '<br><strong>Note:</strong> ' . e($note) : '')
                        . '<br><strong>Recorded by:</strong> ' . e($isReporter ? 'You' : $reporter),
                        $isReporter ? 'info' : 'warning'
                    )
                    . '<p style="margin:14px 0 0;font-size:14px;line-height:1.6;color:#475569;">'
                    . ($isReporter
                        ? 'Nothing further is needed. If plans change, cancel the absence in the app and ' . e($child->centre_name) . ' will be told.'
                        : 'If this is not right, contact ' . e($child->centre_name) . ' so it can be corrected.')
                    . '</p>';

                $html = EmailTemplate::wrap((int) $child->agency_id, $body, [
                    'eyebrow' => 'ATTENDANCE',
                    'title' => $title,
                    'subtitle' => $child->centre_name . ' · ' . $d->format('D, j M Y'),
                    'preheader' => $line,
                ]);

                $to = $g->email;
                $subject = $title . ' — ' . $d->format('j M Y');
                dispatch(function () use ($child, $to, $html, $subject) {
                    \App\Services\AgencyMailer::forAgency((int) $child->agency_id)->mailer()
                        ->html($html, function ($m) use ($to, $subject) {
                            $m->to($to)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($subject);
                        });
                })->onQueue('mail');
            }
        } catch (\Throwable $e) {
            // Recording the absence must succeed even if telling people fails.
            \Illuminate\Support\Facades\Log::error('Absence family notice failed', [
                'child' => $child->id ?? null, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tell everyone who needs to know: the child's room educators, the centre
     * director, and the agency admin. In-app + push now; email to the staff who
     * would otherwise be phoning the family at 9am.
     */
    private function tellTheCentre($child, string $date, ?string $reason, ?string $note, string $reporter, string $tz): void
    {
        $name = $child->preferred_name ?: $child->first_name;
        $when = Carbon::parse($date, $tz)->isToday() ? 'today' : Carbon::parse($date, $tz)->format('D j M');

        $title = "🚫 {$name} is not in {$when}";
        $body = trim("{$name} will not be at {$child->centre_name} {$when}"
            . ($reason ? ' — ' . ucfirst($reason) : '')
            . ($note ? '. "' . $note . '"' : '')
            . ". Reported by {$reporter}.");

        // Educators assigned to the child's room, plus everyone running the centre.
        $staffIds = DB::table('role_assignments')
            ->where('active', true)
            ->where(function ($q) use ($child) {
                $q->where('centre_id', $child->centre_id)
                  ->orWhere(function ($w) use ($child) {
                      $w->where('agency_id', $child->agency_id)->whereIn('role', ['agency_admin']);
                  });
            })
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        foreach ($staffIds as $uid) {
            try {
                DB::table('notifications')->insert([
                    'user_id' => $uid,
                    'type' => 'absence',
                    'title' => $title,
                    'body' => $body,
                    'data' => json_encode(['link' => '#today', 'child_id' => $child->id]),
                    'created_at' => now(),
                ]);
                app(FcmService::class)->sendToUser((int) $uid, $title, $body, '#today', true);
            } catch (\Throwable $e) {
            }
        }

        // Email the director + agency admin — they are the ones who chase absences.
        try {
            $emails = DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', true)
                ->whereIn('ra.role', ['centre_director', 'agency_admin'])
                ->where(function ($q) use ($child) {
                    $q->where('ra.centre_id', $child->centre_id)
                      ->orWhere('ra.agency_id', $child->agency_id);
                })
                ->whereNull('u.deleted_at')
                ->pluck('u.email')->unique()->values()->all();

            if ($emails) {
                $html = EmailTemplate::wrap((int) $child->agency_id,
                    '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($body) . '</p>'
                    . EmailTemplate::calloutBox(
                        '<strong>Child:</strong> ' . e($name) . '<br>'
                        . '<strong>Date:</strong> ' . e(Carbon::parse($date, $tz)->format('l, j F Y')) . '<br>'
                        . '<strong>Reason:</strong> ' . e($reason ? ucfirst($reason) : 'Not given')
                        . ($note ? '<br><strong>Note:</strong> ' . e($note) : '')
                        . '<br><strong>Reported by:</strong> ' . e($reporter),
                        'warning'
                    ),
                    [
                        'eyebrow' => 'ABSENCE REPORTED',
                        'title' => $title,
                        'subtitle' => $child->centre_name,
                        'preheader' => $body,
                    ]);

                // The heading says "today", which is right when you are reading it and
                // useless in an inbox a fortnight later — and a subject line is what gets
                // searched and filed. So the date goes in it, as the parent-facing absence
                // email already does.
                $subject = $title . ' — ' . Carbon::parse($date, $tz)->format('j M Y');
                dispatch(function () use ($child, $emails, $html, $subject) {
                    \App\Services\AgencyMailer::forAgency((int) $child->agency_id)->mailer()
                        ->html($html, function ($m) use ($emails, $subject) {
                            $m->to($emails)
                              ->from('noreply@kiddietrac.com', 'KiddieTrac')
                              ->subject($subject);
                        });
                })->onQueue('mail');
            }
        } catch (\Throwable $e) {
        }
    }
}
