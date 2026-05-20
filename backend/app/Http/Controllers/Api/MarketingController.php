<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $isPlatform = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatform) {
            if ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists()) {
                return $activeId;
            }
            return (int) DB::table('agencies')->whereNull('deleted_at')->orderBy('id')->value('id');
        }
        if ($activeId && DB::table('role_assignments')
                ->where('user_id', $userId)->where('agency_id', $activeId)
                ->whereIn('role', ['agency_admin', 'centre_director'])->where('active', true)->exists()) {
            return $activeId;
        }
        // Director's centre -> agency
        $centreId = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'centre_director')->where('active', true)->value('centre_id');
        if ($centreId) {
            return (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        }
        return DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'agency_admin')->where('active', true)->value('agency_id');
    }

    private function getCentreIdIfDirector(Request $request): ?int
    {
        $assignment = DB::table('role_assignments')
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
            ->orderByDesc('updated_at');

        // Directors only see campaigns scoped to their centre (or agency-wide)
        $centreId = $this->getCentreIdIfDirector($request);
        if ($centreId) {
            $q->where(function ($x) use ($centreId) { $x->whereNull('centre_id')->orWhere('centre_id', $centreId); });
        }

        $rows = $q->limit(100)->get();
        return response()->json(['campaigns' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('marketing_campaigns')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
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
            'hero_image_url' => ['nullable', 'string', 'max:500'],
            'audience' => ['required', 'in:all_families,active_families,waitlist,prospects,staff,custom'],
            'channel' => ['required', 'in:email,in_portal,both'],
            'centre_id' => ['nullable', 'integer'],
            'scheduled_for' => ['nullable', 'date'],
            'status' => ['nullable', 'in:draft,scheduled'],
        ]);

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
        $row = DB::table('marketing_campaigns')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        if ($row->status === 'sent') return response()->json(['message' => 'Sent campaigns are read-only'], 422);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'body_html' => ['sometimes', 'string', 'max:200000'],
            'hero_image_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'audience' => ['sometimes', 'in:all_families,active_families,waitlist,prospects,staff,custom'],
            'channel' => ['sometimes', 'in:email,in_portal,both'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'in:draft,scheduled,archived'],
        ]);
        if (isset($data['body_html'])) $data['body_html'] = $this->sanitiseHtml($data['body_html']);
        $data['updated_at'] = now();
        DB::table('marketing_campaigns')->where('id', $id)->update($data);
        return response()->json(['message' => 'Campaign updated']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('marketing_campaigns')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        DB::table('marketing_campaigns')->where('id', $id)->update(['deleted_at' => now()]);
        return response()->json(['message' => 'Campaign deleted']);
    }

    // ── Send (in-portal channel writes to announcements; email queues) ────

    public function sendNow(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('marketing_campaigns')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        if (in_array($row->status, ['sent', 'sending'], true)) return response()->json(['message' => 'Already sent or sending'], 422);

        DB::table('marketing_campaigns')->where('id', $id)->update(['status' => 'sending', 'updated_at' => now()]);

        $recipients = $this->resolveAudience($row, $agencyId);

        // In-portal channel: stamp an announcements row so families see it in
        // their feed. The email channel will be picked up by the v22p35
        // scheduled command — for now we just mark recipient_count so the
        // operator sees the projected fan-out.
        if (in_array($row->channel, ['in_portal', 'both'], true)) {
            DB::table('announcements')->insert([
                'scope_type' => $row->centre_id ? 'centre' : 'agency',
                'scope_id' => $row->centre_id ?: $agencyId,
                'title' => $row->title,
                'body' => strip_tags($row->body_html, '<p><br><a><img><ul><ol><li><strong><em><h1><h2><h3>'),
                'send_email' => 0,
                'send_push' => 1,
                'sent_at' => now(),
                'created_by_id' => $row->created_by_user_id,
                'created_at' => now(),
            ]);
        }

        DB::table('marketing_campaigns')->where('id', $id)->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipient_count' => count($recipients),
            'delivery_count' => count($recipients),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Campaign sent',
            'recipients' => count($recipients),
            'channel' => $row->channel,
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
        $file->storeAs('public/marketing', $name);
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
            : DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->pluck('id')->all();

        if ($aud === 'staff') {
            return DB::table('role_assignments')
                ->where('active', true)
                ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
                ->where(function ($q) use ($agencyId, $centreScope) {
                    $q->where('agency_id', $agencyId)->orWhereIn('centre_id', $centreScope);
                })
                ->distinct()->pluck('user_id')->all();
        }
        // Families = users that are guardians of children in our centre scope
        $base = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreScope);
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
}
