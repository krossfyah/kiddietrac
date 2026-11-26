<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\EmailTemplate;
use App\Services\PaymentReceipt;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * TELL THE OFFICE ABOUT MONEY (2026-11-05). Anthony: "if an invoice is paid via stripe or any of the payment options,
 * what notices are sent to the admin/directors for payment?" - none were: the receipt went to the payer only, and staff
 * found out by opening the invoice. He chose both: an alert on each payment and a daily summary email.
 *
 * - received(): money that came in without a staff member typing it (card, Apple Pay / Google Pay, saved-card autopay,
 *   staff "Charge saved card", the Stripe webhook, Helcim, a partner system's payment). A payment staff record by hand
 *   is not alerted - they know - but it is in the summary.
 * - failed(): an autopay charge that did not go through.
 * - refunded(): money given back automatically (AgencyStripe::refundExcess), or an overpayment that could not be.
 * - summary(): yesterday's money for one agency, emailed each morning (billing:payment-summary).
 *
 * Who: the agency's admins and the directors of the invoice's centre (a director never sees another centre's money).
 * Each alert is a bell row, which also rings their phone (Notify::write pushes every row).
 *
 * TESTS: set PaymentAlerts::$redirect = ['user_id' => .., 'email' => ..] and every alert goes to that one person only,
 * with an emailed copy of each, so nobody at the agency hears about a test payment.
 */
final class PaymentAlerts
{
    /** ['user_id' => int, 'email' => string] - every alert goes to this one person instead (tests). */
    public static ?array $redirect = null;

    /** What was sent this request, for tests. */
    public static array $log = [];

    /** With $redirect['hold'], test emails wait here (to send after a rolled-back test): [[to, subject, html]]. */
    public static array $outbox = [];

    /** The agency's admins plus the directors of this centre: [['user_id','email','first_name','centre_id'|null]]. */
    public static function staff(int $agencyId, ?int $centreId): array
    {
        if (self::$redirect) {
            return [['user_id' => (int) self::$redirect['user_id'], 'email' => (string) self::$redirect['email'], 'first_name' => (string) (self::$redirect['first_name'] ?? 'there'), 'centre_id' => null]];
        }
        $rows = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.agency_id', $agencyId)->where('r.active', 1)
            ->whereIn('r.role', ['agency_admin', 'admin', 'centre_director', 'director', 'assistant_director'])
            ->whereNull('u.deleted_at')
            ->whereNotIn('u.status', ['invited', 'not_invited', 'deactivated', 'suspended'])
            ->get(['u.id', 'u.first_name', 'u.email', 'r.role', 'r.centre_id']);
        $out = [];
        foreach ($rows as $r) {
            // Service accounts (the iLearn integration login and the like) are not people.
            if (preg_match('/^(integration|noreply|no-reply|system|api)[+@]/i', (string) $r->email)) continue;
            $isAdmin = in_array((string) $r->role, ['agency_admin', 'admin'], true);
            if (! $isAdmin && $centreId !== null && $r->centre_id !== null && (int) $r->centre_id !== $centreId) continue;
            $id = (int) $r->id;
            // An admin sees every centre (centre_id null); a director keeps their centre.
            $scope = $isAdmin || $r->centre_id === null ? null : (int) $r->centre_id;
            if (isset($out[$id])) { if ($scope === null) $out[$id]['centre_id'] = null; continue; }
            $out[$id] = ['user_id' => $id, 'email' => mb_strtolower(trim((string) $r->email)), 'first_name' => (string) $r->first_name, 'centre_id' => $scope];
        }
        if (PublicDemo::isAgency($agencyId)) $out = array_filter($out, fn ($s) => PublicDemo::isUser($s['user_id']));

        return array_values($out);
    }

    /** A payment that came in: alert once. */
    public static function received(int $paymentId): void
    {
        try {
            if (! self::$redirect && ! Cache::add('kt-payalert:' . $paymentId, 1, now()->addDays(30))) return;
            $p = DB::table('payments')->where('id', $paymentId)->first();
            if (! $p || $p->status !== 'succeeded' || (float) $p->amount < 0.01) return;
            // A partner sync or backfill replaying old payments must not ring every admin's phone.
            if (Carbon::parse($p->paid_at ?: $p->created_at, 'UTC')->lt(now()->subHours(48))) return;
            $f = self::facts($p);
            if (! $f) return;
            // A split payer's money settles THEIR share; the invoice may still be waiting on the other parent.
            $left = $f['share']
                ? ($f['balance'] <= 0.004 ? 'their share is paid' : '$' . number_format($f['balance'], 2) . ' left on their share')
                    . ($f['invoice_balance'] <= 0.004 ? ', invoice paid in full' : ', $' . number_format($f['invoice_balance'], 2) . ' still owing on the invoice')
                : ($f['balance'] <= 0.004 ? 'paid in full' : '$' . number_format($f['balance'], 2) . ' still owing');
            self::send($f['agency_id'], $f['centre_id'], 'payment_received',
                '💳 $' . number_format((float) $p->amount, 2) . ' received · ' . $f['who'],
                'Invoice ' . $f['number'] . ' · ' . $f['how'] . ' · ' . $left . '.',
                $f['link'], ['payment_id' => $paymentId, 'invoice_id' => $f['invoice_id']]);
        } catch (\Throwable $e) {
            Log::warning('payment alert failed', ['payment' => $paymentId, 'msg' => $e->getMessage()]);
        }
    }

    /** An autopay charge that did not go through. $c is an AutopayRunner candidate row. */
    public static function failed(object $c, string $why): void
    {
        try {
            $inv = DB::table('invoices')->where('id', $c->id)->first(['id', 'centre_id']);
            if (! $inv) return;
            $aid = (int) ($c->agency_id ?? AgencyStripe::agencyForInvoice((int) $c->id));
            $card = trim(self::brand((string) ($c->autopay_card_brand ?? '')) . ($c->autopay_card_last4 ? ' ending ' . $c->autopay_card_last4 : ''));
            self::send($aid, $inv->centre_id ? (int) $inv->centre_id : null, 'payment_failed',
                '⚠️ Auto-pay failed · ' . self::familyLabel((string) $c->family_name),
                'Invoice ' . $c->invoice_number . ' · $' . number_format((float) $c->balance_due, 2) . ($card !== '' ? ' on the ' . $card : '')
                    . ' · ' . self::plainReason($why) . '. The family was asked to update their card.',
                '#admin-billing', ['invoice_id' => (int) $c->id]);
        } catch (\Throwable $e) {
            Log::warning('payment failed alert failed', ['invoice' => $c->id ?? null, 'msg' => $e->getMessage()]);
        }
    }

    /** Money given back automatically, or an overpayment that could not be ($status null). */
    public static function refunded(int $paymentId, float $amount, string $why, ?string $status): void
    {
        try {
            $p = DB::table('payments')->where('id', $paymentId)->first();
            $f = $p ? self::facts($p) : null;
            if (! $f) return;
            $money = '$' . number_format($amount, 2);
            if ($status) {
                self::send($f['agency_id'], $f['centre_id'], 'payment_refunded', '↩️ ' . $money . ' refunded automatically · ' . $f['who'],
                    'Invoice ' . $f['number'] . ': ' . $why . ', so the extra went straight back to the same card. Nothing to do.',
                    $f['link'], ['payment_id' => $paymentId]);
            } else {
                self::send($f['agency_id'], $f['centre_id'], 'payment_refunded', '⚠️ ' . $money . ' overpaid - refund it · ' . $f['who'],
                    'Invoice ' . $f['number'] . ': ' . $why . ', and the automatic refund did not go through. Refund it from Finance > Refunds.',
                    '#refunds', ['payment_id' => $paymentId]);
            }
        } catch (\Throwable $e) {
            Log::warning('refund alert failed', ['payment' => $paymentId, 'msg' => $e->getMessage()]);
        }
    }

    /* ── THE DAILY SUMMARY ───────────────────────────────────────────────────────────────────────────────────── */

    /**
     * One agency's money for one agency-local day: what came in, what failed, what went back. One email per admin
     * (every centre) and director (their centre). Nothing happened = nothing sent. $dry builds without sending.
     * Returns [['to', 'subject', 'received', 'failed', 'refunds']].
     */
    public static function summary(int $agencyId, string $date, bool $dry = false): array
    {
        if (! self::$redirect && ! Suppression::agencyNotificationsEnabled($agencyId)) return [];
        [$from, $to] = AgencyTime::dayRange($agencyId, $date);
        $tz = AgencyTime::tz($agencyId);
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id')->all();
        if (! $centres) return [];

        $got = [];
        $q = DB::table('payments as p')->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->whereIn('i.centre_id', array_keys($centres))->where('p.status', 'succeeded')->where('p.amount', '>', 0)
            ->whereRaw('COALESCE(p.paid_at, p.created_at) >= ? AND COALESCE(p.paid_at, p.created_at) < ?', [$from, $to])
            ->orderBy(DB::raw('COALESCE(p.paid_at, p.created_at)'))->get(['p.*']);
        foreach ($q as $p) if ($f = self::facts($p)) $got[] = $f + ['amount' => (float) $p->amount, 'at' => $p->paid_at ?: $p->created_at];
        $ext = DB::table('payments as p')->join('external_invoices as e', 'e.id', '=', 'p.external_invoice_id')
            ->where('e.agency_id', $agencyId)->where('p.status', 'succeeded')->where('p.amount', '>', 0)
            ->whereRaw('COALESCE(p.paid_at, p.created_at) >= ? AND COALESCE(p.paid_at, p.created_at) < ?', [$from, $to])->get(['p.*']);
        foreach ($ext as $p) if ($f = self::facts($p)) $got[] = $f + ['amount' => (float) $p->amount, 'at' => $p->paid_at ?: $p->created_at];

        $fails = [];
        foreach (DB::table('payment_runs')->where('agency_id', $agencyId)->where('created_at', '>=', $from)->where('created_at', '<', $to)->get(['results', 'created_at']) as $run) {
            foreach ((array) json_decode((string) $run->results, true) as $r) {
                if (($r['result'] ?? '') !== 'failed') continue;
                $cid = (int) DB::table('invoices')->where('id', (int) ($r['invoice_id'] ?? 0))->value('centre_id');
                $fails[] = ['centre_id' => $cid ?: null, 'who' => (string) ($r['family'] ?? ''), 'number' => (string) ($r['invoice_number'] ?? ''),
                    'amount' => (float) ($r['amount'] ?? 0), 'why' => self::plainReason((string) ($r['message'] ?? '')), 'at' => $run->created_at];
            }
        }

        $backs = [];
        foreach (DB::table('payment_refunds')->where('created_at', '>=', $from)->where('created_at', '<', $to)->whereIn('status', ['succeeded', 'pending'])->get() as $r) {
            $p = DB::table('payments')->where('id', $r->payment_id)->first();
            $f = $p ? self::facts($p) : null;
            if (! $f || $f['agency_id'] !== $agencyId) continue;
            $backs[] = $f + ['amount' => (float) $r->amount, 'auto' => (int) $r->initiated_by_id === 0, 'at' => $r->created_at];
        }

        $sent = [];
        foreach (self::staff($agencyId, null) as $s) {
            $mine = fn ($row) => $s['centre_id'] === null || (int) ($row['centre_id'] ?? 0) === $s['centre_id'];
            $g = array_values(array_filter($got, $mine)); $fl = array_values(array_filter($fails, $mine)); $b = array_values(array_filter($backs, $mine));
            if (! $g && ! $fl && ! $b) continue;
            if ($s['email'] === '' || ! filter_var($s['email'], FILTER_VALIDATE_EMAIL)) continue;
            if (! self::$redirect && StaffNotifyPrefs::off((int) $s['user_id'], 'staff_payments', 'email', $agencyId)) continue;   // switched off (2026-11-26)
            $total = array_sum(array_column($g, 'amount'));
            $day = Carbon::parse($date)->format('l, M j');
            $subject = (self::$redirect ? '[TEST] ' : '') . 'Payments for ' . $day . ': $' . number_format($total, 2) . ' received'
                . ($fl ? ' · ' . count($fl) . ' failed' : '');
            $html = self::summaryHtml($agencyId, $s, $day, $g, $fl, $b, $tz, $s['centre_id'] !== null ? ($centres[$s['centre_id']] ?? null) : null);
            if (! $dry) self::mail($agencyId, $s['email'], $subject, $html);
            $sent[] = ['to' => $s['email'], 'subject' => $subject, 'received' => count($g), 'failed' => count($fl), 'refunds' => count($b)];
        }

        return $sent;
    }

    private static function summaryHtml(int $agencyId, array $s, string $day, array $got, array $fails, array $backs, string $tz, ?string $centre): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t = fn ($ts) => AgencyTime::fmt($ts, $tz, 'g:i A') ?? '';
        $total = array_sum(array_column($got, 'amount'));
        $table = function (string $head, string $colour, array $rows, callable $line) use ($e) {
            if (! $rows) return '';
            $h = '<p style="margin:20px 0 6px;font-size:13px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:' . $colour . ';">' . $e($head) . '</p>'
                . '<table role="presentation" class="kt-panel" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:separate;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;">';
            $n = count($rows);
            foreach ($rows as $i => $r) {
                [$left, $sub, $right] = $line($r);
                $bd = $i < $n - 1 ? 'border-bottom:1px solid rgba(148,163,184,.28);' : '';
                $h .= '<tr><td style="padding:10px 16px;' . $bd . 'font-size:14px;color:#0F172A;"><strong>' . $left . '</strong>'
                    . '<div class="kt-muted" style="font-size:12.5px;color:#64748B;margin-top:2px;">' . $sub . '</div></td>'
                    . '<td style="padding:10px 16px;' . $bd . 'font-size:14px;color:#0F172A;font-weight:700;text-align:right;white-space:nowrap;vertical-align:top;">' . $right . '</td></tr>';
            }
            return $h . '</table>';
        };

        $body = '<p style="margin:0 0 6px;">Hi ' . $e($s['first_name'] ?: 'there') . ',</p>'
            . '<p style="margin:0;">Here is the money for <strong>' . $e($day) . '</strong>' . ($centre ? ' at ' . $e($centre) : '') . '.</p>'
            . InvoiceMail::details(array_values(array_filter([
                ['Received', '<span style="color:#15803D;">$' . number_format($total, 2) . '</span> · ' . count($got) . ' payment' . (count($got) === 1 ? '' : 's')],
                $fails ? ['Failed', '<span style="color:#B91C1C;">' . count($fails) . ' autopay charge' . (count($fails) === 1 ? '' : 's') . '</span>'] : null,
                $backs ? ['Refunded', '$' . number_format(array_sum(array_column($backs, 'amount')), 2)] : null,
                $backs ? ['Net', '<strong>$' . number_format($total - array_sum(array_column($backs, 'amount')), 2) . '</strong>'] : null,
            ])))
            . $table('Received', '#15803D', $got, fn ($r) => [$e($r['who']), $e($t($r['at']) . ' · Invoice ' . $r['number'] . ' · ' . $r['how']), '$' . number_format($r['amount'], 2)])
            . $table('Failed - the family was asked to update their card', '#B91C1C', $fails, fn ($r) => [$e($r['who']), $e($t($r['at']) . ' · Invoice ' . $r['number'] . ' · ' . $r['why']), '$' . number_format($r['amount'], 2)])
            . $table('Refunded', '#B45309', $backs, fn ($r) => [$e($r['who']), $e($t($r['at']) . ' · Invoice ' . $r['number'] . ($r['auto'] ? ' · automatic (more than was owed)' : ' · by staff')), '$' . number_format($r['amount'], 2)])
            . '<p style="margin:22px 0 0;">' . InvoiceMail::payButton($agencyId, 'https://app.kiddietrac.com/dashboard.html#admin-billing', 'Open billing') . '</p>'
            . '<p class="kt-muted" style="font-size:12.5px;color:#64748B;margin:16px 0 0;">You get this because you are an admin or director. Each payment also alerts you in the app as it comes in.</p>';

        return EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'PAYMENTS',
            'title' => 'Payments for ' . $day,
            'subtitle' => (string) DB::table('agencies')->where('id', $agencyId)->value('name'),
            'preheader' => '$' . number_format($total, 2) . ' received' . ($fails ? ', ' . count($fails) . ' failed' : '') . '.',
        ]);
    }

    /* ── PLUMBING ────────────────────────────────────────────────────────────────────────────────────────────── */

    /** Who paid, for what, how, and what is left - for one payment row (native or partner invoice). */
    private static function facts(object $p): ?array
    {
        $native = (bool) $p->invoice_id;
        $inv = $native ? DB::table('invoices')->where('id', $p->invoice_id)->first()
            : DB::table('external_invoices')->where('id', $p->external_invoice_id)->first();
        if (! $inv) return null;
        $centreId = ! empty($inv->centre_id) ? (int) $inv->centre_id : null;
        $agencyId = (int) ($inv->agency_id ?? ($centreId ? DB::table('centres')->where('id', $centreId)->value('agency_id') : 0));
        if (! $agencyId) return null;
        $fam = $p->family_id ? (string) DB::table('families')->where('id', $p->family_id)->value('family_name') : '';
        $who = self::familyLabel($fam);
        $balance = $invBalance = (float) ($inv->balance_due ?? 0);
        $share = false;
        if ($native && $p->payer_guardian_id && ($sh = BillingSplit::shareFor($inv, (int) $p->payer_guardian_id))) {
            $who .= ' · ' . $sh['name'];
            $balance = (float) $sh['balance'];
            $share = true;
        }

        return ['agency_id' => $agencyId, 'centre_id' => $centreId, 'who' => $who, 'balance' => $balance, 'share' => $share, 'invoice_balance' => $invBalance,
            'number' => (string) ($inv->invoice_number ?? $inv->number ?? ('#' . $inv->id)), 'invoice_id' => $native ? (int) $inv->id : null,
            'how' => self::how($p), 'link' => $native ? '#admin-billing' : '#external-billing'];
    }

    private static function how(object $p): string
    {
        $n = (string) ($p->notes ?? '');
        // The browser payment sheet cannot say which one the parent picked (WalletPayController::recordIntent).
        if (stripos($n, 'Apple Pay / Google Pay') !== false) return 'Apple Pay or Google Pay (browser)';
        foreach (['Apple Pay', 'Google Pay'] as $w) if (stripos($n, $w) !== false) return $w;
        if (stripos($n, 'charged by staff') !== false || stripos($n, 'Saved card') === 0) return 'Saved card, charged by staff';
        if ($p->stripe_payment_id && stripos($n, 'Auto-pay') !== false) return 'Auto-pay (saved card)';
        if (! empty($p->external_source)) return ucfirst((string) $p->external_source) . ' payment';
        return match ((string) $p->method) {
            'stripe_card', 'card' => 'Card', 'stripe_ach' => 'Bank debit', 'interac' => 'Interac e-Transfer', 'cash' => 'Cash',
            'cheque' => 'Cheque', 'eft' => 'Bank transfer', 'helcim' => 'Card (Helcim)', default => 'Recorded by staff',
        };
    }

    /** "Rivera" -> "Rivera family"; a split payer's "Rivera · Maria" -> "Rivera family · Maria". */
    private static function familyLabel(string $fam): string
    {
        $parts = explode(' · ', $fam, 2);
        $f = trim($parts[0]);
        $f = $f === '' ? 'A family' : $f . (stripos($f, 'family') === false ? ' family' : '');

        return $f . (isset($parts[1]) && trim($parts[1]) !== '' ? ' · ' . trim($parts[1]) : '');
    }

    private static function brand(string $b): string
    {
        return ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'Amex', 'discover' => 'Discover', 'jcb' => 'JCB',
            'unionpay' => 'UnionPay', 'diners' => 'Diners', 'interac' => 'Interac', 'acss_debit' => 'bank account'][strtolower($b)] ?? $b;
    }

    /** Stripe's words, made readable ("Your card has insufficient funds." -> "insufficient funds"). */
    private static function plainReason(string $why): string
    {
        $w = trim(preg_replace('/\s+/', ' ', $why));
        if (preg_match('/insufficient funds/i', $w)) return 'insufficient funds';
        if (preg_match('/expired/i', $w)) return 'the card has expired';
        if (preg_match('/declined/i', $w)) return 'the card was declined';
        if (preg_match('/authenticat|requires_action/i', $w)) return 'the bank wants the parent to approve it';
        if (preg_match('/requires_payment_method/i', $w)) return 'the card was declined';
        return $w !== '' ? rtrim(mb_substr($w, 0, 90), '.') : 'the charge did not go through';
    }

    private static function send(int $agencyId, ?int $centreId, string $type, string $title, string $body, string $link, array $data): void
    {
        if (! self::$redirect && ! Suppression::agencyNotificationsEnabled($agencyId)) return;
        $rows = [];
        $people = self::staff($agencyId, $centreId);
        foreach ($people as $s) {
            $rows[] = ['user_id' => $s['user_id'], 'type' => 'payment', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => $link, 'kind' => $type] + $data), 'created_at' => now()];
        }
        if ($rows) Notify::write($rows);
        self::$log[] = ['type' => $type, 'title' => $title, 'body' => $body, 'to' => array_column($people, 'user_id')];
        // A test also emails each alert, so it can be read as it would look on the phone.
        if (self::$redirect && ! empty(self::$redirect['email'])) {
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $html = EmailTemplate::wrap($agencyId, '<p style="margin:0 0 14px;">This is the alert admins and directors get in the app (bell + phone notification):</p>'
                . '<table role="presentation" class="kt-panel" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:separate;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:14px;">'
                . '<tr><td style="padding:14px 16px;"><div style="font-size:15px;font-weight:800;color:#0F172A;">' . $e($title) . '</div>'
                . '<div class="kt-muted" style="font-size:13.5px;color:#475569;margin-top:4px;line-height:1.45;">' . $e($body) . '</div></td></tr></table>',
                ['eyebrow' => 'PAYMENT ALERT', 'title' => $title, 'subtitle' => 'Test - sent to you only', 'preheader' => $body]);
            self::mail($agencyId, (string) self::$redirect['email'], '[TEST] ' . $title, $html);
        }
    }

    private static function mail(int $agencyId, string $to, string $subject, string $html): void
    {
        $build = function ($m) use ($to, $subject) { $m->to($to)->subject($subject); };
        if (self::$redirect) {
            if (! empty(self::$redirect['hold'])) { self::$outbox[] = [$to, $subject, $html]; return; }
            Mail::html($html, function ($m) use ($build) { MailScope::platform($m); $build($m); });
            return;
        }
        \App\Services\AgencyMailer::forAgency($agencyId)->html($html, $build);
    }
}
