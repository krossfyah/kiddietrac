<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* QUICK NOTE PICTURES (2026-11-26, Anthony: "ability to add screenshots to notes as well wire this up").
   App\Support\QuickNotePictures. A picture has no audience of its own: whoever may see the note (QuickNotes::find)
   may see its pictures, nobody else. Files live on the private disk (storage/app/private/quick-notes/<agency|personal>/
   <note>/), re-encoded on the server (no EXIF, no location), and are only ever served through
   GET /quick-notes/pictures/{id}, signed in. Removing a picture or deleting its note deletes the file and the row. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quick_note_pictures')) {
            Schema::create('quick_note_pictures', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('note_id')->index();
                $t->unsignedBigInteger('agency_id')->nullable()->index();
                $t->unsignedBigInteger('user_id');
                $t->string('path', 255);
                $t->string('mime', 40)->default('image/webp');
                $t->unsignedInteger('width')->default(0);
                $t->unsignedInteger('height')->default(0);
                $t->unsignedInteger('bytes')->default(0);
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_note_pictures');
    }
};
