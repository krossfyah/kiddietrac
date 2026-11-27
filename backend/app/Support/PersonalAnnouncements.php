<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PERSONAL ANNOUNCEMENTS (2026-10-31, Anthony: "when there is a new invoice or payroll make this an announcement for
 * each user").
 *
 * A new invoice becomes an announcement in each paying guardian's News (their own amount - their share when the bill is
 * split); a new payslip becomes one in that staff member's Announcements. Each is scope_type 'user' / scope_id = the
 * person, so only they ever see it: the agency/centre/room lists ask for those scope types by name.
 *
 * WHY A SWEEP (kt:personal-announcements, every 2 minutes) and not hooks in every issue path: invoices are issued by
 * hand, in bulk, by the monthly run and by payment schedules, iLearn pushes its own invoices and payslips, and payroll is
 * generated two ways. One sweep over "issued since announce.personal_since and not announced yet" covers every path,
 * including ones added later, and can never announce the same thing twice (unique scope + ref).
 *
 * Rules:
 *  - nothing from before announce.personal_since (set by the migration) - no backfill;
 *  - invoices: not draft / void / cancelled; agency setting billing_setup.announce_invoices (default on);
 *  - payslips: kind payslip, not void, linked to a user; agency setting payroll.announce_payslips (default on);
 *    iLearn never sends switched-off contacts (kiddietrac_enabled=0) and voids their old ones, so they never qualify;
 *  - an edit that changes the amount or due date rewrites the same announcement quietly (no second bell);
 *  - the bell + push go out once, unless the issue path already rang the bell for this invoice (native issue writes an
 *    'invoice' notification) - then the announcement is added quietly.
 */
final class PersonalAnnouncements
{
    private const SKIP_INVOICE = ['draft', 'void', 'cancelled', 'refunded'];
    private const WINDOW_DAYS = 45;

    public static function since(): ?string
    {
        $s = PlatformSettings::get('announce.personal_since');
        return $s ? (string) $s : null;
    }

