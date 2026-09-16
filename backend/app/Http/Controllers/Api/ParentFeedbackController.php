<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "How was their day?" — feedback a parent leaves from the daily summary email.
 *
 * The link is a SIGNED URL with no login. That is a deliberate trade: asking a parent to
 * sign in at 7pm, on a phone, to answer four questions produces almost no responses, and
 * feedback nobody leaves is worth less than the security it buys. The link is write-only —
 * it can create one row and read nothing — scoped to one child and one date, and expires
 * after 14 days.
 *
 * The GET renders a form and changes NOTHING: mail scanners fetch every link in a message
 * before it is delivered, so a one-tap action that mutated on GET would file feedback
 * nobody wrote. Same rule as TimeOffController::actPage.
 */
final class ParentFeedbackController extends Controller
{
    public const LINK_DAYS = 14;

    /** Praise goes straight to the educator; anything at or below this waits for a director. */
    private const PRAISE_FROM = 4;

    // ─── The public, signed form ────────────────────────────────────────────

    public function form(Request $request, int $child)
    {
        $ctx = $this->context($child, $request->query('d'));
        if (! $ctx) {
            return $this->shell('That link is no longer valid.',
                'The child may have left, or the link has expired.', null, 404);
        }
        return $this->shell(null, null, $ctx);
    }

    public function submit(Request $request, int $child)
    {
        $ctx = $this->context($child, $request->query('d'));
        if (! $ctx) {
            return $this->shell('That link is no longer valid.',
                'The child may have left, or the link has expired.', null, 404);
        }

        $data = $request->validate([
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'tomorrow_note' => ['nullable', 'string', 'max:2000'],
            'educator_id' => ['nullable', 'integer'],
            'compliment' => ['nullable', 'string', 'max:2000'],
        ]);

        // Nothing said is not feedback. Better to say so than to file an empty row.
        $said = ($data['rating'] ?? null) || trim((string) ($data['comment'] ?? ''))
             || trim((string) ($data['tomorrow_note'] ?? '')) || trim((string) ($data['compliment'] ?? ''));
        if (! $said) {
            return $this->shell('Nothing was filled in.',
                'Add a rating or a note and send it again.', $ctx, 422);
        }

        // One per family per day: a reload of the form must not become a second entry.
        $already = DB::table('parent_feedback')
            ->where('child_id', $ctx['child']->id)
            ->where('for_date', $ctx['date'])
            ->whereNotNull('for_date')
            ->exists();
        if ($already) {
            return $this->shell('Thank you — we already have your note for that day.',
                'If you need to add something, please message us in the app.', null);
        }

        /* validate() omits any key that was not submitted, so every optional field has
           to be read defensively — `$data['educator_id']` threw on the very first real
           submission, which had no educator picked. */
        $educatorId = ($data['educator_id'] ?? null) ?: null;
        if ($educatorId && ! in_array((int) $educatorId, array_map(fn ($e) => (int) $e->id, $ctx['educators']), true)) {
            $educatorId = null;   // only an educator who actually works with this child
        }

        $rating = $data['rating'] ?? null;
        $isPraise = $rating !== null && (int) $rating >= self::PRAISE_FROM;

        $id = DB::table('parent_feedback')->insertGetId(array_filter([
            'family_id' => $ctx['child']->family_id,
            'child_id' => $ctx['child']->id,
            'centre_id' => $ctx['centre_id'],
            'for_date' => $ctx['date'],
            'educator_id' => $educatorId,
            /* Praise is released immediately; anything else waits for a director to
               decide how to pass it on. Anthony, 2026-08-26. */
            'released_at' => $isPraise ? now() : null,
            'user_id' => null,          // a signed link identifies a FAMILY, not a person
            'rating' => $rating,
            'category' => 'day',
            'comment' => $data['comment'] ?? null,
            'tomorrow_note' => $data['tomorrow_note'] ?? null,
            'source' => 'email_link',
            'created_at' => now(),
        ], fn ($v) => $v !== null));

        $this->notify($ctx, $id, $rating, $isPraise, $educatorId);

        return $this->shell('Thank you — that has gone to the team.',
            $isPraise
                ? 'Your educator will see it.'
                : 'The centre director will read it and follow up with you.',
            null);
    }

    // ─── Staff side ─────────────────────────────────────────────────────────

