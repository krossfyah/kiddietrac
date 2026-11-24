<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log was slow to open (2026-11-10, Anthony: "why when going to audit log its slow?"). Its total count read
 * the whole table (type=ALL, 106k rows with their payloads) and the filter lists read 87k. These two cover what those
 * queries ask (agency, action or record kind, actor), so both are answered from the index alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            if (! Schema::hasIndex('audit_logs', 'idx_audit_agency_action_user')) $t->index(['agency_id', 'action', 'user_id'], 'idx_audit_agency_action_user');
            if (! Schema::hasIndex('audit_logs', 'idx_audit_agency_entity_user')) $t->index(['agency_id', 'entity_type', 'user_id'], 'idx_audit_agency_entity_user');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->dropIndex('idx_audit_agency_action_user');
            $t->dropIndex('idx_audit_agency_entity_user');
        });
    }
};
