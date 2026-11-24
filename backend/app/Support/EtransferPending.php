<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices a family says they paid by Interac e-Transfer, waiting for a person to confirm the money arrived
 * (EtransferRequestController::report, 2026-11-11). Anthony chose "pending until approved": nothing is booked on the
 * family's word, and meanwhile the overdue reminders (InvoiceReminders) and late fees (ApplyLateFeesCommand) leave those
 * invoices alone. Approving records the payment; rejecting puts the invoice back to open and the jobs pick it up again.
 */
final class EtransferPending
{
    private static array $memo = [];

    /** @return array<string, true> keyed 'inv:{id}' (a KiddieTrac invoice) / 'ext:{id}' (a synced one) */
    public static function keys(int $agencyId): array
    {
        $k = $agencyId . '|' . date('YmdHi');   // one read a minute: the jobs run in long-lived processes
        if (isset(self::$memo[$k])) return self::$memo[$k];
        $out = [];
        if (Schema::hasTable('etransfer_requests') && Schema::hasColumn('etransfer_requests', 'reference')) {
            foreach (DB::table('etransfer_requests')->where('agency_id', $agencyId)->where('status', 'reported')->pluck('items') as $items) {
                foreach ((array) json_decode((string) $items, true) as $it) {
                    if (! empty($it['id'])) $out[($it['kind'] ?? '') . ':' . (int) $it['id']] = true;
                }
            }
        }
        if (count(self::$memo) > 200) self::$memo = [];

        return self::$memo[$k] = $out;
    }

    /** After a report is made, approved, rejected or withdrawn: the jobs and screens in this process see it at once. */
    public static function forget(): void
    {
        self::$memo = [];
    }

    /** $kind: inv | ext */
    public static function has(int $agencyId, string $kind, int $id): bool
    {
        return isset(self::keys($agencyId)[$kind . ':' . $id]);
    }
}
