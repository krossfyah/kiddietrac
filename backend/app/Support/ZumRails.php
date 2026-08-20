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

    public static function configured(): bool
    {
        $c = config('services.zumrails');

        return ! empty($c['base_url']) && ! empty($c['username']) && ! empty($c['password']);
    }

    /** A bearer token, cached. Null when unconfigured or the exchange fails. */
    public static function token(bool $fresh = false): ?string
    {
        if (! self::configured()) {
            return null;
        }
        if ($fresh) {
            Cache::forget(self::TOKEN_KEY);
        }

        return Cache::remember(self::TOKEN_KEY, self::TOKEN_TTL, function () {
            $c = config('services.zumrails');
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
    public static function call(string $method, string $path, array $payload = []): ?array
    {
        if (! self::configured()) {
            return null;
        }

        $attempt = function (?string $token) use ($method, $path, $payload) {
            $url = rtrim(config('services.zumrails.base_url'), '/').'/'.ltrim($path, '/');
            $req = Http::timeout(30)->withToken((string) $token)->asJson();

            return strtoupper($method) === 'GET'
                ? $req->get($url, $payload)
                : $req->send(strtoupper($method), $url, ['json' => $payload]);
        };

        try {
            $res = $attempt(self::token());
            if ($res->status() === 401) {
                $res = $attempt(self::token(true));
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
    public static function userIdFor(int $userId): ?string
    {
        $existing = DB::table('zum_users')->where('user_id', $userId)->value('zum_user_id');
        if ($existing) {
            return $existing;
        }
        if (! self::configured()) {
            return null;
        }

        $u = DB::table('users')->where('id', $userId)->first(['first_name', 'last_name', 'email', 'phone']);
        if (! $u || ! $u->email) {
            return null;
        }

        $res = self::call('POST', '/api/user', [
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
        if (! $row || ! $row->zum_transaction_id || ! self::configured()) {
            return false;
        }
        if (in_array($row->status, ['settled', 'cancelled', 'failed'], true)) {
            return false;
        }
        if ($row->method === self::CARD) {
            // Not a limitation of ours: their API does not allow it.
            return false;
        }

        $res = self::call('DELETE', '/api/transaction/'.$row->zum_transaction_id);
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
     * Refund a settled payment.
     *
     * NOT IMPLEMENTED, on purpose. Zum's transaction reference documents create, get,
     * filter, delete and a credit-card authorisation completion — no refund endpoint.
     * Reversal appears only as an event name, which is not enough to build against.
     *
     * Guessing the path, verb or body of a refund call in a payments integration is the
     * one thing worth refusing outright: a wrong guess either silently does nothing while
     * a family is told they were refunded, or moves money twice. Confirm the endpoint
     * with Zum, then this becomes a few lines.
     */
    public static function refund(int $rowId, ?float $amount = null): never
    {
        throw new \RuntimeException(
            'Zum refunds are not implemented: their API reference documents no refund '
            .'endpoint. Confirm the reversal endpoint with Zum before enabling this.'
        );
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
        if (! self::configured() || $amount <= 0) {
            return null;
        }
        $zumUser = self::userIdFor($userId);
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

        $res = self::call('POST', '/api/transaction', [
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
