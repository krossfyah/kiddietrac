<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v15 reseller migration — additive only, idempotent.
 *
 * Adds to agencies:
 *   - feature_flags JSON           (per-agency feature gating)
 *   - plan_code VARCHAR(40)        ('free' | 'starter' | 'growth' | 'enterprise')
 *   - plan_amount_cents INT        (monthly recurring revenue contribution)
 *   - plan_currency CHAR(3)        (default 'CAD')
 *   - billing_status VARCHAR(20)   ('trial' | 'active' | 'past_due' | 'cancelled')
 *   - billing_starts_at DATE       (for MRR / cohort calcs)
 *   - cancelled_at TIMESTAMP NULL  (for churn calcs)
 *   - brand_logo_url VARCHAR(500)  (white-label invoicing)
 *   - brand_primary_color CHAR(7)
 *   - brand_support_email VARCHAR(160)
 *   - brand_bank_info TEXT         (free-form, shown on invoices)
 *   - powered_by_visible TINYINT   (1 = show "powered by Kiddietrac" footer)
 *
 * Uses raw "IF NOT EXISTS" SQL because Laravel's Schema::hasColumn()
 * is reliable but slower per-column. We do this in one ALTER statement
 * per column to keep transactions short.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agencies')) {
            // Defensive: don't try to alter a non-existent table.
            echo "  ⚠ agencies table not found, skipping migration\n";
            return;
        }

        $columns = [
            'feature_flags'         => "JSON NULL",
            'plan_code'             => "VARCHAR(40) NOT NULL DEFAULT 'free'",
            'plan_amount_cents'     => "INT NOT NULL DEFAULT 0",
            'plan_currency'         => "CHAR(3) NOT NULL DEFAULT 'CAD'",
            'billing_status'        => "VARCHAR(20) NOT NULL DEFAULT 'trial'",
            'billing_starts_at'     => "DATE NULL",
            'cancelled_at'          => "TIMESTAMP NULL",
            'brand_logo_url'        => "VARCHAR(500) NULL",
            'brand_primary_color'   => "CHAR(7) NULL",
            'brand_support_email'   => "VARCHAR(160) NULL",
            'brand_bank_info'       => "TEXT NULL",
            'powered_by_visible'    => "TINYINT(1) NOT NULL DEFAULT 1",
        ];

        foreach ($columns as $name => $type) {
            if (! Schema::hasColumn('agencies', $name)) {
                try {
                    DB::statement("ALTER TABLE agencies ADD COLUMN `{$name}` {$type}");
                } catch (\Throwable $e) {
                    // If it failed for any reason other than already-exists, surface it
                    if (stripos($e->getMessage(), 'duplicate') === false) {
                        throw $e;
                    }
                }
            }
        }

        // Index on plan_code for MRR queries
        $hasIdx = collect(DB::select("SHOW INDEX FROM agencies WHERE Key_name = 'idx_agencies_plan_code'"))->isNotEmpty();
        if (! $hasIdx) {
            try {
                DB::statement("CREATE INDEX idx_agencies_plan_code ON agencies(plan_code)");
            } catch (\Throwable $e) { /* idempotent */ }
        }

        $hasIdx2 = collect(DB::select("SHOW INDEX FROM agencies WHERE Key_name = 'idx_agencies_billing_status'"))->isNotEmpty();
        if (! $hasIdx2) {
            try {
                DB::statement("CREATE INDEX idx_agencies_billing_status ON agencies(billing_status)");
            } catch (\Throwable $e) { /* idempotent */ }
        }
    }

    public function down(): void
    {
        // Intentionally a no-op. Dropping columns is destructive and we
        // never want this to happen via `migrate:rollback`.
    }
};
