<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time off measured in hours, not only in days.
 *
 * start_at and end_at are DATE columns, so the smallest thing anyone could ask for was a
 * whole day. A dentist appointment at 2pm meant booking the day off, which reads on the
 * rota as an absent educator and — for a home provider — closes the centre outright.
 *
 * all_day defaults TRUE so every existing row keeps meaning exactly what it meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_off_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('time_off_requests', 'all_day')) {
                $table->boolean('all_day')->default(true)->after('end_at');
            }
            if (! Schema::hasColumn('time_off_requests', 'start_time')) {
                $table->time('start_time')->nullable()->after('all_day');
            }
            if (! Schema::hasColumn('time_off_requests', 'end_time')) {
                $table->time('end_time')->nullable()->after('start_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('time_off_requests', function (Blueprint $table) {
            foreach (['all_day', 'start_time', 'end_time'] as $c) {
                if (Schema::hasColumn('time_off_requests', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