    public static function enabled(int $agencyId, string $what): bool
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        if ($what === 'invoices') return (($s['billing_setup']['announce_invoices'] ?? true) !== false);
        return (($s['payroll']['announce_payslips'] ?? true) !== false);
    }

    /** One pass. Returns counts. */
    public static function run(bool $dry = false, int $limit = 1500): array
    {
        $out = ['invoices' => 0, 'external' => 0, 'payslips' => 0, 'updated' => 0, 'quiet' => 0, 'skipped' => 0];
        $since = self::since();
        if (! $since) return $out;
        $floor = max($since, now()->subDays(self::WINDOW_DAYS)->format('Y-m-d H:i:s'));
        $agencyOn = [];
        $on = function (int $aid, string $what) use (&$agencyOn) {
            $k = $aid . ':' . $what;
            return $agencyOn[$k] ??= self::enabled($aid, $what);
        };

        // Native invoices issued since the cut-off (and invoices edited since then that were already announced).
        $native = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->whereNotIn('i.status', self::SKIP_INVOICE)->whereNotNull('i.issued_at')
            // issued_at is a DATE: issued on/after the cut-off day, not in the future, and touched (issued) after the
            // cut-off moment - an invoice issued earlier on the cut-off day is not announced (no backfill).
            ->whereDate('i.issued_at', '>=', substr($floor, 0, 10))->whereDate('i.issued_at', '<=', now()->addDay()->toDateString())
            ->where('i.updated_at', '>=', $since)
            ->orderByDesc('i.id')->limit($limit)
            ->get(['i.*', 'c.agency_id', DB::raw('i.invoice_number as number')]);
        foreach ($native as $inv) {
            if (! $on((int) $inv->agency_id, 'invoices')) { $out['skipped']++; continue; }
            self::forInvoice($inv, 'native', $dry, $out);
        }

        $external = DB::table('external_invoices as x')
            ->whereNotIn('x.status', array_merge(self::SKIP_INVOICE, ['paid']))->whereNotNull('x.family_id')
            ->where('x.created_at', '>=', $floor)
            ->orderByDesc('x.id')->limit($limit)
            ->get(['x.*']);
        foreach ($external as $x) {
            if (! $on((int) $x->agency_id, 'invoices')) { $out['skipped']++; continue; }
            self::forInvoice($x, 'external', $dry, $out);
        }

        $slips = DB::table('payroll_documents')->where('kind', 'payslip')->whereNotNull('user_id')
            ->whereNotIn('status', ['void', 'draft'])->where('created_at', '>=', $floor)
            ->orderByDesc('id')->limit($limit)->get();
        foreach ($slips as $d) {
            if (! $on((int) $d->agency_id, 'payslips')) { $out['skipped']++; continue; }
            self::forPayslip($d, $dry, $out);
        }
        return $out;
    }

    /** An invoice (native or iLearn-synced) to each paying guardian. */
    public static function forInvoice(object $inv, string $kind, bool $dry, array &$out): void
    {
        $agencyId = (int) ($inv->agency_id ?? 0);
        // Families see the agency without Inc / Ltd (FamilyFacingName, 2026-11-27).
        $agency = FamilyFacingName::agency($agencyId) ?: 'your centre';
        $refType = $kind === 'native' ? 'invoice' : 'external_invoice';
        $split = $kind === 'native' && ! empty($inv->split_snapshot);
        try { $people = InvoiceReminders::guardians($inv, $kind); } catch (Throwable $e) { $people = []; }
        $interac = '';
        try {
            $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $interac = (string) ($s['billing_setup']['interac_email'] ?? '');
        } catch (Throwable $e) {}
        $due = ! empty($inv->due_at) ? Carbon::parse(substr((string) $inv->due_at, 0, 10))->format('l, F j, Y') : null;

        foreach ($people as $p) {
            $uid = (int) ($p['user_id'] ?? 0);
            if (! $uid) continue;
            $number = (string) ($inv->number ?? $inv->invoice_number ?? ('#' . $inv->id));
            if ($split) {
                try { $number = SplitInvoiceCopy::numberFor($inv, (int) $p['guardian_id']) ?: $number; } catch (Throwable $e) {}
            }
            $amount = $split && $p['share'] !== null ? (float) $p['share'] : (float) ($inv->total ?? $inv->balance_due ?? 0);
            $title = 'New invoice from ' . $agency . ': ' . $number;
            $body = '<p>Hi ' . e($p['first_name'] ?: 'there') . ',</p>'
                . '<p>Your invoice <strong>' . e($number) . '</strong> for <strong>$' . number_format($amount, 2) . '</strong>'
                . ($split ? ' (your share)' : '') . ' is ready' . ($due ? ', due <strong>' . e($due) . '</strong>' : '') . '.</p>'
                . ($interac !== '' ? '<p>Pay by Interac e-Transfer to <strong>' . e($interac) . '</strong> and put <strong>' . e($number) . '</strong> in the message, or ask for an e-Transfer request in Billing.</p>' : '')
                . '<p>Your receipts, statements and account ledger are always in <strong>Billing</strong>.</p>';
            $actions = [['label' => '🧾 View invoice', 'link' => '#billing'] + ($kind === 'native' ? ['invoice_id' => (int) $inv->id] : []),
                ['label' => '💳 Pay', 'link' => '#billing']];
            $hash = sha1(json_encode([$number, round($amount, 2), substr((string) ($inv->due_at ?? ''), 0, 10)]));
            // The native issue path already rang the bell for this invoice: add the announcement quietly.
            $rang = DB::table('notifications')->where('user_id', $uid)->where('type', 'invoice')
                ->where('data', 'REGEXP', '"invoice_id":"?' . (int) $inv->id . '"?[,}]')->exists();
            self::upsert([
                'user_id' => $uid, 'agency_id' => $agencyId, 'audience' => 'parents',
                'ref_type' => $refType, 'ref_id' => (int) $inv->id, 'hash' => $hash,
                'title' => $title, 'body' => $body, 'link' => '#billing', 'actions' => $actions,
                'notif_title' => '🧾 New invoice · ' . $agency,
                'notif_body' => $number . ' for $' . number_format($amount, 2) . ($due ? ', due ' . Carbon::parse(substr((string) $inv->due_at, 0, 10))->format('M j') : '') . '.',
                'category' => 'billing', 'quiet' => $kind === 'native' && $rang,
            ], $dry, $out, $kind === 'native' ? 'invoices' : 'external');
        }
    }

    /** A payslip to the staff member it belongs to. */
    public static function forPayslip(object $d, bool $dry, array &$out): void
    {
        $uid = (int) $d->user_id;
        $u = DB::table('users')->where('id', $uid)->whereNull('deleted_at')->first(['id', 'first_name', 'status']);
        if (! $u || in_array((string) $u->status, Audience::OFF_STATUSES, true)) { $out['skipped']++; return; }
        $period = $d->period_start && $d->period_end
            ? Carbon::parse($d->period_start)->format('M j') . ' – ' . Carbon::parse($d->period_end)->format('M j, Y')
            : ($d->period_end ? Carbon::parse($d->period_end)->format('M j, Y') : 'this pay period');
        $net = $d->net !== null ? (float) $d->net : null;
        $isHv = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'home_visitor')->where('active', 1)->exists();
        $link = $isHv ? '#my-pay' : '#my-hours';
        $title = 'Your payslip for ' . $period . ' is ready';
        $body = '<p>Hi ' . e($u->first_name ?: 'there') . ',</p>'
            . '<p>Your payslip for <strong>' . e($period) . '</strong> is ready'
            . ($net !== null ? ' — net pay <strong>$' . number_format($net, 2) . '</strong>' : '') . '.</p>'
            . '<p>Open <strong>Payroll</strong> to view or download it, along with your pay ledger.</p>';
        self::upsert([
            'user_id' => $uid, 'agency_id' => (int) $d->agency_id, 'audience' => 'educators',
            'ref_type' => 'payslip', 'ref_id' => (int) $d->id,
            'hash' => sha1(json_encode([$period, $net])),
            'title' => $title, 'body' => $body, 'link' => $link,
            'actions' => [['label' => '💵 View payslip', 'link' => $link]],
            'notif_title' => '💵 Your payslip is ready',
            'notif_body' => $period . ($net !== null ? ' · net $' . number_format($net, 2) : '') . '.',
            'category' => 'staff', 'quiet' => false,
        ], $dry, $out, 'payslips');
    }

    /** Create once (bell + push), or quietly rewrite when the amount/date changed. */
    private static function upsert(array $o, bool $dry, array &$out, string $bucket): void
    {
        $have = DB::table('announcements')->where('scope_type', 'user')->where('scope_id', $o['user_id'])
            ->where('ref_type', $o['ref_type'])->where('ref_id', $o['ref_id'])->first(['id', 'ref_hash']);
        if ($have) {
            if ($have->ref_hash === $o['hash']) return;
            if (! $dry) {
                self::audit($o, (int) $have->id, true);
                DB::table('announcements')->where('id', $have->id)->update([
                    'title' => mb_substr($o['title'], 0, 200), 'body' => $o['body'], 'ref_hash' => $o['hash'],
                    'actions' => json_encode($o['actions']), 'link' => $o['link'],
                ]);
            }
            $out['updated']++;
            return;
        }
        if ($dry) { $out[$bucket]++; return; }
        try {
            $id = DB::table('announcements')->insertGetId([
                'scope_type' => 'user', 'scope_id' => $o['user_id'], 'agency_id' => $o['agency_id'], 'audience' => $o['audience'],
                'title' => mb_substr($o['title'], 0, 200), 'body' => $o['body'],
                'send_email' => 0, 'send_sms' => 0, 'send_push' => $o['quiet'] ? 0 : 1, 'send_voice' => 0,
                'sent_at' => now(), 'created_by_id' => null, 'created_at' => now(),
                'ref_type' => $o['ref_type'], 'ref_id' => $o['ref_id'], 'ref_hash' => $o['hash'],
                'link' => $o['link'], 'actions' => json_encode($o['actions']),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return; // another pass created it a moment ago (unique key)
        }
        $out[$bucket]++;
        self::audit($o, (int) $id, false);
        if ($o['quiet']) { $out['quiet']++; return; }
        try {
            Notify::write(['user_id' => $o['user_id'], 'type' => 'announcement', 'title' => $o['notif_title'],
                'body' => mb_substr($o['notif_body'], 0, 1000),
                'data' => json_encode(['announcement_id' => $id, 'link' => $o['link'], 'personal' => true]), 'created_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('personal announcement bell failed', ['announcement' => $id, 'error' => $e->getMessage()]);
        }
        if (\App\Support\TestAgency::skips((int) ($o['agency_id'] ?? 0), 'personal announcement push', ['user' => $o['user_id']])) return;   // test agency (2026-11-25)
        try {
            app(\App\Services\FcmService::class)->sendToUser($o['user_id'], $o['notif_title'], $o['notif_body'], $o['link'],
                false, false, true, ['kt_category' => $o['category']]);
        } catch (Throwable $e) {}
    }

    /** WHO got WHAT, about WHICH record, and how they were told (2026-10-31: no raw audit rows). */
    private static function audit(array $o, int $announcementId, bool $updated): void
    {
        try {
            $u = DB::table('users')->where('id', $o['user_id'])->first(['first_name', 'last_name', 'email']);
            $who = $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: (string) $u->email) : 'a person';
            $what = rtrim(trim(strip_tags(str_replace(['</p>', '<br>'], ' ', (string) ($o['notif_body'] ?? '')))), '.');
            $role = $o['ref_type'] === 'payslip' ? 'staff member' : 'guardian';
            $fam = '';
            if ($o['ref_type'] !== 'payslip') {
                $table = $o['ref_type'] === 'invoice' ? 'invoices' : 'external_invoices';
                $fid = DB::table($table)->where('id', $o['ref_id'])->value('family_id');
                $fam = $fid ? (string) (DB::table('families')->where('id', $fid)->value('family_name') ?? '') : '';
            }
            $how = $o['quiet'] ? 'added quietly (the invoice already rang their bell)' : 'with a bell and phone notification';
            Audit::write([
                'user_id' => null, 'agency_id' => $o['agency_id'] ?: null,
                'action' => 'announcement.personal', 'entity_type' => 'user', 'entity_id' => $o['user_id'],
                'payload' => json_encode([
                    'summary' => ($updated ? 'Updated the personal announcement to ' : 'Announced to ') . $who . ' (' . $role . ($fam !== '' ? ', ' . $fam . ' family' : '') . '): “'
                        . $o['title'] . '” — ' . $what . ($updated ? ' (amount or date changed; no new notification)' : ' — ' . $how) . '. Scheduled job kt:personal-announcements.',
                    'announcement_id' => $announcementId, 'ref_type' => $o['ref_type'], 'ref_id' => $o['ref_id'],
                    'to' => $u->email ?? null, 'updated' => $updated,
                ]),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // auditing never blocks the announcement
        }
    }

    /** The signed-in person's own personal announcements, newest first (for the feeds). */
    public static function forUser(int $userId, int $limit = 50, ?int $agencyId = null): array
    {
        /* Only the agency the person is working in (2026-10-31): Anthony, active in Test Agency, saw his iLearn
           payslips. A row without an agency (none today) still shows. No active agency = nothing (fail closed). */
        if (! $agencyId) return [];
        /* NO PAYSLIPS IN NEWS (2026-11-07, Anthony: "News should not have payslips listed there"). A new payslip still
           rings the bell and the phone (its link opens My pay / My hours); it is just not a News item. This is the one
           reader of these rows for the News lists (staff and parent), so leaving them out here covers every screen. */
        return DB::table('announcements')->where('scope_type', 'user')->where('scope_id', $userId)
            ->where(function ($w) { $w->whereNull('ref_type')->orWhere('ref_type', '!=', 'payslip'); })
            ->where(function ($w) use ($agencyId) { $w->where('agency_id', $agencyId)->orWhereNull('agency_id'); })
            ->whereNotNull('sent_at')->orderByDesc('sent_at')->limit($limit)->get()
            ->map(fn ($a) => self::present($a))->all();
    }

    public static function present(object $a): array
    {
        $actions = $a->actions ? (json_decode((string) $a->actions, true) ?: []) : [];
        return [
            'id' => (int) $a->id, 'title' => $a->title, 'body' => $a->body, 'sent_at' => $a->sent_at,
            'personal' => true, 'kind' => $a->ref_type, 'ref_id' => isset($a->ref_id) ? (int) $a->ref_id : null, 'link' => $a->link, 'actions' => $actions,
            'due_at' => null, 'track_done' => false, 'my_done' => false, 'pdf_name' => null, 'pdf_url' => null,
        ];
    }
}
