<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Late-pickup fee rules (2026-08-25).
 *
 * ONE implementation of the maths, because this will be needed in at least three places —
 * the live check-out path, the nightly sweep for pickups nobody recorded promptly, and the
 * reports that show what was charged. Three copies would drift, and this one produces
 * money.
 *
 * NOTHING here decides policy. Every value comes from the agency, optionally overridden
 * per centre. A null on the centre means INHERIT — which is why the override columns are
 * nullable rather than defaulted; a 0 there would silently mean "free", and a provider
 * leaving a box blank must never mean that.
 */
final class LateFeePolicy
{
    public const MODES = ['per_minute', 'per_block', 'flat'];

    /**
     * The effective policy for a centre: agency settings with any centre override applied.
     *
     * @param  object  $agency  row from agencies
     * @param  object|null  $centre  row from centres, or null for the agency policy alone
     */
    public static function resolve(object $agency, ?object $centre = null): array
    {
        /* Tri-state: null on the centre inherits, true/false overrides. A provider can
           therefore switch the fee off for themselves without clearing the agency rule. */
        $enabled = $centre !== null && $centre->late_fee_enabled !== null
            ? (bool) $centre->late_fee_enabled
            : (bool) ($agency->late_fee_enabled ?? false);

        $pick = function (string $col, $default) use ($agency, $centre) {
            if ($centre !== null && ($centre->$col ?? null) !== null) {
                return $centre->$col;
            }

            return $agency->$col ?? $default;
        };

        $mode = (string) $pick('late_fee_mode', 'per_minute');

        return [
            'enabled' => $enabled,
            'mode' => in_array($mode, self::MODES, true) ? $mode : 'per_minute',
            'grace_minutes' => (int) $pick('late_fee_grace_minutes', 0),
            'block_minutes' => max(1, (int) $pick('late_fee_block_minutes', 15)),
            'rate_cents' => (int) $pick('late_fee_rate_cents', 0),
            /* Cap is agency-only on purpose: it is a protection for families, so a
               provider should not be able to raise or remove it locally. */
            'max_cents' => (int) ($agency->late_fee_max_cents ?? 0),
            'auto_charge' => (bool) ($agency->late_fee_auto_charge ?? false),
            'label' => $agency->late_fee_label ?: 'Late pickup fee',
        ];
    }

    /**
     * Minutes past closing, ignoring the grace period.
     *
     * Returns 0 when not late, when the centre has no closing time, or when the pickup is
     * within grace — the caller should treat 0 as "no fee" without needing to know which.
     */
    public static function lateMinutes(?string $closeTime, Carbon $pickedUpAt, int $graceMinutes): int
    {
        if (! $closeTime) {
            return 0;   // no closing time recorded: nothing to be late for
        }

        $close = $pickedUpAt->copy()->setTimeFromTimeString($closeTime)->addMinutes($graceMinutes);
        if ($pickedUpAt->lessThanOrEqualTo($close)) {
            return 0;
        }

        return (int) $close->diffInMinutes($pickedUpAt);
    }

    /**
     * The fee in cents for a pickup this many minutes late.
     *
     * $lateMinutes has already had grace subtracted by lateMinutes().
     */
    public static function feeCents(int $lateMinutes, array $policy): int
    {
        if (! $policy['enabled'] || $lateMinutes <= 0 || $policy['rate_cents'] <= 0) {
            return 0;
        }

        $fee = match ($policy['mode']) {
            /* Part of a block counts as a block — the common convention, and the reason
               block mode exists rather than just rounding per-minute. */
            'per_block' => (int) ceil($lateMinutes / $policy['block_minutes']) * $policy['rate_cents'],
            'flat' => $policy['rate_cents'],
            default => $lateMinutes * $policy['rate_cents'],
        };

        if ($policy['max_cents'] > 0) {
            $fee = min($fee, $policy['max_cents']);
        }

        return $fee;
    }

    /** Human explanation for the invoice line and the audit trail. */
    public static function explain(int $lateMinutes, array $policy, string $currency = 'CAD'): string
    {
        if ($lateMinutes <= 0) {
            return 'Not late';
        }

        $money = fn (int $c) => PlatformBilling::money($c, $currency);

        return match ($policy['mode']) {
            'per_block' => ceil($lateMinutes / $policy['block_minutes']) . ' × '
                . $policy['block_minutes'] . '-minute block at ' . $money($policy['rate_cents'])
                . ' (' . $lateMinutes . ' min late)',
            'flat' => 'Flat ' . $money($policy['rate_cents']) . ' (' . $lateMinutes . ' min late)',
            default => $lateMinutes . ' min × ' . $money($policy['rate_cents']),
        };
    }
}
