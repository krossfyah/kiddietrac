<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Setting and clearing an immunization exemption — ONE definition.
 *
 * There are two ways into this: a director exempting a single dose from the schedule,
 * and a director working through an exemption form that has been filed, ticking every
 * dose it covers. They are the same write, and the moment they are two pieces of code
 * they start disagreeing about the edge that matters — what happens when a row for
 * that dose already exists.
 *
 * THE RULES, in one place:
 *
 *   • A dose is matched by `vaccine|dose_label`, case- and space-insensitively, the
 *     same way the schedule matches one. An exemption has to land on the dose the
 *     reader is looking at, not beside it.
 *   • IDEMPOTENT. Exempting twice updates one row rather than leaving two: two rows
 *     for one dose is how a "done" and an "exempt" end up disagreeing about the same
 *     vaccine.
 *   • A dose already recorded as GIVEN is never silently turned into an exemption.
 *     Somebody wrote a date against it from a card; an exemption form arriving later
 *     does not unsay that, and quietly flipping it would destroy the more specific
 *     record. It is reported back as skipped so the caller can say so.
 *   • Clearing REMOVES the row when the row exists only to carry the exemption, and
 *     otherwise just lifts the flag. Leaving an empty row behind would read as neither
 *     given nor due.
 */
final class ImmunizationExemption
{
    public const SET = 'set';
    public const UPDATED = 'updated';
    public const SKIPPED_GIVEN = 'skipped_given';
    public const REMOVED = 'removed';
    public const CLEARED = 'cleared';
    public const NOTHING = 'nothing';

    /** vaccine|dose, lowercased and trimmed — the schedule's own matching rule. */
    public static function key(?string $vaccine, ?string $doseLabel): string
    {
        return mb_strtolower(trim(((string) $vaccine) . '|' . ((string) $doseLabel)));
    }

    /** The existing row for this dose, if there is one. */
    private static function find(int $childId, string $vaccine, ?string $doseLabel): ?object
    {
        $wanted = self::key($vaccine, $doseLabel);
        foreach (DB::table('immunizations')->where('child_id', $childId)->get() as $row) {
            if (self::key($row->vaccine, $row->dose_label) === $wanted) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Record this dose as exempt.
     *
     * @param  string|null  $proofUrl  the document the exemption was read off, when there is one
     * @return string  one of SET, UPDATED, SKIPPED_GIVEN
     */
    public static function set(
        int $childId,
        string $vaccine,
        ?string $doseLabel,
        string $reason,
        int $byUserId,
        ?string $proofUrl = null
    ): string {
        $vaccine = trim($vaccine);
        $doseLabel = trim((string) $doseLabel) ?: null;
        $existing = self::find($childId, $vaccine, $doseLabel);

        if ($existing && ! $existing->exempt && $existing->administered_on) {
            return self::SKIPPED_GIVEN;
        }

        if ($existing) {
            DB::table('immunizations')->where('id', $existing->id)->update(array_filter([
                'exempt' => 1,
                'exemption_reason' => $reason,
                'proof_document_url' => $proofUrl ?: $existing->proof_document_url,
                'updated_at' => now(),
            ], fn ($v) => $v !== null));

            return self::UPDATED;
        }

        DB::table('immunizations')->insert([
            'child_id' => $childId,
            'vaccine' => $vaccine,
            'dose_label' => $doseLabel,
            'administered_on' => null,
            'exempt' => 1,
            'exemption_reason' => $reason,
            'proof_document_url' => $proofUrl,
            'recorded_by_id' => $byUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return self::SET;
    }

    /**
     * Lift the exemption from this dose.
     *
     * @return string  one of REMOVED, CLEARED, NOTHING
     */
    public static function clear(int $childId, string $vaccine, ?string $doseLabel): string
    {
        $existing = self::find($childId, trim($vaccine), trim((string) $doseLabel) ?: null);
        if (! $existing || ! $existing->exempt) {
            return self::NOTHING;
        }

        $onlyAnExemption = ! $existing->administered_on && ! $existing->lot_number
            && ! $existing->site && ! $existing->clinic_name && ! $existing->administered_by;

        if ($onlyAnExemption) {
            DB::table('immunizations')->where('id', $existing->id)->delete();

            return self::REMOVED;
        }

        DB::table('immunizations')->where('id', $existing->id)->update([
            'exempt' => 0,
            'exemption_reason' => null,
            'updated_at' => now(),
        ]);

        return self::CLEARED;
    }
}
