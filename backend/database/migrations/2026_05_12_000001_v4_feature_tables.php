<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4 schema additions: AI digests, messaging, observations, invoice lines, payments.
 *
 * Run this AFTER deploying v4 with: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── AI-generated daily digests (cached) ─────────────────────
        if (! Schema::hasTable('ai_daily_digests')) {
            Schema::create('ai_daily_digests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('child_id');
                $t->date('digest_date');
                $t->text('body');
                $t->timestamp('generated_at')->useCurrent();
                $t->string('model', 80)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['child_id', 'digest_date']);
                $t->index('digest_date');
            });
        }

        // ─── Parent ↔ room conversations ─────────────────────────────
        if (! Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('parent_id');
                $t->unsignedBigInteger('room_id');
                $t->unsignedBigInteger('child_id')->nullable();
                $t->timestamp('last_activity_at')->useCurrent();
                $t->timestamps();
                $t->index(['parent_id', 'room_id']);
                $t->index('last_activity_at');
            });
        }

        // ─── Messages within a conversation ──────────────────────────
        if (! Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id');
                $t->unsignedBigInteger('sender_id');
                $t->text('body');
                $t->timestamp('sent_at')->useCurrent();
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
                $t->index(['conversation_id', 'sent_at']);
            });
        }

        // ─── Learning observations ───────────────────────────────────
        if (! Schema::hasTable('observations')) {
            Schema::create('observations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('child_id');
                $t->enum('domain', [
                    'social_emotional', 'physical', 'language_literacy',
                    'cognitive', 'creative_arts', 'self_care', 'outdoor',
                ]);
                $t->string('title', 160);
                $t->text('body');
                $t->unsignedBigInteger('media_id')->nullable();
                $t->timestamp('observed_at')->useCurrent();
                $t->unsignedBigInteger('recorded_by_id');
                $t->timestamps();
                $t->index(['child_id', 'observed_at']);
            });
        }

        // ─── Add columns to media table if missing ───────────────────
        if (Schema::hasTable('media')) {
            Schema::table('media', function (Blueprint $t) {
                if (! Schema::hasColumn('media', 'storage_path')) {
                    $t->string('storage_path', 500)->nullable()->after('file_url');
                }
                if (! Schema::hasColumn('media', 'mime_type')) {
                    $t->string('mime_type', 80)->nullable()->after('storage_path');
                }
                if (! Schema::hasColumn('media', 'size_bytes')) {
                    $t->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');
                }
            });
        }

        // ─── Invoice line items ──────────────────────────────────────
        if (! Schema::hasTable('invoice_lines')) {
            Schema::create('invoice_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('invoice_id');
                $t->string('description', 200);
                $t->decimal('amount', 10, 2);
                $t->decimal('subsidy_amount', 10, 2)->default(0);
                $t->decimal('net_amount', 10, 2);
                $t->timestamp('created_at')->useCurrent();
                $t->index('invoice_id');
            });
        }

        // ─── Payments ────────────────────────────────────────────────
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('invoice_id');
                $t->unsignedBigInteger('family_id');
                $t->decimal('amount', 10, 2);
                $t->enum('method', [
                    'cash', 'cheque', 'e_transfer', 'bank_transfer',
                    'credit_card_offline', 'other',
                ]);
                $t->timestamp('paid_at');
                $t->string('reference', 120)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('recorded_by_id');
                $t->timestamp('created_at')->useCurrent();
                $t->index(['invoice_id', 'paid_at']);
                $t->index('family_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('observations');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('ai_daily_digests');
        // Don't drop the added columns on media — they're additive
    }
};
