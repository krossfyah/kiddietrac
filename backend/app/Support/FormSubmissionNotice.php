<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\EmailTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * TWO EMAILS WHEN A FORM IS SIGNED: one to the person who signed it, one to the office.
 *
 * Signing used to be silent in both directions. A parent filled in four forms from a link
 * and got no acknowledgement at all — nothing to keep, no way to tell whether it had
 * worked, which is the state people ring the centre about. And the agency learned nothing
 * until somebody opened the Forms Manager's Completed tab, so "have the Hoseins sent their
 * paperwork back" was a question you had to go and look up.
 *
 * The two are deliberately different letters, not one letter sent twice:
 *
 *   · the SIGNER gets a receipt — you sent this, to this agency, at this time, and here is
 *     what is still outstanding
 *   · the OFFICE gets a notice — this family sent this, here is where they are up to
 *
 * "THE OFFICE" IS THE ADDRESS ON THE FORM, and only that. Not a list this file works out
 * for itself. The first version derived one from roles and thereby overrode what the
 * agency had configured in Forms Manager; see officeEmails() for what that cost.
 *
 * Both name the agency, the family, the exact time in the AGENCY's timezone, and every
 * form the person has been given with its status — because "which forms" is the question
 * both readers actually have, and one form title in isolation does not answer it.
 *
 * Best effort throughout: the signature is already on file by the time this runs, and a
 * mail problem must never turn a successful submission into an error the signer sees.
 * (Anthony, 2026-09-10)
 */
