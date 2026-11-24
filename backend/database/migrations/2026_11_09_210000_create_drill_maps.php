<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A map of each centre, kept with its Drill log (2026-11-09, Anthony: "drill log should have ability to upload a map of
 * the educators centre - wire this up"). One current map per centre (replaced_at IS NULL); a replaced or removed map
 * keeps its row and file, so what was on the wall at an inspection can still be shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drill_maps')) {
            return;
        }
        Schema::create('drill_maps', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('centre_id')->index();
            $t->string('file_url', 512);          // /storage/drill-maps/<agency>/<file> - signed on the way out (ProtectedMedia)
            $t->string('file_name', 190)->nullable();
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('size')->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamp('replaced_at')->nullable();
            $t->unsignedBigInteger('replaced_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_maps');
    }
};
