<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * GETTING PEOPLE ONTO TEXT ALERTS (2026-09-18).
 *
 * Anthony: "SMS broadcast - when sending to whole agency i don't see anything being sent
 * and what can we do to allow everyone to opt in for SMS going forward."
 *
 * The send was working exactly as told. Consent is per person and almost nobody had given
 * it: iLearn had 52 people on active assignments, 42 with a phone on file, and ONE opted
 * in. The only place to say yes was a toggle inside Settings > Notifications, behind a
 * confirm dialog, which is not somewhere a parent goes.
 *
 * THREE WAYS IN, because they reach different people and carry different evidence:
 *
 *   - this file: an emailed, signed, expiring link to a one-page consent screen. The only
 *     one that moves people who are ALREADY on the system, which is the whole current
 *     population.
 *   - onboarding: new families are asked as they join, so the problem stops growing.
 *   - admin-recorded: a director who was told yes in person records it, WITH THEIR OWN
 *     NAME against it, so the evidence is never anonymous.
 *
 * WHAT IS DELIBERATELY NOT HERE: any way to switch consent on in bulk. Consent the person
 * did not give is not consent, it is a carrier complaint and a fine, and a column full of
 * ones nobody can account for is worse than an empty one. Every route below records WHO
 * said yes, WHEN, THROUGH WHICH CHANNEL, and THE EXACT WORDS THEY WERE SHOWN.
 */
final class SmsConsentInviteController extends Controller
{
    /** Long enough to survive a holiday and a reminder, short enough that a leak dies. */
    public const LINK_DAYS = 21;

    public static function linkFor(int $userId): string
    {
        return URL::temporarySignedRoute('sms.consent.page', now()->addDays(self::LINK_DAYS), ['u' => $userId]);
    }

    /* -- the no-login page --------------------------------------------------- */

    /** GET /sms-consent/{u} - signed. */
    public function page(Request $request, int $u)
    {
        $user = DB::table('users')->where('id', $u)->first(['id', 'first_name', 'last_name', 'phone', 'sms_opt_in']);
        if (! $user) {
            return $this->html($this->shell('This link is no longer valid',
                '<p style="margin:0;">Please ask your centre to send it again.</p>'), 404);
        }

        $agencyId = $this->agencyOf($u);
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->value('name') : null;

        if ($user->sms_opt_in) {
            return $this->html($this->shell('Text alerts are already on',
                '<p style="margin:0;">You are set up to get text messages from ' . e((string) ($agency ?: 'your centre'))
                . '. There is nothing more to do. You can turn them off at any time by replying STOP to any '
                . 'text, or from Settings in the app.</p>'));
        }

        return $this->html($this->form($user, (string) ($agency ?: 'your centre'), $request->fullUrl()));
    }