final class FormSubmissionNotice
{
    /** Send both letters for one sign-off. Safe to call from any signing path. */
    public static function send(int $signoffId): void
    {
        try {
            $s = DB::table('managed_form_signoffs as s')
                ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
                ->where('s.id', $signoffId)
                ->whereNotNull('s.signed_at')
                ->first([
                    's.id', 's.user_id', 's.signed_at', 's.signer_name', 's.filled_file_url',
                    'f.id as form_id', 'f.title', 'f.description', 'f.agency_id',
                    // The one field that says who is told about this form. See officeEmails().
                    'f.notify_email',
                ]);
            if (! $s || ! $s->user_id) {
                return;
            }

            $agencyId = (int) $s->agency_id;
            $agency = DB::table('agencies')->where('id', $agencyId)->first(['id', 'name']);
            $agencyName = trim((string) ($agency->name ?? '')) ?: 'your childcare agency';

            $user = DB::table('users')->where('id', $s->user_id)
                ->first(['id', 'first_name', 'last_name', 'email']);
            $who = trim((string) (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')))
                ?: ($s->signer_name ?: (string) ($user->email ?? 'Someone'));

            $tz = AgencyTime::tz($agencyId);
            $when = \Illuminate\Support\Carbon::parse($s->signed_at)->setTimezone($tz);
            $stampFull = $when->format('D, M j, Y') . ' at ' . $when->format('g:i A')
                . ' (' . $when->format('T') . ')';
            $stampShort = $when->format('M j, Y');

            /* THE FAMILY, when there is one.
               A signer is often a guardian, and then the family is the thing the office
               files this under. An educator signing a policy has no family, and the line
               is simply left out rather than printed empty. */
            $family = DB::table('guardians as g')
                ->join('families as f', 'f.id', '=', 'g.family_id')
                ->where('g.user_id', $s->user_id)->whereNull('f.deleted_at')
                ->first(['f.id', 'f.family_name', 'f.centre_id']);
            $familyName = $family ? trim((string) $family->family_name) : null;

            /* EVERY form this person has been given, and where each stands.
               "Which forms filled" is not answerable from the one that triggered this —
               somebody working through a package of four wants to see the package. */
            $status = self::formStatus((int) $s->user_id, $agencyId);

            self::toSigner($user, $who, $agencyId, $agencyName, $familyName, $s, $stampFull, $status);
            self::toOffice($agencyId, $agencyName, $who, $user, $familyName, $s, $stampFull, $stampShort, $status);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Every form addressed to this person, signed or not.
     *
     * Named individually OR reached by a role audience — the same two ways
     * ManagedFormController decides a form is theirs, so this cannot list a different
     * set from the one they see in the portal.
     *
     * @return array{done: array<int, array{title: string, at: ?string}>, open: array<int, string>}
     */
    private static function formStatus(int $userId, int $agencyId): array
    {
        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->pluck('role')->all();
        if (DB::table('guardians')->where('user_id', $userId)->exists()) {
            $roles[] = 'guardian';
        }

        /* Exactly the set the portal would show them — App\Support\FormAudience, the
           one rule. This listed "named OR role" before, which put forms in the
           "still outstanding" section of an email that the reader could not open. */
        $forms = collect(\App\Support\FormAudience::filter(
            DB::table('managed_forms')->where('agency_id', $agencyId)->where('active', 1)
                ->get(['id', 'title', 'audiences']),
            $userId,
            $roles
        ));

        $signed = DB::table('managed_form_signoffs')->where('user_id', $userId)
            ->whereNotNull('signed_at')
            ->orderBy('signed_at')
            ->get(['managed_form_id', 'signed_at'])
            ->keyBy('managed_form_id');

        $done = [];
        $open = [];
        foreach ($forms as $f) {
            $hit = $signed[$f->id] ?? null;
            if ($hit) {
                $done[] = ['title' => (string) $f->title, 'at' => (string) $hit->signed_at];
            } else {
                $open[] = (string) $f->title;
            }
        }

        return ['done' => $done, 'open' => $open];
    }

    /** A list of forms as a readable block, or a dash when there are none. */
    private static function listHtml(array $lines): string
    {
        if (! $lines) {
            return '<span style="color:#94A3B8;">None</span>';
        }

        return '<ul style="margin:6px 0 0;padding-left:20px;">'
            . implode('', array_map(fn ($l) => '<li style="margin:2px 0;">' . $l . '</li>', $lines))
            . '</ul>';
    }

    /** The receipt. */
    private static function toSigner($user, string $who, int $agencyId, string $agencyName,
                                     ?string $familyName, $s, string $stampFull, array $status): void
    {
        $to = trim((string) ($user->email ?? ''));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $tz = AgencyTime::tz($agencyId);
        $doneLines = array_map(function ($d) use ($tz) {
            $at = $d['at'] ? \Illuminate\Support\Carbon::parse($d['at'])->setTimezone($tz)->format('M j, Y') : null;
            return '<strong>' . e($d['title']) . '</strong>' . ($at ? ' <span style="color:#64748B;">— ' . e($at) . '</span>' : '');
        }, $status['done']);
        $openLines = array_map(fn ($t) => e($t), $status['open']);

        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
            . 'Thank you, ' . e($who) . '. Your completed <strong>' . e((string) $s->title) . '</strong> '
            . 'has been submitted to <strong>' . e($agencyName) . '</strong>.</p>'
            . EmailTemplate::calloutBox(
                '<strong>Form submitted:</strong> ' . e((string) $s->title)
                . '<br><strong>Submitted:</strong> ' . e($stampFull)
                . '<br><strong>Agency:</strong> ' . e($agencyName)
                . ($familyName ? '<br><strong>Family:</strong> ' . e($familyName) : '')
                . '<br><strong>Signed by:</strong> ' . e($who),
                'success'
            )
            . '<p style="margin:16px 0 4px;font-size:13.5px;font-weight:700;color:#334155;">Forms you have completed</p>'
            . self::listHtml($doneLines)
            /* Only when something is actually outstanding. A receipt that ends with
               "Still to sign: None" reads like a chase letter for no reason. */
            . ($status['open']
                ? '<p style="margin:16px 0 4px;font-size:13.5px;font-weight:700;color:#334155;">Still to sign</p>'
                    . self::listHtml($openLines)
                : '')
            . '<p style="margin:16px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
            . 'You do not need to do anything else with this form. A copy is kept in your '
            . 'KiddieTrac account under <strong>Documents</strong>, where you can open or save it at any time.</p>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'   => 'FORM RECEIVED',
            'title'     => 'We have your ' . $s->title,
            'subtitle'  => 'Submitted to ' . $agencyName,
            'preheader' => 'Your completed ' . $s->title . ' has been received by ' . $agencyName . '.',
        ]);

        self::queue($to, 'We have received your ' . $s->title . ' — ' . $agencyName, $html, $agencyId);
    }

    /** The notice to the office. */
    private static function toOffice(int $agencyId, string $agencyName, string $who, $user,
                                     ?string $familyName, $s, string $stampFull,
                                     string $stampShort, array $status): void
    {
        $recipients = self::officeEmails($s);
        if (! $recipients) {
            return;
        }

        $tz = AgencyTime::tz($agencyId);
        $doneLines = array_map(function ($d) use ($tz) {
            $at = $d['at'] ? \Illuminate\Support\Carbon::parse($d['at'])->setTimezone($tz)->format('M j, Y g:i A') : null;
            return '<strong>' . e($d['title']) . '</strong>' . ($at ? ' <span style="color:#64748B;">— ' . e($at) . '</span>' : '');
        }, $status['done']);
        $openLines = array_map(fn ($t) => e($t), $status['open']);

        $subject = 'Form submitted: ' . $s->title
            . ($familyName ? ' — ' . $familyName : ' — ' . $who)
            . ' — ' . $stampShort;

        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
            . '<strong>' . e($who) . '</strong>' . ($familyName ? ' (' . e($familyName) . ')' : '')
            . ' has filled in and signed <strong>' . e((string) $s->title) . '</strong>.</p>'
            . EmailTemplate::calloutBox(
                '<strong>Agency:</strong> ' . e($agencyName)
                . ($familyName ? '<br><strong>Family:</strong> ' . e($familyName) : '')
                . '<br><strong>Signed by:</strong> ' . e($who)
                . (($user->email ?? '') ? ' (' . e((string) $user->email) . ')' : '')
                . '<br><strong>Form:</strong> ' . e((string) $s->title)
                . ((string) ($s->description ?? '') !== '' ? '<br><strong>About:</strong> ' . e((string) $s->description) : '')
                . '<br><strong>Submitted:</strong> ' . e($stampFull),
                'info'
            )
            . '<p style="margin:16px 0 4px;font-size:13.5px;font-weight:700;color:#334155;">Completed by this person</p>'
            . self::listHtml($doneLines)
            . '<p style="margin:16px 0 4px;font-size:13.5px;font-weight:700;color:#334155;">Still outstanding</p>'
            . self::listHtml($openLines)
            . '<p style="margin:16px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
            . 'The signed copy is filed on their record and in <strong>Forms Manager → Completed</strong>.</p>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'   => 'FORM SUBMITTED',
            'title'     => (string) $s->title,
            'subtitle'  => 'Signed by ' . $who . ($familyName ? ' · ' . $familyName : ''),
            'preheader' => $who . ' submitted ' . $s->title . '.',
        ]);

        foreach ($recipients as $to) {
            self::queue($to, $subject, $html, $agencyId);
        }
    }

