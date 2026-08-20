<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Zum Rails — money in from parents, money out to staff.
 *
 * DARK until credentials exist. configured() is false without a username, password and
 * base URL, and every method returns null rather than throwing, so nothing that calls
 * this can break a page while the integration is unconfigured. Same approach as the
 * social-login scaffolding.
 *
 * Two things about their API shape drive this class:
 *
 *  1. Auth is a username/password exchanged for a bearer token that lasts ONE HOUR, not
 *     a long-lived key. So the token is cached for fifty minutes and re-fetched, rather
 *     than requested per call (which would be a login storm) or held forever.
 *
 *  2. Direction is a FIELD, not an endpoint. ZumRailsType is AccountsReceivable to
 *     collect from a parent and AccountsPayable to pay a staff member, over the same
 *     transaction endpoint. Both flows therefore share one method here.
 *
 * Settlement status must come from the webhook, never from the POST response: an Interac
 * e-Transfer is accepted immediately and settles later, so treating the create response
 * as "paid" would mark invoices paid that have not been.
 */
final class ZumRails
{
    private const TOKEN_KEY = 'zumrails:token';
    private const TOKEN_TTL = 3000;          // 50 minutes; their token lasts 60.

    /** The two methods we use. Their Canadian set also includes Interac, VisaDirect,
     *  PrepaidCard and CreditCardIssuance, which we deliberately do not. */
    public const EFT = 'Eft';
    public const CARD = 'CreditCard';

    /** Their statuses, verbatim, so the mapping below has something to be checked against:
     *  InProgress, Completed, Failed, Cancelled, Scheduled, InReview, Pending Cancellation. */

    /** This agency's credentials. Never another's, and never the platform's. */
    private static function cfg(int $agencyId): array
    {
        return \App\Support\PaymentProviders::config($agencyId, \App\Support\PaymentProviders::ZUM);
    }

    public static function configured(int $agencyId): bool
    {
        return \App\Support\PaymentProviders::configured($agencyId, \App\Support\PaymentProviders::ZUM);
    }

    /** The agency a payment belongs to, from the paying user's own roles. */
    public static function agencyOf(int $userId): ?int
    {
        $id = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->whereNotNull('agency_id')->value('agency_id');
        if ($id) {
            return (int) $id;
        }

        // A guardian has no agency role — find it through their family's centre.
        return DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('g.user_id', $userId)
            ->value('c.agency_id');
    }

