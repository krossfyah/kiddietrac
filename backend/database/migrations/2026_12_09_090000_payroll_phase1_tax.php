<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 1 (2026-11-27): each employee's tax setup and their tax forms.
 *
 * Country-neutral on purpose so the US (Phase 2: W-4, state withholding certificates) slots in without a rewrite:
 *  - payroll_tax_profiles: what the EMPLOYER sets (country, province/state of employment, pay frequency, exemptions,
 *    vacation pay %). One row per person per agency. Nothing reads it until the agency switches payroll on (Phase 2).
 *  - payroll_tax_forms: what the EMPLOYEE claims (CA: TD1 federal, TD1 provincial, TP-1015.3 Quebec; US later: W-4 and
 *    state forms), per tax year, as structured `claims` JSON. E-signed through Managed Forms (managed_form_id, signed =
 *    managed_form_signoffs.signed_at), or recorded from a paper form by an agency admin (source = paper).
 * The SIN is NOT stored here: it stays in hr_staff_sin (encrypted, admins only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_tax_profiles')) {
            Schema::create('payroll_tax_profiles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->char('country', 2)->default('CA');
                $t->string('jurisdiction', 8)->nullable();          // AB..YT / QC; US state code in Phase 2. Null = from the main centre.
                $t->unsignedSmallInteger('pay_frequency')->nullable(); // periods a year (52, 26, 24, 12). Null = the agency's payroll cadence.
                $t->string('cpp_exempt_reason', 20)->nullable();   // null | cpt30 | disability | other (age 18 / 70 comes from the birth date)
                $t->boolean('ei_exempt')->default(false);
                $t->boolean('qpip_exempt')->default(false);
                $t->decimal('vacation_pay_pct', 5, 2)->nullable();  // e.g. 4.00; null = not paid out per pay
                $t->decimal('annual_zone_deduction', 10, 2)->nullable(); // TD1 prescribed-zone deduction (HD), annual
                $t->string('notes', 500)->nullable();
                $t->json('extra')->nullable();                      // country-specific employer settings (US Phase 2)
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('payroll_tax_forms')) {
            Schema::create('payroll_tax_forms', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->char('country', 2)->default('CA');
                $t->string('form', 16);                             // TD1 (federal + provincial pair) | TP-1015.3 | W-4 (Phase 2)
                $t->unsignedSmallInteger('tax_year');
                $t->string('jurisdiction', 8)->nullable();          // the provincial form's province
                $t->json('claims');                                 // structured amounts the employee claimed
                $t->string('source', 10)->default('esign');         // esign | paper
                $t->string('status', 12)->default('sent');          // sent | signed (paper) | withdrawn
                $t->unsignedBigInteger('managed_form_id')->nullable();
                $t->unsignedBigInteger('submitted_by_id')->nullable();
                $t->timestamp('signed_at')->nullable();             // paper forms only; e-signed = managed_form_signoffs.signed_at
                $t->timestamps();
                $t->index(['agency_id', 'user_id', 'tax_year']);
                $t->index('managed_form_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_tax_forms');
        Schema::dropIfExists('payroll_tax_profiles');
    }
};
