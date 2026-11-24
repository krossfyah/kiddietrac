<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\SalesCampaigns;
use App\Support\SubscriberCampaign;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales -> Campaigns (2026-10-26). Behind role:sales_rep,platform_admin + feature:sales_crm (the sales route group).
 * Drafts are edited freely; a campaign is SENT only after the person confirms the number of people it goes to, and
 * kiddietrac:sales-campaigns then sends it from sales@ in small batches. See App\Support\SalesCampaigns.
 */
class SalesCampaignController extends Controller
{
    private const MAX_RECIPIENTS = 5000;

    private function campaign(int $id): object
    {
        $c = DB::table('sales_campaigns')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($c, 404, 'Campaign not found.');
        return $c;
    }

    private function present(object $c): array
    {
        $by = fn ($id) => $id ? trim((string) DB::table('users')->where('id', $id)->selectRaw("CONCAT(first_name,' ',last_name) n")->value('n')) : null;
        $queued = (int) DB::table('sales_campaign_recipients')->where('campaign_id', $c->id)->where('status', 'queued')->count();
        $pct = fn ($n) => $c->sent_count ? round($n * 100 / $c->sent_count) : 0;
        return [
            'id' => (int) $c->id, 'name' => $c->name, 'subject' => $c->subject, 'preheader' => $c->preheader, 'headline' => $c->headline,
            'intro' => $c->intro, 'highlights' => json_decode((string) $c->highlights, true) ?: SalesCampaigns::DEFAULT_HIGHLIGHTS,
            'video' => $c->video, 'show_plans' => (bool) $c->show_plans, 'consent_basis' => $c->consent_basis, 'status' => $c->status,
            'created_by' => $by($c->created_by), 'sent_by' => $by($c->sent_by), 'send_started_at' => $c->send_started_at, 'sent_at' => $c->sent_at,
            'created_at' => $c->created_at, 'updated_at' => $c->updated_at, 'queued' => $queued,
            'counts' => ['recipients' => (int) $c->recipient_count, 'sent' => (int) $c->sent_count, 'opened' => (int) $c->open_count, 'clicked' => (int) $c->click_count,
                'responded' => (int) $c->response_count, 'unsubscribed' => (int) $c->unsub_count],
            'rates' => ['opened' => $pct($c->open_count), 'clicked' => $pct($c->click_count), 'responded' => $pct($c->response_count)],
            // Subscriber emails + approve / schedule (2026-10-27). Times for the screen are America/Toronto.
            'template' => $c->template ?? null, 'template_label' => isset($c->template) && $c->template ? (SubscriberCampaign::TEMPLATES[$c->template] ?? $c->template) : null,
            'scheduled_for' => $c->scheduled_for ?? null,
            'scheduled_local' => ! empty($c->scheduled_for) ? Carbon::parse($c->scheduled_for, 'UTC')->tz(self::TZ)->format('Y-m-d\TH:i') : null,
            'scheduled_label' => ! empty($c->scheduled_for) ? Carbon::parse($c->scheduled_for, 'UTC')->tz(self::TZ)->format('D, M j \a\t g:i A T') : null,
            'approved_at' => $c->approved_at ?? null, 'approved_by' => $by($c->approved_by ?? null),
            'display_status' => self::displayStatus($c),
            // Layout + template (2026-11-04): what the template picker shows as chosen.
            'design' => $c->design ?? null, 'template_id' => isset($c->template_id) ? ($c->template_id ? (int) $c->template_id : null) : null,
            'layout' => ! empty($c->template) ? 'sub:' . $c->template : (! empty($c->design) ? $c->design : 'standard'),
            'canvas' => \App\Support\CampaignRich::canvas($c->canvas ?? null),   // "Your own email": header, footer, look (2026-11-05)
            'from_account_id' => ! empty($c->from_account_id) ? (int) $c->from_account_id : null,
            'from_label' => ($b = SalesCampaigns::sender($c)) ? ($b['email'] ? $b['name'] . ' <' . $b['email'] . '>' : 'A mailbox that is no longer connected')
                : (! empty($c->template) ? 'KiddieTrac (no-reply), replies to info@kiddietrac.com' : 'KiddieTrac Sales <' . SalesCampaigns::FROM . '>'),
        ];
    }

    private const TZ = 'America/Toronto';

