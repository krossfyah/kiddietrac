<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printable document templates — payslips, invoices, receipts.
 *
 * Bodies are stored in a RESTRICTED syntax, never Blade. A Blade file is PHP: rendering an
 * uploaded one would hand anybody who can reach the import screen arbitrary code execution
 * on the server. The importer converts Blade into the safe subset instead, and reports
 * whatever it could not convert rather than passing it through.
 *
 * agency_id NULL is a platform-wide default that every agency inherits until it saves its
 * own. Only one template per (agency, kind) is active at a time; the rest are kept as
 * history, because a payslip already issued should stay reproducible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('kind', 40)->index();          // payslip | invoice | payroll_invoice | receipt
            $table->string('name', 120);
            $table->text('body');                         // safe subset, not Blade
            $table->text('styles')->nullable();
            $table->boolean('is_active')->default(false);

            $table->string('source', 20)->default('builtin');   // builtin | imported | edited
            $table->string('imported_from', 190)->nullable();
            $table->text('import_notes')->nullable();     // what the converter could not translate
            $table->unsignedInteger('version')->default(1);

            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
