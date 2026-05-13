<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Structured allergy + dietary fields on children. Existing `medical_notes` /
        // `dietary_notes` free-text columns are kept for backward compatibility.
        Schema::table('children', function (Blueprint $table) {
            if (! Schema::hasColumn('children', 'allergies')) {
                $table->json('allergies')->nullable()->after('medical_notes');
            }
            if (! Schema::hasColumn('children', 'dietary_restrictions')) {
                $table->json('dietary_restrictions')->nullable()->after('allergies');
            }
            if (! Schema::hasColumn('children', 'health_alerts')) {
                $table->json('health_alerts')->nullable()->after('dietary_restrictions');
            }
        });

        // Standing medication orders (parent-authorized).
        if (! Schema::hasTable('medications')) {
        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('centre_id');
            $table->string('name', 200);
            $table->string('strength', 100)->nullable();
            $table->string('route', 40)->default('oral');
            $table->string('dosage', 200);
            $table->string('frequency', 200);
            $table->string('reason', 200)->nullable();
            $table->date('starts_on');
            $table->date('expires_on')->nullable();
            $table->text('special_instructions')->nullable();
            $table->boolean('requires_refrigeration')->default(false);
            $table->string('storage_location', 100)->nullable();
            $table->unsignedBigInteger('authorized_by_id')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->longText('parent_signature_data')->nullable();
            $table->boolean('is_prescription')->default(false);
            $table->string('prescribing_physician', 160)->nullable();
            $table->enum('status', ['pending_auth', 'active', 'discontinued', 'expired'])->default('pending_auth');
            $table->unsignedBigInteger('created_by_id');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['centre_id', 'status']);
            $table->index('child_id');
        });
        }

        // Each dose administered (CCEYA-style log).
        if (! Schema::hasTable('medication_logs')) {
        Schema::create('medication_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('medication_id');
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('centre_id');
            $table->timestamp('administered_at')->useCurrent();
            $table->string('dose_given', 200);
            $table->enum('outcome', ['given', 'refused', 'missed', 'partial'])->default('given');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('administered_by_id');
            $table->unsignedBigInteger('witness_id')->nullable();
            $table->timestamp('parent_notified_at')->nullable();
            $table->timestamps();
            $table->index(['centre_id', 'administered_at']);
            $table->index('child_id');
            $table->index('medication_id');
        });
        }

        // Immunization records per child.
        if (! Schema::hasTable('immunizations')) {
        Schema::create('immunizations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('child_id');
            $table->string('vaccine', 100);
            $table->string('dose_label', 40)->nullable();
            $table->date('administered_on')->nullable();
            $table->string('lot_number', 80)->nullable();
            $table->string('site', 80)->nullable();
            $table->string('administered_by', 160)->nullable();
            $table->string('clinic_name', 160)->nullable();
            $table->date('next_due_on')->nullable();
            $table->boolean('exempt')->default(false);
            $table->string('exemption_reason', 200)->nullable();
            $table->string('proof_document_url', 500)->nullable();
            $table->unsignedBigInteger('recorded_by_id');
            $table->timestamps();
            $table->index(['child_id', 'vaccine']);
            $table->index('next_due_on');
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('immunizations');
        Schema::dropIfExists('medication_logs');
        Schema::dropIfExists('medications');
        Schema::table('children', function (Blueprint $table) {
            if (Schema::hasColumn('children', 'health_alerts')) {
                $table->dropColumn('health_alerts');
            }
            if (Schema::hasColumn('children', 'dietary_restrictions')) {
                $table->dropColumn('dietary_restrictions');
            }
            if (Schema::hasColumn('children', 'allergies')) {
                $table->dropColumn('allergies');
            }
        });
    }
};
