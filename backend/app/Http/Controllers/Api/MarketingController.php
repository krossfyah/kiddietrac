<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * v22p34 — Marketing campaigns. Directors and agency admins compose rich
 * messages (newsletters, promotions, open-house announcements) with images
 * and send them to a chosen audience.
 *
 * The 'email' channel is wired but actual sending is queued and processed
 * by the scheduled command in v22p35. The 'in_portal' channel writes to
 * the existing announcements table so families see them in their feed
 * immediately, no scheduler needed.
 */
final class MarketingController extends Controller
{
    // ── Agency scope resolver — same shape as AdminController ─────────────

    private function getAgencyId(Request $request): ?int
    {
        $userId = $request->user()->id;
        $isPlatform = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatform) {
            if ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists()) {
                return $activeId;
            }
            // No agency chosen means none (2026-11-05 leak sweep), not whichever agency has the lowest id.
            return null;
        }
        /* The agency you are working in, and only if you are an admin or director THERE (2026-11-05 leak sweep):
           this used to fall back to the first director/admin row held anywhere, so a person with roles in two
           agencies could have campaigns read, sent and deleted in the other one. */
        $active = \App\Support\AgencyRole::activeAgency($request);
        if ($active && array_intersect(\App\Support\AgencyRole::names($request), ['agency_admin', 'centre_director'])) {
            return $active;
        }
        return null;
    }

    /* The centres a director may run campaigns for (2026-11-05 leak sweep). null = agency-wide (admin /
       platform). Otherwise the centres where the caller is centre_director in this agency: Visibility alone
       would also count a centre where they are only an educator. Fails closed ([] = nothing). */
    private function campaignScope(Request $request, int $agencyId): ?array
    {
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if ($scope === null) return null;
        $dir = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $request->user()->id)->where('role', 'centre_director')->where('active', true)
            ->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
        return array_values(array_intersect($scope, $dir));
    }

    private function getCentreIdIfDirector(Request $request): ?int
    {
        $assignment = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $request->user()->id)
            ->where('role', 'centre_director')
            ->where('active', true)
            ->first();
        return $assignment?->centre_id ? (int) $assignment->centre_id : null;
    }

    // ── List / show ───────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['campaigns' => []]);

        $q = DB::table('marketing_campaigns')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNull('trigger_event')   // drip campaigns are listed on Drip campaigns, never here (2026-11-28)
            ->orderByDesc('updated_at');

        // Directors only see campaigns scoped to their centre (or agency-wide)
        $centreId = $this->getCentreIdIfDirector($request);
        if ($centreId) {
            $q->where(function ($x) use ($centreId) { $x->whereNull('centre_id')->orWhere('centre_id', $centreId); });
        }
        /* A centre-scoped caller sees agency-wide campaigns and their own centres' only (2026-11-05 leak sweep):
           with no director row found above, the list was the whole agency. */
        $scope = $this->campaignScope($request, $agencyId);
        if ($scope !== null) {
            $q->where(function ($x) use ($scope) { $x->whereNull('centre_id')->orWhereIn('centre_id', $scope ?: [0]); });
        }

        $rows = $q->limit(100)->get();
        return response()->json(['campaigns' => $rows]);
    }

    /* ── CAMPAIGN REPORT (2026-10-31, Anthony: "in reports, add a ... campaign report") ─────────────────────────
       Reports → Campaign report. The agency's own campaigns (Marketing → Campaigns) with the counts the send
       actually recorded, plus announcements to families with their email delivery/opens from email_logs. Nothing
       is estimated: a figure the platform never recorded comes back null and the screen says "not tracked".
       Same scope as the campaigns list: the active agency; a director sees agency-wide + their centre only. */
    public function report(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['campaigns' => [], 'announcements' => [], 'months' => [], 'totals' => []]);
        $isDate = fn ($v) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v);
        $from = $isDate($request->query('from')) ? (string) $request->query('from') : now()->subMonths(12)->toDateString();
        $to = $isDate($request->query('to')) ? (string) $request->query('to') : now()->toDateString();
        $centreId = $this->getCentreIdIfDirector($request);

        $q = DB::table('marketing_campaigns')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNull('trigger_event')
            ->where(function ($w) use ($from, $to) {
                $w->whereBetween(DB::raw('DATE(COALESCE(sent_at, email_sent_at, scheduled_for, created_at))'), [$from, $to]);
            });
        if ($centreId) { $q->where(function ($x) use ($centreId) { $x->whereNull('centre_id')->orWhere('centre_id', $centreId); }); }
        /* Same centre limit as the list, and the announcements below too (2026-11-05 leak sweep): a scoped caller
           with no director row read every centre's figures. */
        $scope = $this->campaignScope($request, $agencyId);
        if ($scope !== null) {
            $q->where(function ($x) use ($scope) { $x->whereNull('centre_id')->orWhereIn('centre_id', $scope ?: [0]); });
        }
        $camps =$q->orderByDesc(DB::raw('COALESCE(sent_at, email_sent_at, created_at)'))->get();

        $campaigns = $camps->map(function ($c) {
            $sent = $c->sent_at ?: $c->email_sent_at;
            $delivered = $c->email_delivery_count !== null ? (int) $c->email_delivery_count : ($c->delivery_count !== null ? (int) $c->delivery_count : null);
            $opens = $c->open_count !== null ? (int) $c->open_count : null;
            $clicks = $c->click_count !== null ? (int) $c->click_count : null;
            return [
                'id' => (int) $c->id, 'title' => $c->title ?: $c->subject ?: 'Untitled campaign', 'channel' => $c->channel ?: 'email',
                'status' => $c->status, 'audience' => $c->audience, 'sent_at' => $sent, 'scheduled_for' => $c->scheduled_for,
                'recipients' => $c->recipient_count !== null ? (int) $c->recipient_count : null,
                'delivered' => $delivered,
                'failed' => $c->email_failure_count !== null ? (int) $c->email_failure_count : null,
                'opens' => $opens, 'clicks' => $clicks,
                'open_rate' => ($delivered && $opens !== null) ? round($opens / $delivered * 100, 1) : null,
                'click_rate' => ($delivered && $clicks !== null) ? round($clicks / $delivered * 100, 1) : null,
                'automated' => (bool) $c->trigger_event,
            ];
        })->values();

        // Announcements to families: channels chosen, and for email the logged deliveries/opens (email_logs rows of
        // this agency with the announcement's title in the subject, within two days of sending).
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $aq = DB::table('announcements')->whereNotNull('sent_at')->whereBetween(DB::raw('DATE(sent_at)'), [$from, $to])
            ->where(function ($w) use ($agencyId, $centreIds, $centreId, $scope) {
                $w->where(function ($x) use ($agencyId) { $x->where('scope_type', 'agency')->where('scope_id', $agencyId); });
                $ids = $centreId ? [$centreId] : $centreIds;
                if ($scope !== null) $ids = array_values(array_intersect(array_map('intval', $ids), $scope));
                if ($ids) { $w->orWhere(function ($x) use ($ids) { $x->where('scope_type', 'centre')->whereIn('scope_id', $ids); }); }
            });
        $anns = $aq->orderByDesc('sent_at')->limit(500)->get(['id', 'title', 'audience', 'scope_type', 'send_email', 'send_sms', 'send_push', 'send_voice', 'sent_at']);
        $announcements = $anns->map(function ($a) use ($agencyId) {
            $emails = null; $opened = null; $failed = null;
            if ($a->send_email && trim((string) $a->title) !== '') {
                $logs = DB::table('email_logs')->where('agency_id', $agencyId)
                    ->where('subject', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string) $a->title, 0, 120)) . '%')
                    ->whereBetween('created_at', [Carbon::parse($a->sent_at)->subMinutes(10), Carbon::parse($a->sent_at)->addDays(2)])
                    ->selectRaw("SUM(status = 'sent') sent, SUM(status = 'sent' AND opened_at IS NOT NULL) opened, SUM(status IN ('failed','bounced')) failed")->first();
                $emails = (int) ($logs->sent ?? 0); $opened = (int) ($logs->opened ?? 0); $failed = (int) ($logs->failed ?? 0);
            }
            $ch = array_values(array_filter([$a->send_email ? 'email' : null, $a->send_sms ? 'sms' : null, $a->send_push ? 'push' : null, $a->send_voice ? 'voice' : null]));
            return ['id' => (int) $a->id, 'title' => $a->title, 'audience' => $a->audience, 'scope' => $a->scope_type, 'channels' => $ch,
                'sent_at' => $a->sent_at, 'emails_sent' => $emails, 'emails_opened' => $opened, 'emails_failed' => $failed,
                'open_rate' => $emails ? round($opened / $emails * 100, 1) : null];
        })->values();

        $months = [];
        foreach ($campaigns as $c) {
            $m = substr((string) ($c['sent_at'] ?: ''), 0, 7); if (! $m) continue;
            $months[$m] = $months[$m] ?? ['month' => $m, 'campaigns' => 0, 'announcements' => 0, 'delivered' => 0, 'opens' => 0];
            $months[$m]['campaigns']++; $months[$m]['delivered'] += (int) $c['delivered']; $months[$m]['opens'] += (int) $c['opens'];
        }
        foreach ($announcements as $a) {
            $m = substr((string) $a['sent_at'], 0, 7); if (! $m) continue;
            $months[$m] = $months[$m] ?? ['month' => $m, 'campaigns' => 0, 'announcements' => 0, 'delivered' => 0, 'opens' => 0];
            $months[$m]['announcements']++; $months[$m]['delivered'] += (int) $a['emails_sent']; $months[$m]['opens'] += (int) $a['emails_opened'];
        }
        ksort($months);
        $sentCamps = $campaigns->filter(fn ($c) => $c['sent_at']);
        $del = (int) $sentCamps->sum('delivered') + (int) $announcements->sum('emails_sent');
        $opn = (int) $sentCamps->sum('opens') + (int) $announcements->sum('emails_opened');
        return response()->json([
            'from' => $from, 'to' => $to,
            'campaigns' => $campaigns, 'announcements' => $announcements, 'months' => array_values($months),
            'totals' => ['campaigns_sent' => $sentCamps->count(), 'announcements' => $announcements->count(),
                'delivered' => $del, 'opens' => $opn, 'open_rate' => $del ? round($opn / $del * 100, 1) : null,
                'clicks' => (int) $sentCamps->sum('clicks')],
            'not_tracked' => ['Per-link clicks', 'Unsubscribes per campaign', 'SMS opens'],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('marketing_campaigns')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNull('trigger_event')->first();   // drips live on their own screen (2026-11-28)
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        /* A director opens agency-wide campaigns and their own centres' only (2026-11-05 leak sweep): any id in
           the agency used to open, including another centre's draft. */
        if (! \App\Support\CentreScope::canRead($this->campaignScope($request, (int) $agencyId), $row->centre_id, true)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        /* ?render=1 (2026-11-27, the list's ⋮ View): the email exactly as the sender builds it
           (SendCampaignEmailsCommand::build), with {first_name} filled by a sample name. Read-only: builds HTML,
           sends nothing, writes nothing. */
        if ($request->boolean('render')) {
            $agency = DB::table('agencies')->where('id', $row->agency_id)->first();
            if ($agency) {
                $sample = \App\Support\CampaignMerge::firstName((string) $request->query('sample', 'Sarah'));
                $built = \App\Console\Commands\SendCampaignEmailsCommand::build($row, $agency);

                return response()->json(['campaign' => $row, 'email' => [
                    'subject' => \App\Support\CampaignMerge::text($built['subject'], $sample),
                    'preheader' => \App\Support\CampaignMerge::text($built['preheader'], $sample),
                    'html' => \App\Support\CampaignMerge::html($built['html'], $sample),
                    'sample_name' => $sample,
                    'uses_first_name' => \App\Support\CampaignMerge::has($row->body_html . ' ' . $row->subject . ' ' . $row->title),
                ]]);
            }
        }
        return response()->json(['campaign' => $row]);
    }

    // ── Create / update ───────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body_html' => ['required', 'string', 'max:200000'],
            'header_asset_id' => ['nullable', 'integer'],
            'footer_asset_id' => ['nullable', 'integer'],
            'hero_image_url' => ['nullable', 'string', 'max:500'],
            'audience' => ['required', 'in:all_families,active_families,waitlist,prospects,staff,custom'],
            'channel' => ['required', 'in:email,in_portal,both'],
            'centre_id' => ['nullable', 'integer'],
            'scheduled_for' => ['nullable', 'date'],
            'status' => ['nullable', 'in:draft,scheduled'],
            'sender_id' => ['nullable', 'integer'],
        ]);
        [$okSender, $senderMsg] = \App\Support\CampaignSender::validateChoice($agencyId, $data['sender_id'] ?? null);
        if (! $okSender) return response()->json(['message' => $senderMsg], 422);

        $status = $data['status'] ?? ($data['scheduled_for'] ?? null ? 'scheduled' : 'draft');

        // Directors can only target their own centre or leave NULL (agency-wide
        // is disallowed unless caller is agency_admin or platform_admin).
        $directorCentre = $this->getCentreIdIfDirector($request);
        if ($directorCentre) {
            $data['centre_id'] = $directorCentre;
        } elseif (!empty($data['centre_id'])) {
            $belongs = DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->exists();
            if (!$belongs) return response()->json(['message' => 'Invalid centre'], 422);
        }
        /* A centre-scoped caller may only create a campaign for a centre they direct (2026-11-05 leak sweep):
           with no director row found above, a NULL (whole agency) or any in-agency centre_id was accepted. */
        if (! \App\Support\CentreScope::canWrite($this->campaignScope($request, $agencyId), $data['centre_id'] ?? null)) {
            return response()->json(['message' => 'You can only create campaigns for a centre you direct.'], 403);
        }

        $id = DB::table('marketing_campaigns')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'title' => $data['title'],
            'subject' => $data['subject'] ?? null,
            'body_html' => $this->sanitiseHtml($data['body_html']),
            'hero_image_url' => $data['hero_image_url'] ?? null,
            'audience' => $data['audience'],
            'channel' => $data['channel'],
            'sender_id' => ! empty($data['sender_id']) ? (int) $data['sender_id'] : null,
            'status' => $status,
            'scheduled_for' => $data['scheduled_for'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'message' => 'Campaign saved'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('marketing_campaigns')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNull('trigger_event')->first();   // drips live on their own screen (2026-11-28)
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        /* Only a campaign of a centre you direct (2026-11-05 leak sweep): a director could edit any campaign in
           the agency by id, including agency-wide ones. */
        if (! \App\Support\CentreScope::canWrite($this->campaignScope($request, (int) $agencyId), $row->centre_id)) {
            return response()->json(['message' => 'You can only change campaigns for a centre you direct.'], 403);
        }
        if ($row->status === 'sent') return response()->json(['message' => 'Sent campaigns are read-only'], 422);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'body_html' => ['sometimes', 'string', 'max:200000'],
            'header_asset_id' => ['nullable', 'integer'],
            'footer_asset_id' => ['nullable', 'integer'],
            'hero_image_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'audience' => ['sometimes', 'in:all_families,active_families,waitlist,prospects,staff,custom'],
            'channel' => ['sometimes', 'in:email,in_portal,both'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'in:draft,scheduled,archived'],
            'sender_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        if (array_key_exists('sender_id', $data)) {
            [$okSender, $senderMsg] = \App\Support\CampaignSender::validateChoice($agencyId, $data['sender_id']);
            if (! $okSender) return response()->json(['message' => $senderMsg], 422);
            $data['sender_id'] = ! empty($data['sender_id']) ? (int) $data['sender_id'] : null;
        }
        /* A header/footer must be one of this agency's saved blocks (2026-11-05 leak sweep): any asset id was
           written, and composeBody then pulled another agency's header/footer into this campaign. */
        foreach (['header_asset_id', 'footer_asset_id'] as $k) {
            if (! empty($data[$k]) && ! DB::table('marketing_assets')->where('id', (int) $data[$k])
                    ->where('agency_id', $agencyId)->whereNull('deleted_at')->exists()) {
                return response()->json(['message' => 'That header or footer was not found for this agency.'], 422);
            }
        }
        if (isset($data['body_html'])) $data['body_html'] = $this->sanitiseHtml($data['body_html']);
        $data['updated_at'] = now();
        DB::table('marketing_campaigns')->where('id', $id)->update($data);
        return response()->json(['message' => 'Campaign updated']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('marketing_campaigns')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNull('trigger_event')->first();   // drips live on their own screen (2026-11-28)
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        /* Only a campaign of a centre you direct (2026-11-05 leak sweep): any id in the agency could be deleted. */
        if (! \App\Support\CentreScope::canWrite($this->campaignScope($request, (int) $agencyId), $row->centre_id)) {
            return response()->json(['message' => 'You can only delete campaigns for a centre you direct.'], 403);
        }
        DB::table('marketing_campaigns')->where('id', $id)->update(['deleted_at' => now()]);
        return response()->json(['message' => 'Campaign deleted']);
    }

    // ── Send (in-portal channel writes to announcements; email queues) ────

    public function sendNow(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('marketing_campaigns')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNull('trigger_event')->first();   // drips live on their own screen (2026-11-28)
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        /* Only a campaign of a centre you direct (2026-11-05 leak sweep): a director could send an agency-wide
           or another centre's campaign by id, so it reached families outside their centres. */
        if (! \App\Support\CentreScope::canWrite($this->campaignScope($request, (int) $agencyId), $row->centre_id)) {
            return response()->json(['message' => 'You can only send campaigns for a centre you direct.'], 403);
        }
        if (in_array($row->status, ['sent', 'sending'], true)) return response()->json(['message' => 'Already sent or sending'], 422);

        DB::table('marketing_campaigns')->where('id', $id)->update(['status' => 'sending', 'updated_at' => now()]);

        $recipients = $this->resolveAudience($row, $agencyId);

        // In-portal channel: stamp an announcements row so families see it in
        // their feed. The email channel will be picked up by the v22p35
        // scheduled command — for now we just mark recipient_count so the
        // operator sees the projected fan-out.
        if (in_array($row->channel, ['in_portal', 'both'], true)) {
            /* ONLY THE CAMPAIGN'S PEOPLE SEE IT (2026-11-27, App\Support\CampaignNews). This row was scoped to the
               whole centre/agency as 'parents', so a staff-only or waitlist-only campaign showed in every family's
               News. It is now linked to the campaign and lists exactly the people the campaign resolved to; every
               News reader shows a campaign row to listed people only (no list = nobody). */
            if (! \App\Support\CampaignNews::ready()) {
                DB::table('marketing_campaigns')->where('id', $id)->update(['status' => $row->status, 'updated_at' => now()]);
                return response()->json(['message' => 'Campaigns cannot post to News right now. Please try again shortly.'], 503);
            }
            $annId = DB::table('announcements')->insertGetId([
                'scope_type' => $row->centre_id ? 'centre' : 'agency',
                'scope_id' => $row->centre_id ?: $agencyId,
                'agency_id' => $agencyId,
                'audience' => \App\Support\CampaignNews::audienceFor($row->audience),
                'ref_type' => \App\Support\CampaignNews::REF,
                'ref_id' => (int) $row->id,
                // The News post is one copy every family reads, so {first_name} reads "there" ("Hi there,").
                'title' => \App\Support\CampaignMerge::text($row->title, null),
                // The header and footer go out with it, so the in-portal copy reads
                // the same as the emailed one.
                'body' => strip_tags(\App\Support\CampaignMerge::html($this->composeBody($row), null), '<p><br><a><img><ul><ol><li><strong><em><h1><h2><h3>'),
                'send_email' => 0,
                'send_push' => 1,
                'sent_at' => now(),
                'created_by_id' => $row->created_by_user_id,
                'created_at' => now(),
            ]);
            \App\Support\CampaignNews::record((int) $annId, $recipients);
        }

        /* Only the in-portal copy has been delivered at this point. The email goes out from
           kiddietrac:campaigns-mail within ~5 minutes (paced for a Gmail sender), which fills
           in email_delivery_count / email_failure_count. This used to report every recipient
           as delivered before a single email had left (2026-10-20). */
        $portal = in_array($row->channel, ['in_portal', 'both'], true);
        DB::table('marketing_campaigns')->where('id', $id)->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipient_count' => count($recipients),
            'delivery_count' => $portal ? count($recipients) : 0,
            'updated_at' => now(),
        ]);

        $emailing = in_array($row->channel, ['email', 'both'], true);
        return response()->json([
            'message' => $emailing ? 'Campaign queued: the email goes out within a few minutes' : 'Campaign sent',
            'recipients' => count($recipients),
            'channel' => $row->channel,
            'email_pending' => $emailing,
        ]);
    }

    // ── Recipients & stats (2026-11-27) ────────────────────────────────────

    /** The campaign the caller may read (agency + director scope), or null. */
    private function readableCampaign(Request $request, int $id): ?object
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return null;
        $row = DB::table('marketing_campaigns')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNull('trigger_event')->first();   // drips live on their own screen (2026-11-28)
        if (! $row || ! \App\Support\CentreScope::canRead($this->campaignScope($request, (int) $agencyId), $row->centre_id, true)) return null;

        return $row;
    }

    /**
     * GET /marketing/campaigns/{id}/recipients — who a campaign went to and what happened, per person, from what was
     * really recorded (App\Support\CampaignSends explains each status and what cannot be known). Read-only, except
     * that a campaign emailed before per-person records existed is rebuilt once from its logs (CampaignSends::backfill).
     * Same scope as the list: the active agency; a director only agency-wide campaigns and their own centres'.
     */
    public function recipients(Request $request, int $id): JsonResponse
    {
        $c = $this->readableCampaign($request, $id);
        if (! $c) return response()->json(['message' => 'Not found'], 404);
        $emailing = in_array($c->channel, ['email', 'both'], true);
        $ready = \App\Support\CampaignSends::ready();
        if ($ready && $emailing) \App\Support\CampaignSends::backfill($c);

        $sends = $ready ? DB::table('marketing_campaign_sends')->where('campaign_id', $c->id)->orderBy('last_name')->orderBy('first_name')->get() : collect();
        $logs = $sends->pluck('email_log_id')->filter()->all()
            ? DB::table('email_logs')->whereIn('id', $sends->pluck('email_log_id')->filter()->all())
                ->get(['id', 'status', 'error', 'bounced_at', 'opened_at', 'opens', 'created_at'])->keyBy('id')
            : collect();

        // In-app notices (the bell) written for each recipient once the email run finished, and whether they were read.
        $bell = DB::table('notifications')->where('type', 'marketing')
            ->whereRaw("JSON_EXTRACT(data, '$.campaign_id') = ?", [(int) $c->id])
            ->get(['user_id', 'read_at'])->keyBy('user_id');

        // Families the people belong to (a name families recognise beside the parent's own).
        $uids = $sends->pluck('user_id')->filter()->unique()->values()->all();
        $families = $uids ? DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->whereIn('g.user_id', $uids)
            ->whereNull('f.deleted_at')->get(['g.user_id', 'f.family_name'])->groupBy('user_id')
            ->map(fn ($r) => $r->pluck('family_name')->filter()->unique()->implode(', ')) : collect();

        /* A bounced row followed by a resend to that person's corrected address (App\Support\CampaignResend) is one
           person, not two: the bounced line is folded into the resend line, which names the address that bounced. */
        $folded = \App\Support\CampaignResend::resentTo($sends, $logs);
        $resentFrom = [];
        foreach ($folded as $bouncedId => $r) {
            $old = $sends->firstWhere('id', $bouncedId);
            if ($old) $resentFrom[(int) $r->id] = $old->email;
        }

        $tiles = ['sent' => 0, 'opened' => 0, 'bounced' => 0, 'failed' => 0, 'held' => 0, 'unknown' => 0];
        $rows = [];
        foreach ($sends as $s) {
            if (isset($folded[(int) $s->id])) continue;
            $log = $s->email_log_id ? $logs->get($s->email_log_id) : null;
            [$status, $why, $at] = \App\Support\CampaignSends::status($s, $log);
            $tiles[$status] = ($tiles[$status] ?? 0) + 1;
            $b = $s->user_id ? $bell->get($s->user_id) : null;
            $rows[] = [
                'id' => (int) $s->id,
                'name' => trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? '')) ?: null,
                'family' => $s->user_id ? ($families->get($s->user_id) ?: null) : null,
                'email' => $s->email,
                'status' => $status,
                'reason' => $why,
                'at' => $at,
                'sent_at' => $s->sent_at,
                'opens' => $log && $log->opens !== null ? (int) $log->opens : null,
                'opened_at' => $log->opened_at ?? null,
                'bell' => $b ? ($b->read_at ? 'read' : 'unread') : null,
                'has_email' => (bool) $s->email_log_id,
                'source' => $s->source,
                'resent_from' => $resentFrom[(int) $s->id] ?? null,
            ];
        }
        /* Resend to bounced (2026-11-27): how many bounced, how many of those now have a corrected address, and
           whether this caller may resend (the same rule as sending the campaign). */
        $resend = null;
        if ($emailing && $tiles['bounced'] > 0) {
            $cands = \App\Support\CampaignResend::candidates($c);
            $resend = ['bounced' => $tiles['bounced'], 'corrected' => count(array_filter($cands, fn ($x) => $x['state'] === 'ready')),
                'can' => $this->canResend($request, $c)];
        }
        $total = count($rows);
        $emailedTotal = (int) ($c->recipient_count ?? 0);
        $pending = ($emailing && $c->sent_at && empty($c->email_sent_at)) ? max(0, $emailedTotal - $total) : 0;

        // The News post, when the campaign posted one: who it is for and who has opened it in News.
        $news = null;
        $ann = DB::table('announcements')->where('ref_type', \App\Support\CampaignNews::REF)->where('ref_id', $c->id)->orderByDesc('id')->first(['id', 'sent_at']);
        if ($ann) {
            $for = \App\Support\CampaignNews::recipients((int) $ann->id);
            $read = $for ? DB::table('announcement_user_state')->where('item_key', 'a:' . $ann->id)->whereIn('user_id', $for)->whereNotNull('read_at')->count() : 0;
            $news = ['posted_at' => $ann->sent_at, 'for' => count($for), 'read' => $read];
        } elseif (in_array($c->channel, ['in_portal', 'both'], true) && $c->sent_at) {
            $news = ['posted_at' => $c->sent_at, 'for' => null, 'read' => null];   // posted before per-person lists existed
        }

        return response()->json([
            'campaign' => ['id' => (int) $c->id, 'title' => $c->title, 'subject' => $c->subject, 'channel' => $c->channel,
                'audience' => $c->audience, 'status' => $c->status, 'sent_at' => $c->sent_at, 'email_sent_at' => $c->email_sent_at,
                'email_from' => $c->email_from, 'recipient_count' => $c->recipient_count],
            'emailing' => $emailing,
            'tiles' => $tiles + ['total' => $total, 'pending' => $pending, 'clicked' => null],
            'bell' => ['sent' => $bell->count(), 'read' => $bell->filter(fn ($b) => ! empty($b->read_at))->count()],
            'news' => $news,
            'rows' => $rows,
            'backfilled' => $sends->contains(fn ($s) => $s->source === 'backfill'),
            'resend' => $resend,
            'notes' => [
                'delivered' => 'Mail services do not send delivery receipts, so "Sent" means our mail service accepted it and no bounce has come back. Bounces are checked every hour.',
                'opened' => 'Opens are counted when the email\'s images load (our own server, no third party). Someone who blocks images shows as not opened, and some mail apps load images automatically.',
                'clicked' => 'Link clicks are not tracked.',
            ],
        ]);
    }

    /** May this caller resend the campaign? The rule for sending it: agency admins agency-wide, directors their centres. */
    private function canResend(Request $request, object $c): bool
    {
        return \App\Support\CentreScope::canWrite($this->campaignScope($request, (int) $c->agency_id), $c->centre_id);
    }

    /**
     * GET /marketing/campaigns/{id}/bounced — every bounced recipient still on the list, the address it bounced at,
     * their CURRENT account email and what can be done (App\Support\CampaignResend::candidates). Read-only.
     */
    public function bounced(Request $request, int $id): JsonResponse
    {
        $c = $this->readableCampaign($request, $id);
        if (! $c) return response()->json(['message' => 'Not found'], 404);
        if (! in_array($c->channel, ['email', 'both'], true)) return response()->json(['rows' => [], 'can' => false, 'resendable' => false]);

        return response()->json([
            'campaign' => ['id' => (int) $c->id, 'title' => $c->title],
            'rows' => \App\Support\CampaignResend::candidates($c),
            'can' => $this->canResend($request, $c),
            'resendable' => \App\Support\CampaignResend::resendable($c),
            'max' => \App\Support\CampaignResend::MAX,
        ]);
    }

    /**
     * POST /marketing/campaigns/{id}/resend-bounced {send_ids:[...], count:N} — send the campaign again to the
     * chosen bounced people, at their CURRENT (corrected) address. Fails closed: the recipients are never typed or
     * defaulted, only bounced rows that are "ready" right now; one row that is not stops the whole send; count must
     * equal what the confirm dialog stated. CampaignResend::send applies the rest (audience, never twice, 80 cap,
     * mail gate) and reports what really happened from the email log.
     */
    public function resendBounced(Request $request, int $id): JsonResponse
    {
        $c = $this->readableCampaign($request, $id);
        if (! $c) return response()->json(['message' => 'Not found'], 404);
        if (! $this->canResend($request, $c)) return response()->json(['message' => 'You can only resend campaigns for a centre you direct.'], 403);
        if (! \App\Support\CampaignResend::resendable($c)) return response()->json(['message' => 'Only a campaign that has already been emailed can be resent.'], 422);
        $data = $request->validate([
            'send_ids' => 'required|array|min:1|max:' . \App\Support\CampaignResend::MAX,
            'send_ids.*' => 'required|integer|distinct',
            'count' => 'required|integer|min:1',
        ]);
        $ids = array_map('intval', $data['send_ids']);
        if ((int) $data['count'] !== count($ids)) {
            return response()->json(['message' => 'The number of people changed. Please open the list again and check it.'], 422);
        }

        $lock = \Illuminate\Support\Facades\Cache::lock('campaign-resend-' . (int) $c->id, 900);
        if (! $lock->get()) return response()->json(['message' => 'A resend of this campaign is already running. Please wait a moment.'], 409);
        try {
            $ready = [];
            foreach (\App\Support\CampaignResend::candidates($c) as $row) $ready[$row['send_id']] = $row;
            $bad = array_values(array_filter($ids, fn ($i) => ! isset($ready[$i]) || $ready[$i]['state'] !== 'ready'));
            if ($bad) {
                return response()->json(['message' => 'Some of the people you ticked can no longer be resent to (their address or account changed, or they already got it). Nothing was sent. Please open the list again.'], 422);
            }
            $emails = [];
            $replaces = [];
            foreach ($ids as $i) {
                $emails[] = $ready[$i]['current_email'];
                $replaces[$ready[$i]['current_email']] = $ready[$i]['bounced_email'];
            }
            @set_time_limit(300);
            ignore_user_abort(true);
            $res = \App\Support\CampaignResend::send($c, $emails, [
                'web' => true, 'via' => 'portal', 'actor' => (int) $request->user()->id, 'replaces' => $replaces,
            ]);
        } finally {
            $lock->release();
        }
        if (! $res['ok']) return response()->json(['message' => $res['error']], 422);

        $t = $res['tally'];
        $notSent = [];
        foreach ($res['results'] as $r) {
            if ($r['outcome'] !== 'sent') $notSent[] = ['email' => $r['email'], 'outcome' => $r['outcome']];
        }

        return response()->json([
            'ok' => true,
            'sent' => $t['sent'], 'held' => $t['suppressed'], 'failed' => $t['failed'], 'unknown' => $t['unknown'],
            'skipped' => count($res['skipped']), 'left' => count($res['left']),
            'not_sent' => $notSent,
        ]);
    }

    /** GET /marketing/campaigns/{id}/recipients/{sendId}/email — the email as it went to that person (stored copy). */
    public function recipientEmail(Request $request, int $id, int $sendId): JsonResponse
    {
        $c = $this->readableCampaign($request, $id);
        if (! $c || ! \App\Support\CampaignSends::ready()) return response()->json(['message' => 'Not found'], 404);
        $s = DB::table('marketing_campaign_sends')->where('id', $sendId)->where('campaign_id', $c->id)->first();
        $log = $s && $s->email_log_id ? DB::table('email_logs')->where('id', $s->email_log_id)->where(fn ($w) => $w->where('agency_id', $c->agency_id)->orWhereNull('agency_id'))->first(['id', 'subject', 'to_email', 'created_at', 'body_html', 'status']) : null;
        if (! $log) return response()->json(['message' => 'There is no stored copy of this email.'], 404);

        return response()->json(['subject' => $log->subject, 'to' => $log->to_email, 'at' => $log->created_at, 'status' => $log->status, 'html' => self::withoutPixel((string) $log->body_html)]);
    }

    /** Viewing a stored copy must not count as the recipient opening it: the open pixel is taken out. */
    private static function withoutPixel(string $html): string
    {
        return (string) preg_replace('#<img[^>]*/api/v1/e/o/[^>]*>#i', '', $html);
    }

    // ── Send from (white-label) ───────────────────────────────────────────

    /**
     * GET /marketing/senders — what the campaign editor's "Send from" offers. A non
     * white-label agency gets white_label:false and no accounts, so the editor can say
     * the option is part of the licence instead of offering something the server refuses.
     */
    public function senders(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);
        $wl = \App\Support\CampaignSender::whiteLabelAgency($agencyId);

        return response()->json([
            'white_label' => $wl,
            'default' => \App\Support\CampaignSender::defaultFrom($agencyId),
            'senders' => $wl ? \App\Support\CampaignSender::options($agencyId) : [],
        ]);
    }

    // ── Image upload (reused for hero + inline content images) ────────────

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);
        $file = $request->file('image');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) Str::uuid() . '.' . $ext;
        $file->storeAs('marketing', $name, 'public');
        $url = '/storage/marketing/' . $name;
        return response()->json(['url' => $url, 'message' => 'Image uploaded']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Resolve the audience to a list of recipient ids. For now returns
     * user_ids matching the audience filter; the v22p35 mailer will
     * actually look up email addresses + send.
     */
    private function resolveAudience(object $campaign, int $agencyId): array
    {
        $aud = $campaign->audience;
        $centreScope = $campaign->centre_id ? [(int) $campaign->centre_id]
            : \App\Support\Live::centreIds($agencyId);   // open centres only, as the email sender uses

        if ($aud === 'staff') {
            return DB::table('role_assignments')
                ->where('active', true)
                ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
                ->where(function ($q) use ($agencyId, $centreScope) {
                    $q->where('agency_id', $agencyId)->orWhereIn('centre_id', $centreScope);
                })
                ->distinct()->pluck('user_id')->all();
        }
        /* Families = guardians at our centres, of families still with us, whose account can still sign in (2026-11-27,
           the same rule as SendCampaignEmailsCommand::resolveAudience). De-enrolled families were counted and listed
           for News. A parent still waiting on their invite IS included: family campaigns reach them. */
        $base = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereIn('f.centre_id', $centreScope)
            ->tap(fn ($q) => \App\Support\Live::families($q, 'f'))
            ->whereNull('u.deleted_at')
            ->where(fn ($w) => $w->whereNull('u.status')->orWhereNotIn('u.status', ['suspended', 'deactivated']));
        if ($aud === 'active_families') {
            $base->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('children as c')
                    ->whereColumn('c.family_id', 'f.id')
                    ->where('c.enrollment_status', 'enrolled')
                    ->whereNull('c.deleted_at');
            });
        } elseif ($aud === 'waitlist') {
            $base->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('children as c')
                    ->whereColumn('c.family_id', 'f.id')
                    ->where('c.enrollment_status', 'waitlist')
                    ->whereNull('c.deleted_at');
            });
        } elseif ($aud === 'prospects') {
            // Prospects = families that have no enrolled children yet
            $base->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('children as c')
                    ->whereColumn('c.family_id', 'f.id')
                    ->where('c.enrollment_status', 'enrolled');
            });
        }
        // 'all_families' / 'custom' — no extra filter (custom audiences will
        // be wired with explicit recipient lists in a later ship)
        return $base->distinct()->pluck('g.user_id')->all();
    }

    /**
     * Cheap whitelist sanitiser. Strips <script>, <iframe>, on* attributes,
     * and javascript: hrefs. Not a replacement for HTMLPurifier — but enough
     * to keep the editor's output safe for in-portal display and inline
     * email rendering.
     */
    private function sanitiseHtml(string $html): string
    {
        // Drop dangerous tags entirely
        $html = preg_replace('#<(script|iframe|object|embed|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|iframe|object|embed|style|meta|link)\b[^>]*/?>#i', '', $html);
        // Strip on* event handlers
        $html = preg_replace('# on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)#i', '', $html);
        // Neutralise javascript: hrefs
        $html = preg_replace('#href\s*=\s*("|\')\s*javascript:#i', 'href=$1about:blank#blocked-js-', $html);
        $html = preg_replace('#src\s*=\s*("|\')\s*javascript:#i', 'src=$1about:blank#blocked-js-', $html);
        return $html;
    }

    /**
     * GET /marketing/assets?kind=header|footer — this agency's saved blocks.
     */
    /* HEADER, FOOTER — AND NOW HERO.

       A hero image used to be uploaded per campaign through /marketing/images, which
       returns a URL and keeps no record: the picture existed, nothing listed it, and the
       next campaign that wanted the same banner had to find the original file again. It is
       the same thing a header is — an image an agency reuses — so it is stored the same
       way and picked from the same list. (Anthony, 2026-09-10) */
    private const ASSET_KINDS = ['header', 'footer', 'hero'];

    public function assetsIndex(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $q = DB::table('marketing_assets')->where('agency_id', $agencyId)->whereNull('deleted_at');
        if ($kind = $request->query('kind')) {
            /* Was `$kind === 'footer' ? 'footer' : 'header'`, which quietly answered
               "header" for anything it did not recognise — so asking for heroes would
               have returned headers rather than nothing, which is the worse failure.
               An unknown kind now matches nothing. */
            $q->whereIn('kind', in_array($kind, self::ASSET_KINDS, true) ? [$kind] : ['__none__']);
        }

        $rows = $q->orderBy('kind')->orderByDesc('id')
            ->get(['id', 'kind', 'name', 'image_path', 'html', 'created_at'])
            ->map(function ($a) {
                $a->image_url = $a->image_path
                    ? rtrim((string) config('app.url'), '/').'/'.$a->image_path
                    : null;

                return $a;
            });

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /marketing/assets — save a header or footer for reuse.
     *
     * An image, a block of HTML, or both: a footer is often words (an address, the
     * unsubscribe wording) rather than a picture, and a header is usually a banner.
     */
    public function assetsStore(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);

        $data = $request->validate([
            'kind' => ['required', 'in:header,footer,hero'],
            'name' => ['required', 'string', 'max:120'],
            'html' => ['nullable', 'string', 'max:20000'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif', 'max:5120'],
        ]);

        $stored = null;
        if ($request->hasFile('image')) {
            $stored = \App\Support\AnnouncementImage::store($request->file('image'), 'campaign-assets');
            if (! $stored) {
                return response()->json([
                    'message' => 'That image could not be prepared. Use a JPEG, PNG or GIF under 5 MB.',
                ], 422);
            }
        }

        if (! $stored && empty($data['html'])) {
            return response()->json(['message' => 'Add an image or some text — an empty block is not much use.'], 422);
        }

        $id = DB::table('marketing_assets')->insertGetId([
            'agency_id' => $agencyId,
            'kind' => $data['kind'],
            'name' => $data['name'],
            'image_path' => $stored['path'] ?? null,
            // Same sanitiser the campaign body goes through: this HTML is emailed out
            // and must not be able to carry a script.
            'html' => ! empty($data['html']) ? $this->sanitiseHtml($data['html']) : null,
            'created_by_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $id,
            'kind' => $data['kind'],
            'name' => $data['name'],
            'image_url' => $stored['url'] ?? null,
        ], 201);
    }

    /** DELETE /marketing/assets/{id} */
    public function assetsDestroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $n = DB::table('marketing_assets')->where('id', $id)->where('agency_id', $agencyId)
            ->whereNull('deleted_at')->update(['deleted_at' => now()]);

        // Soft-deleted rather than removed: campaigns already sent still reference it,
        // and their record should keep showing what actually went out.
        return response()->json(['ok' => (bool) $n]);
    }

    /**
     * A campaign's body with its header and footer wrapped around it.
     *
     * Kept here so the preview, the in-portal copy and the email all compose the same
     * way — a preview that does not match what is sent is worse than no preview.
     */
    public function composeBody($row): string
    {
        /* Only the campaign's own agency's blocks are ever composed in (2026-11-05 leak sweep). */
        $agencyOf = isset($row->agency_id) ? (int) $row->agency_id : null;
        $part = function ($assetId) use ($agencyOf) {
            if (! $assetId) {
                return '';
            }
            $aq = DB::table('marketing_assets')->where('id', $assetId)->whereNull('deleted_at');
            if ($agencyOf) $aq->where('agency_id', $agencyOf);
            $a = $aq->first();
            if (! $a) {
                return '';
            }
            $out = '';
            if ($a->image_path) {
                $url = rtrim((string) config('app.url'), '/').'/'.$a->image_path;
                // Inline width, not a stylesheet — most email clients discard <style>.
                $out .= '<img src="'.e($url).'" alt="" style="display:block;width:100%;max-width:600px;border:0;">';
            }
            if ($a->html) {
                $out .= $a->html;
            }

            return $out;
        };

        return $part($row->header_asset_id ?? null)
            .($row->body_html ?? '')
            .$part($row->footer_asset_id ?? null);
    }

}
