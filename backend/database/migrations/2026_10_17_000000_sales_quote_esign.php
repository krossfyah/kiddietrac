<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online signing of proposals (2026-10-16, Anthony: "can we make this where it can be signed
 * virtually and countersigned by kiddietrac virtually to complete the proposal process").
 * The prospect signs from a private link (proposal.html?t=…); KiddieTrac countersigns in the
 * CRM; the fully signed PDF is stored, emailed and filed on the lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $t) {
            $add = fn (string $c) => ! Schema::hasColumn('sales_quotes', $c);
            if ($add('sign_token')) $t->string('sign_token', 64)->nullable()->unique()->after('prepared_by');
            if ($add('sign_viewed_at')) $t->dateTime('sign_viewed_at')->nullable();
            if ($add('client_signed_at')) $t->dateTime('client_signed_at')->nullable();
            if ($add('client_signer_name')) $t->string('client_signer_name', 160)->nullable();
            if ($add('client_signer_title')) $t->string('client_signer_title', 160)->nullable();
            if ($add('client_signer_org')) $t->string('client_signer_org', 255)->nullable();
            if ($add('client_signature')) $t->mediumText('client_signature')->nullable();
            if ($add('client_sign_ip')) $t->string('client_sign_ip', 45)->nullable();
            if ($add('client_sign_ua')) $t->string('client_sign_ua', 300)->nullable();
            if ($add('document_hash')) $t->char('document_hash', 64)->nullable();
            if ($add('countersigned_at')) $t->dateTime('countersigned_at')->nullable();
            if ($add('countersigner_id')) $t->unsignedBigInteger('countersigner_id')->nullable();
            if ($add('countersigner_name')) $t->string('countersigner_name', 160)->nullable();
            if ($add('countersigner_title')) $t->string('countersigner_title', 160)->nullable();
            if ($add('countersignature')) $t->mediumText('countersignature')->nullable();
            if ($add('countersign_ip')) $t->string('countersign_ip', 45)->nullable();
            if ($add('signed_pdf_path')) $t->string('signed_pdf_path', 255)->nullable();
            if ($add('declined_at')) $t->dateTime('declined_at')->nullable();
            if ($add('decline_reason')) $t->text('decline_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', function (Blueprint $t) {
            $t->dropUnique(['sign_token']);
            $t->dropColumn(['sign_token', 'sign_viewed_at', 'client_signed_at', 'client_signer_name', 'client_signer_title', 'client_signer_org',
                'client_signature', 'client_sign_ip', 'client_sign_ua', 'document_hash', 'countersigned_at', 'countersigner_id', 'countersigner_name',
                'countersigner_title', 'countersignature', 'countersign_ip', 'signed_pdf_path', 'declined_at', 'decline_reason']);
        });
    }
};
