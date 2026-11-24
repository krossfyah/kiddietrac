<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforcePlanFeatures;
use App\Support\AgencyTime;
use App\Support\RefCode;
use App\Support\SearchRegistry;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v22p4.6 — Global portal search.
 *
 * Single endpoint, scoped to the calling user's ACTIVE agency. Returns a typed, grouped,
 * RANKED result set so the frontend can route each click to the right hash.
 *
 * GET /api/v1/admin/search?q=<term>
 *
 * Minimum term length: 2 chars (1 for a bare reference number). Returns empty results below that.
 *
 * WHAT IS SEARCHABLE LIVES IN App\Support\SearchRegistry (2026-11-02). Each entry there has a
 * src<Key>() method here that applies its scope; `php artisan kt:search-coverage` fails when a
 * main record type has neither a source nor a written reason. Anthony: "make the search on the
 * portal pull up invoices, receipts (global search) - keep this current with any new changes".
 *
 * Response:
 *   results  { <key>: [ {id, label, sublabel, hash, rank, ref_code?} ] }   every registry key
 *   groups   [ {key, label, icon, count, best} ]  non-empty groups, BEST MATCH FIRST — an exact
 *            invoice number puts Invoices above Families; ties keep the registry order
 *   ref      the exact reference ID typed, if any
 *
 * rank: 0 = it IS the term (name / number / ID), 1 = starts with it, 2 = a word starts with it
 * or it matched through the family / amount, 3 = contains it. Each group is ordered by rank in
 * SQL BEFORE its limit, so the exact match is never the one cut ([[kiddietrac-search-ranking]]).
 *
 * REFERENCE IDS EVERYWHERE (2026-10-14). Every child, family, staff and parent result carries
 * its ID (ILH-C-0042); an ID typed in full, in part or loosely finds its records
 * (RefCode::searchIds), and a family / child / parent ID also finds that family's invoices and
 * payments. Raw database ids are never shown.
 *
 * SCOPE. The agency comes from X-Active-Agency-Id (a platform admin must pick one). Centres come
 * from Visibility::centreIds for THAT agency, so a director sees their own centres only, even
 * when they are an agency admin somewhere else. Billing groups need platform_admin /
 * agency_admin / centre_director IN THIS AGENCY and the plan feature; nothing else gets them.
 */
final class SearchController extends Controller
{
    /** Search words that name a billing STATUS => canonical status. */
    private const STATUS_WORDS = [
        'overdue' => 'overdue', 'late' => 'overdue',
        'paid' => 'paid', 'settled' => 'paid', 'received' => 'paid',
        'partial' => 'partial', 'partly' => 'partial', 'partially' => 'partial',
        'void' => 'void', 'voided' => 'void', 'cancelled' => 'void', 'canceled' => 'void',
        'draft' => 'draft', 'drafts' => 'draft', 'pending' => 'pending',
        'sent' => 'issued', 'issued' => 'issued',
        'unpaid' => 'unpaid', 'outstanding' => 'unpaid', 'owing' => 'unpaid', 'owed' => 'unpaid', 'open' => 'unpaid', 'due' => 'unpaid',
        'refunded' => 'refunded', 'refund' => 'refunded', 'refunds' => 'refunded',
        'failed' => 'failed', 'declined' => 'failed', 'bounced' => 'failed',
        'scheduled' => 'scheduled', 'upcoming' => 'scheduled', 'disputed' => 'disputed',
    ];

    /** Words that narrow WHICH billing groups answer. */
    private const TYPE_WORDS = [
        'invoice' => 'inv', 'invoices' => 'inv', 'inv' => 'inv',
        'receipt' => 'pay', 'receipts' => 'pay', 'payment' => 'pay', 'payments' => 'pay',
        'bill' => 'bill', 'bills' => 'bill', 'expense' => 'bill', 'expenses' => 'bill',
    ];

    /** Payment method words => payments.method values. */
    private const METHOD_WORDS = [
        'cheque' => ['cheque'], 'cheques' => ['cheque'], 'check' => ['cheque'], 'cash' => ['cash'],
        'card' => ['card', 'stripe_card', 'helcim_card'], 'credit' => ['card', 'stripe_card', 'helcim_card'],
        'visa' => ['card', 'stripe_card', 'helcim_card'], 'mastercard' => ['card', 'stripe_card', 'helcim_card'],
        'interac' => ['interac'], 'etransfer' => ['interac'], 'e-transfer' => ['interac'],
        'eft' => ['eft'], 'pad' => ['eft'], 'debit' => ['eft'], 'ach' => ['stripe_ach'], 'bank' => ['stripe_ach', 'eft'],
        'manual' => ['manual'], 'stripe' => ['stripe_card', 'stripe_ach'],
    ];

