<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Text & voice allowance, billed as "SMS Incremental" blocks (2026-10-28).
 *
 * Anthony: "we need a way to monitor the number of texts and voice messages per agency and bill based on a threshold
 * that exceeds - pls add an add-on for SMS Incremental - $15.00 ... configurable, we can use defaults as 1,000 for
 * text and voice". His choices (question prompt):
 *   - $15 per extra BLOCK: each block is the size of the threshold (1,000 texts allowed, 2,400 used = 2 blocks over);
 *   - counted per CALENDAR MONTH in the agency's own timezone;
 *   - he APPROVES each charge: nothing reaches an invoice until a platform admin adds it;
 *   - EVERYTHING in and out counts - texts sent and received, calls placed - each against its own threshold.
 *
 * Counted exactly as Usage & spend counts (UsageSpend::period), so the two screens never disagree: texts that went
 * through (not failed, not skipped - a failed or consent-skipped text never reached anyone), and calls that
 * connected (duration over zero), from the announcement call-outs (voice_calls) and the agency's own portal phone
 * (outbound_calls). KiddieTrac's own 1-855 line (inbound_calls, voicemails) belongs to no agency and is not counted.
 *
 * An approved charge becomes a one-off platform_invoice_extras line (part number of the SMS Incremental add-on),
 * which PlatformAccounts::applyExtras puts on the agency's next KiddieTrac invoice - the path every extra takes.
 */
final class MessagingOverage
{
    public const PRODUCT_NAME = 'SMS Incremental';
    public const DEFAULT_PRICE = 15.00;
    public const DEFAULTS = ['texts' => 1000, 'calls' => 1000];
    private const KEY = 'overage.thresholds';

    /** {default: {texts, calls}, agencies: {id: {texts?, calls?}}} - a blank agency value uses the default. */
    public static function settings(): array
    {
        $raw = json_decode((string) PlatformSettings::get(self::KEY, ''), true);
        $d = is_array($raw['default'] ?? null) ? $raw['default'] : [];
        $out = ['default' => [], 'agencies' => []];
        foreach (self::DEFAULTS as $k => $v) {
            $out['default'][$k] = isset($d[$k]) && is_numeric($d[$k]) && $d[$k] > 0 ? (int) $d[$k] : $v;
        }
        foreach ((array) ($raw['agencies'] ?? []) as $id => $row) {
            foreach (self::DEFAULTS as $k => $_) {
                if (isset($row[$k]) && is_numeric($row[$k]) && $row[$k] > 0) {
                    $out['agencies'][(int) $id][$k] = (int) $row[$k];
                }
            }
        }

        return $out;
    }

    public static function saveSettings(array $default, array $agencies): array
    {
        $clean = ['default' => [], 'agencies' => []];
        foreach (self::DEFAULTS as $k => $v) {
            $clean['default'][$k] = isset($default[$k]) && is_numeric($default[$k]) && $default[$k] >= 1 && $default[$k] < 10000000 ? (int) $default[$k] : $v;
        }
        $live = DB::table('agencies')->pluck('id')->map(fn ($v) => (int) $v)->flip();
        foreach ($agencies as $id => $row) {
            if (! isset($live[(int) $id]) || ! is_array($row)) {
                continue;
            }
            foreach (self::DEFAULTS as $k => $_) {
                if (isset($row[$k]) && $row[$k] !== '' && is_numeric($row[$k]) && $row[$k] >= 1 && $row[$k] < 10000000) {
                    $clean['agencies'][(int) $id][$k] = (int) $row[$k];
                }
            }
        }
        PlatformSettings::set(self::KEY, json_encode($clean));

        return self::settings();
    }

    public static function thresholdsFor(array $s, int $agencyId): array
    {
        $texts = $s['agencies'][$agencyId]['texts'] ?? $s['default']['texts'];

        /* A PREPAID SMS INCREMENTAL RAISES THE ALLOWANCE (2026-11-26, Anthony: give 'SMS Incremental' "a real monthly SMS
           quota increase"). Each live SMS Incremental line on the agency's licence adds its qty in blocks, each the size
           of the agency's text allowance, to the texts allowed each month - so a block bought ahead is not charged again
           as overage. Nothing here stops a text: the allowance only decides what is billed. */
        $texts += self::prepaidBlocks($agencyId) * $texts;

        return ['texts' => $texts, 'calls' => $s['agencies'][$agencyId]['calls'] ?? $s['default']['calls']];
    }

    /** Blocks of texts bought ahead: the qty on the agency's live SMS Incremental licence lines. */
    public static function prepaidBlocks(int $agencyId): int
    {
        $pid = DB::table('sales_products')->where('name', self::PRODUCT_NAME)->where('kind', 'addon')->value('id');
        if (! $pid) return 0;

        return (int) floor((float) DB::table('agency_licences')->where('agency_id', $agencyId)->where('product_id', $pid)
            ->whereIn('status', Licences::LIVE)->sum('qty'));
    }

