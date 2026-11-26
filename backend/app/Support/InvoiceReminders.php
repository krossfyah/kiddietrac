<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * INVOICE REMINDERS AND RESENDS (2026-10-31, Anthony: "the resend invoices should allow to cc admins/director as
 * default in the popup or can untick. when invoice reminders are sent cc admin/directors for that agency and wire this
 * up in settings where you can setup how often, who gets notified and how many times. When invoices are overdue the
 * emails should be sent everyday until paid").
 *
 * One place for: the per-agency schedule (agencies.settings.billing_reminders), who a reminder goes to (each billing
 * guardian, with THEIR share when the family splits the bill), who gets a copy (agency admins, the centre's directors,
 * chosen staff - always a BLIND copy, so a family is never handed the office's address list), the email itself
 * (EmailTemplate::wrap - branded, white-label aware, dark-mode palette; the text part is added by AddPlainTextPart),
 * the once-a-day log that stops repeats, and the "who is due now" preview.
 *
 * Two gates still stand in front of any automatic send: config('billing.reminders_enabled') (platform, OFF) and the
 * agency's own switches. A resend is a person pressing Send, so only the agency mail switch applies to it.
 */
final class InvoiceReminders
{
    public const DEFAULTS = [
        'invoice_enabled'     => false,     // remind before the due date
        'invoice_days_before' => '7,3',     // which days before
        'due_today'           => true,      // a reminder on the due date itself (when reminders are on)
        'overdue_enabled'     => false,     // remind after the due date
        'overdue_mode'        => 'daily',   // daily | every | weekly | days (the old fixed list)
        'overdue_every'       => 3,         // for 'every': every N days
        'overdue_days_after'  => '1,7,14',  // for 'days'
        'overdue_max'         => 0,         // stop after N overdue reminders; 0 = until paid
        'send_time'           => '09:00',   // agency-local
        'channel_email'       => true,
        /* STAFF COPIES ON/OFF (2026-11-26, Anthony: "wire up a setting to allow invoice parent billing reminders to
           include directors/admins and staff (can turn this on or off)"). The master switch: off = no staff copy on any
           automatic reminder, whatever is ticked below. Off for an agency that never set it (never default a recipient);
           iLearn's stored settings were given cc_enabled = true the same day so its copies carried on unchanged. */
        'cc_enabled'          => false,
        'cc_issued'           => false,     // also copy the invoice email sent on its own date (instalments)
        'cc_admins'           => true,      // blind copy to the agency admins
        'cc_directors'        => true,      // blind copy to the invoice's centre directors
        'cc_staff'            => [],        // + chosen staff (user ids in this agency)
        'cc_admin'            => false,     // + the agency's billing contact address (older setting)
        'custom_message'      => '',
    ];

    public const MODES = ['daily', 'every', 'weekly', 'days'];

    public static function cfg(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $r = (isset($s['billing_reminders']) && is_array($s['billing_reminders'])) ? $s['billing_reminders'] : [];
        $c = array_merge(self::DEFAULTS, $r);
        if (! in_array($c['overdue_mode'], self::MODES, true)) $c['overdue_mode'] = 'daily';
        $c['overdue_every'] = max(1, min(60, (int) $c['overdue_every']));
        $c['overdue_max'] = max(0, min(365, (int) $c['overdue_max']));
        $c['cc_staff'] = array_values(array_unique(array_map('intval', is_array($c['cc_staff']) ? $c['cc_staff'] : [])));
        return $c;
    }

    public static function days($csv): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', array_map('trim', explode(',', (string) $csv))),
            fn ($n) => $n >= 0 && $n <= 365
        )));
    }

    /* ── WHO GETS A COPY ─────────────────────────────────────────────────────────────────────────────────────── */

    /**
     * The staff who may be copied on this agency's invoice email: agency admins, the centre's directors (every
     * director in the agency when the centre is unknown) and any staff the agency chose. Only people who hold an
     * active role in THIS agency - never anybody else, whatever the client sends.
     *
     * @return list<array{user_id:int,name:string,email:string,role:string,default:bool}>
     */
    public static function staffChoices(int $agencyId, ?int $centreId, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::cfg($agencyId);
        $rows = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.agency_id', $agencyId)->where('r.active', 1)
            ->whereIn('r.role', ['agency_admin', 'centre_director', 'admin', 'director', 'educator', 'staff', 'assistant_director'])
            ->whereNull('u.deleted_at')->whereNotNull('u.email')
            ->whereNotIn('u.status', ['invited', 'not_invited', 'deactivated', 'suspended'])
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'r.role', 'r.centre_id']);
        $out = [];
        foreach ($rows as $r) {
            $role = (string) $r->role;
            $isAdmin = in_array($role, ['agency_admin', 'admin'], true);
            $isDir = in_array($role, ['centre_director', 'director', 'assistant_director'], true)
                && ($centreId === null || $r->centre_id === null || (int) $r->centre_id === $centreId);
            $chosen = in_array((int) $r->id, $cfg['cc_staff'], true);
            if (! $isAdmin && ! $isDir && ! $chosen) continue;
            // Service accounts (the iLearn integration login and the like) are not people to copy.
            if (preg_match('/^(integration|noreply|no-reply|system|api)[+@]/i', (string) $r->email)) continue;
            $id = (int) $r->id;
            $label = $isAdmin ? 'Agency admin' : ($isDir ? 'Director' : 'Staff');
            $def = ($isAdmin && $cfg['cc_admins']) || ($isDir && $cfg['cc_directors']) || $chosen;
            if (isset($out[$id])) {
                // Strongest label, and default if any of their roles says so.
                if ($isAdmin) $out[$id]['role'] = 'Agency admin';
                $out[$id]['default'] = $out[$id]['default'] || $def;
                continue;
            }
            $out[$id] = ['user_id' => $id, 'name' => trim($r->first_name . ' ' . $r->last_name) ?: $r->email,
                'email' => mb_strtolower(trim((string) $r->email)), 'role' => $label, 'default' => $def];
        }
        $list = array_values($out);
        usort($list, fn ($a, $b) => [$a['role'] !== 'Agency admin', $a['name']] <=> [$b['role'] !== 'Agency admin', $b['name']]);
        return $list;
    }

    /** The addresses the schedule copies (blind) on an automatic reminder. */
    public static function scheduledCopies(int $agencyId, ?int $centreId, array $cfg, ?int $familyId = null): array
    {
        if (empty($cfg['cc_enabled'])) return [];   // the agency's staff-copies switch (2026-11-26)
        $emails = [];
        foreach (self::staffChoices($agencyId, $centreId, $cfg) as $s) {
            if ($s['default'] && StaffNotifyPrefs::wants((int) $s['user_id'], 'staff_billing_copies', 'email', $agencyId)) $emails[] = $s['email'];
        }
        if (! empty($cfg['cc_admin'])) {
            $a = DB::table('agencies')->where('id', $agencyId)->first(['contact_email', 'brand_support_email']);
            $x = trim((string) ($a->contact_email ?? $a->brand_support_email ?? ''));
            if ($x !== '' && filter_var($x, FILTER_VALIDATE_EMAIL)) $emails[] = mb_strtolower($x);
        }
        /* A staff member who is also a guardian in THIS family gets the family's own reminder, never a staff copy of it
           as well (memory: staff who are parents). */
        if ($familyId && $emails) {
            $own = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $familyId)
                ->whereNotNull('u.email')->pluck('u.email')->map(fn ($e) => mb_strtolower(trim((string) $e)))->all();
            $pe = DB::table('families')->where('id', $familyId)->value('primary_email');
            if ($pe) $own[] = mb_strtolower(trim((string) $pe));
            $emails = array_values(array_diff($emails, $own));
        }
        return array_values(array_unique($emails));
    }

    /* ── WHO IT GOES TO ──────────────────────────────────────────────────────────────────────────────────────── */

    /**
     * The family's billing guardians for this invoice. A split family: each guardian with their own share and what
     * they still owe (a guardian whose share is paid is marked settled). Otherwise every billing guardian, with the
     * invoice balance.
     *
     * @return list<array{guardian_id:?int,user_id:?int,first_name:string,name:string,email:string,share:?float,balance:float,pct:?float}>
     */
    public static function guardians(object $inv, string $kind): array
    {
        $fid = (int) ($inv->family_id ?? 0);
        if (! $fid) return [];
        $rows = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $fid)->whereNull('u.deleted_at')->whereNotNull('u.email')->where('u.email', '<>', '')
            /* A billing guardian who has not accepted their app invite still gets their invoices, reminders and receipts
               (2026-11-06, Anthony: "Yes, billing mail only" - MailScope::billing lets it past the not-onboarded gate).
               Only a closed account is left out. */
            ->whereNotIn('u.status', ['deactivated', 'suspended'])
            ->get(['g.id', 'g.user_id', 'g.can_receive_billing', 'u.status', 'u.first_name', 'u.last_name', 'u.email']);
        if ($rows->isEmpty()) return [];
        $shares = null;
        if ($kind === 'native') {
            try { $shares = BillingSplit::shares($inv); } catch (Throwable $e) { $shares = null; }
        }
        $out = [];
        if ($shares) {
            $byG = $rows->keyBy('id');
            foreach ($shares as $s) {
                $g = $byG[$s['guardian_id']] ?? null;
                if (! $g) continue;
                $out[] = ['guardian_id' => (int) $g->id, 'user_id' => (int) $g->user_id, 'first_name' => (string) $g->first_name,
                    'name' => trim($g->first_name . ' ' . $g->last_name), 'email' => mb_strtolower(trim((string) $g->email)),
                    'share' => (float) $s['share'], 'balance' => (float) $s['balance'], 'pct' => (float) $s['pct']];
            }
            return $out;
        }
        $billing = $rows->filter(fn ($r) => (int) $r->can_receive_billing === 1);
        $active = $rows->filter(fn ($r) => ! in_array($r->status, ['invited', 'not_invited'], true));
        $use = $billing->isNotEmpty() ? $billing : $rows;
        /* Adding the not-yet-onboarded billing guardians must not take anyone off: when none of the billing guardians
           has signed in yet, the signed-in guardians who were getting these until now (the old fallback) still do. */
        if ($billing->isNotEmpty() && $billing->pluck('id')->intersect($active->pluck('id'))->isEmpty()) $use = $billing->concat($active);
        $bal = (float) ($inv->balance_due ?? 0);
        foreach ($use as $g) {
            $out[] = ['guardian_id' => (int) $g->id, 'user_id' => (int) $g->user_id, 'first_name' => (string) $g->first_name,
                'name' => trim($g->first_name . ' ' . $g->last_name), 'email' => mb_strtolower(trim((string) $g->email)),
                'share' => null, 'balance' => $bal, 'pct' => null];
        }
        // One email per address, even when two guardian rows share one.
        $seen = []; $uniq = [];
        foreach ($out as $o) { if (isset($seen[$o['email']])) continue; $seen[$o['email']] = 1; $uniq[] = $o; }
        return $uniq;
    }

    /** Has this address bounced? (processed_bounces is filled from the mailbox's NDRs.) */
    public static function bounced(string $email): bool
    {
        try {
            return DB::table('processed_bounces')->whereRaw('LOWER(recipient) = ?', [mb_strtolower($email)])->where('matched', 1)->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ── WHICH INVOICES ──────────────────────────────────────────────────────────────────────────────────────── */

    /**
     * Unpaid invoices of an agency due between two dates (inclusive), from both tables, each with its kind and centre.
     *
     * @return list<object>
     */
    public static function invoices(int $agencyId, string $from, string $to): array
    {
        $skip = ['paid', 'void', 'cancelled', 'refunded', 'draft'];
        $native = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereDate('i.due_at', '>=', $from)->whereDate('i.due_at', '<=', $to)
            ->whereNotIn('i.status', $skip)->where('i.balance_due', '>', 0)
            ->get(['i.id', 'i.family_id', 'i.centre_id', 'i.balance_due', 'i.due_at', 'i.total', 'i.status', 'i.issued_at',
                'i.split_snapshot', DB::raw('i.invoice_number as number')]);
        $external = DB::table('external_invoices as x')->leftJoin('families as f', 'f.id', '=', 'x.family_id')
            ->where('x.agency_id', $agencyId)
            ->whereDate('x.due_at', '>=', $from)->whereDate('x.due_at', '<=', $to)
            ->whereNotIn('x.status', $skip)->where('x.balance_due', '>', 0)->whereNotNull('x.family_id')
            ->get(['x.id', 'x.family_id', 'f.centre_id', 'x.balance_due', 'x.due_at', 'x.total', 'x.number']);
        $out = [];
        foreach ($native as $n) { $n->kind = 'native'; $out[] = $n; }
        foreach ($external as $x) { $x->kind = 'external'; $out[] = $x; }
        return $out;
    }

    /* ── THE SCHEDULE ────────────────────────────────────────────────────────────────────────────────────────── */

    /**
     * What the schedule says for this invoice today: null (nothing), or ['before', d] / ['due', 0] / ['overdue', d].
     * $sentOverdue = overdue reminders already sent to this address; $lastOverdue = date of the last one.
     */
    public static function due(array $cfg, string $dueDate, string $today, int $sentOverdue, ?string $lastOverdue): ?array
    {
        $d = (int) Carbon::parse($today)->diffInDays(Carbon::parse($dueDate), false);   // >0 = days until due
        if ($d > 0) {
            return ($cfg['invoice_enabled'] && in_array($d, self::days($cfg['invoice_days_before']), true)) ? ['before', $d] : null;
        }
        if ($d === 0) {
            return ($cfg['invoice_enabled'] || $cfg['overdue_enabled']) && $cfg['due_today'] ? ['due', 0] : null;
        }
        if (! $cfg['overdue_enabled']) return null;
        $late = -$d;
        if ($cfg['overdue_max'] > 0 && $sentOverdue >= $cfg['overdue_max']) return null;
        $since = $lastOverdue ? (int) Carbon::parse($lastOverdue)->diffInDays(Carbon::parse($today)) : null;
        switch ($cfg['overdue_mode']) {
            case 'daily':  $go = $since === null || $since >= 1; break;
            case 'every':  $go = $since === null ? true : $since >= $cfg['overdue_every']; break;
            case 'weekly': $go = $since === null || $since >= 7; break;
            default:       $go = in_array($late, self::days($cfg['overdue_days_after']), true);
        }
        return $go ? ['overdue', $late] : null;
    }

    /** Overdue reminders already sent for an invoice to one address: [count, last date]. */
    public static function history(string $kind, int $invoiceId, string $email): array
    {
        $q = DB::table('invoice_reminder_log')->where('invoice_kind', $kind)->where('invoice_id', $invoiceId)
            ->where('recipient', mb_strtolower($email));
        $o = (clone $q)->where('reason', 'overdue');
        return [(int) $o->count(), $o->max('sent_on'), $q->max('sent_on')];
    }

    /* ── THE EMAIL ───────────────────────────────────────────────────────────────────────────────────────────── */

    public static function payLink(int $agencyId): string
    {
        $base = 'https://app.kiddietrac.com/';
        try {
            if (BrandLock::keyForAgency($agencyId) !== 'kt') {
                $slug = (string) DB::table('agencies')->where('id', $agencyId)->value('slug');
                if ($slug !== '') return $base . '?tenant=' . rawurlencode($slug) . '#billing';
            }
        } catch (Throwable $e) {
        }
        return $base . 'dashboard.html#billing';
    }

    /**
     * Subject + HTML for one guardian.
     * $reason: before | due | overdue | resend. $days: days until due (before) or overdue (overdue).
     * $o: agency_name, number, due_date (Y-m-d), amount (their balance), total (invoice total), share_pct, first_name,
     *     custom_message, interac (email|null), pay_link, test (bool), copies (list of names - shown only in a test).
     */
    public static function email(?int $agencyId, string $reason, int $days, array $o): array
    {
        $ag = (string) ($o['agency_name'] ?? 'your childcare centre');
        $num = trim((string) ($o['number'] ?? ''));
        $amt = '$' . number_format((float) ($o['amount'] ?? 0), 2);
        $due = ! empty($o['due_date']) ? Carbon::parse($o['due_date'])->format('l, F j, Y') : null;
        $dueShort = ! empty($o['due_date']) ? Carbon::parse($o['due_date'])->format('M j') : null;
        $first = trim((string) ($o['first_name'] ?? ''));
        $inv = $num !== '' ? 'invoice ' . $num : 'your invoice';

        [$subject, $eyebrow, $title, $lead, $tone, $accent] = match ($reason) {
            'before' => [
                'Reminder: ' . ($num !== '' ? 'invoice ' . $num : 'your invoice') . ' is due ' . ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days') . ' — ' . $ag,
                'PAYMENT REMINDER', 'Due ' . ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days'),
                'A friendly heads-up: ' . e($inv) . ' is due on <strong>' . e((string) $due) . '</strong>.', 'info', '#1F6080'],
            'due' => [
                'Due today: ' . ($num !== '' ? 'invoice ' . $num : 'your invoice') . ' — ' . $ag,
                'DUE TODAY', 'Your payment is due today',
                'Just a reminder that ' . e($inv) . ' is due <strong>today</strong>.', 'warning', '#B45309'],
            'overdue' => [
                'Overdue (' . $days . ' day' . ($days === 1 ? '' : 's') . '): ' . ($num !== '' ? 'invoice ' . $num : 'your invoice') . ' — ' . $ag,
                'PAYMENT OVERDUE', $days . ' day' . ($days === 1 ? '' : 's') . ' overdue',
                e(ucfirst($inv)) . ' was due on <strong>' . e((string) $due) . '</strong> and still has a balance. '
                    . 'Please pay as soon as you can, or contact us if something is wrong.', 'danger', '#B91C1C'],
            default => [
                'Invoice ' . ($num !== '' ? $num . ' ' : '') . '— ' . $ag,
                'INVOICE', $num !== '' ? 'Invoice ' . $num : 'Your invoice',
                'Here is ' . e($inv) . ($due ? ', due <strong>' . e((string) $due) . '</strong>' : '') . '. The invoice is attached.', 'info', '#1F6080'],
        };
        if (! empty($o['test'])) $subject = '[TEST] ' . $subject;

        $isShare = isset($o['share_pct']) && $o['share_pct'] !== null && (float) $o['share_pct'] < 99.99;
        $rows = [];
        if ($num !== '') $rows[] = ['Invoice', e($num)];
        $rows[] = [$reason === 'resend' ? 'Amount due' : 'Balance due', '<strong>' . e($amt) . '</strong>'];
        if ($isShare) $rows[] = ['Your share', e(rtrim(rtrim(number_format((float) $o['share_pct'], 2), '0'), '.') . '% of $' . number_format((float) ($o['total'] ?? 0), 2))];
        if ($dueShort) $rows[] = ['Due', e((string) Carbon::parse($o['due_date'])->format('M j, Y'))];
        if ($reason === 'overdue') $rows[] = ['Overdue', '<span style="color:#B91C1C;">' . $days . ' day' . ($days === 1 ? '' : 's') . '</span>'];
        $tiles = InvoiceMail::details($rows);

        $body = '<p style="margin:0 0 6px;">Hi ' . ($first !== '' ? e($first) : 'there') . ',</p>'
            . '<p style="margin:0;">' . $lead . '</p>'
            . $tiles
            . ($o['custom_message'] ?? '' ? '<p style="margin:0 0 14px;">' . nl2br(e((string) $o['custom_message'])) . '</p>' : '')
            . InvoiceMail::payButton($agencyId, (string) ($o['pay_link'] ?? 'https://app.kiddietrac.com/dashboard.html#billing'), 'Pay now');
        // Interac and the other ways to pay are listed by the content slot below (InvoiceEmailBlocks).
        $body .= InvoiceMail::slot($agencyId, ['kind' => $reason, 'number' => $num, 'balance' => (float) ($o['amount'] ?? 0),
            'centre_id' => $o['centre_id'] ?? null, 'invoice_id' => $o['invoice_id'] ?? null, 'kind_of_invoice' => $o['kind_of_invoice'] ?? null,
            'family_id' => $o['family_id'] ?? null, 'guardian_id' => $o['guardian_id'] ?? null]);
        if (array_key_exists('attached', $o) && $o['attached'] !== null) {
            $body .= '<p style="margin:14px 0 0;font-size:13.5px;color:#334155;">' . ($o['attached']
                ? 'Your invoice is attached as a PDF.'
                : 'You can open this invoice any time in the parent app under <strong>Billing</strong>.') . '</p>';
        }
        $body .= '<p class="kt-muted" style="font-size:12.5px;color:#64748B;margin:16px 0 0;">Already paid? Thank you — please ignore this reminder; payments can take a day or two to show.</p>';
        if (! empty($o['test']) && ! empty($o['copies'])) {
            $body .= '<p class="kt-muted" style="font-size:12px;color:#64748B;margin:10px 0 0;border-top:1px dashed #CBD5E1;padding-top:10px;">'
                . 'TEST NOTE (not in real emails): a hidden copy would go to ' . e(implode(', ', $o['copies'])) . '.</p>';
        }

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => $eyebrow,
            'title' => $title,
            'subtitle' => $ag,
            'preheader' => strip_tags($lead) . ' Balance ' . $amt . '.',
            // KiddieTrac's invoice header with this agency's logo and centre (2026-10-31).
            'invoice_hero' => InvoiceMail::hero(in_array($reason, ['before', 'due', 'overdue', 'resend'], true) ? ($isShare && $reason === 'resend' ? 'share' : $reason) : 'invoice',
                $num, $amt,
                match ($reason) {
                    'before' => ($days === 1 ? 'due tomorrow' : 'due in ' . $days . ' days') . ($isShare ? ' · your share' : ''),
                    'due' => 'due today' . ($isShare ? ' · your share' : ''),
                    'overdue' => 'overdue since ' . (string) $dueShort . ($isShare ? ' · your share' : ''),
                    default => ($dueShort ? 'due ' . $dueShort : 'balance due') . ($isShare ? ' · your share' : ''),
                },
                isset($o['centre_id']) && $o['centre_id'] ? (int) $o['centre_id'] : null),
        ]);
        return [$subject, $html];
    }

    /* ── THE SCHEDULED PASS ──────────────────────────────────────────────────────────────────────────────────── */

    /**
     * Everything due today for one agency: sends (or, dry, lists) and logs. The caller has already checked the
     * platform switch and the agency's send hour. Returns lines describing each send.
     */
    public static function runAgency(int $agencyId, Carbon $nowLocal, bool $dry): array
    {
        $cfg = self::cfg($agencyId);
        if ((! $cfg['invoice_enabled'] && ! $cfg['overdue_enabled']) || ! $cfg['channel_email']) return [];
        if (! Suppression::agencyNotificationsEnabled($agencyId)) return [];
        $today = $nowLocal->toDateString();
        $maxBefore = max([0] + self::days($cfg['invoice_days_before']));
        $from = $cfg['overdue_enabled'] ? $nowLocal->copy()->subDays(366)->toDateString() : $today;
        $to = $nowLocal->copy()->addDays($cfg['invoice_enabled'] ? $maxBefore : 0)->toDateString();
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['name']);
        $pay = ParentBilling::payInfo($agencyId);
        $interac = $pay['interac']['email'] ?? null;
        $link = self::payLink($agencyId);
        $am = \App\Services\AgencyMailer::forAgency($agencyId);
        $lines = [];
        $pdfs = [];   // one PDF per invoice (per guardian copy) per run
        // First: invoices whose own date has come and that nobody has been sent yet (logged, so no reminder goes the same day).
        if ($cfg['invoice_enabled']) {
            $lines = self::issuedPass($agencyId, $nowLocal, $dry, $agency, $interac, $link, $am, $cfg);
        }
        foreach (self::invoices($agencyId, $from, $to) as $inv) {
            $copies = self::scheduledCopies($agencyId, $inv->centre_id ? (int) $inv->centre_id : null, $cfg, $inv->family_id ? (int) $inv->family_id : null);
            /* PAID IN THE PARTNER, NOT YET LINKED (2026-11-03). The family has a payment in the partner system,
               received since this invoice was issued, that is on none of our invoices (PartnerPaymentHold). It may be
               this invoice's money - Kicks-Ward got "Overdue" four days running for one that was. Hold the reminder
               and tell the billing staff instead; a person links it, and reminders resume if it was something else. */
            $held = $inv->kind === 'native' ? PartnerPaymentHold::heldBy($agencyId, $inv) : [];
            $heldToday = false;
            // The family says they paid it by e-Transfer and a person has not confirmed it yet (2026-11-11): no reminder meanwhile.
            if (EtransferPending::has($agencyId, $inv->kind === 'native' ? 'inv' : 'ext', (int) $inv->id)) {
                $lines[] = ($dry ? '[dry] ' : '') . "skipped {$inv->kind}#{$inv->id}: e-Transfer waiting to be confirmed";
                continue;
            }
            foreach (self::guardians($inv, $inv->kind) as $g) {
                if ($g['balance'] <= 0.004) continue;                       // their share is paid
                if (self::bounced($g['email'])) continue;
                [$n, $lastOver, $lastAny] = self::history($inv->kind, (int) $inv->id, $g['email']);
                if ($lastAny === $today) continue;                            // one a day per invoice per address
                $step = self::due($cfg, substr((string) $inv->due_at, 0, 10), $today, $n, $lastOver);
                if (! $step) continue;
                [$reason, $days] = $step;
                if ($held) {
                    $heldToday = true;
                    $lines[] = ($dry ? '[dry] ' : '') . "held {$reason} d{$days} native#{$inv->id} → {$g['email']} (partner payment "
                        . implode(', ', array_map(fn ($h) => '$' . number_format($h['amount'], 2) . ' ' . $h['date'], $held)) . ' not linked)';
                    if (! $dry) self::log($agencyId, 'native', (int) $inv->id, $g['email'], $g['guardian_id'], 'held', $days, $today, [], null);
                    continue;
                }
                $number = $inv->number ?? null;
                $copyOf = null;
                if ($inv->kind === 'native' && $g['pct'] !== null && class_exists(SplitInvoiceCopy::class)) {
                    try { $number = SplitInvoiceCopy::numberFor($inv, (int) $g['guardian_id']) ?: $number; $copyOf = (int) $g['guardian_id']; } catch (Throwable $e) {}
                }
                // The invoice PDF goes with every reminder (2026-10-31); a failure sends without it and says where to find it.
                $pk = $inv->kind . ':' . $inv->id . ':' . ($copyOf ?: 0);
                if (! $dry && ! array_key_exists($pk, $pdfs)) {
                    $full = $inv->kind === 'native' ? DB::table('invoices')->where('id', $inv->id)->first() : DB::table('external_invoices')->where('id', $inv->id)->first();
                    $pdfs[$pk] = $full ? PaidMail::invoicePdf($full, $inv->kind, $copyOf) : null;
                    if ($pdfs[$pk] === null) Log::warning('invoice reminder: PDF not attached', ['invoice' => $pk]);
                }
                $pdf = $pdfs[$pk] ?? null;
                [$subject, $html] = self::email($agencyId, $reason, $days, [
                    'agency_name' => $agency->name ?? null, 'number' => $number, 'due_date' => substr((string) $inv->due_at, 0, 10),
                    'amount' => $g['balance'], 'total' => $inv->total ?? null, 'share_pct' => $g['pct'], 'first_name' => $g['first_name'],
                    'custom_message' => $cfg['custom_message'], 'interac' => $interac, 'pay_link' => $link,
                    'centre_id' => $inv->centre_id ?? null, 'invoice_id' => (int) $inv->id, 'kind_of_invoice' => $inv->kind,
                    'family_id' => $inv->family_id ?? null, 'guardian_id' => $g['guardian_id'] ?? null,
                    'attached' => $dry ? null : ($pdf !== null),
                ]);
                $line = "{$reason} d{$days} {$inv->kind}#{$inv->id} → {$g['email']}" . ($copies ? ' (bcc ' . count($copies) . ')' : '') . ($pdf ? ' +pdf' : '');
                if ($dry) { $lines[] = '[dry] ' . $line; continue; }
                try {
                    $am->html($html, function ($m) use ($g, $subject, $copies, $pdf, $number) {
                        MailScope::billing($m);
                        $m->to($g['email'], $g['name'] ?: null)->subject($subject);
                        foreach ($copies as $c) { if ($c !== $g['email']) $m->bcc($c); }
                        if ($pdf) $m->attachData($pdf, 'Invoice ' . PaidMail::fileSafe((string) $number) . '.pdf', ['mime' => 'application/pdf']);
                    });
                    self::log($agencyId, $inv->kind, (int) $inv->id, $g['email'], $g['guardian_id'], $reason, $days, $today, $copies, null);
                    $lines[] = $line;
                } catch (Throwable $e) {
                    Log::warning('invoice reminder failed: ' . $e->getMessage(), ['invoice' => $inv->kind . '#' . $inv->id]);
                }
            }
            if ($heldToday && ! $dry) {
                foreach ($held as $h) {
                    // Once when first held, then at most weekly while it keeps holding.
                    PartnerPaymentHold::alert($agencyId, (int) $inv->family_id, $h, (string) $inv->number, 7);
                }
            }
        }
        return $lines;
    }

    /* ── A NEW INVOICE, ON ITS OWN DATE (2026-11-06) ─────────────────────────────────────────────────────────────

       Anthony, after a billing sweep: "Email each on its own date". Instalments raised ahead - a payment schedule, or a
       partner's plan mirrored in, all marked sent the day they were created - were owed but never sent: only the first
       went out as an invoice, and a family first saw the rest as "Reminder: due in 3 days".

       Each open invoice now goes out once, as the ordinary "here is your invoice" letter (InvoiceMail::issued) with its
       PDF, on the earlier of the first day of its period and 7 days before it is due, to each billing guardian who has
       not had it: an issue, resend or batch email naming the invoice, or any reminder for it, counts as having had it.
       Only invoices not yet due - one already late has been reminded about instead. Logged as reason 'issued'. */
    private static function issuedPass(int $agencyId, Carbon $nowLocal, bool $dry, ?object $agency, ?string $interac, string $link, $am, array $cfg = []): array
    {
        $today = $nowLocal->toDateString();
        $week = $nowLocal->copy()->addDays(7)->toDateString();
        $lines = [];
        $rows = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->where('c.agency_id', $agencyId)->whereNull('c.deleted_at')
            ->whereIn('i.status', ['sent', 'partial', 'overdue'])->where('i.balance_due', '>', 0)
            ->whereNotNull('i.due_at')->whereDate('i.due_at', '>=', $today)
            ->where(fn ($w) => $w->whereNull('i.issued_at')->orWhereDate('i.issued_at', '<=', $today))
            ->where(fn ($w) => $w->whereDate('i.period_start', '<=', $today)->orWhereDate('i.due_at', '<=', $week))
            ->orderBy('i.due_at')->get(['i.*']);
        foreach ($rows as $inv) {
            $base = (string) ($inv->invoice_number ?? '');
            if ($base === '') continue;
            if (PartnerPaymentHold::heldBy($agencyId, $inv)) continue;   // may already be paid in the partner; the reminder loop tells staff
            $pdfs = [];
            // Staff copies of the invoice email itself, when the agency turned that on (2026-11-26): once per invoice.
            $issuedCopies = ! empty($cfg['cc_issued'])
                ? self::scheduledCopies($agencyId, $inv->centre_id ? (int) $inv->centre_id : null, $cfg, $inv->family_id ? (int) $inv->family_id : null) : [];
            foreach (self::guardians($inv, 'native') as $g) {
                if ($g['balance'] <= 0.004) continue;
                if (self::bounced($g['email'])) continue;
                $number = $base; $copyOf = null;
                if ($g['pct'] !== null && class_exists(SplitInvoiceCopy::class)) {
                    try { $number = SplitInvoiceCopy::numberFor($inv, (int) $g['guardian_id']) ?: $base; $copyOf = (int) $g['guardian_id']; } catch (Throwable $e) {}
                }
                if (DB::table('invoice_reminder_log')->where('invoice_kind', 'native')->where('invoice_id', $inv->id)
                    ->where('recipient', $g['email'])->exists()) continue;
                $had = DB::table('email_logs')->whereRaw('LOWER(to_email) LIKE ?', ['%' . $g['email'] . '%'])
                    ->where(fn ($w) => $w->where('subject', 'like', '%' . $base . '%')->orWhere('subject', 'like', '%' . $number . '%'))
                    ->whereNotIn('status', ['suppressed', 'failed'])->exists();
                if ($had) continue;
                $daysToDue = (int) Carbon::parse($today)->diffInDays(Carbon::parse(substr((string) $inv->due_at, 0, 10)), false);
                $copies = $issuedCopies; $issuedCopies = [];
                $line = "issued d{$daysToDue} native#{$inv->id} {$number} → {$g['email']}" . ($copies ? ' (bcc ' . count($copies) . ')' : '');
                if ($dry) { $lines[] = '[dry] ' . $line; continue; }
                $pk = $copyOf ?: 0;
                if (! array_key_exists($pk, $pdfs)) $pdfs[$pk] = PaidMail::invoicePdf($inv, 'native', $copyOf);
                $pdf = $pdfs[$pk];
                $share = $g['pct'] !== null && (float) $g['pct'] < 99.99;
                [$subject, $html] = InvoiceMail::issued($agencyId, [
                    'number' => $number, 'total' => $share ? (float) ($g['share'] ?? $g['balance']) : (float) $inv->total,
                    'balance' => (float) $g['balance'], 'due' => substr((string) $inv->due_at, 0, 10),
                    'first_name' => $g['first_name'] ?: null, 'agency_name' => $agency->name ?? null,
                    'centre_id' => $inv->centre_id ? (int) $inv->centre_id : null, 'pay_link' => $link, 'interac' => $interac,
                    'kind' => $share ? 'share' : 'invoice', 'attached' => $pdf !== null,
                    'invoice_id' => (int) $inv->id, 'kind_of_invoice' => 'native', 'family_id' => $inv->family_id ?? null,
                    'guardian_id' => $g['guardian_id'] ?? null,
                ]);
                try {
                    $am->html($html, function ($m) use ($g, $subject, $number, $pdf, $copies) {
                        MailScope::billing($m);
                        $m->to($g['email'], $g['name'] ?: null)->subject($subject);
                        foreach ($copies as $c) { if ($c !== $g['email']) $m->bcc($c); }
                        if ($pdf) $m->attachData($pdf, 'Invoice ' . PaidMail::fileSafe($number) . '.pdf', ['mime' => 'application/pdf']);
                    });
                    self::log($agencyId, 'native', (int) $inv->id, $g['email'], $g['guardian_id'], 'issued', $daysToDue, $today, $copies, null);
                    $lines[] = $line . ($pdf ? ' +pdf' : '');
                } catch (Throwable $e) {
                    Log::warning('invoice issued email failed: ' . $e->getMessage(), ['invoice' => $inv->id]);
                }
            }
        }
        return $lines;
    }

    public static function log(int $agencyId, string $kind, int $invoiceId, string $email, ?int $guardianId, string $reason, int $days, string $sentOn, array $copies, ?int $byUser): void
    {
        try {
            DB::table('invoice_reminder_log')->insertOrIgnore([
                'agency_id' => $agencyId, 'invoice_kind' => $kind, 'invoice_id' => $invoiceId, 'recipient' => mb_strtolower($email),
                'guardian_id' => $guardianId, 'reason' => $reason, 'days' => $days, 'sent_on' => $sentOn,
                'copies' => $copies ? json_encode(array_values($copies)) : null, 'sent_by' => $byUser, 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /* ── WHO IS DUE NOW ──────────────────────────────────────────────────────────────────────────────────────── */

    /**
     * Read-only: unpaid invoices due in the next $ahead days or overdue, each guardian's amount, the last reminder,
     * and what today's pass WOULD send under the agency's settings (as if reminders were on).
     */
    public static function preview(int $agencyId, int $ahead = 7, bool $asIfOn = true): array
    {
        $cfg = self::cfg($agencyId);
        if ($asIfOn) { $cfg['invoice_enabled'] = true; $cfg['overdue_enabled'] = true; }
        $tz = DB::table('agencies')->where('id', $agencyId)->value('timezone') ?: 'America/Toronto';
        try { $now = Carbon::now($tz); } catch (Throwable $e) { $now = Carbon::now('America/Toronto'); }
        $today = $now->toDateString();
        $rows = [];
        foreach (self::invoices($agencyId, $now->copy()->subDays(366)->toDateString(), $now->copy()->addDays($ahead)->toDateString()) as $inv) {
            $fam = DB::table('families')->where('id', $inv->family_id)->value('family_name');
            $copies = self::scheduledCopies($agencyId, $inv->centre_id ? (int) $inv->centre_id : null, $cfg, $inv->family_id ? (int) $inv->family_id : null);
            $dueDate = substr((string) $inv->due_at, 0, 10);
            $gs = self::guardians($inv, $inv->kind);
            $held = $inv->kind === 'native' ? PartnerPaymentHold::heldBy($agencyId, $inv) : [];
            $who = [];
            foreach ($gs as $g) {
                [$n, $lastOver, $lastAny] = self::history($inv->kind, (int) $inv->id, $g['email']);
                $step = ! $held && $g['balance'] > 0.004 && ! self::bounced($g['email']) && $lastAny !== $today
                    ? self::due($cfg, $dueDate, $today, $n, $lastOver) : null;
                $who[] = ['name' => $g['name'], 'email' => $g['email'], 'balance' => round($g['balance'], 2), 'pct' => $g['pct'],
                    'last_reminder' => $lastAny, 'reminders_sent' => $n, 'today' => $step ? $step[0] . ($step[0] === 'due' ? '' : ' ' . $step[1] . 'd') : null];
            }
            $late = (int) Carbon::parse($dueDate)->diffInDays(Carbon::parse($today), false);
            $rows[] = ['kind' => $inv->kind, 'id' => (int) $inv->id, 'number' => $inv->number, 'family' => $fam, 'balance' => round((float) $inv->balance_due, 2),
                'due' => $dueDate, 'days_overdue' => max(0, $late), 'due_in' => max(0, -$late), 'guardians' => $who, 'copies' => $copies,
                'no_email' => empty($gs),
                // On hold: a payment in the partner system may cover it (PartnerPaymentHold).
                'held' => $held ? array_map(fn ($h) => ['amount' => $h['amount'], 'date' => $h['date'], 'reference' => $h['reference'], 'where' => $h['label']], $held) : null];
        }
        usort($rows, fn ($a, $b) => [$b['days_overdue'], $a['due']] <=> [$a['days_overdue'], $b['due']]);
        return ['agency_id' => $agencyId, 'today' => $today, 'settings' => $cfg, 'rows' => $rows];
    }
}
