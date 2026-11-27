<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC HR PHASE 3, ITEM 9: STAFF EXPENSE AND MILEAGE CLAIMS (2026-11-26).
 *
 * A staff member claims money back two ways:
 *   - expense: a receipt (photo or PDF), the amount before tax, the tax, and a category;
 *   - mileage: km x the agency's rate (agencies.settings.hr_claims.km_rate, set by an admin; no rate = no mileage
 *     claims, never a guessed government rate), from / to. The rate is copied onto the claim when it is submitted.
 *
 * WHO (one rule, reader and guard together, inside the ACTIVE agency, fail closed):
 *   - the claimant: their own claims (see, submit, withdraw while waiting);
 *   - a centre director: claims of the people at their own centres, or filed at those centres (CentreScope);
 *   - an agency admin: all of the agency's.
 * Nobody decides their own claim.
 *
 * PAY: approved claims ride the EXISTING payroll path, nothing new. ManualPayrollController::prepare offers each
 * person's approved, unpaid claims as lines (claim_id) on their next manual payslip; commit() marks them paid with
 * payroll_document_id, exactly as educator reward bonuses. A claim is paid once.
 *
 * RECEIPTS: private, local disk, hr-claims/<agency>/<claim>/; streamed only through HrClaimController::receipt.
 */
final class HrClaims
{
    public const KINDS = ['expense' => 'Expense', 'mileage' => 'Mileage'];
    public const STATUSES = ['submitted' => 'Waiting for approval', 'approved' => 'Approved: in the next pay', 'declined' => 'Declined',
        'paid' => 'Paid', 'withdrawn' => 'Withdrawn'];
    public const DEFAULT_CATEGORIES = ['Classroom supplies', 'Food and snacks', 'Field trips and outings', 'Training and courses',
        'Parking and transit', 'Office supplies', 'Other'];
    public const RECEIPT_RULE = 'file|mimes:jpg,jpeg,png,webp,heic,heif,gif,pdf|max:12288';
    public const COLS = ['id', 'agency_id', 'centre_id', 'user_id', 'kind', 'claim_date', 'category', 'description', 'amount', 'tax', 'total',
        'km', 'km_rate', 'from_place', 'to_place', 'receipt_path', 'receipt_name', 'status', 'decided_by_id', 'decided_at', 'decision_comment',
        'payroll_document_id', 'paid_at', 'created_at'];

    public static function on(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('hr_claims');
    }

    /** agencies.settings.hr_claims: km_rate (null = mileage off) and the categories staff pick from. */
    public static function settings(int $agencyId): array
    {
        $raw = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $s = is_array($raw['hr_claims'] ?? null) ? $raw['hr_claims'] : [];
        $rate = isset($s['km_rate']) && is_numeric($s['km_rate']) && (float) $s['km_rate'] > 0 ? round((float) $s['km_rate'], 3) : null;
        $cats = array_values(array_unique(array_filter(array_map(fn ($c) => trim(mb_substr((string) $c, 0, 60)), (array) ($s['categories'] ?? [])))));

        return ['km_rate' => $rate, 'categories' => $cats ?: self::DEFAULT_CATEGORIES, 'receipt_required' => ($s['receipt_required'] ?? true) !== false];
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true) ?: [];
            $all['hr_claims'] = ['km_rate' => $in['km_rate'] ?? null, 'categories' => array_values((array) ($in['categories'] ?? [])),
                'receipt_required' => ! array_key_exists('receipt_required', $in) || (bool) $in['receipt_required']];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return self::settings($agencyId);
    }

    /** The payslip line label for a claim (also what the employee reads on the payslip). */
    public static function label(object $c): string
    {
        $d = substr((string) $c->claim_date, 0, 10);
        $day = $d ? date('M j', strtotime($d)) : '';
        if ($c->kind === 'mileage') {
            return 'Mileage reimbursement (' . rtrim(rtrim(number_format((float) $c->km, 1, '.', ''), '0'), '.') . ' km, ' . $day . ')';
        }

        return 'Expense reimbursement (' . ($c->category ?: 'Expense') . ', ' . $day . ')';
    }

    /** One claim as lists show it. $names: user id => full name. */
    public static function shape(object $c, array $names, array $centres): array
    {
        return ['id' => (int) $c->id, 'user_id' => (int) $c->user_id, 'person' => $names[(int) $c->user_id] ?? '', 'kind' => $c->kind,
            'claim_date' => substr((string) $c->claim_date, 0, 10), 'category' => $c->category, 'description' => $c->description,
            'amount' => round((float) $c->amount, 2), 'tax' => round((float) $c->tax, 2), 'total' => round((float) $c->total, 2),
            'km' => $c->km !== null ? (float) $c->km : null, 'km_rate' => $c->km_rate !== null ? (float) $c->km_rate : null,
            'from_place' => $c->from_place, 'to_place' => $c->to_place, 'has_receipt' => (bool) $c->receipt_path, 'receipt_name' => $c->receipt_name,
            'status' => $c->status, 'decided_by' => $c->decided_by_id ? ($names[(int) $c->decided_by_id] ?? '') : '',
            'decided_at' => $c->decided_at ? (string) $c->decided_at : null, 'decision_comment' => $c->decision_comment,
            'paid_at' => $c->paid_at ? (string) $c->paid_at : null, 'payroll_document_id' => $c->payroll_document_id ? (int) $c->payroll_document_id : null,
            'centre_id' => $c->centre_id ? (int) $c->centre_id : null, 'centre' => $c->centre_id ? ($centres[(int) $c->centre_id] ?? null) : null,
            'created_at' => (string) $c->created_at];
    }

    /**
     * Approved, unpaid claims per person, as payroll lines for ManualPayrollController::prepare.
     * @param int[] $userIds  @return array<int, array<int, array{claim_id:int,label:string,amount:float}>>
     */
    public static function forPayroll(int $agencyId, array $userIds): array
    {
        if (! self::on() || ! $userIds) return [];
        $out = [];
        foreach (DB::table('hr_claims')->where('agency_id', $agencyId)->where('status', 'approved')->whereNull('payroll_document_id')
            ->whereIn('user_id', $userIds)->orderBy('claim_date')->get(['id', 'user_id', 'kind', 'claim_date', 'category', 'km', 'total']) as $c) {
            $out[(int) $c->user_id][] = ['claim_id' => (int) $c->id, 'label' => self::label($c), 'amount' => round((float) $c->total, 2)];
        }

        return $out;
    }

    /** Mark the claims a payslip pays: only this person's, this agency's, approved and unpaid (a stale screen cannot pay twice). */
    public static function markPaid(int $agencyId, int $userId, array $claimIds, int $payrollDocumentId): int
    {
        if (! self::on() || ! $claimIds) return 0;

        return DB::table('hr_claims')->whereIn('id', $claimIds)->where('agency_id', $agencyId)->where('user_id', $userId)
            ->where('status', 'approved')->whereNull('payroll_document_id')
            ->update(['status' => 'paid', 'payroll_document_id' => $payrollDocumentId, 'paid_at' => now(), 'updated_at' => now()]);
    }
}
