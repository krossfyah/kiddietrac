<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt can belong to an external invoice.
 *
 * $37,904.48 has been received through the iLearn sync, and none of it has a row in
 * `payments`. That is the table refunds hang off — `payment_refunds.payment_id` — so
 * until now none of that money could be refunded at all. The refund screen simply had
 * nothing to show.
 *
 * WHY NOT JUST WRITE THE RECEIPT AGAINST invoice_id. Because `payments.invoice_id`
 * carries a foreign key to `invoices.id`, and the two id ranges overlap badly: native
 * invoices run 9..64, external ones 2..464. Writing external invoice #22's receipt into
 * invoice_id would attach it to NATIVE invoice #22 — a different family's record — and
 * the foreign key would happily accept it. Silent corruption of the money table, found
 * before it shipped.
 *
 * So a receipt names which kind of invoice it belongs to. Exactly one of the two columns
 * is set; that is enforced in the application rather than by a CHECK constraint, because
 * CHECK support on this host is unreliable (the demo seeder has already been bitten by
 * json_valid CHECKs behaving differently than expected).
 *
 * Anthony chose this over letting refunds point at invoices directly, so that a refund
 * keeps ONE shape everywhere: it reverses a receipt. `payment_refunds` is untouched by
 * this migration, and every "how much has been refunded" query keeps working unchanged.
 *
 * Nothing is backfilled. A receipt row is created at refund time, for the one invoice
 * being refunded, so the sync is untouched and 227 historical rows are not invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* The FK has to come off before the column can become nullable, and it is put
           back afterwards so a native receipt still cannot name an invoice that does not
           exist. Raw DDL because the schema builder cannot express dropping and
           recreating a foreign key around a MODIFY on this MySQL version. */
        $fk = $this->foreignKeyOn('payments', 'invoice_id');
        if ($fk) {
            DB::statement("ALTER TABLE `payments` DROP FOREIGN KEY `{$fk}`");
        }

        DB::statement('ALTER TABLE `payments` MODIFY `invoice_id` BIGINT UNSIGNED NULL');

        if (! Schema::hasColumn('payments', 'external_invoice_id')) {
            Schema::table('payments', function ($table) {
                $table->unsignedBigInteger('external_invoice_id')->nullable()->after('invoice_id')->index();
            });
        }

        if ($fk) {
            DB::statement(
                'ALTER TABLE `payments` ADD CONSTRAINT `payments_invoice_id_foreign` '
                . 'FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`)'
            );
        }

        /* An external receipt must name a real external invoice for the same reason. */
        DB::statement(
            'ALTER TABLE `payments` ADD CONSTRAINT `payments_external_invoice_id_foreign` '
            . 'FOREIGN KEY (`external_invoice_id`) REFERENCES `external_invoices` (`id`)'
        );
    }

    public function down(): void
    {
        foreach (['payments_external_invoice_id_foreign'] as $c) {
            try { DB::statement("ALTER TABLE `payments` DROP FOREIGN KEY `{$c}`"); } catch (\Throwable $e) {}
        }
        if (Schema::hasColumn('payments', 'external_invoice_id')) {
            Schema::table('payments', function ($table) { $table->dropColumn('external_invoice_id'); });
        }
        /* invoice_id is deliberately left nullable: rows written while this migration
           was applied may have no native invoice, and forcing NOT NULL back would fail
           on them or, worse, need them filled with something untrue. */
    }

    /** The current foreign-key name on a column, or null if it has none. */
    private function foreignKeyOn(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME n FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
            [$table, $column]
        );

        return $row->n ?? null;
    }
};