    /** The SMS Incremental add-on in the catalogue, created (KT-ADD part number, $15 per block) the first time. */
    public static function product(): object
    {
        $p = DB::table('sales_products')->where('name', self::PRODUCT_NAME)->first();
        if ($p) {
            return $p;
        }
        $id = DB::table('sales_products')->insertGetId([
            'name' => self::PRODUCT_NAME, 'kind' => 'addon', 'price' => self::DEFAULT_PRICE, 'unit' => 'block', 'active' => 1,
            'description' => 'Texts or calls over the monthly allowance, per block the size of the allowance (default 1,000).',
            'part_number' => Licences::nextPart('addon'), 'sort' => 900, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('sales_products')->where('id', $id)->first();
    }

    /** "YYYY-MM", defaulting to this month in Toronto (the platform's billing clock). */
    public static function month(?string $m): string
    {
        return ($m && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) ? $m : Carbon::now('America/Toronto')->format('Y-m');
    }

    /** One agency's texts and calls for one of its own calendar months. */
    public static function usage(int $agencyId, string $month): array
    {
        $first = $month . '-01';
        [$from, $to] = AgencyTime::spanRange($agencyId, $first, Carbon::parse($first)->endOfMonth()->toDateString());
        $texts = DB::table('sms_messages')->where('agency_id', $agencyId)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereNotIn('status', ['failed', 'skipped'])->count();
        $calls = DB::table('voice_calls')->where('agency_id', $agencyId)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->where('duration_secs', '>', 0)->count();
        $calls += DB::table('outbound_calls')->where('agency_id', $agencyId)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->where('duration_seconds', '>', 0)->count();

        return ['texts' => $texts, 'calls' => $calls];
    }

    /** Whole blocks over: 1,000 allowed and 2,400 used = 2 (1,001-2,000 is one block, 2,001-3,000 the next). */
    public static function blocks(int $used, int $threshold): int
    {
        return ($threshold > 0 && $used > $threshold) ? (int) ceil(($used - $threshold) / $threshold) : 0;
    }

    /** Every live agency for a month: use, allowance, blocks over, amount, and any decision already taken. */
    public static function rows(string $month): array
    {
        $s = self::settings();
        $p = self::product();
        $unit = (int) round((float) $p->price * 100);
        $charges = DB::table('messaging_overage_charges')->where('month', $month)->get()->keyBy('agency_id');
        $out = [];
        foreach (DB::table('agencies')->whereNull('deleted_at')->orderBy('name')
            ->get(['id', 'name', 'billing_status', 'next_invoice_at']) as $a) {
            $id = (int) $a->id;
            $u = self::usage($id, $month);
            $t = self::thresholdsFor($s, $id);
            $tb = self::blocks($u['texts'], $t['texts']);
            $cb = self::blocks($u['calls'], $t['calls']);
            $c = $charges[$id] ?? null;
            $out[] = [
                'agency_id' => $id, 'name' => $a->name,
                'texts' => $u['texts'], 'calls' => $u['calls'],
                'text_threshold' => $t['texts'], 'call_threshold' => $t['calls'],
                'custom' => (object) ($s['agencies'][$id] ?? []),
                'text_blocks' => $tb, 'call_blocks' => $cb, 'blocks' => $tb + $cb,
                'amount_cents' => ($tb + $cb) * $unit,
                'billing' => ['status' => (string) $a->billing_status, 'next_invoice_at' => $a->next_invoice_at ? (string) $a->next_invoice_at : null],
                'charge' => $c ? ['id' => (int) $c->id, 'status' => $c->status, 'blocks' => (int) $c->text_blocks + (int) $c->call_blocks,
                    'amount_cents' => (int) $c->amount_cents, 'extra_id' => $c->extra_id ? (int) $c->extra_id : null,
                    'on_invoice' => $c->extra_id ? (bool) DB::table('platform_invoice_extras')->where('id', $c->extra_id)->where('applied_count', '>', 0)->exists() : false,
                    'decided_at' => (string) $c->updated_at] : null,
            ];
        }
        usort($out, fn ($x, $y) => ($y['blocks'] <=> $x['blocks']) ?: strcmp($x['name'], $y['name']));

        return ['month' => $month, 'settings' => $s, 'product' => ['name' => $p->name, 'part_number' => $p->part_number,
            'unit_cents' => $unit, 'unit' => $p->unit], 'rows' => $out];
    }

    /** Approve: the month's blocks onto the agency's next KiddieTrac invoice, once. */
    public static function charge(int $agencyId, string $month, int $userId): array
    {
        return DB::transaction(function () use ($agencyId, $month, $userId) {
            $ex = DB::table('messaging_overage_charges')->where('agency_id', $agencyId)->where('month', $month)->lockForUpdate()->first();
            if ($ex && $ex->status === 'added') {
                throw new \RuntimeException('This month is already on its way to the invoice.');
            }
            $s = self::settings();
            $u = self::usage($agencyId, $month);
            $t = self::thresholdsFor($s, $agencyId);
            $tb = self::blocks($u['texts'], $t['texts']);
            $cb = self::blocks($u['calls'], $t['calls']);
            if ($tb + $cb < 1) {
                throw new \RuntimeException('This agency is within its allowance for ' . $month . '.');
            }
            $p = self::product();
            $unit = (int) round((float) $p->price * 100);
            $label = Carbon::parse($month . '-01')->format('F Y');
            $parts = [];
            if ($tb) {
                $parts[] = number_format($u['texts']) . ' texts (' . number_format($t['texts']) . ' included)';
            }
            if ($cb) {
                $parts[] = number_format($u['calls']) . ' calls (' . number_format($t['calls']) . ' included)';
            }
            $extraId = DB::table('platform_invoice_extras')->insertGetId([
                'agency_id' => $agencyId,
                'description' => self::PRODUCT_NAME . ' — ' . $label . ': ' . implode(', ', $parts) . ' · ' . ($tb + $cb) . ' block' . ($tb + $cb === 1 ? '' : 's'),
                'part_number' => $p->part_number, 'qty' => $tb + $cb, 'unit_cents' => $unit, 'taxable' => 1,
                'recurring' => 0, 'starts_on' => null, 'ends_on' => null, 'active' => 1, 'applied_count' => 0,
                'created_by_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = ['texts' => $u['texts'], 'calls' => $u['calls'], 'text_threshold' => $t['texts'], 'call_threshold' => $t['calls'],
                'text_blocks' => $tb, 'call_blocks' => $cb, 'unit_cents' => $unit, 'amount_cents' => ($tb + $cb) * $unit,
                'status' => 'added', 'extra_id' => $extraId, 'decided_by_id' => $userId, 'updated_at' => now()];
            if ($ex) {
                DB::table('messaging_overage_charges')->where('id', $ex->id)->update($row);
            } else {
                DB::table('messaging_overage_charges')->insert($row + ['agency_id' => $agencyId, 'month' => $month, 'created_at' => now()]);
            }

            return $row + ['description_extra_id' => $extraId];
        });
    }

    /** Waive this month (recorded, so it stops asking). */
    public static function dismiss(int $agencyId, string $month, int $userId): void
    {
        $ex = DB::table('messaging_overage_charges')->where('agency_id', $agencyId)->where('month', $month)->first();
        if ($ex && $ex->status === 'added') {
            throw new \RuntimeException('Undo the charge first.');
        }
        $u = self::usage($agencyId, $month);
        $t = self::thresholdsFor(self::settings(), $agencyId);
        $row = ['texts' => $u['texts'], 'calls' => $u['calls'], 'text_threshold' => $t['texts'], 'call_threshold' => $t['calls'],
            'text_blocks' => self::blocks($u['texts'], $t['texts']), 'call_blocks' => self::blocks($u['calls'], $t['calls']),
            'unit_cents' => 0, 'amount_cents' => 0, 'status' => 'waived', 'extra_id' => null, 'decided_by_id' => $userId, 'updated_at' => now()];
        if ($ex) {
            DB::table('messaging_overage_charges')->where('id', $ex->id)->update($row);
        } else {
            DB::table('messaging_overage_charges')->insert($row + ['agency_id' => $agencyId, 'month' => $month, 'created_at' => now()]);
        }
    }

    /** Take a decision back - a charge only while its line has not been invoiced yet. */
    public static function undo(int $chargeId): object
    {
        return DB::transaction(function () use ($chargeId) {
            $c = DB::table('messaging_overage_charges')->where('id', $chargeId)->lockForUpdate()->first();
            if (! $c) {
                throw new \RuntimeException('That decision no longer exists.');
            }
            if ($c->extra_id) {
                $x = DB::table('platform_invoice_extras')->where('id', $c->extra_id)->first();
                if ($x && (int) $x->applied_count > 0) {
                    throw new \RuntimeException('It is already on an invoice. Credit it on that invoice instead.');
                }
                DB::table('platform_invoice_extras')->where('id', $c->extra_id)->delete();
            }
            DB::table('messaging_overage_charges')->where('id', $chargeId)->delete();

            return $c;
        });
    }
}
