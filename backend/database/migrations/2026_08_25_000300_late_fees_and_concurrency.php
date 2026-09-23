<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable late-pickup fees, and a room's concurrent limit (2026-08-25).
 *
 * The fee POLICY is not decided here. Grace period, how it is calculated, the rate, any
 * cap, and whether a charge posts automatically are all agency settings, because these
 * vary by licence and by what a provider has agreed with families — hardcoding one shape
 * would make the feature wrong for everyone it did not happen to match.
 *
 * Every default below is deliberately inert: disabled, zero rate. Nothing can charge a
 * family until a director has actually configured and enabled it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $t) {
            if (! Schema::hasColumn('rooms', 'max_concurrent_children')) {
                /* Distinct from `capacity` (physical places) and centres.license_capacity
                   (the licence total). This is how many may be PRESENT at once, which is
                   what a live ratio check compares against. Null = not limited. */
                $t->unsignedInteger('max_concurrent_children')->nullable()->after('capacity');
            }
        });

        Schema::table('agencies', function (Blueprint $t) {
            foreach ([
                // Master switch. Off means no late fee is ever calculated or recorded.
                'late_fee_enabled' => fn () => $t->boolean('late_fee_enabled')->default(false),
                // Minutes after closing before a pickup counts as late.
                'late_fee_grace_minutes' => fn () => $t->unsignedInteger('late_fee_grace_minutes')->default(0),
                // per_minute | per_block | flat
                'late_fee_mode' => fn () => $t->string('late_fee_mode', 16)->default('per_minute'),
                // Block length when mode = per_block; ignored otherwise.
                'late_fee_block_minutes' => fn () => $t->unsignedInteger('late_fee_block_minutes')->default(15),
                // The rate, in cents: per minute, per block, or the flat amount.
                'late_fee_rate_cents' => fn () => $t->unsignedInteger('late_fee_rate_cents')->default(0),
                // Optional ceiling per occurrence, in cents. 0 = uncapped.
                'late_fee_max_cents' => fn () => $t->unsignedInteger('late_fee_max_cents')->default(0),
                /* false = record the late pickup and raise a PENDING charge a human
                   approves. Staff sometimes record a check-out well after the fact, and a
                   fee that posts itself from a mistyped time is hard to walk back. */
                'late_fee_auto_charge' => fn () => $t->boolean('late_fee_auto_charge')->default(false),
                // Shown to families on the invoice line.
                'late_fee_label' => fn () => $t->string('late_fee_label', 60)->nullable(),
            ] as $col => $make) {
                if (! Schema::hasColumn('agencies', $col)) {
                    $make();
                }
            }
        });

        Schema::table('centres', function (Blueprint $t) {
            /* Per-provider override. Null means "use the agency setting", which is why it
               is nullable rather than defaulted — a 0 here would silently mean free. */
            foreach ([
                'late_fee_grace_minutes', 'late_fee_rate_cents', 'late_fee_block_minutes',
            ] as $col) {
                if (! Schema::hasColumn('centres', $col)) {
                    $t->unsignedInteger($col)->nullable();
                }
            }
            if (! Schema::hasColumn('centres', 'late_fee_mode')) {
                $t->string('late_fee_mode', 16)->nullable();
            }
            if (! Schema::hasColumn('centres', 'late_fee_enabled')) {
                // Nullable tri-state: null inherits, true/false overrides the agency.
                $t->boolean('late_fee_enabled')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn('max_concurrent_children'));
        Schema::table('agencies', fn (Blueprint $t) => $t->dropColumn([
            'late_fee_enabled', 'late_fee_grace_minutes', 'late_fee_mode', 'late_fee_block_minutes',
            'late_fee_rate_cents', 'late_fee_max_cents', 'late_fee_auto_charge', 'late_fee_label',
        ]));
        Schema::table('centres', fn (Blueprint $t) => $t->dropColumn([
            'late_fee_grace_minutes', 'late_fee_rate_cents', 'late_fee_block_minutes',
            'late_fee_mode', 'late_fee_enabled',
        ]));
    }
};
