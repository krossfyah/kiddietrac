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
    /**
     * Why the last call failed.
     *
     * call() returns null on any non-2xx, which tells a caller THAT something went
     * wrong and nothing about what — so a rejected payment was being filed with
     * last_response 'null'. Zum's message ("Your account is not active", a validation
     * complaint about a Memo, a declined instruction) is the whole diagnosis, and
     * losing it means the only way to find out why a parent's payment failed is to go
     * digging in the log for the right minute. Kept here for the caller that is about
     * to record the failure; overwritten by every call, never read speculatively.
     */
    private static ?array $lastError = null;

    public static function lastError(): ?array
    {
        return self::$lastError;
    }

    private const TOKEN_KEY = 'zumrails:token';
    private const TOKEN_TTL = 3000;          // 50 minutes; their token lasts 60.

    /** The Canadian methods we support. Their full set also includes VisaDirect,
     *  PrepaidCard and CreditCardIssuance, which we deliberately do not. */
    public const EFT = 'Eft';
    public const CARD = 'CreditCard';
    /* Interac e-Transfer, both directions: request money from a parent
       (AccountsReceivable) and send money out (AccountsPayable). It settles
       asynchronously and can land in InReview, both of which the webhook already
       handles — this only names the method so it can be asked for. */
    public const INTERAC = 'Interac';

    /** Methods this class will send. Anything else is refused rather than passed
     *  through to Zum to be rejected with a less helpful message. */
    public const METHODS = [self::EFT, self::CARD, self::INTERAC];

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

    /**
     * A phone number in the only shape Zum accepts: digits, at most ten.
     *
     * We store phones for display -- "(416) 989-2621" -- and sending that verbatim
     * failed EVERY call with "Phone number can have maximum 10 digits", which meant
     * no Zum user, and therefore no payment, refund or payout, for the 48 of our 80
     * users whose number is stored formatted.
     *
     * A leading country code is dropped rather than truncated: truncating turns a
     * correct number into a different, valid-looking one. Anything that is still not
     * ten digits is sent empty -- Zum treats this field as optional, so omitting it
     * costs nothing, while a malformed one costs the whole transaction.
     */
    public static function phoneForZum($raw): string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strlen($d) === 11 && $d[0] === '1') {
            $d = substr($d, 1);
        }

        return strlen($d) === 10 ? $d : '';
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
                $body = mb_substr((string) $res->body(), 0, 1000);
                $decoded = json_decode($body, true);
                self::$lastError = [
                    'path' => $path,
                    'status' => $res->status(),
                    // Their errors arrive as responseException.exceptionMessage; the raw
                    // body is kept too, for the shapes that do not.
                    'message' => $decoded['responseException']['exceptionMessage']
                        ?? ($decoded['message'] ?? null),
                    'body' => $body,
                    'at' => now()->toDateTimeString(),
                ];
                Log::warning('zumrails call failed', [
                    'path' => $path, 'status' => $res->status(),
                    'body' => mb_substr($body, 0, 400),
                ]);

                return null;
            }

            self::$lastError = null;

            return $res->json() ?: [];
        } catch (\Throwable $e) {
            self::$lastError = [
                'path' => $path, 'status' => 0,
                'message' => $e->getMessage(), 'body' => null,
                'at' => now()->toDateTimeString(),
            ];
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
            'PhoneNumber' => self::phoneForZum($u->phone ?? ''),
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

    /* ============================================================
     * ZUM CONNECT — adding a card or bank profile in Zum's own UI
     * ============================================================ */

    /** Their CDN, per environment. The SDK is loaded from Zum, never bundled. */
    public const CONNECT_SDK = [
        'sandbox'    => 'https://sandbox-cdn.zumrails.com/sandbox/zumsdk.js',
        'production' => 'https://cdn.zumrails.com/production/zumsdk.js',
    ];

    /**
     * A short-lived token for the browser SDK.
     *
     * Not a secret worth guarding like a password — it is customer-scoped, expires
     * within the hour, and can only be used to open Zum's own onboarding UI. It is
     * still handed only to a signed-in parent for their own session.
     *
     * @param  array  $allow  which rails to offer; defaults to everything enabled.
     * @return array|null  ['token' => …, 'expires_at' => …, 'sdk' => …, 'sandbox' => bool]
     */
    public static function connectToken(int $agencyId, int $userId, array $allow = []): ?array
    {
        if (! self::configured($agencyId)) {
            return null;
        }

        $allow = $allow + [
            'allowEft' => true,
            'allowInterac' => true,
            'allowVisaDirect' => true,
            'allowCreditCard' => true,
        ];

        $res = self::call($agencyId, 'POST', 'api/connect/createtoken', [
            'ConnectTokenType' => 'AddPaymentProfile',
            'Configuration' => [
                'allowEft' => (bool) $allow['allowEft'],
                'allowInterac' => (bool) $allow['allowInterac'],
                'allowVisaDirect' => (bool) $allow['allowVisaDirect'],
                'allowCreditCard' => (bool) $allow['allowCreditCard'],
            ],
            /* Carried for their audit trail and support tickets. Zum does not read it
               back on the user record, so it is NOT relied on for identity — see
               linkConnected(). */
            'clientUserId' => 'kt-user-' . $userId,
        ]);

        $r = $res['result'] ?? null;
        if (! is_array($r) || empty($r['Token'])) {
            return null;
        }

        $mode = (string) (DB::table('agency_payment_providers')
            ->where('agency_id', $agencyId)->where('provider', 'zumrails')->value('mode') ?: 'sandbox');
        $sandbox = $mode !== 'production' && $mode !== 'live';

        return [
            'token' => (string) $r['Token'],
            'expires_at' => $r['ExpirationUTC'] ?? null,
            'sdk' => $sandbox ? self::CONNECT_SDK['sandbox'] : self::CONNECT_SDK['production'],
            'sandbox' => $sandbox,
        ];
    }

    /**
     * Attach the profile a parent just created in Connect to their account.
     *
     * NEVER TRUST THE BROWSER HERE. onSuccess hands the page a Zum userId and a page
     * can post whatever it likes; an unchecked write would let one parent claim
     * another family's payment profile and then charge it. Two checks, both required:
     *
     *   1. the id resolves to a real Zum user whose EMAIL matches the account making
     *      the claim, and
     *   2. the id is not already mapped to a different local user.
     *
     * @return string|null  null on success, otherwise a message safe to show the payer
     */
    public static function linkConnected(int $agencyId, int $userId, string $zumUserId): ?string
    {
        $zumUserId = trim($zumUserId);
        if ($zumUserId === '' || ! preg_match('/^[0-9a-f-]{16,64}$/i', $zumUserId)) {
            return 'That payment profile could not be read. Please try again.';
        }

        // Already ours? Nothing to do, and saying so is not an error.
        $owner = DB::table('zum_users')->where('zum_user_id', $zumUserId)->value('user_id');
        if ($owner && (int) $owner === $userId) {
            self::forgetMethods($userId);

            return null;
        }
        if ($owner) {
            Log::warning('zum connect: profile already claimed', [
                'zum_user' => $zumUserId, 'claimed_by' => $owner, 'claimant' => $userId,
            ]);

            return 'That payment profile is already attached to another account. '
                 . 'Please contact your centre.';
        }

        $remote = self::call($agencyId, 'GET', 'api/user/' . $zumUserId);
        $r = $remote['result'] ?? $remote;
        if (! is_array($r) || empty($r['Id'])) {
            return 'We could not confirm that payment profile with our provider. Please try again.';
        }

        $ours = DB::table('users')->where('id', $userId)->value('email');
        $theirs = (string) ($r['Email'] ?? '');
        if ($ours === null || $theirs === '' || mb_strtolower(trim($theirs)) !== mb_strtolower(trim((string) $ours))) {
            /* The email typed into Connect is not the one on this account. Refused
               rather than linked: attaching a stranger's card to this parent is the
               one outcome that must not happen. */
            Log::warning('zum connect: email mismatch, link refused', [
                'user' => $userId, 'zum_user' => $zumUserId,
            ]);

            return 'The email on that payment profile does not match your account. '
                 . 'Please use ' . $ours . ' when adding a payment method, or contact your centre.';
        }

        /* ONE row per user, replaced — not appended.

           Connect always creates a NEW Zum user (their token cannot be scoped to an
           existing one), so a parent adding a second card used to get a second row
           here. userIdFor() reads the FIRST row, so the new card saved, showed as on
           file, and every charge went on the old one. Silent, and about money.

           Latest wins: adding a card means "use this one". The superseded Zum user is
           left in place at Zum — it may carry transaction history, and orphaning that
           to tidy a row is a bad trade. */
        DB::transaction(function () use ($userId, $zumUserId) {
            DB::table('zum_users')->where('user_id', $userId)->delete();
            DB::table('zum_users')->insert([
                'user_id' => $userId,
                'zum_user_id' => $zumUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        self::forgetMethods($userId);

        return null;
    }

    /**
     * Put a parent's bank account on file with Zum, so EFT can be collected from it.
     *
     * DELIBERATELY NOT STORED HERE. The numbers are forwarded to Zum and forgotten —
     * this portal keeps no copy, not even encrypted, because the safest place for a
     * bank account it never needs to read again is somebody else's vault. What is on
     * file is read back from Zum by methodsOnFile() when it needs to be shown.
     *
     * Canada holds ONE bank account per user, replaced with a PATCH, rather than the
     * collection of payment instruments the US side uses.
     *
     * @return string|null  null on success, otherwise a message safe to show the payer
     */
    public static function saveBankAccount(int $userId, string $institution, string $transit, string $account): ?string
    {
        /* Shape first, then whether we can send it. A typo in a transit number is the
           payer's problem to fix and they should hear about it either way; "your centre
           is not set up" in answer to a mistyped digit sends them to ask the office
           about something that was never the obstacle. */
        $institution = preg_replace('/\D/', '', $institution) ?? '';
        $transit = preg_replace('/\D/', '', $transit) ?? '';
        $account = preg_replace('/\D/', '', $account) ?? '';

        /* Checked here rather than left to Zum: these are the shapes every Canadian
           bank uses, and a clear message now beats a rejected transaction later. */
        if (strlen($institution) !== 3) {
            return 'The institution number is the 3 digits that identify your bank.';
        }
        if (strlen($transit) !== 5) {
            return 'The transit number is the 5 digits that identify your branch.';
        }
        if (strlen($account) < 5 || strlen($account) > 12) {
            return 'That account number does not look right — it is usually 7 to 12 digits.';
        }

        $agencyId = self::agencyOf($userId);
        if (! $agencyId || ! self::configured($agencyId)) {
            return 'Bank payments are not set up for your centre yet.';
        }

        $zumUser = self::userIdFor($agencyId, $userId);
        if (! $zumUser) {
            return 'We could not set up your payment profile. Please tell your centre.';
        }

        $res = self::call($agencyId, 'PATCH', '/api/user/UpdateBankAccountInformation/' . $zumUser, [
            'InstitutionNumber' => $institution,
            'TransitNumber' => $transit,
            'AccountNumber' => $account,
        ]);
        if ($res === null) {
            return 'Your bank could not be saved just now. Please try again shortly.';
        }

        self::forgetMethods($userId);

        return null;
    }

    /**
     * What Zum holds for this payer — read from Zum, never from our own tables.
     *
     * Cached briefly because the billing screen asks on every render and the answer
     * changes only when somebody saves a payment method.
     *
     * @return array{bank:bool, card:bool, bank_hint:?string, card_hint:?string}
     */
    public static function methodsOnFile(int $userId): array
    {
        $empty = ['bank' => false, 'card' => false, 'bank_hint' => null,
                  'card_hint' => null, 'card_brand' => null, 'card_kind' => null];
        $agencyId = self::agencyOf($userId);
        if (! $agencyId || ! self::configured($agencyId)) {
            return $empty;
        }

        return Cache::remember(self::methodsKey($userId), 60, function () use ($agencyId, $userId, $empty) {
            $zumUser = DB::table('zum_users')->where('user_id', $userId)->value('zum_user_id');
            if (! $zumUser) {
                return $empty;
            }
            $res = self::call($agencyId, 'GET', '/api/user/' . $zumUser);
            $u = $res['result'] ?? $res ?? [];
            if (! is_array($u)) {
                return $empty;
            }

            /* Their payload has moved shape between versions and differs by country, so
               each fact is looked for in the places it has been known to live rather
               than assuming one. A missing key means "nothing on file", never an error. */
            $bank = $u['BankAccountInformation'] ?? $u['bankAccountInformation'] ?? null;
            $card = $u['CreditCardInformation'] ?? $u['creditCardInformation'] ?? null;

            $last = function ($v, int $n) {
                $v = preg_replace('/\D/', '', (string) $v) ?? '';
                return $v === '' ? null : substr($v, -$n);
            };

            $cardNumber = $card ? (string) ($card['Number'] ?? $card['number'] ?? '') : '';

            return [
                'bank' => ! empty($bank),
                'card' => ! empty($card),
                'bank_hint' => $bank ? $last($bank['AccountNumber'] ?? $bank['accountNumber'] ?? '', 3) : null,
                'card_hint' => $card ? $last($cardNumber, 4) : null,
                /* So the screen can show the right brand mark instead of a generic
                   rectangle. Taken from Zum where they send it, inferred from the
                   leading digits where they do not. */
                /* BrandName is what Zum actually sends ("MasterCard", "Visa"). The
                   others are kept because their payload has moved between versions,
                   and the digit fallback stays for the rare unlabelled case — it
                   cannot help here, since Number comes back masked as ************0077. */
                'card_brand' => $card
                    ? self::cardBrand($card['BrandName'] ?? $card['brandName']
                        ?? $card['CardBrand'] ?? $card['cardBrand']
                        ?? $card['Brand'] ?? $card['brand'] ?? null, $cardNumber)
                    : null,
                /* Debit or credit — a parent with two cards on file needs more than
                   the last four to tell them apart. */
                'card_kind' => $card
                    ? (stripos((string) ($card['CardIndicator'] ?? ''), 'debit') !== false ? 'debit' : 'credit')
                    : null,
            ];
        });
    }

    /**
     * Normalise a card brand to one of our known keys.
     *
     * Their payload spells it several ways across versions and countries, so the
     * label is matched loosely and, failing that, read off the leading digits — the
     * IIN ranges are fixed and are what every payment form uses to draw the logo
     * before the card is even submitted.
     */
    public static function cardBrand(?string $label, string $number = ''): ?string
    {
        $l = mb_strtolower(trim((string) $label));
        if ($l !== '') {
            if (str_contains($l, 'visa')) { return 'visa'; }
            if (str_contains($l, 'master')) { return 'mastercard'; }
            if (str_contains($l, 'amex') || str_contains($l, 'american')) { return 'amex'; }
            if (str_contains($l, 'discover')) { return 'discover'; }
            if (str_contains($l, 'jcb')) { return 'jcb'; }
            if (str_contains($l, 'diners')) { return 'diners'; }
            if (str_contains($l, 'unionpay') || str_contains($l, 'union pay')) { return 'unionpay'; }
        }

        $d = preg_replace('/\D/', '', $number) ?? '';
        if ($d === '') { return null; }
        if ($d[0] === '4') { return 'visa'; }
        $two = (int) substr($d, 0, 2);
        $four = (int) substr($d, 0, 4);
        if ($two >= 51 && $two <= 55) { return 'mastercard'; }
        if ($four >= 2221 && $four <= 2720) { return 'mastercard'; }
        if ($two === 34 || $two === 37) { return 'amex'; }
        if ($two === 65 || $four === 6011) { return 'discover'; }
        if ($two === 35) { return 'jcb'; }
        if ($two === 36 || $two === 38 || ($four >= 3000 && $four <= 3059)) { return 'diners'; }
        if ($two === 62) { return 'unionpay'; }

        return null;
    }

    private static function methodsKey(int $userId): string
    {
        return 'zumrails:methods:' . $userId;
    }

    /** Called after anything that changes what is on file. */
    public static function forgetMethods(int $userId): void
    {
        try { Cache::forget(self::methodsKey($userId)); } catch (\Throwable $e) {}
    }

    /**
     * Charge a parent's card. Acceptance is AccountsReceivable + CreditCard — the same
     * transaction endpoint, with the direction expressed as a field.
     *
     * NOT REACHABLE FROM THE PORTAL YET, on purpose. Zum's Canadian card API takes a
     * raw card number and CVV server-side, which would put this application in PCI
     * scope; cards are on Stripe, which tokenises in the browser so no card data ever
     * reaches us. This stays as the seam for the day Zum confirms a hosted field or
     * client-side encryption (their CreditCardInformation lists an EncryptedNumber,
     * which is the thread to pull). Nothing here should ever accept a PAN.
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
     * Request money from a parent by Interac e-Transfer.
     *
     * Nothing is settled when this returns. Interac accepts the request immediately and
     * the money arrives when the parent approves it in their own bank, so the webhook
     * remains the only authority on whether it was paid — and Interac in particular can
     * land in InReview, which is neither.
     */
    public static function requestInterac(int $userId, float $amount, array $links = [], string $comment = ''): ?int
    {
        return self::transact('in', $userId, $amount, self::INTERAC, $links, $comment);
    }

    /** Send money out by Interac e-Transfer — a refund, or a payment to a provider. */
    public static function sendInterac(int $userId, float $amount, array $links = [], string $comment = ''): ?int
    {
        return self::transact('out', $userId, $amount, self::INTERAC, $links, $comment);
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
        /* Cards used to be refused here on the grounds that their API did not allow
           it. That was written before card onboarding existed, so nobody could test
           it. Tested 2026-09-03 in sandbox: DELETE api/transaction/{id} on a
           CreditCard charge returns 200 and Zum's record moves to Cancelled.

           What actually governs a void is whether the money has moved, and the
           status check above already enforces that — an unsettled charge cancels, a
           settled one must be refunded. Refusing cards only forced a director to let
           a mistaken charge settle and then refund it, which a family sees as a
           charge AND a credit on their statement instead of nothing at all. */

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

        /* Their refund reverses a charge back to the card it was taken from. An Interac
           e-Transfer or an EFT was never a charge, and the sandbox says so outright:
           HTTP 400 "Payment method not supported for refund." Refusing here — before a
           row is written — beats letting it fail at Zum, which left a 'failed' refund
           reserving part of the payment and no explanation for the person who clicked.

           Money does go back to those families; it goes as a NEW outbound payment, which
           is sendInterac() and a different decision. */
        if ($row->method !== self::CARD) {
            // Their API tokens are not English: "Eft" and "Interac" both need help.
            $label = [
                'Interac' => 'An Interac e-Transfer',
                'Eft' => 'An EFT payment',
                'Ach' => 'An ACH payment',
            ][$row->method] ?? ('A ' . $row->method . ' payment');

            throw new \InvalidArgumentException(
                $label . ' cannot be reversed — only card payments can be refunded. '
                . 'Send the money back as a new Interac transfer instead.'
            );
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
            // Same reason as the transaction above: refunds settle through the same
            // webhook, so they need the same scope on them.
            'agency_id' => self::agencyOf((int) $row->user_id),
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
            // On failure $res is null, and json_encode(null) is the string "null" — a row
            // that says it failed and cannot say why. Keep Zum's own message instead.
            'last_response' => json_encode($res ?? self::lastError()),
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
    /**
     * The statement memo, which Zum requires on EVERY transaction.
     *
     * It was not being sent at all — only Comment was, which is a different field —
     * so every transaction this class created would have been rejected the moment real
     * credentials were in place. Their rule is narrow and worth honouring exactly:
     * at most 15 characters, and only letters, numbers, dash, space and underscore.
     * Anything else (an apostrophe in a family name, an accented character, the dollar
     * sign in an amount) is stripped rather than sent and refused.
     */
    private static function memo(array $links, string $fallback = 'KiddieTrac'): string
    {
        $raw = $fallback;
        if (! empty($links['invoice_id'])) {
            $raw = 'INV ' . $links['invoice_id'];
        } elseif (! empty($links['payroll_document_id'])) {
            $raw = 'PAY ' . $links['payroll_document_id'];
        }
        $clean = preg_replace('/[^A-Za-z0-9 _-]/', '', $raw) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        return mb_substr($clean !== '' ? $clean : 'KiddieTrac', 0, 15);
    }

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
        if (! in_array($method, self::METHODS, true)) {
            Log::warning('ZumRails: refusing an unsupported transaction method', [
                'method' => $method, 'agency' => $agencyId,
            ]);

            return null;
        }
        $zumUser = self::userIdFor($agencyId, $userId);
        if (! $zumUser) {
            return null;
        }

        $rowId = DB::table('zum_transactions')->insertGetId([
            /* Whose money this is. Derivable from the payer's roles, but the settlement
               webhook has to check it on every callback and was never going to do that
               join — so it is a column, and the guard is a WHERE. */
            'agency_id' => $agencyId,
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

        $payload = [
            'ZumRailsType' => $direction === 'in' ? 'AccountsReceivable' : 'AccountsPayable',
            'TransactionMethod' => $method,
            'Amount' => round($amount, 2),
            'UserId' => $zumUser,
            // Required by Zum on every transaction; see memo() for the character rules.
            'Memo' => self::memo($links),
            'Comment' => $comment !== '' ? $comment : null,
        ];

        /* The agency's wallet: what money is collected into, and paid out of. Sent
           whenever it is configured — their quickstart includes it on the canonical
           EFT example, and a transaction "involving wallet" needs it. */
        $wallet = trim((string) (self::cfg($agencyId)['wallet_id'] ?? ''));
        if ($wallet !== '') {
            $payload['WalletId'] = $wallet;
        }

        /* A saved card, where one is being charged. Zum identifies a stored instrument
           rather than taking card numbers through this API, which is also why nothing
           here ever handles a PAN. */
        if ($method === self::CARD && ! empty($links['payment_instrument_id'])) {
            $payload['PaymentInstrumentId'] = $links['payment_instrument_id'];
        }

        /* How Interac reaches the person — Zum takes email or sms. Left unset so their
           account default applies unless an agency has expressed a preference. */
        if ($method === self::INTERAC && ! empty($links['interac_channel'])) {
            $payload['InteracNotificationChannel'] = $links['interac_channel'];
        }

        $res = self::call($agencyId, 'POST', '/api/transaction', $payload);

        $zumTxnId = $res['Id'] ?? $res['result']['Id'] ?? null;

        /* THE STATUS IS IN THE CREATE RESPONSE, and it was being ignored.

           Zum answers a declined or expired card with an Id AND
           "TransactionStatus": "Failed". Recording 'submitted' because an id came
           back meant transact() returned a row, the controller called it accepted,
           and the parent was told "Paid" for a charge that had already failed.
           Verified with their documented simulation (comment CreditCardExpiredCard). */
        $remoteStatus = (string) ($res['TransactionStatus']
            ?? $res['result']['TransactionStatus'] ?? '');
        $failed = in_array(mb_strtolower($remoteStatus), ['failed', 'declined', 'rejected'], true);

        DB::table('zum_transactions')->where('id', $rowId)->update([
            'zum_transaction_id' => $zumTxnId,
            /* No id back means it never reached them; a terminal status means it
               reached them and was refused. Both are 'failed' — nothing should sit
               waiting on a webhook that is never coming. Completed stays 'submitted'
               on purpose: settlement is what writes the ledger row, and skipping
               that path here would credit an invoice with no payment behind it. */
            'status' => (! $zumTxnId || $failed) ? 'failed' : 'submitted',
            // On failure $res is null, so record WHY rather than the word "null".
            'last_response' => json_encode($res ?? self::lastError()),
            'updated_at' => now(),
        ]);

        if ($failed) {
            self::$lastError = [
                'path' => '/api/transaction',
                'status' => 200,
                'message' => trim((string) ($res['FailedTransactionEvent']['Description']
                    ?? $res['ErrorMessage'] ?? $res['StatusMessage'] ?? ''))
                    ?: 'The payment was declined.',
                'body' => null,
                'at' => now()->toDateTimeString(),
            ];
            Log::warning('zum transaction refused at create', [
                'row' => $rowId, 'zum' => $zumTxnId, 'status' => $remoteStatus,
            ]);

            /* Zum answers a decline synchronously and then sends NO webhook — there is
               nothing pending from their side — so the notifier hooked into the webhook
               would never fire for the commonest failure of all. Told here instead. */
            try {
                $fresh = DB::table('zum_transactions')->where('id', $rowId)->first();
                if ($fresh) {
                    \App\Services\PaymentNotifier::failed($fresh, (string) (self::$lastError['message'] ?? ''));
                }
            } catch (\Throwable $e) {
                Log::warning('decline notify failed', ['row' => $rowId, 'error' => $e->getMessage()]);
            }

            // NULL, so every caller reports the failure it already knows how to report.
            return null;
        }

        return $rowId;
    }
}
