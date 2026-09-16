<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * One place that keeps settings.brand_address in step with the address columns.
 *
 * The address lives in six columns — address_line1, address_line2, city, province,
 * postal_code, country — and TWO editors write them: the platform one
 * (PlatformController::updateAgency) and the agency's own (AgencyManagementController).
 *
 * `settings.brand_address` is the printed form of the same thing, read by the invoice PDF
 * and by the branding payload. It is derived, not authored: nobody types it any more.
 *
 * This exists because the rebuild was written into the platform editor only. That was fine
 * until the agency editor could edit the address too — at which point an agency admin
 * correcting their own postcode would have left the invoice showing the old one, with the
 * columns and the printed string disagreeing and no way to tell which was right.
 */
final class AgencyAddress
{
    /** The address columns, in the order they are printed. */
    public const PARTS = ['address_line1', 'address_line2', 'city', 'province', 'postal_code'];

    /** Does this payload touch the address at all? */
    public static function touches(array $data): bool
    {
        return (bool) array_intersect(self::PARTS, array_keys($data));
    }

    /**
     * Rebuild the printed address from what the columns will hold after this write.
     *
     * Takes the incoming $data and the current row, because a save usually carries only
     * the fields that changed — composing from $data alone would drop the city when
     * somebody edits nothing but the postcode.
     *
     * @param  array  $data     the pending column writes
     * @param  object $current  the agency row as it is now
     * @return string|null      null when there is no address at all
     */
    public static function compose(array $data, $current): ?string
    {
        $part = function (string $k) use ($data, $current) {
            $v = array_key_exists($k, $data) ? $data[$k] : ($current->$k ?? null);

            return trim((string) $v);
        };

        // City, province and postcode share a line, the way an envelope is written.
        $locality = trim(implode(' ', array_filter([
            $part('city'), $part('province'), $part('postal_code'),
        ])));

        $composed = implode("\n", array_filter([
            $part('address_line1'),
            $part('address_line2'),
            $locality,
        ]));

        return $composed !== '' ? $composed : null;
    }

    /**
     * Fold the rebuilt address into a settings blob, returning the JSON to store.
     *
     * $pendingSettings is whatever the caller has already decided to write, so an editor
     * that is also changing other settings keys in the same save does not lose them.
     */
    public static function applyToSettings(array $data, $current, ?string $pendingSettings = null): string
    {
        $settings = json_decode($pendingSettings ?? ($current->settings ?? '{}'), true);
        $settings = is_array($settings) ? $settings : [];
        $settings['brand_address'] = self::compose($data, $current);

        return json_encode($settings);
    }
}