    /** Feedback the signed-in educator is allowed to see: released, and about them or their rooms. */
    public function mine(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;

        $rows = DB::table('parent_feedback as pf')
            ->leftJoin('children as c', 'c.id', '=', 'pf.child_id')
            ->whereNotNull('pf.released_at')
            ->where(function ($q) use ($uid) {
                $q->where('pf.educator_id', $uid)
                  ->orWhere(function ($qq) use ($uid) {
                      // Room-wide feedback: named nobody, but is about a room they work in.
                      $qq->whereNull('pf.educator_id')
                         ->whereIn('c.primary_room_id', function ($sub) use ($uid) {
                             $sub->from('educator_rooms')->select('room_id')->where('user_id', $uid);
                         });
                  });
            })
            ->orderByDesc('pf.created_at')
            ->limit(100)
            ->get([
                'pf.id', 'pf.rating', 'pf.comment', 'pf.tomorrow_note', 'pf.for_date',
                'pf.created_at', 'pf.educator_read_at', 'pf.educator_id',
                DB::raw("TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) as child_name"),
            ]);

        return response()->json([
            'feedback' => $rows,
            'unread' => $rows->whereNull('educator_read_at')->count(),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $ids = (array) $request->input('ids', []);
        $q = DB::table('parent_feedback')->whereNotNull('released_at')->whereNull('educator_read_at');
        if ($ids) {
            $q->whereIn('id', array_map('intval', $ids));
        }
        // Only rows this educator is entitled to; never a blanket update.
        $n = $q->where('educator_id', $uid)->update(['educator_read_at' => now()]);
        return response()->json(['marked' => $n]);
    }

    /** A director releasing held feedback to the educator it names. */
    public function release(Request $request, int $id): JsonResponse
    {
        $row = DB::table('parent_feedback')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $agencyId = (int) $request->header('X-Active-Agency-Id');
        $centreAgency = $row->centre_id
            ? (int) DB::table('centres')->where('id', $row->centre_id)->value('agency_id')
            : 0;
        if (! $agencyId || $centreAgency !== $agencyId) {
            return response()->json(['message' => 'No access to this feedback'], 403);
        }

        DB::table('parent_feedback')->where('id', $id)->update([
            'released_at' => now(),
            'released_by_id' => $request->user()->id,
        ]);
        return response()->json(['ok' => true, 'released_at' => now()->toDateTimeString()]);
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    /** Resolve the child + day a signed link refers to, or null if it no longer applies. */
    private function context(int $childId, $dateParam): ?array
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) {
            return null;
        }
        $family = DB::table('families')->where('id', $child->family_id)->first();
        if (! $family) {
            return null;
        }
        $centreId = (int) ($family->centre_id ?? 0);
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        $tz = \App\Support\AgencyTime::tz($agencyId ?: null);

        $date = null;
        try {
            $date = $dateParam ? Carbon::parse((string) $dateParam)->toDateString() : Carbon::now($tz)->toDateString();
        } catch (\Throwable $e) {
            $date = Carbon::now($tz)->toDateString();
        }

        $educators = [];
        try {
            if (Schema::hasTable('educator_rooms') && $child->primary_room_id) {
                $educators = DB::table('educator_rooms as er')
                    ->join('users as u', 'u.id', '=', 'er.user_id')
                    ->where('er.room_id', $child->primary_room_id)
                    ->whereNull('u.deleted_at')->where('u.status', 'active')
                    ->distinct()
                    ->get(['u.id', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as name")])
                    ->all();
            }
        } catch (\Throwable $e) { /* the form still works without the praise picker */ }

        return [
            'child' => $child,
            'family' => $family,
            'centre_id' => $centreId,
            'agency_id' => $agencyId,
            'date' => $date,
            'tz' => $tz,
            'educators' => $educators,
            'child_name' => trim((string) ($child->preferred_name ?: $child->first_name)),
        ];
    }

    /** Tell the right people, according to what was said. */
    private function notify(array $ctx, int $id, $rating, bool $isPraise, ?int $educatorId): void
    {
        try {
            $title = $isPraise
                ? ('🌟 Kind words from ' . ($ctx['family']->family_name ?? 'a parent'))
                : (($rating ? $rating . '-star' : 'New') . ' feedback from ' . ($ctx['family']->family_name ?? 'a parent'));

            $recipients = [];
            if ($isPraise && $educatorId) {
                $recipients[] = $educatorId;      // straight to the person it is about
            }
            // Directors and admins always hear about it.
            $recipients = array_merge($recipients, DB::table('role_assignments')
                ->whereIn('role', ['centre_director', 'agency_admin'])
                ->where('centre_id', $ctx['centre_id'])
                ->where('active', 1)
                ->pluck('user_id')->all());

            foreach (array_unique(array_filter($recipients)) as $uid) {
                \App\Support\Notify::write([
                    'user_id' => $uid,
                    'type' => 'feedback',
                    'title' => $title,
                    'body' => mb_substr((string) ($ctx['child_name'] . ' — ' . ($rating ? $rating . '/5' : 'note')), 0, 200),
                    'data' => json_encode(['link' => '#feedback', 'feedback_id' => $id]),
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // Telling people is a courtesy; it must never lose the feedback itself.
        }
    }

    /** A small, self-contained branded page — no portal assets, no login. */
    private function shell(?string $doneTitle, ?string $doneBody, ?array $ctx, int $status = 200)
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $agencyName = 'your centre';
        if ($ctx) {
            $agencyName = (string) (DB::table('agencies')->where('id', $ctx['agency_id'])->value('name') ?: 'your centre');
        }

        $inner = '';
        if ($doneTitle !== null) {
            $inner = '<div class="card done">'
                . '<div class="tick">✓</div>'
                . '<h1>' . $e($doneTitle) . '</h1>'
                . ($doneBody ? '<p>' . $e($doneBody) . '</p>' : '')
                . '</div>';
        }

        if ($ctx) {
            $day = Carbon::parse($ctx['date'])->format('l j F');
            $opts = '';
            foreach ($ctx['educators'] as $ed) {
                $opts .= '<option value="' . (int) $ed->id . '">' . $e($ed->name) . '</option>';
            }
            $inner .= '<form class="card" method="post">'
                . '<h1>How was ' . $e($ctx['child_name']) . '\'s day?</h1>'
                . '<p class="sub">' . $e($day) . ' at ' . $e($agencyName) . '. It takes a minute, and it goes straight to the team.</p>'

                . '<label class="lbl">Their day</label>'
                . '<div class="stars">';
            foreach ([[5, '😊', 'Great'], [4, '🙂', 'Good'], [3, '😐', 'OK'], [2, '🙁', 'Not great'], [1, '😟', 'Poor']] as [$v, $emo, $lab]) {
                $inner .= '<label class="star"><input type="radio" name="rating" value="' . $v . '">'
                    . '<span class="emo">' . $emo . '</span><span class="lab">' . $e($lab) . '</span></label>';
            }
            $inner .= '</div>'

                . '<label class="lbl" for="comment">Anything you\'d like to say</label>'
                . '<textarea id="comment" name="comment" rows="3" placeholder="What went well, or what you noticed…"></textarea>'

                . '<label class="lbl" for="tomorrow_note">Anything we should know for tomorrow?</label>'
                . '<textarea id="tomorrow_note" name="tomorrow_note" rows="2" placeholder="Slept badly, off their food, a bit anxious…"></textarea>';

            if ($opts) {
                $inner .= '<label class="lbl" for="educator_id">Want to thank someone in particular?</label>'
                    . '<select id="educator_id" name="educator_id"><option value="">— no one in particular —</option>' . $opts . '</select>';
            }

            $inner .= '<button type="submit">Send to the team</button>'
                . '<p class="fine">Your note is shared with the centre director. Kind words are passed to your educator too.</p>'
                . '</form>';
        }

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>How was their day?</title><style>'
            . ':root{--ink:#0F172A;--muted:#64748B;--line:#E2E8F0;--brand:#0E7C90;}'
            . '*{box-sizing:border-box}'
            . 'body{margin:0;background:#F1F5F9;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:var(--ink);padding:22px 14px 40px;}'
            . '.wrap{max-width:520px;margin:0 auto}'
            . '.card{background:#fff;border-radius:16px;padding:22px;box-shadow:0 6px 24px rgba(15,23,42,.07);margin-bottom:14px}'
            . 'h1{font-size:21px;margin:0 0 6px;line-height:1.3}'
            . '.sub{color:var(--muted);font-size:14px;margin:0 0 18px}'
            . '.lbl{display:block;font-size:12px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:var(--muted);margin:16px 0 7px}'
            . '.stars{display:flex;gap:6px}'
            . '.star{flex:1;text-align:center;border:1px solid var(--line);border-radius:11px;padding:9px 3px;cursor:pointer;transition:.12s}'
            . '.star input{position:absolute;opacity:0;width:0;height:0}'
            . '.star .emo{display:block;font-size:23px}'
            . '.star .lab{display:block;font-size:10.5px;color:var(--muted);margin-top:3px}'
            . '.star:has(input:checked){border-color:var(--brand);background:#ECFEFF;box-shadow:0 0 0 2px rgba(14,124,144,.15)}'
            . 'textarea,select{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font:inherit;font-size:15px;background:#fff}'
            . 'textarea{resize:vertical}'
            . 'button{width:100%;margin-top:20px;background:var(--brand);color:#fff;border:0;border-radius:11px;padding:14px;font-size:16px;font-weight:800;cursor:pointer}'
            . '.fine{font-size:12px;color:var(--muted);margin:12px 0 0;text-align:center}'
            . '.done{text-align:center}.done .tick{font-size:40px;color:#16A34A}'
            . '</style></head><body><div class="wrap">' . $inner . '</div></body></html>';

        // The \u{...} escapes above are PHP double-quoted only; this file uses single
        // quotes for the markup, so decode them once here.
        $html = preg_replace_callback('/\\\\u\{([0-9A-Fa-f]+)\}/', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $html);

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
