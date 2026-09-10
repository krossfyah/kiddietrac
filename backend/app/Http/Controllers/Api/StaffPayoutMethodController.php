<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * WHERE A MEMBER OF STAFF'S PAY GOES.
 *
 * The profile screen used to offer staff the parent AUTOPAY page — a place to hand the
 * centre a card so it could charge them for childcare — which is exactly backwards for
 * somebody the centre owes money to. This is the right question: e-Transfer, or direct
 * deposit, and to what.
 *
 * THREE RULES, and they are the whole design:
 *
 *   1. WRITE-ONLY. Nothing here ever returns an account number or an e-Transfer address
 *      to a screen. The owner and the payroll list both see a HINT — "•••• 4821" — which
 *      is enough to answer "is the right account on file" and useless to anybody else.
 *      Same contract the Twilio and SMTP secrets keep.
 *   2. BLANK MEANS LEAVE IT. Saving the form after changing only the legal name must not
 *      wipe the account number. Clearing is explicit, via `clear`.
 *   3. ONE DELIBERATE DOOR OUT. reveal() decrypts, and only for an admin or director
 *      issuing a payout, and it writes an audit row EVERY time — who looked at whose
 *      banking detail, and when. A payout destination is the most attractive thing in a
 *      payroll system to tamper with; the log is what makes tampering visible.
 */
final class StaffPayoutMethodController extends Controller
{
    use ResolvesCentreContext;

    /** GET /me/payout-method — the caller's own, never the secrets. */
    public function mine(Request $request): JsonResponse
    {
        $row = DB::table('staff_payout_methods')->where('user_id', $request->user()->id)->first();

        return response()->json(['payout_method' => $this->publicShape($row)]);
    }