    private const MONTHS = [
        'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4,
        'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];

    public function query(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $isNumber = (bool) preg_match('/^\d{1,7}$/', $q);

        if (mb_strlen($q) < 2 && ! $isNumber) {
            return response()->json(['q' => $q, 'results' => $this->emptyGroups(), 'groups' => []]);
        }

        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'Select an agency first.'], 403);
        }
        $user = $request->user();

        // Roles that count HERE: in this agency (or platform). A role in another agency is no role.
        $roleRows = DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)->get(['role', 'agency_id', 'centre_id']);
        $agencyCentresAll = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $here = $roleRows->filter(fn ($r) => $r->role === 'platform_admin' || (int) $r->agency_id === $agencyId
            || ($r->centre_id && in_array((int) $r->centre_id, $agencyCentresAll, true)))->pluck('role')->unique()->values()->all();
        $wholeAgency = (bool) array_intersect($here, ['platform_admin', 'agency_admin']);

        // The caller's LIVE centres in this agency (fails closed: [] = nothing).
        $centreIds = array_values(array_map('intval', Visibility::centreIds($agencyId, $user)));

        $ctx = (object) [
            'q' => $q,
            'isNumber' => $isNumber,
            'nameSearch' => ! $isNumber,
            'term' => $this->like($q),
            'agencyId' => $agencyId,
            'centreIds' => $centreIds,
            'wholeAgency' => $wholeAgency,
            // Families on the agency waitlist after their provider closed: agency admins still find them (2026-11-24).
            'held' => $wholeAgency ? (\App\Support\AgencyWaitlist::familyIds($agencyId) ?: []) : [],
            'refs' => RefCode::searchIds($q, $agencyId) ?? [],
            'exact' => RefCode::lookup($q, $agencyId),
            'billing' => (bool) array_intersect($here, SearchRegistry::BILLING_ROLES),
            'acct' => $this->allows($agencyId, 'accounting_sync'),
            'today' => AgencyTime::today($agencyId),
            'tz' => AgencyTime::tz($agencyId),
        ];
        $ctx->bq = $this->parseBilling($q, $ctx);

        $results = [];
        $groups = [];
        $order = 0;
        foreach (SearchRegistry::SOURCES as $key => $meta) {
            $results[$key] = [];
            $order++;
            if (! empty($meta['billing'])) {
                if (! $ctx->billing) continue;
                if (! empty($meta['feature']) && ! $this->allows($agencyId, $meta['feature'])) continue;
            }
            $method = SearchRegistry::method($key);
            try {
                $items = $this->{$method}($ctx, $meta);
            } catch (Throwable $e) {
                /* Never a raw error, never a silent drop: the group is left out of this answer
                   and the failure is logged loudly enough for kt:search-coverage readers. */
                Log::error('[search] source failed', ['source' => $key, 'agency' => $agencyId, 'error' => $e->getMessage()]);
                $items = [];
            }
            $results[$key] = array_values($items);
            if ($items) {
                $best = min(array_map(fn ($i) => (int) ($i['rank'] ?? 3), $items));
                $groups[] = SearchRegistry::groupMeta($key) + ['count' => count($items), 'best' => $best, 'order' => $order];
            }
        }
        usort($groups, fn ($a, $b) => [$a['best'], $a['order']] <=> [$b['best'], $b['order']]);
        $groups = array_map(fn ($g) => ['key' => $g['key'], 'label' => $g['label'], 'icon' => $g['icon'], 'count' => $g['count'], 'best' => $g['best']], $groups);

        $exact = $ctx->exact;
        return response()->json([
            'q' => $q,
            'ref' => $exact ? ['type' => $exact['type'], 'id' => $exact['id'], 'code' => $exact['code']] : null,
            'results' => $results,
            'groups' => $groups,
        ]);
    }

    // ── People and places ────────────────────────────────────────────────────────────

    private function srcCentres(object $ctx, array $meta): array
    {
        if (! $ctx->nameSearch || ! $ctx->centreIds) return [];
        [$rk, $b] = $this->rankExpr(['name', 'city'], $ctx->q);
        return DB::table('centres')
            ->whereIn('id', $ctx->centreIds)
            ->where(fn ($w) => $w->where('name', 'LIKE', $ctx->term)->orWhere('city', 'LIKE', $ctx->term))
            ->selectRaw('id, name, city, ' . $rk . ' as rk', $b)
            ->orderBy('rk')->orderBy('name')
            ->limit($meta['limit'])->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'label' => $c->name, 'sublabel' => $c->city, 'hash' => '#admin-centres', 'rank' => (int) $c->rk,
            ])->all();
    }

    private function srcRooms(object $ctx, array $meta): array
    {
        // rooms has no deleted_at column (verified at runtime); closed centres are out via centreIds.
        if (! $ctx->nameSearch || ! $ctx->centreIds) return [];
        [$rk, $b] = $this->rankExpr(['name'], $ctx->q);
        return DB::table('rooms')
            ->whereIn('centre_id', $ctx->centreIds)
            ->where('name', 'LIKE', $ctx->term)
            ->selectRaw('id, name, centre_id, ' . $rk . ' as rk', $b)
            ->orderBy('rk')->orderBy('name')
            ->limit($meta['limit'])->get()
            ->map(fn ($r) => ['id' => $r->id, 'label' => $r->name, 'sublabel' => 'Room', 'hash' => '#admin-centres', 'rank' => (int) $r->rk])
            ->all();
    }

    private function srcFamilies(object $ctx, array $meta): array
    {
        if (! $ctx->centreIds && ! $ctx->held) return [];
        $famIds = array_map('intval', $ctx->refs['F'] ?? []);
        $exactId = ($ctx->exact && ($ctx->exact['type'] ?? '') === 'F') ? (int) $ctx->exact['id'] : 0;
        if (! $ctx->nameSearch && ! $famIds) return [];
        [$rk, $b] = $this->rankExpr(['family_name'], $ctx->q);
        $refSql = $famIds ? 'CASE WHEN id = ' . $exactId . ' THEN 0 WHEN id IN (' . implode(',', $famIds) . ') THEN 1 ELSE ' . $rk . ' END' : $rk;

        $families = DB::table('families')
            ->where(fn ($w) => $w->whereIn('centre_id', $ctx->centreIds ?: [0])->orWhereIn('id', $ctx->held ?: [0]))
            ->where(function ($w) use ($ctx, $famIds) {
                if ($ctx->nameSearch) $w->where(fn ($x) => $x->whereNull('deleted_at')->where('family_name', 'LIKE', $ctx->term));
                if ($famIds) $w->orWhereIn('id', $famIds);
            })
            ->selectRaw('id, family_name, centre_id, deleted_at, ' . $refSql . ' as rk', $b)
            ->orderBy('rk')->orderBy('family_name')
            ->limit($meta['limit'])->get();
        if ($families->isEmpty()) return [];

        $codes = RefCode::families($families->pluck('id')->all());
        $firstChild = DB::table('children')->whereIn('family_id', $families->pluck('id'))
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'family_id'])->groupBy('family_id')->map(fn ($g) => (int) $g->first()->id);

        return $families->map(fn ($f) => [
            'id' => $f->id,
            'label' => $f->family_name . ($f->deleted_at ? ' (archived)' : ''),
            'sublabel' => $this->sub($codes[$f->id] ?? null, in_array((int) $f->id, $ctx->held, true) ? 'Family · ⏳ Waitlisted' : 'Family'),
            'ref_code' => $codes[$f->id] ?? null,
            // Directors have no Families screen: their family hit opens the first child's record.
            'hash' => $ctx->wholeAgency ? '#admin-families?family=' . $f->id . ($f->deleted_at ? '&archived=1' : '')
                : (isset($firstChild[$f->id]) ? '#child-detail?id=' . $firstChild[$f->id] : '#dashboard'),
            'rank' => (int) $f->rk,
        ])->all();
    }

    private function srcChildren(object $ctx, array $meta): array
    {
        if (! $ctx->centreIds && ! $ctx->held) return [];
        $kidIds = array_map('intval', $ctx->refs['C'] ?? []);
        $exactId = ($ctx->exact && ($ctx->exact['type'] ?? '') === 'C') ? (int) $ctx->exact['id'] : 0;
        if (! $ctx->nameSearch && ! $kidIds) return [];
        $full = "CONCAT(children.first_name,' ',children.last_name)";
        $pref = "CONCAT(COALESCE(NULLIF(children.preferred_name,''),children.first_name),' ',children.last_name)";
        [$rk, $b] = $this->rankExpr([$full, $pref, 'children.first_name', 'children.last_name', 'children.preferred_name'], $ctx->q);
        $refSql = $kidIds ? 'CASE WHEN children.id = ' . $exactId . ' THEN 0 WHEN children.id IN (' . implode(',', $kidIds) . ') THEN 1 ELSE ' . $rk . ' END' : $rk;

        // An archived child is still found by its ID — the ID is printed on documents kept for years.
        $children = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->where(fn ($w) => $w->whereIn('families.centre_id', $ctx->centreIds ?: [0])->orWhereIn('families.id', $ctx->held ?: [0]))
            ->where(function ($w) use ($ctx, $kidIds, $full, $pref) {
                if ($ctx->nameSearch) {
                    $w->where(fn ($x) => $x->whereNull('children.deleted_at')->where(fn ($y) => $y
                        ->where('children.first_name', 'LIKE', $ctx->term)
                        ->orWhere('children.last_name', 'LIKE', $ctx->term)
                        ->orWhere('children.preferred_name', 'LIKE', $ctx->term)
                        ->orWhereRaw($full . ' LIKE ?', [$ctx->term])
                        ->orWhereRaw($pref . ' LIKE ?', [$ctx->term])));
                }
                if ($kidIds) $w->orWhereIn('children.id', $kidIds);
            })
            ->selectRaw('children.id, children.first_name, children.last_name, children.deleted_at, children.preferred_name, families.family_name, ' . $refSql . ' as rk', $b)
            ->orderBy('rk')->orderBy('children.first_name')
            ->limit($meta['limit'])->get();
        $codes = RefCode::children($children->pluck('id')->all());

        return $children->map(fn ($c) => [
            'id' => $c->id,
            'label' => trim(($c->preferred_name ?: $c->first_name) . ' ' . $c->last_name) . ($c->deleted_at ? ' (archived)' : ''),
            'sublabel' => $this->sub($codes[$c->id] ?? null, $c->family_name ? $this->famLabel($c->family_name, false) : '— family'),
            'ref_code' => $codes[$c->id] ?? null,
            'hash' => '#child-detail?id=' . $c->id . ($c->deleted_at ? '&archived=1' : ''),
            'rank' => (int) $c->rk,
        ])->all();
    }

    private function srcStaff(object $ctx, array $meta): array
    {
        // An active non-guardian role at the agency (admins) or at the caller's centres.
        $ids = DB::table('role_assignments')
            ->where('active', true)
            ->where('role', '!=', 'guardian')
            ->where(fn ($w) => $w
                ->when($ctx->wholeAgency, fn ($x) => $x->where('agency_id', $ctx->agencyId))
                ->orWhereIn('centre_id', $ctx->centreIds ?: [0]))
            ->pluck('user_id')->map(fn ($x) => (int) $x)->unique()->all();
        $people = $this->people($ctx, $ids, $ctx->refs['S'] ?? [], 'S', $meta['limit']);
        $codes = RefCode::users($people->pluck('id')->all(), $ctx->agencyId, 'S');
        return $people->map(fn ($u) => [
            'id' => $u->id,
            'label' => trim(($u->preferred_name ?: $u->first_name) . ' ' . $u->last_name),
            'sublabel' => $this->sub($codes[$u->id] ?? null, $u->email),
            'ref_code' => $codes[$u->id] ?? null,
            'hash' => '#admin-users?user=' . $u->id,
            'rank' => (int) $u->rk,
        ])->all();
    }

    private function srcParents(object $ctx, array $meta): array
    {
        // Guardians of a family at the caller's centres.
        $ids = (! $ctx->centreIds && ! $ctx->held) ? [] : DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->where(fn ($w) => $w->whereIn('f.centre_id', $ctx->centreIds ?: [0])->orWhereIn('f.id', $ctx->held ?: [0]))->pluck('g.user_id')->map(fn ($x) => (int) $x)->unique()->all();
        $people = $this->people($ctx, $ids, $ctx->refs['P'] ?? [], 'P', $meta['limit']);
        $codes = RefCode::users($people->pluck('id')->all(), $ctx->agencyId, 'P');
        return $people->map(fn ($u) => [
            'id' => $u->id,
            'label' => trim(($u->preferred_name ?: $u->first_name) . ' ' . $u->last_name),
            'sublabel' => $this->sub($codes[$u->id] ?? null, $u->email),
            'ref_code' => $codes[$u->id] ?? null,
            'hash' => '#admin-users?user=' . $u->id,
            'rank' => (int) $u->rk,
        ])->all();
    }

    /** People from a candidate set, by name/email or by ID, ranked. */
    private function people(object $ctx, array $candidates, array $byRef, string $type, int $limit)
    {
        $byRef = array_values(array_intersect(array_map('intval', $byRef), $candidates));
        if (! $candidates || (! $ctx->nameSearch && ! $byRef)) return collect();
        $exactId = ($ctx->exact && ($ctx->exact['type'] ?? '') === $type) ? (int) $ctx->exact['id'] : 0;
        $full = "CONCAT(first_name,' ',last_name)";
        $pref = "CONCAT(COALESCE(NULLIF(preferred_name,''),first_name),' ',last_name)";
        [$rk, $b] = $this->rankExpr([$full, $pref, 'first_name', 'last_name', 'preferred_name', 'email'], $ctx->q);
        $refSql = $byRef ? 'CASE WHEN id = ' . $exactId . ' THEN 0 WHEN id IN (' . implode(',', $byRef) . ') THEN 1 ELSE ' . $rk . ' END' : $rk;

        return DB::table('users')
            ->whereIn('id', $candidates)
            ->whereNull('deleted_at')
            ->where(function ($w) use ($ctx, $byRef, $full, $pref) {
                if ($ctx->nameSearch) {
                    $w->where(fn ($x) => $x
                        ->where('first_name', 'LIKE', $ctx->term)
                        ->orWhere('last_name', 'LIKE', $ctx->term)
                        ->orWhere('preferred_name', 'LIKE', $ctx->term)
                        ->orWhere('email', 'LIKE', $ctx->term)
                        ->orWhereRaw($full . ' LIKE ?', [$ctx->term])
                        ->orWhereRaw($pref . ' LIKE ?', [$ctx->term]));
                }
                if ($byRef) $w->orWhereIn('id', $byRef);
            })
            ->selectRaw('id, first_name, last_name, preferred_name, email, ' . $refSql . ' as rk', $b)
            ->orderBy('rk')->orderBy('first_name')
            ->limit($limit)
            ->get();
    }

    // ── Billing (2026-11-02) ─────────────────────────────────────────────────────────

    /** KiddieTrac invoices. Opens the invoice sheet (KT.openInvoiceById) from Accounting. */
    private function srcInvoices(object $ctx, array $meta): array
    {
        $bq = $ctx->bq;
        if (! $ctx->centreIds || ! $this->kindAllows($bq, 'inv')) return [];
        $qb = DB::table('invoices as i')
            ->leftJoin('families as f', 'f.id', '=', 'i.family_id')
            ->whereIn('i.centre_id', $ctx->centreIds);
        $rk = $this->billingWhere($qb, $ctx, [
            'num' => ['i.invoice_number'], 'fam' => 'i.family_id', 'amounts' => ['i.total', 'i.balance_due'],
            'dates' => ['i.issued_at', 'i.due_at', 'i.period_start'], 'status' => 'i.status', 'due' => 'i.due_at',
            'statusMap' => [
                'overdue' => ['overdue'], 'paid' => ['paid'], 'partial' => ['partial'], 'void' => ['void'],
                'draft' => ['draft'], 'pending' => ['draft'], 'issued' => ['sent'], 'unpaid' => ['sent', 'partial', 'overdue'],
                'refunded' => ['refunded'], 'scheduled' => ['sent'],
            ],
        ]);
        if ($rk === null) return [];
        $rows = $qb->selectRaw('i.id, i.invoice_number, i.total, i.balance_due, i.status, i.issued_at, i.due_at, i.family_id,'
                . ' f.family_name, f.deleted_at as fam_deleted, ' . $rk[0] . ' as rk', $rk[1])
            ->orderBy('rk')->orderByRaw("i.status = 'void'")->orderByDesc('i.issued_at')->orderByDesc('i.id')
            ->limit($meta['limit'])->get();

        return $rows->map(function ($r) use ($ctx) {
            $bits = [$this->famLabel($r->family_name, (bool) $r->fam_deleted), $this->money($r->total),
                $this->statusWord((string) $r->status, $r->due_at, $ctx->today)];
            if ((float) $r->balance_due > 0.004 && (float) $r->balance_due < (float) $r->total - 0.004) $bits[] = $this->money($r->balance_due) . ' owing';
            if ($r->due_at) $bits[] = 'due ' . $this->day($r->due_at);
            return [
                'id' => $r->id,
                'label' => 'Invoice ' . ($r->invoice_number ?: '(no number)'),
                'sublabel' => implode(' · ', $bits),
                'hash' => $this->invoiceHash($ctx, (int) $r->id),
                'status' => $r->status,
                'rank' => (int) $r->rk,
            ];
        })->all();
    }

    /** Invoices synced from a provider's own system (iLearn). Opens Accounting filtered to it. */
    private function srcProviderInvoices(object $ctx, array $meta): array
    {
        $bq = $ctx->bq;
        if (! $this->kindAllows($bq, 'inv')) return [];
        $qb = DB::table('external_invoices as e')
            ->leftJoin('families as f', 'f.id', '=', 'e.family_id')
            ->where('e.agency_id', $ctx->agencyId);
        if (! $ctx->wholeAgency) {
            $qb->whereIn('e.family_id', $this->scopeFamilies($ctx) ?: [0]);
        }
        $rk = $this->billingWhere($qb, $ctx, [
            'num' => ['e.number'], 'fam' => 'e.family_id', 'amounts' => ['e.total', 'e.balance_due'],
            'dates' => ['e.issued_at', 'e.due_at'], 'status' => 'e.status', 'due' => 'e.due_at',
            'statusMap' => [
                'overdue' => ['overdue'], 'paid' => ['paid'], 'void' => ['void'], 'issued' => ['open'],
                'unpaid' => ['open', 'overdue'], 'scheduled' => ['open'],
            ],
        ]);
        if ($rk === null) return [];
        $rows = $qb->selectRaw('e.id, e.number, e.total, e.balance_due, e.status, e.issued_at, e.due_at, e.family_id,'
                . ' f.family_name, f.deleted_at as fam_deleted, ' . $rk[0] . ' as rk', $rk[1])
            ->orderBy('rk')->orderByRaw("e.status = 'void'")->orderByDesc('e.issued_at')->orderByDesc('e.id')
            ->limit($meta['limit'])->get();

        return $rows->map(function ($r) use ($ctx) {
            $bits = [$this->famLabel($r->family_name, (bool) $r->fam_deleted), $this->money($r->total),
                $this->statusWord((string) $r->status, $r->due_at, $ctx->today)];
            if ($r->issued_at) $bits[] = $this->day($r->issued_at);
            return [
                'id' => $r->id,
                'label' => 'Invoice ' . ($r->number ?: '(no number)'),
                'sublabel' => implode(' · ', $bits) . ' · synced',
                'hash' => $this->providerHash((string) $r->number, (int) $r->family_id, (string) $r->status),
                'status' => $r->status,
                'rank' => (int) $r->rk,
            ];
        })->all();
    }

    /** What the agency bills or pays someone outside enrolment billing. Accounting, filtered. */
    private function srcPayeeInvoices(object $ctx, array $meta): array
    {
        $bq = $ctx->bq;
        if (! $this->kindAllows($bq, 'inv')) return [];
        $qb = DB::table('payee_invoices as pi')
            ->leftJoin('families as f', 'f.id', '=', 'pi.payee_family_id')
            ->where('pi.agency_id', $ctx->agencyId);
        if (! $ctx->wholeAgency) {
            // As Accounting: a director sees only those raised against a family at their centres.
            $qb->whereIn('pi.payee_family_id', $this->scopeFamilies($ctx) ?: [0]);
        }
        $rk = $this->billingWhere($qb, $ctx, [
            'num' => ['pi.reference'], 'fam' => 'pi.payee_family_id', 'text' => ['pi.payee_name'],
            'amounts' => ['pi.subtotal', 'pi.amount'], 'dates' => ['pi.period_start', 'pi.period_end', 'pi.created_at'],
            'status' => 'pi.status',
            'statusMap' => [
                'paid' => ['paid'], 'void' => ['void'], 'scheduled' => ['upcoming'], 'pending' => ['upcoming'],
                'issued' => ['issued'], 'unpaid' => ['upcoming', 'issued'],
            ],
        ]);
        if ($rk === null) return [];
        $rows = $qb->selectRaw('pi.id, pi.reference, pi.kind, pi.payee_name, pi.amount, pi.subtotal, pi.status, pi.period_end,'
                . ' f.family_name, ' . $rk[0] . ' as rk', $rk[1])
            ->orderBy('rk')->orderByRaw("pi.status = 'void'")->orderByDesc('pi.created_at')->orderByDesc('pi.id')
            ->limit($meta['limit'])->get();

        return $rows->map(function ($r) use ($ctx) {
            $who = trim((string) ($r->payee_name ?: $r->family_name ?: ''));
            $bits = array_filter([$who ?: null, $this->money($r->subtotal ?? $r->amount),
                $this->statusWord((string) $r->status, null, $ctx->today), $r->kind ? ucfirst((string) $r->kind) : null]);
            return [
                'id' => $r->id,
                'label' => 'Payee invoice ' . ($r->reference ?: '(no reference)'),
                'sublabel' => implode(' · ', $bits),
                'hash' => '#external-billing?q=' . rawurlencode((string) $r->reference) . (strtolower((string) $r->status) === 'void' ? '&tab=void' : ''),
                'status' => $r->status,
                'rank' => (int) $r->rk,
            ];
        })->all();
    }

    /** Payments and their receipts. Opens the invoice they paid (its sheet lists the payments). */
    private function srcPayments(object $ctx, array $meta): array
    {
        $bq = $ctx->bq;
        if (! $ctx->centreIds || ! $this->kindAllows($bq, 'pay')) return [];
        $qb = DB::table('payments as p')
            ->join('families as f', 'f.id', '=', 'p.family_id')
            ->leftJoin('invoices as li', 'li.id', '=', 'p.invoice_id')
            ->leftJoin('external_invoices as le', 'le.id', '=', 'p.external_invoice_id')
            ->whereIn('f.centre_id', $ctx->centreIds);
        $rk = $this->billingWhere($qb, $ctx, [
            // The Interac / cheque / processor reference AND the invoice it paid both identify it.
            'num' => ['p.reference_number', 'p.stripe_payment_id', 'li.invoice_number', 'le.number'],
            'fam' => 'p.family_id', 'amounts' => ['p.amount'], 'dates' => ['p.paid_at'], 'datetime' => true,
            'status' => 'p.status', 'method' => 'p.method',
            'statusMap' => [
                'paid' => ['succeeded'], 'refunded' => ['refunded'], 'failed' => ['failed'], 'pending' => ['pending'],
                'disputed' => ['disputed'],
            ],
        ]);
        if ($rk === null) return [];
        $rows = $qb->selectRaw('p.id, p.amount, p.method, p.status, p.reference_number, p.paid_at, p.created_at, p.invoice_id,'
                . ' p.external_invoice_id, p.family_id, li.invoice_number, le.number as ext_number, le.status as ext_status,'
                . ' f.family_name, f.deleted_at as fam_deleted, ' . $rk[0] . ' as rk', $rk[1])
            ->orderBy('rk')->orderByDesc('p.paid_at')->orderByDesc('p.id')
            ->limit($meta['limit'])->get();

        $refunded = DB::table('payment_refunds')->whereIn('payment_id', $rows->pluck('id')->all() ?: [0])
            ->whereNotIn('status', ['failed', 'cancelled', 'rejected'])
            ->groupBy('payment_id')->selectRaw('payment_id, SUM(amount) as amt')->pluck('amt', 'payment_id');

        return $rows->map(function ($r) use ($ctx, $refunded) {
            $method = $this->methodWord((string) $r->method);
            $when = $r->paid_at ?: $r->created_at;
            $bits = [$method];
            if ($r->reference_number) $bits[] = 'ref ' . $r->reference_number;
            if ($when) $bits[] = AgencyTime::fmt($when, $ctx->tz, 'M j, Y');
            $inv = $r->invoice_number ?: $r->ext_number;
            if ($inv) $bits[] = 'invoice ' . $inv;
            $st = $this->statusWord((string) $r->status, null, $ctx->today);
            if (isset($refunded[$r->id]) && (float) $refunded[$r->id] > 0.004 && $r->status !== 'refunded') {
                $st .= ', ' . $this->money($refunded[$r->id]) . ' refunded';
            }
            $bits[] = $st;
            if ($r->invoice_id) {
                $hash = $this->invoiceHash($ctx, (int) $r->invoice_id);
            } elseif ($r->external_invoice_id && $ctx->acct) {
                $hash = $this->providerHash((string) $r->ext_number, (int) $r->family_id, (string) $r->ext_status);
            } else {
                $hash = $ctx->acct ? '#external-billing?family=' . (int) $r->family_id : '#billing-schedule';
            }
            return [
                'id' => $r->id,
                'label' => 'Payment ' . $this->money($r->amount) . ' · ' . $this->famLabel($r->family_name, (bool) $r->fam_deleted),
                'sublabel' => implode(' · ', array_filter($bits)),
                'hash' => $hash,
                'status' => $r->status,
                'rank' => (int) $r->rk,
            ];
        })->all();
    }

    /** Supplier bills (Expenses). */
    private function srcExpenseBills(object $ctx, array $meta): array
    {
        $bq = $ctx->bq;
        if (! $this->kindAllows($bq, 'bill') && ! $this->kindAllows($bq, 'inv')) return [];
        $qb = DB::table('expense_invoices as x')
            ->leftJoin('suppliers as s', 's.id', '=', 'x.supplier_id')
            ->where('x.agency_id', $ctx->agencyId)
            ->whereNull('x.deleted_at');
        if (! $ctx->wholeAgency) {
            $qb->whereIn('x.centre_id', $ctx->centreIds ?: [0]);
        }
        $rk = $this->billingWhere($qb, $ctx, [
            'num' => ['x.invoice_number', 'x.reference'], 'fam' => null, 'text' => ['s.name'],
            'amounts' => ['x.total'], 'dates' => ['x.issue_date', 'x.due_date'], 'status' => 'x.status', 'due' => 'x.due_date',
            'statusMap' => [
                'draft' => ['draft'], 'pending' => ['awaiting_approval'], 'paid' => ['paid'], 'partial' => ['partial'],
                'overdue' => ['overdue'], 'void' => ['void'], 'issued' => ['approved'], 'unpaid' => ['approved', 'partial', 'overdue'],
            ],
        ]);
        if ($rk === null) return [];
        $rows = $qb->selectRaw('x.id, x.invoice_number, x.reference, x.total, x.status, x.due_date, s.name as supplier, ' . $rk[0] . ' as rk', $rk[1])
            ->orderBy('rk')->orderByRaw("x.status = 'void'")->orderByDesc('x.issue_date')->orderByDesc('x.id')
            ->limit($meta['limit'])->get();

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'label' => 'Bill ' . ($r->invoice_number ?: $r->reference ?: '(no number)'),
            'sublabel' => implode(' · ', array_filter([$r->supplier, $this->money($r->total),
                $this->statusWord((string) $r->status, $r->due_date, $ctx->today), $r->due_date ? 'due ' . $this->day($r->due_date) : null])),
            'hash' => '#expenses',
            'status' => $r->status,
            'rank' => (int) $r->rk,
        ])->all();
    }

    /**
     * Apply the parsed billing query to a source and return [rank SQL, bindings], or null when
     * this source cannot satisfy it (e.g. a payment method asked of invoices).
     *
     * Constraints are ANDed: words (number / reference / family, child or parent name / IDs),
     * an amount, a status, a payment method, a date. A bare whole number is BOTH a number
     * fragment and an amount. A query with no billing constraint at all ("invoice") finds nothing.
     */
    private function billingWhere($qb, object $ctx, array $c): ?array
    {
        $bq = $ctx->bq;
        $num = $c['num'] ?? [];
        $text = $c['text'] ?? [];
        $amounts = $c['amounts'] ?? [];
        $famCol = $c['fam'] ?? null;
        $any = false;

        if ($bq->statuses) {
            if (empty($c['status'])) return null;
            $plain = []; $sched = [];
            foreach ($bq->statuses as $s) {
                if (! isset($c['statusMap'][$s])) continue;
                if ($s === 'scheduled' && ! empty($c['due'])) { $sched = array_merge($sched, $c['statusMap'][$s]); continue; }
                $plain = array_merge($plain, $c['statusMap'][$s]);
            }
            if (! $plain && ! $sched) return null;
            $qb->where(function ($w) use ($c, $plain, $sched, $ctx) {
                if ($plain) $w->whereIn($c['status'], array_values(array_unique($plain)));
                if ($sched) $w->orWhere(fn ($x) => $x->whereIn($c['status'], array_values(array_unique($sched)))->where($c['due'], '>', $ctx->today));
            });
            $any = true;
        }
        if ($bq->methods) {
            if (empty($c['method'])) return null;
            $qb->whereIn($c['method'], $bq->methods);
            $any = true;
        }
        if ($bq->from) {
            if (empty($c['dates'])) return null;
            $qb->where(function ($w) use ($c, $bq) {
                foreach ($c['dates'] as $d) $w->orWhereRaw('DATE(' . $d . ') BETWEEN ? AND ?', [$bq->from, $bq->to]);
            });
            $any = true;
        }
        if ($bq->amount !== null && $bq->bare === null) {
            if (! $amounts) return null;
            $amt = number_format($bq->amount, 2, '.', '');
            $qb->where(function ($w) use ($amounts, $amt) { foreach ($amounts as $a) $w->orWhereRaw($a . ' = ?', [$amt]); });
            $any = true;
        }
        if ($bq->bare !== null) {
            $amt = number_format((float) $bq->bare, 2, '.', '');
            $like = $this->like($bq->bare);
            if (! $num && ! $amounts) return null;
            $qb->where(function ($w) use ($num, $amounts, $amt, $like) {
                foreach ($num as $n) $w->orWhere($n, 'LIKE', $like);
                foreach ($amounts as $a) $w->orWhereRaw($a . ' = ?', [$amt]);
            });
            $any = true;
        }
        $fams = [];
        if ($bq->text !== '') {
            $fams = $famCol ? array_values(array_unique(array_merge($bq->nameFams, $bq->refFams))) : [];
            if (! $num && ! $text && ! $fams) return null;
            $like = $this->like($bq->text);
            $qb->where(function ($w) use ($num, $text, $fams, $famCol, $like) {
                foreach ($num as $n) $w->orWhere($n, 'LIKE', $like);
                foreach ($text as $t) $w->orWhere($t, 'LIKE', $like);
                if ($fams) $w->orWhereIn($famCol, $fams);
            });
            $any = true;
        }
        if (! $any) return null;

        // Rank: 0 the number/reference IS it (or the family ID typed exactly), 1 starts/ends
        // with it, 2 matched by family name or amount, 3 the rest.
        $core = $bq->text !== '' ? $bq->text : ($bq->bare ?? '');
        $when = []; $b = [];
        if ($core !== '' && $num) {
            $when[] = 'WHEN ' . implode(' OR ', array_map(fn ($n) => $n . ' = ?', $num)) . ' THEN 0';
            foreach ($num as $n) $b[] = $core;
        }
        if ($famCol && $bq->text !== '' && $bq->exactFams) {
            $when[] = 'WHEN ' . $famCol . ' IN (' . implode(',', array_map('intval', $bq->exactFams)) . ') THEN 0';
        }
        if ($core !== '' && $num) {
            $esc = $this->likeEsc($core);
            $when[] = 'WHEN ' . implode(' OR ', array_map(fn ($n) => '(' . $n . ' LIKE ? OR ' . $n . ' LIKE ?)', $num)) . ' THEN 1';
            foreach ($num as $n) { $b[] = $esc . '%'; $b[] = '%-' . $esc; }
        }
        if ($core !== '' && $text) {
            $esc = $this->likeEsc($core);
            $when[] = 'WHEN ' . implode(' OR ', array_map(fn ($t) => '(' . $t . ' = ? OR ' . $t . ' LIKE ?)', $text)) . ' THEN 1';
            foreach ($text as $t) { $b[] = $core; $b[] = $esc . '%'; }
        }
        if ($famCol && $fams) {
            $when[] = 'WHEN ' . $famCol . ' IN (' . implode(',', array_map('intval', $fams)) . ') THEN 2';
        }
        $amtVal = $bq->amount !== null ? number_format($bq->amount, 2, '.', '') : null;
        if ($amtVal !== null && $amounts) {
            $when[] = 'WHEN ' . implode(' OR ', array_map(fn ($a) => $a . ' = ?', $amounts)) . ' THEN 2';
            foreach ($amounts as $a) $b[] = $amtVal;
        }
        $sql = $when ? 'CASE ' . implode(' ', $when) . ' ELSE 3 END' : '3';
        return [$sql, $b];
    }

    /**
     * Read a search for billing: status words, a payment method, an amount, a month or date,
     * and the words left over (number / reference / family, child or parent name / ID).
     */
    private function parseBilling(string $q, object $ctx): object
    {
        $bq = (object) ['kinds' => [], 'statuses' => [], 'methods' => [], 'amount' => null, 'bare' => null,
            'from' => null, 'to' => null, 'text' => '', 'nameFams' => [], 'refFams' => [], 'exactFams' => []];
        if (! $ctx->billing) return $bq;

        $tokens = array_values(array_filter(array_map(fn ($t) => trim($t, " \t,;:()"), preg_split('/\s+/u', mb_strtolower($q)) ?: []), 'strlen'));
        $hasMonth = (bool) array_filter($tokens, fn ($t) => isset(self::MONTHS[$t]));
        $words = [];
        $month = null; $year = null;
        foreach ($tokens as $t) {
            if (isset(self::TYPE_WORDS[$t])) { $bq->kinds[] = self::TYPE_WORDS[$t]; continue; }
            if (isset(self::STATUS_WORDS[$t])) { $bq->statuses[] = self::STATUS_WORDS[$t]; continue; }
            if (isset(self::METHOD_WORDS[$t])) { $bq->methods = array_values(array_unique(array_merge($bq->methods, self::METHOD_WORDS[$t]))); continue; }
            if (isset(self::MONTHS[$t])) { $month = self::MONTHS[$t]; continue; }
            if ($hasMonth && preg_match('/^(19|20)\d{2}$/', $t)) { $year = (int) $t; continue; }
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $t, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $bq->from = $bq->to = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                continue;
            }
            if (preg_match('/^(\d{4})-(\d{1,2})$/', $t, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
                $d = Carbon::create((int) $m[1], (int) $m[2], 1);
                $bq->from = $d->toDateString(); $bq->to = $d->copy()->endOfMonth()->toDateString();
                continue;
            }
            if (preg_match('/^\$?((?:\d{1,3}(?:,\d{3})+)|\d+)(?:\.(\d{1,2}))?$/', $t, $m)) {
                $amt = (float) (str_replace(',', '', $m[1]) . (isset($m[2]) && $m[2] !== '' ? '.' . $m[2] : ''));
                if (ctype_digit($t)) {
                    $bq->bare = $t;            // a fragment of a number AND an amount
                    $bq->amount = $amt;
                } else {
                    $bq->amount = $amt;
                }
                continue;
            }
            $words[] = $t;
        }
        if ($month) {
            $today = Carbon::parse($ctx->today);
            $y = $year ?: ($month > (int) $today->month ? (int) $today->year - 1 : (int) $today->year);
            $d = Carbon::create($y, $month, 1);
            $bq->from = $d->toDateString(); $bq->to = $d->copy()->endOfMonth()->toDateString();
        }
        // Words beat a bare number: "smith 412" = Smith's invoices for $412.
        if ($words && $bq->bare !== null) { $bq->bare = null; }
        $bq->text = implode(' ', $words);

        if ($bq->text !== '' && $ctx->centreIds) {
            $bq->nameFams = $this->familiesByName($ctx, $bq->text);
            // A family / child / parent ID finds the family's billing ("TA-F-0003", "C12").
            if (preg_match('/[a-z]/i', $bq->text) && preg_match('/\d/', $bq->text)) {
                $refs = RefCode::searchIds($bq->text, $ctx->agencyId) ?? [];
                $bq->refFams = $this->familiesFromRefs($ctx, $refs);
                $exact = RefCode::lookup($bq->text, $ctx->agencyId);
                if ($exact && in_array($exact['type'] ?? '', ['F', 'C', 'P'], true)) {
                    $bq->exactFams = $this->familiesFromRefs($ctx, [$exact['type'] => [(int) $exact['id']]]);
                }
            }
        }
        return $bq;
    }

    /** Families (live, in the caller's centres) whose name, child's name or parent's name/email matches. */
    private function familiesByName(object $ctx, string $text): array
    {
        if (mb_strlen($text) < 3 || ! preg_match('/\pL/u', $text)) return [];
        $term = $this->like($text);
        $cids = $ctx->centreIds ?: [0];
        $a = DB::table('families')->whereIn('centre_id', $cids)->whereNull('deleted_at')
            ->where('family_name', 'LIKE', $term)->limit(60)->pluck('id')->all();
        $b = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $cids)->whereNull('c.deleted_at')->whereNull('f.deleted_at')
            ->where(fn ($w) => $w->where('c.first_name', 'LIKE', $term)->orWhere('c.last_name', 'LIKE', $term)
                ->orWhere('c.preferred_name', 'LIKE', $term)
                ->orWhereRaw("CONCAT(c.first_name,' ',c.last_name) LIKE ?", [$term])
                ->orWhereRaw("CONCAT(COALESCE(NULLIF(c.preferred_name,''),c.first_name),' ',c.last_name) LIKE ?", [$term]))
            ->limit(60)->pluck('c.family_id')->all();
        $c = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereIn('f.centre_id', $cids)->whereNull('u.deleted_at')->whereNull('f.deleted_at')
            ->where(fn ($w) => $w->where('u.first_name', 'LIKE', $term)->orWhere('u.last_name', 'LIKE', $term)
                ->orWhere('u.email', 'LIKE', $term)
                ->orWhereRaw("CONCAT(u.first_name,' ',u.last_name) LIKE ?", [$term]))
            ->limit(60)->pluck('g.family_id')->all();
        return array_values(array_unique(array_map('intval', array_merge($a, $b, $c))));
    }

    /** Family ids behind F / C / P reference IDs, limited to the caller's centres (archived included: an ID is exact). */
    private function familiesFromRefs(object $ctx, array $refs): array
    {
        $cids = $ctx->centreIds ?: [0];
        $out = [];
        if (! empty($refs['F'])) {
            $out = array_merge($out, DB::table('families')->whereIn('id', $refs['F'])->whereIn('centre_id', $cids)->pluck('id')->all());
        }
        if (! empty($refs['C'])) {
            $out = array_merge($out, DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
                ->whereIn('c.id', $refs['C'])->whereIn('f.centre_id', $cids)->pluck('f.id')->all());
        }
        if (! empty($refs['P'])) {
            $out = array_merge($out, DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                ->whereIn('g.user_id', $refs['P'])->whereIn('f.centre_id', $cids)->pluck('f.id')->all());
        }
        return array_values(array_unique(array_map('intval', $out)));
    }

    /** Every family (any state) at the caller's centres — Accounting's director scope. */
    private function scopeFamilies(object $ctx): array
    {
        if (! isset($ctx->scopeFams)) {
            $ctx->scopeFams = $ctx->centreIds
                ? DB::table('families')->whereIn('centre_id', $ctx->centreIds)->pluck('id')->map(fn ($v) => (int) $v)->all()
                : [];
        }
        return $ctx->scopeFams;
    }

    /** The plan's answer for a feature, read once per request (the gate costs several queries). */
    private array $featureCache = [];

    private function allows(int $agencyId, string $feature): bool
    {
        return $this->featureCache[$agencyId . ':' . $feature] ??= EnforcePlanFeatures::allows($agencyId, $feature);
    }

    private function kindAllows(object $bq, string $kind): bool
    {
        return ! $bq->kinds || in_array($kind, $bq->kinds, true);
    }

    private function invoiceHash(object $ctx, int $invoiceId): string
    {
        // Accounting opens the invoice sheet (KT.openInvoiceById) for ?view=.
        return $ctx->acct ? '#external-billing?view=' . $invoiceId : '#billing-schedule';
    }

    private function providerHash(string $number, int $familyId, string $status): string
    {
        return '#external-billing?q=' . rawurlencode($number) . ($familyId ? '&family=' . $familyId : '')
            . (strtolower($status) === 'void' ? '&tab=void' : '');
    }

    // ── Words ────────────────────────────────────────────────────────────────────────

    /** The portal's invoice vocabulary (KT.invoiceStatus / KT.invoicePill), never a raw status. */
    private function statusWord(string $s, $due, string $today): string
    {
        $s = strtolower(trim($s));
        if (in_array($s, ['sent', 'open', 'unpaid', 'issued', 'outstanding'], true)) {
            return ($due && substr((string) $due, 0, 10) > $today) ? 'Scheduled' : 'Issued';
        }
        return [
            'draft' => 'Pending', 'overdue' => 'Overdue', 'paid' => 'Paid', 'partial' => 'Partly paid',
            'refunded' => 'Refunded', 'void' => 'Void', 'upcoming' => 'Upcoming', 'succeeded' => 'Received',
            'failed' => 'Failed', 'pending' => 'Pending', 'disputed' => 'Disputed',
            'awaiting_approval' => 'Awaiting approval', 'approved' => 'Approved',
        ][$s] ?? ($s === '' ? '—' : ucfirst(str_replace('_', ' ', $s)));
    }

    private function methodWord(string $m): string
    {
        return [
            'interac' => 'Interac e-Transfer', 'eft' => 'Direct debit', 'card' => 'Card', 'stripe_card' => 'Card',
            'helcim_card' => 'Card', 'stripe_ach' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque',
            'manual' => 'Recorded by the centre',
        ][strtolower($m)] ?? ($m === '' ? 'Payment' : ucfirst(str_replace('_', ' ', $m)));
    }

    private function famLabel($name, bool $archived): string
    {
        $n = trim((string) $name);
        if ($n === '') $n = 'Unnamed';
        if (! preg_match('/\bfamily$/i', $n)) $n .= ' family';
        return $n . ($archived ? ' (archived)' : '');
    }

    private function money($v): string
    {
        return '$' . number_format((float) $v, 2);
    }

    /** A DATE column (no zone) as "Oct 3, 2026". */
    private function day($d): string
    {
        $s = substr((string) $d, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? Carbon::createFromFormat('Y-m-d', $s)->format('M j, Y') : (string) $d;
    }

    private function sub(?string $code, ?string $rest): string
    {
        return trim(($code ? $code . ' · ' : '') . ($rest ?? ''), ' ·');
    }

    // ── SQL helpers ──────────────────────────────────────────────────────────────────

    private function likeEsc(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }

    private function like(string $s): string
    {
        return '%' . $this->likeEsc($s) . '%';
    }

    /** CASE ranking columns against the query: 0 equal, 1 starts with, 2 a word starts with, 3 otherwise. */
    private function rankExpr(array $cols, string $q): array
    {
        $esc = $this->likeEsc($q);
        $b = [];
        $eq = []; $st = []; $wd = [];
        foreach ($cols as $c) { $eq[] = $c . ' = ?'; $b[] = $q; }
        foreach ($cols as $c) { $st[] = $c . ' LIKE ?'; $b[] = $esc . '%'; }
        foreach ($cols as $c) { $wd[] = $c . ' LIKE ?'; $b[] = '% ' . $esc . '%'; }
        return ['CASE WHEN ' . implode(' OR ', $eq) . ' THEN 0 WHEN ' . implode(' OR ', $st) . ' THEN 1 WHEN '
            . implode(' OR ', $wd) . ' THEN 2 ELSE 3 END', $b];
    }

    private function emptyGroups(): array
    {
        return array_fill_keys(array_keys(SearchRegistry::SOURCES), []);
    }

    private function resolveAgencyId(Request $request): ?int
    {
        // v22p20: honor X-Active-Agency-Id header for multi-agency admins.
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatformAdmin) {
            /* A platform admin searches the agency they have SELECTED, never a default:
               the old fallback to the first agency returned iLearn's people to a super-admin
               with no agency chosen (the same leak fixed elsewhere in v22p98). */
            return ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists())
                ? $activeId : null;
        }
        if ($activeId && DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->whereIn('role', ['agency_admin', 'centre_director'])
                ->where('active', true)
                ->where(fn ($w) => $w->where('agency_id', $activeId)
                    ->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $activeId)->pluck('id')))
                ->exists()) {
            return $activeId;
        }
        $byAgencyAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');
        if ($byAgencyAdmin) return (int) $byAgencyAdmin;

        // Director: the agency of their centre. What they SEE is narrowed to their own
        // centres by Visibility in query().
        $directorCentreId = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'centre_director')
            ->where('active', true)
            ->value('centre_id');
        if ($directorCentreId) {
            return (int) DB::table('centres')->where('id', $directorCentreId)->value('agency_id');
        }
        return null;
    }
}