    /** Draft / Scheduled - awaiting approval / Scheduled - approved / Sending / Paused / Sent. */
    private static function displayStatus(object $c): string
    {
        if (in_array($c->status, ['sending', 'paused', 'sent'], true)) return $c->status;
        if (! empty($c->scheduled_for)) return ! empty($c->approved_at) ? 'scheduled_approved' : 'scheduled_pending';
        return 'draft';
    }

    /** GET /sales/campaigns - every campaign with its numbers, and the choices the editor offers. */
    public function index(): JsonResponse
    {
        $rows = DB::table('sales_campaigns')->whereNull('deleted_at')->orderByDesc('id')->get();
        // [icon, title, line] lists (highlights) give icon + label + text; the label was the icon until 2026-11-04 ("💳 💳").
        $opts = fn ($list) => collect($list)->map(fn ($v, $k) => ['key' => $k] + (is_array($v) && count($v) > 2 ? ['icon' => $v[0], 'label' => $v[1], 'text' => $v[2]] : ['label' => is_array($v) ? $v[0] : $v]))->values();
        return response()->json([
            'campaigns' => $rows->map(fn ($c) => $this->present($c))->values(),
            'suppressed' => DB::table('sales_suppressions')->count(),
            'options' => [
                'videos' => $opts(SalesCampaigns::VIDEOS),
                // The rich editor's 🎬 Film button: each film's page and its play-button poster (2026-11-04).
                'film_links' => collect(SalesCampaigns::VIDEOS)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0],
                    'url' => 'https://www.kiddietrac.com' . $v[1], 'poster' => 'https://www.kiddietrac.com/videos/' . $k . '/og-play.jpg'])->values(),
                'highlights' => $opts(SalesCampaigns::HIGHLIGHTS),
                'consent' => collect(SalesCampaigns::CONSENT)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'footer' => $v[1]])->values(),
                'default_highlights' => SalesCampaigns::DEFAULT_HIGHLIGHTS,
                'templates' => collect(SubscriberCampaign::TEMPLATES)->map(fn ($v, $k) => ['key' => $k, 'label' => $v, 'subject' => SubscriberCampaign::subject($k)])->values(),
                'subscribers' => \Illuminate\Support\Facades\Schema::hasTable('site_subscribers') ? DB::table('site_subscribers')->whereNull('unsubscribed_at')->count() : 0,
                // Email templates (2026-11-04): KiddieTrac presets + the team's own, and every layout.
                'email_templates' => SalesCampaignTemplateController::all(),
                'layouts' => collect(SalesCampaignTemplateController::layouts())->map(fn ($v, $k) => ['key' => $k, 'label' => $v])->values(),
                // "Your own email" canvas choices + your remembered test addresses (2026-11-05).
                'fonts' => collect(\App\Support\CampaignRich::FONTS)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'css' => $v[1]])->values(),
                'canvas_default' => \App\Support\CampaignRich::canvas(null),
                'test_to' => self::testAddresses((int) request()->user()->id),
                'mailboxes' => SalesCampaigns::mailboxes(),   // "Send from" (2026-11-09)
            ],
        ]);
    }

    private function rules(bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';
        return [
            'name' => [$r, 'string', 'max:160'], 'subject' => [$create ? 'nullable' : 'sometimes', 'string', 'max:200'],
            'design' => ['nullable', 'in:standard,custom,' . implode(',', array_keys(\App\Support\CampaignDesigns::DESIGNS))],
            'preheader' => ['nullable', 'string', 'max:200'], 'headline' => ['nullable', 'string', 'max:200'], 'intro' => ['nullable', 'string', 'max:60000'],
            'highlights' => ['nullable', 'array', 'max:8'], 'highlights.*' => ['string', 'in:' . implode(',', array_keys(SalesCampaigns::HIGHLIGHTS))],
            'video' => ['nullable', 'in:' . implode(',', array_keys(SalesCampaigns::VIDEOS))], 'show_plans' => ['nullable', 'boolean'],
            'consent_basis' => ['nullable', 'in:' . implode(',', array_keys(SalesCampaigns::CONSENT))],
            'template' => ['nullable', 'in:' . implode(',', array_keys(SubscriberCampaign::TEMPLATES))],
            // Any layout, a finished subscriber design included (2026-11-05): how a finished design becomes editable.
            'layout' => ['nullable', 'in:' . implode(',', array_keys(SalesCampaignTemplateController::layouts()))],
            // Send from (2026-11-09): one of the platform mailboxes connected on the portal, or null for the usual sender.
            'from_account_id' => ['nullable', 'integer', 'in:' . implode(',', array_merge([0], array_column(SalesCampaigns::mailboxes(), 'id')))],
        ] + \App\Support\CampaignRich::canvasRules();
    }

    /** POST /sales/campaigns - a new draft. */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate($this->rules(true));
        $id = DB::table('sales_campaigns')->insertGetId([
            'name' => $d['name'], 'subject' => trim((string) ($d['subject'] ?? '')) !== '' ? $d['subject'] : 'A one-minute look at KiddieTrac', 'preheader' => $d['preheader'] ?? null, 'headline' => $d['headline'] ?? null, 'intro' => \App\Support\CampaignRich::clean($d['intro'] ?? null) ?? null,
            'highlights' => json_encode($d['highlights'] ?? SalesCampaigns::DEFAULT_HIGHLIGHTS), 'video' => $d['video'] ?? 'a-day-with-kiddietrac',
            'show_plans' => array_key_exists('show_plans', $d) ? (bool) $d['show_plans'] : true, 'consent_basis' => $d['consent_basis'] ?? 'implied_published',
            'status' => 'draft', 'created_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            'template' => $d['template'] ?? null,
            'design' => empty($d['template']) && ($d['design'] ?? 'standard') !== 'standard' ? $d['design'] : null,
            'canvas' => array_key_exists('canvas', $d) ? \App\Support\CampaignRich::cleanCanvas($d['canvas']) : null,
            'from_account_id' => ! empty($d['from_account_id']) ? (int) $d['from_account_id'] : null,
        ]);
        if (! empty($d['template'])) DB::table('sales_campaigns')->where('id', $id)->update(['consent_basis' => 'implied_business']);
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'sales.campaign_created', 'entity_type' => 'sales_campaign', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Created the sales campaign "' . $d['name'] . '"']), 'created_at' => now()]);
        return response()->json(['campaign' => $this->present($this->campaign($id))], 201);
    }

    /** GET /sales/campaigns/{id} - the campaign and everyone on it. */
    public function show(int $id): JsonResponse
    {
        $c = $this->campaign($id);
        $rows = DB::table('sales_campaign_recipients as r')->leftJoin('sales_leads as l', function ($j) { $j->on('l.id', '=', 'r.lead_id')->whereNull('l.deleted_at'); })
            ->where('r.campaign_id', $id)->orderBy('r.id')->limit(self::MAX_RECIPIENTS)
            ->get(['r.id', 'r.email', 'r.name', 'r.company', 'r.consent_basis', 'r.status', 'r.skip_reason', 'r.error', 'r.sent_at', 'r.opened_at', 'r.open_count',
                'r.clicked_at', 'r.click_count', 'r.last_link', 'r.responded_at', 'r.response', 'r.lead_id', 'l.stage as lead_stage', 'l.name as lead_name']);
        return response()->json(['campaign' => $this->present($c), 'recipients' => $rows]);
    }

    /** PATCH /sales/campaigns/{id} - edit the email (not while it is going out). */
    public function update(Request $request, int $id): JsonResponse
    {
        $c = $this->campaign($id);
        abort_if($c->status === 'sending', 422, 'Pause the campaign before changing the email.');
        $d = $request->validate($this->rules(false));
        $u = ['updated_at' => now()];
        foreach (['name', 'subject', 'preheader', 'headline', 'intro', 'video', 'consent_basis'] as $k) if (array_key_exists($k, $d)) $u[$k] = $d[$k];
        if (array_key_exists('intro', $u)) $u['intro'] = \App\Support\CampaignRich::clean($u['intro']);   // rich text through the allowlist (2026-11-04)
        // The layout of a written email (a subscriber email keeps its finished design).
        if (array_key_exists('design', $d) && empty($c->template)) $u['design'] = $d['design'] === 'standard' ? null : $d['design'];
        if (! empty($d['layout'])) {
            abort_if($c->status === 'sent', 422, 'This campaign has been sent. Start a new campaign to change its layout.');
            $lf = SalesCampaignTemplateController::campaignFields(['layout' => $d['layout']]);
            $u['template'] = $lf['template']; $u['design'] = $lf['design'];
            if (! empty($lf['consent_basis'])) $u['consent_basis'] = $lf['consent_basis'];
        }
        if (array_key_exists('highlights', $d)) $u['highlights'] = json_encode(array_values($d['highlights'] ?? []));
        if (array_key_exists('show_plans', $d)) $u['show_plans'] = (bool) $d['show_plans'];
        if (array_key_exists('canvas', $d)) $u['canvas'] = \App\Support\CampaignRich::cleanCanvas($d['canvas']);
        if (array_key_exists('from_account_id', $d)) {
            abort_if($c->status === 'sent', 422, 'This campaign has been sent. Start a new campaign to send from another mailbox.');
            $u['from_account_id'] = ! empty($d['from_account_id']) ? (int) $d['from_account_id'] : null;
        }
        DB::table('sales_campaigns')->where('id', $id)->update($u);
        return response()->json(['campaign' => $this->present($this->campaign($id))]);
    }

    /**
     * POST /sales/campaigns/{id}/copy - a new draft with the same email (2026-11-11, Anthony: "add ability to copy an
     * existing campaign to a new campaign as well"). The wording, layout, design, highlights, video, sender mailbox and
     * consent basis come across; status, schedule, approval and every count start fresh. with_people also brings the
     * people on it, as new queued recipients with their own tracking tokens; anyone unsubscribed since is skipped.
     */
    public function copy(Request $request, int $id): JsonResponse
    {
        $c = $this->campaign($id);
        $d = $request->validate(['name' => ['nullable', 'string', 'max:160'], 'with_people' => ['nullable', 'boolean']]);
        $name = trim((string) ($d['name'] ?? '')) !== '' ? trim($d['name']) : mb_substr('Copy of ' . $c->name, 0, 160);
        $uid = (int) $request->user()->id;
        $new = DB::table('sales_campaigns')->insertGetId([
            'name' => $name, 'subject' => $c->subject, 'preheader' => $c->preheader, 'headline' => $c->headline, 'intro' => $c->intro,
            'highlights' => $c->highlights, 'video' => $c->video, 'show_plans' => $c->show_plans, 'consent_basis' => $c->consent_basis,
            'template' => $c->template, 'design' => $c->design, 'template_id' => $c->template_id, 'canvas' => $c->canvas,
            'from_account_id' => $c->from_account_id, 'status' => 'draft', 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $people = 0; $skipped = 0;
        if (! empty($d['with_people'])) {
            $rows = DB::table('sales_campaign_recipients')->where('campaign_id', $id)->orderBy('id')->limit(self::MAX_RECIPIENTS)
                ->get(['lead_id', 'email', 'name', 'company', 'consent_basis']);
            foreach ($rows as $r) {
                $sup = SalesCampaigns::suppressed($r->email);
                DB::table('sales_campaign_recipients')->insert([
                    'campaign_id' => $new, 'lead_id' => $r->lead_id, 'email' => $r->email, 'name' => $r->name, 'company' => $r->company,
                    'consent_basis' => $r->consent_basis ?: $c->consent_basis, 'token' => SalesCampaigns::token(),
                    'status' => $sup ? 'skipped' : 'queued', 'skip_reason' => $sup ? 'unsubscribed' : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $sup ? $skipped++ : $people++;
            }
            SalesCampaigns::recount($new);
        }
        Audit::write(['user_id' => $uid, 'action' => 'sales.campaign_copied', 'entity_type' => 'sales_campaign', 'entity_id' => $new,
            'payload' => json_encode(['summary' => 'Copied the sales campaign "' . $c->name . '" to "' . $name . '"'
                . (! empty($d['with_people']) ? ' with ' . $people . ' ' . ($people === 1 ? 'person' : 'people') : ''), 'from_campaign_id' => $id]), 'created_at' => now()]);
        return response()->json(['campaign' => $this->present($this->campaign($new)), 'people' => $people, 'skipped' => $skipped], 201);
    }

    /** DELETE /sales/campaigns/{id} - remove a campaign (its history stays in the database). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $c = $this->campaign($id);
        abort_if($c->status === 'sending', 422, 'Pause the campaign before removing it.');
        DB::table('sales_campaigns')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        DB::table('sales_campaign_recipients')->where('campaign_id', $id)->where('status', 'queued')->update(['status' => 'skipped', 'skip_reason' => 'campaign removed', 'updated_at' => now()]);
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'sales.campaign_removed', 'entity_type' => 'sales_campaign', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Removed the sales campaign "' . $c->name . '"']), 'created_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /**
     * POST /sales/campaigns/{id}/recipients - add people: lead_ids (from Leads) and/or rows [{email,name,company}]
     * (pasted or a CSV). Each is checked: a valid address, not already on this campaign, not unsubscribed.
     */
    public function addRecipients(Request $request, int $id): JsonResponse
    {
        $c = $this->campaign($id);
        abort_if($c->status === 'sent', 422, 'This campaign has been sent. Start a new campaign for more people.');
        $d = $request->validate([
            'lead_ids' => ['nullable', 'array', 'max:' . self::MAX_RECIPIENTS], 'lead_ids.*' => ['integer'],
            'rows' => ['nullable', 'array', 'max:' . self::MAX_RECIPIENTS], 'rows.*.email' => ['nullable', 'string', 'max:190'],
            'rows.*.name' => ['nullable', 'string', 'max:160'], 'rows.*.company' => ['nullable', 'string', 'max:190'],
            'consent_basis' => ['nullable', 'in:' . implode(',', array_keys(SalesCampaigns::CONSENT))],
            'audience' => ['nullable', 'in:subscribers'],
        ]);
        $basis = $d['consent_basis'] ?? $c->consent_basis;
        $cands = [];
        /* ALL SUBSCRIBERS (2026-10-27): everyone on the KiddieTrac subscriber list who has not unsubscribed - users of
           onboarded agencies, leads and newsletter sign-ups (implied consent: existing business relationship). */
        $already = [];
        if (($d['audience'] ?? null) === 'subscribers') {
            $basis = 'implied_business';
            foreach (DB::table('site_subscribers')->whereNull('unsubscribed_at')->orderBy('id')->get(['email', 'name', 'agency_name']) as $sb) {
                $cands[] = ['email' => $sb->email, 'name' => $sb->name, 'company' => $sb->agency_name, 'lead_id' => null];
            }
            if (! empty($c->template) && \Illuminate\Support\Facades\Schema::hasTable('subscriber_campaign_sends')) {
                $already = DB::table('subscriber_campaign_sends')->where('campaign', $c->template)->pluck('email')->map(fn ($e) => mb_strtolower($e))->flip()->all();
            }
        }
        if (! empty($d['lead_ids'])) {
            foreach (DB::table('sales_leads')->whereIn('id', $d['lead_ids'])->whereNull('deleted_at')->get(['id', 'name', 'company', 'email']) as $l) {
                $cands[] = ['email' => $l->email, 'name' => $l->name, 'company' => $l->company, 'lead_id' => (int) $l->id];
            }
        }
        foreach ($d['rows'] ?? [] as $row) $cands[] = ['email' => $row['email'] ?? '', 'name' => $row['name'] ?? null, 'company' => $row['company'] ?? null, 'lead_id' => null];
        $have = DB::table('sales_campaign_recipients')->where('campaign_id', $id)->pluck('email')->map(fn ($e) => mb_strtolower($e))->flip()->all();
        $count = count($have);
        $res = ['added' => 0, 'duplicates' => 0, 'invalid' => 0, 'unsubscribed' => 0, 'over_limit' => 0];
        foreach ($cands as $cd) {
            $email = mb_strtolower(trim((string) $cd['email']));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { $res['invalid']++; continue; }
            if (isset($have[$email])) { $res['duplicates']++; continue; }
            if (isset($already[$email])) { $res['duplicates']++; continue; }   // already received this subscriber email
            if ($count >= self::MAX_RECIPIENTS) { $res['over_limit']++; continue; }
            $lead = $cd['lead_id'] ?: DB::table('sales_leads')->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])->value('id');
            $sup = SalesCampaigns::suppressed($email);
            DB::table('sales_campaign_recipients')->insert([
                'campaign_id' => $id, 'lead_id' => $lead ?: null, 'email' => $email, 'name' => $cd['name'] ? mb_substr(trim($cd['name']), 0, 160) : null,
                'company' => $cd['company'] ? mb_substr(trim($cd['company']), 0, 190) : null, 'consent_basis' => $basis, 'token' => SalesCampaigns::token(),
                'status' => $sup ? 'skipped' : 'queued', 'skip_reason' => $sup ? 'unsubscribed' : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $have[$email] = 1; $count++;
            $sup ? $res['unsubscribed']++ : $res['added']++;
        }
        SalesCampaigns::recount($id);
        return response()->json($res + ['campaign' => $this->present($this->campaign($id))]);
    }

    /** DELETE /sales/campaigns/{id}/recipients/{rid} - take someone off before their email goes. */
    public function removeRecipient(int $id, int $rid): JsonResponse
    {
        $this->campaign($id);
        $n = DB::table('sales_campaign_recipients')->where('campaign_id', $id)->where('id', $rid)->whereIn('status', ['queued', 'skipped', 'failed'])->delete();
        abort_unless($n, 422, 'Their email has already gone, so they stay on the campaign.');
        SalesCampaigns::recount($id);
        return response()->json(['ok' => true]);
    }

    /** GET /sales/campaigns/{id}/preview - the email exactly as it will look (links untracked). */
    public function preview(Request $request, int $id)
    {
        $c = $this->campaign($id);
        return response(SalesCampaigns::render($c, null, (int) $request->user()->id), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** The test addresses this person last used (remembered for the next test, 2026-11-05). */
    public const TEST_PREF = 'sales_campaign_test_to';
    public static function testAddresses(int $uid): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('user_ui_prefs')) return [];
        $v = json_decode((string) DB::table('user_ui_prefs')->where('user_id', $uid)->where('pref_key', self::TEST_PREF)->value('value'), true);
        return is_array($v) ? array_values(array_filter($v, fn ($e) => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL))) : [];
    }

    /**
     * POST /sales/campaigns/{id}/test {emails: [..] (up to 5) | email} - a copy to each address you set, marked [TEST],
     * nothing tracked. The addresses are remembered for your next test (2026-11-05, Anthony: "test to send to my own
     * email (ability to configure a test email)").
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $c = $this->campaign($id);
        $d = $request->validate(['email' => ['required_without:emails', 'nullable', 'email', 'max:190'], 'emails' => ['required_without:email', 'nullable', 'array', 'min:1', 'max:5'], 'emails.*' => ['email', 'max:190']]);
        $to = array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim($e)), $d['emails'] ?? [$d['email']])));
        $uid = (int) $request->user()->id;
        $sent = []; $failed = [];
        foreach ($to as $e) {
            [$ok, $err] = SalesCampaigns::sendOne($c, null, $e, $uid);
            if ($ok) $sent[] = $e; else $failed[] = ['email' => $e, 'message' => 'Not sent: ' . $err];
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('user_ui_prefs')) {
            DB::table('user_ui_prefs')->updateOrInsert(['user_id' => $uid, 'pref_key' => self::TEST_PREF], ['value' => json_encode($to), 'updated_at' => now()]);
        }
        abort_unless($sent, 422, 'The test could not be sent: ' . ($failed[0]['message'] ?? 'no address'));
        Audit::write(['user_id' => $uid, 'action' => 'sales.campaign_test', 'entity_type' => 'sales_campaign', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Sent a test of "' . $c->name . '" to ' . implode(', ', $sent)]), 'created_at' => now()]);
        return response()->json(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
    }

    /**
     * POST /sales/campaigns/{id}/send {confirm} - start sending. `confirm` must equal the number of people still to
     * receive it, so nobody sends to a list they have not looked at.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $c = $this->campaign($id);
        abort_if($c->status === 'sending', 422, 'This campaign is already sending.');
        $queued = DB::table('sales_campaign_recipients')->where('campaign_id', $id)->where('status', 'queued')->count();
        abort_unless($queued > 0, 422, 'Nobody is waiting to receive this campaign. Add people first.');
        $d = $request->validate(['confirm' => ['required', 'integer']]);
        abort_unless((int) $d['confirm'] === $queued, 422, 'The number of people changed. Check the list and confirm again (' . $queued . ').');
        DB::table('sales_campaigns')->where('id', $id)->update(['status' => 'sending', 'sent_by' => (int) $request->user()->id,
            'send_started_at' => $c->send_started_at ?: now(), 'updated_at' => now()]);
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'sales.campaign_sent', 'entity_type' => 'sales_campaign', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Started sending "' . $c->name . '" to ' . $queued . ' people']), 'created_at' => now()]);
        return response()->json(['campaign' => $this->present($this->campaign($id))]);
    }

    /**
     * POST /sales/campaigns/{id}/schedule {send_at: "2026-10-30T09:00" (America/Toronto) | null} - when the scheduler
     * starts it. A new time clears an earlier approval: what was approved is the time it was approved for.
     */
    public function schedule(Request $request, int $id): JsonResponse
    {
        $c = $this->campaign($id);
        abort_if(in_array($c->status, ['sending', 'sent'], true), 422, 'This campaign has already gone out.');
        $d = $request->validate(['send_at' => ['nullable', 'date_format:Y-m-d\TH:i']]);
        $at = ! empty($d['send_at']) ? Carbon::createFromFormat('Y-m-d\TH:i', $d['send_at'], self::TZ)->utc() : null;
        abort_if($at && $at->lt(now()->subMinute()), 422, 'Pick a time in the future.');
        DB::table('sales_campaigns')->where('id', $id)->update(['scheduled_for' => $at, 'approved_at' => null, 'approved_by' => null, 'updated_at' => now()]);
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'sales.campaign_scheduled', 'entity_type' => 'sales_campaign', 'entity_id' => $id,
            'payload' => json_encode(['summary' => $at ? 'Scheduled "' . $c->name . '" for ' . $at->copy()->tz(self::TZ)->format('D M j, g:i A T') . ' (needs approval)' : 'Unscheduled "' . $c->name . '"']), 'created_at' => now()]);
        return response()->json(['campaign' => $this->present($this->campaign($id))]);
    }

    /** POST /sales/campaigns/{id}/approve {approve: true|false} - a scheduled campaign sends only once approved. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $c = $this->campaign($id);
        abort_if(in_array($c->status, ['sending', 'sent'], true), 422, 'This campaign has already gone out.');
        $d = $request->validate(['approve' => ['required', 'boolean']]);
        if ($d['approve']) {
            abort_unless(! empty($c->scheduled_for), 422, 'Set the send time first.');
            abort_unless(DB::table('sales_campaign_recipients')->where('campaign_id', $id)->where('status', 'queued')->exists(), 422, 'Nobody is waiting to receive this campaign. Add people first.');
        }
        DB::table('sales_campaigns')->where('id', $id)->update($d['approve']
            ? ['approved_at' => now(), 'approved_by' => (int) $request->user()->id, 'updated_at' => now()]
            : ['approved_at' => null, 'approved_by' => null, 'updated_at' => now()]);
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => $d['approve'] ? 'sales.campaign_approved' : 'sales.campaign_unapproved', 'entity_type' => 'sales_campaign', 'entity_id' => $id,
            'payload' => json_encode(['summary' => ($d['approve'] ? 'Approved "' : 'Withdrew approval for "') . $c->name . '"' . ($d['approve'] ? ' to send ' . Carbon::parse($c->scheduled_for, 'UTC')->tz(self::TZ)->format('D M j, g:i A T') : '')]), 'created_at' => now()]);
        return response()->json(['campaign' => $this->present($this->campaign($id))]);
    }

    /** POST /sales/campaigns/{id}/pause - stop sending; the rest wait until it is resumed (sent again). */
    public function pause(Request $request, int $id): JsonResponse
    {
        $c = $this->campaign($id);
        abort_unless($c->status === 'sending', 422, 'This campaign is not sending.');
        DB::table('sales_campaigns')->where('id', $id)->update(['status' => 'paused', 'updated_at' => now()]);
        return response()->json(['campaign' => $this->present($this->campaign($id))]);
    }

    /**
     * POST /sales/campaigns/{id}/recipients/{rid}/action {action}: replied | interested (a response recorded by hand),
     * lead (add to Leads without a response), unsubscribe (never email them again).
     */
    public function recipientAction(Request $request, int $id, int $rid): JsonResponse
    {
        $c = $this->campaign($id);
        $r = DB::table('sales_campaign_recipients')->where('campaign_id', $id)->where('id', $rid)->first();
        abort_unless($r, 404, 'Not found.');
        $d = $request->validate(['action' => ['required', 'in:replied,interested,lead,unsubscribe'], 'note' => ['nullable', 'string', 'max:2000']]);
        $uid = (int) $request->user()->id;
        if ($d['action'] === 'lead') {
            $lead = SalesCampaigns::ensureLead($r, $c, $uid);
        } else {
            SalesCampaigns::respond($r, $d['action'] === 'unsubscribe' ? 'unsubscribed' : $d['action'], $uid, $d['note'] ?? null);
            $lead = DB::table('sales_campaign_recipients')->where('id', $rid)->value('lead_id');
        }
        return response()->json(['ok' => true, 'lead_id' => $lead ? (int) $lead : null]);
    }
}