    /**
     * Who in the office hears about it: THE ADDRESS SET ON THE FORM. Nobody else.
     *
     * BUG (found 2026-09-10, by the agency owner): this used to derive its own audience —
     * every agency_admin plus the directors of the centre — and ignore the form's own
     * configuration entirely. Forms Manager says who is notified about a form, in one
     * field, `managed_forms.notify_email`; the Completed-copy mail has always honoured it.
     * This notice did not, so from the moment it shipped, iLearn's Daily Sleep Chart and
     * Daily Supervision Check — configured to go to info@ilearnhcc.com alone — also went
     * to every other agency_admin, including integration+ilearn@kiddietrac.com, a service
     * account that no one had ever put on a form. Educator paperwork about named children
     * landed in mailboxes the agency never chose.
     *
     * A recipient list that a screen displays and the sender then disregards is worse than
     * having no screen: the setting reads as obeyed. The rule is the one the portal
     * already had — the form's address, or nobody.
     *
     * FAILS CLOSED, on purpose. No address on the form means no office notice, exactly as
     * `emailSignoff` refuses to send one ("No address is set on this form"). Silence is
     * recoverable — an admin sets the field and the next submission is delivered. Guessing
     * a recipient is not, because the mail is already gone. The skip is audited so the
     * absence is visible instead of mysterious.
     *
     * @return string[]
     */
    private static function officeEmails(object $s): array
    {
        $to = trim((string) ($s->notify_email ?? ''));

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Audit::write([
                'user_id' => null,
                'agency_id' => (int) $s->agency_id,
                'action' => 'managed_form.notice_skipped_no_address',
                'entity_type' => 'managed_form',
                'entity_id' => (int) $s->form_id,
                // 'payload' — audit_logs has no 'input' column.
                'payload' => json_encode([
                    'form' => (string) $s->title,
                    'signoff_id' => (int) $s->id,
                    'reason' => $to === ''
                        ? 'No notify address is set on this form (Forms Manager → Edit).'
                        : 'The address set on this form is not a valid email: ' . $to,
                ]),
            ]);

            return [];
        }

        return [$to];
    }

    /**
     * Queued, and NOT bypassing suppression.
     *
     * Unlike the completed-copy email — which goes to an address an admin typed, often a
     * licensing contact outside the agency, and therefore carries the bypass header —
     * these two go to the agency's own people about the agency's own paperwork. They are
     * exactly what the per-agency comms switch is for, so they respect it.
     */
    private static function queue(string $to, string $subject, string $html, int $agencyId): void
    {
        dispatch(function () use ($to, $subject, $html, $agencyId) {
            try {
                Mail::html($html, function ($m) use ($to, $subject, $agencyId) {
                    $m->to($to)
                      ->from('noreply@kiddietrac.com', 'KiddieTrac')
                      ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                      ->subject($subject);

                    /* WHICH AGENCY IS WRITING. Not decoration -- SuppressAgencyMail reads
                       this to decide whose switches govern the message. Without it the
                       gate judges EVERY account on the recipient's address, and one
                       address can hold accounts in two agencies: the first version of
                       this file was cancelled outright because the reader also has a Test
                       Agency account whose master switch is off, so iLearn's own receipt
                       to its own admin never left the building. Every sender stamps it;
                       this one now does too. */
                    $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                });
            } catch (\Throwable $e) {
                Log::warning('Form submission notice failed', ['to' => $to, 'error' => $e->getMessage()]);
            }
        })->onQueue('mail');
    }
}
