<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailTemplate;
use App\Support\EmailHeader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform → Seasonal email headers (2026-10-09). Platform admins only: the KiddieTrac
 * banner is the platform's, not an agency's. See App\Support\EmailHeader.
 */
class EmailHeaderSeasonsController extends Controller
{
    private function assertPlatform(Request $request): void
    {
        abort_unless(DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where('role', 'platform_admin')->exists(), 403, 'Platform admins only.');
    }

    private function out(object $s): array
    {
        $fmt = fn ($v) => Carbon::parse($v, EmailHeader::TZ)->format('Y-m-d\TH:i');

        return ['id' => (int) $s->id, 'name' => $s->name, 'image_url' => $s->image_url,
            'starts_local' => $fmt($s->starts_at), 'ends_local' => $fmt($s->ends_at),
            'repeat_yearly' => (bool) $s->repeat_yearly, 'active' => (bool) $s->active];
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertPlatform($request);
        $cur = EmailHeader::current();

        return response()->json([
            'seasons' => DB::table('email_header_seasons')->orderBy('starts_at')->get()->map(fn ($s) => $this->out($s))->values(),
            'default_url' => EmailHeader::DEFAULT_URL,
            'current' => $cur ? ['id' => (int) $cur->id, 'name' => $cur->name, 'image_url' => $cur->image_url] : null,
            'tz' => EmailHeader::TZ,
        ]);
    }

    private function fields(Request $request, bool $create): array
    {
        $d = $request->validate([
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:120'],
            'starts_local' => [$create ? 'required' : 'sometimes', 'date_format:Y-m-d\TH:i'],
            'ends_local' => [$create ? 'required' : 'sometimes', 'date_format:Y-m-d\TH:i'],
            'repeat_yearly' => ['sometimes'],
            'active' => ['sometimes'],
            'image' => [$create ? 'required' : 'nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:15360'],
        ]);
        $row = [];
        if (isset($d['name'])) $row['name'] = trim($d['name']);
        if (isset($d['starts_local'])) $row['starts_at'] = Carbon::createFromFormat('Y-m-d\TH:i', $d['starts_local'], EmailHeader::TZ)->format('Y-m-d H:i:s');
        if (isset($d['ends_local'])) $row['ends_at'] = Carbon::createFromFormat('Y-m-d\TH:i', $d['ends_local'], EmailHeader::TZ)->format('Y-m-d H:i:s');
        if ($request->has('repeat_yearly')) $row['repeat_yearly'] = filter_var($request->input('repeat_yearly'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if ($request->has('active')) $row['active'] = filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if ($request->hasFile('image')) {
            try { $row['image_url'] = EmailHeader::storeImage($request->file('image')->getRealPath(), $row['name'] ?? 'season'); }
            catch (\Throwable $e) { abort(422, $e->getMessage()); }
        }

        return $row;
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertPlatform($request);
        $row = $this->fields($request, true);
        if (empty($row['repeat_yearly']) && isset($row['starts_at'], $row['ends_at']) && $row['ends_at'] <= $row['starts_at']) {
            abort(422, 'The end must be after the start.');
        }
        $id = DB::table('email_header_seasons')->insertGetId($row + ['repeat_yearly' => 1, 'active' => 1,
            'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $id, 'Seasonal email header added: ' . $row['name']);

        return response()->json(['season' => $this->out(DB::table('email_header_seasons')->find($id))], 201);
    }

    /** POST /admin/email-header-seasons/{id} (multipart, so an image can be replaced). */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->assertPlatform($request);
        abort_unless(DB::table('email_header_seasons')->where('id', $id)->exists(), 404);
        $row = $this->fields($request, false);
        DB::table('email_header_seasons')->where('id', $id)->update($row + ['updated_at' => now()]);
        $s = DB::table('email_header_seasons')->find($id);
        $this->audit($request, $id, 'Seasonal email header updated: ' . $s->name . ' (' . implode(', ', array_keys($row)) . ')');

        return response()->json(['season' => $this->out($s)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertPlatform($request);
        $s = DB::table('email_header_seasons')->find($id);
        abort_unless($s, 404);
        DB::table('email_header_seasons')->where('id', $id)->delete();
        $this->audit($request, $id, 'Seasonal email header deleted: ' . $s->name);

        return response()->json(['ok' => true]);
    }

    /** A sample email with a given header (or the one in force now). Sends nothing. */
    public function preview(Request $request): JsonResponse
    {
        $this->assertPlatform($request);
        $url = (string) $request->input('image_url', '');
        $html = EmailTemplate::wrap(null, '<p style="margin:0 0 12px;line-height:1.6;">Hi Alex,</p><p style="margin:0;line-height:1.6;">This is how KiddieTrac emails look with this header.</p>',
            ['eyebrow' => 'PREVIEW', 'title' => 'Seasonal header', 'subtitle' => 'Nothing is sent', 'preheader' => 'Preview', 'header_image' => $url ?: null]);

        return response()->json(['html' => $html]);
    }

    private function audit(Request $request, int $id, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'email_header_season.changed',
                'entity_type' => 'email_header_season', 'entity_id' => $id, 'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }
}
