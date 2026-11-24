<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\EducatorPoints;
use App\Support\GiftCardProvider;
use App\Support\TangoProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Educator points & rewards (2026-10-20).
 *
 *  GET    /educator-points/me              an earner's own month, their awards, the centre leaderboard
 *  GET    /educator-points                 directors/admins: every earner's month, with awards
 *  POST   /educator-points/awards          give a bonus / payout / gift card for a month
 *  DELETE /educator-points/awards/{id}     cancel a bonus not yet paid in a pay run
 *  GET|PUT /educator-points/settings       point values, daily caps, suggested-award rate
 *  POST   /educator-points/awards/{id}/refresh  re-check a Giftbit gift now
 *  GET|PUT /educator-points/giftbit        this agency's own Giftbit account (agency admin)
 *  POST   /educator-points/giftbit/test    GET /ping with it
 *  GET    /educator-points/giftbit/brands[/{code}]  the catalogue for the Award dialog
 *
 * WHO SEES WHAT. An educator sees their own points, what earned them, their own awards
 * (gift card code included, behind their sign-in) and a leaderboard of points at their
 * centre: points only, never money. Money is between the educator and the people who
 * award it. A centre director sees and rewards the staff of their own centres, but not
 * themselves or another director: those awards are the agency admin's.
 */
class EducatorRewardsController extends Controller
{
    use ResolvesCentreContext;

    private const METHODS = [
        'payroll_bonus' => 'Bonus in the next pay',
        'payout' => 'Payout',
        'gift_card_manual' => 'Gift card',
        'gift_card_provider' => 'Gift card',
        'thank_you' => 'Thank-you award',   // ThankYouAward: a signed certificate email, no money (2026-10-30)
    ];

    private function roles(Request $request): array
    {
        return \App\Support\AgencyRole::names($request);
    }

    private function isAdmin(Request $request): bool
    {
        return (bool) array_intersect($this->roles($request), ['agency_admin', 'platform_admin']);
    }

    private function isDirector(Request $request): bool
    {
        return in_array('centre_director', $this->roles($request), true);
    }

    /** null = the whole agency; else the director's centres. */
    private function scope(Request $request, int $agencyId): ?array
    {
        if ($this->isAdmin($request)) {
            return null;
        }

        return \App\Support\CentreScope::ids($request, $agencyId) ?? [];
    }

    /* ───────────── the educator's own ───────────── */

