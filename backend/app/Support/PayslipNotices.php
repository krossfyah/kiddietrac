<?php

namespace App\Support;

use App\Http\Controllers\Api\PayrollDocumentController;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PAYSLIP EMAIL NOTICES (2026-10-31, Anthony: "with the payroll synced in KiddieTrac - KiddieTrac is not sending out the
 * payroll notices to the staff - pls look into this, wire this up in settings").
 *
 * Nothing emailed staff when a payslip arrived: IntegrationController::upsertPayroll only stores the row, and the one
 * payslip email (PayrollDocumentController::email) is the manual Resend. Today's personal announcements put a bell and a
 * push in the app; this adds the EMAIL.
 *
 * A sweep (kt:payslip-notices, every 2 minutes) rather than a hook in each path: payslips arrive from the iLearn sync,
 * "Generate payroll" and manual payroll, and one sweep over "created since payroll.payslip_email_since and not emailed
 * yet" covers all of them and can never email the same payslip twice (payslip_notices, unique per document + key).
 *
 * Rules:
 *  - nothing created before payroll.payslip_email_since (set by the migration) - no backfill; the command's --since
 *    --apply sends an older batch only when someone asks for it;
 *  - kind payslip, not void, linked to a staff account (the email goes to that account's address, never a guessed one);
 *    iLearn never sends switched-off contacts (kiddietrac_enabled=0) and voids their old payslips, so they never qualify;
 *  - agency settings (agencies.settings.payroll): email_payslips (default on), email_attach_pdf (on), email_bcc_user_ids
 *    ([]), email_updated_notices (off), email_timing immediate|batch + email_batch_time HH:MM (agency time);
 *  - the agency's "Send notifications and emails" switch and the mail suppression listener still apply;
 *  - an updated notice only when the setting is on and the NET changed after the first notice.
 */
final class PayslipNotices
{
    public static function since(): ?string
    {
        $s = PlatformSettings::get('payroll.payslip_email_since');
        return $s ? (string) $s : null;
    }

    /** The agency's email settings, defaults filled in. */
    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $p = (isset($s['payroll']) && is_array($s['payroll'])) ? $s['payroll'] : [];
        $time = (string) ($p['email_batch_time'] ?? '08:00');
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) $time = '08:00';
        return [
            'email_payslips' => (($p['email_payslips'] ?? true) !== false),
            'email_attach_pdf' => (($p['email_attach_pdf'] ?? true) !== false),
            'email_bcc_user_ids' => array_values(array_map('intval', (array) ($p['email_bcc_user_ids'] ?? []))),
            'email_updated_notices' => (($p['email_updated_notices'] ?? false) === true),
            'email_timing' => (($p['email_timing'] ?? 'immediate') === 'batch') ? 'batch' : 'immediate',
            'email_batch_time' => $time,
        ];
    }

    /** People who may be copied: active agency admins and centre directors of that agency. [id => [name, email]] */
    public static function bccChoices(int $agencyId): array
    {
        $rows = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.agency_id', $agencyId)->where('r.active', 1)->whereIn('r.role', ['agency_admin', 'centre_director'])
            ->whereNotNull('u.email')->where('u.email', '!=', '')
            ->orderBy('u.first_name')->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'r.role']);
        $out = [];
        foreach ($rows as $r) {
            if (isset($out[(int) $r->id])) continue;
            $out[(int) $r->id] = ['id' => (int) $r->id, 'name' => trim($r->first_name . ' ' . $r->last_name), 'email' => (string) $r->email,
                'role' => $r->role === 'agency_admin' ? 'Agency admin' : 'Centre director'];
        }
        return $out;
    }

    /**
     * One pass. $since overrides the cut-off (backlog), $docIds limits to those documents.
     * Returns counts and, for a dry run, the list of what would go.
     */
    public static function run(bool $dry = false, ?int $agencyId = null, ?string $since = null, array $docIds = []): array
    {
        $out = ['sent' => 0, 'updated' => 0, 'held' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0, 'would' => []];
        $cut = $since ?: self::since();
        if (! $cut && ! $docIds) return $out;

        $q = DB::table('payroll_documents')->where('kind', 'payslip')->where('status', '!=', 'void')->whereNotNull('user_id');
        if ($docIds) $q->whereIn('id', $docIds); else $q->where('created_at', '>=', $cut);
        if ($agencyId) $q->where('agency_id', $agencyId);
        $docs = $q->orderBy('id')->limit(500)->get();

        $cfgs = [];
        foreach ($docs as $doc) {
            $aid = (int) $doc->agency_id;
            if (\App\Support\Features::skips($aid, 'payroll', 'payslip notice')) { $out['skipped']++; continue; }   // not in the agency's plan (2026-11-26)
            $cfg = $cfgs[$aid] ??= self::settings($aid);
            if (! $cfg['email_payslips']) { $out['skipped']++; continue; }

            $first = DB::table('payslip_notices')->where('payroll_document_id', $doc->id)->where('kind', 'new')->first();
            $kind = 'new';
            $key = 'new';
            if ($first) {
                // Already emailed. An updated notice only when asked for and the pay actually changed.
                if (! $cfg['email_updated_notices'] || $first->status !== 'sent') continue;
                $latest = DB::table('payslip_notices')->where('payroll_document_id', $doc->id)->orderByDesc('id')->first();
                if (abs((float) $latest->net - (float) $doc->net) < 0.005) continue;
                $kind = 'updated';
                $key = 'upd:' . number_format((float) $doc->net, 2, '.', '');
                if (DB::table('payslip_notices')->where('payroll_document_id', $doc->id)->where('dedupe_key', $key)->exists()) continue;
            }

            // A daily batch: only what arrived before today's send time, and only once that time has passed.
            if ($cfg['email_timing'] === 'batch' && ! $docIds) {
                $tz = AgencyTime::tz($aid) ?: 'America/Toronto';
                $at = Carbon::parse(Carbon::now($tz)->toDateString() . ' ' . $cfg['email_batch_time'], $tz);
                if (Carbon::now($tz)->lt($at) || Carbon::parse($doc->created_at, 'UTC')->gte($at->copy()->utc())) { $out['waiting']++; continue; }
            }

            if ($dry) {
                $u = DB::table('users')->where('id', $doc->user_id)->first(['first_name', 'email']);
                $out['would'][] = ['doc' => (int) $doc->id, 'agency' => $aid, 'first_name' => $u->first_name ?? '', 'email' => self::mask((string) ($u->email ?? '')),
                    'period' => self::period($doc), 'kind' => $kind];
                continue;
            }
            $r = self::send($doc, $cfg, $kind, $key);
            $out[$r]++;
            if ($r === 'sent' && $kind === 'updated') { $out['sent']--; $out['updated']++; }
        }
        return $out;
    }

    /** "sa***@gmail.com" */
    public static function mask(string $e): string
    {
        if (! str_contains($e, '@')) return $e;
        [$a, $d] = explode('@', $e, 2);
        return substr($a, 0, 2) . '***@' . $d;
    }

    public static function period(object $doc): string
    {
        $s = $doc->period_start ? Carbon::parse($doc->period_start) : null;
        $e = $doc->period_end ? Carbon::parse($doc->period_end) : null;
        if ($s && $e && $s->toDateString() !== $e->toDateString()) {
            return $s->year === $e->year ? $s->format('M j') . ' – ' . $e->format('M j, Y') : $s->format('M j, Y') . ' – ' . $e->format('M j, Y');
        }
        return ($e ?: $s) ? ($e ?: $s)->format('M j, Y') : 'this pay period';
    }

    /**
     * Email one payslip notice. $to (test only) overrides the recipient and records nothing in payslip_notices.
     * Returns sent | held | failed | skipped.
     */
    public static function send(object $doc, array $cfg, string $kind = 'new', string $key = 'new', ?string $to = null, bool $test = false): string
    {
        $aid = (int) $doc->agency_id;
        $user = DB::table('users')->where('id', $doc->user_id)->first(['id', 'first_name', 'last_name', 'email']);
        $recipient = $to ?: (string) ($user->email ?? '');
        $who = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) $doc->payee_name;
        $period = self::period($doc);
        $record = function (string $status, ?string $reason = null) use ($doc, $kind, $key, $recipient, $test) {
            if ($test) return;
            try {
                DB::table('payslip_notices')->insert(['payroll_document_id' => $doc->id, 'user_id' => $doc->user_id, 'agency_id' => $doc->agency_id,
                    'kind' => $kind, 'dedupe_key' => $key, 'net' => $doc->net, 'to_email' => $recipient ?: null, 'status' => $status,
                    'reason' => $reason, 'created_at' => now()]);
            } catch (Throwable $e) {
                // Unique (document, key): another pass already recorded it.
            }
        };

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) { $record('skipped', 'no email on the staff account'); return 'skipped'; }
        if (! Suppression::agencyNotificationsEnabled($aid)) { $record('skipped', 'agency email switched off'); return 'skipped'; }

        $agencyName = (string) (DB::table('agencies')->where('id', $aid)->value('name') ?: 'your agency');
        $first = trim((string) ($user->first_name ?? '')) ?: (string) strtok((string) $doc->payee_name, ' ');

        // The payslip itself, through the same method every portal screen uses (iLearn's own PDF when it sent one).
        $pdf = null;
        // Payroll engine payslips (P2): the notice says who and when, never what - no amounts and no attachment.
        if ($cfg['email_attach_pdf'] && ($doc->source ?? '') !== 'engine') {
            try {
                $req = Request::create('/', 'GET');
                $u = \App\Models\User::find((int) $doc->user_id);
                if ($u) {
                    $req->setUserResolver(fn () => $u);
                    $res = app(PayrollDocumentController::class)->pdf($req, (int) $doc->id);
                    $bytes = method_exists($res, 'getContent') ? $res->getContent() : null;
                    if (is_string($bytes) && str_starts_with($bytes, '%PDF')) $pdf = $bytes;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        [$subject, $html] = self::render($aid, $doc, $first, $agencyName, $kind, $pdf !== null, $cfg['email_attach_pdf']);
        if ($test) $subject = '[TEST] ' . $subject;
        $fileName = 'Payslip ' . ($doc->period_start ?: '') . ($doc->period_end && $doc->period_end !== $doc->period_start ? ' to ' . $doc->period_end : '') . '.pdf';

        $bcc = [];
        if (! $test) {
            $choices = self::bccChoices($aid);
            foreach ($cfg['email_bcc_user_ids'] as $bid) {
                if (isset($choices[$bid]) && strcasecmp($choices[$bid]['email'], $recipient) !== 0) $bcc[] = $choices[$bid]['email'];
            }
        }

        $sentAt = now();
        try {
            AgencyMailer::forAgency($aid)->html($html, function ($m) use ($recipient, $first, $subject, $pdf, $fileName, $bcc) {
                $m->to($recipient, $first !== '' ? $first : null)->subject($subject);
                foreach ($bcc as $b) $m->bcc($b);
                if ($pdf !== null) $m->attachData($pdf, $fileName, ['mime' => 'application/pdf']);
            });
        } catch (Throwable $e) {
            report($e);
            if (! $test) {
                Audit::write(['user_id' => null, 'agency_id' => $aid, 'action' => 'payroll_document.notice_emailed', 'entity_type' => 'payroll_document',
                    'entity_id' => $doc->id, 'payload' => json_encode(['summary' => 'Could not email ' . $who . ' the ' . ($kind === 'updated' ? 'updated ' : '')
                        . 'payslip notice for ' . $period . ' — the mail server refused it.', 'to' => $recipient, 'document_id' => $doc->id,
                        'staff' => $who, 'period' => $period, 'delivered' => false, 'error' => mb_substr($e->getMessage(), 0, 200), 'system' => 'kt:payslip-notices'])]);
            }
            return 'failed';
        }

        $verdict = DB::table('email_logs')->whereRaw('LOWER(to_email) = ?', [mb_strtolower($recipient)])
            ->where('created_at', '>=', $sentAt->copy()->subMinutes(2))->orderByDesc('id')->first(['id', 'status']);
        $held = $verdict && strtolower((string) $verdict->status) === 'suppressed';
        $record($held ? 'held' : 'sent', $held ? 'held back by a mail switch' : null);

        if (! $test) {
            Audit::write(['user_id' => null, 'agency_id' => $aid, 'action' => 'payroll_document.notice_emailed', 'entity_type' => 'payroll_document',
                'entity_id' => $doc->id, 'payload' => json_encode([
                    'summary' => ($held ? 'Tried to email ' : 'Emailed ') . $who . ' the ' . ($kind === 'updated' ? 'updated ' : '') . 'payslip notice for ' . $period
                        . ' to ' . $recipient . ($pdf !== null ? ', payslip PDF attached' : ', no PDF attached')
                        . ($bcc ? ' (hidden copy to ' . implode(', ', $bcc) . ')' : '') . ($held ? ' — held back by a mail switch' : '')
                        . ' — sent automatically by the scheduled job kt:payslip-notices.',
                    'to' => $recipient, 'document_id' => $doc->id, 'staff' => $who, 'period' => $period, 'kind' => $kind,
                    'attachment' => $pdf !== null, 'bcc' => $bcc ?: null, 'delivered' => ! $held, 'system' => 'kt:payslip-notices',
                    'email_log_id' => $verdict->id ?? null,
                ])]);
        }
        return $held ? 'held' : 'sent';
    }

    /**
     * WHO, NOT WHAT, FOR EVERY PAYSLIP EMAIL (2026-11-27, Anthony: "all payslips should follow iLearn's pass-protect rule for
     * any agency going forward"). The attached PDF is locked with the person's payslip password (PayslipLock, the rule iLearn
     * uses), so the email no longer carries what the lock protects: no amounts in the body, the subject, the preheader or a
     * hero. When the PDF is attached the email gives the password FORMAT (iLearn's own hint for iLearn's file), never the
     * password. Payroll engine payslips (P2) were already sent this way; every payslip now is. This replaces "the pay in the
     * body" (2026-11-03: gross, deductions, net and year to date in the email, as iLearn's email does). [subject, html], in the
     * approved invoice-email look; also the resend (PayrollDocumentController::email), so there is one payslip email.
     */
    public static function render(int $aid, object $doc, string $first, string $agencyName, string $kind, bool $attached, bool $wantPdf, string $note = ''): array
    {
        $engine = ($doc->source ?? '') === 'engine';
        $period = self::period($doc);
        $payDay = null;
        if ($engine) {
            $b = json_decode((string) ($doc->breakdown ?? ''), true) ?: [];
            if (! empty($b['run']['pay_date'])) { try { $payDay = Carbon::parse($b['run']['pay_date']); } catch (Throwable $e) {} }
        } else {
            foreach (['paid_at', 'issued_at'] as $f) {
                if (! empty($doc->$f)) { try { $payDay = Carbon::parse($doc->$f); break; } catch (Throwable $e) {} }
            }
        }
        if ($engine) {
            [$link, $where, $button] = ['https://app.kiddietrac.com/dashboard.html#my-hr', 'My HR &rarr; My payslips', 'Open my payslip'];
        } else {
            $role = Payroll::primaryRole((int) $doc->user_id);
            [$link, $where, $button] = ['https://app.kiddietrac.com/dashboard.html#' . ($role === 'home_visitor' ? 'my-pay' : 'my-hours'), 'My pay &rarr; Payslips', 'View in the app'];
        }

        $lead = ($kind === 'updated' ? 'Your payslip for <strong>' . e($period) . '</strong> from <strong>' . e($agencyName) . '</strong> has been updated'
                : 'Your payslip for <strong>' . e($period) . '</strong> from <strong>' . e($agencyName) . '</strong> is ready')
            . ($payDay ? ' (' . ($engine || ! empty($doc->paid_at) ? 'pay date ' : 'issued ') . e($payDay->format('M j, Y')) . ')' : '') . '.'
            . ($attached ? ' It is attached as a password-protected PDF.' : '');

        $body = '<p style="margin:0 0 8px;">Hi ' . ($first !== '' ? e($first) : 'there') . ',</p>'
            . '<p style="margin:0;">' . $lead . '</p>'
            . ($note !== '' ? '<p style="margin:12px 0 0;">' . nl2br(e($note)) . '</p>' : '')
            . '<p style="margin:12px 0 14px;">For your privacy the amounts are not in this email. Open the PDF, or sign in, to see your pay, every deduction and your year to date.</p>'
            . InvoiceMail::payButton($aid, $link, $button);
        if ($attached) {
            $hint = trim((string) ($doc->pdf_password_hint ?? '')) ?: Payroll\PayslipLock::forDoc($doc)['hint'];
            $body .= EmailTemplate::calloutBox('<strong>The PDF is password protected.</strong> ' . e($hint)
                . ' For security, your actual password is not included in this email.', 'info');
        }
        if ($wantPdf && ! $attached) {
            $body .= '<p style="margin:12px 0 0;font-size:13px;color:#92400E;">The PDF could not be attached &mdash; open the app to view or download it.</p>';
        }
        // The footer says this inbox is not read, so "reply to the office" sent questions nowhere.
        $body .= '<p class="kt-muted" style="font-size:12.5px;color:#64748B;margin:16px 0 0;">Your payslips are always in the app under <strong>' . $where
            . '</strong>. Questions about your pay? Contact the office.</p>';

        $html = EmailTemplate::wrap($aid, $body, [
            'eyebrow' => 'PAYSLIP',
            'title' => $kind === 'updated' ? 'Payslip updated' : 'Payslip ready',
            'subtitle' => $agencyName,
            'preheader' => ($kind === 'updated' ? 'An updated payslip' : 'Your payslip') . ' for ' . $period . ' is ready.',
        ]);
        $subject = ($kind === 'updated' ? 'Updated payslip' : 'Your payslip') . ' for ' . $period . ' — ' . $agencyName;

        return [$subject, $html];
    }
}
