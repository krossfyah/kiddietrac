<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales campaign email templates (2026-11-04, Anthony: "there should be a drop down on preset campaign templates and
 * ability to add a new one and edit existing"). A template is a reusable email: its layout (the standard introduction,
 * one of the CampaignDesigns, or a finished subscriber email) and its words. KiddieTrac presets are seeded here from
 * the designs that already existed but could not be picked on screen; the sales team adds their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_campaign_templates')) {
            Schema::create('sales_campaign_templates', function (Blueprint $t) {
                $t->id();
                $t->string('name', 160);
                $t->string('kind', 10)->default('custom');      // preset | custom
                $t->string('layout', 40)->default('standard');  // standard | a CampaignDesigns key | sub:<SubscriberCampaign key>
                $t->string('subject', 200);
                $t->string('preheader', 200)->nullable();
                $t->string('headline', 200)->nullable();
                $t->text('intro')->nullable();
                $t->string('video', 60)->nullable();
                $t->json('highlights')->nullable();
                $t->boolean('show_plans')->default(true);
                $t->unsignedInteger('sort')->default(100);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasColumn('sales_campaigns', 'template_id')) {
            Schema::table('sales_campaigns', function (Blueprint $t) { $t->unsignedBigInteger('template_id')->nullable()->after('design'); });
        }
        if (DB::table('sales_campaign_templates')->where('kind', 'preset')->exists()) return;

        // The words of the design drafts already written (Cold reach follow-ups), so each preset starts finished.
        $from = fn (string $design) => DB::table('sales_campaigns')->where('design', $design)->whereNull('deleted_at')->orderBy('id')->first();
        $roi = $from('letter_roi');
        $presets = [
            ['Standard introduction (with the film)', 'standard', null],
            ['Morning photo story', 'morning', $from('morning')],
            ['Safety spotlight', 'safety', $from('safety')],
            ['Personal letter', 'letter', $from('letter') ?: $roi],
            ['Personal letter + ROI', 'letter_roi', $roi],
            ['Subscriber email: One-Tap', 'sub:onetap', null],
            ['Subscriber email: Halloween', 'sub:halloween', null],
        ];
        $subjects = ['sub:onetap' => 'Meet One-Tap: check-in and pick-up in one tap', 'sub:halloween' => 'Happy Halloween from KiddieTrac 🎃'];
        foreach ($presets as $i => [$name, $layout, $src]) {
            DB::table('sales_campaign_templates')->insert([
                'name' => $name, 'kind' => 'preset', 'layout' => $layout,
                'subject' => $src->subject ?? ($subjects[$layout] ?? 'A one-minute look at KiddieTrac'),
                'preheader' => $src->preheader ?? null, 'headline' => $src->headline ?? null, 'intro' => $src->intro ?? null,
                'video' => $src->video ?? 'a-day-with-kiddietrac',
                'highlights' => $src->highlights ?? json_encode(['billing', 'parentapp', 'compliance', 'staff']),
                'show_plans' => true, 'sort' => ($i + 1) * 10, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_campaigns', 'template_id')) Schema::table('sales_campaigns', function (Blueprint $t) { $t->dropColumn('template_id'); });
        Schema::dropIfExists('sales_campaign_templates');
    }
};
