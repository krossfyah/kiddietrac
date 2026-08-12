<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hcc_inspection_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('centre_id')->nullable()->index();
            $table->unsignedBigInteger('home_visitor_id')->index();
            $table->string('form_type', 40)->index();        // monthly_monitoring | quarterly_checklist
            $table->string('provider_name', 191);
            $table->date('visit_date')->index();
            $table->string('visit_time_in', 20)->nullable();
            $table->string('visit_time_out', 20)->nullable();
            $table->string('quarter', 4)->nullable();
            $table->string('status', 20)->default('submitted');
            $table->json('answers')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hcc_inspection_forms');
    }
};