    public function me(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);
        $uid = (int) $request->user()->id;
        $cfg = EducatorPoints::settings($agencyId);
        $all = EducatorPoints::earners($agencyId);
        if (! isset($all[$uid])) {
            /* A super admin previewing an earner's view ("View as" Educator / Home visitor / Director) is not an
               earner, but the preview should show the screen as that person sees it: count them as an earner with
               nothing earned, across every centre, so the ring and the leaderboard draw. Nobody else gets in. */
            $view = (string) $request->header('X-View-As-Role', '');
            $u = $request->user();
            $previewing = in_array($view, ['educator', 'home_visitor', 'centre_director'], true)
                && in_array('platform_admin', \App\Support\AgencyRole::names($request), true);
            if (! $previewing) {
                return response()->json(['message' => 'Points are for educators, home visitors and directors.'], 403);
            }
            $all[$uid] = ['user_id' => $uid, 'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'You',
                'role' => $view, 'centre_ids' => DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($v) => (int) $v)->all()];
        }
        $month = EducatorPoints::month($agencyId, $request->query('month'));
        $me = $all[$uid];

        // The leaderboard: people sharing a centre with me (or, without a centre, others without one).
        $peers = array_filter($all, function ($e) use ($me) {
            return $me['centre_ids'] ? (bool) array_intersect($e['centre_ids'], $me['centre_ids']) : ! $e['centre_ids'];
        });
        $res = EducatorPoints::compute($agencyId, $month, $cfg['leaderboard'] ? $peers : [$uid => $me]);
        $mine = $res[$uid];

        $board = [];
        if ($cfg['leaderboard']) {
            $list = array_values($res);
            usort($list, fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['name'], $b['name']));
            $rank = 0; $prev = null; $i = 0;
            foreach ($list as $r) {
                $i++;
                if ($r['total'] !== $prev) { $rank = $i; $prev = $r['total']; }
                $board[] = ['rank' => $rank, 'name' => $r['name'], 'points' => $r['total'], 'me' => $r['user_id'] === $uid];
            }
        }

        /* WHERE I STAND IN THE WHOLE AGENCY (2026-10-23, Anthony: "stats should be all of the agency
           ranking"). For the ring on Today (?agency=1): my place, out of how many, and the top
           score — numbers only, no one else's name. Only when the leaderboard is on. */
        $agencyRank = null;
        if ($cfg['leaderboard'] && $request->boolean('agency')) {
            $resAll = EducatorPoints::compute($agencyId, $month, $all);
            $tot = array_values(array_map(fn ($r) => (int) $r['total'], $resAll));
            rsort($tot);
            $myT = (int) ($resAll[$uid]['total'] ?? 0);
            $agencyRank = ['rank' => 1 + count(array_filter($tot, fn ($t) => $t > $myT)), 'of' => count($tot), 'top' => $tot[0] ?? 0];
        }

        $this->refreshPending(DB::table('educator_rewards')->where('agency_id', $agencyId)->where('user_id', $uid)
            ->where('method', 'gift_card_provider')->whereIn('status', ['requested', 'awaiting_funds', 'sent'])->limit(5)->get());
        $awards = DB::table('educator_rewards')->where('agency_id', $agencyId)->where('user_id', $uid)
            ->where('status', '!=', 'cancelled')->orderByDesc('id')->limit(36)->get()
            ->map(fn ($a) => $this->awardOut($a, true))->values();

        return response()->json([
            'month' => $month,
            'enabled' => $cfg['enabled'],
            'activities' => EducatorPoints::activityList($cfg),
            'me' => ['total' => $mine['total'], 'by' => $mine['by']],
            'leaderboard' => $cfg['leaderboard'] ? $board : null,
            'agency_rank' => $agencyRank,
            'awards' => $awards,
        ]);
    }

    /* ───────────── directors & admins ───────────── */

    public function index(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $scope = $this->scope($request, $agencyId);
        $cfg = EducatorPoints::settings($agencyId);
        $month = EducatorPoints::month($agencyId, $request->query('month'));
        $earners = EducatorPoints::earners($agencyId, $scope);
        if ($request->filled('centre_id')) {
            $c = (int) $request->query('centre_id');
            $earners = array_filter($earners, fn ($e) => in_array($c, $e['centre_ids'], true));
        }
        $res = EducatorPoints::compute($agencyId, $month, $earners);
        $this->refreshPending(DB::table('educator_rewards')->where('agency_id', $agencyId)->where('month', $month)
            ->whereIn('user_id', array_keys($res) ?: [0])->where('method', 'gift_card_provider')
            ->whereIn('status', ['requested', 'awaiting_funds', 'sent'])->limit(10)->get());
        $awards = DB::table('educator_rewards')->where('agency_id', $agencyId)->where('month', $month)
            ->whereIn('user_id', array_keys($res) ?: [0])->where('status', '!=', 'cancelled')
            ->orderBy('id')->get()->groupBy('user_id');
        $me = (int) $request->user()->id;
        $admin = $this->isAdmin($request);
        $centreNames = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id');

        $rows = array_values(array_map(function ($r) use ($awards, $cfg, $me, $admin, $centreNames) {
            $mine = $awards[$r['user_id']] ?? collect();
            return [
                'user_id' => $r['user_id'], 'name' => $r['name'], 'role' => $r['role'],
                'centres' => array_values(array_filter(array_map(fn ($c) => $centreNames[$c] ?? null, array_unique($r['centre_ids'])))),
                'total' => $r['total'], 'by' => $r['by'], 'days' => (object) ($r['days'] ?? []),
                'suggested' => EducatorPoints::suggest($cfg, $r['total']),
                'awards' => $mine->map(fn ($a) => $this->awardOut($a, false))->values(),
                'can_award' => $admin || ($r['user_id'] !== $me && $r['role'] !== 'centre_director'),
            ];
        }, $res));
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['name'], $b['name']));

        $centres = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]))->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'month' => $month,
            'settings' => $cfg,
            'activities' => EducatorPoints::activityList($cfg),
            'rows' => $rows,
            'centres' => $centres,
            'can_edit_settings' => $admin,
            'provider' => $this->providerSummary($agencyId, $cfg['currency']),
            'methods' => self::METHODS,
            'auto' => $this->autoSummary($agencyId),
        ]);
    }

    /** "Automatic awards: On · …" for the awards screens; the settings themselves live in Settings → Educator awards. */
    private function autoSummary(int $agencyId): array
    {
        $c = \App\Support\ThankYouAuto::settings($agencyId);
        return ['enabled' => $c['enabled'], 'mode' => $c['mode'],
            'schedule_label' => \App\Support\ThankYouAuto::scheduleLabel($c), 'rule_label' => \App\Support\ThankYouAuto::ruleLabel($c),
            'pending' => (int) DB::table('thank_you_auto_drafts')->where('agency_id', $agencyId)->where('status', 'pending')->count()];
    }

    /**
     * GET /educator-points/history?from=YYYY-MM&to=YYYY-MM&centre_id=&user_id=&cancelled=1
     * Who was awarded, month by month, permanently (App\Support\EducatorAwardHistory). Admins see the agency; a
     * director sees awards to people at their own centres. Gift card codes and links are never included.
     */
    public function history(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $mon = ['regex:/^\d{4}-(0[1-9]|1[0-2])$/'];
        $f = $request->validate(['from' => array_merge(['nullable'], $mon), 'to' => array_merge(['nullable'], $mon),
            'centre_id' => 'nullable|integer', 'user_id' => 'nullable|integer', 'cancelled' => 'nullable|boolean']);
        if (! empty($f['from']) && ! empty($f['to']) && $f['from'] > $f['to']) {
            [$f['from'], $f['to']] = [$f['to'], $f['from']];
        }
        $scope = $this->scope($request, $agencyId);
        if (! empty($f['centre_id']) && $scope !== null && ! in_array((int) $f['centre_id'], $scope, true)) {
            return response()->json(['message' => 'That centre is not one of yours.'], 403);
        }
        $f['include_cancelled'] = $request->boolean('cancelled');
        $h = \App\Support\EducatorAwardHistory::rows($agencyId, $scope, $f, self::METHODS);
        $centres = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]))->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'rows' => $h['rows'], 'people' => $h['people'], 'centres' => $centres, 'tz' => $h['tz'],
            'filters' => ['from' => $f['from'] ?? null, 'to' => $f['to'] ?? null, 'centre_id' => $f['centre_id'] ?? null,
                'user_id' => $f['user_id'] ?? null, 'cancelled' => $f['include_cancelled']],
            'auto' => $this->autoSummary($agencyId),
            'can_edit_auto' => $this->isAdmin($request),
        ]);
    }

    public function award(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $data = $request->validate([
            'user_id' => 'required|integer',
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount' => 'required|numeric|min:0.01|max:10000',
            'method' => 'required|in:payroll_bonus,payout,gift_card_manual,gift_card_provider',
            'brand' => 'nullable|string|max:120',
            'provider' => 'nullable|in:giftbit,tango',
            'gift_code' => 'nullable|string|max:500',
            'gift_link' => 'nullable|url|max:1000',
            'reference' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:1000',
        ]);
        $current = EducatorPoints::month($agencyId, null);
        if ($data['month'] > $current) {
            return response()->json(['message' => 'That month has not happened yet.'], 422);
        }
        $earners = EducatorPoints::earners($agencyId, $this->scope($request, $agencyId));
        $uid = (int) $data['user_id'];
        if (! isset($earners[$uid])) {
            return response()->json(['message' => 'That person is not on your team.'], 422);
        }
        $me = (int) $request->user()->id;
        if (! $this->isAdmin($request) && ($uid === $me || $earners[$uid]['role'] === 'centre_director')) {
            return response()->json(['message' => 'An agency admin gives awards to directors.'], 403);
        }
        if ($data['method'] === 'gift_card_manual' && empty($data['gift_code']) && empty($data['gift_link'])) {
            return response()->json(['message' => 'Add the gift card code or its link so the educator can use it.'], 422);
        }
        $cfg = EducatorPoints::settings($agencyId);
        $acct = null;
        $prov = null;
        if ($data['method'] === 'gift_card_provider') {
            /* Giftbit or Tango Card (2026-10-20). The dialog says which; an older client that
               does not, gets whichever is connected (Giftbit first, as before). */
            $prov = $data['provider'] ?? (GiftCardProvider::connected($agencyId) ? 'giftbit' : 'tango');
            $P = $this->cardProvider($prov);
            $label = $this->cardLabel($prov);
            $acct = $P::account($agencyId);
            if (! $acct) {
                return response()->json(['message' => $label . ' is not connected for this agency yet. Enter a gift card you bought instead.'], 422);
            }
            $amt = round((float) $data['amount'], 2);
            if ($prov === 'tango' && empty($data['brand'])) {
                return response()->json(['message' => 'Pick the Tango Card gift card to send.'], 422);
            }
            if (! empty($data['brand'])) {
                $brand = $prov === 'tango'
                    ? TangoProvider::brand($acct, (string) $data['brand'], $cfg['currency'])
                    : GiftCardProvider::brand($acct, (string) $data['brand']);
                if (! $brand) {
                    return response()->json(['message' => 'That gift card isn\'t available from ' . $label . '. Pick another.'], 422);
                }
                if ($why = GiftCardProvider::checkValue($brand, $amt, $cfg['currency'])) {
                    return response()->json(['message' => $why], 422);
                }
                $data['brand_name'] = $brand['name'];
            }
            /* Say so BEFORE ordering. Giftbit would accept the order and hold it until the
               account is topped up; a director should know that now, not discover later why
               the educator's card never arrived. */
            $bal = $P::balance($acct, $cfg['currency']);
            if ($bal !== null && $bal['available'] < $amt) {
                return response()->json(['message' => 'Your ' . $label . ' balance is $' . number_format($bal['available'], 2) . ' ' . $cfg['currency']
                    . ', not enough for a $' . number_format($amt, 2) . ' card. Add funds in your ' . $label . ' account first.'], 422);
            }
        }
        $points = EducatorPoints::compute($agencyId, $data['month'], [$uid => $earners[$uid]])[$uid]['total'];
        $status = ['payroll_bonus' => 'pending_payroll', 'payout' => 'paid', 'gift_card_manual' => 'sent', 'gift_card_provider' => 'requested'][$data['method']];

        $id = DB::table('educator_rewards')->insertGetId([
            'agency_id' => $agencyId, 'user_id' => $uid, 'month' => $data['month'], 'points' => $points,
            'amount' => round((float) $data['amount'], 2), 'currency' => $cfg['currency'],
            'method' => $data['method'], 'status' => $status,
            'brand' => $data['brand_name'] ?? ($data['method'] === 'gift_card_provider' && empty($data['brand']) ? 'Their choice' : ($data['brand'] ?? null)),
            'gift_code' => ! empty($data['gift_code']) ? Crypt::encryptString(trim($data['gift_code'])) : null,
            'gift_link' => ! empty($data['gift_link']) ? Crypt::encryptString(trim($data['gift_link'])) : null,
            'provider' => $prov,
            'provider_account' => $acct['key'] ?? null,
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'awarded_by_id' => $me,
            'paid_at' => $status === 'paid' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ] + \App\Support\EducatorAwardHistory::snapshot($agencyId, $uid, $me, 'manual'));

        if ($data['method'] === 'gift_card_provider' && $prov === 'tango') {
            /* EMBEDDED delivery: Tango sends nothing; the card (link and/or code + PIN) comes
               back here, is stored encrypted and is shown only to the educator. */
            $orderId = TangoProvider::orderId($agencyId, $id);
            $sent = TangoProvider::order($acct, $orderId, (string) $data['brand'], (float) $data['amount'], 'KiddieTrac educator reward ' . $data['month']);
            $raw = $sent['raw'] ?? null;
            DB::table('educator_rewards')->where('id', $id)->update([
                'status' => $sent['ok'] ? $sent['status'] : 'failed',
                'provider_order_id' => $orderId,
                'provider_campaign_uuid' => $sent['order_id'] ?? null,
                'provider_gift_uuid' => $sent['line_id'] ?? null,
                'provider_status' => $sent['ok'] ? $sent['info'] : 'error',
                'provider_checked_at' => now(),
                'provider_response' => is_array($raw) ? TangoProvider::safeRaw($raw + ['_instructions' => $sent['instructions'] ?? null]) : null,
                'gift_link' => ! empty($sent['link']) ? Crypt::encryptString($sent['link']) : null,
                'gift_code' => ! empty($sent['code']) ? Crypt::encryptString($sent['code']) : null,
                'updated_at' => now(),
            ]);
            if (! $sent['ok']) {
                $this->audit($request, $agencyId, 'educator_reward.failed', $id, ['summary' => 'Tango Card gift card could not be sent: ' . $sent['error'], 'order_id' => $orderId]);

                return response()->json(['message' => $sent['error']], 422);
            }
        } elseif ($data['method'] === 'gift_card_provider') {
            $orderId = GiftCardProvider::orderId($agencyId, $id);
            $sent = GiftCardProvider::order($acct, $orderId, (float) $data['amount'], $cfg['currency'], $data['brand'] ?? null);
            DB::table('educator_rewards')->where('id', $id)->update([
                'status' => $sent['ok'] ? $sent['status'] : 'failed',
                'provider_order_id' => $orderId,
                'provider_campaign_uuid' => $sent['uuid'] ?? null,
                'provider_status' => $sent['ok'] ? $sent['info'] : 'error',
                'provider_checked_at' => now(),
                'provider_response' => $this->safeRaw($sent['raw'] ?? null),
                'gift_link' => ! empty($sent['link']) ? Crypt::encryptString($sent['link']) : null,
                'updated_at' => now(),
            ]);
            if (! $sent['ok']) {
                $this->audit($request, $agencyId, 'educator_reward.failed', $id, ['summary' => 'Giftbit gift card could not be sent: ' . $sent['error'], 'order_id' => $orderId]);

                return response()->json(['message' => $sent['error']], 422);
            }
        }

        $award = DB::table('educator_rewards')->where('id', $id)->first();
        $orderId = $award->provider_order_id;
        $told = $this->tell($agencyId, $award, $request->user());
        $name = $earners[$uid]['name'];
        $this->audit($request, $agencyId, 'educator_reward.created', $id, [
            'summary' => 'Gave ' . $name . ' a ' . strtolower(self::METHODS[$data['method']]) . ' of $' . number_format((float) $data['amount'], 2)
                . ' for ' . $data['month'] . ' (' . $points . ' points).'
                . ($told['emailed'] ? ' Emailed ' . $told['emailed'] . ($told['bcc'] ? ', BCC ' . implode(', ', $told['bcc']) : '') . '.' : ' No email was sent.')
                . ' In-app notice' . ($told['push'] === 'sent' ? ' and push' : '') . ' sent.',
            'user_id' => $uid, 'month' => $data['month'], 'amount' => (float) $data['amount'], 'method' => $data['method'], 'points' => $points,
            'card_provider' => $prov,
            'card_order_id' => $orderId,
            'card_account' => $acct['source'] ?? null,
            // Who was told, named (the summary says it in words too).
            'told' => $told,
        ]);

        return response()->json(['ok' => true, 'award' => $this->awardOut($award, false)], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $a = DB::table('educator_rewards')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $a) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $earners = EducatorPoints::earners($agencyId, $this->scope($request, $agencyId));
        if (! isset($earners[(int) $a->user_id])) {
            return response()->json(['message' => 'Not found'], 404);
        }
        /* Only what has not happened yet can be undone. A payout was paid, a gift card
           was handed over; cancelling either here would make the record lie. */
        if ($a->method === 'gift_card_provider' && $a->provider === 'tango' && in_array($a->status, ['requested', 'sent'], true)) {
            $acct = $this->acctFor($a);
            if (! $acct) {
                return response()->json(['message' => 'The Tango Card account that sent this card is no longer connected, so it cannot be cancelled from here.'], 422);
            }
            if (! $a->provider_gift_uuid) {
                return response()->json(['message' => 'Tango Card is still preparing this card. Try cancelling again in a few minutes.'], 422);
            }
            $c = TangoProvider::cancel($acct, (string) $a->provider_gift_uuid);
            if (! $c['ok']) {
                return response()->json(['message' => $c['error']], 422);
            }
            DB::table('educator_rewards')->where('id', $id)->update(['status' => 'cancelled', 'provider_status' => 'CANCELLED',
                'gift_link' => null, 'gift_code' => null, 'provider_checked_at' => now(), 'updated_at' => now()]);
            $this->audit($request, $agencyId, 'educator_reward.cancelled', $id, [
                'summary' => 'Cancelled a $' . number_format((float) $a->amount, 2) . ' Tango Card gift card for ' . ($earners[(int) $a->user_id]['name'] ?? 'an educator') . ' (' . $a->month . ').',
                'card_provider' => 'tango', 'card_order_id' => $a->provider_order_id, 'line_item' => $a->provider_gift_uuid,
            ]);

            return response()->json(['ok' => true]);
        }
        if ($a->method === 'gift_card_provider' && in_array($a->status, ['requested', 'awaiting_funds', 'sent'], true)) {
            /* A Giftbit card can be cancelled until it is claimed; the money goes back to the
               account that paid. Ask Giftbit first: a card already redeemed cannot come back. */
            $acct = $this->acctFor($a);
            if (! $acct) {
                return response()->json(['message' => 'The Giftbit account that sent this card is no longer connected, so it cannot be cancelled from here.'], 422);
            }
            $g = $a->provider_gift_uuid ? ['uuid' => $a->provider_gift_uuid] : GiftCardProvider::gift($acct, (string) $a->provider_order_id);
            if (! $g || empty($g['uuid'])) {
                return response()->json(['message' => 'Giftbit is still preparing this card. Try cancelling again in a few minutes.'], 422);
            }
            $c = GiftCardProvider::cancel($acct, (string) $g['uuid']);
            if (! $c['ok']) {
                return response()->json(['message' => $c['error'] . ' A card that has been redeemed cannot be cancelled.'], 422);
            }
            DB::table('educator_rewards')->where('id', $id)->update(['status' => 'cancelled', 'provider_status' => 'GIVER_CANCELLED',
                'provider_gift_uuid' => $g['uuid'], 'gift_link' => null, 'provider_checked_at' => now(), 'updated_at' => now()]);
            $this->audit($request, $agencyId, 'educator_reward.cancelled', $id, [
                'summary' => 'Cancelled a $' . number_format((float) $a->amount, 2) . ' Giftbit gift card for ' . ($earners[(int) $a->user_id]['name'] ?? 'an educator') . ' (' . $a->month . ').',
                'giftbit_order_id' => $a->provider_order_id,
            ]);

            return response()->json(['ok' => true]);
        }
        if (! in_array($a->status, ['pending_payroll', 'failed'], true)) {
            return response()->json(['message' => 'Only a bonus still waiting for a pay run, or a gift card from Giftbit or Tango Card not yet redeemed, can be cancelled.'], 422);
        }
        DB::table('educator_rewards')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'educator_reward.cancelled', $id, [
            'summary' => 'Cancelled a $' . number_format((float) $a->amount, 2) . ' bonus for ' . ($earners[(int) $a->user_id]['name'] ?? 'an educator') . ' (' . $a->month . ').',
        ]);

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $cfg = EducatorPoints::settings($agencyId);

        return response()->json(['settings' => $cfg, 'activities' => EducatorPoints::activityList($cfg), 'can_edit' => $this->isAdmin($request)]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && $this->isAdmin($request), 403);
        $in = $request->validate([
            'enabled' => 'nullable|boolean', 'leaderboard' => 'nullable|boolean',
            'currency' => 'nullable|in:CAD,USD',
            'rate_points' => 'nullable|integer|min:1|max:100000', 'rate_amount' => 'nullable|numeric|min:0|max:100000',
            'default_method' => 'nullable|in:payroll_bonus,payout,gift_card_manual,gift_card_provider',
            'points' => 'nullable|array', 'points.*' => 'integer|min:0|max:1000',
            'caps' => 'nullable|array', 'caps.*' => 'integer|min:0|max:1000',
        ]);
        $cfg = EducatorPoints::saveSettings($agencyId, $in);
        $this->audit($request, $agencyId, 'educator_points.settings', null, ['summary' => 'Changed the educator points settings.', 'settings' => $cfg]);

        return response()->json(['settings' => $cfg, 'activities' => EducatorPoints::activityList($cfg), 'can_edit' => true]);
    }

    /* ───────────── this agency's own Giftbit account ───────────── */

    public function giftbitShow(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && $this->isAdmin($request), 403);

        return response()->json(GiftCardProvider::agencyConfig($agencyId));
    }

    public function giftbitSave(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && $this->isAdmin($request), 403);
        $in = $request->validate([
            'env' => 'nullable|in:testbed,live', 'token' => 'nullable|string|max:2000',
            'clear_token' => 'nullable|boolean', 'enabled' => 'nullable|boolean',
        ]);
        $cfg = GiftCardProvider::saveAgency($agencyId, $in);
        $this->audit($request, $agencyId, 'educator_rewards.giftbit', null, ['summary' => 'Changed this agency\'s Giftbit account ('
            . $cfg['env'] . ', ' . ($cfg['enabled'] ? 'on' : 'off') . ')' . (! empty($in['token']) ? ', new API token saved' : '')
            . (! empty($in['clear_token']) ? ', token removed' : '') . '.']);

        return response()->json($cfg);
    }

    public function giftbitTest(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && $this->isAdmin($request), 403);
        $acct = GiftCardProvider::account($agencyId);
        if (! $acct) {
            return response()->json(['ok' => false, 'message' => 'Save an API token and switch Giftbit on first.']);
        }
        $r = GiftCardProvider::ping($acct);
        if ($r['ok']) {
            $bal = GiftCardProvider::balance($acct, EducatorPoints::settings($agencyId)['currency']);
            if ($bal) { $r['message'] .= ' Available: $' . number_format($bal['available'], 2) . '.'; }
        }

        return response()->json($r);
    }

    /* ───────────── this agency's own Tango Card account (2026-10-20) ───────────── */

    public function tangoShow(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && $this->isAdmin($request), 403);

        return response()->json(TangoProvider::agencyConfig($agencyId));
    }

    public function tangoSave(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && $this->isAdmin($request), 403);
        $in = $request->validate([
            'env' => 'nullable|in:sandbox,production', 'platform_name' => 'nullable|string|max:100', 'api_key' => 'nullable|string|max:500',
            'customer_identifier' => 'nullable|string|max:100', 'account_identifier' => 'nullable|string|max:100',
            'clear_key' => 'nullable|boolean', 'enabled' => 'nullable|boolean',
        ]);
        $cfg = TangoProvider::saveAgency($agencyId, $in);
        $this->audit($request, $agencyId, 'educator_rewards.tango', null, ['summary' => 'Changed this agency\'s Tango Card account ('
            . $cfg['env'] . ', ' . ($cfg['enabled'] ? 'on' : 'off') . ', customer ' . ($cfg['customer_identifier'] ?: '-') . ', account ' . ($cfg['account_identifier'] ?: '-') . ')'
            . (! empty($in['api_key']) ? ', new API key saved' : '') . (! empty($in['clear_key']) ? ', API key removed' : '') . '.']);

        return response()->json($cfg);
    }

    public function tangoTest(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && $this->isAdmin($request), 403);
        $acct = TangoProvider::account($agencyId);

        return response()->json($acct ? TangoProvider::ping($acct)
            : ['ok' => false, 'message' => 'Enter the platform name, API key, customer and account identifiers, and switch Tango Card on first.']);
    }

    /** GET /educator-points/giftbit/brands — the catalogue in the agency's currency. */
    public function brands(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $prov = $request->query('provider') === 'tango' ? 'tango' : 'giftbit';
        $P = $this->cardProvider($prov);
        $acct = $P::account($agencyId);
        if (! $acct) {
            return response()->json(['brands' => [], 'connected' => false, 'provider' => $prov]);
        }

        return response()->json(['brands' => $P::brands($acct, EducatorPoints::settings($agencyId)['currency']), 'connected' => true, 'env' => $acct['env'], 'provider' => $prov]);
    }

    /** GET /educator-points/giftbit/brands/{code} — the values one brand allows. */
    public function brandDetail(Request $request, string $code): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        if ($request->query('provider') === 'tango') {
            $acct = TangoProvider::account($agencyId);
            $b = $acct ? TangoProvider::brand($acct, $code, EducatorPoints::settings($agencyId)['currency']) : null;
        } else {
            $acct = GiftCardProvider::account($agencyId);
            $b = $acct ? GiftCardProvider::brand($acct, $code) : null;
        }

        return $b ? response()->json(['brand' => $b]) : response()->json(['message' => 'That brand isn\'t available.'], 404);
    }

    /** POST /educator-points/awards/{id}/refresh — ask Giftbit now. */
    public function refreshOne(Request $request, int $id): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $a = DB::table('educator_rewards')->where('id', $id)->where('agency_id', $agencyId)->where('method', 'gift_card_provider')->first();
        if (! $a || ! isset(EducatorPoints::earners($agencyId, $this->scope($request, $agencyId))[(int) $a->user_id])) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $this->refreshPending(collect([$a]), true);

        return response()->json(['award' => $this->awardOut(DB::table('educator_rewards')->where('id', $id)->first(), false)]);
    }

    /* ───────────── Giftbit helpers ───────────── */

    /** The account a gift was bought from (it stays with that account even if settings change). */
    private function acctFor(object $a): ?array
    {
        $acct = $this->cardProvider($a->provider === 'tango' ? 'tango' : 'giftbit')::account((int) $a->agency_id);

        return ($acct && (! $a->provider_account || $acct['key'] === $a->provider_account)) ? $acct : null;
    }

    /**
     * Bring Giftbit gifts up to date: the link once it is ready, and the gift's status
     * (redeemed, cancelled, expired). Each award is asked at most every 10 minutes, so
     * opening a page repeatedly never hammers Giftbit.
     */
    private function refreshPending($awards, bool $force = false): void
    {
        foreach ($awards as $a) {
            try {
                if (! $force && $a->provider_checked_at && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($a->provider_checked_at)) < 10) {
                    continue;
                }
                $acct = $this->acctFor($a);
                if (! $acct || ! $a->provider_order_id) {
                    continue;
                }
                $up = ['provider_checked_at' => now(), 'updated_at' => now()];
                $status = $a->status;
                if ($a->provider === 'tango') {
                    /* Tango: the order view carries its status and, once COMPLETE, the card. */
                    $o = $a->provider_campaign_uuid ? TangoProvider::getOrder($acct, (string) $a->provider_campaign_uuid) : null;
                    if ($o) {
                        $up['provider_status'] = $o['info'];
                        if ($o['line_id']) { $up['provider_gift_uuid'] = $o['line_id']; }
                        if (! $a->gift_link && $o['link']) { $up['gift_link'] = Crypt::encryptString($o['link']); }
                        if (! $a->gift_code && $o['code']) { $up['gift_code'] = Crypt::encryptString($o['code']); }
                        if ($o['status'] !== 'requested' || $status === 'requested') { $status = $o['status']; }
                    }
                    if ($status !== $a->status) {
                        $up['status'] = $status;
                        if ($status === 'cancelled') { $up['gift_link'] = null; $up['gift_code'] = null; }
                    }
                    DB::table('educator_rewards')->where('id', $a->id)->update($up);
                    continue;
                }
                if (! $a->gift_link) {
                    $l = GiftCardProvider::fetchLink($acct, (string) $a->provider_order_id);
                    if ($l['ready']) {
                        $up['gift_link'] = Crypt::encryptString($l['link']);
                        if ($status === 'requested') { $status = 'sent'; }
                    }
                }
                $g = GiftCardProvider::gift($acct, (string) $a->provider_order_id);
                if ($g) {
                    $up['provider_gift_uuid'] = $g['uuid'];
                    $up['provider_status'] = $g['status'];
                    $status = GiftCardProvider::mapGiftStatus($g['status'], $status);
                }
                if ($status !== $a->status) {
                    $up['status'] = $status;
                    if ($status === 'redeemed') { $up['paid_at'] = now(); }
                    if (in_array($status, ['cancelled', 'expired'], true)) { $up['gift_link'] = null; }
                }
                DB::table('educator_rewards')->where('id', $a->id)->update($up);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** The card services this agency can send from, for the Award dialog (balance only when connected). */
    private function providerSummary(int $agencyId, string $currency): array
    {
        $list = [];
        foreach (['giftbit', 'tango'] as $p) {
            $P = $this->cardProvider($p);
            $acct = $P::account($agencyId);
            if ($acct) {
                $list[] = ['key' => $p, 'label' => $this->cardLabel($p), 'env' => $acct['env'], 'source' => $acct['source'],
                    'test' => in_array($acct['env'], ['testbed', 'sandbox'], true), 'balance' => $P::balance($acct, $currency)];
            }
        }
        $first = $list[0] ?? null;

        // Top-level fields kept for a page loaded before this change (they describe the first one).
        return ['connected' => (bool) $list, 'providers' => $list, 'currency' => $currency,
            'env' => $first['env'] ?? null, 'source' => $first['source'] ?? null, 'balance' => $first['balance'] ?? null];
    }

    /** @return class-string<GiftCardProvider>|class-string<TangoProvider> */
    private function cardProvider(string $p): string
    {
        return $p === 'tango' ? TangoProvider::class : GiftCardProvider::class;
    }

    private function cardLabel(?string $p): string
    {
        return $p === 'tango' ? 'Tango Card' : 'Giftbit';
    }

    /** Giftbit's response for the record, without the card link (that is the card itself). */
    private function safeRaw($raw): ?string
    {
        if (! is_array($raw)) { return null; }
        unset($raw['direct_links']);

        return mb_substr(json_encode($raw), 0, 4000);
    }

    /* ───────────── helpers ───────────── */

    private function awardOut(object $a, bool $owner): array
    {
        $dec = function ($v) {
            if (! $v) { return null; }
            try { return Crypt::decryptString($v); } catch (\Throwable $e) { return null; }
        };
        $by = $a->awarded_by_id ? DB::table('users')->where('id', $a->awarded_by_id)->first(['first_name', 'last_name']) : null;

        return [
            'id' => (int) $a->id, 'month' => $a->month, 'points' => (int) $a->points,
            'amount' => (float) $a->amount, 'currency' => $a->currency,
            'method' => $a->method, 'method_label' => self::METHODS[$a->method] ?? $a->method,
            'status' => $a->status, 'brand' => $a->brand,
            // The card itself only ever goes to the person it belongs to.
            'gift_code' => $owner ? $dec($a->gift_code) : null,
            'gift_link' => $owner ? $dec($a->gift_link) : null,
            'has_card' => (bool) ($a->gift_code || $a->gift_link),
            'provider' => $a->provider, 'provider_label' => $a->provider ? $this->cardLabel($a->provider) : null,
            // How to use a Tango card (not secret, but only useful to its owner).
            'redemption_instructions' => $owner && $a->provider === 'tango' && $a->provider_response
                ? (json_decode((string) $a->provider_response, true)['_instructions'] ?? null) : null,
            'reference' => $owner ? null : $a->reference,
            'note' => $a->note,
            'awarded_by' => $by ? trim($by->first_name . ' ' . $by->last_name) : null,
            'created_at' => $a->created_at, 'paid_at' => $a->paid_at,
        ];
    }

    /**
     * THE ANNOUNCEMENT (2026-10-20, Anthony: "when sending award to educators it needs to send
     * an announcement, email to that educator and bcc admin/director").
     *   - bell notice + push (FcmService: suppression, web-push fallback) linking to My points;
     *   - App\Support\RewardMail: the designed email, To the educator, BCC the agency admins
     *     and their centre's director(s). The gift card itself is never in it.
     * Returns who was told, for the audit entry.
     */
    private function tell(int $agencyId, object $a, $by): array
    {
        [, $headline] = \App\Support\RewardMail::rewardLine($a);
        $byName = trim(($by->first_name ?? '') . ' ' . ($by->last_name ?? '')) ?: 'Your director';
        $monthLabel = \Illuminate\Support\Carbon::parse($a->month . '-01')->format('F Y');
        $isCard = str_starts_with((string) $a->method, 'gift_card');
        $body = mb_substr($byName . ' sent you a ' . $headline . ' for your ' . $monthLabel . ' points (' . $a->points . ').'
            . ($isCard ? ' Open My points to see your card.' : ''), 0, 240);

        \App\Support\Notify::write([
            'user_id' => (int) $a->user_id, 'type' => 'educator_reward',
            'title' => '⭐ You’ve earned a reward', 'body' => $body,
            'data' => json_encode(['link' => '#my-points', 'award_id' => (int) $a->id]),
        ]);
        $push = 'not sent';
        try {
            $r = app(\App\Services\FcmService::class)->sendToUser((int) $a->user_id, '⭐ You’ve earned a reward', $body, '#my-points');
            $push = ! empty($r['suppressed']) ? 'suppressed' : ((($r['sent'] ?? 0) > 0) ? 'sent' : 'no device');
        } catch (\Throwable $e) {
            report($e);
        }

        $mail = ['sent' => false];
        try {
            $mail = \App\Support\RewardMail::send($agencyId, $a, $by);
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'bell' => true, 'push' => $push,
            'emailed' => $mail['to_email'] ?? null,
            'bcc' => $mail['bcc_emails'] ?? [],
            'not_emailed' => $mail['skipped'] ?? [],
        ];
    }

    /**
     * GET /educator-points/thanks?user_id= — for the "Send a thank-you award" dialog: who signs (the agency's owner
     * first, then the other agency admins; first names only) and who is told (the same people as an award).
     */
    public function thanksInfo(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $uid = (int) $request->query('user_id');
        if (! isset(EducatorPoints::earners($agencyId, $this->scope($request, $agencyId))[$uid])) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $signers = \App\Support\ThankYouAward::signers($agencyId);
        $rc = \App\Support\RewardMail::recipients($agencyId, $uid);

        return response()->json([
            'signers' => array_map(fn ($s) => ['key' => $s['key'], 'first' => $s['first'], 'title' => $s['title']], $signers),
            'date' => now()->setTimezone(\App\Support\AgencyTime::tz($agencyId))->format('F j, Y'),
            'educator_email' => (bool) $rc['to'],
            'bcc' => array_map(fn ($b) => ['name' => $b['name'] ?: $b['email'], 'role' => $b['role'] === 'agency_admin' ? 'agency admin' : 'director'], $rc['bcc']),
        ]);
    }

    /**
     * POST /educator-points/thanks {user_id, month, message?, signer?} — the certificate of appreciation email
     * (App\Support\ThankYouAward), signed by the agency's owner (or the admin picked) and dated today in the agency's
     * time zone. Recorded as a $0 "thank_you" award so it shows in their rewards history; bell + push like an award.
     */
    public function thanks(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $data = $request->validate([
            'user_id' => 'required|integer',
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'message' => 'nullable|string|max:600',
            'signer' => 'nullable|string|max:20',
            'note' => 'nullable|string|max:3000',
            'note_ai' => 'nullable|boolean',
        ]);
        if ($data['month'] > EducatorPoints::month($agencyId, null)) {
            return response()->json(['message' => 'That month has not happened yet.'], 422);
        }
        $earners = EducatorPoints::earners($agencyId, $this->scope($request, $agencyId));
        $uid = (int) $data['user_id'];
        if (! isset($earners[$uid])) {
            return response()->json(['message' => 'That person is not on your team.'], 422);
        }
        $me = (int) $request->user()->id;
        if (! $this->isAdmin($request) && ($uid === $me || $earners[$uid]['role'] === 'centre_director')) {
            return response()->json(['message' => 'An agency admin thanks directors.'], 403);
        }
        $signer = \App\Support\ThankYouAward::signer($agencyId, $data['signer'] ?? null);
        if (! $signer) {
            return response()->json(['message' => 'This agency has no admin to sign the award. Add the owner under Agency settings first.'], 422);
        }
        $message = trim((string) ($data['message'] ?? '')) ?: null;
        $ctx = \App\Support\ThankYouAward::context($agencyId, $uid, $data['month'], $message, $signer);
        // The letter in the email body: what the sender saw and edited; if they sent none, write one now (2026-10-31).
        $letter = trim((string) ($data['note'] ?? ''));
        $letterAi = (bool) ($data['note_ai'] ?? false);
        if ($letter === '') {
            $n = \App\Support\ThankYouAward::note($agencyId, $ctx, 'warm');
            $letter = $n['note']; $letterAi = $n['ai'];
        }
        $ctx['note'] = $letter;

        $mail = ['sent' => false];
        try {
            $mail = \App\Support\ThankYouAward::send($agencyId, $ctx, $uid);
        } catch (\Throwable $e) {
            report($e);
        }
        $cfg = EducatorPoints::settings($agencyId);
        $id = DB::table('educator_rewards')->insertGetId([
            'agency_id' => $agencyId, 'user_id' => $uid, 'month' => $data['month'], 'points' => (int) $ctx['points'],
            'amount' => 0, 'currency' => $cfg['currency'], 'method' => 'thank_you', 'status' => ($mail['sent'] ?? false) ? 'sent' : 'failed',
            'reference' => mb_substr('Signed by ' . $signer['first'] . ' (' . $signer['title'] . ')', 0, 120),
            'note' => $message, 'letter' => $letter, 'awarded_by_id' => $me, 'created_at' => now(), 'updated_at' => now(),
        ] + \App\Support\EducatorAwardHistory::snapshot($agencyId, $uid, $me, 'manual'));
        $monthLabel = \Illuminate\Support\Carbon::parse($data['month'] . '-01')->format('F Y');
        $body = mb_substr($signer['first'] . ' sent you a certificate of appreciation for ' . $monthLabel . '. Check your email.', 0, 240);
        \App\Support\Notify::write([
            'user_id' => $uid, 'type' => 'educator_reward', 'title' => '🏅 Thank you!', 'body' => $body,
            'data' => json_encode(['link' => '#my-points', 'award_id' => $id]),
        ]);
        $push = 'not sent';
        try {
            $r = app(\App\Services\FcmService::class)->sendToUser($uid, '🏅 Thank you!', $body, '#my-points');
            $push = ! empty($r['suppressed']) ? 'suppressed' : ((($r['sent'] ?? 0) > 0) ? 'sent' : 'no device');
        } catch (\Throwable $e) {
            report($e);
        }
        $this->audit($request, $agencyId, 'educator_reward.thanked', $id, [
            'summary' => 'Sent ' . $earners[$uid]['name'] . ' a thank-you award for ' . $data['month'] . ' (' . $ctx['points'] . ' points), signed by '
                . $signer['first'] . ' (' . $signer['title'] . '), with a personal letter' . ($letterAi ? ' (drafted with the AI writing helper and reviewed by the sender)' : '')
                . (($mail['pdf'] ?? false) ? ' and a PDF certificate' : '') . '.'
                . (($mail['sent'] ?? false) ? ' Emailed ' . $mail['to_email'] . (! empty($mail['bcc_emails']) ? ', BCC ' . implode(', ', $mail['bcc_emails']) : '') . '.' : ' No email could be sent.'),
            'user_id' => $uid, 'month' => $data['month'], 'points' => (int) $ctx['points'], 'signer' => $signer['key'], 'push' => $push,
            'not_emailed' => $mail['skipped'] ?? [],
        ]);
        if (! ($mail['sent'] ?? false)) {
            return response()->json(['message' => 'There is no email address to send it to (' . implode(', ', $mail['skipped'] ?? []) . '). They still got an in-app notice.'], 422);
        }

        return response()->json(['ok' => true, 'award' => $this->awardOut(DB::table('educator_rewards')->where('id', $id)->first(), false)], 201);
    }

    /**
     * POST /educator-points/thanks/note {user_id, month, tone?} — the letter for the thank-you email, written from the
     * educator's real month (App\Support\ThankYouAward::note): AI Bundle agencies get an AI draft, others a written
     * template. Nothing is saved or sent; the sender edits it in the dialog.
     */
    public function thanksNote(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $data = $request->validate([
            'user_id' => 'required|integer',
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'tone' => 'nullable|in:warm,formal,short',
            'signer' => 'nullable|string|max:20',
        ]);
        $uid = (int) $data['user_id'];
        if (! isset(EducatorPoints::earners($agencyId, $this->scope($request, $agencyId))[$uid])) {
            return response()->json(['message' => 'That person is not on your team.'], 422);
        }
        $signer = \App\Support\ThankYouAward::signer($agencyId, $data['signer'] ?? null) ?: ['first' => '', 'title' => ''];
        $ctx = \App\Support\ThankYouAward::context($agencyId, $uid, $data['month'], null, $signer);
        $n = \App\Support\ThankYouAward::note($agencyId, $ctx, $data['tone'] ?? 'warm');

        return response()->json(['note' => $n['note'], 'ai' => $n['ai'], 'ai_available' => \App\Support\AiBundle::on($agencyId)]);
    }

    /** GET /educator-points/notify-preview?user_id= — who an award to this person will tell. */
    public function notifyPreview(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId && ($this->isAdmin($request) || $this->isDirector($request)), 403);
        $uid = (int) $request->query('user_id');
        if (! isset(EducatorPoints::earners($agencyId, $this->scope($request, $agencyId))[$uid])) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $rc = \App\Support\RewardMail::recipients($agencyId, $uid);

        return response()->json([
            'educator_email' => (bool) $rc['to'],
            'bcc' => array_map(fn ($b) => ['name' => $b['name'] ?: $b['email'], 'role' => $b['role'] === 'agency_admin' ? 'agency admin' : 'director'], $rc['bcc']),
        ]);
    }

    private function audit(Request $request, int $agencyId, string $action, ?int $id, array $payload): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => (int) $request->user()->id, 'agency_id' => $agencyId,
                'action' => $action, 'entity_type' => 'educator_reward', 'entity_id' => $id,
                'payload' => json_encode($payload), 'ip_address' => $request->ip(), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
