<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\CampaignDesigns;
use App\Support\SalesCampaigns;
use App\Support\SubscriberCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales -> Campaigns -> email templates (2026-11-04, Anthony: "there should be a drop down on preset campaign templates
 * and ability to add a new one and edit existing etc"). Same route group as SalesCampaignController
 * (role:sales_rep,platform_admin + feature:sales_crm). Presets are KiddieTrac's own and can be edited but not removed;
 * the team's templates can be removed. Applying a template copies it into a campaign, which is then edited freely.
 */
class SalesCampaignTemplateController extends Controller
{
    /** Every layout a template or campaign can use: key => label. */
    public static function layouts(): array
    {
        $out = ['standard' => 'Standard introduction (with the film)'];
        foreach (CampaignDesigns::DESIGNS as $k => $v) $out[$k] = $v;
        $out['custom'] = 'Your own email (blank: write it all in the editor)';   // 2026-11-04
        foreach (SubscriberCampaign::TEMPLATES as $k => $v) $out['sub:' . $k] = 'Subscriber email: ' . $v . ' (finished design)';
        return $out;
    }

    private function row(int $id): object
    {
        $t = DB::table('sales_campaign_templates')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($t, 404, 'Template not found.');
        return $t;
    }

    public static function present(object $t): array
    {
        $by = fn ($id) => $id ? trim((string) DB::table('users')->where('id', $id)->value('first_name')) : null;
        return [
            'id' => (int) $t->id, 'name' => $t->name, 'kind' => $t->kind, 'layout' => $t->layout,
            'layout_label' => self::layouts()[$t->layout] ?? $t->layout, 'subject' => $t->subject, 'preheader' => $t->preheader,
            'headline' => $t->headline, 'intro' => $t->intro, 'video' => $t->video,
            'highlights' => json_decode((string) $t->highlights, true) ?: SalesCampaigns::DEFAULT_HIGHLIGHTS,
            'show_plans' => (bool) $t->show_plans, 'updated_at' => $t->updated_at, 'updated_by' => $by($t->updated_by ?: $t->created_by),
            'canvas' => \App\Support\CampaignRich::canvas($t->canvas ?? null),
        ];
    }

    /** Presets first (in their order), then the team's own, newest first. */
    public static function all(): array
    {
        return DB::table('sales_campaign_templates')->whereNull('deleted_at')
            ->orderByRaw("kind = 'preset' desc")->orderBy('sort')->orderByDesc('id')->get()
            ->map(fn ($t) => self::present($t))->values()->all();
    }

    private function rules(bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';
        return [
            'name' => [$r, 'string', 'max:160'], 'layout' => [$r, 'in:' . implode(',', array_keys(self::layouts()))],
            'subject' => [$r, 'string', 'max:200'], 'preheader' => ['nullable', 'string', 'max:200'], 'headline' => ['nullable', 'string', 'max:200'],
            'intro' => ['nullable', 'string', 'max:60000'], 'video' => ['nullable', 'in:' . implode(',', array_keys(SalesCampaigns::VIDEOS))],
            'highlights' => ['nullable', 'array', 'max:8'], 'highlights.*' => ['string', 'in:' . implode(',', array_keys(SalesCampaigns::HIGHLIGHTS))],
            'show_plans' => ['nullable', 'boolean'],
        ] + \App\Support\CampaignRich::canvasRules();
    }

    private function fields(array $d): array
    {
        $u = [];
        foreach (['name', 'layout', 'subject', 'preheader', 'headline', 'intro', 'video'] as $k) if (array_key_exists($k, $d)) $u[$k] = $d[$k];
        if (array_key_exists('highlights', $d)) $u['highlights'] = json_encode(array_values($d['highlights'] ?? []));
        if (array_key_exists('show_plans', $d)) $u['show_plans'] = (bool) $d['show_plans'];
        if (array_key_exists('intro', $u)) $u['intro'] = \App\Support\CampaignRich::clean($u['intro']);   // rich text through the allowlist
        if (array_key_exists('canvas', $d)) $u['canvas'] = \App\Support\CampaignRich::cleanCanvas($d['canvas']);
        return $u;
    }

    /** GET /sales/campaign-templates */
    public function index(): JsonResponse
    {
        return response()->json(['templates' => self::all(), 'layouts' => collect(self::layouts())->map(fn ($v, $k) => ['key' => $k, 'label' => $v])->values()]);
    }

    /** POST /sales/campaign-templates - a new template (from scratch, or "save this campaign's email as a template"). */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate($this->rules(true));
        $uid = (int) $request->user()->id;
        $id = DB::table('sales_campaign_templates')->insertGetId($this->fields($d) + ['kind' => 'custom', 'sort' => 1000,
            'created_by' => $uid, 'updated_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        Audit::write(['user_id' => $uid, 'action' => 'sales.campaign_template_created', 'entity_type' => 'sales_campaign_template', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Created the campaign template "' . $d['name'] . '"']), 'created_at' => now()]);
        return response()->json(['template' => self::present($this->row($id)), 'templates' => self::all()], 201);
    }

    /** PATCH /sales/campaign-templates/{id} - edit a template. Campaigns already made from it keep their own copy. */
    public function update(Request $request, int $id): JsonResponse
    {
        $t = $this->row($id);
        $d = $request->validate($this->rules(false));
        $uid = (int) $request->user()->id;
        DB::table('sales_campaign_templates')->where('id', $id)->update($this->fields($d) + ['updated_by' => $uid, 'updated_at' => now()]);
        Audit::write(['user_id' => $uid, 'action' => 'sales.campaign_template_updated', 'entity_type' => 'sales_campaign_template', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Edited the campaign template "' . ($d['name'] ?? $t->name) . '"']), 'created_at' => now()]);
        return response()->json(['template' => self::present($this->row($id)), 'templates' => self::all()]);
    }

    /** DELETE /sales/campaign-templates/{id} - the team's own only. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $t = $this->row($id);
        abort_if($t->kind === 'preset', 422, 'KiddieTrac presets can be edited but not removed.');
        DB::table('sales_campaign_templates')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'sales.campaign_template_removed', 'entity_type' => 'sales_campaign_template', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Removed the campaign template "' . $t->name . '"']), 'created_at' => now()]);
        return response()->json(['ok' => true, 'templates' => self::all()]);
    }

    /** The campaign columns a layout + words become. */
    public static function campaignFields(array $t): array
    {
        $layout = (string) ($t['layout'] ?? 'standard');
        $sub = str_starts_with($layout, 'sub:') ? substr($layout, 4) : null;
        $u = ['template' => $sub, 'design' => ! $sub && $layout !== 'standard' ? $layout : null];
        foreach (['subject', 'preheader', 'headline', 'intro', 'video'] as $k) if (array_key_exists($k, $t)) $u[$k] = $t[$k];
        if (array_key_exists('intro', $u)) $u['intro'] = \App\Support\CampaignRich::clean($u['intro']);
        if (array_key_exists('highlights', $t)) $u['highlights'] = is_array($t['highlights']) ? json_encode(array_values($t['highlights'])) : $t['highlights'];
        if (array_key_exists('show_plans', $t)) $u['show_plans'] = (bool) $t['show_plans'];
        if (array_key_exists('canvas', $t)) $u['canvas'] = \App\Support\CampaignRich::cleanCanvas($t['canvas']);
        if (empty($u['video'])) $u['video'] = 'a-day-with-kiddietrac';
        if ($sub) $u['consent_basis'] = 'implied_business';   // subscriber emails go to the subscriber list
        return $u;
    }

    /** POST /sales/campaign-templates/preview {layout, subject, ...} - an unsaved template drawn as it will arrive. */
    public function preview(Request $request)
    {
        $d = $request->validate(array_merge($this->rules(false), ['layout' => ['required', 'in:' . implode(',', array_keys(self::layouts()))]]));
        $c = (object) (self::campaignFields($d) + ['id' => 0, 'name' => $d['name'] ?? 'Preview', 'subject' => $d['subject'] ?? 'Preview',
            'preheader' => null, 'headline' => null, 'intro' => null, 'highlights' => null, 'show_plans' => true,
            'consent_basis' => 'implied_published', 'sent_by' => null, 'created_by' => (int) $request->user()->id]);
        return response(SalesCampaigns::render($c, null, (int) $request->user()->id), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** POST /sales/campaign-images {image} - a picture for the rich editor, served from api.kiddietrac.com (2026-11-04). */
    public function image(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120']]);
        $file = $request->file('image');
        $name = (string) \Illuminate\Support\Str::uuid() . '.' . strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $file->storeAs('marketing', $name, 'public');
        return response()->json(['url' => 'https://api.kiddietrac.com/storage/marketing/' . $name]);
    }

    /** POST /sales/campaigns/{id}/apply-template {template_id} - replace a campaign's email with a template's. */
    public function apply(Request $request, int $id): JsonResponse
    {
        $c = DB::table('sales_campaigns')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($c, 404, 'Campaign not found.');
        abort_if(in_array($c->status, ['sending', 'sent'], true), 422, $c->status === 'sent' ? 'This campaign has been sent. Start a new campaign to use another template.' : 'Pause the campaign before changing the email.');
        $d = $request->validate(['template_id' => ['required', 'integer']]);
        $t = self::present($this->row((int) $d['template_id']));
        DB::table('sales_campaigns')->where('id', $id)->update(self::campaignFields($t) + ['template_id' => $t['id'], 'updated_at' => now()]);
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'sales.campaign_template_applied', 'entity_type' => 'sales_campaign', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Used the template "' . $t['name'] . '" for the campaign "' . $c->name . '"']), 'created_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
