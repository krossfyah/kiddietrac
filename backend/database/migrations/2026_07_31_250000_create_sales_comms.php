<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_messages')) {
            Schema::create('sales_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->text('body');
                $t->timestamps();
                $t->index('created_at');
            });
        }
        if (! Schema::hasTable('sales_announcements')) {
            Schema::create('sales_announcements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('title');
                $t->text('body');
                $t->boolean('pinned')->default(false);
                $t->timestamps();
                $t->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_messages');
        Schema::dropIfExists('sales_announcements');
    }
};
