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
        if ($cfg['email_attach_pdf']) {
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

    /** [subject, html] — the approved invoice-email look (KiddieTrac billing header; white-label agencies their own). */
    /* THE PAY IN THE BODY (2026-11-03, Anthony: "the payslip emails that are sent out do not have the details of the pay
       in the body of the email such as amounts - if you compare to the ilearn payslip email use the similiar wordings").
       It showed the period, gross and net only. Now the summary iLearn's own payslip email builds (Livewire/Payroll
       sendPayslip): Gross, Vacation, CPP, EI, Tax, Other, Net pay, in its words, plus the hours and rate, any pay lines and
       the year to date. Only what this payslip actually carries - an empty or zero row is left out, nothing is invented.
       Also the resend (PayrollDocumentController::email) for payslips, so there is one payslip email. $note: the office's
       message from the resend dialog. */
    public static function render(int $aid, object $doc, string $first, string $agencyName, string $kind, bool $attached, bool $wantPdf, string $note = ''): array
    {
        $money = fn ($v) => '$' . number_format((float) $v, 2);
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $has = fn ($v) => $v !== null && abs((float) $v) > 0.004;
        $period = self::period($doc);
        $payDay = null;
        foreach (['paid_at', 'issued_at'] as $f) {
            if (! empty($doc->$f)) { try { $payDay = Carbon::parse($doc->$f); break; } catch (Throwable $e) {} }
        }

        // iLearn: Providers and Contractors are independent - no CPP / EI / tax withheld, and Contractors accrue no vacation.
        $roleKey = strtolower(trim((string) ($doc->role_label ?? '')));
        $independent = in_array($roleKey, ['provider', 'contractor'], true);
        $gross = (float) ($doc->gross ?? 0);
        $vacation = $roleKey === 'contractor' ? 0.0 : (float) ($doc->vacation_pay ?? 0);
        $ded = [['CPP', $doc->cpp ?? null], ['EI', $doc->ei ?? null], ['Tax', $doc->income_tax ?? null], ['Other', $doc->other_deductions ?? null]];
        $dedTotal = 0.0;
        foreach ($ded as [, $v]) $dedTotal += (float) $v;
        $net = $doc->net !== null ? (float) $doc->net : $gross + $vacation - $dedTotal;

        $rows = [['Pay period', e($period)]];
        if ($payDay) $rows[] = [$doc->paid_at ? 'Pay date' : 'Issued', e($payDay->format('M j, Y'))];
        $units = (float) ($doc->units ?? 0);
        $unit = strtolower((string) ($doc->unit_label ?? ''));
        if ($units > 0.004 && in_array($unit, ['hours', 'visits'], true)) {
            $what = $unit === 'hours' ? ($units == 1.0 ? 'hour' : 'hours') : ($units == 1.0 ? 'visit' : 'visits');
            $rate = (float) ($doc->rate ?? 0);
            $rows[] = [$unit === 'hours' ? 'Hours' : 'Visits', e($num($units) . ' ' . $what . ($rate > 0.004 ? ' at ' . $money($rate) . ($unit === 'hours' ? '/hour' : '/visit') : ''))];
        }
        if ($has($doc->ot_amount ?? null)) {
            $rows[] = ['Overtime', e(($has($doc->ot_hours ?? null) ? $num($doc->ot_hours) . ' hours' . ($doc->ot_mult ? ' at ' . $num($doc->ot_mult) . '×' : '') . ' · ' : '') . $money($doc->ot_amount))];
        }
        // Pay lines (wage enhancement, workforce compensation, a reward bonus...), itemised as iLearn itemises its misc lines.
        $lines = isset($doc->lines) && $doc->lines ? (json_decode((string) $doc->lines, true) ?: []) : [];
        foreach ((array) $lines as $l) {
            if (! is_array($l) || trim((string) ($l['label'] ?? '')) === '' || ! $has($l['amount'] ?? null)) continue;
            $rows[] = [(string) $l['label'], e($money($l['amount']))];
        }
        if ($gross > 0.004) $rows[] = ['Gross', e($money($gross))];
        if ($vacation > 0.004) $rows[] = ['Vacation', e($money($vacation))];
        foreach ($ded as [$label, $v]) {
            if ($has($v)) $rows[] = [$label, '&minus;' . e($money($v))];
        }
        $rows[] = ['Net pay', '<strong>' . e($money($net)) . '</strong>'];

        // Year to date: this person's payslips from this agency, same calendar year, up to and including this one.
        $ytd = [];
        $yearOf = (string) substr((string) ($doc->period_end ?: $doc->period_start), 0, 4);
        if (! empty($doc->user_id) && preg_match('/^\d{4}$/', $yearOf)) {
            try {
                $q = \Illuminate\Support\Facades\DB::table('payroll_documents')->where('agency_id', $aid)->where('user_id', $doc->user_id)
                    ->where('kind', 'payslip')->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'void'))
                    ->whereRaw('LEFT(COALESCE(period_end, period_start), 4) = ?', [$yearOf])
                    ->whereRaw('COALESCE(period_end, period_start) <= ?', [(string) ($doc->period_end ?: $doc->period_start)])
                    ->selectRaw('SUM(gross) g, SUM(COALESCE(net, gross)) n, COUNT(*) c')->first();
                if ($q && (int) $q->c > 0) {
                    if ((float) $q->g > 0.004) $ytd[] = ['Gross, year to date', e($money($q->g))];
                    if ((float) $q->n > 0.004) $ytd[] = ['Net pay, year to date', e($money($q->n))];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        $role = Payroll::primaryRole((int) $doc->user_id);
        $link = 'https://app.kiddietrac.com/dashboard.html#' . ($role === 'home_visitor' ? 'my-pay' : 'my-hours');

        $lead = $kind === 'updated'
            ? 'Your payslip for <strong>' . e($period) . '</strong> from <strong>' . e($agencyName) . '</strong> has been updated.'
            : 'Your payslip for <strong>' . e($period) . '</strong> from <strong>' . e($agencyName) . '</strong> is ready.';
        if ($attached) $lead .= ' It is attached as a PDF.';

        $body = '<p style="margin:0 0 8px;">Hi ' . ($first !== '' ? e($first) : 'there') . ',</p>'
            . '<p style="margin:0;">' . $lead . '</p>'
            . ($note !== '' ? '<p style="margin:12px 0 0;">' . nl2br(e($note)) . '</p>' : '')
            . '<p style="margin:16px 0 0;font-size:13px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;" class="kt-muted">Your pay</p>'
            . InvoiceMail::details($rows);
        if ($independent && $dedTotal < 0.005) {
            $body .= '<p class="kt-muted" style="margin:-8px 0 14px;font-size:12.5px;font-style:italic;color:#64748B;">Statutory deductions (CPP / EI / income tax) &mdash; '
                . '<strong>not applicable</strong> for independent ' . e(ucfirst($roleKey)) . 's.</p>';
        }
        if ($ytd) $body .= InvoiceMail::details($ytd);
        $body .= '<p style="margin:0 0 14px;">Thank you for everything you do as part of the ' . e($agencyName) . ' team. The care you give our children means so much to the families we serve &mdash; we truly appreciate having you with us.</p>'
            . InvoiceMail::payButton($aid, $link, 'View in the app');
        if ($attached && trim((string) $doc->pdf_password_hint) !== '') {
            $body .= EmailTemplate::calloutBox('<strong>The PDF is password protected.</strong> ' . e((string) $doc->pdf_password_hint)
                . ' For security, your actual password is not included in this email.', 'info');
        }
        if ($wantPdf && ! $attached) {
            $body .= '<p style="margin:12px 0 0;font-size:13px;color:#92400E;">The PDF could not be attached &mdash; open My pay in the app to view or download it.</p>';
        }
        // The footer says this inbox is not read, so "reply to the office" sent questions nowhere.
        $body .= '<p class="kt-muted" style="font-size:12.5px;color:#64748B;margin:16px 0 0;">Your payslips, your pay ledger and year-to-date totals are always in the app under '
            . '<strong>My pay &rarr; Payslips</strong>. Questions about your pay? Contact the office.</p>';

        $hero = ['chip' => $kind === 'updated' ? 'UPDATED PAYSLIP' : 'PAYSLIP READY', 'chip_col' => '#4ADE80', 'doc_title' => 'Payslip',
            'number' => $period, 'amount' => $money($net), 'amount_sub' => 'net pay' . ($payDay ? ' · ' . ($doc->paid_at ? 'paid ' : 'issued ') . $payDay->format('M j') : ''),
            'centre_id' => null, 'label' => 'PAYROLL'];
        $html = EmailTemplate::wrap($aid, $body, [
            'eyebrow' => 'PAYSLIP',
            'title' => 'Payslip ' . $period,
            'subtitle' => $agencyName,
            'preheader' => ($kind === 'updated' ? 'Updated payslip' : 'Your payslip') . ' for ' . $period . ' — net pay ' . $money($net) . '.',
            'invoice_hero' => $hero,
        ]);
        $subject = ($kind === 'updated' ? 'Updated payslip' : 'Your payslip') . ' for ' . $period . ' — ' . $agencyName;
        return [$subject, $html];
    }
}