    /** A bearer token, cached. Null when unconfigured or the exchange fails. */
    public static function token(int $agencyId, bool $fresh = false): ?string
    {
        if (! self::configured($agencyId)) {
            return null;
        }
        // Keyed by agency: two agencies hold different credentials, and a shared cache key
        // would hand one agency the other's session.
        $key = self::TOKEN_KEY.':'.$agencyId;
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TOKEN_TTL, function () use ($agencyId) {
            $c = self::cfg($agencyId);
            try {
                $res = Http::timeout(20)->asJson()->post(rtrim($c['base_url'], '/').'/api/authorize', [
                    'Username' => $c['username'],
                    'Password' => $c['password'],
                ]);
                if (! $res->successful()) {
                    Log::warning('zumrails authorize failed', ['status' => $res->status()]);

                    return null;
                }
                // Their payload nests the token under result on some endpoints; accept both.
                $body = $res->json();

                return $body['Token'] ?? $body['result']['Token'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('zumrails authorize threw', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * One request, with a single retry after a forced token refresh.
     *
     * A 401 mid-flight means the hour elapsed between our cache write and the call, which
     * is normal rather than exceptional — retrying once with a fresh token is the whole
     * handling it needs.
     */
    public static function call(int $agencyId, string $method, string $path, array $payload = []): ?array
    {
        if (! self::configured($agencyId)) {
            return null;
        }
        $base = (string) (self::cfg($agencyId)['base_url'] ?? '');
        if ($base === '') {
            return null;
        }

        $attempt = function (?string $token) use ($method, $path, $payload, $base) {
            $url = rtrim($base, '/').'/'.ltrim($path, '/');
            $req = Http::timeout(30)->withToken((string) $token)->asJson();

            return strtoupper($method) === 'GET'
                ? $req->get($url, $payload)
                : $req->send(strtoupper($method), $url, ['json' => $payload]);
        };

        try {
            $res = $attempt(self::token($agencyId));
            if ($res->status() === 401) {
                $res = $attempt(self::token($agencyId, true));
            }
            if (! $res->successful()) {
                Log::warning('zumrails call failed', [
                    'path' => $path, 'status' => $res->status(),
                    'body' => mb_substr((string) $res->body(), 0, 400),
                ]);

                return null;
            }

            return $res->json() ?: [];
        } catch (\Throwable $e) {
            Log::warning('zumrails call threw', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The Zum user id for one of ours, creating it on first use.
     *
     * Zum needs its own user record before money can move either way, so this keeps a
     * mapping rather than looking anything up by email each time — an email can change,
     * and a mismatched lookup would move money to the wrong person.
     */
    public static function userIdFor(int $agencyId, int $userId): ?string
    {
        $existing = DB::table('zum_users')->where('user_id', $userId)->value('zum_user_id');
        if ($existing) {
            return $existing;
        }
        if (! self::configured($agencyId)) {
            return null;
        }

        $u = DB::table('users')->where('id', $userId)->first(['first_name', 'last_name', 'email', 'phone']);
        if (! $u || ! $u->email) {
            return null;
        }

        $res = self::call($agencyId, 'POST', '/api/user', [
            'FirstName' => (string) $u->first_name,
            'LastName' => (string) $u->last_name,
            'Email' => (string) $u->email,
            'PhoneNumber' => (string) ($u->phone ?? ''),
        ]);
        $zumId = $res['Id'] ?? $res['result']['Id'] ?? null;
        if (! $zumId) {
            return null;
        }

        DB::table('zum_users')->insert([
            'user_id' => $userId,
            'zum_user_id' => $zumId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $zumId;
    }

    /**
     * Charge a parent's card. Acceptance is AccountsReceivable + CreditCard — the same
     * transaction endpoint, with the direction expressed as a field.
     */
    public static function chargeCard(int $userId, float $amount, array $links = [], string $comment = ''): ?int
    {
        return self::transact('in', $userId, $amount, self::CARD, $links, $comment);
    }

    /** Collect by EFT from a parent's bank account. */
    public static function collectEft(int $userId, float $amount, array $links = [], string $comment = ''): ?int
    {
        return self::transact('in', $userId, $amount, self::EFT, $links, $comment);
    }

    /**
     * Void a transaction that has not gone through yet.
     *
     * Deliberately refuses rather than tries, where their rules say it cannot work:
     *
     *  • EFT can only be cancelled before it reaches the financial institution, so a
     *    settled one is refused here rather than sent and rejected.
     *  • A card payment cannot be cancelled at all once taken — it has to be reversed,
     *    which is a different operation (see refund below).
     *
     * Returns true only when Zum accepted the cancellation.
     */
    public static function void(int $rowId): bool
    {
        $row = DB::table('zum_transactions')->where('id', $rowId)->first();
        $agencyId = $row ? self::agencyOf((int) $row->user_id) : null;
        if (! $row || ! $row->zum_transaction_id || ! $agencyId || ! self::configured($agencyId)) {
            return false;
        }
        if (in_array($row->status, ['settled', 'cancelled', 'failed'], true)) {
            return false;
        }
        if ($row->method === self::CARD) {
            // Not a limitation of ours: their API does not allow it.
            return false;
        }

        $res = self::call($agencyId, 'DELETE', '/api/transaction/'.$row->zum_transaction_id);
        if ($res === null) {
            return false;
        }

        DB::table('zum_transactions')->where('id', $rowId)->update([
            // Their cancel is not always immediate — some methods pass through
            // "Pending Cancellation" first, and the webhook confirms the end state.
            'status' => 'cancelling',
            'last_response' => json_encode($res),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * How much of this payment can still be refunded.
     *
     * Settled amount minus everything already refunded. Only SETTLED refunds count:
     * one in flight has not moved any money yet, but it is still reserved here so two
     * people refunding at once cannot together exceed the payment.
     */
    public static function refundableAmount(int $rowId): float
    {
        $row = DB::table('zum_transactions')->where('id', $rowId)->first();
        if (! $row || $row->status !== 'settled') {
            return 0.0;
        }

        // 'blocked' counts as in flight. It means recorded but not yet sent, because no
        // refund endpoint is configured — an intent that WILL go out once one is. Without
        // reserving it, ten blocked refunds of the full amount could be queued and all
        // fire the moment the endpoint is set.
        $inFlight = (float) DB::table('zum_refunds')
            ->where('zum_transaction_id_local', $rowId)
            ->whereIn('status', ['pending', 'submitted', 'blocked'])
            ->sum('amount');

        return max(0.0, round((float) $row->amount - (float) $row->refunded_amount - $inFlight, 2));
    }

    /**
     * Refund some or all of a settled payment.
     *
     * $amount omitted refunds everything still refundable. Returns our zum_refunds row
     * id — follow OUR record, because the money only actually moves when the webhook
     * says it has.
     *
     * Partial by design: a childcare refund is usually part of a payment — a closure
     * credit, a withdrawn day, a sibling adjustment — so several refunds against one
     * charge is the normal case rather than the exception.
     *
     * @throws \InvalidArgumentException when the amount is not refundable. Refusing
     *         loudly beats returning null: over-refunding is somebody else's money.
     */
    public static function refund(
        int $rowId,
        ?float $amount = null,
        string $reason = '',
        ?int $byUserId = null
    ): ?int {
        $row = DB::table('zum_transactions')->where('id', $rowId)->first();
        if (! $row) {
            throw new \InvalidArgumentException('No such payment.');
        }
        if ($row->status !== 'settled') {
            throw new \InvalidArgumentException('Only a settled payment can be refunded.');
        }
        if ($row->direction !== 'in') {
            throw new \InvalidArgumentException('Only money collected from a family can be refunded.');
        }

        $available = self::refundableAmount($rowId);
        $amount = $amount === null ? $available : round((float) $amount, 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be more than zero.');
        }
        if ($amount > $available + 0.005) {
            throw new \InvalidArgumentException(sprintf(
                'Only %s of this payment can still be refunded.', number_format($available, 2)
            ));
        }

        // Recorded BEFORE the call, so a request that never returns still leaves a
        // trace and the amount is reserved against further refunds.
        $refundId = DB::table('zum_refunds')->insertGetId([
            'zum_transaction_id_local' => $rowId,
            'amount' => $amount,
            'reason' => $reason !== '' ? mb_substr($reason, 0, 300) : null,
            'status' => 'pending',
            'requested_by_id' => $byUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $agencyId = self::agencyOf((int) $row->user_id);
        $path = $agencyId ? (string) (self::cfg($agencyId)['refund_path'] ?? '') : '';

        // Their API reference documents no refund endpoint. Until one is confirmed the
        // refund is recorded and left blocked -- deliberately NOT attempted against a
        // guessed URL, because a wrong guess either quietly does nothing while a family
        // has been told their money is coming back, or sends it twice.
        if ($path === '' || ! $agencyId || ! self::configured($agencyId)) {
            DB::table('zum_refunds')->where('id', $refundId)->update([
                'status' => 'blocked',
                'last_response' => json_encode(['blocked' => 'no refund endpoint configured']),
                'updated_at' => now(),
            ]);
            Log::warning('zumrails refund recorded but not sent', [
                'refund_id' => $refundId, 'reason' => 'services.zumrails.refund_path is not set',
            ]);

            return $refundId;
        }

        $res = self::call($agencyId, 'POST', str_replace('{id}', (string) $row->zum_transaction_id, $path), [
            'Amount' => $amount,
            'Comment' => $reason !== '' ? $reason : null,
        ]);

        $zumRefundId = $res['Id'] ?? $res['result']['Id'] ?? null;
        DB::table('zum_refunds')->where('id', $refundId)->update([
            'zum_refund_id' => $zumRefundId,
            'status' => $zumRefundId ? 'submitted' : 'failed',
            'last_response' => json_encode($res),
            'updated_at' => now(),
        ]);

        return $refundId;
    }
    /**
     * Move money. $direction is 'in' (collect from a parent) or 'out' (pay somebody).
     *
     * Returns our own zum_transactions row id, not theirs — callers should follow OUR
     * record, because the authoritative status arrives later by webhook.
     */
    public static function transact(
        string $direction,
        int $userId,
        float $amount,
        string $method = self::EFT,
        array $links = [],
        string $comment = ''
    ): ?int {
        $agencyId = self::agencyOf($userId);
        if (! $agencyId || ! self::configured($agencyId) || $amount <= 0) {
            return null;
        }
        $zumUser = self::userIdFor($agencyId, $userId);
        if (! $zumUser) {
            return null;
        }

        $rowId = DB::table('zum_transactions')->insertGetId([
            'direction' => $direction === 'in' ? 'in' : 'out',
            'user_id' => $userId,
            'invoice_id' => $links['invoice_id'] ?? null,
            'payroll_document_id' => $links['payroll_document_id'] ?? null,
            'amount' => $amount,
            'method' => $method,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = self::call($agencyId, 'POST', '/api/transaction', [
            'ZumRailsType' => $direction === 'in' ? 'AccountsReceivable' : 'AccountsPayable',
            'TransactionMethod' => $method,
            'Amount' => round($amount, 2),
            'UserId' => $zumUser,
            'Comment' => $comment !== '' ? $comment : null,
        ]);

        $zumTxnId = $res['Id'] ?? $res['result']['Id'] ?? null;
        DB::table('zum_transactions')->where('id', $rowId)->update([
            'zum_transaction_id' => $zumTxnId,
            // No id back means it never reached them. Recorded as failed rather than left
            // pending, so nothing waits forever on a webhook that cannot arrive.
            'status' => $zumTxnId ? 'submitted' : 'failed',
            'last_response' => json_encode($res),
            'updated_at' => now(),
        ]);

        return $rowId;
    }
}