    /** POST /sms-consent/{u} - signed. */
    public function submit(Request $request, int $u)
    {
        $user = DB::table('users')->where('id', $u)->first(['id', 'first_name', 'phone', 'sms_opt_in']);
        if (! $user) {
            return $this->html($this->shell('This link is no longer valid',
                '<p style="margin:0;">Please ask your centre to send it again.</p>'), 404);
        }

        $agencyId = $this->agencyOf($u);
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->value('name') : 'your centre';

        if ($request->input('answer') !== 'yes') {
            /* A no is an answer, not a non-event. Recorded so nobody asks them again by
               hand, and so "we never heard back" can be told apart from "they declined". */
            DB::table('users')->where('id', $u)->update([
                'sms_opt_in' => 0,
                'sms_opt_out_at' => now(),
                'sms_consent_source' => 'email_link',
                'updated_at' => now(),
            ]);
            $this->audit($u, $agencyId, 'sms_consent.declined',
                trim((string) ($user->first_name ?? 'Someone')) . ' declined text alerts from the emailed consent link.');

            \App\Services\SmsConsentReceipt::send($u, false, 'email_link', null, $agencyId);

            return $this->html($this->shell('No problem',
                '<p style="margin:0;">You will not get text messages from ' . e((string) $agency) . '. '
                . 'Email and in-app notices are unchanged. If you change your mind, ask your centre to send the link again.</p>'));
        }

        /* A YES WITH NO NUMBER IS USELESS, so the page asks for one when we hold none.
           Their own record, reached through a signed link that names only them. */
        $phone = preg_replace('/[^0-9+]/', '', (string) $request->input('phone', ''));
        $existing = preg_replace('/[^0-9+]/', '', (string) ($user->phone ?? ''));
        if (strlen((string) $phone) < 10 && strlen((string) $existing) < 10) {
            return $this->html($this->shell('We need a mobile number',
                '<p style="margin:0;">Please go back and enter the mobile number you want texts sent to.</p>'), 422);
        }

        $update = [
            'sms_opt_in' => 1,
            'sms_opt_in_at' => now(),
            'sms_opt_out_at' => null,
            'sms_consent_source' => 'email_link',
            'sms_consent_text' => SmsConsentController::CONSENT_VERSION . ' :: ' . SmsConsentController::CONSENT_TEXT,
            'updated_at' => now(),
        ];
        $changedNumber = false;
        if (strlen((string) $phone) >= 10 && $phone !== $existing) {
            $update['phone'] = $request->input('phone');
            $changedNumber = true;
        }
        DB::table('users')->where('id', $u)->update($update);

        $this->audit($u, $agencyId, 'sms_consent.granted',
            trim((string) ($user->first_name ?? 'Someone')) . ' agreed to text alerts from the emailed consent link'
            . ($changedNumber ? ', and gave a new mobile number' : '') . '.');

        \App\Services\SmsConsentReceipt::send($u, true, 'email_link', null, $agencyId);

        return $this->html($this->shell('You are all set',
            '<p style="margin:0;">You will now get text messages from ' . e((string) $agency) . '. '
            . 'Reply STOP to any message to turn them off, or HELP for help. '
            . 'Message and data rates may apply.</p>'));
    }

    /* -- admin side ---------------------------------------------------------- */

    /** GET /admin/sms/consent-coverage - who can be texted, who cannot, and why. */
    public function coverage(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);

        $rows = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.agency_id', $agencyId)->where('ra.active', true)
            ->groupBy('u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.sms_opt_in',
                'u.sms_opt_in_at', 'u.sms_consent_source', 'u.sms_opt_out_at')
            ->selectRaw("u.id, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) name,
                         u.email, u.phone, u.sms_opt_in, u.sms_opt_in_at, u.sms_consent_source, u.sms_opt_out_at,
                         GROUP_CONCAT(DISTINCT ra.role ORDER BY ra.role SEPARATOR ', ') roles")
            ->orderBy('u.first_name')->get();

        $summary = ['total' => $rows->count(), 'opted_in' => 0, 'declined' => 0, 'no_phone' => 0, 'never_asked' => 0];
        foreach ($rows as $r) {
            $hasPhone = trim((string) $r->phone) !== '';
            if ($r->sms_opt_in) {
                $summary['opted_in']++;
                $r->status = 'opted_in';
            } elseif ($r->sms_opt_out_at) {
                $summary['declined']++;
                $r->status = 'declined';
            } elseif (! $hasPhone) {
                $summary['no_phone']++;
                $r->status = 'no_phone';
            } else {
                $summary['never_asked']++;
                $r->status = 'never_asked';
            }
        }

        return response()->json(['summary' => $summary, 'data' => $rows]);
    }