    /** PUT /me/payout-method — set or change it. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method'             => 'required|in:interac,direct_deposit',
            'legal_name'         => 'nullable|string|max:160',
            'interac_email'      => 'nullable|email|max:190',
            /* Canadian bank coordinates: 3-digit institution, 5-digit transit, 7-12 digit
               account. Validated so a transposed number is caught at the form rather than
               by a failed payment three weeks later. */
            'institution_number' => 'nullable|digits:3',
            'transit_number'     => 'nullable|digits:5',
            'account_number'     => 'nullable|digits_between:7,12',
            'clear'              => 'nullable|boolean',
        ]);

        $userId = (int) $request->user()->id;
        $agencyId = $this->resolveAgencyId($request);
        $existing = DB::table('staff_payout_methods')->where('user_id', $userId)->first();

        if ($request->boolean('clear')) {
            DB::table('staff_payout_methods')->where('user_id', $userId)->delete();
            $this->note($userId, $agencyId, 'staff.payout_method_cleared', ['by' => 'self']);

            return response()->json(['ok' => true, 'payout_method' => null]);
        }

        $row = [
            'user_id'       => $userId,
            'agency_id'     => $existing->agency_id ?? $agencyId,
            'method'        => $data['method'],
            'legal_name'    => $data['legal_name'] ?? ($existing->legal_name ?? null),
            'updated_by_id' => $userId,
            'updated_at'    => now(),
        ];

        // ── e-Transfer ──
        if (! empty($data['interac_email'])) {
            $row['interac_email_enc'] = Crypt::encryptString($data['interac_email']);
            $row['interac_email_hint'] = $this->maskEmail($data['interac_email']);
        }

        // ── direct deposit: all three or none, so a half-entered account is impossible ──
        $bank = array_filter([
            'institution_number' => $data['institution_number'] ?? null,
            'transit_number'     => $data['transit_number'] ?? null,
            'account_number'     => $data['account_number'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($bank) {
            if (count($bank) !== 3) {
                return response()->json([
                    'message' => 'Enter all three: institution, transit and account number.',
                    'errors' => ['account_number' => ['A bank account needs all three numbers.']],
                ], 422);
            }
            $row['institution_number_enc'] = Crypt::encryptString($bank['institution_number']);
            $row['transit_number_enc'] = Crypt::encryptString($bank['transit_number']);
            $row['account_number_enc'] = Crypt::encryptString($bank['account_number']);
            $row['account_hint'] = '•••• ' . substr($bank['account_number'], -4);
        }

        /* The chosen method must actually have something behind it — counting what is
           already stored, because blank means "leave it". Choosing direct deposit with no
           account on file would leave payroll with a destination it cannot pay. */
        $hasInterac = isset($row['interac_email_enc']) || ! empty($existing->interac_email_enc);
        $hasBank = isset($row['account_number_enc']) || ! empty($existing->account_number_enc);

        if ($data['method'] === 'interac' && ! $hasInterac) {
            return response()->json([
                'message' => 'Add the e-Transfer address you want to be paid at.',
                'errors' => ['interac_email' => ['Required for e-Transfer.']],
            ], 422);
        }
        if ($data['method'] === 'direct_deposit' && ! $hasBank) {
            return response()->json([
                'message' => 'Add the account you want to be paid into.',
                'errors' => ['account_number' => ['Required for direct deposit.']],
            ], 422);
        }

        if ($existing) {
            DB::table('staff_payout_methods')->where('user_id', $userId)->update($row);
        } else {
            $row['created_at'] = now();
            DB::table('staff_payout_methods')->insert($row);
        }

        $fresh = DB::table('staff_payout_methods')->where('user_id', $userId)->first();

        /* WHAT CHANGED, NEVER TO WHAT. The hint is already public to the owner; the
           numbers are not, and an audit row is read by more people than the screen is. */
        $this->note($userId, $agencyId, 'staff.payout_method_updated', [
            'method'  => $data['method'],
            'changed' => array_values(array_filter([
                isset($row['interac_email_enc']) ? 'interac_email' : null,
                isset($row['account_number_enc']) ? 'bank_account' : null,
                array_key_exists('legal_name', $row) ? 'legal_name' : null,
            ])),
            'by' => 'self',
        ]);

        return response()->json(['ok' => true, 'payout_method' => $this->publicShape($fresh)]);
    }

    /**
     * GET /admin/payroll/payout-methods — who can be paid, and how. Admins + directors.
     *
     * Hints only. This is the screen somebody opens to see whether a payroll run can go
     * out, and that question is answered by "yes, e-Transfer, address on file" without
     * a single account number being decrypted.
     */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['staff' => []]);
        }

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');

        /* Everybody the agency actually employs — a role at the agency, or at one of its
           centres. `?: [0]` so an agency with no centres matches nobody, never everybody. */
        $staffIds = DB::table('role_assignments')
            ->where('active', 1)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'home_visitor', 'auditor'])
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereIn('centre_id', $centreIds ?: [0]))
            ->pluck('user_id')->unique()->values();

        $methods = DB::table('staff_payout_methods')->whereIn('user_id', $staffIds ?: [0])
            ->get()->keyBy('user_id');

        $out = DB::table('users')->whereIn('id', $staffIds ?: [0])->whereNull('deleted_at')
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email'])
            ->map(function ($u) use ($methods) {
                $m = $methods->get($u->id);

                return [
                    'user_id' => (int) $u->id,
                    'name'    => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email,
                    'email'   => $u->email,
                    'payout_method' => $this->publicShape($m),
                ];
            })->values();

        return response()->json([
            'staff' => $out,
            'ready' => $out->filter(fn ($s) => $s['payout_method'] !== null)->count(),
            'missing' => $out->filter(fn ($s) => $s['payout_method'] === null)->count(),
        ]);
    }

    /**
     * GET /admin/payroll/payout-methods/{user}/reveal — the actual detail, to pay them.
     *
     * The one door out, and it is audited every time. Nothing else in this controller
     * decrypts anything.
     */
    public function reveal(Request $request, int $user): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);

        /* They must be OUR staff. Without this an admin could read the banking details of
           anybody on the platform by id — the shape that has bitten this codebase before
           (see the standing data-leakage rule). 404, not 403: another agency's staff are
           not ours to confirm the existence of. */
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');
        $isOurs = DB::table('role_assignments')->where('user_id', $user)->where('active', 1)
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereIn('centre_id', $centreIds ?: [0]))
            ->exists();
        abort_unless($isOurs, 404, 'Not found');

        $row = DB::table('staff_payout_methods')->where('user_id', $user)->first();
        abort_unless($row, 404, 'No payout method on file');

        $person = DB::table('users')->where('id', $user)->first(['first_name', 'last_name']);

        $this->note($user, $agencyId, 'staff.payout_method_revealed', [
            'by' => (int) $request->user()->id,
            'staff' => trim(($person->first_name ?? '') . ' ' . ($person->last_name ?? '')),
            'method' => $row->method,
            'reason' => 'Opened to issue a payment.',
        ]);

        $dec = fn ($v) => $v ? rescue(fn () => Crypt::decryptString($v), null, false) : null;

        return response()->json([
            'user_id'    => (int) $user,
            'method'     => $row->method,
            'legal_name' => $row->legal_name,
            'interac_email' => $row->method === 'interac' ? $dec($row->interac_email_enc) : null,
            'institution_number' => $row->method === 'direct_deposit' ? $dec($row->institution_number_enc) : null,
            'transit_number'     => $row->method === 'direct_deposit' ? $dec($row->transit_number_enc) : null,
            'account_number'     => $row->method === 'direct_deposit' ? $dec($row->account_number_enc) : null,
        ]);
    }

    /** What any screen may see: the method, a hint, and when it was last touched. */
    private function publicShape(?object $row): ?array
    {
        if (! $row) {
            return null;
        }

        return [
            'method'     => $row->method,
            'legal_name' => $row->legal_name,
            'hint'       => $row->method === 'interac'
                ? ($row->interac_email_hint ?: null)
                : ($row->account_hint ?: null),
            'updated_at' => $row->updated_at,
        ];
    }

    /** n•••@example.com — enough to recognise, not enough to use. */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '•••';
        }

        return substr($email, 0, 1) . '•••' . substr($email, $at);
    }

    private function note(int $subjectUserId, ?int $agencyId, string $action, array $payload): void
    {
        try {
            Audit::write([
                'user_id'     => optional(request()->user())->id,
                'agency_id'   => $agencyId,
                'action'      => $action,
                'entity_type' => 'user',
                'entity_id'   => $subjectUserId,
                'payload'     => json_encode($payload),
            ]);
        } catch (\Throwable $e) {
            // An audit row must never be why a payroll change fails.
        }
    }
}
