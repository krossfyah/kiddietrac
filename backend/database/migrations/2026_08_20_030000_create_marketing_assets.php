<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable campaign headers and footers.
 *
 * Every campaign had to have its banner pasted in by hand, so the branding drifted
 * between one send and the next and there was no way to change the footer everywhere at
 * once. A saved asset is uploaded once, named, and picked from a list thereafter.
 *
 * Stored per agency: a white-label tenant's banner must never appear in another
 * agency's list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_assets')) {
            Schema::create('marketing_assets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                // header | footer
                $table->string('kind', 12)->index();
                $table->string('name', 120);
                // Public path under the API web root — an email client fetches images
                // with no session, so it has to be reachable without a login. The
                // filename is a long random token, which is what keeps it unguessable.
                $table->string('image_path', 300)->nullable();
                // A footer is often words (address, unsubscribe wording) rather than a
                // picture, so either is allowed and at least one is required.
                $table->text('html')->nullable();
                $table->unsignedBigInteger('created_by_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::table('marketing_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_campaigns', 'header_asset_id')) {
                $table->unsignedBigInteger('header_asset_id')->nullable()->after('body_html');
            }
            if (! Schema::hasColumn('marketing_campaigns', 'footer_asset_id')) {
                $table->unsignedBigInteger('footer_asset_id')->nullable()->after('header_asset_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            foreach (['header_asset_id', 'footer_asset_id'] as $c) {
                if (Schema::hasColumn('marketing_campaigns', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::dropIfExists('marketing_assets');
    }
};