    /**
     * POST /admin/sms/consent-invites - email the consent link.
     *
     * Only to people this agency actually holds, who have an email, and who have not
     * already answered. An explicit `user_ids` lets a director chase three people without
     * mailing the other forty.
     */
    public function invite(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        /* NAME THEM. NO "EVERYONE" DEFAULT (2026-09-18).

           This used to treat an absent user_ids as "every person in the agency who has
           not consented". Anthony: "can we see the list before this gets sent out as we
           could not want all 41 sent out and does this include staff/contractors etc?"
           - it did, all of them, guardians and staff alike, and one call with an empty
           body mailed the lot.

           A defaulted recipient list is the same mistake as a defaulted `to`: the caller
           has to say who, so that sending to fifty-one people is always something somebody
           chose rather than something that happened. The screen now picks them explicitly
           and there is no path left that skips the picker. */
        $data = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer',
            'include_declined' => 'nullable|boolean',
        ]);

        $q = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.agency_id', $agencyId)->where('ra.active', true)
            ->whereNotNull('u.email')->where('u.email', '!=', '')
            ->where('u.sms_opt_in', 0)
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.email')->distinct();

        /* Somebody who said no has said no. Asking again needs to be a deliberate act, not
           the default behaviour of a button labelled "ask everyone". */
        if (empty($data['include_declined'])) {
            $q->whereNull('u.sms_opt_out_at');
        }
        /* The ids narrow the set; they never widen it. Everything above still applies,
           so an id belonging to another agency, or to somebody who already consented,
           simply does not come back. */
        $q->whereIn('u.id', $data['user_ids']);

        $people = $q->get();
        $sent = 0;
        $failed = 0;
        $suppressed = 0;
        $failures = [];
        $suppressedTo = [];
        $suppressedWhy = null;
        foreach ($people as $p) {
            /* "SENT" MUST MEAN DELIVERED (2026-09-18).

               The mailer returning without throwing means the message was HANDED OVER,
               not that it went anywhere. I reported this very endpoint as "sent: 1" while
               testing, and the email log said:

                 suppressed - Recipient belongs to a suppressed agency

               which is the same false success that made a 7-form package look delivered
               when it never left. So the answer is read back out of email_logs, which the
               suppression listener writes synchronously during the send, rather than
               inferred from the absence of an exception. */
            $before = (int) DB::table('email_logs')->max('id');
            if (! $this->email($agencyId, $p)) {
                $failed++;
                $failures[] = $p->email;
                continue;
            }

            $log = DB::table('email_logs')->where('id', '>', $before)
                ->where('to_email', 'like', '%' . $p->email . '%')
                ->orderByDesc('id')->first(['status', 'error']);

            if ($log && $log->status === 'suppressed') {
                $suppressed++;
                $reason = trim((string) $log->error);
                $suppressedTo[] = $p->email . ' (' . $reason . ')';
                $suppressedWhy = $suppressedWhy ?? $reason;
            } else {
                $sent++;
            }
        }

        Audit::write([
            'user_id' => $request->user()->id,
            'agency_id' => $agencyId,
            'action' => 'sms_consent.invites_sent',
            'entity_type' => 'agency',
            'entity_id' => $agencyId,
            'payload' => json_encode([
                'summary' => 'Emailed a text-alert consent link to ' . $sent . ' '
                    . ($sent === 1 ? 'person' : 'people')
                    . ($suppressed ? ', ' . $suppressed . ' suppressed before delivery' : '')
                    . ($failed ? ', ' . $failed . ' could not be sent' : '') . '.',
                'suppressed' => $suppressedTo,
                /* Named, not counted - a count cannot answer "was Natasha asked?". */
                'recipients' => $people->pluck('email')->all(),
                'failed' => $failures,
            ]),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'sent' => $sent,
            'suppressed' => $suppressed,
            'failed' => $failed,
            'total' => $people->count(),
            /* Named so the screen can say WHY, not just how many. A suppressed invite is
               almost always a deliberate agency-level switch, and a director staring at
               "0 sent" deserves to be told that rather than left to guess. */
            'suppressed_reason' => $suppressedWhy,
        ]);
    }

    /**
     * POST /admin/sms/consent/{user} - a director records a yes they were given in person.
     *
     * Stored with the recorder's own name. Consent obtained verbally is acceptable to
     * carriers when it is documented; "somebody ticked a box once" is not, so the row
     * carries who, when, and the exact wording the person was read.
     */
    public function record(Request $request, int $user): JsonResponse
    {
        $agencyId = $this->agency($request);
        $data = $request->validate([
            'agreed' => 'required|boolean',
            'note' => 'nullable|string|max:300',
        ]);

        abort_unless(DB::table('role_assignments')->where('user_id', $user)
            ->where('agency_id', $agencyId)->where('active', true)->exists(), 403, 'Not in this agency.');

        $u = DB::table('users')->where('id', $user)->first(['id', 'first_name', 'last_name', 'phone']);
        abort_unless($u, 404, 'No such person.');

        if ($data['agreed'] && trim((string) ($u->phone ?? '')) === '') {
            return response()->json([
                'message' => 'That person has no mobile number on file, so text alerts cannot be turned on.',
            ], 422);
        }

        $by = $request->user();
        $byName = trim(($by->first_name ?? '') . ' ' . ($by->last_name ?? '')) ?: ($by->email ?? ('user ' . $by->id));

        DB::table('users')->where('id', $user)->update($data['agreed'] ? [
            'sms_opt_in' => 1,
            'sms_opt_in_at' => now(),
            'sms_opt_out_at' => null,
            'sms_consent_source' => 'admin',
            'sms_consent_recorded_by' => $by->id,
            'sms_consent_text' => SmsConsentController::CONSENT_VERSION . ' :: recorded by ' . $byName
                . ($data['note'] ? ' :: ' . $data['note'] : '') . ' :: ' . SmsConsentController::CONSENT_TEXT,
            'updated_at' => now(),
        ] : [
            'sms_opt_in' => 0,
            'sms_opt_out_at' => now(),
            'sms_consent_source' => 'admin',
            'sms_consent_recorded_by' => $by->id,
            'updated_at' => now(),
        ]);

        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ('user ' . $user);

        /* The receipt matters MOST here: this is the one path where somebody else pressed
           the button. The email names the person who recorded it, so the first the subject
           hears of it is not a text message they do not remember agreeing to. */
        \App\Services\SmsConsentReceipt::send($user, (bool) $data['agreed'], 'admin', $byName, $agencyId);

        $this->audit($user, $agencyId, $data['agreed'] ? 'sms_consent.granted' : 'sms_consent.declined',
            $byName . ' recorded that ' . $name . ($data['agreed'] ? ' agreed to' : ' declined') . ' text alerts'
            . ($data['note'] ? ' (' . $data['note'] . ')' : '') . '.', (int) $by->id);

        return response()->json(['ok' => true, 'opted_in' => (bool) $data['agreed']]);
    }

    /* -- plumbing ------------------------------------------------------------ */

    private function email(int $agencyId, object $p): bool
    {
        $name = trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? ''));
        $agency = DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'your centre';
        $link = self::linkFor((int) $p->id);

        $body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="font-size:15px;color:#334155;line-height:1.65;">'
            . 'Hello' . ($name ? ' ' . e(explode(' ', $name)[0]) : '') . ',<br><br>'
            . e($agency) . ' can send you a text message when your child is signed in or out, and for urgent '
            . 'notices such as an early closure. We will only do this if you say yes.'
            . '</td></tr>'
            . '<tr><td style="padding:22px 0 0;"><a href="' . e($link) . '" style="background:#1F6080;color:#fff;'
            . 'text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:10px;display:inline-block;">'
            . 'Choose yes or no</a></td></tr>'
            . '<tr><td style="padding:18px 0 0;font-size:12.5px;color:#94A3B8;line-height:1.55;">'
            . 'No sign-in needed. Message frequency varies and message and data rates may apply. You can stop the '
            . 'messages at any time by replying STOP. This link is personal to you and expires in '
            . self::LINK_DAYS . ' days.'
            . '</td></tr></table>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'TEXT ALERTS',
            'title' => 'Would you like text alerts?',
            'subtitle' => 'One question, no sign-in needed',
            'preheader' => $agency . ' would like to text you about sign-in, sign-out and urgent notices.',
        ]);

        try {
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($p, $name, $agencyId) {
                $m->to($p->email, $name ?: null)->subject('Would you like text alerts?');
                try {
                    $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                } catch (\Throwable $e) {
                }
                /* Same reason as a form package: this is the question you ask someone
                   BEFORE they are set up, so the not-onboarded gate must not eat it.
                   See SuppressAgencyMail. */
                try {
                    $m->getHeaders()->addTextHeader('X-KT-Form-Package', '1');
                } catch (\Throwable $e) {
                }
            });

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function agency(Request $request): int
    {
        $active = (int) $request->header('X-Active-Agency-Id');
        abort_unless($active > 0, 422, 'No active agency.');
        abort_unless(DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)
            ->where(function ($w) use ($active) {
                $w->where('agency_id', $active)->orWhere('role', 'platform_admin');
            })
            ->exists(), 403, 'Not your agency.');

        return $active;
    }

    private function agencyOf(int $userId): ?int
    {
        $id = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->orderByRaw("role = 'guardian' DESC")->value('agency_id');

        return $id ? (int) $id : null;
    }

    private function audit(int $subject, ?int $agencyId, string $action, string $summary, ?int $actor = null): void
    {
        try {
            Audit::write([
                'user_id' => $actor,
                'agency_id' => $agencyId,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => $subject,
                'payload' => json_encode([
                    'summary' => $summary,
                    'consent_version' => SmsConsentController::CONSENT_VERSION,
                ]),
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private function html(string $body, int $status = 200)
    {
        return response($body, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function form(object $user, string $agency, string $action): string
    {
        $phone = trim((string) ($user->phone ?? ''));
        $known = $phone !== '';

        return $this->shell('Would you like text alerts?',
            '<p style="margin:0 0 18px;">' . e($agency) . ' can text you when your child is signed in or out, '
            . 'and for urgent notices such as an early closure.</p>'
            . '<p style="margin:0 0 20px;font-size:13.5px;color:#64748B;line-height:1.6;">'
            . e(SmsConsentController::CONSENT_TEXT) . '</p>'
            . '<form method="post" action="' . e($action) . '">'
            . '<label style="display:block;font-size:12px;font-weight:700;color:#475569;letter-spacing:.04em;'
            . 'text-transform:uppercase;margin-bottom:6px;">Mobile number</label>'
            . '<input name="phone" type="tel" value="' . e($phone) . '" placeholder="(416) 555-0199" '
            . 'style="width:100%;box-sizing:border-box;padding:12px 14px;font-size:16px;border:1px solid #CBD5E1;'
            . 'border-radius:10px;margin-bottom:6px;">'
            . '<div style="font-size:12px;color:#94A3B8;margin-bottom:22px;">'
            . ($known ? 'This is the number we hold for you. Change it here if it is wrong.'
                      : 'We do not have a mobile number for you yet.') . '</div>'
            . '<button name="answer" value="yes" type="submit" style="width:100%;background:#1F6080;color:#fff;'
            . 'border:none;padding:15px;font-size:16px;font-weight:700;border-radius:10px;cursor:pointer;">'
            . 'Yes, send me text alerts</button>'
            . '<button name="answer" value="no" type="submit" style="width:100%;background:transparent;'
            . 'color:#64748B;border:none;padding:14px;font-size:14px;margin-top:10px;cursor:pointer;">'
            . 'No thanks</button>'
            . '</form>');
    }

    private function shell(string $title, string $bodyHtml): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>' . e($title) . '</title></head>'
            . '<body style="margin:0;background:#F1F5F9;font:15px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#0F172A;">'
            . '<div style="max-width:480px;margin:0 auto;padding:40px 18px;">'
            . '<div style="background:#fff;border-radius:16px;padding:30px 26px;box-shadow:0 10px 40px rgba(15,23,42,.08);">'
            . '<h1 style="margin:0 0 16px;font-size:21px;line-height:1.3;">' . e($title) . '</h1>'
            . '<div style="color:#334155;">' . $bodyHtml . '</div>'
            . '</div>'
            . EmailTemplate::pageFooterHtml()
            . '</div></body></html>';
    }
}
