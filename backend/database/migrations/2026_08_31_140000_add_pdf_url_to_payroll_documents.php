<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the SOURCE system's own payslip lives, when it has one.
 *
 * payroll_documents held the figures and KiddieTrac drew its own payslip from
 * them. Close, but not the same paper: iLearn's carries the agency branding, the
 * non-employee clause that makes a provider's payslip coherent on its own,
 * year-to-date totals and terms. An educator comparing the PDF in their inbox
 * with the one in the portal should not find two different documents.
 *
 * A URL, not the bytes. The far end renders on demand, so it is always the
 * current document and a corrected period needs no re-upload. KiddieTrac fetches
 * it server-side and streams it on, so the signed link never reaches a browser.
 *
 * Null for everything KiddieTrac produces itself, which keeps working exactly as
 * it does — this only ever adds a better answer when one exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_documents', 'pdf_url')) {
                $table->string('pdf_url', 500)->nullable()->after('external_key');
            }
            // The FORMAT of the password, never the password. Enough for the person
            // it belongs to, useless to anybody else.
            if (! Schema::hasColumn('payroll_documents', 'pdf_password_hint')) {
                $table->string('pdf_password_hint', 200)->nullable()->after('pdf_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            foreach (['pdf_url', 'pdf_password_hint'] as $col) {
                if (Schema::hasColumn('payroll_documents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
